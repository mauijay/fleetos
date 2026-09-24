<?php

use App\Database\Migrations\CreateVehicleHealthObservationFoundation;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-24-000026_CreateVehicleHealthObservationFoundation.php';

/** @internal */
final class VehicleHealthObservationFoundationMigrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'vehicle_health_policies',
        'vehicle_odometer_observations',
        'vehicle_tire_pressure_observations',
        'vehicle_health_observations',
        'fleet_vehicles',
        'companies',
    ];

    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (self::TABLES as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    public function testCreatesFourEmptyTablesWithExpectedKeys(): void
    {
        $this->migration()->up();

        foreach (array_slice(self::TABLES, 0, 4) as $table) {
            $this->assertTrue($this->connection->tableExists($table));
            $this->assertSame(0, $this->connection->table($table)->countAllResults());
        }
        $observationIndexes = array_keys($this->connection->getIndexData('vehicle_health_observations'));
        $this->assertContains('vehicle_health_observation_supersedes_unique', $observationIndexes);
        $this->assertContains('vehicle_health_observation_source_unique', $observationIndexes);
        $this->assertContains('vehicle_health_observation_authority_idx', $observationIndexes);
        $this->assertContains('vehicle_health_observation_vehicle_idx', $observationIndexes);
        $this->assertNotEmpty($this->connection->getForeignKeyData('vehicle_health_observations'));
        $this->assertNotEmpty($this->connection->getForeignKeyData('vehicle_tire_pressure_observations'));
        $this->assertNotEmpty($this->connection->getForeignKeyData('vehicle_odometer_observations'));
        $this->assertNotEmpty($this->connection->getForeignKeyData('vehicle_health_policies'));
        foreach (['lf_psi', 'rf_psi', 'lr_psi', 'rr_psi', 'recommended_psi'] as $field) {
            $this->assertSame('SMALLINT', strtoupper($this->fieldType('vehicle_tire_pressure_observations', $field)));
        }
        foreach (['recommended_psi', 'acceptable_min_psi', 'acceptable_max_psi', 'safety_min_psi', 'safety_max_psi'] as $field) {
            $this->assertSame('SMALLINT', strtoupper($this->fieldType('vehicle_health_policies', $field)));
        }
    }

    public function testNullableExternalIdentityAllowsManualRowsAndEnforcesExternalIdentity(): void
    {
        $this->migration()->up();
        $this->connection->table('companies')->insert(['id' => 1, 'name' => 'Company A']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 10, 'company_id' => 1]);
        $row = [
            'company_id' => 1,
            'fleet_vehicle_id' => 10,
            'observation_code' => 'odometer',
            'observed_at' => '2026-09-23 10:00:00',
            'received_at' => '2026-09-23 10:01:00',
            'source' => 'manual',
            'created_at' => '2026-09-23 10:01:00',
        ];
        $this->assertTrue($this->connection->table('vehicle_health_observations')->insert($row));
        $this->assertTrue($this->connection->table('vehicle_health_observations')->insert($row));

        $external = array_merge($row, ['source' => 'import', 'source_external_id' => 'reading-1']);
        $this->assertTrue($this->connection->table('vehicle_health_observations')->insert($external));
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->connection->table('vehicle_health_observations')->insert($external);
    }

    public function testRollbackDropsOnlyFoundationTables(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        foreach (array_slice(self::TABLES, 0, 4) as $table) {
            $this->assertFalse($this->connection->tableExists($table));
        }
        $this->assertTrue($this->connection->tableExists('companies'));
        $this->assertTrue($this->connection->tableExists('fleet_vehicles'));
    }

    private function migration(): CreateVehicleHealthObservationFoundation
    {
        return new CreateVehicleHealthObservationFoundation(Database::forge($this->connection));
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function fieldType(string $table, string $field): string
    {
        foreach ($this->connection->getFieldData($table) as $metadata) {
            if ($metadata->name === $field) {
                return (string) $metadata->type;
            }
        }

        $this->fail('Missing field metadata for ' . $table . '.' . $field);
    }
}
