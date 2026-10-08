<?php

namespace Tests\Support;

use App\Repositories\OperatingExpenseRepository;
use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageFinancialReconciliationService;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/** Synthetic expense/invoice pair on the real commands and migrations. */
final class VehicleDamageFinancialReconciliationFixture
{
    public static function pair(BaseConnection $db, array &$paths, ?int $expenseId = null): array
    {
        $work = new Work($db);
        $repo = new VehicleDamageRepairRepository($db);
        $result = $work->createJob(1, 10, VehicleDamageRepairDatabaseFixture::creation($db, [VehicleDamageRepairDatabaseFixture::condition($db)]), 7);
        self::success($result);
        $j = $result['id'];
        $command = fn (array $facts): array => $facts + ['expected_version' => $repo->job(1, 10, $j)['version'], 'command_key' => Work::commandKey()];
        $tmp = tempnam(sys_get_temp_dir(), 'b32a_synthetic_invoice_');
        $paths[] = $tmp;
        file_put_contents($tmp, "%PDF-1.4\n% Synthetic complete expense invoice " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
        $cost = new VehicleDamageRepairCostService($db);
        $review = $cost->duplicates(1, 10, $j, ['amount' => '6200.00', 'occurred_on' => '2026-10-06', 'vendor_reference' => null], null);
        $reviewFields = $review['candidates'] === [] ? [] : ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic independently reviewed complete invoice'];
        $invoice = $work->recordCostEntry(1, 10, $j, $command($reviewFields + ['kind_code' => 'invoice', 'amount' => '6200.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'document' => ['kind_code' => 'invoice', 'upload' => new UploadedFile($tmp, 'synthetic-invoice.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK)]]), 7);
        self::success($invoice);
        $cost = new VehicleDamageRepairCostService($db);
        $doc = $cost->sources->documents->documents->document(1, 10, $j, $invoice['document_id']);
        $paths[] = $cost->sources->documents->storage->resolve(1, $doc, $cost->sources->documents->documents->metadata($doc))['path'];
        self::success($work->start(1, 10, $j, $command(['started_at' => '2026-10-01T09:00']), 7));
        self::success($work->cancel(1, 10, $j, $command(['reason' => 'Synthetic retained work']), 7));
        self::success($work->finalizeRepairCost(1, 10, $j, $command(['confirmed' => '1', 'expected_invoiced_state' => $cost->invoicedFingerprint(1, 10, $j), 'note' => 'Synthetic complete invoice']), 7));
        if ($expenseId === null) {
            $expenses = new OperatingExpenseRepository($db);
            $expenseId = $expenses->createExpense(['company_id' => 1, 'fleet_vehicle_id' => 10, 'amount' => '6200.00', 'expense_date' => '2026-10-06', 'expense_category_lookup_value_id' => $expenses->category('other')['id'], 'vendor' => 'Synthetic Shop', 'business_purpose' => 'Synthetic full repair', 'source_code' => 'manual', 'status_code' => 'recorded', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-06 10:00:00', 'updated_at' => '2026-10-06 10:00:00']);
        }
        $helper = new VehicleDamageFinancialReconciliationService($db);
        $root = $invoice['cost_entry_id'];
        $payload = $command(['cost_root_entry_id' => $root, 'operating_expense_id' => $expenseId, 'expected_damage_state' => $helper->families(1, 10, $j)[$root]['fingerprint'], 'expected_expense_state' => $helper->expenses(1, [$expenseId])[$expenseId]['fingerprint'], 'amount' => '6200.00', 'currency' => 'USD', 'confirmed' => '1', 'whole_fact_confirmed' => '1', 'no_other_job_confirmed' => '1', 'no_other_expense_confirmed' => '1', 'not_partial_confirmed' => '1', 'reason' => 'Synthetic whole cost identity']);
        return ['job' => $j, 'root' => $root, 'expense' => $expenseId, 'payload' => $payload, 'invoice' => $invoice];
    }

    private static function success(array $result): void
    {
        if (! $result['success']) {
            throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }
}
