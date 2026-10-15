<?php

use App\Repositories\HistoricalMissingRecoveryRepairRepository as Repository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingRecoveryRepairService as Repair;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingHandoffFixture;
use Tests\Support\HistoricalMissingRecoveryFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal */
final class HistoricalMissingRecoveryRepairMariaDbTest extends TestCase
{
    private VehicleDamageRepairMariaDbFixture $fixture;
    private array $r;
    private array $workers = [];
    private int $parentThread;

    protected function setUp(): void
    {
        $this->assertNotFalse(getenv('B21_MARIADB_CONFIG'), 'Disposable MariaDB configuration is mandatory.');
        $this->assertNotFalse(getenv('B21_MARIADB_DATADIR'), 'Worktree-local MariaDB directory is mandatory.');
        $this->fixture = new VehicleDamageRepairMariaDbFixture(through: 36, seed: false);
        $this->r = Fixture::seed($this->fixture->db);
        $this->parentThread = (int) $this->fixture->db->query('SELECT CONNECTION_ID() AS thread')->getRowArray()['thread'];
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
        if (isset($this->fixture)) {
            while ($this->fixture->db->transDepth > 0) {
                $this->fixture->db->transRollback();
            }
            $this->fixture->close();
        }
    }

    #[DataProvider('races')]
    public function testApplyContentionAndReplayHaveExactlyOneRecovery(string $race): void
    {
        $db = $this->fixture->db;
        $repair = new Repair($db);
        $preview = $repair->preview($this->r, Fixture::ACTOR);
        $other = $this->r;
        if ($race === 'different_uuid') {
            $other['operation_uuid'] = HistoricalMissingHandoffFixture::uuid();
        }
        $hash = $repair->preview($other, Fixture::ACTOR)['confirmation'];
        $worker = [];
        $repo = $this->callbackRepository(function () use (&$worker, $other, $hash): void {
            $worker = $this->start('apply', $other, $hash);
        }, $race === 'receipt_replay' ? Repository::RECEIPT_ACTION : 'historical_missing_recovery_created');
        $winner = (new Repair($db, $repo))->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $loser = $this->finish($worker);
        $this->assertSame('applied', $winner['status']);
        if ($race === 'different_uuid') {
            $this->assertFalse($loser['success']);
            $this->assertMatchesRegularExpression('/stale|Existing recovery/', $loser['error']);
        } else {
            $this->assertTrue($loser['success'], $loser['error'] ?? '');
            $this->assertSame('already_applied', $loser['result']['status']);
            foreach (['event_id', 'assessment_id', 'receipt_audit_id'] as $key) {
                $this->assertSame($winner[$key], $loser['result'][$key]);
            }
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
        $this->assertSame(1, $db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'vehicle_recovered'])->countAllResults());
    }

    public static function races(): array
    {
        return [['different_uuid'], ['same_uuid'], ['receipt_replay']];
    }

    #[DataProvider('ordinaryClosures')]
    public function testApplyVersusOrdinaryRecoveryCannotCreateDuplicate(string $operation): void
    {
        $db = $this->fixture->db;
        $preview = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $worker = [];
        $repo = $this->callbackRepository(function () use (&$worker, $operation): void {
            $worker = $this->start($operation, $this->r);
        }, 'historical_missing_recovery_created');
        $result = (new Repair($db, $repo))->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $loser = $this->finish($worker);
        $this->assertSame('applied', $result['status']);
        $this->assertFalse($loser['success'], 'Concurrent ordinary recovery must see the committed closure.');
        $this->assertSame(1, $db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'vehicle_recovered'])->countAllResults());
    }

    public static function ordinaryClosures(): array
    {
        return [['ordinary_recovery'], ['ordinary_return'], ['ordinary_recovery_snapshot']];
    }

    public function testLaterPositionWaitsUntilRecoveryCommitAndDoesNotChangeCustody(): void
    {
        $db = $this->fixture->db;
        $preview = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $worker = [];
        $repo = $this->callbackRepository(function () use (&$worker): void {
            $worker = $this->start('position', $this->r);
        }, 'historical_missing_recovery_created');
        $result = (new Repair($db, $repo))->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $later = $this->finish($worker);
        $this->assertTrue($later['success'], $later['error'] ?? '');
        $custody = (new App\Services\Fleet\CurrentVehicleCustodyService(new OperationalFactsRepository($db)))->resolve(Fixture::VEHICLE);
        $position = (new App\Services\Fleet\CurrentVehicleLocationService(new OperationalFactsRepository($db)))->resolve(Fixture::VEHICLE);
        $this->assertSame('operator', $custody['custody']);
        $this->assertSame($result['event_id'], $custody['basis_event_id']);
        $this->assertSame($later['result']['id'], (int) $position['event_id']);
    }

    #[DataProvider('authorityWinners')]
    public function testWaitingApplyReadsCommittedAuthorityRatherThanOldSnapshot(string $change): void
    {
        $db = $this->fixture->db;
        $preview = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $db->transBegin();
        $facts = new OperationalFactsRepository($db);
        $events = new MovementEventService($facts);
        if ($change === 'handoff') {
            $events->correct($this->r['handoff_event_id'], ['location_detail' => 'Synthetic corrected pickup'], Fixture::ACTOR, 'Synthetic correction', false);
        } elseif ($change === 'ordinary_recovery') {
            $checklist = (new App\Repositories\MovementChecklistRepository($db))->checklistForCompany(Fixture::COMPANY, $this->r['return_checklist_id']);
            (new MovementOperationalFactService($db, $events, new MovementAssessmentService($facts)))->recoverVehicle($checklist + ['exists' => true], $this->r['recovery'] + ['confirm_recovery_location' => '1', 'energy_percent' => 67], Fixture::ACTOR);
        } elseif ($change === 'position') {
            Fixture::laterPositions($db);
        } else {
            $db->table('movement_assessments')->where('id', $this->r['handoff_assessment_id'])->update(['energy_percent' => 71]);
        }
        $worker = $this->start('apply', $this->r, $preview['confirmation']);
        $db->transCommit();
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $loser = $this->finish($worker);
        $this->assertFalse($loser['success']);
        $this->assertStringContainsString('stale', $loser['error']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    public static function authorityWinners(): array
    {
        return [['handoff'], ['ordinary_recovery'], ['position'], ['assessment']];
    }

    public function testCommitLostAcknowledgementReturnsOriginalReceiptWithoutDuplication(): void
    {
        $db = $this->fixture->db;
        $preview = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $fault = $this->fixture->independent(true);
        try {
            try {
                (new Repair($fault))->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
                $this->fail('Synthetic lost acknowledgement expected');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('acknowledgement lost', $e->getMessage());
            }
        } finally {
            $fault->close();
        }
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $replay = (new Repair($db))->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $this->assertSame('already_applied', $replay['status']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    public function testTrustedCliRequiresSuperadminAndExactPreview(): void
    {
        $path = dirname(__DIR__, 2) . '/build/v0354-tooling/cli-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($this->r, JSON_THROW_ON_ERROR));
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($path, 0600);
        }
        try {
            $before = HistoricalMovementRepairFixture::snapshot($this->fixture->db);
            $preview = $this->cli($path);
            $this->assertSame('preview', $preview['status']);
            $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->fixture->db));
            $this->assertStringContainsString('exact historical_missing_recovery.v1 preview hash', $this->cli($path, ['--apply'], false));
            $result = $this->cli($path, ['--apply', '--confirm', $preview['confirmation']]);
            $this->assertSame('applied', $result['status']);
            $this->assertSame('already_applied', $this->cli($path, ['--apply', '--confirm', $preview['confirmation']])['status']);
        } finally {
            unlink($path);
        }
    }
    private function cli(string $path, array $options = [], bool $decode = true): array|string
    {
        $config = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        $config['database'] = $this->fixture->db->database;
        $env = getenv();
        $env['CI_ENVIRONMENT'] = 'development';
        foreach ($config as $key => $value) {
            $setting = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $env['database.default.' . $key] = $setting;
            $env['database_default_' . $key] = $setting;
        }
        // Import exact case-sensitive keys: Windows getenv() normalizes the
        // inherited key spelling, while DotEnv/Config indexes use exact keys.
        $env['SYNTHETIC_HMR_CLI_CONFIGURATION'] = json_encode($config, JSON_THROW_ON_ERROR);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-r', 'foreach (json_decode(getenv("SYNTHETIC_HMR_CLI_CONFIGURATION"), true, 512, JSON_THROW_ON_ERROR) as $key => $value) { $_ENV["database.default." . $key] = is_bool($value) ? ($value ? "true" : "false") : (string) $value; } require "spark";', '--', 'fleet:repair:missing-recovery', '--request', $path, '--actor', (string) Fixture::ACTOR, ...$options], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch synthetic CLI.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if (! $decode) {
            return $output . $error;
        }
        $this->assertSame(0, $code, $output . $error);
        $this->assertSame('', $error);
        $start = strpos($output, '{');
        $this->assertNotFalse($start, $output);
        return json_decode(substr($output, $start), true, 512, JSON_THROW_ON_ERROR);
    }

    private function callbackRepository(Closure $callback, string $action): Repository
    {
        return new class ($this->fixture->db, $callback, $action) extends Repository {
            public function __construct(BaseConnection $db, private readonly Closure $callback, private readonly string $action)
            {
                parent::__construct($db);
            }
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === $this->action) {
                    ($this->callback)();
                }
            }
        };
    }

    /** Start an independent connection and observe a real InnoDB wait before releasing the winner. */
    private function start(string $operation, array $request, string $confirmation = ''): array
    {
        $db = $this->fixture->db;
        $configuration = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        $configuration['database'] = $db->database;
        $configuration['DBPrefix'] = $db->DBPrefix;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), __DIR__ . '/../_support/HistoricalMissingRecoveryWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start synthetic contender.');
        }
        $this->workers[] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['configuration' => $configuration, 'operation' => $operation, 'request' => $request, 'confirmation' => $confirmation], JSON_THROW_ON_ERROR) . PHP_EOL);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 25);
        $thread = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['thread'];
        $this->assertNotSame($this->parentThread, $thread);
        $inspector = $this->fixture->independent();
        try {
            $deadline = microtime(true) + 12;
            do {
                $wait = $inspector->query("SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=? AND COMMAND='Query' AND TIME>=1", [$thread])->getRowArray();
                if ($wait !== null && preg_match('/FOR UPDATE|INSERT|UPDATE/i', $wait['INFO'] ?? '')) {
                    $this->assertNotEmpty($wait['INFO']);
                    return [$process, $pipes];
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('No real InnoDB contention observed for ' . $operation);
        } finally {
            $inspector->close();
        }
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $line = (string) fgets($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(0, $exit, $error);
        $this->assertSame('', $error);
        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
}
