<?php

use App\Database\Migrations\ConvergeFleetMasterData;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-28-000011_ConvergeFleetMasterData.php';

/** @internal */
final class ConvergeFleetMasterDataMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        foreach (['fleet_vehicles', 'vehicle_drivetrains', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(190), legal_name VARCHAR(190), slug VARCHAR(120) UNIQUE, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_drivetrains') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(120), motor_count INTEGER NULL, sort_order INTEGER, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, vehicle_drivetrain_id INTEGER)');
    }

    public function testMigrationConvergesOnlyIntendedCompanyAndCreatesFourWheelDrive(): void
    {
        $this->connection->table('companies')->insertBatch([
            ['id' => 1, 'name' => 'GO808 FleetOS', 'legal_name' => 'GO808 FleetOS', 'slug' => 'go808-fleetos'],
            ['id' => 2, 'name' => 'Unrelated Company', 'legal_name' => 'Unrelated Company LLC', 'slug' => 'unrelated-company'],
        ]);
        $this->connection->table('vehicle_drivetrains')->insert(['id' => 1, 'code' => 'awd', 'name' => 'All-Wheel Drive', 'motor_count' => 2, 'sort_order' => 10]);
        $this->connection->table('fleet_vehicles')->insert(['id' => 10, 'vehicle_drivetrain_id' => 1]);

        (new ConvergeFleetMasterData())->up();

        $company = $this->connection->table('companies')->where('id', 1)->get()->getRowArray();
        $unrelated = $this->connection->table('companies')->where('id', 2)->get()->getRowArray();
        $awd = $this->connection->table('vehicle_drivetrains')->where('code', 'awd')->get()->getRowArray();
        $fourWheelDrive = $this->connection->table('vehicle_drivetrains')->where('code', '4wd')->get()->getRowArray();

        $this->assertSame('808biz, Inc.', $company['name']);
        $this->assertSame('808biz, Inc.', $company['legal_name']);
        $this->assertSame('go808-fleetos', $company['slug']);
        $this->assertSame('Unrelated Company', $unrelated['name']);
        $this->assertSame('All-Wheel Drive', $awd['name']);
        $this->assertSame(2, (int) $awd['motor_count']);
        $this->assertSame('Four-Wheel Drive', $fourWheelDrive['name']);
        $this->assertSame(1, (int) $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRowArray()['vehicle_drivetrain_id']);
    }

    public function testMigrationConvergesExistingFourWheelDriveWithoutDuplicatingIt(): void
    {
        $this->connection->table('companies')->insert(['id' => 1, 'name' => '808biz, Inc.', 'legal_name' => '808biz, Inc.', 'slug' => 'go808-fleetos']);
        $this->connection->table('vehicle_drivetrains')->insertBatch([
            ['id' => 1, 'code' => 'awd', 'name' => 'All-Wheel Drive', 'motor_count' => 2, 'sort_order' => 10],
            ['id' => 4, 'code' => '4wd', 'name' => '4 wheel drive', 'motor_count' => 4, 'sort_order' => 99],
        ]);

        $migration = new ConvergeFleetMasterData();
        $migration->up();
        $migration->up();

        $this->assertSame(1, $this->connection->table('vehicle_drivetrains')->where('code', '4wd')->countAllResults());
        $fourWheelDrive = $this->connection->table('vehicle_drivetrains')->where('code', '4wd')->get()->getRowArray();
        $this->assertSame(4, (int) $fourWheelDrive['id']);
        $this->assertSame('Four-Wheel Drive', $fourWheelDrive['name']);
        $this->assertNull($fourWheelDrive['motor_count']);
        $this->assertSame(15, (int) $fourWheelDrive['sort_order']);
        $this->assertSame('All-Wheel Drive', $this->connection->table('vehicle_drivetrains')->where('code', 'awd')->get()->getRowArray()['name']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
