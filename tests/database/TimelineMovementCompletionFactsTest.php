<?php

use App\Repositories\OperationalFactsRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class TimelineMovementCompletionFactsTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testBatchCompletionReadUsesOnlyActiveAuthoritativeCompanyOwnedTripFacts(): void
    {
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 1, 'company_id' => 1],
            ['id' => 2, 'company_id' => 2],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 1, 'deleted_at' => null],
            ['id' => 202, 'fleet_vehicle_id' => 2, 'deleted_at' => null],
            ['id' => 303, 'fleet_vehicle_id' => 1, 'deleted_at' => '2026-09-13 08:00:00'],
        ]);
        $this->connection->table('trip_movement_events')->insertBatch([
            $this->event(1, 1, 1, 101, 'actual_handoff', 'pickup', '2026-09-13 09:00:00', '2026-09-13 09:01:00'),
            $this->event(2, 1, 1, 101, 'actual_return', 'return', '2026-09-12 20:15:00', '2026-09-13 10:00:00'),
            $this->event(3, 1, 1, 101, 'vehicle_positioned', null, '2026-09-13 10:30:00', '2026-09-13 10:31:00'),
            $this->event(4, 2, 2, 202, 'actual_handoff', 'pickup', '2026-09-13 09:00:00', '2026-09-13 09:01:00'),
            array_merge($this->event(5, 1, 1, 101, 'vehicle_recovered', 'return', '2026-09-13 10:00:00', '2026-09-13 10:01:00'), ['voided_at' => '2026-09-13 10:02:00']),
            $this->event(6, 1, 1, 101, 'actual_return', 'return', '2026-09-13 11:00:00', '2026-09-13 13:00:00'),
            $this->event(7, 1, 1, 303, 'actual_handoff', 'pickup', '2026-09-13 08:30:00', '2026-09-13 08:31:00'),
            $this->event(8, 2, 2, 101, 'actual_return', 'return', '2026-09-13 11:00:00', '2026-09-13 11:01:00'),
        ]);

        $facts = (new OperationalFactsRepository($this->connection))
            ->authoritativeMovementCompletionsForCompany(1, [101, 202, 303], '2026-09-13 12:00:00');

        $this->assertSame([2, 1], array_column($facts, 'id'));
        $this->assertSame(['actual_return', 'actual_handoff'], array_column($facts, 'event_code'));
        $this->assertSame([1, 1], array_map('intval', array_column($facts, 'company_id')));
        $this->assertSame([101, 101], array_map('intval', array_column($facts, 'turo_trip_normalized_id')));
    }

    /** @return array<string, int|string|null> */
    private function event(int $id, int $companyId, int $vehicleId, int $tripId, string $code, ?string $movementType, string $occurredAt, string $createdAt): array
    {
        return [
            'id' => $id,
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_normalized_id' => $tripId,
            'event_code' => $code,
            'movement_type' => $movementType,
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
            'voided_at' => null,
        ];
    }

    private function createTables(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, turo_trip_normalized_id INTEGER NULL, event_code VARCHAR(40) NOT NULL, movement_type VARCHAR(20) NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NULL, voided_at DATETIME NULL)');
    }

    private function dropTables(): void
    {
        foreach (['trip_movement_events', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}
