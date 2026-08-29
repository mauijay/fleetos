<?php

use App\Database\Migrations\ConvergeVehicleCatalog;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-29-000012_ConvergeVehicleCatalog.php';

/** @internal */
final class ConvergeVehicleCatalogMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['vehicle_specs', 'vehicle_colors', 'vehicle_body_styles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_body_styles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(120), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_colors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(120), hex_color CHAR(7) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_specs') . ' (id INTEGER PRIMARY KEY, vehicle_body_style_id INTEGER, exterior_vehicle_color_id INTEGER, interior_vehicle_color_id INTEGER)');
    }

    public function testMigrationConvergesCatalogWithoutChangingExistingRelationships(): void
    {
        $this->connection->table('vehicle_body_styles')->insertBatch([
            ['id' => 1, 'code' => 'sedan', 'name' => 'Sedan'],
            ['id' => 2, 'code' => 'suv', 'name' => 'SUV'],
            ['id' => 7, 'code' => 'truck', 'name' => 'Pickup'],
        ]);
        $this->connection->table('vehicle_colors')->insertBatch([
            ['id' => 1, 'code' => 'black', 'name' => 'Black', 'hex_color' => '#000000'],
            ['id' => 3, 'code' => 'gray', 'name' => 'Gray', 'hex_color' => '#808080'],
            ['id' => 4, 'code' => 'silver', 'name' => 'Silver', 'hex_color' => '#C0C0C0'],
            ['id' => 8, 'code' => 'tan', 'name' => 'Beige', 'hex_color' => null],
        ]);
        $this->connection->table('vehicle_specs')->insert(['id' => 1, 'vehicle_body_style_id' => 2, 'exterior_vehicle_color_id' => 3, 'interior_vehicle_color_id' => 4]);

        $migration = new ConvergeVehicleCatalog();
        $migration->up();
        $migration->up();

        $this->assertSame(1, $this->connection->table('vehicle_body_styles')->where('code', 'truck')->countAllResults());
        $this->assertSame(1, $this->connection->table('vehicle_colors')->where('code', 'tan')->countAllResults());
        $this->assertSame(['id' => 7, 'name' => 'Truck'], array_intersect_key($this->connection->table('vehicle_body_styles')->where('code', 'truck')->get()->getRowArray(), ['id' => true, 'name' => true]));
        $this->assertSame(['id' => 8, 'name' => 'Tan', 'hex_color' => '#D2B48C'], array_intersect_key($this->connection->table('vehicle_colors')->where('code', 'tan')->get()->getRowArray(), ['id' => true, 'name' => true, 'hex_color' => true]));
        $this->assertSame(['Sedan', 'SUV'], array_column($this->connection->table('vehicle_body_styles')->whereIn('code', ['sedan', 'suv'])->orderBy('id')->get()->getResultArray(), 'name'));
        $spec = $this->connection->table('vehicle_specs')->where('id', 1)->get()->getRowArray();
        $this->assertSame([2, 3, 4], [(int) $spec['vehicle_body_style_id'], (int) $spec['exterior_vehicle_color_id'], (int) $spec['interior_vehicle_color_id']]);
    }

    public function testMigrationCreatesMissingTruckAndTanRows(): void
    {
        (new ConvergeVehicleCatalog())->up();

        $truck = $this->connection->table('vehicle_body_styles')->where('code', 'truck')->get()->getRowArray();
        $tan = $this->connection->table('vehicle_colors')->where('code', 'tan')->get()->getRowArray();
        $this->assertSame('Truck', $truck['name']);
        $this->assertSame('Tan', $tan['name']);
        $this->assertSame('#D2B48C', $tan['hex_color']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
