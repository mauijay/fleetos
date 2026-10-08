<?php

namespace Tests\Database;

use App\Repositories\ChargingCostRepository;
use App\Repositories\FleetVehicleRepository;
use App\Repositories\MaintenanceCostRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Fleet\FinancialActivityReadService;
use App\Services\Fleet\FinancialSummaryService;
use App\Services\Fleet\VehicleFinancialSummaryService;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

final class VehicleDamageRepairRecoveryBoundaryTest extends VehicleDamageRepairRecoveryTestCase
{
    public function testCommandsAndGetLeaveEveryOtherDomainAndBothFinancialReportsByteEquivalent(): void
    {
        $j = $this->createWork([$this->condition()]);
        $activity = new FinancialActivityReadService(new TuroNormalizedTransactionRepository($this->connection), new TripMonthAllocationRepository($this->connection), new OperatingExpenseRepository($this->connection), new MaintenanceCostRepository($this->connection), new ChargingCostRepository($this->connection), new TuroAccessReimbursementRepository($this->connection));
        $company = new FinancialSummaryService($activity);
        $vehicles = new VehicleFinancialSummaryService($company, new FleetVehicleRepository($this->connection));
        $reports = fn (): string => json_encode([$company->period(1, '2026-10-01', '2026-11-01'), $vehicles->period(1, '2026-10-01', '2026-11-01')], JSON_THROW_ON_ERROR);
        $beforeReports = $reports();
        $before = $this->boundary();
        $receipt = $this->receipt($j);
        $this->success($receipt);
        $this->success($this->reverse($j, $receipt['recovery_entry_id']));
        $this->success($this->work->replaceRecovery(1, 10, $j, $this->correction($j, $receipt['recovery_entry_id'], ['amount' => '90.00']), 7));
        $this->success($this->finalizeRecovery($j));
        $this->success($this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic completeness review', 'expected_finalization_state' => $this->recovery->finalizationFingerprint(1, 10, $j)]), 7));
        $this->assertSame($before, $this->boundary());
        $this->assertSame($beforeReports, $reports());
        $all = $this->rows();
        $this->economics($j);
        $this->assertSame($all, $this->rows(), 'GET economics creates no history or business change.');
    }

    public function testClaimAmountsAreContextOnlyAndAssociationNeedsExplicitJobRelationship(): void
    {
        $condition = $this->condition();
        $j = $this->createWork([$condition]);
        $this->connection->table('damage_claims')->insert(['fleet_vehicle_id' => 10, 'claim_number' => 'SYNTHETIC-CLAIM', 'estimated_repair_amount' => '900.00', 'approved_reimbursement_amount' => '800.00', 'paid_amount' => '700.00']);
        $claim = (int) $this->connection->insertID();
        $claimBefore = $this->connection->table('damage_claims')->get()->getResultArray();
        $this->assertNull($this->economics($j)['net']);
        $this->failure($this->receipt($j, ['damage_claim_id' => $claim, 'claim_context_confirmed' => '1']), 'relationship');
        $this->connection->table('vehicle_damage_items')->where('id', $condition)->update(['damage_claim_id' => $claim]);
        $this->failure($this->receipt($j, ['damage_claim_id' => $claim, 'claim_context_confirmed' => '0']), 'confirmation');
        $this->success($this->receipt($j, ['damage_claim_id' => $claim, 'claim_context_confirmed' => '1', 'amount' => '25.00']));
        $this->assertSame('25.00', $this->economics($j)['net']);
        $this->assertSame($claimBefore, $this->connection->table('damage_claims')->get()->getResultArray());
    }

    private function boundary(): array
    {
        $rows = $this->rows();
        foreach (['files', 'images', 'vehicle_damage_repair_documents', 'vehicle_damage_repair_recovery_entries', 'vehicle_damage_repair_job_events', 'audit_logs'] as $table) {
            unset($rows[$this->connection->prefixTable($table)]);
        }
        foreach ($rows[$this->connection->prefixTable('vehicle_damage_repair_jobs')] as &$row) {
            foreach (['version', 'updated_by', 'updated_at', 'recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'] as $field) {
                unset($row[$field]);
            }
        }
        unset($row);
        return $rows;
    }

    private function rows(): array
    {
        $rows = [];
        foreach ($this->connection->listTables() as $table) {
            $rows[$table] = $this->connection->table($table)->get()->getResultArray();
        }
        return $rows;
    }
}
