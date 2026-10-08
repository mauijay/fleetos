<?php

use App\Repositories\OperatingExpenseRepository;
use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\OperatingExpenseReconciliationGuard;
use App\Services\Fleet\VehicleDamageFinancialReconciliationService as Reconcile;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageFinancialReconciliationFixture as Pair;
use Tests\Support\VehicleDamageRepairMariaDbFixture as Maria;

/** @internal Zero-skip actual independent-connection InnoDB waits, current validation and MVCC reads. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageFinancialReconciliationMariaDbTest extends CIUnitTestCase
{
    public static function races(): array
    {
        return array_map(fn (string $case): array => [$case], ['two_jobs_one_expense', 'same_root', 'expense_edit_vs_create', 'expense_archive_vs_create', 'cost_replace_vs_create', 'credit_vs_create', 'invalidate_vs_edit', 'replace_vs_source', 'lost_ack_replay']);
    }

    #[DataProvider('races')]
    public function testIndependentConnectionActuallyWaitsThenRevalidates(string $case): void
    {
        $fixture = new Maria(false, '', 35);
        $db = $fixture->db;
        $paths = [];
        $faultConnection = null;
        try {
            $pair = Pair::pair($db, $paths);
            $repo = new VehicleDamageRepairRepository($db);
            $helper = new Reconcile($db);
            $method = $winnerMethod = 'reconcileRepairCostToExpense';
            $payload = $pair['payload'];
            $winnerPayload = $payload;
            $payload['command_key'] = Work::commandKey();
            $j = $pair['job'];
            $winnerJob = $j;
            $sourceWinner = false;
            $expectedSuccess = false;
            if ($case === 'two_jobs_one_expense') {
                $other = Pair::pair($db, $paths, $pair['expense']);
                $j = $other['job'];
                $payload = $other['payload'];
            }
            if (in_array($case, ['expense_edit_vs_create', 'expense_archive_vs_create'], true)) {
                $sourceWinner = true;
            }
            if (in_array($case, ['invalidate_vs_edit', 'replace_vs_source'], true)) {
                $created = (new Work($db))->reconcileRepairCostToExpense(1, 10, $j, $pair['payload'], 7);
                $this->assertTrue($created['success'], json_encode($created));
                $row = $helper->records->rows(1, 10, $j)[0];
                $winnerMethod = $case === 'invalidate_vs_edit' ? 'invalidateFinancialReconciliation' : 'replaceFinancialReconciliation';
                $winnerPayload = array_replace($pair['payload'], ['command_key' => Work::commandKey(), 'expected_version' => $created['version'], 'reconciliation_id' => $row['id'], 'expected_reconciliation_state' => Reconcile::state($row)]);
                $method = 'b32_expense_edit';
                $expectedSuccess = true;
            }
            if ($case === 'cost_replace_vs_create') {
                $cost = new VehicleDamageRepairCostService($db);
                $entry = $cost->costs->entries(1, 10, $j)[0];
                $winnerMethod = 'replaceCostEntry';
                $winnerPayload = ['expected_version' => $repo->job(1, 10, $j)['version'], 'command_key' => Work::commandKey(), 'cost_entry_id' => $pair['root'], 'expected_entry_state' => $cost->entryFingerprint(1, 10, $j, $pair['root']), 'repair_document_id' => $entry['repair_document_id'], 'expected_document_state' => $cost->sources->documents->documents->fingerprint(1, 10, $j, (int) $entry['repair_document_id']), 'kind_code' => 'invoice', 'amount' => '6000.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'reason' => 'Synthetic correction'];
            }
            if ($case === 'credit_vs_create') {
                $tmp = tempnam(sys_get_temp_dir(), 'b32a_synthetic_credit_');
                $paths[] = $tmp;
                file_put_contents($tmp, "%PDF-1.4\n% Synthetic credit " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
                $cost = new VehicleDamageRepairCostService($db);
                $attached = (new Work($db))->attachDocument(1, 10, $j, ['expected_version' => $repo->job(1, 10, $j)['version'], 'command_key' => Work::commandKey(), 'document' => ['kind_code' => 'invoice_credit', 'upload' => new UploadedFile($tmp, 'synthetic-credit.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK)]], 7);
                $this->assertTrue($attached['success'], json_encode($attached));
                $winnerMethod = 'recordCostEntry';
                $winnerPayload = ['expected_version' => $attached['version'], 'command_key' => Work::commandKey(), 'kind_code' => 'invoice_credit', 'amount' => '10.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'related_cost_entry_id' => $pair['root'], 'repair_document_id' => $attached['document_id'], 'expected_document_state' => $cost->sources->documents->documents->fingerprint(1, 10, $j, $attached['document_id']), 'confirmed' => '1'];
                $payload['expected_version'] = $attached['version'];
            }
            if ($case === 'lost_ack_replay') {
                $payload = $winnerPayload;
                $expectedSuccess = true;
                $faultConnection = $fixture->independent(true);
            }
            $arguments = $method === 'b32_expense_edit' ? [1, $pair['expense'], ['amount' => '6100.00', 'updated_by' => 7]] : [1, 10, $j, $payload, 7];
            $race = $fixture->contendUntilCommit($method, $arguments, function (Closure $barrier) use ($db, $case, $sourceWinner, $pair, $winnerJob, $winnerMethod, $winnerPayload, $faultConnection): array {
                if ($sourceWinner) {
                    $changes = $case === 'expense_archive_vs_create' ? ['status_code' => 'archived', 'archived_at' => '2026-10-07 10:00:00'] : ['amount' => '6100.00'];
                    return (new OperatingExpenseReconciliationGuard($db, $barrier))->run(['company_id' => 1, 'expense_id' => $pair['expense'], 'actor' => 7], function () use ($db, $changes, $pair): array {
                        $db->table('operating_expenses')->where('id', $pair['expense'])->update($changes);
                        return ['success' => true];
                    });
                }
                if ($case === 'lost_ack_replay') {
                    $uncertain = (new Work($faultConnection, commandClock: $barrier))->{$winnerMethod}(1, 10, $winnerJob, $winnerPayload, 7);
                    $this->assertTrue($uncertain['uncertain'] ?? false, json_encode([$uncertain, (string) $faultConnection->getLastQuery()]));
                    return (new Work($db))->{$winnerMethod}(1, 10, $winnerJob, $winnerPayload, 7);
                }
                return (new Work($db, commandClock: $barrier))->{$winnerMethod}(1, 10, $winnerJob, $winnerPayload, 7);
            });
            $this->assertTrue($race['blocked'], 'Actual one-second InnoDB lock wait was observed.');
            $this->assertSame($expectedSuccess, $race['result']['success'], json_encode($race));
            if ($case === 'lost_ack_replay') {
                $this->assertTrue($race['result']['replayed']);
                $this->assertSame(1, $db->table(Records::TABLE)->countAllResults());
            }
            if ($case === 'replace_vs_source') {
                $this->assertSame(0, $db->table(Records::TABLE)->where('status_code', 'active')->countAllResults());
                $this->assertSame(2, $db->table(Records::TABLE)->countAllResults());
            }
            if ($case === 'two_jobs_one_expense') {
                $this->assertSame(1, $db->table(Records::TABLE)->countAllResults());
            }
        } finally {
            $faultConnection?->close();
            $fixture->close();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testReportAndReconciliationReadsSeeConsistentCommittedSourceTransition(): void
    {
        $fixture = new Maria(false, '', 35);
        $paths = [];
        $independent = null;
        try {
            $db = $fixture->db;
            $pair = Pair::pair($db, $paths);
            $this->assertTrue((new Work($db))->reconcileRepairCostToExpense(1, 10, $pair['job'], $pair['payload'], 7)['success']);
            $independent = $fixture->independent();
            (new OperatingExpenseReconciliationGuard($db))->run(['company_id' => 1, 'expense_id' => $pair['expense'], 'actor' => 7], function () use ($db, $independent, $pair): void {
                $db->table('operating_expenses')->where('id', $pair['expense'])->update(['amount' => '6100.00']);
                $activity = (new OperatingExpenseRepository($independent))->recordedFinancialActivity(1, '2026-10-01', '2026-11-01');
                $this->assertSame('6200.00', $activity[0]['amount']);
                $this->assertSame('active', (new Reconcile($independent))->workspace(1, 10, $pair['job'])['history'][0]['read_status']);
            });
            $this->assertSame('6100.00', (new OperatingExpenseRepository($independent))->recordedFinancialActivity(1, '2026-10-01', '2026-11-01')[0]['amount']);
            $this->assertSame('invalidated', (new Reconcile($independent))->workspace(1, 10, $pair['job'])['history'][0]['read_status']);
        } finally {
            $independent?->close();
            $fixture->close();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
