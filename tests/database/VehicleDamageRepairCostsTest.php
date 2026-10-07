<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairService;
use CodeIgniter\HTTP\Files\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairTestCase;

final class VehicleDamageRepairCostsTest extends VehicleDamageRepairTestCase
{
    private array $temporary = [];
    private array $stored = [];

    private function upload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'b23_synthetic_');
        file_put_contents($path, "%PDF-1.4\n% Synthetic cost source " . bin2hex(random_bytes(8)) . "\n%%EOF\n");
        $this->temporary[] = $path;
        return new UploadedFile($path, 'synthetic-cost.pdf', 'application/pdf', filesize($path), UPLOAD_ERR_OK);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->temporary, ...$this->stored] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function record(int $job, string $kind = 'invoice', string $amount = '100.00', array $extra = []): array
    {
        $data = $this->command($job, $extra + ['kind_code' => $kind, 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Cost Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'document' => ['kind_code' => \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$kind], 'upload' => $this->upload()]]);
        $result = $this->work->recordCostEntry(1, 10, $job, $data, 7);
        if ($result['success']) {
            $source = new VehicleDamageRepairCostService($this->connection);
            $doc = $source->sources->documents->documents->document(1, 10, $job, $result['document_id']);
            $this->stored[] = $source->sources->documents->storage->resolve(1, $doc, $source->sources->documents->documents->metadata($doc))['path'];
        }
        return $result;
    }

    private function terminal(int $job): void
    {
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $this->success($this->work->cancel(1, 10, $job, $this->command($job, ['reason' => 'Synthetic performed work cancellation']), 7));
    }

    public static function invoiceChanges(): array
    {
        return array_map(fn (string $case): array => [$case], ['record_invoice', 'record_credit', 'void_invoice', 'void_credit', 'replace_invoice', 'replace_credit']);
    }

    #[DataProvider('invoiceChanges')]
    public function testEveryInvoiceOrCreditChangeInvalidatesInItsOnlyEvent(string $case): void
    {
        $job = $this->createWork([$this->condition()]);
        $invoice = $this->record($job);
        $this->success($invoice);
        $credit = null;
        if (str_contains($case, 'credit')) {
            $credit = $this->record($job, 'invoice_credit', '20.00', ['related_cost_entry_id' => $invoice['cost_entry_id']]);
            $this->success($credit);
        }
        $this->terminal($job);
        $this->success($this->finalize($job));
        $before = $this->counts();
        $oldJob = $this->repairs->job(1, 10, $job);
        $helper = new VehicleDamageRepairCostService($this->connection);
        if ($case === 'record_invoice') {
            $result = $this->record($job, 'invoice', '75.00');
        } elseif ($case === 'record_credit') {
            $result = $this->record($job, 'invoice_credit', '5.00', ['related_cost_entry_id' => $invoice['cost_entry_id']]);
        } else {
            $target = str_contains($case, 'credit') ? $credit : $invoice;
            $data = ['cost_entry_id' => $target['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $target['cost_entry_id']), 'confirmed' => '1', 'reason' => 'Synthetic finalization correction'];
            if (str_starts_with($case, 'replace')) {
                $old = array_column($helper->costs->entries(1, 10, $job), null, 'id')[$target['cost_entry_id']];
                $data += array_intersect_key($old, array_flip(['kind_code', 'currency', 'occurred_on', 'vendor_snapshot', 'vendor_company_id', 'vendor_reference', 'related_cost_entry_id', 'note', 'repair_document_id']));
                $data += ['amount' => str_contains($case, 'credit') ? '25.00' : '110.00', 'performed_work_confirmed' => '1', 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $job, $target['document_id'])];
                $result = $this->work->replaceCostEntry(1, 10, $job, $this->command($job, $data), 7);
            } else {
                $result = $this->work->voidCostEntry(1, 10, $job, $this->command($job, $data), 7);
            }
        }
        $this->success($result);
        $after = $this->repairs->job(1, 10, $job);
        $this->assertNull($after['cost_finalized_at']);
        $this->assertNull($after['cost_finalized_by']);
        $this->assertNull($after['cost_finalization_note']);
        $this->assertSame((int) $oldJob['version'] + 1, (int) $after['version']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $auditDelta = str_starts_with($case, 'void') ? 2 : 3;
        $this->assertSame($before['audit_logs'] + $auditDelta, $this->counts()['audit_logs']);
        $event = $this->connection->table('vehicle_damage_repair_job_events')->where('id', $result['event_id'])->get()->getRowArray();
        $snapshot = json_decode($event['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($oldJob['cost_finalized_at'], $snapshot['job']['cost_finalized_at']);
    }

    public function testExplicitFinalizationInvalidationRequiresCurrentFingerprintAndOneAudit(): void
    {
        $job = $this->createWork([$this->condition()]);
        $this->success($this->record($job));
        $this->terminal($job);
        $this->success($this->finalize($job));
        $helper = new VehicleDamageRepairCostService($this->connection);
        $base = ['confirmed' => '1', 'reason' => 'Synthetic completeness review', 'expected_finalization_state' => $helper->finalizationFingerprint(1, 10, $job)];
        $before = $this->counts();
        $this->failure($this->work->invalidateCostFinalization(1, 10, $job, $this->command($job, array_replace($base, ['expected_finalization_state' => str_repeat('0', 64)])), 7));
        $this->assertSame($before, $this->counts());
        $result = $this->work->invalidateCostFinalization(1, 10, $job, $this->command($job, $base), 7);
        $this->success($result);
        $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        $this->assertNull($this->repairs->job(1, 10, $job)['cost_finalized_at']);
        $this->assertSame(1, $this->connection->table(Costs::TABLE)->countAllResults());
        $this->assertSame('repair_cost_finalization_invalidated', $this->connection->table('vehicle_damage_repair_job_events')->where('id', $result['event_id'])->get()->getRowArray()['event_code']);
    }

    public function testVendorMismatchAndReductionChronologyCannotBeBypassed(): void
    {
        $job = $this->createWork([$this->condition()], ['vendor_snapshot' => 'Synthetic Job Vendor']);
        $before = $this->counts();
        $this->failure($this->record($job));
        $this->assertSame($before, $this->counts());
        $invoice = $this->record($job, 'invoice', '100.00', ['vendor_mismatch_confirmed' => '1', 'vendor_mismatch_reason' => 'Synthetic verified different source vendor']);
        $this->success($invoice);
        foreach ([['vendor_snapshot' => 'Synthetic Wrong Vendor'], ['occurred_on' => '2026-10-05'], ['related_cost_entry_id' => 999999], ['amount' => '100.01']] as $change) {
            $this->failure($this->record($job, 'invoice_credit', '20.00', $change + ['related_cost_entry_id' => $invoice['cost_entry_id'], 'vendor_mismatch_confirmed' => '1', 'vendor_mismatch_reason' => 'Synthetic source vendor review']));
        }
        $this->assertTrue(VehicleDamageRepairCostService::sameVendor(['vendor_company_id' => 30, 'vendor_snapshot' => 'A'], ['vendor_company_id' => 30, 'vendor_snapshot' => 'B']));
        $this->assertFalse(VehicleDamageRepairCostService::sameVendor(['vendor_company_id' => 30, 'vendor_snapshot' => 'Same'], ['vendor_company_id' => 31, 'vendor_snapshot' => 'Same']));
        $this->assertTrue(VehicleDamageRepairCostService::sameVendor(['vendor_company_id' => null, 'vendor_snapshot' => ' Synthetic VENDOR '], ['vendor_company_id' => null, 'vendor_snapshot' => 'synthetic vendor']));
    }

    private function finalize(int $job): array
    {
        $helper = new VehicleDamageRepairCostService($this->connection);
        return $this->work->finalizeRepairCost(1, 10, $job, $this->command($job, ['expected_invoiced_state' => $helper->invoicedFingerprint(1, 10, $job), 'confirmed' => '1', 'note' => 'Synthetic source completeness review']), 7);
    }

    public static function paymentCorrections(): array
    {
        return [['payment', false], ['payment', true], ['payment_refund', false], ['payment_refund', true]];
    }

    #[DataProvider('paymentCorrections')]
    public function testPaymentAndRefundCorrectionsPreserveFinalization(string $kind, bool $replace): void
    {
        $job = $this->createWork([$this->condition()]);
        $this->success($this->record($job));
        $payment = $this->record($job, 'payment', '50.00');
        $this->success($payment);
        $target = $kind === 'payment' ? $payment : $this->record($job, 'payment_refund', '5.00', ['related_cost_entry_id' => $payment['cost_entry_id']]);
        $this->success($target);
        $this->terminal($job);
        $this->success($this->finalize($job));
        $helper = new VehicleDamageRepairCostService($this->connection);
        $old = array_column($helper->costs->entries(1, 10, $job), null, 'id')[$target['cost_entry_id']];
        $data = ['cost_entry_id' => $target['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $target['cost_entry_id']), 'confirmed' => '1', 'reason' => 'Synthetic payment correction'];
        if ($replace) {
            $data += array_intersect_key($old, array_flip(['kind_code', 'currency', 'occurred_on', 'vendor_snapshot', 'vendor_company_id', 'vendor_reference', 'related_cost_entry_id', 'note', 'repair_document_id']));
            $data += ['amount' => $kind === 'payment' ? '55.00' : '6.00', 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $job, $target['document_id'])];
        }
        $before = $this->repairs->job(1, 10, $job);
        $result = $replace ? $this->work->replaceCostEntry(1, 10, $job, $this->command($job, $data), 7) : $this->work->voidCostEntry(1, 10, $job, $this->command($job, $data), 7);
        $this->success($result);
        $after = $this->repairs->job(1, 10, $job);
        foreach (['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame((int) $before['version'] + 1, (int) $after['version']);
    }

    public function testReplacementRejectsKindChangeAndNoOpWithoutMutatingHistory(): void
    {
        $job = $this->createWork([$this->condition()]);
        $invoice = $this->record($job);
        $this->success($invoice);
        $helper = new VehicleDamageRepairCostService($this->connection);
        $old = $helper->costs->entries(1, 10, $job)[0];
        $facts = array_intersect_key($old, array_flip(['kind_code', 'amount', 'currency', 'occurred_on', 'vendor_snapshot', 'vendor_company_id', 'vendor_reference', 'related_cost_entry_id', 'note', 'repair_document_id']));
        $data = $facts + ['cost_entry_id' => $invoice['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $invoice['cost_entry_id']), 'confirmed' => '1', 'performed_work_confirmed' => '1', 'reason' => 'Synthetic correction review', 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $job, $invoice['document_id'])];
        $before = $this->counts();
        $this->failure($this->work->replaceCostEntry(1, 10, $job, $this->command($job, $data), 7), 'No change');
        $this->failure($this->work->replaceCostEntry(1, 10, $job, $this->command($job, array_replace($data, ['kind_code' => 'payment'])), 7), 'same kind');
        $this->assertSame($before, $this->counts());
        $this->assertSame([$old], $helper->costs->entries(1, 10, $job));
    }

    public function testDuplicateCandidatesAcrossDomainsRequireFreshReviewAndStayReadOnly(): void
    {
        $job = $this->createWork([$this->condition()]);
        $helper = new VehicleDamageRepairCostService($this->connection);
        $category = (new \App\Repositories\LookupRepository($this->connection))->valueId('operating_expense_category', 'supplies_consumables');
        $this->connection->table('operating_expenses')->insert(['company_id' => 1, 'expense_category_lookup_value_id' => $category, 'expense_date' => '2026-10-06', 'amount' => '12.00', 'vendor' => 'Synthetic Cost Vendor', 'payment_reference' => 'Synthetic duplicate reference', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-06 12:00:00', 'updated_at' => '2026-10-06 12:00:00']);
        $this->connection->table('operating_expense_receipts')->insert(['company_id' => 1, 'file_id' => 800, 'document_date' => '2026-10-06', 'observed_amount' => '12.00', 'vendor' => 'Synthetic Cost Vendor', 'created_by' => 7, 'created_at' => '2026-10-06 12:00:00', 'updated_at' => '2026-10-06 12:00:00']);
        $this->connection->table('maintenance_logs')->insert(['fleet_vehicle_id' => 10, 'service_on' => '2026-10-06', 'total_amount' => '12.00']);
        $facts = ['amount' => '12.00', 'occurred_on' => '2026-10-06', 'vendor_reference' => 'Synthetic duplicate reference'];
        $boundary = $this->boundary();
        $review = $helper->duplicates(1, 10, $job, $facts, null);
        $this->assertEqualsCanonicalizing(['operating_expense', 'expense_receipt', 'maintenance'], array_column($review['candidates'], 'domain'));
        $this->assertSame($boundary, $this->boundary());
        $this->failure($this->record($job, 'payment', '12.00', ['vendor_reference' => $facts['vendor_reference']]));
        $this->failure($this->record($job, 'payment', '12.00', ['vendor_reference' => $facts['vendor_reference'], 'duplicate_review_fingerprint' => str_repeat('0', 64), 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic stale review']));
        $this->success($this->record($job, 'payment', '12.00', ['vendor_reference' => $facts['vendor_reference'], 'duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic separately verified fact']));
        $this->assertSame($boundary, $this->boundary());
        $referenceOnly = $helper->duplicates(1, 10, $job, ['amount' => '99.00', 'occurred_on' => '2026-10-01', 'vendor_reference' => $facts['vendor_reference']], null);
        $this->assertContains('operating_expense', array_column($referenceOnly['candidates'], 'domain'));
    }

    public function testFinalizationPreservesPaymentsInvalidatesInvoicesAndReopenInOneEvent(): void
    {
        $job = $this->createWork([$this->condition()]);
        $this->failure($this->finalize($job));
        $this->success($this->record($job, 'invoice', '0.00', ['verified_zero_confirmed' => '1']));
        $this->failure($this->finalize($job));
        $this->terminal($job);
        $before = $this->counts();
        $final = $this->finalize($job);
        $this->success($final);
        $this->assertSame('0.00', $final['finalization']['invoiced_total']);
        $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        $state = $this->repairs->job(1, 10, $job)['cost_finalized_at'];
        $payment = $this->record($job, 'payment', '50.00');
        $this->success($payment);
        $this->success($this->record($job, 'payment_refund', '5.00', ['related_cost_entry_id' => $payment['cost_entry_id']]));
        $this->assertSame($state, $this->repairs->job(1, 10, $job)['cost_finalized_at']);
        $before = $this->counts();
        $this->success($this->record($job, 'invoice', '10.00'));
        $this->assertNull($this->repairs->job(1, 10, $job)['cost_finalized_at']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->success($this->finalize($job));
        $before = $this->counts();
        $this->success($this->work->reopenJob(1, 10, $job, $this->command($job, ['same_work_order_confirmed' => '1', 'reason_category_code' => 'continuing_order', 'reason' => 'Synthetic continuing order']), 7));
        $this->assertNull($this->repairs->job(1, 10, $job)['cost_finalized_at']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        $events = $this->repairs->events(1, 10, $job);
        $this->assertSame('job_reopened', end($events)['event_code']);
    }

    public static function genuineParents(): array
    {
        return [['invoice', 'invoice_credit', 'invoiced'], ['payment', 'payment_refund', 'payments']];
    }

    #[DataProvider('genuineParents')]
    public function testParentReplacementRetainsGenuineReductionAndEnforcesNewBudget(string $kind, string $reduction, string $total): void
    {
        $job = $this->createWork([$this->condition()]);
        $invoice = $this->record($job, $kind);
        $this->success($invoice);
        $credit = $this->record($job, $reduction, '40.00', ['related_cost_entry_id' => $invoice['cost_entry_id']]);
        $this->success($credit);
        $helper = new VehicleDamageRepairCostService($this->connection);
        $base = ['cost_entry_id' => $invoice['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $invoice['cost_entry_id']), 'kind_code' => $kind, 'amount' => '50.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Cost Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'reason' => 'Synthetic corrected charge', 'repair_document_id' => $invoice['document_id'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $job, $invoice['document_id'])];
        $duplicate = $helper->duplicates(1, 10, $job, $base, $helper->sources->documents->documents->document(1, 10, $job, $invoice['document_id'])['content_checksum'], $invoice['cost_entry_id']);
        $base += ['duplicate_review_fingerprint' => $duplicate['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic correction review'];
        $before = $this->counts();
        $this->failure($this->work->replaceCostEntry(1, 10, $job, $this->command($job, array_replace($base, ['amount' => '39.99'])), 7), 'exceeds');
        $this->assertSame($before, $this->counts());
        foreach ([['vendor_snapshot' => 'Synthetic incompatible vendor'], ['occurred_on' => '2026-10-07']] as $change) {
            $this->failure($this->work->replaceCostEntry(1, 10, $job, $this->command($job, array_replace($base, $change)), 7));
            $this->assertSame($before, $this->counts());
        }
        $replacement = $this->work->replaceCostEntry(1, 10, $job, $this->command($job, $base), 7);
        $this->success($replacement);
        $rows = $helper->costs->entries(1, 10, $job);
        $this->assertSame('10.00', Costs::totals($rows)[$total]);
        $byId = array_column($rows, null, 'id');
        $this->assertSame($invoice['cost_entry_id'], (int) $byId[$credit['cost_entry_id']]['related_cost_entry_id']);
        $this->assertSame($replacement['cost_entry_id'], (int) Costs::head($rows, $invoice['cost_entry_id'])['id']);
        $this->assertSame($before['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->failure($this->work->voidCostEntry(1, 10, $job, $this->command($job, ['cost_entry_id' => $replacement['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $replacement['cost_entry_id']), 'confirmed' => '1', 'reason' => 'Synthetic parent void']), 7), 'dependent');
    }

    public function testCostsRecordFourKindsWithoutChangingPhysicalOrFinancialAuthorities(): void
    {
        $condition = $this->condition();
        $job = $this->createWork([$condition]);
        $before = $this->conditions->item(1, 10, $condition);
        $boundary = $this->boundary();
        $invoice = $this->record($job);
        $this->success($invoice);
        $credit = $this->record($job, 'invoice_credit', '20.00', ['related_cost_entry_id' => $invoice['cost_entry_id']]);
        $this->success($credit);
        $payment = $this->record($job, 'payment', '60.00');
        $this->success($payment);
        $this->success($this->record($job, 'payment_refund', '10.00', ['related_cost_entry_id' => $payment['cost_entry_id']]));
        $rows = (new Costs($this->connection))->entries(1, 10, $job);
        $this->assertSame('80.00', Costs::totals($rows)['invoiced']);
        $this->assertSame('50.00', Costs::totals($rows)['payments']);
        $this->assertSame($before, $this->conditions->item(1, 10, $condition));
        $this->assertSame(5, (int) $this->repairs->job(1, 10, $job)['version']);
        $this->assertCount(5, $this->repairs->events(1, 10, $job));
        $this->assertSame($boundary, $this->boundary(), 'Every unrelated authority and financial result stays unchanged.');
    }

    private function boundary(): array
    {
        $snapshot = [];
        foreach ($this->connection->listTables() as $table) {
            if (preg_match('/(?:vehicle_damage_repair_cost_entries|vehicle_damage_repair_documents|vehicle_damage_repair_job_events|audit_logs|files)$/', $table)) {
                continue;
            }
            $rows = $this->connection->table($table)->get()->getResultArray();
            if (str_ends_with($table, 'vehicle_damage_repair_jobs')) {
                foreach ($rows as &$row) {
                    foreach (['version', 'updated_by', 'updated_at', 'cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'] as $field) {
                        unset($row[$field]);
                    }
                }
                unset($row);
            }
            $snapshot[$table] = $rows;
        }
        $activity = new \App\Services\Fleet\FinancialActivityReadService(new \App\Repositories\TuroNormalizedTransactionRepository($this->connection), new \App\Repositories\TripMonthAllocationRepository($this->connection), new \App\Repositories\OperatingExpenseRepository($this->connection), new \App\Repositories\MaintenanceCostRepository($this->connection), new \App\Repositories\ChargingCostRepository($this->connection), new \App\Repositories\TuroAccessReimbursementRepository($this->connection));
        $financial = new \App\Services\Fleet\VehicleFinancialSummaryService(new \App\Services\Fleet\FinancialSummaryService($activity), new \App\Repositories\FleetVehicleRepository($this->connection));
        $snapshot['financial_result'] = $financial->period(1, '2026-10-01', '2026-11-01');
        return $snapshot;
    }

    public function testNestedMaterialCommandRefusesBeforeMovingUpload(): void
    {
        $job = $this->createWork([$this->condition()]);
        $upload = $this->upload();
        $data = $this->command($job, ['kind_code' => 'invoice', 'amount' => '1.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Nested Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'document' => ['kind_code' => 'invoice', 'upload' => $upload]]);
        $before = $this->counts();
        $this->connection->transBegin();
        try {
            $this->failure($this->work->recordCostEntry(1, 10, $job, $data, 7), 'outer transaction');
            $this->assertFileExists($upload->getTempName());
            $this->assertSame($before, $this->counts());
            $this->assertSame(1, $this->connection->transDepth);
        } finally {
            $this->connection->transRollback();
        }
    }

    public function testWrongSourcesAndContextsFailBeforeCreatingMonetaryHistory(): void
    {
        $job = $this->createWork([$this->condition()]);
        $invoice = $this->record($job);
        $this->success($invoice);
        $helper = new VehicleDamageRepairCostService($this->connection);
        $base = ['kind_code' => 'payment', 'amount' => '12.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Cost Vendor', 'confirmed' => '1', 'repair_document_id' => $invoice['document_id'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $job, $invoice['document_id'])];
        $before = $this->counts();
        foreach ([[1, 10, $job, $base], [2, 20, $job, $base], [1, 11, $job, $base], [1, 10, $job + 100, $base], [1, 10, $job, array_replace($base, ['repair_document_id' => 99999])], [1, 10, $job, array_replace($base, ['kind_code' => 'invoice', 'file_id' => 1])], [1, 10, $job, array_replace($base, ['document' => 'malformed'])], [1, 10, $job, array_replace($base, ['repair_document_id' => ['1']])]] as [$c, $v, $j, $data]) {
            $this->failure($this->work->recordCostEntry($c, $v, $j, $this->command($job, $data), 7));
        }
        $this->assertSame($before, $this->counts());
        $doc = $helper->sources->documents->documents->document(1, 10, $job, $invoice['document_id']);
        $file = $helper->sources->documents->documents->metadata($doc);
        foreach ([['checksum' => str_repeat('0', 64)], ['mime_type' => 'text/html'], ['size_bytes' => 1], ['path' => '../synthetic-cost.pdf']] as $change) {
            $this->connection->table('files')->where('id', $file['id'])->update($change);
            $data = array_replace($base, ['kind_code' => 'invoice', 'performed_work_confirmed' => '1']);
            $this->failure($this->work->recordCostEntry(1, 10, $job, $this->command($job, $data), 7), 'verified binary');
            $this->connection->table('files')->where('id', $file['id'])->update($file);
        }
        $this->assertSame($before, $this->counts());
    }

    public function testRefundBudgetAndInformationalInvoiceReferenceDoNotAllocatePayments(): void
    {
        $job = $this->createWork([$this->condition()]);
        $payment = $this->record($job, 'payment', '90.00');
        $this->success($payment);
        $this->assertNull(Costs::totals((new Costs($this->connection))->entries(1, 10, $job))['invoiced']);
        $this->failure($this->record($job, 'payment_refund', '90.01', ['related_cost_entry_id' => $payment['cost_entry_id']]), 'exceeds');
        $review = (new VehicleDamageRepairCostService($this->connection))->duplicates(1, 10, $job, ['amount' => '90.00', 'occurred_on' => '2026-10-06', 'vendor_reference' => null], null);
        $this->success($this->record($job, 'payment_refund', '90.00', ['related_cost_entry_id' => $payment['cost_entry_id'], 'duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic full refund against genuine payment']));
        $this->assertSame('0.00', Costs::totals((new Costs($this->connection))->entries(1, 10, $job))['payments']);
        $invoice = $this->record($job, 'invoice', '1.00');
        $this->success($invoice);
        $this->success($this->record($job, 'payment', '500.00', ['related_cost_entry_id' => $invoice['cost_entry_id'], 'occurred_on' => '2026-10-01']));
        $this->assertSame('500.00', Costs::totals((new Costs($this->connection))->entries(1, 10, $job))['payments']);
    }

    public function testAuditRollbackCleansOnlyNewBytesAndPreservesDeduplicatedCommittedSource(): void
    {
        $job = $this->createWork([$this->condition()]);
        $committed = $this->record($job);
        $this->success($committed);
        $helper = new VehicleDamageRepairCostService($this->connection);
        $doc = $helper->sources->documents->documents->document(1, 10, $job, $committed['document_id']);
        $source = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc));
        $bytes = file_get_contents($source['path']);
        $snapshot = function (): array {
            $rows = [];
            foreach (['files', 'vehicle_damage_repair_documents', Costs::TABLE, 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_events', 'audit_logs'] as $table) {
                $rows[$table] = $this->connection->table($table)->orderBy('id')->get()->getResultArray();
            }
            $rows['binary_paths'] = glob((new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1/*/*/*') ?: [];
            return $rows;
        };
        $before = $snapshot();
        $audit = $this->getMockBuilder(\App\Repositories\AuditLogRepository::class)->setConstructorArgs([$this->connection])->onlyMethods(['record'])->getMock();
        $audit->expects($this->exactly(2))->method('record')->willThrowException(new \RuntimeException('Synthetic cost audit failure'));
        $failing = new VehicleDamageRepairService($this->connection, audits: $audit);
        foreach ([false, true] as $dedupe) {
            $upload = $this->upload();
            if ($dedupe) {
                file_put_contents($upload->getTempName(), $bytes);
            }
            $descriptor = $helper->sources->documents->storage->descriptor($upload);
            $data = ['kind_code' => 'payment', 'amount' => '25.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Cost Vendor', 'confirmed' => '1', 'document' => ['kind_code' => 'payment_receipt', 'upload' => $upload, 'descriptor' => $descriptor]];
            $review = $helper->duplicates(1, 10, $job, $data, $descriptor['checksum']);
            $data += ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic dedupe source review'];
            $this->failure($failing->recordCostEntry(1, 10, $job, $this->command($job, $data), 7), 'audit failure');
            $this->assertSame($before, $snapshot());
            $this->assertSame($bytes, file_get_contents($source['path']));
        }
    }

    public function testCreditAndRefundCapsAndArchiveGuardIncludeVoids(): void
    {
        $job = $this->createWork([$this->condition()]);
        $invoice = $this->record($job);
        $this->success($invoice);
        $before = $this->counts();
        $this->failure($this->record($job, 'invoice_credit', '100.01', ['related_cost_entry_id' => $invoice['cost_entry_id']]), 'exceeds');
        $this->assertSame($before, $this->counts());
        $helper = new VehicleDamageRepairCostService($this->connection);
        $void = $this->work->voidCostEntry(1, 10, $job, $this->command($job, ['cost_entry_id' => $invoice['cost_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $invoice['cost_entry_id']), 'confirmed' => '1', 'reason' => 'Synthetic correction']), 7);
        $this->success($void);
        $source = $helper->sources->documents->documents;
        $this->failure($this->work->archiveDocument(1, 10, $job, $this->command($job, ['document_id' => $invoice['document_id'], 'expected_document_state' => $source->fingerprint(1, 10, $job, $invoice['document_id']), 'confirmed' => '1', 'reason' => 'Synthetic archive']), 7), 'permanently retained');
        $this->assertNull(Costs::totals((new Costs($this->connection))->entries(1, 10, $job))['invoiced']);
    }

    public function testSameKeyReplayPrecedesStaleValidationAndRejectsChangedActor(): void
    {
        $job = $this->createWork([$this->condition()]);
        $upload = $this->upload();
        $helper = new VehicleDamageRepairCostService($this->connection);
        $data = $this->command($job, ['kind_code' => 'payment', 'amount' => '1.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Cost Vendor', 'confirmed' => '1', 'document' => ['kind_code' => 'payment_receipt', 'upload' => $upload, 'descriptor' => $helper->sources->documents->storage->descriptor($upload)]]);
        $first = $this->work->recordCostEntry(1, 10, $job, $data, 7);
        $this->success($first);
        $doc = $helper->sources->documents->documents->document(1, 10, $job, $first['document_id']);
        $this->stored[] = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc))['path'];
        $counts = $this->counts();
        $second = $this->work->recordCostEntry(1, 10, $job, $data, 7);
        $this->success($second);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['cost_entry_id'], $second['cost_entry_id']);
        $this->assertSame($counts, $this->counts());
        $this->failure($this->work->recordCostEntry(1, 10, $job, $data, 8), 'different payload');
    }
}
