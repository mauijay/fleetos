<?php

use App\Repositories\HistoricalMissingHandoffRepairRepository as Repository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingHandoffRepairService as Repair;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingHandoffFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal */
final class HistoricalMissingHandoffRepairMariaDbTest extends TestCase
{
    private VehicleDamageRepairMariaDbFixture $fixture;
    private array $r;
    private array $workers = [];
    private int $parentThread;

    protected function setUp(): void
    {
        // Acceptance deliberately fails rather than silently skipping native gates.
        $this->assertNotFalse(getenv('B21_MARIADB_CONFIG'), 'Explicit disposable MariaDB configuration required.');
        $this->assertNotFalse(getenv('B21_MARIADB_DATADIR'), 'Explicit worktree-local MariaDB directory required.');
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

    #[DataProvider('applyRaces')]
    public function testTwoAppliesOrConcurrentReplayHaveOneWinner(string $race): void
    {
        $db = $this->fixture->db;
        $repair = new Repair($db);
        $p = $repair->preview($this->r, Fixture::ACTOR);
        $other = $this->r;
        if ($race === 'different_uuid') {
            $other['operation_uuid'] = Fixture::uuid();
        }
        $otherHash = $repair->preview($other, Fixture::ACTOR)['confirmation'];
        $worker = [];
        $callback = function () use (&$worker, $other, $otherHash): void {
            $worker = $this->start('apply', $other, $otherHash);
        };
        $repo = $this->callbackRepository($callback, $race === 'replay' ? Repository::RECEIPT_ACTION : 'historical_missing_handoff_created');
        $winner = (new Repair($db, $repo))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $loser = $this->finish($worker);
        $this->assertSame('applied', $winner['status']);
        if ($race === 'different_uuid') {
            $this->assertFalse($loser['success']);
            $this->assertStringContainsString('Existing handoff', $loser['error']);
        } else {
            $this->assertTrue($loser['success'], $loser['error'] ?? '');
            $this->assertSame('already_applied', $loser['result']['status']);
            foreach (['event_id','creation_audit_id','receipt_audit_id'] as $id) {
                $this->assertSame($winner[$id], $loser['result'][$id]);
            }
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
        $this->assertCount(1, $db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'actual_handoff'])->get()->getResultArray());
    }

    public static function applyRaces(): array
    {
        return [['different_uuid'], ['same_uuid'], ['replay']];
    }

    #[DataProvider('writers')]
    public function testOrdinaryWriterCorrectionAndPositionWaitForMissingHandoffCommit(string $operation): void
    {
        $db = $this->fixture->db;
        $p = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $worker = [];
        $callback = function () use (&$worker, $operation): void {
            $worker = $this->start($operation, $this->r);
        };
        $repo = $this->callbackRepository($callback, 'historical_missing_handoff_created');
        $result = (new Repair($db, $repo))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($result['before'], $result['after']);
        $later = $this->finish($worker);
        $this->assertTrue($later['success'], $later['error'] ?? '');
        $this->assertCount(1, $db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'actual_handoff'])->get()->getResultArray());
        $replay = (new Repair($db))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($result['event_id'], $replay['event_id']);
        $this->assertSame('already_applied', $replay['status']);
    }

    public static function writers(): array
    {
        return [['ordinary'], ['correction'], ['position']];
    }

    #[DataProvider('authorityWinners')]
    public function testWaitingStaleApplyRechecksCommittedAuthorityWithoutOldSnapshot(string $operation): void
    {
        $db = $this->fixture->db;
        $p = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $db->transBegin();
        $events = new MovementEventService(new OperationalFactsRepository($db));
        match ($operation) {
            'correction' => $events->correct($this->r['recovery_event_id'], ['location_detail' => 'Synthetic winning recovery correction'], Fixture::ACTOR, 'Synthetic winner', false),
            'position' => $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, '2024-03-18 09:00:00', 'home', 'Synthetic winning later depot', 'synthetic_operator', Fixture::ACTOR),
            'assessment' => $db->table('movement_assessments')->where('id', $this->r['recovery_assessment_id'])->update(['energy_percent' => 63]),
            default => throw new RuntimeException('Unknown synthetic authority change.'),
        };
        $worker = $this->start('apply', $this->r, $p['confirmation']);
        $db->transCommit();
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $loser = $this->finish($worker);
        $this->assertFalse($loser['success']);
        $this->assertMatchesRegularExpression('/stale|original same-trip recovery/', $loser['error']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
        $this->assertCount(0, $db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'actual_handoff'])->get()->getResultArray());
    }

    public static function authorityWinners(): array
    {
        return [['correction'], ['position'], ['assessment']];
    }

    public function testActualCommitLostAcknowledgementReplaysOriginalIds(): void
    {
        $db = $this->fixture->db;
        $p = (new Repair($db))->preview($this->r, Fixture::ACTOR);
        $fault = $this->fixture->independent(true);
        try {
            try {
                (new Repair($fault))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
                $this->fail('Expected lost acknowledgement');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('acknowledgement lost', $e->getMessage());
            }
        } finally {
            $fault->close();
        }
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $result = (new Repair($db))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $receipt = (new Repository($db))->commandReceipt($this->r['operation_uuid']);
        $this->assertSame('already_applied', $result['status']);
        $this->assertSame($receipt['receipt']['event_id'], $result['event_id']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    public function testTrustedCliDefaultsToPurePreviewAndRequiresExactConfirmation(): void
    {
        $path = dirname(__DIR__, 2) . '/build/v0352-tooling/cli-synthetic-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($this->r, JSON_THROW_ON_ERROR));
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($path, 0600);
        }
        try {
            $db = $this->fixture->db;
            $before = HistoricalMovementRepairFixture::snapshot($db);
            $preview = $this->cli($path);
            $this->assertSame('preview', $preview['status']);
            $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
            $output = $this->cli($path, ['--apply'], false);
            $this->assertStringContainsString('exact historical_missing_handoff.v1 preview hash', $output);
            $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
            $missingActor = $this->cli($path, ['--actor'], false);
            $this->assertStringContainsString('bounded private request file and superadmin actor', $missingActor);
            $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
            $applied = $this->cli($path, ['--apply', '--confirm', $preview['confirmation']]);
            $this->assertSame('applied', $applied['status']);
            $this->assertSame($applied['before'], $applied['after']);
            $replay = $this->cli($path, ['--apply', '--confirm', $preview['confirmation']]);
            $this->assertSame($applied['event_id'], $replay['event_id']);
            $this->assertSame('already_applied', $replay['status']);
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
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-r', 'foreach (json_decode(getenv("SYNTHETIC_HMR_CLI_CONFIGURATION"), true, 512, JSON_THROW_ON_ERROR) as $key => $value) { $_ENV["database.default." . $key] = is_bool($value) ? ($value ? "true" : "false") : (string) $value; } require "spark";', '--', 'fleet:repair:missing-handoff', '--request', $path, '--actor', (string) Fixture::ACTOR, ...$options], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
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
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), __DIR__ . '/../_support/HistoricalMissingHandoffWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
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
