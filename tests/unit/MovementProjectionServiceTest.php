<?php

use App\Repositories\AirportMovementRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\MovementProjectionService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class MovementProjectionServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private MovementProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();

        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($this->connection));
        $airports = new AirportMovementWorkflowService(new AirportMovementRepository($this->connection), $checklists);
        $this->service = new MovementProjectionService(new TuroNormalizedTripRepository($this->connection), $checklists, $airports);
    }

    public function testDryRunApplyAndRepeatedApplyAreIdempotent(): void
    {
        $dryRun = $this->service->projectDate(new DateTimeImmutable('2026-07-19'));

        $this->assertSame(2, $dryRun['trips_examined']);
        $this->assertSame(1, $dryRun['skipped']);
        $this->assertSame(2, $dryRun['would_project_checklists']);
        $this->assertSame(2, $dryRun['would_project_airport_workflows']);
        $this->assertSame(0, $this->connection->table('trip_movement_checklists')->countAllResults());
        $this->assertSame(0, $this->connection->table('airport_movement_workflows')->countAllResults());

        $applied = $this->service->projectDate(new DateTimeImmutable('2026-07-19'), true);

        $this->assertSame(2, $applied['checklists_projected']);
        $this->assertSame(2, $applied['airport_workflows_projected']);
        $this->assertSame(2, $this->connection->table('trip_movement_checklists')->countAllResults());
        $this->assertSame(2, $this->connection->table('airport_movement_workflows')->countAllResults());

        $repeated = $this->service->projectTrip(
            1,
            true,
            'import',
            new DateTimeImmutable('2026-07-19 12:00:00', new DateTimeZone('Pacific/Honolulu')),
        );

        $this->assertSame(4, $repeated['already_present']);
        $this->assertSame(0, $repeated['checklists_projected']);
        $this->assertSame(0, $repeated['airport_workflows_projected']);
        $this->assertSame(2, $this->connection->table('trip_movement_checklists')->countAllResults());
        $this->assertSame(2, $this->connection->table('airport_movement_workflows')->countAllResults());
    }

    public function testTripWithoutLinkedFleetVehicleIsSkipped(): void
    {
        $summary = $this->service->projectTrip(2, true);

        $this->assertSame(1, $summary['trips_examined']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $this->connection->table('trip_movement_checklists')->countAllResults());
    }

    public function testDateProjectionDoesNotProjectMovementOutsideDate(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 1)->update(['ends_at' => '2026-07-20 18:00:00']);

        $summary = $this->service->projectDate(new DateTimeImmutable('2026-07-19'), true);

        $this->assertSame(1, $summary['checklists_projected']);
        $this->assertSame(1, $summary['airport_workflows_projected']);
        $this->assertSame(['pickup'], array_column($this->connection->table('trip_movement_checklists')->get()->getResultArray(), 'movement_type'));
    }

    public function testAutomaticProjectionAppliesStatusAndMovementWindow(): void
    {
        $asOf = new DateTimeImmutable('2026-07-19 12:00:00', new DateTimeZone('Pacific/Honolulu'));

        $eligible = $this->service->projectTrip(1, true, 'import', $asOf);
        $canceled = $this->service->projectTrip(3, true, 'import', $asOf);
        $distant = $this->service->projectTrip(4, true, 'import', $asOf);
        $inProgress = $this->service->projectTrip(5, true, 'import', $asOf);

        $this->assertSame(2, $eligible['checklists_projected']);
        $this->assertSame(2, $canceled['skipped_terminal_status']);
        $this->assertSame(2, $distant['skipped_outside_window']);
        $this->assertSame(1, $inProgress['checklists_projected']);
        $this->assertSame(1, $inProgress['skipped_outside_window']);

        $rows = $this->connection->table('trip_movement_checklists')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['import', 'import', 'import'], array_column($rows, 'projection_source'));
        $this->assertNotEmpty($rows[0]['projected_at']);
    }

    public function testExplicitRangeReplacesAutomaticWindowButNotStatusPolicy(): void
    {
        $booked = $this->service->projectDate(new DateTimeImmutable('2026-09-01'), true);
        $canceled = $this->service->projectDate(new DateTimeImmutable('2026-09-02'), true);

        $this->assertSame(2, $booked['checklists_projected']);
        $this->assertSame(2, $canceled['skipped_terminal_status']);
        $this->assertSame(['backfill', 'backfill'], array_column(
            $this->connection->table('trip_movement_checklists')->where('turo_trip_normalized_id', 4)->get()->getResultArray(),
            'projection_source',
        ));
    }

    public function testExistingHistoryIsPreservedWhenTripBecomesIneligible(): void
    {
        $this->connection->table('trip_movement_checklists')->insert([
            'turo_trip_normalized_id' => 3,
            'fleet_vehicle_id' => 9,
            'movement_type' => 'pickup',
            'scheduled_at' => '2026-07-20 14:00:00',
            'readiness_status' => 'complete',
            'completed_at' => '2026-07-20 14:05:00',
        ]);

        $summary = $this->service->projectDate(new DateTimeImmutable('2026-07-20'), true);

        $this->assertSame(2, $summary['skipped_terminal_status']);
        $this->assertSame(1, $this->connection->table('trip_movement_checklists')->where('turo_trip_normalized_id', 3)->countAllResults());
    }

    private function resetSchema(): void
    {
        foreach (['airport_movement_audits', 'airport_movement_exceptions', 'airport_movement_workflows', 'trip_movement_checklist_audits', 'trip_movement_checklist_items', 'trip_movement_checklists', 'airport_deliveries', 'airports', 'turo_trips_normalized', 'lookup_values', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, turo_trip_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airports') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(10), name VARCHAR(190))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_deliveries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, airport_id INTEGER, turo_trip_normalized_id INTEGER, scheduled_at DATETIME, completed_at DATETIME NULL, delivery_fee_amount DECIMAL(10,2), parking_cost_amount DECIMAL(10,2), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, readiness_status VARCHAR(40), vehicle_disposition VARCHAR(40) NULL, completed_at DATETIME NULL, completion_note TEXT NULL, projection_source VARCHAR(30) NULL, projected_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklists_unique') . ' ON ' . $this->table('trip_movement_checklists') . ' (turo_trip_normalized_id, movement_type, scheduled_at)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, item_code VARCHAR(80), label VARCHAR(190), is_required INTEGER, is_critical INTEGER, applicability VARCHAR(40) DEFAULT \'applicable\', completion_state VARCHAR(40) DEFAULT \'open\', completion_source VARCHAR(40) NULL, completed_at DATETIME NULL, note TEXT NULL, sort_order INTEGER DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklist_items_unique') . ' ON ' . $this->table('trip_movement_checklist_items') . ' (trip_movement_checklist_id, item_code)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, trip_movement_checklist_item_id INTEGER NULL, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_delivery_id INTEGER NULL, turo_trip_normalized_id INTEGER, trip_movement_checklist_id INTEGER NULL, fleet_vehicle_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, workflow_status VARCHAR(40) DEFAULT \'preparing\', garage VARCHAR(120) NULL, terminal VARCHAR(120) NULL, airline_or_flight VARCHAR(120) NULL, parking_level VARCHAR(40) NULL, parking_zone VARCHAR(80) NULL, parking_row VARCHAR(80) NULL, parking_stall VARCHAR(80) NULL, parking_entry_at DATETIME NULL, parking_exit_at DATETIME NULL, vehicle_staged_at DATETIME NULL, vehicle_recovered_at DATETIME NULL, key_card_confirmed_at DATETIME NULL, vehicle_locked_at DATETIME NULL, parking_ticket_location VARCHAR(190) NULL, parking_access_method VARCHAR(120) NULL, estimated_parking_cost_amount DECIMAL(10,2) NULL, actual_parking_cost_amount DECIMAL(10,2) NULL, parking_cost_responsibility VARCHAR(60), guest_instructions TEXT NULL, guest_instructions_sent_at DATETIME NULL, guest_pickup_confirmed_at DATETIME NULL, return_location_reported_at DATETIME NULL, guest_reported_level VARCHAR(40) NULL, guest_reported_zone VARCHAR(80) NULL, guest_reported_row VARCHAR(80) NULL, guest_reported_stall VARCHAR(80) NULL, guest_note TEXT NULL, operator_notes TEXT NULL, completed_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('airport_movement_workflows_unique') . ' ON ' . $this->table('airport_movement_workflows') . ' (turo_trip_normalized_id, movement_type, scheduled_at)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, exception_type VARCHAR(80), severity VARCHAR(40), note TEXT, resolved_at DATETIME NULL, resolution_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
    }

    private function seedData(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 9, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009']);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'booked'],
            ['id' => 2, 'code' => 'in_progress'],
            ['id' => 3, 'code' => 'canceled'],
            ['id' => 4, 'code' => 'completed'],
        ]);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 1, 'fleet_vehicle_id' => 9, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'trip-1', 'guest_name' => 'Guest One', 'starts_at' => '2026-07-19 14:00:00', 'ends_at' => '2026-07-19 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 2, 'fleet_vehicle_id' => null, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'trip-2', 'guest_name' => 'Guest Two', 'starts_at' => '2026-07-19 15:00:00', 'ends_at' => '2026-07-19 19:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 3, 'fleet_vehicle_id' => 9, 'trip_status_lookup_value_id' => 3, 'turo_trip_id' => 'trip-3', 'guest_name' => 'Guest Three', 'starts_at' => '2026-07-20 14:00:00', 'ends_at' => '2026-07-20 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 4, 'fleet_vehicle_id' => 9, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'trip-4', 'guest_name' => 'Guest Four', 'starts_at' => '2026-09-01 14:00:00', 'ends_at' => '2026-09-01 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 5, 'fleet_vehicle_id' => 9, 'trip_status_lookup_value_id' => 2, 'turo_trip_id' => 'trip-5', 'guest_name' => 'Guest Five', 'starts_at' => '2026-07-18 14:00:00', 'ends_at' => '2026-07-21 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 6, 'fleet_vehicle_id' => 9, 'trip_status_lookup_value_id' => 3, 'turo_trip_id' => 'trip-6', 'guest_name' => 'Guest Six', 'starts_at' => '2026-09-02 14:00:00', 'ends_at' => '2026-09-02 18:00:00']);
        $this->connection->table('airports')->insert(['id' => 1, 'code' => 'HNL', 'name' => 'Honolulu International Airport']);
        $this->connection->table('airport_deliveries')->insert(['id' => 1, 'fleet_vehicle_id' => 9, 'airport_id' => 1, 'turo_trip_normalized_id' => 1, 'scheduled_at' => '2026-07-19 14:00:00', 'completed_at' => null, 'delivery_fee_amount' => '0.00', 'parking_cost_amount' => '0.00', 'deleted_at' => null]);
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
