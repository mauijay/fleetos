<?php

use App\Repositories\VehicleTuroListingRepository;
use App\Services\Fleet\FleetVehicleService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FleetVehicleServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private FleetVehicleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedLookups();
        $this->service = new FleetVehicleService($this->connection, new VehicleTuroListingRepository($this->connection));
    }

    public function testCreateRequiresFleetNumberAndValidLookups(): void
    {
        $missing = $this->validData();
        $missing['fleet_number'] = '';
        $invalid = $this->validData();
        $invalid['vehicle_status_id'] = 999;

        $this->assertSame('This field is required.', $this->service->create($missing)['errors']['fleet_number']);
        $this->assertSame('Choose a valid option.', $this->service->create($invalid)['errors']['vehicle_status_id']);
        $this->assertSame(0, $this->connection->table('fleet_vehicles')->countAllResults());
    }

    public function testCreateNormalizesCatalogCreatesMappingAndAudit(): void
    {
        $result = $this->service->create($this->validData([
            'vehicle_drivetrain_id' => 2,
            'battery_description' => '2.0L Turbo ICE',
        ]), 42, 'turo-11');

        $this->assertTrue($result['success']);
        $vehicle = $this->service->vehicle((int) $result['id']);
        $this->assertSame(11, (int) $vehicle['fleet_number']);
        $this->assertSame('Ford', $vehicle['make_name']);
        $this->assertSame('Bronco', $vehicle['model_name']);
        $this->assertSame(2, (int) $vehicle['vehicle_drivetrain_id']);
        $this->assertSame('2.0L Turbo ICE', $vehicle['battery_description']);
        $this->assertSame('turo-11', $vehicle['turo_vehicle_id']);
        $audit = $this->connection->table('vehicle_turo_listing_audits')->get()->getRowArray();
        $this->assertSame(42, (int) $audit['created_by']);
    }

    public function testFormOptionsKeepAwdAndFourWheelDriveDistinct(): void
    {
        $options = $this->service->formOptions();

        $this->assertSame([
            ['id' => 1, 'name' => 'All-Wheel Drive'],
            ['id' => 2, 'name' => 'Four-Wheel Drive'],
        ], $options['drivetrains']);
    }

    public function testFormOptionsKeepExteriorCatalogBroadAndFilterInteriorColors(): void
    {
        $options = $this->service->formOptions();
        $legacyOptions = $this->service->formOptions(4);

        $this->assertSame(['Black', 'Gray', 'Silver', 'Tan', 'White'], array_column($options['exterior_colors'], 'name'));
        $this->assertSame(['Black', 'Tan', 'White'], array_column($options['interior_colors'], 'name'));
        $this->assertSame(['Black', 'Silver', 'Tan', 'White'], array_column($legacyOptions['interior_colors'], 'name'));
    }

    public function testCreateRejectsNonPreferredInteriorButEditPreservesCurrentLegacyInterior(): void
    {
        $create = $this->service->create($this->validData(['interior_vehicle_color_id' => 3]));
        $this->assertSame('Choose Black, White, or Tan.', $create['errors']['interior_vehicle_color_id']);

        $this->seedLegacyVehicle(4);
        $update = $this->service->update(5, $this->validData([
            'fleet_number' => 10,
            'fleet_code' => 'Legacy',
            'display_name' => 'Legacy Updated',
            'vin' => 'LEGACYVIN',
            'interior_vehicle_color_id' => 4,
        ]));

        $this->assertTrue($update['success']);
        $this->assertSame(4, (int) $this->service->vehicle(5)['interior_vehicle_color_id']);
    }

    public function testCreatingFourWheelDriveBroncoDoesNotChangeAwdTesla(): void
    {
        $this->seedAwdTesla();

        $result = $this->service->create($this->validData(['vehicle_drivetrain_id' => 2]));

        $this->assertTrue($result['success']);
        $tesla = $this->service->vehicle(6);
        $this->assertSame('Tesla', $tesla['make_name']);
        $this->assertSame(1, (int) $tesla['vehicle_drivetrain_id']);
        $this->assertSame(2, (int) $this->service->vehicle((int) $result['id'])['vehicle_drivetrain_id']);
    }

    public function testUniquenessAndCompanyScopeAreEnforced(): void
    {
        $this->assertTrue($this->service->create($this->validData())['success']);
        $duplicateNumber = $this->validData(['fleet_code' => 'Fleet-12', 'vin' => 'VIN12']);
        $otherCompany = $this->validData(['company_id' => 2, 'fleet_code' => 'Fleet-B11', 'vin' => 'VINB11']);

        $this->assertArrayHasKey('fleet_number', $this->service->create($duplicateNumber)['errors']);
        $this->assertTrue($this->service->create($otherCompany)['success']);
        $this->assertArrayHasKey('fleet_code', $this->service->create($this->validData(['fleet_number' => 12]))['errors']);
        $this->assertArrayHasKey('vin', $this->service->create($this->validData(['fleet_number' => 12, 'fleet_code' => 'Fleet-12']))['errors']);
    }

    public function testLegacyNullCanBeAssignedButEstablishedNumberCannotChange(): void
    {
        $this->seedLegacyVehicle();
        $assign = $this->validData(['company_id' => 2, 'fleet_number' => 10, 'fleet_code' => 'Legacy', 'display_name' => 'Legacy', 'vin' => 'LEGACYVIN']);
        $this->assertTrue($this->service->update(5, $assign)['success']);
        $this->assertSame(2, (int) $this->service->vehicle(5)['company_id']);

        $assign['fleet_number'] = 12;
        $result = $this->service->update(5, $assign);
        $this->assertFalse($result['success']);
        $this->assertSame('An assigned fleet number cannot be changed through ordinary editing.', $result['errors']['fleet_number']);
        $this->assertSame(10, (int) $this->service->vehicle(5)['fleet_number']);
    }

    public function testSilentDatabaseFailureRollsBackVehicleUpdate(): void
    {
        $this->seedLegacyVehicle();
        $this->connection->query('CREATE TRIGGER reject_fleet_vehicle_update BEFORE UPDATE ON ' . $this->table('fleet_vehicles') . " BEGIN SELECT RAISE(ABORT, 'update rejected'); END");
        $result = $this->service->update(5, $this->validData(['fleet_number' => 10, 'fleet_code' => 'Legacy', 'display_name' => 'Changed', 'vin' => 'LEGACYVIN']));

        $this->assertFalse($result['success']);
        $this->assertSame('Vehicle update transaction failed.', $result['errors']['database']);
        $this->assertSame('Legacy', $this->service->vehicle(5)['display_name']);
        $this->assertNull($this->service->vehicle(5)['fleet_number']);
        $this->connection->resetTransStatus();
    }

    public function testMappingConflictRollsBackVehicleAndCatalogCreation(): void
    {
        $this->connection->table('vehicle_turo_listings')->insert(['fleet_vehicle_id' => 99, 'turo_vehicle_id' => 'occupied', 'is_active' => 1]);
        $result = $this->service->create($this->validData(), 42, 'occupied');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->connection->table('fleet_vehicles')->countAllResults());
        $this->assertSame(0, $this->connection->table('vehicle_makes')->countAllResults());
    }

    /** @return array<string, mixed> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'company_id' => 1, 'fleet_number' => 11, 'fleet_code' => 'Fleet-11', 'display_name' => 'Bronco 11',
            'model_year' => 2026, 'make_name' => 'Ford', 'model_name' => 'Bronco', 'vehicle_body_style_id' => 1,
            'exterior_vehicle_color_id' => 1, 'interior_vehicle_color_id' => 1, 'vehicle_trim_level_id' => 1,
            'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 2, 'vin' => 'VIN11', 'license_plate' => 'PLATE11',
            'purchase_date' => '2026-08-01', 'in_service_date' => null, 'out_of_service_date' => null,
            'odometer_miles' => 100, 'battery_description' => '', 'seating_capacity' => 5,
        ], $overrides);
    }

    private function seedLegacyVehicle(int $interiorColorId = 1): void
    {
        $this->connection->table('vehicle_makes')->insert(['id' => 1, 'code' => 'legacy', 'name' => 'Legacy']);
        $this->connection->table('vehicle_models')->insert(['id' => 1, 'vehicle_make_id' => 1, 'code' => 'legacy', 'name' => 'Legacy']);
        $this->connection->table('vehicle_specs')->insert(['id' => 1, 'vehicle_model_id' => 1, 'model_year' => 2020, 'vehicle_body_style_id' => 1, 'exterior_vehicle_color_id' => 1, 'interior_vehicle_color_id' => $interiorColorId, 'battery_description' => '', 'seating_capacity' => 5]);
        $this->connection->table('fleet_vehicles')->insert(['id' => 5, 'company_id' => 1, 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1, 'fleet_number' => null, 'fleet_code' => 'Legacy', 'display_name' => 'Legacy', 'vin' => 'LEGACYVIN']);
    }

    private function seedAwdTesla(): void
    {
        $this->connection->table('vehicle_makes')->insert(['id' => 2, 'code' => 'tesla', 'name' => 'Tesla']);
        $this->connection->table('vehicle_models')->insert(['id' => 2, 'vehicle_make_id' => 2, 'code' => 'model_y', 'name' => 'Model Y']);
        $this->connection->table('vehicle_specs')->insert(['id' => 2, 'vehicle_model_id' => 2, 'model_year' => 2026, 'vehicle_body_style_id' => 1, 'exterior_vehicle_color_id' => 1, 'interior_vehicle_color_id' => 1, 'battery_description' => '', 'seating_capacity' => 5]);
        $this->connection->table('fleet_vehicles')->insert(['id' => 6, 'company_id' => 1, 'vehicle_spec_id' => 2, 'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1, 'fleet_number' => 6, 'fleet_code' => 'Tesla-6', 'display_name' => 'Tesla 6', 'vin' => 'TESLAVIN6']);
    }

    private function seedLookups(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A', 'is_active' => 1], ['id' => 2, 'name' => 'Company B', 'is_active' => 1]]);
        $this->connection->table('vehicle_body_styles')->insert(['id' => 1, 'code' => 'suv', 'name' => 'SUV']);
        $this->connection->table('vehicle_colors')->insertBatch([
            ['id' => 1, 'code' => 'black', 'name' => 'Black'],
            ['id' => 2, 'code' => 'white', 'name' => 'White'],
            ['id' => 3, 'code' => 'gray', 'name' => 'Gray'],
            ['id' => 4, 'code' => 'silver', 'name' => 'Silver'],
            ['id' => 5, 'code' => 'tan', 'name' => 'Tan'],
        ]);
        $this->connection->table('vehicle_trim_levels')->insert(['id' => 1, 'name' => 'Base']);
        $this->connection->table('vehicle_drivetrains')->insertBatch([
            ['id' => 1, 'name' => 'All-Wheel Drive'],
            ['id' => 2, 'name' => 'Four-Wheel Drive'],
        ]);
        $this->connection->table('vehicle_statuses')->insertBatch([['id' => 1, 'code' => 'active', 'name' => 'Active'], ['id' => 2, 'code' => 'pending_onboarding', 'name' => 'Pending Onboarding']]);
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(120), is_active INTEGER, deleted_at DATETIME NULL)');
        foreach (['vehicle_body_styles', 'vehicle_colors'] as $table) {
            $this->connection->query('CREATE TABLE ' . $this->table($table) . ' (id INTEGER PRIMARY KEY, code VARCHAR(80), name VARCHAR(120))');
        }
        foreach (['vehicle_trim_levels', 'vehicle_drivetrains'] as $table) {
            $this->connection->query('CREATE TABLE ' . $this->table($table) . ' (id INTEGER PRIMARY KEY, name VARCHAR(120))');
        }
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_statuses') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80), name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_makes') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80), name VARCHAR(120), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_models') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_make_id INTEGER, code VARCHAR(80), name VARCHAR(120), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_specs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_model_id INTEGER, model_year INTEGER, vehicle_body_style_id INTEGER, exterior_vehicle_color_id INTEGER, interior_vehicle_color_id INTEGER, battery_description VARCHAR(120), seating_capacity INTEGER, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, vehicle_spec_id INTEGER, vehicle_trim_level_id INTEGER, vehicle_drivetrain_id INTEGER, vehicle_status_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80) UNIQUE, display_name VARCHAR(150), vin VARCHAR(32) NULL UNIQUE, license_plate VARCHAR(32) NULL, purchase_date DATE NULL, in_service_date DATE NULL, out_of_service_date DATE NULL, odometer_miles INTEGER NULL, sort_order INTEGER DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL, UNIQUE(company_id, fleet_number))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_vehicle_id VARCHAR(80) UNIQUE, source_system VARCHAR(40), is_active INTEGER, listed_at DATETIME NULL, unlisted_at DATETIME NULL, mapping_note TEXT NULL, mapped_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listing_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_turo_listing_id INTEGER, action VARCHAR(40), turo_vehicle_id VARCHAR(80), old_fleet_vehicle_id INTEGER NULL, new_fleet_vehicle_id INTEGER NULL, note TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
    }

    private function resetSchema(): void
    {
        foreach (['vehicle_turo_listing_audits', 'vehicle_turo_listings', 'fleet_vehicles', 'vehicle_specs', 'vehicle_models', 'vehicle_makes', 'vehicle_statuses', 'vehicle_drivetrains', 'vehicle_trim_levels', 'vehicle_colors', 'vehicle_body_styles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
