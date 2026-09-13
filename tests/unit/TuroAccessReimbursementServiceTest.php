<?php

use App\Repositories\FileRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Services\Files\PrivateFileStorageService;
use App\Services\Fleet\TuroAccessReimbursementService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\AirportReceipts;
use Config\Database;
use Config\TuroAccess;

/**
 * @internal
 */
final class TuroAccessReimbursementServiceTest extends CIUnitTestCase
{
    private const COMPANY_ID = 1;
    private const ACTOR_ID = 42;

    private BaseConnection $connection;
    private TuroAccessReimbursementService $service;
    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();
        $config = new TuroAccess();
        $config->newReimbursementClaimsEnabled = true;
        $config->reimbursementCapAmount = 21.00;
        $receiptConfig = new AirportReceipts();
        $this->storageDirectory = 'airport-receipts/tests/' . bin2hex(random_bytes(8));
        $receiptConfig->storageDirectory = $this->storageDirectory;
        $this->service = new TuroAccessReimbursementService(
            new TuroAccessReimbursementRepository($this->connection),
            new PrivateFileStorageService(new FileRepository($this->connection), $receiptConfig),
            $config,
        );
    }

    protected function tearDown(): void
    {
        $this->removeStorageDirectory();
        parent::tearDown();
    }

    public function testTuroAccessOverrideIncidentCanBeCreatedWithTripVehicleAndMovement(): void
    {
        $result = $this->service->createIncident(self::COMPANY_ID, 1, ['incident_context' => 'exit', 'operator_type' => 'guest', 'ticket_number' => 'T123', 'parking_amount_paid' => '18.00']);

        $this->assertTrue($result['success']);
        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $result['incident_id'])->get()->getRowArray();
        $this->assertSame(10, (int) $incident['turo_trip_normalized_id']);
        $this->assertSame(9, (int) $incident['fleet_vehicle_id']);
        $this->assertSame('pickup', $incident['movement_type']);
        $this->assertSame('exit', $incident['incident_context']);
    }

    public function testDuplicateIncidentWarningWorks(): void
    {
        $this->service->createIncident(self::COMPANY_ID, 1, ['ticket_number' => 'T123']);
        $duplicate = $this->service->createIncident(self::COMPANY_ID, 1, ['ticket_number' => 'T123']);

        $this->assertFalse($duplicate['success']);
        $this->assertSame('possible_duplicate', $duplicate['code']);
        $confirmed = $this->service->createIncident(self::COMPANY_ID, 1, ['ticket_number' => 'T123'], true);
        $this->assertTrue($confirmed['success']);
    }

    public function testReceiptAttachmentProducesClaimReadyStateAndCapMath(): void
    {
        $incidentId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '28.00', 'incident_at' => '2026-07-19 14:30:00'])['incident_id'];

        $this->assertTrue($this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['original_filename' => 'receipt.jpg', 'amount' => '28.00', 'attachment_type' => 'paid_receipt']));
        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();

        $this->assertSame('ready_to_file', $incident['claim_status']);
        $this->assertSame('21', (string) $incident['expected_reimbursement_amount']);
        $this->assertSame('7', (string) $incident['host_unreimbursed_amount']);
    }

    public function testMissingReceiptPreventsClaimReadyState(): void
    {
        $incidentId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];

        $incident = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('not_ready', $incident['claim_status']);
    }

    public function testClaimLifecycleKeepsFiledSeparateFromReimbursedAndStoresDenial(): void
    {
        $incidentId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['original_filename' => 'receipt.jpg', 'amount' => '18.00']);

        $this->assertTrue($this->service->markFiled(self::COMPANY_ID, $incidentId, 'CASE-1', '18.00', self::ACTOR_ID));
        $filed = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('filed', $filed['claim_status']);
        $this->assertNull($filed['reimbursed_amount']);

        $this->assertTrue($this->service->markReimbursed(self::COMPANY_ID, $incidentId, '18.00', self::ACTOR_ID));
        $this->assertSame('reimbursed', $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray()['claim_status']);

        $deniedId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '12.00'], true)['incident_id'];
        $this->service->attachReceipt(self::COMPANY_ID, $deniedId, ['original_filename' => 'denied.jpg', 'amount' => '12.00']);
        $this->assertTrue($this->service->markFiled(self::COMPANY_ID, $deniedId, 'CASE-DENIED', '12.00', self::ACTOR_ID));
        $this->assertTrue($this->service->deny(self::COMPANY_ID, $deniedId, 'Not eligible', self::ACTOR_ID));
        $this->assertSame('Not eligible', $this->connection->table('airport_turo_access_override_incidents')->where('id', $deniedId)->get()->getRowArray()['denial_reason']);
        $actors = array_values(array_unique(array_map('intval', array_column($this->connection->table('airport_turo_access_audits')->whereIn('action', ['claim_filed', 'reimbursed', 'denied'])->get()->getResultArray(), 'created_by'))));
        $this->assertSame([self::ACTOR_ID], $actors);
    }

    public function testCurrentHnlPolicyRejectsNewIncidentBeforeAnyWrite(): void
    {
        $service = new TuroAccessReimbursementService(new TuroAccessReimbursementRepository($this->connection), null, new TuroAccess());
        $incidentCount = $this->connection->table('airport_turo_access_override_incidents')->countAllResults();
        $exceptionCount = $this->connection->table('airport_movement_exceptions')->countAllResults();

        $result = $service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '14.00'], false, self::ACTOR_ID);

        $this->assertFalse($result['success']);
        $this->assertSame('legacy_workflow_inactive', $result['code']);
        $this->assertSame($incidentCount, $this->connection->table('airport_turo_access_override_incidents')->countAllResults());
        $this->assertSame($exceptionCount, $this->connection->table('airport_movement_exceptions')->countAllResults());
    }

    public function testEveryInvalidClaimTransitionAndTerminalMutationIsRejectedWithoutWrite(): void
    {
        $incidentId = (int) $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $auditCount = $this->connection->table('airport_turo_access_audits')->countAllResults();
        $this->assertFalse($this->service->markFiled(self::COMPANY_ID, $incidentId, 'TOO-SOON', '18.00', self::ACTOR_ID));
        $this->assertFalse($this->service->markReimbursed(self::COMPANY_ID, $incidentId, '18.00', self::ACTOR_ID));
        $this->assertFalse($this->service->deny(self::COMPANY_ID, $incidentId, 'Too soon', self::ACTOR_ID));
        $this->assertSame($auditCount, $this->connection->table('airport_turo_access_audits')->countAllResults());

        $this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['original_filename' => 'receipt.jpg', 'amount' => '18.00']);
        $this->assertFalse($this->service->markReimbursed(self::COMPANY_ID, $incidentId, '18.00', self::ACTOR_ID));
        $this->assertFalse($this->service->deny(self::COMPANY_ID, $incidentId, 'Still too soon', self::ACTOR_ID));
        $this->assertTrue($this->service->markFiled(self::COMPANY_ID, $incidentId, 'CASE-VALID', '18.00', self::ACTOR_ID));
        $this->assertFalse($this->service->markFiled(self::COMPANY_ID, $incidentId, 'AGAIN', '18.00', self::ACTOR_ID));
        $this->assertTrue($this->service->markReimbursed(self::COMPANY_ID, $incidentId, '18.00', self::ACTOR_ID));
        $terminalAuditCount = $this->connection->table('airport_turo_access_audits')->countAllResults();
        $this->assertFalse($this->service->markFiled(self::COMPANY_ID, $incidentId, 'TERMINAL', null, self::ACTOR_ID));
        $this->assertFalse($this->service->markReimbursed(self::COMPANY_ID, $incidentId, '18.00', self::ACTOR_ID));
        $this->assertFalse($this->service->deny(self::COMPANY_ID, $incidentId, 'Terminal', self::ACTOR_ID));
        $this->assertFalse($this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['original_filename' => 'late.jpg', 'amount' => '18.00']));
        $this->assertSame($terminalAuditCount, $this->connection->table('airport_turo_access_audits')->countAllResults());
    }

    public function testReceiptActionModelExcludesTerminalAndResolvedOperationsReceipts(): void
    {
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'unresolved']);
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'trip_reimbursement']);
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'airport_operations_expense']);
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'non_business']);
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'duplicate']);
        $resolvedId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'unresolved', 'amount' => '14.00']);
        $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $resolvedId, ['expense_category' => 'parking'], self::ACTOR_ID);

        $summary = $this->service->attentionSummary(self::COMPANY_ID);
        $action = $this->service->inbox(self::COMPANY_ID, 'action');
        $history = $this->service->inbox(self::COMPANY_ID, 'history');

        $this->assertSame(3, $summary['needs_setup']);
        $this->assertSame(3, $summary['total_actionable']);
        $this->assertCount(3, $action['receipt_actions']);
        $classifications = array_values(array_unique(array_column($history['receipt_actions'], 'receipt_classification')));
        sort($classifications);
        $this->assertSame(['duplicate', 'non_business'], $classifications);
        $this->assertNotContains($resolvedId, array_map('intval', array_column($action['receipt_actions'], 'id')));
    }

    public function testClassificationValidationAndActorAuditFailClosed(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'unresolved']);
        $auditCount = $this->connection->table('airport_turo_access_audits')->countAllResults();
        try {
            $this->service->classifyReceipt(self::COMPANY_ID, $receiptId, 'invented_status', null, self::ACTOR_ID);
            $this->fail('Invalid classifications must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($auditCount, $this->connection->table('airport_turo_access_audits')->countAllResults());

        $this->assertTrue($this->service->classifyReceipt(self::COMPANY_ID, $receiptId, 'non_business', 'Operator classified.', self::ACTOR_ID)['success']);
        $audit = $this->connection->table('airport_turo_access_audits')->where('action', 'receipt_classified')->get()->getRowArray();
        $this->assertSame(self::ACTOR_ID, (int) $audit['created_by']);
    }

    public function testCommandCenterAndAirportPageQueryCountsStayFixedAsRowsGrow(): void
    {
        foreach ([1, 2] as $workflowId) {
            $incidentId = (int) $this->service->createIncident(self::COMPANY_ID, $workflowId, ['parking_amount_paid' => '18.00'])['incident_id'];
            $this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['original_filename' => "receipt-{$workflowId}.jpg", 'amount' => '18.00']);
        }
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['receipt_classification' => 'unresolved']);

        Events::removeAllListeners('DBQuery');
        $queryCounter = new class () {
            private int $count = 0;

            public function increment(): void
            {
                $this->count++;
            }

            public function reset(): void
            {
                $this->count = 0;
            }

            public function current(): int
            {
                return $this->count;
            }
        };
        Events::on('DBQuery', static function () use ($queryCounter): void {
            $queryCounter->increment();
        });
        $this->service->attentionSummary(self::COMPANY_ID);
        $this->assertSame(1, $queryCounter->current());

        $queryCounter->reset();
        $this->service->inbox(self::COMPANY_ID, 'action');
        $this->assertSame(5, $queryCounter->current());
        Events::removeAllListeners('DBQuery');
    }

    public function testUnmatchedReceiptAndCandidateTripsAreDeterministic(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $candidates = $this->service->candidateTripsForReceipt(self::COMPANY_ID, $receiptId);

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
        $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00', 'ticket_number' => 'R1']);
        $receipt = $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('receipt.png'), ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $workspace = $this->service->matchingWorkspace(self::COMPANY_ID, (int) $receipt['receipt_id']);

        $this->assertTrue($workspace['exists']);
        $this->assertNotEmpty($workspace['candidates']);
        $this->assertSame(1, $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', null)->countAllResults());
    }

    public function testReceiptImageUploadStoresFileMetadataAndRefreshesClaimReadiness(): void
    {
        $incidentId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $result = $this->service->uploadReceiptForIncident(self::COMPANY_ID, $incidentId, $this->uploadedPng('receipt.png'), ['amount' => '18.00', 'attachment_type' => 'paid_receipt']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->connection->table('files')->countAllResults());
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', $incidentId)->get()->getRowArray();
        $this->assertNotNull($receipt['file_id']);
        $this->assertSame('ready_to_file', $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray()['claim_status']);
    }

    public function testDuplicateUploadReusesExistingFileRecord(): void
    {
        $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('receipt-a.png'), ['document_date' => '2026-07-19', 'amount' => '10.00']);
        $result = $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('receipt-b.png'), ['document_date' => '2026-07-19', 'amount' => '10.00']);

        $this->assertTrue($result['duplicate_file']);
        $this->assertSame(1, $this->connection->table('files')->countAllResults());
        $this->assertSame(2, $this->connection->table('airport_turo_access_receipts')->countAllResults());
    }

    public function testUnsupportedUploadMimeTypeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedText('receipt.txt'), ['document_date' => '2026-07-19']);
    }

    public function testInvalidVehicleUploadIsRejectedBeforeFileOrReceiptCreation(): void
    {
        $upload = $this->uploadedPng('invalid-vehicle.png');

        try {
            $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $upload, ['fleet_vehicle_id' => 19]);
            $this->fail('Expected cross-company vehicle validation to reject the upload.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $this->connection->table('files')->countAllResults());
            $this->assertSame(0, $this->connection->table('airport_turo_access_receipts')->countAllResults());
        } finally {
            $tempPath = $upload->getTempName();
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function testReceiptFileAccessRequiresAuthorizedReceiptParentEvenWhenContentIsDeduplicated(): void
    {
        $own = $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('own-receipt.png'), ['document_date' => '2026-07-19']);
        $other = $this->service->uploadUnmatchedReceipt(2, $this->uploadedPng('other-receipt.png'), ['document_date' => '2026-07-19']);

        $this->assertTrue($other['duplicate_file']);
        $ownReceipt = $this->connection->table('airport_turo_access_receipts')->where('id', $own['receipt_id'])->get()->getRowArray();
        $otherReceipt = $this->connection->table('airport_turo_access_receipts')->where('id', $other['receipt_id'])->get()->getRowArray();
        $this->assertSame($ownReceipt['file_id'], $otherReceipt['file_id']);
        $resolved = $this->service->receiptFile(self::COMPANY_ID, (int) $own['receipt_id']);
        $this->assertFileExists($resolved['path']);
        $this->assertSame('image/png', $resolved['metadata']['mime_type']);
        $this->assertSame('own-receipt.png', $resolved['metadata']['original_filename']);
        $this->assertPageNotFound(fn () => $this->service->receiptFile(self::COMPANY_ID, (int) $other['receipt_id']));
        $this->assertPageNotFound(fn () => $this->service->receiptFile(self::COMPANY_ID, 999999));
    }

    public function testMissingDeletedAndUnattachedFileMetadataFailClosed(): void
    {
        $this->connection->table('airport_turo_access_receipts')->insert([
            'id' => 700,
            'company_id' => self::COMPANY_ID,
            'file_id' => 700,
            'receipt_classification' => 'unresolved',
        ]);
        $this->assertPageNotFound(fn () => $this->service->receiptFile(self::COMPANY_ID, 700));

        $uploaded = $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('deleted.png'), ['document_date' => '2026-07-19']);
        $uploadedReceipt = $this->connection->table('airport_turo_access_receipts')->where('id', $uploaded['receipt_id'])->get()->getRowArray();
        $this->connection->table('files')->where('id', $uploadedReceipt['file_id'])->update(['deleted_at' => '2026-07-20 00:00:00']);
        $this->assertPageNotFound(fn () => $this->service->receiptFile(self::COMPANY_ID, (int) $uploaded['receipt_id']));

        $this->connection->table('files')->insert([
            'id' => 900,
            'storage_disk' => 'local',
            'path' => $this->storageDirectory . '/unattached.png',
            'original_filename' => 'unattached.png',
            'mime_type' => 'image/png',
        ]);
        $this->assertPageNotFound(fn () => $this->service->receiptFile(self::COMPANY_ID, 900));
    }

    public function testClientSuppliedFileIdIsIgnoredOutsideAuthorizedUploadFlow(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['file_id' => 321, 'document_date' => '2026-07-19']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertNull($receipt['file_id']);

        $incidentId = (int) $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->assertTrue($this->service->attachReceipt(self::COMPANY_ID, $incidentId, ['file_id' => 654, 'amount' => '18.00']));
        $attached = $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', $incidentId)->get()->getRowArray();
        $this->assertNull($attached['file_id']);
    }

    public function testUnmatchedReceiptCanBeLinkedToSelectedAirportTrip(): void
    {
        $receipt = $this->service->uploadUnmatchedReceipt(self::COMPANY_ID, $this->uploadedPng('receipt.png'), ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00', 'ticket_number' => 'R1']);
        $result = $this->service->linkReceiptToWorkflow(self::COMPANY_ID, (int) $receipt['receipt_id'], 1, self::ACTOR_ID);

        $this->assertTrue($result['success']);
        $linked = $this->connection->table('airport_turo_access_receipts')->where('id', $receipt['receipt_id'])->get()->getRowArray();
        $this->assertNotNull($linked['airport_turo_access_override_incident_id']);
        $this->assertSame(0, $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', null)->countAllResults());
    }

    public function testOperationsExpenseCanExistWithoutTripAndReceiptCanBeAssignedToRun(): void
    {
        $run = $this->service->createOperationsRun(self::COMPANY_ID, ['run_date' => '2026-07-19', 'purpose' => 'Wash and restage airport car', 'chase_vehicle_type' => 'personal_vehicle', 'chase_vehicle_description' => 'Personal Tacoma']);
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '14.00', 'receipt_classification' => 'unresolved']);
        $assigned = $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'car_wash', 'business_purpose_note' => 'Wash and return vehicle to HNL staging.'], self::ACTOR_ID);

        $this->assertTrue($assigned['success']);
        $expense = $this->connection->table('airport_operations_expenses')->where('id', $assigned['expense_id'])->get()->getRowArray();
        $this->assertSame(0, $this->connection->table('airport_turo_access_override_incidents')->countAllResults());
        $this->assertSame('car_wash', $expense['expense_category']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertSame('airport_operations_expense', $receipt['receipt_classification']);
        $this->assertNull($receipt['airport_turo_access_override_incident_id']);
        $audit = $this->connection->table('airport_turo_access_audits')->where('action', 'receipt_linked_to_operations_expense')->get()->getRowArray();
        $this->assertSame(self::ACTOR_ID, (int) $audit['created_by']);
    }

    public function testNewRunCanBeCreatedDuringReceiptClassificationAndReferenceMultipleVehicles(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '18.00']);
        $assigned = $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, [
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
        ], self::ACTOR_ID);

        $this->assertTrue($assigned['success']);
        $this->assertSame(2, $this->connection->table('airport_operations_run_activities')->where('airport_operations_run_id', $assigned['run_id'])->countAllResults());
    }

    public function testExpenseAllocationReconcilesToTotalAndUnallocatedRemainsValid(): void
    {
        $run = $this->service->createOperationsRun(self::COMPANY_ID, ['run_date' => '2026-07-19', 'purpose' => 'Charge vehicles']);
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '30.00']);
        $assigned = $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'ev_charging', 'business_purpose_note' => 'Charging before airport handoff.'], self::ACTOR_ID);

        $this->assertTrue($this->service->allocateOperationsExpense(self::COMPANY_ID, (int) $assigned['expense_id'], [
            ['fleet_vehicle_id' => 9, 'allocation_method' => 'manual_amount', 'allocated_amount' => '15.00'],
            ['fleet_vehicle_id' => 8, 'allocation_method' => 'manual_amount', 'allocated_amount' => '15.00'],
        ]));
        $this->assertFalse($this->service->allocateOperationsExpense(self::COMPANY_ID, (int) $assigned['expense_id'], [
            ['fleet_vehicle_id' => 9, 'allocation_method' => 'manual_amount', 'allocated_amount' => '31.00'],
        ]));

        $unallocatedReceiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '9.00']);
        $unallocated = $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $unallocatedReceiptId, ['airport_operations_run_id' => $run['run_id'], 'expense_category' => 'supplies', 'business_purpose_note' => 'Airport supplies.'], self::ACTOR_ID);
        $this->assertTrue($unallocated['success']);
    }

    public function testSplitReceiptTotalsReconcileAndDoubleCountingIsPrevented(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '40.00']);

        $this->assertFalse($this->service->splitReceipt(self::COMPANY_ID, $receiptId, ['original_receipt_total' => '40.00', 'reimbursement_portion_amount' => '25.00', 'operations_expense_portion_amount' => '20.00', 'remaining_unclassified_amount' => '0.00'])['success'] ?? false);
        $split = $this->service->splitReceipt(self::COMPANY_ID, $receiptId, ['original_receipt_total' => '40.00', 'reimbursement_portion_amount' => '25.00', 'operations_expense_portion_amount' => '15.00', 'remaining_unclassified_amount' => '0.00']);
        $this->assertTrue($split['success']);

        $trip = $this->service->linkReceiptToWorkflow(self::COMPANY_ID, $receiptId, 1, self::ACTOR_ID);
        $this->assertTrue($trip['success']);
        $blocked = $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['create_airport_operations_run' => '1', 'run_date' => '2026-07-19', 'expense_category' => 'parking', 'business_purpose_note' => 'Should be blocked.'], self::ACTOR_ID);
        $this->assertFalse($blocked['success']);
    }

    public function testReceiptCanBeReclassifiedAndChangesAreAudited(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '8.00']);
        $result = $this->service->classifyReceipt(self::COMPANY_ID, $receiptId, 'non_business', 'Personal coffee stop.', self::ACTOR_ID);

        $this->assertTrue($result['success']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertSame('non_business', $receipt['receipt_classification']);
        $this->assertGreaterThan(0, $this->connection->table('airport_turo_access_audits')->where('action', 'receipt_classified')->countAllResults());
    }

    public function testCommandCenterCountsOnlyDistinctActionableReceiptWork(): void
    {
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '8.00']);
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '12.00']);
        $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['expense_category' => 'parking', 'business_purpose_note' => 'Airport parking during recovery run.'], self::ACTOR_ID);

        $summary = $this->service->attentionSummary(self::COMPANY_ID);
        $this->assertSame(1, $summary['needs_setup']);
        $this->assertSame(1, $summary['total_actionable']);
        $this->assertTrue($summary['has_reimbursement_work']);
    }

    public function testReceiptMetadataDoesNotRecomputeStoredHistoricalClaimAmount(): void
    {
        $incidentId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->uploadReceiptForIncident(self::COMPANY_ID, $incidentId, $this->uploadedPng('receipt.png'), ['amount' => '18.00', 'attachment_type' => 'paid_receipt']);
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('airport_turo_access_override_incident_id', $incidentId)->get()->getRowArray();

        $this->assertTrue($this->service->updateReceiptMetadata(self::COMPANY_ID, (int) $receipt['id'], ['amount' => '28.00', 'attachment_type' => 'paid_receipt']));
        $updated = $this->connection->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        $this->assertSame('18', (string) $updated['expected_reimbursement_amount']);
    }

    public function testAttentionSummaryCountsUnmatchedReadyAndFiled(): void
    {
        $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00']);
        $readyId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '18.00'])['incident_id'];
        $this->service->attachReceipt(self::COMPANY_ID, $readyId, ['original_filename' => 'receipt.jpg', 'amount' => '18.00']);
        $filedId = $this->service->createIncident(self::COMPANY_ID, 1, ['parking_amount_paid' => '12.00'], true)['incident_id'];
        $this->service->attachReceipt(self::COMPANY_ID, $filedId, ['original_filename' => 'receipt2.jpg', 'amount' => '12.00']);
        $this->service->markFiled(self::COMPANY_ID, $filedId, 'CASE-2', '12.00', self::ACTOR_ID);

        $summary = $this->service->attentionSummary(self::COMPANY_ID);
        $this->assertSame(1, $summary['needs_setup']);
        $this->assertSame(1, $summary['ready_to_file']);
        $this->assertSame(1, $summary['filed_pending']);
        $this->assertTrue($summary['has_reimbursement_work']);
    }

    public function testReceiptInboxOffersOnlyActiveCompanyVehicles(): void
    {
        $vehicles = $this->service->inbox(self::COMPANY_ID)['fleet_vehicles'];

        $this->assertSame([8, 9], array_map(static fn (array $vehicle): int => (int) $vehicle['id'], $vehicles));
        $this->assertNotContains(19, array_map(static fn (array $vehicle): int => (int) $vehicle['id'], $vehicles));
    }

    public function testWrongCompanyResourcesFailClosedAcrossEveryAirportResourceCategory(): void
    {
        $otherIncidentId = (int) $this->service->createIncident(2, 20, ['parking_amount_paid' => '11.00'])['incident_id'];
        $otherReceiptId = $this->service->createUnmatchedReceipt(2, ['fleet_vehicle_id' => 19, 'document_date' => '2026-07-19', 'amount' => '11.00']);
        $otherRunId = (int) $this->service->createOperationsRun(2, ['run_date' => '2026-07-19', 'purpose' => 'Other company airport run', 'chase_fleet_vehicle_id' => 19])['run_id'];
        $assigned = $this->service->assignReceiptToOperationsExpense(2, $otherReceiptId, ['airport_operations_run_id' => $otherRunId, 'expense_category' => 'parking'], self::ACTOR_ID);
        $otherExpenseId = (int) $assigned['expense_id'];

        $this->assertPageNotFound(fn () => $this->service->matchingWorkspace(self::COMPANY_ID, $otherReceiptId));
        $this->assertPageNotFound(fn () => $this->service->classifyReceipt(self::COMPANY_ID, $otherReceiptId, 'non_business', null, self::ACTOR_ID));
        $this->assertPageNotFound(fn () => $this->service->updateReceiptMetadata(self::COMPANY_ID, $otherReceiptId, ['amount' => '12.00']));
        $this->assertPageNotFound(fn () => $this->service->markFiled(self::COMPANY_ID, $otherIncidentId, 'NOPE', null, self::ACTOR_ID));
        $this->assertPageNotFound(fn () => $this->service->allocateOperationsExpense(self::COMPANY_ID, $otherExpenseId, []));
        $this->assertPageNotFound(fn () => $this->service->splitReceipt(self::COMPANY_ID, $otherReceiptId, ['original_receipt_total' => '11.00']));

        $repo = new TuroAccessReimbursementRepository($this->connection);
        $this->assertNull($repo->workflow(self::COMPANY_ID, 20));
        $this->assertNull($repo->incident(self::COMPANY_ID, $otherIncidentId));
        $this->assertNull($repo->receipt(self::COMPANY_ID, $otherReceiptId));
        $this->assertNull($repo->operationsRun(self::COMPANY_ID, $otherRunId));
        $this->assertNull($repo->operationsExpense(self::COMPANY_ID, $otherExpenseId));
    }

    public function testCandidateSearchInboxAndSummaryExcludeOtherCompany(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00']);
        $this->service->createUnmatchedReceipt(2, ['fleet_vehicle_id' => 19, 'document_date' => '2026-07-19', 'amount' => '11.00']);
        $this->service->createIncident(2, 20, ['parking_amount_paid' => '11.00']);
        $otherRunId = (int) $this->service->createOperationsRun(2, ['run_date' => '2026-07-19', 'purpose' => 'Other company run', 'chase_fleet_vehicle_id' => 19])['run_id'];

        $this->assertSame([], $this->service->searchCandidates(self::COMPANY_ID, $receiptId, 'Other Guest'));
        $this->assertSame([], $this->service->inbox(self::COMPANY_ID)['incidents']);
        $this->assertSame(1, $this->service->attentionSummary(self::COMPANY_ID)['needs_setup']);
        $runIds = array_map('intval', array_column($this->service->candidateOperationsRunsForReceipt(self::COMPANY_ID, $receiptId), 'id'));
        $this->assertNotContains($otherRunId, $runIds);
    }

    public function testCrossCompanyReceiptWorkflowAndRunLinksLeaveNoPartialChanges(): void
    {
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['fleet_vehicle_id' => 9, 'document_date' => '2026-07-19', 'amount' => '18.00']);
        $otherRunId = (int) $this->service->createOperationsRun(2, ['run_date' => '2026-07-19', 'purpose' => 'Other company run', 'chase_fleet_vehicle_id' => 19])['run_id'];
        $incidentCount = $this->connection->table('airport_turo_access_override_incidents')->countAllResults();
        $expenseCount = $this->connection->table('airport_operations_expenses')->countAllResults();
        $auditCount = $this->connection->table('airport_turo_access_audits')->countAllResults();

        $this->assertPageNotFound(fn () => $this->service->linkReceiptToWorkflow(self::COMPANY_ID, $receiptId, 20, self::ACTOR_ID));
        $this->assertSame($incidentCount, $this->connection->table('airport_turo_access_override_incidents')->countAllResults());
        $this->assertSame($auditCount, $this->connection->table('airport_turo_access_audits')->countAllResults());

        $this->assertPageNotFound(fn () => $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['airport_operations_run_id' => $otherRunId, 'expense_category' => 'parking'], self::ACTOR_ID));
        $this->assertSame($expenseCount, $this->connection->table('airport_operations_expenses')->countAllResults());
        $receipt = $this->connection->table('airport_turo_access_receipts')->where('id', $receiptId)->get()->getRowArray();
        $this->assertSame('unresolved', $receipt['receipt_classification']);
        $this->assertNull($receipt['airport_operations_expense_id']);
    }

    public function testCrossCompanyActivityAndAllocationRejectBeforeWriting(): void
    {
        $runCount = $this->connection->table('airport_operations_runs')->countAllResults();
        $activityCount = $this->connection->table('airport_operations_run_activities')->countAllResults();
        $auditCount = $this->connection->table('airport_turo_access_audits')->countAllResults();
        try {
            $this->service->createOperationsRun(self::COMPANY_ID, [
                'run_date' => '2026-07-19',
                'purpose' => 'Invalid mixed-company run',
                'activities' => [['activity_type' => 'recover_fleet_vehicle', 'fleet_vehicle_id' => 19, 'turo_trip_normalized_id' => 20, 'airport_movement_workflow_id' => 20]],
            ]);
            $this->fail('Cross-company run activity must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($runCount, $this->connection->table('airport_operations_runs')->countAllResults());
        $this->assertSame($activityCount, $this->connection->table('airport_operations_run_activities')->countAllResults());
        $this->assertSame($auditCount, $this->connection->table('airport_turo_access_audits')->countAllResults());

        $runId = (int) $this->service->createOperationsRun(self::COMPANY_ID, ['run_date' => '2026-07-19', 'purpose' => 'Valid company run'])['run_id'];
        $receiptId = $this->service->createUnmatchedReceipt(self::COMPANY_ID, ['document_date' => '2026-07-19', 'amount' => '20.00']);
        $expenseId = (int) $this->service->assignReceiptToOperationsExpense(self::COMPANY_ID, $receiptId, ['airport_operations_run_id' => $runId, 'expense_category' => 'parking'], self::ACTOR_ID)['expense_id'];
        $this->assertTrue($this->service->allocateOperationsExpense(self::COMPANY_ID, $expenseId, [['fleet_vehicle_id' => 9, 'allocation_method' => 'manual_amount', 'allocated_amount' => '10.00']]));
        $this->assertFalse($this->service->allocateOperationsExpense(self::COMPANY_ID, $expenseId, [['fleet_vehicle_id' => 19, 'allocation_method' => 'manual_amount', 'allocated_amount' => '10.00']]));
        $allocations = $this->connection->table('airport_operations_expense_allocations')->where('airport_operations_expense_id', $expenseId)->get()->getResultArray();
        $this->assertCount(1, $allocations);
        $this->assertSame(9, (int) $allocations[0]['fleet_vehicle_id']);
    }

    private function resetSchema(): void
    {
        foreach (['airport_receipt_splits', 'airport_operations_expense_allocations', 'airport_operations_expenses', 'airport_operations_run_activities', 'airport_operations_runs', 'airport_turo_access_audits', 'airport_turo_access_receipts', 'airport_turo_access_override_incidents', 'airport_movement_exceptions', 'airport_movement_audits', 'airport_movement_workflows', 'files', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, fleet_number INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), sort_order INTEGER DEFAULT 0, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('files') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, storage_disk VARCHAR(80), path VARCHAR(255), original_filename VARCHAR(190) NULL, mime_type VARCHAR(120) NULL, size_bytes INTEGER NULL, document_date DATE NULL, checksum VARCHAR(128) NULL, uploaded_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_delivery_id INTEGER NULL, turo_trip_normalized_id INTEGER, trip_movement_checklist_id INTEGER NULL, fleet_vehicle_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, workflow_status VARCHAR(40), garage VARCHAR(120) NULL, parking_level VARCHAR(40) NULL, parking_row VARCHAR(80) NULL, parking_stall VARCHAR(80) NULL, completed_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, exception_type VARCHAR(80), severity VARCHAR(40), note TEXT, resolved_at DATETIME NULL, resolution_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_override_incidents') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_movement_workflow_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, movement_type VARCHAR(40) NULL, incident_stage VARCHAR(60), claim_status VARCHAR(60), incident_context VARCHAR(40), operator_type VARCHAR(40), incident_at DATETIME NULL, ticket_number VARCHAR(120) NULL, parking_entry_at DATETIME NULL, parking_exit_at DATETIME NULL, parking_amount_paid DECIMAL(10,2) NULL, payment_at DATETIME NULL, payment_method VARCHAR(80) NULL, expected_reimbursement_amount DECIMAL(10,2), host_unreimbursed_amount DECIMAL(10,2), claim_filed_on DATE NULL, claim_reference VARCHAR(120) NULL, claimed_amount DECIMAL(10,2) NULL, approved_amount DECIMAL(10,2) NULL, reimbursed_amount DECIMAL(10,2) NULL, reimbursed_on DATE NULL, denial_reason TEXT NULL, operator_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_receipts') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, airport_turo_access_override_incident_id INTEGER NULL, airport_operations_expense_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, file_id INTEGER NULL, attachment_type VARCHAR(80), receipt_classification VARCHAR(60) DEFAULT "unresolved", original_filename VARCHAR(190) NULL, mime_type VARCHAR(120) NULL, document_date DATE NULL, amount DECIMAL(10,2) NULL, ticket_number VARCHAR(120) NULL, note TEXT NULL, classification_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_turo_access_override_incident_id INTEGER NULL, action VARCHAR(80), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_runs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, run_date DATE, start_time TIME NULL, end_time TIME NULL, chase_vehicle_type VARCHAR(40), chase_fleet_vehicle_id INTEGER NULL, chase_vehicle_description VARCHAR(190) NULL, operator_name VARCHAR(120) NULL, purpose VARCHAR(190), airport_id INTEGER NULL, starting_location VARCHAR(190) NULL, ending_location VARCHAR(190) NULL, starting_mileage DECIMAL(10,1) NULL, ending_mileage DECIMAL(10,1) NULL, business_miles DECIMAL(10,1) NULL, notes TEXT NULL, run_status VARCHAR(40), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_run_activities') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_run_id INTEGER, activity_type VARCHAR(60), fleet_vehicle_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, airport_movement_workflow_id INTEGER NULL, movement_type VARCHAR(40) NULL, started_at DATETIME NULL, completed_at DATETIME NULL, note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expenses') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_run_id INTEGER NULL, airport_turo_access_receipt_id INTEGER NULL, expense_category VARCHAR(60), amount DECIMAL(10,2), expense_date DATE, vendor VARCHAR(190) NULL, payment_method VARCHAR(80) NULL, file_id INTEGER NULL, business_purpose_note TEXT, is_reimbursable INTEGER DEFAULT 0, reimbursement_source VARCHAR(120) NULL, accounting_status VARCHAR(60), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expense_allocations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_operations_expense_id INTEGER, fleet_vehicle_id INTEGER NULL, allocation_method VARCHAR(40), allocated_amount DECIMAL(10,2), allocated_percentage DECIMAL(5,2) NULL, note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_receipt_splits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, airport_turo_access_receipt_id INTEGER, original_receipt_total DECIMAL(10,2), reimbursement_portion_amount DECIMAL(10,2), operations_expense_portion_amount DECIMAL(10,2), remaining_unclassified_amount DECIMAL(10,2), note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }

    private function seedData(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 9, 'company_id' => self::COMPANY_ID, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 8, 'company_id' => self::COMPANY_ID, 'fleet_code' => 'Spaceship-008', 'display_name' => 'Spaceship-008']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 19, 'company_id' => 2, 'fleet_code' => 'Other-019', 'display_name' => 'Other-019']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 10, 'fleet_vehicle_id' => 9, 'turo_trip_id' => 'trip-10', 'guest_name' => 'Guest Ten', 'starts_at' => '2026-07-19 14:00:00', 'ends_at' => '2026-07-19 18:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 11, 'fleet_vehicle_id' => 8, 'turo_trip_id' => 'trip-11', 'guest_name' => 'Guest Eleven', 'starts_at' => '2026-07-19 15:00:00', 'ends_at' => '2026-07-19 19:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 20, 'fleet_vehicle_id' => 19, 'turo_trip_id' => 'trip-20', 'guest_name' => 'Other Guest', 'starts_at' => '2026-07-19 16:00:00', 'ends_at' => '2026-07-19 20:00:00']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 1, 'airport_delivery_id' => 1, 'turo_trip_normalized_id' => 10, 'trip_movement_checklist_id' => null, 'fleet_vehicle_id' => 9, 'airport_id' => 1, 'movement_type' => 'pickup', 'scheduled_at' => '2026-07-19 14:00:00', 'workflow_status' => 'picked_up', 'garage' => 'HNL International Parking Garage', 'parking_level' => '7', 'parking_row' => 'C', 'parking_stall' => '742']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 2, 'airport_delivery_id' => 2, 'turo_trip_normalized_id' => 11, 'trip_movement_checklist_id' => null, 'fleet_vehicle_id' => 8, 'airport_id' => 1, 'movement_type' => 'return', 'scheduled_at' => '2026-07-19 15:00:00', 'workflow_status' => 'completed', 'garage' => 'HNL International Parking Garage', 'parking_level' => '6', 'parking_row' => 'B', 'parking_stall' => '611']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 20, 'airport_delivery_id' => 20, 'turo_trip_normalized_id' => 20, 'trip_movement_checklist_id' => null, 'fleet_vehicle_id' => 19, 'airport_id' => 1, 'movement_type' => 'pickup', 'scheduled_at' => '2026-07-19 16:00:00', 'workflow_status' => 'picked_up', 'garage' => 'HNL International Parking Garage', 'parking_level' => '5', 'parking_row' => 'A', 'parking_stall' => '501']);
    }

    private function assertPageNotFound(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected generic not-found behavior.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
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

    private function removeStorageDirectory(): void
    {
        $path = rtrim((new \Config\Paths())->writableDirectory, '/\\') . DIRECTORY_SEPARATOR
            . 'uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $this->storageDirectory);
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
