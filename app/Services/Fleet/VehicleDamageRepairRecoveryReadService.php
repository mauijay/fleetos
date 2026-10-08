<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Repositories\VehicleDamageRepairRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/** Read-only economics. A stored finalization marker alone never establishes source validity. */
class VehicleDamageRepairRecoveryReadService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function workspace(int $c, int $v, int $j): array
    {
        $work = new VehicleDamageRepairRepository($this->db);
        $job = $work->job($c, $v, $j);
        if ($job === null) {
            throw new \InvalidArgumentException('Repair job not found in this context.');
        }
        $result = ['ready' => false, 'entries' => [], 'entry_states' => [], 'referenced_documents' => [], 'net' => null, 'ledger_state' => null, 'finalization_state' => null,
            'recovery_finalized_valid' => false, 'cost_finalized_valid' => false, 'cost' => null, 'host_balance' => null, 'excess' => null, 'issues' => [], 'duplicate_review' => ['candidates' => []], 'turo_enabled' => false];
        $service = new VehicleDamageRepairRecoveryService($this->db);
        if (! $service->recoveries->ready()) {
            return $result;
        }
        $result['ready'] = true;
        $rows = $service->recoveries->entries($c, $v, $j);
        $result['entries'] = $rows;
        $result['referenced_documents'] = array_map('intval', array_column($rows, 'repair_document_id'));
        $result['finalization_state'] = $service->finalizationFingerprint($c, $v, $j);
        foreach ($rows as $row) {
            try {
                $result['entry_states'][$row['id']] = $service->entryFingerprint($c, $v, $j, (int) $row['id']);
            } catch (Throwable) {
                $result['issues'][$row['id']] = 'Recovery correction lineage needs review. History is retained.';
            }
        }
        try {
            $ledger = $service->ledger($c, $v, $j);
            $result['net'] = Recoveries::totals($rows)['net'];
            $result['ledger_state'] = Estimates::digest($ledger);
            $result['finalization_state'] = $service->finalizationFingerprint($c, $v, $j);
            $result['duplicate_review'] = $service->ledgerDuplicates($c, $v, $j);
            $event = $this->finalization($c, $j, 'repair_recovery_finalized');
            $final = $event['receipt']['finalization'] ?? null;
            $result['recovery_finalized_valid'] = $this->markerMatches($job, $event['job'] ?? [], 'recovery') && is_array($final)
                && ($final['ledger_fingerprint'] ?? null) === $result['ledger_state']
                && ($final['duplicate_review']['fingerprint'] ?? null) === $result['duplicate_review']['fingerprint'];
            if ($job['recovery_finalized_at'] !== null && ! $result['recovery_finalized_valid']) {
                $result['issues'][0] = 'Recovery source, evidence or duplicate review changed. Completeness needs review.';
            }
            if ($result['recovery_finalized_valid'] && $result['net'] === null) {
                $result['net'] = '0.00';
            }
        } catch (Throwable) {
            $result['issues'][0] = 'Recovery source/evidence or correction lineage needs review. Final economic result is unavailable.';
        }
        try {
            $cost = new VehicleDamageRepairCostService($this->db);
            $costRows = $cost->costs->entries($c, $v, $j);
            $result['cost'] = Costs::totals($costRows)['invoiced'];
            $frozen = [];
            foreach ($costRows as $row) {
                if ($row['status_code'] === 'recorded' && in_array($row['kind_code'], ['invoice', 'invoice_credit'], true)) {
                    $cost->validateRelations($costRows, $row);
                    $cost->verifyDocument($c, $v, $j, (int) $row['repair_document_id'], $row['kind_code']);
                    $frozen[] = ['id' => (int) $row['id'], 'fingerprint' => $cost->entryFingerprint($c, $v, $j, (int) $row['id'])];
                }
            }
            $event = $this->finalization($c, $j, 'repair_cost_finalized');
            $final = $event['receipt']['finalization'] ?? null;
            $result['cost_finalized_valid'] = $result['cost'] !== null && $this->markerMatches($job, $event['job'] ?? [], 'cost') && is_array($final)
                && Estimates::digest($frozen) === Estimates::digest($final['entries'] ?? []) && $result['cost'] === ($final['invoiced_total'] ?? null);
            if ($job['cost_finalized_at'] !== null && ! $result['cost_finalized_valid']) {
                $result['issues']['cost'] = 'Repair cost finalization or its evidence needs review.';
            }
        } catch (Throwable) {
            $result['issues']['cost'] = 'Repair cost authority needs review. Final economic result is unavailable.';
        }
        if ($result['cost'] !== null && $result['net'] !== null && $result['ledger_state'] !== null && ! isset($result['issues']['cost']) && RepairCostMoney::compare($result['cost'], $result['net']) < 0) {
            $result['excess'] = RepairCostMoney::subtract($result['net'], $result['cost']);
        }
        if ($result['cost_finalized_valid'] && $result['recovery_finalized_valid'] && $result['net'] !== null) {
            $comparison = RepairCostMoney::compare($result['cost'], $result['net']);
            if ($comparison < 0) {
                $result['excess'] = RepairCostMoney::subtract($result['net'], $result['cost']);
                $final = $this->finalization($c, $j, 'repair_recovery_finalized')['receipt']['finalization'];
                if (empty($final['scope_review_confirmed'])) {
                    $result['issues']['scope'] = 'Recovery exceeds recorded repair cost. Invalidate completeness and explicitly review recovery scope before finalizing again.';
                    return $result;
                }
                $result['host_balance'] = '-' . $result['excess'];
            } else {
                $result['host_balance'] = RepairCostMoney::subtract($result['cost'], $result['net']);
            }
        }
        return $result;
    }

    private function finalization(int $c, int $j, string $event): array
    {
        $row = $this->db->table('vehicle_damage_repair_job_events')->select('after_json')->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->where('event_code', $event)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        return $row === null ? [] : json_decode($row['after_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function markerMatches(array $job, array $frozenJob, string $prefix): bool
    {
        if (($job[$prefix . '_finalized_at'] ?? null) === null) {
            return false;
        }
        foreach (['_finalized_at', '_finalized_by', '_finalization_note'] as $suffix) {
            if ((string) ($job[$prefix . $suffix] ?? '') !== (string) ($frozenJob[$prefix . $suffix] ?? '')) {
                return false;
            }
        }
        return true;
    }
}
