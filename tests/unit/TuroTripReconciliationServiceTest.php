<?php

use App\Repositories\AirportMovementRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\MovementProjectionService;
use App\Services\Fleet\TripMovementChecklistService;
use App\Services\Fleet\VehiclePositioningPlanService;
use App\Services\Turo\TuroTripImportService;
use App\Services\Turo\TuroTripReconciliationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class TuroTripReconciliationServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroTripReconciliationService $service;
    private TuroTripImportService $importer;
    private TuroTripReconciliationPositioningPlanSpy $positioningPlans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedLookups();
        $this->seedFleetMapping();

        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($this->connection));
        $airports = new AirportMovementWorkflowService(new AirportMovementRepository($this->connection), $checklists);
        $projection = new MovementProjectionService(new TuroNormalizedTripRepository($this->connection), $checklists, $airports);

        $this->positioningPlans = new TuroTripReconciliationPositioningPlanSpy();
        $this->importer = new TuroTripImportService(
            $this->connection,
            movementProjection: $projection,
            positioningPlans: $this->positioningPlans,
        );
        $this->service = new TuroTripReconciliationService(
            new TuroVehicleMappingIssueRepository($this->connection),
            new TuroImportErrorRepository($this->connection),
            new TuroNormalizedTripRepository($this->connection),
            $this->importer,
        );
    }

    public function testMappedPreviouslyUnmatchedRowBecomesEligible(): void
    {
        $this->insertVehicleIssue(1, 'trip-ready', 'turo-009');

        $preview = $this->service->preview('turo-009');

        $this->assertSame(1, $preview['summary']['ready']);
        $this->assertSame('ready', $preview['items'][0]['classification']);
    }

    public function testValidRowIsReprocessedThroughImportLogicAndUsesMapping(): void
    {
        $this->insertVehicleIssue(1, 'trip-ready', 'turo-009');

        $result = $this->service->execute('turo-009');
        $trip = $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-ready')->get()->getRowArray();

        $this->assertSame(1, $result['summary']['successfully_imported'], (string) ($result['results'][0]['message'] ?? 'No reconciliation message.'));
        $this->assertSame(9, (int) $trip['fleet_vehicle_id']);
        $this->assertSame(1, $this->connection->table('trip_month_allocations')->where('turo_trip_normalized_id', (int) $trip['id'])->countAllResults());
        $this->assertSame(0, $this->connection->table('trip_movement_checklists')->where('turo_trip_normalized_id', (int) $trip['id'])->countAllResults());
        $this->assertSame([[9, 'material_trip_reconciliation', null]], $this->positioningPlans->invalidations);
    }

    public function testOnlyCreatedOrMateriallyUpdatedTripInvalidatesPositioningPlan(): void
    {
        $payload = [
            'trip_id' => 'trip-material',
            'vehicle_id' => 'turo-009',
            'guest_name' => 'Guest Material',
            'status' => 'Completed',
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-03 10:00:00',
            'gross_revenue' => '$600.00',
            'host_payout' => '$500.00',
            'delivery_fee' => '$20.00',
            'pickup_location' => 'HNL Airport',
            'return_location' => 'Fleet home',
        ];

        $this->importer->importStoredRow(1, 20, $payload, actorUserId: null);
        $this->assertSame([[9, 'material_trip_reconciliation', null]], $this->positioningPlans->invalidations);

        $this->positioningPlans->invalidations = [];
        $this->importer->importStoredRow(1, 21, $payload, actorUserId: null);
        $this->assertSame([], $this->positioningPlans->invalidations);

        $payload['starts_at'] = '2026-07-01 11:00:00';
        $this->importer->importStoredRow(1, 22, $payload, actorUserId: null);
        $this->assertSame([[9, 'material_trip_reconciliation', null]], $this->positioningPlans->invalidations);
    }

    public function testReprocessingIsIdempotentAndDoesNotDuplicateAllocations(): void
    {
        $this->insertVehicleIssue(1, 'trip-ready', 'turo-009');

        $this->service->execute('turo-009');
        $this->connection->table('turo_import_errors')->where('id', 1)->update(['resolved_at' => null]);
        $this->service->execute('turo-009');

        $trip = $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-ready')->get()->getRowArray();
        $this->assertSame(1, $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-ready')->countAllResults(), (string) ($this->connection->table('turo_import_errors')->where('id', 1)->get()->getRowArray()['reconciliation_message'] ?? 'No reconciliation message.'));
        $this->assertSame(1, $this->connection->table('trip_month_allocations')->where('turo_trip_normalized_id', (int) $trip['id'])->countAllResults());
        $this->assertSame(0, $this->connection->table('trip_movement_checklists')->where('turo_trip_normalized_id', (int) $trip['id'])->countAllResults());
    }

    public function testExistingEquivalentTripIsRecognizedSafely(): void
    {
        $this->insertVehicleIssue(1, 'trip-equivalent', 'turo-009');
        $this->insertExistingTrip('trip-equivalent', 9, '500.00');

        $preview = $this->service->preview('turo-009');
        $result = $this->service->execute('turo-009');

        $this->assertSame('already_imported_equivalent', $preview['items'][0]['classification']);
        $this->assertSame(1, $result['summary']['already_imported_equivalent']);
        $this->assertNotNull($this->connection->table('turo_import_errors')->where('id', 1)->get()->getRowArray()['resolved_at']);
    }

    public function testExistingConflictingTripIsNotOverwritten(): void
    {
        $this->insertVehicleIssue(1, 'trip-conflict', 'turo-009');
        $this->insertExistingTrip('trip-conflict', 9, '100.00');

        $result = $this->service->execute('turo-009');
        $trip = $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-conflict')->get()->getRowArray();

        $this->assertSame(1, $result['summary']['already_imported_conflict']);
        $this->assertSame('100.00', number_format((float) $trip['host_payout_amount'], 2, '.', ''));
        $this->assertNull($this->connection->table('turo_import_errors')->where('id', 1)->get()->getRowArray()['resolved_at']);
    }

    public function testMissingPayloadAndInvalidValuesRemainUnresolved(): void
    {
        $this->connection->table('turo_import_errors')->insert($this->issueRow(1, null));
        $this->insertVehicleIssue(2, 'trip-invalid', 'turo-009', ['ends_at' => 'bad date']);

        $result = $this->service->execute('turo-009');

        $this->assertSame(1, $result['summary']['invalid_source_data']);
        $this->assertNull($this->connection->table('turo_import_errors')->where('id', 2)->get()->getRowArray()['resolved_at']);
    }

    public function testBulkReprocessingAffectsOnlySelectedTuroVehicleId(): void
    {
        $this->insertVehicleIssue(1, 'trip-009', 'turo-009');
        $this->insertVehicleIssue(2, 'trip-008', 'turo-008');

        $this->service->execute('turo-009');

        $this->assertSame(1, $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-009')->countAllResults());
        $this->assertSame(0, $this->connection->table('turo_trips_normalized')->where('turo_trip_id', 'trip-008')->countAllResults());
    }

    public function testCommandCenterCountsAwaitingReconciliation(): void
    {
        $this->insertVehicleIssue(1, 'trip-ready', 'turo-009');

        $summary = $this->service->attentionSummary();

        $this->assertTrue($summary['has_reconciliation_work']);
        $this->assertSame(1, $summary['awaiting_reconciliation']);
    }

    private function resetSchema(): void
    {
        foreach (['trip_movement_checklist_audits', 'trip_movement_checklist_items', 'trip_movement_checklists', 'airport_movement_exceptions', 'airport_movement_audits', 'scheduled_movement_locations', 'airport_movement_workflows', 'airport_deliveries', 'airports', 'turo_import_reprocess_attempts', 'audit_logs', 'trip_month_allocations', 'turo_trips_normalized', 'turo_trip_raw', 'turo_import_errors', 'turo_import_batches', 'vehicle_turo_listings', 'fleet_vehicles', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(120), is_active INTEGER DEFAULT 1)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NOT NULL, turo_vehicle_id VARCHAR(80), is_active INTEGER DEFAULT 1)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source_filename VARCHAR(190), started_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trip_raw') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, external_trip_id VARCHAR(80), external_vehicle_id VARCHAR(80), row_number INTEGER, row_hash VARCHAR(128), raw_payload TEXT, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NULL, turo_trip_raw_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80) NULL, guest_name VARCHAR(190) NULL, booked_at DATETIME NULL, starts_at DATETIME, ends_at DATETIME, canceled_at DATETIME NULL, trip_days DECIMAL(8,3), billable_days DECIMAL(8,3), gross_revenue_amount DECIMAL(10,2), host_payout_amount DECIMAL(10,2), delivery_fee_amount DECIMAL(10,2), discount_amount DECIMAL(10,2), reimbursement_amount DECIMAL(10,2), airport_fee_amount DECIMAL(10,2), currency_code CHAR(3), is_forecast INTEGER, normalized_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('scheduled_movement_locations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER NULL, movement_type VARCHAR(20), location_class VARCHAR(40), source_text VARCHAR(500) NULL, airport_id INTEGER NULL, airport_movement_workflow_id INTEGER NULL, classification_source VARCHAR(40), classification_status VARCHAR(40), created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE (turo_trip_normalized_id, movement_type))');
        $this->connection->query('CREATE TABLE ' . $this->table('airports') . ' (id INTEGER PRIMARY KEY, code VARCHAR(20), name VARCHAR(190) NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_deliveries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, airport_id INTEGER, turo_trip_normalized_id INTEGER, scheduled_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, resolved_at DATETIME NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, readiness_status VARCHAR(40), vehicle_disposition VARCHAR(40) NULL, completed_at DATETIME NULL, completion_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE (turo_trip_normalized_id, movement_type, scheduled_at))');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, item_code VARCHAR(80), label VARCHAR(190), is_required INTEGER, is_critical INTEGER, applicability VARCHAR(40) DEFAULT \'applicable\', completion_state VARCHAR(40) DEFAULT \'open\', completion_source VARCHAR(40) NULL, completed_at DATETIME NULL, note TEXT NULL, sort_order INTEGER DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE (trip_movement_checklist_id, item_code))');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, trip_movement_checklist_item_id INTEGER NULL, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_month_allocations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER NULL, allocation_month DATE, allocation_starts_at DATETIME, allocation_ends_at DATETIME, allocated_trip_days DECIMAL(8,3), allocated_billable_days DECIMAL(8,3), allocated_gross_revenue_amount DECIMAL(10,2), allocated_host_payout_amount DECIMAL(10,2), allocated_delivery_fee_amount DECIMAL(10,2), allocated_reimbursement_amount DECIMAL(10,2), is_forecast INTEGER, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, raw_table VARCHAR(80) NULL, raw_row_id INTEGER NULL, row_number INTEGER NULL, error_code VARCHAR(120), message TEXT, raw_payload TEXT NULL, created_at DATETIME NULL, resolved_at DATETIME NULL, resolution_note TEXT NULL, reconciliation_status VARCHAR(40) NULL, reconciliation_result_code VARCHAR(80) NULL, reconciliation_message TEXT NULL, reconciled_at DATETIME NULL, reconciled_trip_id INTEGER NULL, last_reprocess_attempt_at DATETIME NULL, reprocess_attempt_count INTEGER DEFAULT 0, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_reprocess_attempts') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_error_id INTEGER, turo_vehicle_id VARCHAR(80), result_code VARCHAR(80), message TEXT, turo_trip_normalized_id INTEGER NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)');
    }

    private function seedLookups(): void
    {
        $this->connection->table('lookup_types')->insertBatch([
            ['id' => 1, 'code' => 'trip_status'],
            ['id' => 2, 'code' => 'audit_action'],
        ]);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 101, 'lookup_type_id' => 1, 'code' => 'completed', 'name' => 'Completed', 'is_active' => 1],
            ['id' => 102, 'lookup_type_id' => 1, 'code' => 'booked', 'name' => 'Booked', 'is_active' => 1],
            ['id' => 201, 'lookup_type_id' => 2, 'code' => 'created', 'name' => 'Created', 'is_active' => 1],
            ['id' => 202, 'lookup_type_id' => 2, 'code' => 'updated', 'name' => 'Updated', 'is_active' => 1],
            ['id' => 203, 'lookup_type_id' => 2, 'code' => 'imported', 'name' => 'Imported', 'is_active' => 1],
        ]);
    }

    private function seedFleetMapping(): void
    {
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 8, 'fleet_code' => 'Spaceship-008', 'display_name' => 'Spaceship-008', 'deleted_at' => null],
            ['id' => 9, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009', 'deleted_at' => null],
        ]);
        $this->connection->table('vehicle_turo_listings')->insertBatch([
            ['fleet_vehicle_id' => 9, 'turo_vehicle_id' => 'turo-009', 'is_active' => 1],
            ['fleet_vehicle_id' => 8, 'turo_vehicle_id' => 'turo-008', 'is_active' => 1],
        ]);
        $this->connection->table('turo_import_batches')->insert(['id' => 1, 'source_filename' => 'july.csv', 'started_at' => '2026-07-19 08:00:00']);
    }

    private function insertVehicleIssue(int $id, string $tripId, string $turoVehicleId, array $overrides = []): void
    {
        $payload = array_merge([
            'trip_id' => $tripId,
            'vehicle_id' => $turoVehicleId,
            'guest_name' => 'Guest ' . $id,
            'status' => 'Completed',
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-03 10:00:00',
            'gross_revenue' => '$600.00',
            'host_payout' => '$500.00',
            'delivery_fee' => '$20.00',
        ], $overrides);

        $this->connection->table('turo_import_errors')->insert($this->issueRow($id, $payload));
    }

    private function insertExistingTrip(string $tripId, int $fleetVehicleId, string $hostPayout): void
    {
        $this->connection->table('turo_trips_normalized')->insert([
            'fleet_vehicle_id' => $fleetVehicleId,
            'turo_trip_raw_id' => null,
            'trip_status_lookup_value_id' => 101,
            'turo_trip_id' => $tripId,
            'turo_reservation_id' => null,
            'guest_name' => 'Guest 1',
            'booked_at' => null,
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-03 10:00:00',
            'canceled_at' => null,
            'trip_days' => '2.000',
            'billable_days' => '2.000',
            'gross_revenue_amount' => '600.00',
            'host_payout_amount' => $hostPayout,
            'delivery_fee_amount' => '20.00',
            'discount_amount' => '0.00',
            'reimbursement_amount' => '0.00',
            'airport_fee_amount' => '0.00',
            'currency_code' => 'USD',
            'is_forecast' => 0,
            'normalized_at' => '2026-07-19 08:00:00',
            'created_at' => '2026-07-19 08:00:00',
            'updated_at' => '2026-07-19 08:00:00',
            'deleted_at' => null,
        ]);
    }

    private function issueRow(int $id, ?array $payload): array
    {
        return [
            'id' => $id,
            'turo_import_batch_id' => 1,
            'raw_table' => 'turo_trip_raw',
            'raw_row_id' => null,
            'row_number' => $id + 1,
            'error_code' => 'vehicle_unmatched',
            'message' => 'Trip imported, but no fleet vehicle could be matched.',
            'raw_payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => '2026-07-19 08:0' . $id . ':00',
            'resolved_at' => null,
        ];
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}

final class TuroTripReconciliationPositioningPlanSpy extends VehiclePositioningPlanService
{
    /** @var array<int, array{int, string, int|null}> */
    public array $invalidations = [];

    public function invalidateForWrite(int $vehicleId, string $reason, ?int $actorUserId): int
    {
        $this->invalidations[] = [$vehicleId, $reason, $actorUserId];
        return 1;
    }
}
