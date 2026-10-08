<?php

namespace Tests\Database;

use App\Repositories\OperatingExpenseRepository;
use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Services\Fleet\FinancialActivityReadService;
use App\Services\Fleet\FinancialSummaryService;
use App\Services\Fleet\VehicleDamageFinancialReconciliationService as Reconcile;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleFinancialSummaryService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

/** @internal Synthetic complete cost provenance, immutable history and financial boundaries. */
class VehicleDamageFinancialReconciliationTest extends VehicleDamageRepairRecoveryTestCase
{
    protected Reconcile $reconcile;
    protected int $job;
    protected int $expense;
    protected int $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconcile = new Reconcile($this->connection);
        $this->job = $this->createWork([$this->condition()]);
        $this->finalizedCost($this->job, '6200.00');
        $this->root = (int) $this->connection->table(Costs::TABLE)->get()->getRowArray()['id'];
        $category = (new OperatingExpenseRepository($this->connection))->category('other')['id'];
        $this->expense = (new OperatingExpenseRepository($this->connection))->createExpense(['company_id' => 1, 'fleet_vehicle_id' => 10, 'expense_category_lookup_value_id' => $category, 'expense_date' => '2026-10-06', 'amount' => '6200.00', 'vendor' => 'Synthetic Shop', 'business_purpose' => 'Synthetic complete repair', 'source_code' => 'manual', 'status_code' => 'recorded', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-06 10:00:00', 'updated_at' => '2026-10-06 10:00:00']);
    }

    protected function payload(array $extra = []): array
    {
        $family = $this->reconcile->families(1, 10, $this->job)[$this->root];
        $expense = $this->reconcile->expenses(1, [$this->expense])[$this->expense];
        return $this->command($this->job, $extra + ['cost_root_entry_id' => $this->root, 'operating_expense_id' => $this->expense, 'expected_damage_state' => $family['fingerprint'], 'expected_expense_state' => $expense['fingerprint'], 'amount' => $family['snapshot']['amount'] ?? '6200.00', 'currency' => 'USD', 'confirmed' => '1', 'whole_fact_confirmed' => '1', 'no_other_job_confirmed' => '1', 'no_other_expense_confirmed' => '1', 'not_partial_confirmed' => '1', 'reason' => 'Synthetic complete fact identity review']);
    }

    protected function create(array $extra = []): array
    {
        $result = $this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload($extra), 7);
        $this->success($result);
        return $result;
    }

