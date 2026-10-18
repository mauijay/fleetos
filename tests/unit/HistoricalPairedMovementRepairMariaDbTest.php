<?php

use App\Repositories\HistoricalMovementRepairRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalPairedMovementRepairService;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal */
final class HistoricalPairedMovementRepairMariaDbTest extends TestCase
{
    private VehicleDamageRepairMariaDbFixture $fixture;
    private array $request;
    private array $workers = [];

    protected function setUp(): void
    {
        if (! getenv('B21_MARIADB_CONFIG') || ! getenv('B21_MARIADB_DATADIR')) {
            $this->markTestSkipped('Requires explicit disposable local MariaDB configuration.');
        }
        $this->fixture = new VehicleDamageRepairMariaDbFixture(through: 35, seed: false);
        $this->request = HistoricalMovementRepairFixture::seed($this->fixture->db);
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

    #[DataProvider('normalWriters')]
    public function testUnmodifiedMovementWriterWaitsForVehicleRangeLocks(string $operation): void
    {
        $db = $this->fixture->db;
        $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->transBegin();
        (new HistoricalMovementRepairRepository($db))->lockVehicle(1, 10);
        [$process, $pipes, $blocked] = $this->start($operation);
        $this->assertFalse($blocked['success']);
        $this->assertTrue($blocked['lock_timeout'], $blocked['error'] ?? '');
        $db->transCommit();
        $retry = $this->finish($process, $pipes);
        $this->assertTrue($retry['success'], $retry['error'] ?? '');
    }

    public static function normalWriters(): iterable
    {
        yield 'later handoff insertion' => ['handoff'];
        yield 'movement correction' => ['correction'];
    }

    public function testConcurrentRepairReplayWaitsAndReturnsAlreadyAppliedWithoutDuplicate(): void
    {
        $db = $this->fixture->db;
        $preview = (new HistoricalPairedMovementRepairService($db))->preview($this->request, 7);
        $callback = function (): void {
            [$process, $pipes, $blocked] = $this->start('repair', $this->previewHash);
            $this->assertFalse($blocked['success']);
            $this->assertTrue($blocked['lock_timeout'], $blocked['error'] ?? '');
            $this->replayWorker = [$process, $pipes];
        };
        $this->previewHash = $preview['confirmation'];
        $repository = new class ($db, $callback) extends HistoricalMovementRepairRepository {
            public function __construct(BaseConnection $db, private Closure $callback)
            {
                parent::__construct($db);
            }
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === 'historical_pair_applied') {
                    ($this->callback)();
                }
            }
        };
        $result = (new HistoricalPairedMovementRepairService($db, $repository))->apply($this->request, 7, $preview['confirmation']);
        $this->assertSame('applied', $result['status']);
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $retry = $this->finish(...$this->replayWorker);
        $this->assertSame('already_applied', $retry['result']['status']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    private string $previewHash;
    private array $replayWorker;

    public function testRepairRechecksAuthorityAfterConcurrentCorrectionCommits(): void
    {
        $db = $this->fixture->db;
        $preview = (new HistoricalPairedMovementRepairService($db))->preview($this->request, 7);
        $db->transBegin();
        $events = new MovementEventService(new OperationalFactsRepository($db));
        $events->correct($this->request['expected_authority']['custody_event_id'], ['location_detail' => 'Synthetic new authority'], 7, 'Synthetic winner correction', false);
        [$process, $pipes, $blocked] = $this->start('repair', $preview['confirmation']);
        $this->assertTrue($blocked['lock_timeout'], $blocked['error'] ?? '');
        $db->transCommit();
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $retry = $this->finish($process, $pipes);
        $this->assertFalse($retry['success']);
        $this->assertStringContainsString('Expected genuine later', $retry['error']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    public function testRepairRechecksAfterConcurrentLaterHandoffCommits(): void
    {
        $db = $this->fixture->db;
        $preview = (new HistoricalPairedMovementRepairService($db))->preview($this->request, 7);
        $db->transBegin();
        (new MovementEventService(new OperationalFactsRepository($db)))->record(10, 3002, 'actual_handoff', 'pickup', '2025-10-10 22:00:00', 'other_delivery', 'Synthetic competing lodge', 'operator', 7);
        [$process, $pipes, $blocked] = $this->start('repair', $preview['confirmation']);
        $this->assertTrue($blocked['lock_timeout'], $blocked['error'] ?? '');
        $db->transCommit();
        $before = HistoricalMovementRepairFixture::snapshot($db);
        $retry = $this->finish($process, $pipes);
        $this->assertFalse($retry['success']);
        $this->assertStringContainsString('conflict-free', $retry['error']);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($db));
    }

    public function testPreservationSurvivesDifferentCommercialParentReadPlans(): void
    {
        $db = \Tests\Support\HistoricalPairPlanConnection::replaceFixtureConnection($this->fixture);
        $commercial = new \App\Repositories\GuestCommitmentProjectionRepository($db);
        $tripIds = [100, 102, 3001, 3002, 3003];
        $before = $commercial->load(1, $tripIds, new \DateTimeImmutable())['trips'];
        $service = new HistoricalPairedMovementRepairService($db);
        $preview = $service->preview($this->request, 7);
        $result = $service->apply($this->request, 7, $preview['confirmation']);
        $after = $commercial->load(1, $tripIds, new \DateTimeImmutable())['trips'];

        $this->assertSame('applied', $result['status']);
        $this->assertSame($result['before'], $result['after']);
        $this->assertSame($before, $after);
        $this->assertSame($tripIds, array_map('intval', array_column($after, 'id')));
        $this->assertSame(['PRIMARY', 'fleet_vehicle_id_starts_at_ends_at'], array_values(array_unique($db->readPlans)));
        $this->assertSame('already_applied', $service->apply($this->request, 7, $preview['confirmation'])['status']);
    }

    private function start(string $operation, string $confirmation = ''): array
    {
        $db = $this->fixture->db;
        $configuration = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        $configuration['database'] = $db->database;
        $configuration['DBPrefix'] = $db->DBPrefix;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), __DIR__ . '/../_support/HistoricalMovementRepairWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start disposable contention worker.');
        }
        $this->workers[] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['configuration' => $configuration, 'operation' => $operation, 'request' => $this->request, 'confirmation' => $confirmation, 'event_id' => $this->request['expected_authority']['custody_event_id']], JSON_THROW_ON_ERROR) . PHP_EOL);
        stream_set_timeout($pipes[1], 15);
        $line = (string) fgets($pipes[1]);
        if ($line === '') {
            throw new RuntimeException('Contender failed: ' . stream_get_contents($pipes[2]));
        }
        return [$process, $pipes, json_decode($line, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function finish($process, array $pipes): array
    {
        fwrite($pipes[0], "retry\n");
        fclose($pipes[0]);
        $result = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('', $errors);
        return $result;
    }
}
