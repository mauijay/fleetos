<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroAccessReimbursementRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function workflow(int $workflowId): ?array
    {
        $row = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, trips.guest_name, fv.fleet_code, fv.display_name')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->where('workflows.id', $workflowId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function incident(int $id): ?array
    {
        $row = $this->db->table('airport_turo_access_override_incidents incidents')
            ->select('incidents.*, trips.guest_name, trips.turo_trip_id, fv.fleet_code, fv.display_name')
            ->join('turo_trips_normalized trips', 'trips.id = incidents.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = incidents.fleet_vehicle_id', 'left')
            ->where('incidents.id', $id)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function possibleDuplicate(int $workflowId, ?string $ticketNumber): ?array
    {
        $builder = $this->db->table('airport_turo_access_override_incidents')
            ->where('airport_movement_workflow_id', $workflowId);

        if ($ticketNumber !== null && trim($ticketNumber) !== '') {
            $builder->where('ticket_number', trim($ticketNumber));
        }

        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createIncident(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_turo_access_override_incidents')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit($id, 'incident_created', null, $data);

        return $id;
    }

    public function updateIncident(int $id, array $data, string $action): bool
    {
        $old = $this->incident($id);
        if ($old === null) {
            return false;
        }

        $this->db->table('airport_turo_access_override_incidents')->where('id', $id)->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        $this->audit($id, $action, $old, array_merge($old, $data));

        return $this->db->affectedRows() > 0;
    }

    public function createReceipt(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_turo_access_receipts')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit((int) ($data['airport_turo_access_override_incident_id'] ?? 0), 'receipt_attached', null, $data);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function receipt(int $id): ?array
    {
        $row = $this->db->table('airport_turo_access_receipts receipts')
            ->select('receipts.*, files.path, files.mime_type AS file_mime_type, files.size_bytes, files.checksum, files.original_filename AS file_original_filename')
            ->join('files', 'files.id = receipts.file_id', 'left')
            ->where('receipts.id', $id)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    public function updateReceipt(int $id, array $data, string $action): bool
    {
        $old = $this->receipt($id);
        if ($old === null) {
            return false;
        }
        $this->db->table('airport_turo_access_receipts')->where('id', $id)->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        $this->audit($old['airport_turo_access_override_incident_id'] === null ? null : (int) $old['airport_turo_access_override_incident_id'], $action, $old, array_merge($old, $data));

        return $this->db->affectedRows() > 0;
    }

    public function recordAirportException(int $workflowId, string $note): void
    {
        $now = date('Y-m-d H:i:s');
        $workflow = $this->workflow($workflowId);
        $this->db->table('airport_movement_exceptions')->insert([
            'airport_movement_workflow_id' => $workflowId,
            'exception_type' => 'airport_turo_access_overridden',
            'severity' => 'today',
            'note' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->table('airport_movement_workflows')->where('id', $workflowId)->update(['workflow_status' => 'exception', 'updated_at' => $now]);
        $this->audit(null, 'airport_exception_recorded', $workflow, ['airport_movement_workflow_id' => $workflowId, 'exception_type' => 'airport_turo_access_overridden']);
    }

    /** @return array<int, array<string, mixed>> */
    public function receiptsForIncident(int $incidentId): array
    {
        return $this->db->table('airport_turo_access_receipts')
            ->where('airport_turo_access_override_incident_id', $incidentId)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function inbox(): array
    {
        return $this->db->table('airport_turo_access_override_incidents incidents')
            ->select('incidents.*, trips.guest_name, trips.turo_trip_id, fv.fleet_code, fv.display_name')
            ->join('turo_trips_normalized trips', 'trips.id = incidents.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = incidents.fleet_vehicle_id', 'left')
            ->whereNotIn('incidents.claim_status', ['reimbursed', 'denied', 'closed_without_filing'])
            ->orderBy('incidents.incident_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function unmatchedReceipts(): array
    {
        return $this->db->table('airport_turo_access_receipts')
            ->where('airport_turo_access_override_incident_id', null)
            ->orderBy('document_date', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateAirportTrips(?int $vehicleId, string $date): array
    {
        $start = (new \DateTimeImmutable($date))->modify('-2 days')->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($date))->modify('+3 days')->format('Y-m-d H:i:s');
        $builder = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, trips.guest_name, trips.turo_trip_id, fv.fleet_code, fv.display_name')
            ->select('incidents.id AS existing_incident_id, incidents.claim_status AS existing_claim_status, incidents.ticket_number AS existing_ticket_number, incidents.parking_amount_paid AS existing_parking_amount_paid')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->join('airport_turo_access_override_incidents incidents', 'incidents.airport_movement_workflow_id = workflows.id', 'left')
            ->where('workflows.scheduled_at >=', $start)
            ->where('workflows.scheduled_at <', $end)
            ->orderBy('workflows.scheduled_at', 'ASC');
        if ($vehicleId !== null && $vehicleId > 0) {
            $builder->orderBy('CASE WHEN workflows.fleet_vehicle_id = ' . $this->db->escape($vehicleId) . ' THEN 0 ELSE 1 END', '', false);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function searchAirportTrips(string $query): array
    {
        return $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, trips.guest_name, trips.turo_trip_id, fv.fleet_code, fv.display_name')
            ->select('incidents.id AS existing_incident_id, incidents.claim_status AS existing_claim_status, incidents.ticket_number AS existing_ticket_number, incidents.parking_amount_paid AS existing_parking_amount_paid')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->join('airport_turo_access_override_incidents incidents', 'incidents.airport_movement_workflow_id = workflows.id', 'left')
            ->groupStart()
                ->like('fv.fleet_code', $query)
                ->orLike('trips.guest_name', $query)
                ->orLike('trips.turo_trip_id', $query)
            ->groupEnd()
            ->orderBy('workflows.scheduled_at', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transStart();
        $result = $callback();
        $this->db->transComplete();

        return $this->db->transStatus() === false ? false : $result;
    }

    /** @return array<string, mixed>|null */
    public function existingIncidentForWorkflow(int $workflowId, ?string $ticketNumber = null): ?array
    {
        $builder = $this->db->table('airport_turo_access_override_incidents')
            ->where('airport_movement_workflow_id', $workflowId)
            ->whereNotIn('claim_status', ['denied', 'closed_without_filing']);
        if ($ticketNumber !== null && trim($ticketNumber) !== '') {
            $builder->where('ticket_number', trim($ticketNumber));
        }

        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function linkReceiptToIncident(int $receiptId, int $incidentId): bool
    {
        $incident = $this->incident($incidentId);
        if ($incident === null) {
            return false;
        }

        return $this->updateReceipt($receiptId, [
            'airport_turo_access_override_incident_id' => $incidentId,
            'airport_operations_expense_id' => null,
            'receipt_classification' => 'trip_reimbursement',
            'turo_trip_normalized_id' => $incident['turo_trip_normalized_id'],
            'fleet_vehicle_id' => $incident['fleet_vehicle_id'],
        ], 'receipt_linked_to_incident');
    }

    public function createOperationsRun(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_runs')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_run_created', null, array_merge($data, ['id' => $id]));

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function operationsRun(int $id): ?array
    {
        $row = $this->db->table('airport_operations_runs')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createRunActivity(int $runId, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_run_activities')->insert(array_merge($data, ['airport_operations_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_run_activity_created', null, array_merge($data, ['airport_operations_run_id' => $runId, 'id' => $id]));

        return $id;
    }

    public function createOperationsExpense(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_expenses')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_expense_created', null, array_merge($data, ['id' => $id]));

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function operationsExpense(int $id): ?array
    {
        $row = $this->db->table('airport_operations_expenses')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function classifyReceipt(int $receiptId, string $classification, ?int $expenseId = null, ?string $note = null): bool
    {
        return $this->updateReceipt($receiptId, [
            'receipt_classification' => $classification,
            'airport_operations_expense_id' => $expenseId,
            'airport_turo_access_override_incident_id' => $classification === 'trip_reimbursement' ? null : null,
            'classification_note' => $note,
        ], 'receipt_classified');
    }

    public function linkReceiptToOperationsExpense(int $receiptId, int $expenseId, ?int $runId, string $note): bool
    {
        return $this->updateReceipt($receiptId, [
            'receipt_classification' => 'airport_operations_expense',
            'airport_operations_expense_id' => $expenseId,
            'airport_turo_access_override_incident_id' => null,
            'classification_note' => $note,
        ], 'receipt_linked_to_operations_expense');
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateOperationsRuns(?string $date, ?int $vehicleId = null): array
    {
        $builder = $this->db->table('airport_operations_runs runs')
            ->select('DISTINCT runs.*', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable('airport_operations_expenses') . ' expenses WHERE expenses.airport_operations_run_id = runs.id) AS expense_count', false)
            ->orderBy('runs.run_date', 'DESC')
            ->limit(20);
        if ($date !== null && $date !== '') {
            $start = (new \DateTimeImmutable($date))->modify('-2 days')->format('Y-m-d');
            $end = (new \DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d');
            $builder->where('runs.run_date >=', $start)->where('runs.run_date <=', $end);
        }
        if ($vehicleId !== null && $vehicleId > 0) {
            $builder->join('airport_operations_run_activities activities', 'activities.airport_operations_run_id = runs.id', 'left')
                ->groupStart()
                    ->where('activities.fleet_vehicle_id', $vehicleId)
                    ->orWhere('runs.chase_fleet_vehicle_id', $vehicleId)
                ->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function operationsAttentionSummary(): array
    {
        $needsClassification = (int) $this->db->table('airport_turo_access_receipts')->where('receipt_classification', 'unresolved')->countAllResults();
        $missingRun = (int) $this->db->table('airport_operations_expenses')->where('airport_operations_run_id', null)->countAllResults();
        $unallocated = (int) $this->db->table('airport_operations_expenses expenses')->join('airport_operations_expense_allocations allocations', 'allocations.airport_operations_expense_id = expenses.id', 'left')->where('allocations.id', null)->countAllResults();
        $missingImages = (int) $this->db->table('airport_operations_expenses')->where('file_id', null)->countAllResults();
        $monthTotal = (float) ($this->db->table('airport_operations_expenses')->selectSum('amount')->where('expense_date >=', date('Y-m-01'))->get()->getRowArray()['amount'] ?? 0);

        return ['needs_classification' => $needsClassification, 'expenses_missing_run' => $missingRun, 'runs_with_unallocated_expenses' => $unallocated, 'operations_receipts_missing_images' => $missingImages, 'month_operations_total' => $monthTotal];
    }

    /** @param array<int, array<string, mixed>> $allocations */
    public function replaceExpenseAllocations(int $expenseId, array $allocations): bool
    {
        $expense = $this->operationsExpense($expenseId);
        if ($expense === null) {
            return false;
        }
        $total = 0.0;
        foreach ($allocations as $allocation) {
            $total += (float) ($allocation['allocated_amount'] ?? 0);
        }
        if ($total > (float) $expense['amount']) {
            return false;
        }
        $this->db->table('airport_operations_expense_allocations')->where('airport_operations_expense_id', $expenseId)->delete();
        $now = date('Y-m-d H:i:s');
        foreach ($allocations as $allocation) {
            $this->db->table('airport_operations_expense_allocations')->insert(array_merge($allocation, ['airport_operations_expense_id' => $expenseId, 'created_at' => $now, 'updated_at' => $now]));
        }
        $this->audit(null, 'airport_operations_expense_allocated', null, ['airport_operations_expense_id' => $expenseId, 'allocations' => $allocations]);

        return true;
    }

    public function createReceiptSplit(array $data): int|false
    {
        $total = round((float) $data['original_receipt_total'], 2);
        $reimbursement = round((float) ($data['reimbursement_portion_amount'] ?? 0), 2);
        $operations = round((float) ($data['operations_expense_portion_amount'] ?? 0), 2);
        $remaining = round((float) ($data['remaining_unclassified_amount'] ?? 0), 2);
        if (round($reimbursement + $operations + $remaining, 2) !== $total) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_receipt_splits')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_receipt_split_created', null, array_merge($data, ['id' => $id]));

        return $id;
    }

    private function audit(?int $incidentId, string $action, ?array $old, array $new): void
    {
        $this->db->table('airport_turo_access_audits')->insert([
            'airport_turo_access_override_incident_id' => $incidentId,
            'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
