<?php

use App\Repositories\AirportMovementRepository;
use App\Repositories\MovementChecklistRepository;
use App\Services\Fleet\AirportInstructionService;
use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class AirportMovementWorkflowServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private AirportMovementWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();
        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($this->connection));
        $this->service = new AirportMovementWorkflowService(new AirportMovementRepository($this->connection), $checklists);
    }

    public function testAirportPickupAndReturnWorkflowsAreCreatedOnce(): void
    {
        $first = $this->service->ensureForDay(new DateTimeImmutable('2026-07-19 08:00:00'));
        $second = $this->service->ensureForDay(new DateTimeImmutable('2026-07-19 08:00:00'));

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(2, $this->connection->table('airport_movement_workflows')->countAllResults());
        $this->assertSame(2, $this->connection->table('trip_movement_checklists')->countAllResults());
    }

    public function testNonAirportDayCreatesNoWorkflows(): void
    {
        $this->assertSame([], $this->service->ensureForDay(new DateTimeImmutable('2026-07-20 08:00:00')));
    }

    public function testStagingDetailsCanBeRecordedAndVehicleCannotBeStagedWithoutConfirmations(): void
    {
        $workflow = $this->pickupWorkflow();

        $this->assertTrue($this->service->recordStaging((int) $workflow['id'], ['parking_level' => '7', 'parking_row' => 'C', 'parking_stall' => '742', 'parking_entry_at' => '2026-07-19 10:00:00']));
        $this->assertFalse($this->service->markStaged((int) $workflow['id'], ['vehicle_parked' => '1']));
        $this->assertTrue($this->service->markStaged((int) $workflow['id'], ['vehicle_parked' => '1', 'vehicle_locked' => '1', 'key_card_placed' => '1', 'parking_details_verified' => '1']));

        $updated = $this->service->workflow((int) $workflow['id']);
        $this->assertSame('staged', $updated['workflow_status']);
        $this->assertNotNull($updated['vehicle_staged_at']);
        $this->assertGreaterThan(0, $this->connection->table('airport_movement_audits')->where('airport_movement_workflow_id', (int) $workflow['id'])->countAllResults());
    }

    public function testInstructionsRequireVerifiedPickupParkingDetails(): void
    {
        $workflow = $this->pickupWorkflow();
        $instructions = (new AirportInstructionService())->pickupInstructions($workflow);
        $this->assertFalse($instructions['complete']);

        $this->service->recordStaging((int) $workflow['id'], ['garage' => 'HNL International Parking Garage', 'parking_level' => '7', 'parking_row' => 'C', 'parking_stall' => '742']);
        $this->assertTrue($this->service->markInstructionsSent((int) $workflow['id']));
        $updated = $this->service->workflow((int) $workflow['id']);

        $this->assertSame('instructions_sent', $updated['workflow_status']);
        $this->assertStringContainsString('Level 7', $updated['guest_instructions']);
        $this->assertStringContainsString('Stall 742', $updated['guest_instructions']);
    }

    public function testGuestPickupAndReturnRecoveryCanBeConfirmed(): void
    {
        $pickup = $this->pickupWorkflow();
        $this->service->recordStaging((int) $pickup['id'], ['parking_level' => '7', 'parking_stall' => '742']);
        $this->service->markStaged((int) $pickup['id'], ['vehicle_parked' => '1', 'vehicle_locked' => '1', 'key_card_placed' => '1', 'parking_details_verified' => '1']);
        $this->assertTrue($this->service->confirmGuestPickup((int) $pickup['id']));

        $return = $this->returnWorkflow();
        $this->assertTrue($this->service->recordReturnLocation((int) $return['id'], ['guest_reported_level' => '8', 'guest_reported_row' => 'D', 'guest_reported_stall' => '812']));
        $this->assertTrue($this->service->confirmVehicleLocated((int) $return['id']));
        $this->assertSame('vehicle_located', $this->service->workflow((int) $return['id'])['workflow_status']);
    }

    public function testParkingCostValidationAndExceptionRecording(): void
    {
        $workflow = $this->pickupWorkflow();

        $this->assertFalse($this->service->recordParkingCost((int) $workflow['id'], 'abc', 'host_operational_cost'));
        $this->assertFalse($this->service->recordParkingCost((int) $workflow['id'], '12.50', 'guest_pays_in_moon_rocks'));
        $this->assertTrue($this->service->recordParkingCost((int) $workflow['id'], '12.50', 'host_operational_cost'));
        $this->assertGreaterThan(0, $this->service->createException((int) $workflow['id'], 'garage_full', 'today', 'Garage was full.'));
        $this->assertSame('exception', $this->service->workflow((int) $workflow['id'])['workflow_status']);
    }

    public function testWorkflowCannotCompleteBeforeLinkedChecklistReadiness(): void
    {
        $workflow = $this->pickupWorkflow();
        $this->assertFalse($this->service->complete((int) $workflow['id']));
    }

    public function testAttentionSummaryCountsIncompleteAirportWork(): void
    {
        $summary = $this->service->attentionSummary(new DateTimeImmutable('2026-07-19 08:00:00'));

        $this->assertTrue($summary['has_airport_work']);
        $this->assertSame(2, $summary['airport_workflows_requiring_action']);
    }

    private function pickupWorkflow(): array
    {
        $this->service->ensureForDay(new DateTimeImmutable('2026-07-19 08:00:00'));
        foreach ($this->service->today(new DateTimeImmutable('2026-07-19 08:00:00')) as $workflow) {
            if ($workflow['movement_type'] === 'pickup') {
                return $workflow;
            }
        }
        return [];
    }

    private function returnWorkflow(): array
    {
        $this->service->ensureForDay(new DateTimeImmutable('2026-07-19 08:00:00'));
        foreach ($this->service->today(new DateTimeImmutable('2026-07-19 08:00:00')) as $workflow) {
            if ($workflow['movement_type'] === 'return') {
                return $workflow;
            }
        }
        return [];
    }

    private function resetSchema(): void
    {
        foreach (['airport_movement_audits', 'airport_movement_exceptions', 'airport_movement_workflows', 'trip_movement_checklist_audits', 'trip_movement_checklist_items', 'trip_movement_checklists', 'airport_deliveries', 'airports', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('airports') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(10), name VARCHAR(190))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_deliveries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, airport_id INTEGER, turo_trip_normalized_id INTEGER, scheduled_at DATETIME, completed_at DATETIME NULL, delivery_fee_amount DECIMAL(10,2), parking_cost_amount DECIMAL(10,2), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, readiness_status VARCHAR(40), vehicle_disposition VARCHAR(40) NULL, completed_at DATETIME NULL, completion_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklists_unique') . ' ON ' . $this->table('trip_movement_checklists') . ' (turo_trip_normalized_id, movement_type, scheduled_at)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, item_code VARCHAR(80), label VARCHAR(190), is_required INTEGER, is_critical INTEGER, applicability VARCHAR(40) DEFAULT \'applicable\', completion_state VARCHAR(40) DEFAULT \'open\', completion_source VARCHAR(40) NULL, completed_at DATETIME NULL, note TEXT NULL, sort_order INTEGER DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklist_items_unique') . ' ON ' . $this->table('trip_movement_checklist_items') . ' (trip_movement_checklist_id, item_code)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, trip_movement_checklist_item_id INTEGER NULL, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_delivery_id INTEGER NULL, turo_trip_normalized_id INTEGER, trip_movement_checklist_id INTEGER NULL, fleet_vehicle_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, workflow_status VARCHAR(40), garage VARCHAR(120) NULL, terminal VARCHAR(120) NULL, airline_or_flight VARCHAR(120) NULL, parking_level VARCHAR(40) NULL, parking_zone VARCHAR(80) NULL, parking_row VARCHAR(80) NULL, parking_stall VARCHAR(80) NULL, parking_entry_at DATETIME NULL, parking_exit_at DATETIME NULL, vehicle_staged_at DATETIME NULL, vehicle_recovered_at DATETIME NULL, key_card_confirmed_at DATETIME NULL, vehicle_locked_at DATETIME NULL, parking_ticket_location VARCHAR(190) NULL, parking_access_method VARCHAR(120) NULL, estimated_parking_cost_amount DECIMAL(10,2) NULL, actual_parking_cost_amount DECIMAL(10,2) NULL, parking_cost_responsibility VARCHAR(60), guest_instructions TEXT NULL, guest_instructions_sent_at DATETIME NULL, guest_pickup_confirmed_at DATETIME NULL, return_location_reported_at DATETIME NULL, guest_reported_level VARCHAR(40) NULL, guest_reported_zone VARCHAR(80) NULL, guest_reported_row VARCHAR(80) NULL, guest_reported_stall VARCHAR(80) NULL, guest_note TEXT NULL, operator_notes TEXT NULL, completed_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('airport_movement_workflows_unique') . ' ON ' . $this->table('airport_movement_workflows') . ' (turo_trip_normalized_id, movement_type, scheduled_at)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, exception_type VARCHAR(80), severity VARCHAR(40), note TEXT, resolved_at DATETIME NULL, resolution_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
    }

    private function seedData(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 9, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 1, 'fleet_vehicle_id' => 9, 'turo_trip_id' => 'trip-1', 'guest_name' => 'Guest One', 'starts_at' => '2026-07-19 14:00:00', 'ends_at' => '2026-07-19 18:00:00']);
        $this->connection->table('airports')->insert(['id' => 1, 'code' => 'HNL', 'name' => 'Honolulu International Airport']);
        $this->connection->table('airport_deliveries')->insert(['id' => 1, 'fleet_vehicle_id' => 9, 'airport_id' => 1, 'turo_trip_normalized_id' => 1, 'scheduled_at' => '2026-07-19 14:00:00', 'completed_at' => null, 'delivery_fee_amount' => '0.00', 'parking_cost_amount' => '0.00', 'deleted_at' => null]);
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