    public function testExactPairReplayAndBoundaryLeaveCompanyAndVehicleReportsByteEquivalent(): void
    {
        $read = new FinancialActivityReadService(new \App\Repositories\TuroNormalizedTransactionRepository($this->connection), new \App\Repositories\TripMonthAllocationRepository($this->connection), new OperatingExpenseRepository($this->connection), new \App\Repositories\MaintenanceCostRepository($this->connection), new \App\Repositories\ChargingCostRepository($this->connection), new \App\Repositories\TuroAccessReimbursementRepository($this->connection));
        $summary = new FinancialSummaryService($read);
        $vehicles = new VehicleFinancialSummaryService($summary, new \App\Repositories\FleetVehicleRepository($this->connection));
        $reports = fn (): string => json_encode([$summary->period(1, '2026-10-01', '2026-11-01'), $vehicles->period(1, '2026-10-01', '2026-11-01')], JSON_THROW_ON_ERROR);
        $beforeReports = $reports();
        // Canonical activity equality also preserves legacy source tables, which remain untouched.
        $before = $read->forPeriod(1, '2026-10-01', '2026-11-01');
        $tables = ['vehicle_damage_repair_cost_entries', 'vehicle_damage_repair_recovery_entries', 'damage_claims', 'trip_movement_events', 'maintenance_logs', 'operating_expenses', 'turo_transactions_normalized'];
        $boundary = [];
        foreach ($tables as $table) {
            $boundary[$table] = $this->connection->table($table)->get()->getResultArray();
        }
        $payload = $this->payload();
        $counts = $this->counts();
        $first = $this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7);
        $this->success($first);
        $this->assertSame($counts['audit_logs'] + 2, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame((int) $payload['expected_version'] + 1, $first['version']);
        $this->assertSame('active', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $afterCounts = $this->counts();
        $replay = $this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7);
        $this->success($replay);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($afterCounts, $this->counts());
        $this->assertSame($beforeReports, $reports());
        $this->assertSame($before, $read->forPeriod(1, '2026-10-01', '2026-11-01'));
        foreach ($tables as $table) {
            $this->assertSame($boundary[$table], $this->connection->table($table)->get()->getResultArray(), $table);
        }
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, array_replace($payload, ['reason' => 'Different identity']), 7), 'different payload');
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 8));
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload(), 7), 'already actively');
    }

    public static function ineligibleSources(): array
    {
        return array_map(fn (array $change): array => [$change], [['amount' => '6000.00'], ['fleet_vehicle_id' => 11], ['company_id' => 2], ['fleet_vehicle_id' => null], ['turo_trip_normalized_id' => 100], ['archived_at' => '2026-10-06 10:00:00'], ['status_code' => 'archived'], ['amount' => '0.00']]);
    }

    #[DataProvider('ineligibleSources')]
    public function testExpenseEligibilityRejectsWithoutMutation(array $change): void
    {
        $payload = $this->payload();
        $this->connection->table('operating_expenses')->where('id', $this->expense)->update($change);
        $before = $this->counts();
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7));
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, $this->connection->table(Records::TABLE)->countAllResults());
    }

    public function testDateAndDescriptorDifferencesRequireExplicitReviewAndRetainReportingDate(): void
    {
        $this->connection->table('operating_expenses')->where('id', $this->expense)->update(['expense_date' => '2026-09-30', 'vendor' => 'Synthetic Alternate Descriptor', 'payment_reference' => 'SYNTHETIC-ALT']);
        $expenseRepository = new OperatingExpenseRepository($this->connection);
        $other = $this->connection->table('operating_expenses')->where('id', $this->expense)->get()->getRowArray();
        unset($other['id']);
        $expenseRepository->createExpense(array_replace($other, ['fleet_vehicle_id' => 11, 'amount' => '123.45']));
        $expenseRepository->createExpense(array_replace($other, ['company_id' => 2, 'fleet_vehicle_id' => 20, 'amount' => '88.00']));
        $read = new FinancialActivityReadService(new \App\Repositories\TuroNormalizedTransactionRepository($this->connection), new \App\Repositories\TripMonthAllocationRepository($this->connection), $expenseRepository, new \App\Repositories\MaintenanceCostRepository($this->connection), new \App\Repositories\ChargingCostRepository($this->connection), new \App\Repositories\TuroAccessReimbursementRepository($this->connection));
        $summary = new FinancialSummaryService($read);
        $vehicles = new VehicleFinancialSummaryService($summary, new \App\Repositories\FleetVehicleRepository($this->connection));
        $reports = static function () use ($summary, $vehicles): array {
            $result = [];
            foreach ([1, 2] as $company) {
                foreach ([['2026-09-01', '2026-10-01'], ['2026-10-01', '2026-11-01'], ['2026-09-01', '2026-11-01']] as [$from, $to]) {
                    $result[$company][$from . ':' . $to] = [$summary->period($company, $from, $to), $vehicles->period($company, $from, $to)];
                }
            }
            return $result;
        };
        $beforeReports = $reports();
        $september = $beforeReports[1]['2026-09-01:2026-10-01'];
        $this->assertSame(6323.45, $september[0]['recorded_operating_costs']);
        $byVehicle = array_column($september[1]['vehicles'], null, 'fleet_vehicle_id');
        $this->assertSame(6200.0, $byVehicle[10]['recorded_operating_costs']);
        $this->assertSame(123.45, $byVehicle[11]['recorded_operating_costs']);
        $this->assertSame(0.0, $beforeReports[1]['2026-10-01:2026-11-01'][0]['recorded_operating_costs']);
        $this->assertSame(88.0, $beforeReports[2]['2026-09-01:2026-10-01'][0]['recorded_operating_costs']);
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload(), 7));
        $this->create(['date_difference_reason' => 'Synthetic report period differs from invoice issue date.', 'descriptor_review_confirmed' => '1', 'descriptor_review_reason' => 'Synthetic source evidence establishes full invoice identity.']);
        $row = $this->reconcile->workspace(1, 10, $this->job)['history'][0];
        $this->assertSame('2026-09-30', $row['financial_occurred_on']);
        $this->assertSame('2026-10-06', $row['damage_occurred_on']);
        $this->assertSame(json_encode($beforeReports, JSON_THROW_ON_ERROR), json_encode($reports(), JSON_THROW_ON_ERROR), 'Both companies and all vehicles retain byte-identical September, October and combined reports.');
    }

    public function testReceiptReassignmentRejectsBeforeLockingAnUnrelatedAncestor(): void
    {
        $settings = new \Config\ExpenseReceipts();
        $stored = (new \App\Services\Files\PrivateEvidenceStorageService(new \App\Repositories\FileRepository($this->connection)))->store($this->upload(), $settings->storageDirectory, $settings->allowedMimeTypes, $settings->maxFileSizeBytes, '2026-10-06', 7);
        $this->paths[] = $stored['absolute_path'];
        $repo = new OperatingExpenseRepository($this->connection);
        $receipt = $repo->createReceipt(['company_id' => 1, 'operating_expense_id' => $this->expense, 'file_id' => $stored['file_id'], 'classification_code' => 'operating_expense', 'created_by' => 7, 'created_at' => '2026-10-06 10:00:00', 'updated_at' => '2026-10-06 10:00:00']);
        $this->create();
        $other = $this->connection->table('operating_expenses')->where('id', $this->expense)->get()->getRowArray();
        unset($other['id']);
        $otherId = $repo->createExpense(array_replace($other, ['fleet_vehicle_id' => 11]));
        $counts = $this->counts();
        $locked = false;
        $guard = new \App\Services\Fleet\OperatingExpenseReconciliationGuard($this->connection, static function () use (&$locked): void {
            $locked = true;
        });
        try {
            $guard->run(['company_id' => 1, 'expense_id' => $otherId, 'receipt_id' => $receipt, 'actor' => 7], static fn (): bool => true);
            $this->fail('An attached receipt must not enter a different expense ancestor plan.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ownership is retained', $exception->getMessage());
        }
        $this->assertFalse($locked);
        try {
            $repo->updateReceipt(1, $receipt, ['operating_expense_id' => $otherId, 'classified_by' => 7]);
            $this->fail('Direct repository receipt reassignment must fail before its source transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ownership is retained', $exception->getMessage());
        }
        $this->assertSame($counts, $this->counts());
        $this->assertSame($this->expense, (int) $repo->receipt(1, $receipt)['operating_expense_id']);
        $this->assertSame('active', $this->reconcile->records->rows(1, 10, $this->job)[0]['status_code']);
    }

    public function testInvalidateReplaceRetainsHistoryAndExactDeltas(): void
    {
        $first = $this->create();
        $row = $this->reconcile->records->rows(1, 10, $this->job)[0];
        $counts = $this->counts();
        $replace = $this->work->replaceFinancialReconciliation(1, 10, $this->job, $this->payload(['reconciliation_id' => $row['id'], 'expected_reconciliation_state' => Reconcile::state($row)]), 7);
        $this->success($replace);
        $this->assertSame($counts['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->assertSame($first['version'] + 1, $replace['version']);
        $rows = $this->reconcile->records->rows(1, 10, $this->job);
        $this->assertSame('invalidated', $rows[0]['status_code']);
        $this->assertNull($rows[0]['current_operating_expense_id']);
        $this->assertSame((int) $row['id'], (int) $rows[1]['replacement_of_reconciliation_id']);
        $counts = $this->counts();
        $invalidate = $this->work->invalidateFinancialReconciliation(1, 10, $this->job, $this->command($this->job, ['reconciliation_id' => $rows[1]['id'], 'expected_reconciliation_state' => Reconcile::state($rows[1]), 'reason' => 'Synthetic invalidation', 'confirmed' => '1']), 7);
        $this->success($invalidate);
        $this->assertSame($counts['audit_logs'] + 2, $this->counts()['audit_logs']);
        $this->assertSame($replace['version'] + 1, $invalidate['version']);
    }

    public function testZeroNetFamilyCannotBecomeReconciliationAuthority(): void
    {
        $this->costFact('invoice_credit', '6200.00', $this->root);
        $cost = new VehicleDamageRepairCostService($this->connection);
        $this->success($this->work->finalizeRepairCost(1, 10, $this->job, $this->command($this->job, ['confirmed' => '1', 'expected_invoiced_state' => $cost->invoicedFingerprint(1, 10, $this->job), 'note' => 'Synthetic fully credited invoice']), 7));
        $before = $this->counts();
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload(), 7));
        $this->assertSame($before, $this->counts());
    }

    public function testReplacementCanSelectDifferentExpenseWithoutEditingHistoricalIdentity(): void
    {
        $this->create();
        $old = $this->reconcile->records->rows(1, 10, $this->job)[0];
        $source = $this->connection->table('operating_expenses')->where('id', $this->expense)->get()->getRowArray();
        unset($source['id']);
        $this->expense = (new OperatingExpenseRepository($this->connection))->createExpense($source);
        $result = $this->work->replaceFinancialReconciliation(1, 10, $this->job, $this->payload(['reconciliation_id' => $old['id'], 'expected_reconciliation_state' => Reconcile::state($old)]), 7);
        $this->success($result);
        $rows = $this->reconcile->records->rows(1, 10, $this->job);
        $this->assertSame($old['operating_expense_id'], $rows[0]['operating_expense_id']);
        $this->assertSame($this->expense, (int) $rows[1]['operating_expense_id']);
        $this->assertSame('invalidated', $rows[0]['status_code']);
        $this->assertSame('active', $rows[1]['status_code']);
    }

    public static function materialExpenseChanges(): array
    {
        return array_map(fn (array $change): array => [$change], [['amount' => '6000.00'], ['expense_date' => '2026-10-05'], ['vendor' => 'Synthetic Corrected Shop'], ['payment_reference' => 'SYNTHETIC-REFERENCE'], ['expense_category_lookup_value_id' => 1], ['status_code' => 'archived', 'archived_at' => '2026-10-07 09:00:00']]);
    }

    #[DataProvider('materialExpenseChanges')]
    public function testGuardedRepositoryMutationInvalidatesAtomically(array $change): void
    {
        $first = $this->create();
        $counts = $this->counts();
        (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, $change + ['updated_by' => 7]);
        $row = $this->reconcile->records->rows(1, 10, $this->job)[0];
        $this->assertSame('invalidated', $row['status_code']);
        $this->assertSame($counts['audit_logs'] + 2, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame($first['version'] + 1, (int) $this->repairs->job(1, 10, $this->job)['version']);
        $this->assertNotNull($this->repairs->job(1, 10, $this->job)['cost_finalized_at']);
    }

    public function testClockOnlyUpdateNoOpAndReadTimeStaleDetectionArePure(): void
    {
        $this->create();
        $counts = $this->counts();
        (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, ['updated_at' => '2026-10-07 11:00:00', 'updated_by' => 7]);
        $this->assertSame($counts, $this->counts());
        $this->assertSame('active', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $this->connection->table('operating_expenses')->where('id', $this->expense)->update(['vendor' => 'Synthetic unexpected SQL change']);
        $this->assertSame('stale / review required', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $this->assertSame($counts, $this->counts());
        $this->assertSame('active', $this->reconcile->records->rows(1, 10, $this->job)[0]['status_code']);
    }

    public function testUnexpectedNegativeSourceMoneyShowsStaleAndOriginalReceiptStillReplays(): void
    {
        $payload = $this->payload();
        $this->success($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7));
        $this->connection->table('operating_expenses')->where('id', $this->expense)->update(['amount' => '-1.00']);
        $counts = $this->counts();
        $this->assertSame('stale / review required', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $replay = $this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7);
        $this->success($replay);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($counts, $this->counts());
    }

    public function testRetainedOwnershipAndPhysicalDeleteFailEvenAfterInvalidation(): void
    {
        $this->create();
        $this->expectException(\Throwable::class);
        (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, ['fleet_vehicle_id' => 11, 'updated_by' => 7]);
    }

    public function testRelevantInvoiceVoidInvalidatesInsideOnlyB23Event(): void
    {
        $first = $this->create();
        $counts = $this->counts();
        $cost = new VehicleDamageRepairCostService($this->connection);
        $this->success($this->work->voidCostEntry(1, 10, $this->job, $this->command($this->job, ['cost_entry_id' => $this->root, 'expected_entry_state' => $cost->entryFingerprint(1, 10, $this->job, $this->root), 'confirmed' => '1', 'reason' => 'Synthetic invoice void']), 7));
        $this->assertSame('invalidated', $this->reconcile->records->rows(1, 10, $this->job)[0]['status_code']);
        $this->assertSame($counts['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame($first['version'] + 1, (int) $this->repairs->job(1, 10, $this->job)['version']);
    }

    public static function lostAckCommands(): array
    {
        return [['create'], ['invalidate'], ['replace']];
    }

    public function testExplicitConfirmationsFinalizationAndOwnedIdsFailClosed(): void
    {
        $before = $this->counts();
        foreach (['confirmed', 'whole_fact_confirmed', 'no_other_job_confirmed', 'no_other_expense_confirmed', 'not_partial_confirmed'] as $field) {
            $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload([$field => '0']), 7));
        }
        foreach ([[2, 10, $this->job, 7], [1, 11, $this->job, 7], [1, 10, 999999, 7], [1, 10, $this->job, 0]] as [$c, $v, $j, $a]) {
            $this->failure($this->work->reconcileRepairCostToExpense($c, $v, $j, $this->payload(), $a));
        }
        foreach (['cost_root_entry_id', 'operating_expense_id'] as $field) {
            $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload([$field => 999999]), 7));
        }
        $this->connection->table('vehicle_damage_repair_jobs')->where('id', $this->job)->update(['cost_finalized_at' => null, 'cost_finalized_by' => null, 'cost_finalization_note' => null]);
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload(), 7), 'finalization');
        $this->assertSame($before, $this->counts());
    }

    public function testImmutableFactsNoDeleteNoReactivationAndRestoreRequiresNewDecision(): void
    {
        $this->create();
        $row = $this->reconcile->records->rows(1, 10, $this->job)[0];
        $debug = new \ReflectionProperty($this->connection, 'DBDebug');
        $oldDebug = $debug->getValue($this->connection);
        $debug->setValue($this->connection, false);
        try {
            $this->assertFalse($this->connection->table(Records::TABLE)->where('id', $row['id'])->update(['reason' => 'Synthetic rewrite']));
            $this->assertFalse($this->connection->table(Records::TABLE)->where('id', $row['id'])->delete());
            $this->assertFalse($this->connection->table('operating_expenses')->where('id', $this->expense)->delete());
        } finally {
            $debug->setValue($this->connection, $oldDebug);
        }
        $expenses = new OperatingExpenseRepository($this->connection);
        $expenses->updateExpense(1, $this->expense, ['status_code' => 'archived', 'archived_at' => '2026-10-07 10:00:00', 'updated_by' => 7]);
        $rows = $this->reconcile->records->rows(1, 10, $this->job);
        $counts = $this->counts();
        $expenses->updateExpense(1, $this->expense, ['status_code' => 'recorded', 'archived_at' => null, 'updated_by' => 7]);
        $this->assertSame($counts, $this->counts());
        $this->assertSame('invalidated', $this->reconcile->records->rows(1, 10, $this->job)[0]['status_code']);
        $debug->setValue($this->connection, false);
        try {
            $this->assertFalse($this->connection->table('operating_expenses')->where('id', $this->expense)->delete());
            $this->assertFalse($this->connection->table(Records::TABLE)->where('id', $rows[0]['id'])->update(['status_code' => 'active', 'invalidated_at' => null, 'invalidated_by' => null, 'invalidation_reason' => null, 'current_cost_root_entry_id' => $this->root, 'current_operating_expense_id' => $this->expense]));
        } finally {
            $debug->setValue($this->connection, $oldDebug);
        }
    }

    public static function failures(): array
    {
        return [['vehicle_damage_financial_reconciliations', 'INSERT'], ['vehicle_damage_repair_jobs', 'UPDATE'], ['audit_logs', 'INSERT'], ['vehicle_damage_repair_job_events', 'INSERT']];
    }

    #[DataProvider('failures')]
    public function testInjectedFailuresRollBackAllCommandEffects(string $table, string $operation): void
    {
        $before = $this->counts();
        $payload = $this->payload();
        $listener = function (Query $query) use ($table, $operation): void {
            if (str_starts_with($query->getQuery(), $operation . ($operation === 'INSERT' ? ' INTO ' : ' ') . $this->connection->escapeIdentifiers($this->connection->prefixTable($table)))) {
                throw new \RuntimeException('Synthetic transaction fault');
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $payload, 7));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, $this->connection->table(Records::TABLE)->countAllResults());
    }

    public function testExpenseFailureAfterSourceMutationRollsBackInvalidationAndSource(): void
    {
        $this->create();
        $before = $this->counts();
        $rows = $this->reconcile->records->rows(1, 10, $this->job);
        $listener = static function (Query $query): void {
            if (str_starts_with($query->getQuery(), 'INSERT INTO `db_audit_logs`')) {
                throw new \RuntimeException('Synthetic audit fault after source change and invalidation');
            }
        };
        Events::on('DBQuery', $listener);
        try {
            try {
                (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, ['amount' => '6000.00', 'updated_by' => 7]);
                $this->fail('Guard must roll back on audit failure.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Synthetic', $exception->getMessage());
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame($rows, $this->reconcile->records->rows(1, 10, $this->job));
        $this->assertSame('6200.00', $this->reconcile->expenses(1, [$this->expense])[$this->expense]['snapshot']['amount']);
    }

    public function testPartialSchemaBlocksBothSourceAndRepairWritesAndLeavesReadsUsable(): void
    {
        $this->connection->query('DROP TRIGGER db_b32_no_delete');
        $this->connection->resetDataCache();
        $this->assertFalse($this->reconcile->records->ready());
        $this->failure($this->work->reconcileRepairCostToExpense(1, 10, $this->job, $this->payload(), 7), 'incomplete');
        $this->assertFalse($this->reconcile->workspace(1, 10, $this->job)['ready']);
        $this->expectException(\RuntimeException::class);
        (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, ['amount' => '6000.00', 'updated_by' => 7]);
    }

    protected function costFact(string $kind, string $amount, ?int $related = null, ?int $replace = null): array
    {
        $service = new VehicleDamageRepairCostService($this->connection);
        $data = ['kind_code' => $kind, 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'vendor_reference' => null, 'confirmed' => '1', 'performed_work_confirmed' => '1', 'related_cost_entry_id' => $related, 'reason' => 'Synthetic current family correction'];
        if ($replace === null) {
            $data['document'] = ['kind_code' => \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$kind], 'upload' => $this->upload()];
            $data = $service->normalize($data, 1, 10, $this->job);
            $checksum = $data['document']['descriptor']['checksum'];
        } else {
            $entry = array_column($service->costs->entries(1, 10, $this->job), null, 'id')[$replace];
            $doc = $service->sources->documents->documents->document(1, 10, $this->job, (int) $entry['repair_document_id']);
            $data += ['cost_entry_id' => $replace, 'expected_entry_state' => $service->entryFingerprint(1, 10, $this->job, $replace), 'repair_document_id' => $doc['id'], 'expected_document_state' => $service->sources->documents->documents->fingerprint(1, 10, $this->job, (int) $doc['id'])];
            $checksum = $doc['content_checksum'];
        }
        $review = $service->duplicates(1, 10, $this->job, $data, $checksum, $replace ?? 0);
        if ($review['candidates'] !== []) {
            $data += ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic independent complete source review'];
        }
        $method = $replace === null ? 'recordCostEntry' : 'replaceCostEntry';
        $result = $this->work->{$method}(1, 10, $this->job, $this->command($this->job, $data), 7);
        $this->success($result);
        return $result;
    }

    public static function familyMutations(): array
    {
        return [['invoice_replace', true, 4], ['credit_create', true, 4], ['credit_replace', true, 4], ['credit_void', true, 3], ['unrelated_invoice', false, 3], ['payment', false, 3], ['refund', false, 3]];
    }

    #[DataProvider('familyMutations')]
    public function testEveryFamilyMutationUsesOnlyTriggeringB23Event(string $case, bool $invalidates, int $audits): void
    {
        $credit = null;
        $payment = null;
        if (in_array($case, ['credit_replace', 'credit_void'], true)) {
            $credit = $this->costFact('invoice_credit', '100.00', $this->root)['cost_entry_id'];
            (new OperatingExpenseRepository($this->connection))->updateExpense(1, $this->expense, ['amount' => '6100.00', 'updated_by' => 7]);
            $cost = new VehicleDamageRepairCostService($this->connection);
            $this->success($this->work->finalizeRepairCost(1, 10, $this->job, $this->command($this->job, ['confirmed' => '1', 'expected_invoiced_state' => $cost->invoicedFingerprint(1, 10, $this->job), 'note' => 'Synthetic net credit reviewed']), 7));
        }
        if ($case === 'refund') {
            $payment = $this->costFact('payment', '50.00')['cost_entry_id'];
        }
        $first = $this->create();
        $counts = $this->counts();
        match ($case) {
            'invoice_replace' => $this->costFact('invoice', '6000.00', null, $this->root),
            'credit_create' => $this->costFact('invoice_credit', '100.00', $this->root),
            'credit_replace' => $this->costFact('invoice_credit', '50.00', $this->root, $credit),
            'credit_void' => $this->success($this->work->voidCostEntry(1, 10, $this->job, $this->command($this->job, ['cost_entry_id' => $credit, 'expected_entry_state' => (new VehicleDamageRepairCostService($this->connection))->entryFingerprint(1, 10, $this->job, $credit), 'reason' => 'Synthetic credit void', 'confirmed' => '1']), 7)),
            'unrelated_invoice' => $this->costFact('invoice', '25.00'),
            'payment' => $this->costFact('payment', '50.00'),
            'refund' => $this->costFact('payment_refund', '10.00', $payment),
            default => throw new \InvalidArgumentException('Unknown synthetic family mutation.'),
        };
        $this->assertSame($invalidates ? 'invalidated' : 'active', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $this->assertSame($counts['audit_logs'] + $audits, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame($first['version'] + 1, (int) $this->repairs->job(1, 10, $this->job)['version']);
    }

    public function testExpenseEvidenceAttachAndClassificationChangesInvalidateButClockAndNoteDoNot(): void
    {
        $settings = new \Config\ExpenseReceipts();
        $stored = (new \App\Services\Files\PrivateEvidenceStorageService(new \App\Repositories\FileRepository($this->connection)))->store($this->upload(), $settings->storageDirectory, $settings->allowedMimeTypes, $settings->maxFileSizeBytes, '2026-10-06', 7);
        $this->paths[] = $stored['absolute_path'];
        $this->create();
        $repo = new OperatingExpenseRepository($this->connection);
        $receipt = $repo->createReceipt(['company_id' => 1, 'operating_expense_id' => $this->expense, 'file_id' => $stored['file_id'], 'classification_code' => 'operating_expense', 'created_by' => 7, 'created_at' => '2026-10-06 10:00:00', 'updated_at' => '2026-10-06 10:00:00']);
        $this->assertSame('invalidated', $this->reconcile->records->rows(1, 10, $this->job)[0]['status_code']);
        $this->create();
        $counts = $this->counts();
        $repo->updateReceipt(1, $receipt, ['updated_at' => '2026-10-07 10:00:00', 'note' => 'Synthetic incidental note', 'classified_by' => 7]);
        $this->assertSame($counts, $this->counts());
        $repo->updateReceipt(1, $receipt, ['classification_code' => 'duplicate', 'classified_by' => 7]);
        $this->assertSame('invalidated', $this->reconcile->records->rows(1, 10, $this->job)[1]['status_code']);
        $this->assertSame($counts['audit_logs'] + 2, $this->counts()['audit_logs']);
    }

    public function testUnexpectedInvoiceEvidenceMutationIsReadOnlyStaleAndArchiveRemainsBlocked(): void
    {
        $this->create();
        $doc = $this->connection->table('vehicle_damage_repair_documents')->get()->getRowArray();
        $counts = $this->counts();
        $service = new VehicleDamageRepairCostService($this->connection);
        $this->failure($this->work->archiveDocument(1, 10, $this->job, $this->command($this->job, ['document_id' => $doc['id'], 'expected_document_state' => $service->sources->documents->documents->fingerprint(1, 10, $this->job, (int) $doc['id']), 'confirmed' => '1', 'reason' => 'Synthetic referenced document archive']), 7));
        $this->connection->table('files')->where('id', $doc['file_id'])->update(['checksum' => str_repeat('a', 64)]);
        $this->assertSame('stale / review required', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
        $this->assertSame($counts, $this->counts());
    }

    public function testExpenseServiceCorrectionArchiveAndRestoreRetainOriginalAudits(): void
    {
        $service = new \App\Services\Fleet\OperatingExpenseService(new OperatingExpenseRepository($this->connection), new \App\Repositories\AuditLogRepository($this->connection), new \App\Repositories\LookupRepository($this->connection));
        $this->create();
        $counts = $this->counts();
        $this->success($service->correct(1, $this->expense, ['amount' => '6200.00', 'expense_date' => '2026-10-06', 'category_code' => 'other', 'fleet_vehicle_id' => 10, 'vendor' => 'Synthetic Corrected Shop', 'business_purpose' => 'Synthetic complete repair', 'correction_reason' => 'Synthetic authoritative vendor correction'], 7));
        $this->assertSame($counts['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->create(['descriptor_review_confirmed' => '1', 'descriptor_review_reason' => 'Synthetic corrected vendor still represents complete invoice']);
        $counts = $this->counts();
        $this->success($service->archiveExpense(1, $this->expense, 'Synthetic source archive', 7));
        $this->assertSame($counts['audit_logs'] + 3, $this->counts()['audit_logs']);
        $counts = $this->counts();
        $this->success($service->restoreExpense(1, $this->expense, 7));
        $this->assertSame($counts['audit_logs'] + 1, $this->counts()['audit_logs']);
        $this->assertSame($counts['vehicle_damage_repair_job_events'], $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame('invalidated', $this->reconcile->records->rows(1, 10, $this->job)[1]['status_code']);
    }

    public function testExistingManualExpenseUploadAndInboxClassificationRemainUsableAfterMigration(): void
    {
        $this->create();
        $repo = new OperatingExpenseRepository($this->connection);
        $settings = new \Config\ExpenseReceipts();
        $storage = new \App\Services\Files\PrivateEvidenceStorageService(new \App\Repositories\FileRepository($this->connection));
        $service = new \App\Services\Fleet\OperatingExpenseService($repo, new \App\Repositories\AuditLogRepository($this->connection), new \App\Repositories\LookupRepository($this->connection), $storage, $settings);
        $counts = $this->counts();
        $result = $service->createManual(1, ['amount' => '127.00', 'expense_date' => '2026-10-06', 'category_code' => 'other', 'fleet_vehicle_id' => 10, 'vendor' => 'Synthetic Separate Expense', 'business_purpose' => 'Synthetic unrelated complete cost'], 7, $this->upload());
        $this->success($result);
        $receipts = $repo->receiptsForExpense(1, $result['id']);
        $this->assertCount(1, $receipts);
        $this->paths[] = $storage->resolve($receipts[0], $settings->storageDirectory, $settings->allowedMimeTypes)['path'];
        $this->assertSame($counts['audit_logs'] + 3, $this->counts()['audit_logs']);
        $inbox = $service->uploadReceipt(1, $this->upload(), ['document_date' => '2026-10-06', 'observed_amount' => '128.00', 'vendor' => 'Synthetic Inbox Expense'], 7);
        $this->success($inbox);
        $this->paths[] = $storage->resolve($repo->receipt(1, $inbox['receipt_id']), $settings->storageDirectory, $settings->allowedMimeTypes)['path'];
        $this->success($service->classifyReceipt(1, $inbox['receipt_id'], ['amount' => '128.00', 'expense_date' => '2026-10-06', 'category_code' => 'other', 'fleet_vehicle_id' => 10, 'vendor' => 'Synthetic Inbox Expense', 'business_purpose' => 'Synthetic separate inbox cost'], 7));
        $this->assertSame($counts['vehicle_damage_repair_job_events'], $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame('active', $this->reconcile->workspace(1, 10, $this->job)['history'][0]['read_status']);
    }

    public function testWorkspaceQueryCountDoesNotGrowWithCandidatesAndNeverLocksOrWrites(): void
    {
        $this->create();
        $queries = new \ArrayObject();
        $listener = static function (Query $query) use ($queries): void {
            $queries->append($query->getQuery());
        };
        $this->reconcile->workspace(1, 10, $this->job); // Warm stable framework metadata caches.
        Events::on('DBQuery', $listener);
        try {
            $this->reconcile->workspace(1, 10, $this->job);
            $count = count($queries);
            $queries->exchangeArray([]);
            $row = $this->connection->table('operating_expenses')->where('id', $this->expense)->get()->getRowArray();
            unset($row['id']);
            for ($i = 0; $i < 30; $i++) {
                $this->connection->table('operating_expenses')->insert($row);
            }
            $queries->exchangeArray([]);
            $workspace = $this->reconcile->workspace(1, 10, $this->job);
            $this->assertCount(20, $workspace['candidate_ids']);
            $this->assertSame($count, count($queries));
            foreach ($queries as $sql) {
                $this->assertStringNotContainsString('FOR UPDATE', $sql);
                $this->assertMatchesRegularExpression('/^(SELECT|PRAGMA)/i', trim($sql));
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
    }

    public function testRepairEvidenceWorkspaceIsBoundedWithoutReadSideEffects(): void
    {
        $document = $this->connection->table('vehicle_damage_repair_documents')->get()->getRowArray();
        unset($document['id']);
        $this->assertNotFalse($this->connection->table('vehicle_damage_repair_documents')->insertBatch(array_fill(0, 999, $document)));
        $before = $this->counts();
        $this->assertNotNull($this->reconcile->families(1, 10, $this->job)[$this->root]['snapshot'], 'Exactly 1,000 documents remain within the supported bound.');
        $this->assertSame($before, $this->counts());
        $this->assertNotFalse($this->connection->table('vehicle_damage_repair_documents')->insert($document));
        $before = $this->counts();
        try {
            $this->reconcile->workspace(1, 10, $this->job);
            $this->fail('An oversized repair-document workspace must fail explicitly, without partial authority.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('evidence exceeds the supported workspace bound', $exception->getMessage());
        }
        $this->assertSame($before, $this->counts());
    }

    #[DataProvider('lostAckCommands')]
    public function testActualPostCommitLostAckReplaysExactly(string $action): void
    {
        if ($action !== 'create') {
            $this->create();
        }
        $payload = $this->payload();
        $method = 'reconcileRepairCostToExpense';
        if ($action !== 'create') {
            $row = $this->reconcile->records->rows(1, 10, $this->job)[0];
            $payload += ['reconciliation_id' => $row['id'], 'expected_reconciliation_state' => Reconcile::state($row)];
            $method = $action === 'replace' ? 'replaceFinancialReconciliation' : 'invalidateFinancialReconciliation';
        }
        $injected = false;
        $listener = static function (Query $query) use (&$injected): void {
            if (! $injected && strtoupper(trim($query->getQuery())) === 'COMMIT') {
                $injected = true;
                throw new \RuntimeException('Synthetic post-COMMIT acknowledgement lost');
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $uncertain = $this->work->{$method}(1, 10, $this->job, $payload, 7);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertTrue($injected);
        $this->assertTrue($uncertain['uncertain']);
        $counts = $this->counts();
        $rows = $this->reconcile->records->rows(1, 10, $this->job);
        $replay = $this->work->{$method}(1, 10, $this->job, $payload, 7);
        $this->success($replay);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($counts, $this->counts());
        $this->assertSame($rows, $this->reconcile->records->rows(1, 10, $this->job));
    }
}
