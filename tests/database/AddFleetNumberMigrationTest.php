<?php

use App\Database\Migrations\AddFleetNumberToFleetVehicles;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-27-000010_AddFleetNumberToFleetVehicles.php';

/**
 * @internal
 */
final class AddFleetNumberMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->connection->query('DROP TABLE IF EXISTS ' . $this->table('fleet_vehicles'));
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, fleet_code VARCHAR(80) NOT NULL)');
    }

    public function testMigrationAllowsLegacyNullsAndScopesUniquenessByCompany(): void
    {
        $migration = new AddFleetNumberToFleetVehicles();
        $migration->up();

        $this->connection->table('fleet_vehicles')->insertBatch([
            ['company_id' => 1, 'fleet_code' => 'Legacy-1', 'fleet_number' => null],
            ['company_id' => 1, 'fleet_code' => 'Legacy-2', 'fleet_number' => null],
            ['company_id' => 1, 'fleet_code' => 'Fleet-11', 'fleet_number' => 11],
            ['company_id' => 2, 'fleet_code' => 'Fleet-11-B', 'fleet_number' => 11],
        ]);

        $this->assertSame(4, $this->connection->table('fleet_vehicles')->countAllResults());

        $this->expectException(Throwable::class);
        $this->connection->table('fleet_vehicles')->insert([
            'company_id' => 1,
            'fleet_code' => 'Duplicate-11',
            'fleet_number' => 11,
        ]);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
