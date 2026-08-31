<?php

use App\Database\Migrations\AddVehicleRegistrationCompliance;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-30-000014_AddVehicleRegistrationCompliance.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleRegistrationComplianceMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('DROP TABLE IF EXISTS ' . $this->table('fleet_vehicles'));
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), license_plate VARCHAR(32) NULL, purchase_date DATE NULL)');
        $this->connection->table('fleet_vehicles')->insert(['fleet_code' => 'Existing-01', 'license_plate' => 'ABC123']);
    }

    public function testUpAddsNullableFieldsWithoutChangingExistingVehicle(): void
    {
        $this->migration()->up();

        $fields = $this->connection->getFieldNames('fleet_vehicles');
        $vehicle = $this->connection->table('fleet_vehicles')->where('fleet_code', 'Existing-01')->get()->getRowArray();

        $this->assertContains('registered_owner', $fields);
        $this->assertContains('registration_renewal_on', $fields);
        $this->assertContains('safety_inspection_due_on', $fields);
        $this->assertSame('ABC123', $vehicle['license_plate']);
        $this->assertNull($vehicle['registered_owner']);
        $this->assertNull($vehicle['registration_renewal_on']);
        $this->assertNull($vehicle['safety_inspection_due_on']);
    }

    public function testDownRemovesOnlyRegistrationComplianceFields(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $fields = $this->connection->getFieldNames('fleet_vehicles');
        $vehicle = $this->connection->table('fleet_vehicles')->where('fleet_code', 'Existing-01')->get()->getRowArray();

        $this->assertNotContains('registered_owner', $fields);
        $this->assertNotContains('registration_renewal_on', $fields);
        $this->assertNotContains('safety_inspection_due_on', $fields);
        $this->assertContains('license_plate', $fields);
        $this->assertSame('ABC123', $vehicle['license_plate']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function migration(): AddVehicleRegistrationCompliance
    {
        return new AddVehicleRegistrationCompliance(Database::forge($this->connection));
    }
}
