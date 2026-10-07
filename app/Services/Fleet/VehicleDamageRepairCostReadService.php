<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/** Bounded, owned job reads. Missing evidence produces warnings, never inferred facts. */
class VehicleDamageRepairCostReadService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function workspace(int $c, int $v, int $j): array
    {
        $service = new VehicleDamageRepairCostService($this->db);
        if (! $service->costs->ready()) {
            return ['ready' => false, 'entries' => [], 'invoiced' => null, 'payments' => null, 'entry_states' => [], 'referenced_documents' => [], 'issues' => []];
        }
        $rows = $service->costs->entries($c, $v, $j);
        $states = [];
        $issues = [];
        foreach ($rows as $row) {
            try {
                $states[$row['id']] = $service->entryFingerprint($c, $v, $j, (int) $row['id']);
                $service->verifyDocument($c, $v, $j, (int) $row['repair_document_id'], $row['kind_code']);
            } catch (Throwable) {
                $issues[$row['id']] = 'Source or correction lineage needs review. Recorded facts remain in history.';
            }
        }
        $invoicedState = null;
        $finalizationState = null;
        try {
            $invoicedState = $service->invoicedFingerprint($c, $v, $j);
            $finalizationState = $service->finalizationFingerprint($c, $v, $j);
        } catch (Throwable) {
            $issues[0] = 'Invoiced cost state needs review before finalization.';
        }
        return ['ready' => true, 'entries' => $rows, 'entry_states' => $states, 'referenced_documents' => array_map('intval', array_column($rows, 'repair_document_id')), 'issues' => $issues,
            'invoiced_state' => $invoicedState, 'finalization_state' => $finalizationState] + Costs::totals($rows);
    }
}
