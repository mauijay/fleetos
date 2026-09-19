<?php

use App\Database\Migrations\CreateVehicleRecoveryExceptions;
use App\Repositories\VehicleRecoveryExceptionRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-17-000022_CreateVehicleRecoveryExceptions.php';

/** @internal */
final class VehicleRecoveryExceptionsMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['vehicle_recovery_exceptions', 'trip_movement_checklists', 'trip_movement_events', 'turo_trips_normalized', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->connection->getPrefix() . $table);
        }
        $prefix = $this->connection->getPrefix();
        $this->connection->query('CREATE TABLE ' . $prefix . 'companies (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'fleet_vehicles (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_code VARCHAR(80), display_name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'turo_trips_normalized (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'trip_movement_events (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER, event_code VARCHAR(40), voided_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'trip_movement_checklists (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(20))');
        $this->connection->table('companies')->insertBatch([['id' => 1], ['id' => 2]]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 11, 'company_id' => 1, 'fleet_code' => 'LOCAL-RETURN-A', 'display_name' => 'Synthetic A'],
            ['id' => 22, 'company_id' => 2, 'fleet_code' => 'LOCAL-RETURN-B', 'display_name' => 'Synthetic B'],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([['id' => 101, 'fleet_vehicle_id' => 11], ['id' => 202, 'fleet_vehicle_id' => 22]]);
        $this->connection->table('trip_movement_events')->insertBatch([
            ['id' => 301, 'company_id' => 1, 'fleet_vehicle_id' => 11, 'turo_trip_normalized_id' => 101, 'event_code' => 'vehicle_recovered'],
            ['id' => 302, 'company_id' => 2, 'fleet_vehicle_id' => 22, 'turo_trip_normalized_id' => 202, 'event_code' => 'vehicle_recovered'],
        ]);
        $this->connection->table('trip_movement_checklists')->insertBatch([
            ['id' => 401, 'turo_trip_normalized_id' => 101, 'fleet_vehicle_id' => 11, 'movement_type' => 'return'],
            ['id' => 402, 'turo_trip_normalized_id' => 202, 'fleet_vehicle_id' => 22, 'movement_type' => 'return'],
        ]);
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    public function testForwardIndexesForeignKeysUniquenessScopingResolutionAndRollback(): void
    {
        $migration = new CreateVehicleRecoveryExceptions(Database::forge($this->connection));
        $migration->up();
        $this->assertTrue($this->connection->tableExists('vehicle_recovery_exceptions'));
        $this->assertCount(4, $this->connection->getForeignKeyData('vehicle_recovery_exceptions'));
        $indexes = array_keys($this->connection->getIndexData('vehicle_recovery_exceptions'));
        $this->assertContains('recovery_exceptions_event_code_unique', $indexes);
        $this->assertContains('recovery_exceptions_company_status_vehicle', $indexes);
        $this->assertContains('recovery_exceptions_company_trip', $indexes);
        $this->assertContains('recovery_exceptions_company_event', $indexes);

        $repo = new VehicleRecoveryExceptionRepository($this->connection);
        $damageId = $repo->createForRecovery(1, 101, 11, 301, 'damage', 'Synthetic scratch', 7);
        $keyId = $repo->createForRecovery(1, 101, 11, 301, 'missing_key', null, 7);
        $this->assertCount(2, $repo->openForCompany(1));
        $this->assertSame([$damageId, $keyId], array_map('intval', array_column($repo->forTrip(1, 101), 'id')));
        $this->assertSame([], $repo->forTrip(2, 101));
        $this->assertFalse($repo->resolveForCompany(2, 101, 11, $damageId, 8, null));
        $this->assertFalse($repo->resolveForCompany(1, 202, 11, $damageId, 8, null));
        try {
            $repo->createForRecovery(2, 101, 11, 301, 'other', 'Wrong company', 8);
            $this->fail('Cross-company recovery must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $repo->createForRecovery(1, 101, 11, 301, 'damage', 'Duplicate', 7);
            $this->fail('Duplicate exception code must be rejected by the database.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
        $this->assertTrue($repo->resolveForCompany(1, 101, 11, $damageId, 8, 'Reviewed in Turo'));
        $this->assertFalse($repo->resolveForCompany(1, 101, 11, $damageId, 8, 'Replay'));
        $this->assertSame([$keyId], array_map('intval', array_column($repo->openForCompany(1), 'id')));
        $history = $repo->forTrip(1, 101);
        $this->assertSame('resolved', $history[0]['status']);
        $this->assertSame('8', (string) $history[0]['resolved_by']);
        $this->assertNotNull($history[0]['resolved_at']);
        $this->assertSame('Reviewed in Turo', $history[0]['resolution_note']);

        $this->connection->table('trip_movement_events')->where('id', 301)->update(['voided_at' => '2026-09-17 12:00:00']);
        $this->assertSame([], $repo->openForCompany(1));
        $this->assertCount(2, $repo->forTrip(1, 101));
        $this->assertSame('2026-09-17 12:00:00', $repo->forTrip(1, 101)[1]['recovery_voided_at']);

        $migration->down();
        $this->assertFalse($this->connection->tableExists('vehicle_recovery_exceptions'));
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
    }
}
