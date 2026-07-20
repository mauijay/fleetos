<?php

use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\FileRepository;
use App\Services\Files\PrivateFileStorageService;
use App\Services\Fleet\TuroAccessReimbursementService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\TuroAccess;

/**
 * @internal
 */
final class TuroAccessReimbursementServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroAccessReimbursementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();
        $config = new TuroAccess();
        $config->reimbursementCapAmount = 21.00;
        $this->service = new TuroAccessReimbursementService(
            new TuroAccessReimbursementRepository($this->connection),
            new PrivateFileStorageService(new FileRepository($this->connection)),
            $config,
        );
    }

    public function testTuroAccessOverrideIncidentCanBeCreatedWithTripVehicleAndMovement(): void
    {
        $result = $this->service->createIncident(1, ['incident_context' => 'exit', 'operator_type' => 'guest', 'ticket_number' => 'T123', 'parking_amount_paid' => '18.00']);

        $this->assertTrue($result['success']);
        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $result['incident_id'])->get()->getRowArray();
        $this->assertSame(10, (int) $incident['turo_trip_normalized_id']);
        $this->assertSame(9, (int) $incident['fleet_vehicle_id']);
        $this->assertSame('pickup', $incident['movement_type']);
        $this->assertSame('exit', $incident['incident_context']);
    }

    public function testDuplicateIncidentWarningWorks(): void
    {
        $this->service->createIncident(1, ['ticket_number' => 'T123']);
        $duplicate = $this->service->createIncident(1, ['ticket_number' => 'T123']);

        $this->assertFalse($duplicate['success']);
        $this->assertSame('possible_duplicate', $duplicate['code']);
        $confirmed = $this->service->createIncident(1, ['ticket_number' => 'T123'], true);
        $this->assertTrue($confirmed['success']);
    }

    public function testReceiptAttachmentProducesClaimReadyStateAndCapMath(): void
    {
        $incidentId = $this->service->createIncident(1, ['parking_amount_paid' => '28.00', 'incident_at' => '2026-07-19 14:30:00'])['incident_id'];

        $this->assertTrue($this->service->attachReceipt($incidentId, ['original_filename' => 'receipt.jpg', 'amount' => '28.00', 'attachment_type' => 'paid_receipt']));
        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();

        $this->assertSame('ready_to_file', $incident['claim_status']);
        $this->assertSame('21', (string) $incident['expected_reimbursement_amount']);
        $this->assertSame('7', (string) $incident['host_unreimbursed_amount']);
    }

    public function testMissingReceiptPreventsClaimReadyState(): void
    {
        $incidentId = $this->service->createIncident(1, ['parking_amount_paid' => '18.00'])['incident_id'];

        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('not_ready', $incident['claim_status']);
    }

    public function testClaimLifecycleKeepsFiledSeparateFromReimbursedAndStoresDenial(): void
    {
        $incidentId = $this->service->createIncident(1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->attachReceipt($incidentId, ['original_filename' => 'receipt.jpg', 'amount' => '18.00']);

        $this->assertTrue($this->service->markFiled($incidentId, 'CASE-1', '18.00'));
        $filed = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('filed', $filed['claim_status']);
        $this->assertNull($filed['reimbursed_amount']);

        $this->assertTrue($this->service->markReimbursed($incidentId, '18.00'));
        $this->assertSame('reimbursed', $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray()['claim_status']);

        $deniedId = $this->service->createIncident(1, ['parking_amount_paid' => '12.00'], true)['incident_id'];
        $this->assertTrue($this->service->deny($deniedId, 'Not eligible'));
        $this->assertSame('Not eligible', $this->connection->table('airport_turo_access_override_incidents')->where('id', $deniedId)->get()->getRowArray()['denial_reason']);
    }

    public function testUnmatchedReceiptAndCandidateTripsAreDeterministic(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $candidates = $this->service->candidateTripsForReceipt($receiptId);

        $this->assertSame(2, count($candidates));
        $this->assertSame(1, (int) $candidates[0]['id']);
        $this->assertSame('Strong match', $candidates[0]['match_label']);
        $this->assertContains('Exact vehicle match', $candidates[0]['reasons']);
        $this->assertContains('Same-day airport pickup', $candidates[0]['reasons']);
        $this->assertSame('Possible match', $candidates[1]['match_label']);
        $this->assertContains('Receipt vehicle differs from candidate vehicle', $candidates[1]['warnings']);
    }

    public function testMatchingWorkspaceDoesNotAutoLinkReceipt(): void
    {
        $receipt = $this->service->uploadUnmatchedReceipt($this->uploadedPng('receipt.png'), ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $workspace = $this->service->matchingWorkspace((int) $receipt['receipt_id']);

        $this->assertTrue($workspace['exists']);
        $this->assertNotEmpty($workspace['candidates']);
        $this->assertSame(1, $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', null)->countAllResults());
    }

    public function testReceiptImageUploadStoresFileMetadataAndRefreshesClaimReadiness(): void
    {
        $incidentId = $this->service->createIncident(1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $result = $this->service->uploadReceiptForIncident($incidentId, $this->uploadedPng('receipt.png'), ['amount' => '18.00', 'attachment_type' => 'paid_receipt']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->connection->table('files')->countAllResults());
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', $incidentId)->get()->getRowArray();
        $this->assertNotNull($receipt['file_id']);
        $this->assertSame('ready_to_file', $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray()['claim_status']);
    }

    public function testDuplicateUploadReusesExistingFileRecord(): void
    {
        $this->service->uploadUnmatchedReceipt($this->uploadedPng('receipt-a.png'), ['document_date' => '2026-07-19', 'amount' => '10.00']);
        $result = $this->service->uploadUnmatchedReceipt($this->uploadedPng('receipt-b.png'), ['document_date' => '2026-07-19', 'amount' => '10.00']);

        $this->assertTrue($result['duplicate_file']);
        $this->assertSame(1, $this->connection->table('files')->countAllResults());
        $this->assertSame(2, $this->connection->table('airport_turo_access_receipts')->countAllResults());
    }

    public function testUnsupportedUploadMimeTypeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->uploadUnmatchedReceipt($this->uploadedText('receipt.txt'), ['document_date' => '2026-07-19']);
    }

    public function testUnmatchedReceiptCanBeLinkedToSelectedAirportTrip(): void
    {
        $receipt = $this->service->uploadUnmatchedReceipt($this->uploadedPng('receipt.png'), ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $result = $this->service->linkReceiptToWorkflow((int) $receipt['receipt_id'], 1);

        $this->assertTrue($result['success']);
        $linked = $this->connection->table('airport_turo_access_receipts')->where('id', $receipt['receipt_id'])->get()->getRowArray();
        $this->assertNotNull($linked['airport_turo_access_override_incident_id']);
        $this->assertSame(0, $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', null)->countAllResults());
    }

    public function testOperationsExpenseCanExistWithoutTripAndReceiptCanBeAssignedToRun(): void
    {
        $run = $this->service->createOperationsRun(['run_date' => '2026-07-19', 'purpose' => 'Wash and restage airport car', 'chase_vehicle_type' => 'personal_vehicle', 'chase_vehicle_description' => 'Personal Tacoma']);
        $receiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '14.00', 'receipt_classification' => 'unresolved']);
        $assigned = $this->service->assignReceiptToOperationsExpense($receiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'car_wash', 'business_purpose_note' => 'Wash and return vehicle to HNL staging.']);

        $this->assertTrue($assigned['success']);
        $expense = $this->connection->table('airport_operations_expenses')->where('id', $assigned['expense_id'])->get()->getRowArray();
        $this->assertSame(0, $this->connection->table('airport_turo_access_override_incidents')->countAllResults());
        $this->assertSame('car_wash', $expense['expense_category']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertSame('airport_operations_expense', $receipt['receipt_classification']);
        $this->assertNull($receipt['airport_turo_access_override_incident_id']);
    }

    public function testNewRunCanBeCreatedDuringReceiptClassificationAndReferenceMultipleVehicles(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '18.00']);
        $assigned = $this->service->assignReceiptToOperationsExpense($receiptId, [
            'create_airport_operations_run' => '1',
            'run_date' => '2026-07-19',
            'purpose' => 'Deliver and recover airport vehicles',
            'chase_vehicle_type' => 'company_vehicle',
            'activity_type' => 'deliver_fleet_vehicle',
            'fleet_vehicle_id' => 9,
            'activities' => [
                ['activity_type' => 'deliver_fleet_vehicle', 'fleet_vehicle_id' => 9],
                ['activity_type' => 'recover_fleet_vehicle', 'fleet_vehicle_id' => 8],
            ],
            'expense_category' => 'parking',
            'business_purpose_note' => 'One airport run supported multiple vehicles.',
        ]);

        $this->assertTrue($assigned['success']);
        $this->assertSame(2, $this->connection->table('airport_operations_run_activities')->where('airport_operations_run_id', $assigned['run_id'])->countAllResults());
    }

    public function testExpenseAllocationReconcilesToTotalAndUnallocatedRemainsValid(): void
    {
        $run = $this->service->createOperationsRun(['run_date' => '2026-07-19', 'purpose' => 'Charge vehicles']);
        $receiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '30.00']);
        $assigned = $this->service->assignReceiptToOperationsExpense($receiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'ev_charging', 'business_purpose_note' => 'Charging before airport handoff.']);

        $this->assertTrue($this->service->allocateOperationsExpense((int) $assigned['expense_id'], [
            ['fleet_vehicle_id' => 9, 'allocation_method' => 'manual_amount', 'allocated_amount' => '15.00'],
            ['fleet_vehicle_id' => 8, 'allocation_method' => 'manual_amount', 'allocated_amount' => '15.00'],
        ]));
        $this->assertFalse($this->service->allocateOperationsExpense((int) $assigned['expense_id'], [
            ['fleet_vehicle_id' => 9, 'allocation_method' => 'manual_amount', 'allocated_amount' => '31.00'],
        ]));

        $unallocatedReceiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '9.00']);
        $unallocated = $this->service->assignReceiptToOperationsExpense($unallocatedReceiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'supplies', 'business_purpose_note' => 'Airport supplies.']);
        $this->assertTrue($unallocated['success']);
    }

    public function testSplitReceiptTotalsReconcileAndDoubleCountingIsPrevented(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '40.00']);

        $this->assertFalse($this->service->splitReceipt($receiptId, ['original_receipt_total' => '40.00', 'reimbursement_portion_amount' => '25.00', 'operations_expense_portion_amount' => '20.00', 'remaining_unclassified_amount' => '0.00'])['success'] ?? false);
        $split = $this->service->splitReceipt($receiptId, ['original_receipt_total' => '40.00', 'reimbursement_portion_amount' => '25.00', 'operations_expense_portion_amount' => '15.00', 'remaining_unclassified_amount' => '0.00']);
        $this->assertTrue($split['success']);

        $trip = $this->service->linkReceiptToWorkflow($receiptId, 1);
        $this->assertTrue($trip['success']);
        $blocked = $this->service->assignReceiptToOperationsExpense($receiptId, ['create_airport_operations_run' => '1', 'run_date' => '2026-07-19', 'expense_category' => 'parking', 'business_purpose_note' => 'Should be blocked.']);
        $this->assertFalse($blocked['success']);
    }

    public function testReceiptCanBeReclassifiedAndChangesAreAudited(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '8.00']);
        $result = $this->service->classifyReceipt($receiptId, 'non_business', 'Personal coffee stop.');

        $this->assertTrue($result['success']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertSame('non_business', $receipt['receipt_classification']);
        $this->assertGreaterThan(0, $this->connection->table('airport_turo_access_audits')->where('action', 'receipt_classified')->countAllResults());
    }

    public function testCommandCenterReceiptCountsIncludeOperationsWork(): void
    {
        $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '8.00']);
        $receiptId = $this->service->createUnmatchedReceipt(['document_date' => '2026-07-19', 'amount' => '12.00']);
        $this->service->assignReceiptToOperationsExpense($receiptId, ['expense_category' => 'parking', 'business_purpose_note' => 'Airport parking during recovery run.']);

        $summary = $this->service->attentionSummary();
        $this->assertSame(1, $summary['needs_classification']);
        $this->assertSame(1, $summary['expenses_missing_run']);
        $this->assertSame(1, $summary['runs_with_unallocated_expenses']);
        $this->assertTrue($summary['has_reimbursement_work']);
    }

    public function testReceiptMetadataCanBeEditedAndReadinessRecalculated(): void
    {
        $incidentId = $this->service->createIncident(1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->uploadReceiptForIncident($incidentId, $this->uploadedPng('receipt.png'), ['amount' => '18.00', 'attachment_type' => 'paid_receipt']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', $incidentId)->get()->getRowArray();

        $this->assertTrue($this->service->updateReceiptMetadata((int) $receipt['id'], ['amount' => '28.00', 'attachment_type' => 'paid_receipt']));
        $updated = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('21', (string) $updated['expected_reimbursement_amount']);
    }

    public function testAttentionSummaryCountsUnmatchedReadyAndFiled(): void
    {
        $this->service->createUnmatchedReceipt(['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00']);
        $readyId = $this->service->createIncident(1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->attachReceipt($readyId, ['original_filename' => 'receipt.jpg', 'amount' => '18.00']);
        $filedId = $this->service->createIncident(1, ['parking_amount_paid' => '12.00'], true)['incident_id'];
        $this->service->attachReceipt($filedId, ['original_filename' => 'receipt2.jpg', 'amount' => '12.00']);
        $this->service->markFiled($filedId, 'CASE-2', '12.00');

        $summary = $this->service->attentionSummary();
        $this->assertSame(1, $summary['unmatched_receipts']);
        $this->assertSame(1, $summary['ready_to_file']);
        $this->assertSame(1, $summary['filed_pending']);
        $this->assertTrue($summary['has_reimbursement_work']);
    }

    private function resetSchema(): void
    {
        foreach (['airport_receipt_splits', 'airport_operations_expense_allocations', 'airport_operations_expenses', 'airport_operations_run_activities', 'airport_operations_runs', 'airport_turo_access_audits', 'airport_turo_access_receipts', 'airport_turo_access_override_incidents', 'airport_movement_exceptions', 'airport_movement_audits', 'airport_movement_workflows', 'files', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('files') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, storage_disk VARCHAR(80), path VARCHAR(255), original_filename VARCHAR(190) NULL, mime_type VARCHAR(120) NULL, size_bytes INTEGER NULL, document_date DATE NULL, checksum VARCHAR(128) NULL, uploaded_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_delivery_id INTEGER NULL, turo_trip_normalized_id INTEGER, trip_movement_checklist_id INTEGER NULL, fleet_vehicle_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, workflow_status VARCHAR(40), garage VARCHAR(120) NULL, parking_level VARCHAR(40) NULL, parking_row VARCHAR(80) NULL, parking_stall VARCHAR(80) NULL, completed_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, exception_type VARCHAR(80), severity VARCHAR(40), note TEXT, resolved_at DATETIME NULL, resolution_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_override_incidents') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, movement_type VARCHAR(40) NULL, incident_stage VARCHAR(60), claim_status VARCHAR(60), incident_context VARCHAR(40), operator_type VARCHAR(40), incident_at DATETIME NULL, ticket_number VARCHAR(120) NULL, parking_entry_at DATETIME NULL, parking_exit_at DATETIME NULL, parking_amount_paid DECIMAL(10,2) NULL, payment_at DATETIME NULL, payment_method VARCHAR(80) NULL, expected_reimbursement_amount DECIMAL(10,2), host_unreimbursed_amount DECIMAL(10,2), claim_filed_on DATE NULL, claim_reference VARCHAR(120) NULL, claimed_amount DECIMAL(10,2) NULL, approved_amount DECIMAL(10,2) NULL, reimbursed_amount DECIMAL(10,2) NULL, reimbursed_on DATE NULL, denial_reason TEXT NULL, operator_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_receipts') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_turo_access_override_incident_id INTEGER NULL, airport_operations_expense_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, file_id INTEGER NULL, attachment_type VARCHAR(80), receipt_classification VARCHAR(60) DEFAULT "unresolved", original_filename VARCHAR(190) NULL, mime_type VARCHAR(120) NULL, document_date DATE NULL, amount DECIMAL(10,2) NULL, ticket_number VARCHAR(120) NULL, note TEXT NULL, classification_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_turo_access_override_incident_id INTEGER NULL, action VARCHAR(80), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_runs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, run_date DATE, start_time TIME NULL, end_time TIME NULL, chase_vehicle_type VARCHAR(40), chase_fleet_vehicle_id INTEGER NULL, chase_vehicle_description VARCHAR(190) NULL, operator_name VARCHAR(120) NULL, purpose VARCHAR(190), airport_id INTEGER NULL, starting_location VARCHAR(190) NULL, ending_location VARCHAR(190) NULL, starting_mileage DECIMAL(10,1) NULL, ending_mileage DECIMAL(10,1) NULL, business_miles DECIMAL(10,1) NULL, notes TEXT NULL, run_status VARCHAR(40), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_run_activities') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_run_id INTEGER, activity_type VARCHAR(60), fleet_vehicle_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, airport_movement_workflow_id INTEGER NULL, movement_type VARCHAR(40) NULL, started_at DATETIME NULL, completed_at DATETIME NULL, note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expenses') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_run_id INTEGER NULL, airport_turo_access_receipt_id INTEGER NULL, expense_category VARCHAR(60), amount DECIMAL(10,2), expense_date DATE, vendor VARCHAR(190) NULL, payment_method VARCHAR(80) NULL, file_id INTEGER NULL, business_purpose_note TEXT, is_reimbursable INTEGER DEFAULT 0, reimbursement_source VARCHAR(120) NULL, accounting_status VARCHAR(60), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expense_allocations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_expense_id INTEGER, fleet_vehicle_id INTEGER NULL, allocation_method VARCHAR(40), allocated_amount DECIMAL(10,2), allocated_percentage DECIMAL(5,2) NULL, note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_receipt_splits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_turo_access_receipt_id INTEGER, original_receipt_total DECIMAL(10,2), reimbursement_portion_amount DECIMAL(10,2), operations_expense_portion_amount DECIMAL(10,2), remaining_unclassified_amount DECIMAL(10,2), note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }

    private function seedData(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 9, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 8, 'fleet_code' => 'Spaceship-008', 'display_name' => 'Spaceship-008']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 10, 'fleet_vehicle_id' => 9, 'turo_trip_id' => 'trip-10', 'guest_name' => 'Guest Ten', 'starts_at' => '2026-07-19 14:00:00', 'ends_at' => '2026-07-19 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 11, 'fleet_vehicle_id' => 8, 'turo_trip_id' => 'trip-11', 'guest_name' => 'Guest Eleven', 'starts_at' => '2026-07-19 15:00:00', 'ends_at' => '2026-07-19 19:00:00']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 1, 'airport_delivery_id' => 1, 'turo_trip_normalized_id' => 10, 'trip_movement_checklist_id' => null, 'fleet_vehicle_id' => 9, 'airport_id' => 1, 'movement_type' => 'pickup', 'scheduled_at' => '2026-07-19 14:00:00', 'workflow_status' => 'picked_up', 'garage' => 'HNL International Parking Garage', 'parking_level' => '7', 'parking_row' => 'C', 'parking_stall' => '742']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 2, 'airport_delivery_id' => 2, 'turo_trip_normalized_id' => 11, 'trip_movement_checklist_id' => null, 'fleet_vehicle_id' => 8, 'airport_id' => 1, 'movement_type' => 'return', 'scheduled_at' => '2026-07-19 15:00:00', 'workflow_status' => 'completed', 'garage' => 'HNL International Parking Garage', 'parking_level' => '6', 'parking_row' => 'B', 'parking_stall' => '611']);
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }

    private function uploadedPng(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt_png_');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', filesize($path), UPLOAD_ERR_OK);
    }

    private function uploadedText(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt_txt_');
        file_put_contents($path, 'not a receipt image');

        return new UploadedFile($path, $name, 'text/plain', filesize($path), UPLOAD_ERR_OK);
    }
}
