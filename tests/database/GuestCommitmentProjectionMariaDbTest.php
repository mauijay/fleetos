<?php

use App\Repositories\FleetExtraRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\GuestCommitmentProjectionFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Real local MariaDB, independent processes and application migrations. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class GuestCommitmentProjectionMariaDbTest extends CIUnitTestCase
{
    public static function races(): iterable
    {
        yield 'import versus completion' => ['import', 'complete'];
        yield 'mapping versus completion' => ['mapping', 'complete'];
        yield 'configuration versus completion' => ['configuration', 'complete'];
        yield 'handoff versus completion' => ['handoff', 'complete'];
        yield 'handoff versus reopening' => ['handoff', 'reopen'];
        yield 'missing fulfillment reconciliation' => ['reconciliation', 'reconcile'];
        yield 'confirmation before late reactivation reconciliation' => ['reactivation', 'reactivate'];
    }

    #[DataProvider('races')]
    public function testMaterialWriterRacesUseCurrentBasisAndHandoffBoundary(string $winner, string $operation): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'), 'Disposable MariaDB is a required projection gate.');
        $fixture = new VehicleDamageRepairMariaDbFixture(through: 35);
        $db = $fixture->db;
        try {
            Fixture::seed($db);
            $work = Fixture::fulfillments($db);
            $work->reconcileForTrip(1, 100);
            $before = Fixture::service($db)->forTrip(1, 100, Fixture::clock());
            $id = (int) $before['purchased'][0]['fulfillment_id'];
            if (in_array($operation, ['reopen', 'reactivate'], true)) {
                $work->complete(1, 100, $id, 7);
            }
            $db->transBegin();
            // These are the shared locks held by import/configuration or movement writers.
            (new TripExtraFulfillmentRepository($db))->lockOwnedTrip(1, 100);
            if ($winner === 'import') {
                Fixture::snapshot($db, [Fixture::item(['quantity' => '3.000'])], '2030-01-02 19:00:00', true, true);
            } elseif ($winner === 'mapping') {
                $extra = $db->table('fleet_extras')->where('id', 301)->get()->getRowArray();
                $extra['id'] = 302;
                $extra['code'] = 'synthetic-other';
                $extra['display_name'] = 'Synthetic alternate gear';
                $db->table('fleet_extras')->insert($extra);
                $db->table('fleet_extra_source_mappings')->update(['fleet_extra_id' => 302]);
            } elseif ($winner === 'configuration') {
                $db->table('fleet_extras')->where('id', 301)->update(['fulfillment_type' => 'install']);
            } elseif ($winner === 'handoff') {
                Fixture::handoff($db);
            } elseif ($winner === 'reactivation') {
                Fixture::snapshot($db, [], '2030-01-02 18:30:00', true, true);
                Fixture::snapshot($db, [Fixture::item()], '2030-01-02 19:00:00', true, true);
                $work->complete(1, 100, $id, 7);
            } else {
                $db->table('trip_extra_fulfillment_audits')->emptyTable();
                $db->table('trip_extra_fulfillments')->emptyTable();
                $work->reconcileForTrip(1, 100);
            }
            $result = $this->contend($db, $operation, $id, (int) $before['purchased'][0]['selection_id']);
            $this->assertTrue($result['blocked'], 'The independent command must wait on the winning writer: ' . json_encode($result));
            $this->assertSame($winner !== 'handoff', $result['retry']['success'], json_encode($result));
            $after = Fixture::service($db)->forTrip(1, 100, Fixture::clock());
            $this->assertCount(1, $after['purchased']);
            $this->assertSame(1, $db->table('trip_extra_fulfillments')->countAllResults());
            if (! in_array($winner, ['handoff', 'reconciliation'], true)) {
                $this->assertTrue($after['purchased'][0]['is_completed']);
                $this->assertTrue($after['purchased'][0]['basis_current']);
            }
        } finally {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            $fixture->close();
        }
    }

    public function testBoardAndPageReadsSeeCommittedSnapshotWithoutWriterContention(): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture(through: 35);
        $db = $fixture->db;
        $reader = null;
        try {
            Fixture::seed($db);
            $reader = Database::connect($this->configuration($db), false);
            $db->transBegin();
            (new FleetExtraRepository($db))->lockCompanyObservations(1);
            Fixture::snapshot($db, [], '2030-01-02 19:00:00', true, true);
            $page = Fixture::service($reader)->forTrip(1, 100, Fixture::clock());
            $board = Fixture::service($reader)->forTrips(1, [100], Fixture::clock())[100];
            $this->assertSame($page, $board);
            $this->assertCount(1, $page['purchased']);
            $db->transCommit();
            $after = Fixture::service($reader)->forTrip(1, 100, Fixture::clock());
            $this->assertSame([], $after['purchased']);
            $this->assertTrue($after['can_show_empty']);
        } finally {
            $reader?->close();
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            $fixture->close();
        }
    }

    public function testActualMariaDbSelectBudgetForOneFiftyAndFiveHundredTrips(): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture(through: 35);
        $db = $fixture->db;
        try {
            Fixture::seed($db);
            $ids = [100];
            for ($index = 1; $index < 500; $index++) {
                $id = 1000 + $index;
                $ids[] = $id;
                $db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => 10, 'turo_trip_id' => '8000' . $id, 'turo_reservation_id' => '8000' . $id, 'starts_at' => '2030-01-03 08:00:00', 'ends_at' => '2030-01-05 09:00:00']);
                Fixture::snapshot($db, [Fixture::item()], Fixture::OBSERVED, true, true, $id);
            }
            Fixture::service($db)->forTrips(1, $ids, Fixture::clock());
            foreach ([1, 50, 500] as $size) {
                $selects = 0;
                $listener = static function (\CodeIgniter\Database\Query $query) use (&$selects): void {
                    if (preg_match('/^SELECT\b/i', $query->getQuery())) {
                        $selects++;
                    }
                };
                \CodeIgniter\Events\Events::on('DBQuery', $listener);
                try {
                    $result = Fixture::service($db)->forTrips(1, array_slice($ids, 0, $size), Fixture::clock());
                } finally {
                    \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
                }
                $this->assertCount($size, $result);
                $this->assertSame(8, $selects, 'Actual MariaDB data SELECT count for ' . $size . ' trips.');
                $this->assertCount(1, $result[$ids[$size - 1]]['purchased']);
            }
        } finally {
            $fixture->close();
        }
    }

    private function configuration(BaseConnection $db): array
    {
        $config = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        $config['database'] = $db->database;
        return $config;
    }

    private function contend(BaseConnection $db, string $operation, int $id, int $selectionId): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), dirname(__DIR__) . '/_support/GuestCommitmentProjectionMariaDbWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start projection contender.');
        }
        try {
            fwrite($pipes[0], json_encode(['configuration' => $this->configuration($db), 'operation' => $operation, 'id' => $id, 'selection_id' => $selectionId], JSON_THROW_ON_ERROR) . PHP_EOL);
            stream_set_timeout($pipes[1], 15);
            $first = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            $db->transCommit();
            fwrite($pipes[0], "retry\n");
            fclose($pipes[0]);
            $retry = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            $this->assertSame('', $errors);
            $this->assertSame(0, $exit);
            return ['blocked' => $first['blocked'], 'first' => $first, 'retry' => $retry];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
