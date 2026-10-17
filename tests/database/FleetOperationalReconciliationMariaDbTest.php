<?php

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\OperationalReconciliationAssertions;
use Tests\Support\OperationalReconciliationFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Required disposable MariaDB gate, with identical authority cases to SQLite. */
final class FleetOperationalReconciliationMariaDbTest extends CIUnitTestCase
{
    use OperationalReconciliationAssertions;

    private static ?VehicleDamageRepairMariaDbFixture $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'), 'Disposable local MariaDB is required.');
        self::$fixture ??= new VehicleDamageRepairMariaDbFixture(through: 36);
        $this->connection = self::$fixture->db;
        $this->connection->transBegin();
        OperationalReconciliationFixture::seed($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->transRollback();
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->close();
        self::$fixture = null;
        parent::tearDownAfterClass();
    }

    public function testOwnedReadOnlySnapshotOnCommittedMariaDbEvidence(): void
    {
        $fixture = new VehicleDamageRepairMariaDbFixture(through: 36);
        try {
            OperationalReconciliationFixture::seed($fixture->db);
            $before = OperationalReconciliationFixture::fingerprint($fixture->db);
            $projection = (new \App\Services\Fleet\FleetOperationalReconciliationService($fixture->db))->forVehicles(1, [1000], OperationalReconciliationFixture::clock());
            $this->assertCount(1, $projection->vehicles);
            $this->assertSame(0, $fixture->db->transDepth);
            $this->assertSame($before, OperationalReconciliationFixture::fingerprint($fixture->db));
        } finally {
            $fixture->close();
        }
    }

    public function testExplainUsesExistingIndexesOnSubstantialSyntheticHistory(): void
    {
        $db = $this->connection;
        $tripRows = $eventRows = $checklistRows = [];
        for ($vehicle = 1001; $vehicle <= 1100; $vehicle++) {
            OperationalReconciliationFixture::vehicle($db, $vehicle);
            for ($offset = 0; $offset < 20; $offset++) {
                $tripId = 20000 + ($vehicle - 1001) * 20 + $offset;
                $start = sprintf('2029-12-%02d 08:00:00', $offset + 1);
                $end = sprintf('2029-12-%02d 18:00:00', $offset + 1);
                $tripRows[] = ['id' => $tripId, 'fleet_vehicle_id' => $vehicle, 'starts_at' => $start, 'ends_at' => $end, 'turo_trip_id' => 'SYNTHETIC-EXPLAIN-' . $tripId, 'turo_reservation_id' => 'SYNTHETIC-EXPLAIN-' . $tripId];
                $checklistRows[] = ['turo_trip_normalized_id' => $tripId, 'fleet_vehicle_id' => $vehicle, 'movement_type' => 'pickup', 'scheduled_at' => $start];
                foreach (['actual_handoff' => $start, 'vehicle_recovered' => $end] as $code => $at) {
                    $eventRows[] = ['company_id' => 1, 'fleet_vehicle_id' => $vehicle, 'turo_trip_normalized_id' => $tripId, 'event_code' => $code, 'occurred_at' => $at, 'source' => 'operator', 'actor_user_id' => 7, 'location_class' => 'home'];
                }
            }
        }
        $db->table('turo_trips_normalized')->insertBatch($tripRows);
        $db->table('trip_movement_events')->insertBatch($eventRows);
        $db->table('trip_movement_checklists')->insertBatch($checklistRows);
        $queries = [];
        $listener = static function (\CodeIgniter\Database\Query $query) use (&$queries): void {
            if (preg_match('/^SELECT\b/i', $query->getQuery())) {
                $queries[] = $query->getQuery();
            }
        };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);
        try {
            $projection = (new \App\Services\Fleet\FleetOperationalReconciliationService($db))->forVehicles(1, [1001], OperationalReconciliationFixture::clock());
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }
        $this->assertCount(1, $projection->vehicles);
        $plans = [];
        foreach ($queries as $sql) {
            $plan = $db->query('EXPLAIN ' . $sql)->getResultArray();
            $this->assertNotEmpty($plan);
            $plans[] = ['sql' => $sql, 'plan' => $plan];
        }
        foreach (['events', 'checklists', 'trips'] as $alias) {
            $scoped = array_merge(...array_column($plans, 'plan'));
            $indexed = array_filter($scoped, static fn (array $row): bool => $row['table'] === $alias && $row['key'] !== null);
            $this->assertNotEmpty($indexed, 'Existing authority index was not selected for ' . $alias);
        }
        // Opt-in local evidence artifact contains synthetic SQL only; no mandatory test output writes.
        $path = getenv('RECONCILIATION_EXPLAIN_OUTPUT');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['history_trips' => count($tripRows), 'history_events' => count($eventRows), 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

}
