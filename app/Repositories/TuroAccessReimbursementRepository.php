<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

class TuroAccessReimbursementRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function workflow(int $companyId, int $workflowId): ?array
    {
        $row = $this->workflowBuilder($companyId)->where('workflows.id', $workflowId)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function incident(int $companyId, int $id): ?array
    {
        $row = $this->incidentBuilder($companyId)->where('incidents.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function possibleDuplicate(int $companyId, int $workflowId, ?string $ticketNumber): ?array
    {
        $builder = $this->incidentBuilder($companyId)->where('incidents.airport_movement_workflow_id', $workflowId);
        if ($ticketNumber !== null && trim($ticketNumber) !== '') {
            $builder->where('incidents.ticket_number', trim($ticketNumber));
        }
        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createIncident(int $companyId, array $data): int
    {
        $workflow = $this->workflow($companyId, (int) ($data['airport_movement_workflow_id'] ?? 0));
        if ($workflow === null
            || (int) ($data['fleet_vehicle_id'] ?? 0) !== (int) $workflow['fleet_vehicle_id']
            || (int) ($data['turo_trip_normalized_id'] ?? 0) !== (int) $workflow['turo_trip_normalized_id']) {
            throw new InvalidArgumentException('Airport incident relationships are invalid.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_turo_access_override_incidents')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit($id, 'incident_created', null, $data);

        return $id;
    }

    public function updateIncident(int $companyId, int $id, array $data, string $action): bool
    {
        $old = $this->incident($companyId, $id);
        if ($old === null) {
            return false;
        }

        $this->db->table('airport_turo_access_override_incidents')
            ->where('id', $id)
            ->whereIn('airport_movement_workflow_id', $this->companyWorkflowIds($companyId))
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        if ($this->db->affectedRows() < 1) {
            return false;
        }
        $this->audit($id, $action, $old, array_merge($old, $data));

        return true;
    }

    public function createReceipt(int $companyId, array $data): int
    {
        $this->validateReceiptRelationships($companyId, $data);
        $now = date('Y-m-d H:i:s');
        $data['company_id'] = $companyId;
        $this->db->table('airport_turo_access_receipts')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(isset($data['airport_turo_access_override_incident_id']) ? (int) $data['airport_turo_access_override_incident_id'] : null, 'receipt_attached', null, $data);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function receipt(int $companyId, int $id): ?array
    {
        $row = $this->receiptBuilder($companyId)->where('receipts.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array{id:string, fleet_number:?string, fleet_code:string, display_name:string}> */
    public function fleetVehicles(int $companyId): array
    {
        return $this->db->table('fleet_vehicles')
            ->select('id, fleet_number, fleet_code, display_name')
            ->where(['company_id' => $companyId, 'deleted_at' => null])
            ->orderBy('sort_order', 'ASC')
            ->orderBy('fleet_code', 'ASC')
            ->get()->getResultArray();
    }

    public function updateReceipt(int $companyId, int $id, array $data, string $action): bool
    {
        $old = $this->receipt($companyId, $id);
        if ($old === null) {
            return false;
        }

        $this->db->table('airport_turo_access_receipts')
            ->where(['id' => $id, 'company_id' => $companyId])
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        if ($this->db->affectedRows() < 1) {
            return false;
        }
        $this->audit($old['airport_turo_access_override_incident_id'] === null ? null : (int) $old['airport_turo_access_override_incident_id'], $action, $old, array_merge($old, $data));

        return true;
    }

    public function recordAirportException(int $companyId, int $workflowId, string $note): bool
    {
        $workflow = $this->workflow($companyId, $workflowId);
        if ($workflow === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_movement_exceptions')->insert([
            'airport_movement_workflow_id' => $workflowId,
            'exception_type' => 'airport_turo_access_overridden',
            'severity' => 'today',
            'note' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->table('airport_movement_workflows')
            ->where('id', $workflowId)
            ->whereIn('fleet_vehicle_id', $this->companyVehicleIds($companyId))
            ->update(['workflow_status' => 'exception', 'updated_at' => $now]);
        if ($this->db->affectedRows() < 1) {
            return false;
        }
        $this->audit(null, 'airport_exception_recorded', $workflow, ['airport_movement_workflow_id' => $workflowId, 'exception_type' => 'airport_turo_access_overridden']);

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function receiptsForIncident(int $companyId, int $incidentId): array
    {
        if ($this->incident($companyId, $incidentId) === null) {
            return [];
        }

        return $this->db->table('airport_turo_access_receipts')
            ->where(['company_id' => $companyId, 'airport_turo_access_override_incident_id' => $incidentId])
            ->orderBy('created_at', 'ASC')->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function inbox(int $companyId): array
    {
        return $this->incidentBuilder($companyId)
            ->whereNotIn('incidents.claim_status', ['reimbursed', 'denied', 'closed_without_filing'])
            ->orderBy('incidents.incident_at', 'ASC')->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function unmatchedReceipts(int $companyId): array
    {
        return $this->db->table('airport_turo_access_receipts')
            ->where(['company_id' => $companyId, 'airport_turo_access_override_incident_id' => null])
            ->orderBy('document_date', 'ASC')->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateAirportTrips(int $companyId, ?int $vehicleId, string $date): array
    {
        if ($vehicleId !== null && $vehicleId > 0 && ! $this->vehicleBelongsToCompany($companyId, $vehicleId)) {
            return [];
        }

        $start = (new \DateTimeImmutable($date))->modify('-2 days')->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($date))->modify('+3 days')->format('Y-m-d H:i:s');
        $builder = $this->candidateWorkflowBuilder($companyId)
            ->where('scheduled_at >=', $start)
            ->where('scheduled_at <', $end)
            ->orderBy('scheduled_at', 'ASC');
        if ($vehicleId !== null && $vehicleId > 0) {
            $builder->orderBy('CASE WHEN workflows.fleet_vehicle_id = ' . $this->db->escape($vehicleId) . ' THEN 0 ELSE 1 END', '', false);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function searchAirportTrips(int $companyId, string $query): array
    {
        return $this->candidateWorkflowBuilder($companyId)
            ->groupStart()->like('fleet_code', $query)->orLike('guest_name', $query)->orLike('turo_trip_id', $query)->groupEnd()
            ->orderBy('scheduled_at', 'DESC')->limit(20)->get()->getResultArray();
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Airport reimbursement transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function existingIncidentForWorkflow(int $companyId, int $workflowId, ?string $ticketNumber = null): ?array
    {
        $builder = $this->incidentBuilder($companyId)
            ->where('incidents.airport_movement_workflow_id', $workflowId)
            ->whereNotIn('incidents.claim_status', ['denied', 'closed_without_filing']);
        if ($ticketNumber !== null && trim($ticketNumber) !== '') {
            $builder->where('incidents.ticket_number', trim($ticketNumber));
        }
        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function linkReceiptToIncident(int $companyId, int $receiptId, int $incidentId): bool
    {
        $receipt = $this->receipt($companyId, $receiptId);
        $incident = $this->incident($companyId, $incidentId);
        if ($receipt === null || $incident === null) {
            return false;
        }

        return $this->updateReceipt($companyId, $receiptId, [
            'airport_turo_access_override_incident_id' => $incidentId,
            'airport_operations_expense_id' => null,
            'receipt_classification' => 'trip_reimbursement',
            'turo_trip_normalized_id' => $incident['turo_trip_normalized_id'],
            'fleet_vehicle_id' => $incident['fleet_vehicle_id'],
        ], 'receipt_linked_to_incident');
    }

    public function createOperationsRun(int $companyId, array $data): int
    {
        $vehicleId = isset($data['chase_fleet_vehicle_id']) ? (int) $data['chase_fleet_vehicle_id'] : 0;
        if ($vehicleId > 0 && ! $this->vehicleBelongsToCompany($companyId, $vehicleId)) {
            throw new InvalidArgumentException('Airport run vehicle is invalid.');
        }

        $data['company_id'] = $companyId;
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_runs')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_run_created', null, array_merge($data, ['id' => $id]));

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function operationsRun(int $companyId, int $id): ?array
    {
        $row = $this->db->table('airport_operations_runs')->where(['company_id' => $companyId, 'id' => $id])->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createRunActivity(int $companyId, int $runId, array $data): int
    {
        if ($this->operationsRun($companyId, $runId) === null) {
            throw new InvalidArgumentException('Airport operations run is invalid.');
        }
        $this->validateOptionalCompanyResource($companyId, $data, 'fleet_vehicle_id', fn (int $id): bool => $this->vehicleBelongsToCompany($companyId, $id));
        $this->validateOptionalCompanyResource($companyId, $data, 'turo_trip_normalized_id', fn (int $id): bool => $this->tripBelongsToCompany($companyId, $id));
        $this->validateOptionalCompanyResource($companyId, $data, 'airport_movement_workflow_id', fn (int $id): bool => $this->workflow($companyId, $id) !== null);

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_run_activities')->insert(array_merge($data, ['airport_operations_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_run_activity_created', null, array_merge($data, ['airport_operations_run_id' => $runId, 'id' => $id]));

        return $id;
    }

    public function createOperationsExpense(int $companyId, array $data): int
    {
        $receiptId = (int) ($data['airport_turo_access_receipt_id'] ?? 0);
        $runId = isset($data['airport_operations_run_id']) ? (int) $data['airport_operations_run_id'] : 0;
        if ($this->receipt($companyId, $receiptId) === null || ($runId > 0 && $this->operationsRun($companyId, $runId) === null)) {
            throw new InvalidArgumentException('Airport expense relationships are invalid.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_operations_expenses')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        $id = (int) $this->db->insertID();
        $this->audit(null, 'airport_operations_expense_created', null, array_merge($data, ['id' => $id]));

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function operationsExpense(int $companyId, int $id): ?array
    {
        $row = $this->expenseBuilder($companyId)->where('expenses.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function classifyReceipt(int $companyId, int $receiptId, string $classification, ?int $expenseId = null, ?string $note = null): bool
    {
        if ($expenseId !== null && $this->operationsExpense($companyId, $expenseId) === null) {
            return false;
        }

        return $this->updateReceipt($companyId, $receiptId, [
            'receipt_classification' => $classification,
            'airport_operations_expense_id' => $expenseId,
            'airport_turo_access_override_incident_id' => null,
            'classification_note' => $note,
        ], 'receipt_classified');
    }

    public function linkReceiptToOperationsExpense(int $companyId, int $receiptId, int $expenseId, ?int $runId, string $note): bool
    {
        if ($this->receipt($companyId, $receiptId) === null
            || $this->operationsExpense($companyId, $expenseId) === null
            || ($runId !== null && $this->operationsRun($companyId, $runId) === null)) {
            return false;
        }

        return $this->updateReceipt($companyId, $receiptId, [
            'receipt_classification' => 'airport_operations_expense',
            'airport_operations_expense_id' => $expenseId,
            'airport_turo_access_override_incident_id' => null,
            'classification_note' => $note,
        ], 'receipt_linked_to_operations_expense');
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateOperationsRuns(int $companyId, ?string $date, ?int $vehicleId = null): array
    {
        if ($vehicleId !== null && $vehicleId > 0 && ! $this->vehicleBelongsToCompany($companyId, $vehicleId)) {
            return [];
        }

        $builder = $this->db->table('airport_operations_runs runs')
            ->select('DISTINCT runs.*', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable('airport_operations_expenses') . ' expenses WHERE expenses.airport_operations_run_id = runs.id) AS expense_count', false)
            ->where('runs.company_id', $companyId)
            ->orderBy('runs.run_date', 'DESC')->limit(20);
        if ($date !== null && $date !== '') {
            $start = (new \DateTimeImmutable($date))->modify('-2 days')->format('Y-m-d');
            $end = (new \DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d');
            $builder->where('runs.run_date >=', $start)->where('runs.run_date <=', $end);
        }
        if ($vehicleId !== null && $vehicleId > 0) {
            $builder->join('airport_operations_run_activities activities', 'activities.airport_operations_run_id = runs.id', 'left')
                ->groupStart()->where('activities.fleet_vehicle_id', $vehicleId)->orWhere('runs.chase_fleet_vehicle_id', $vehicleId)->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function operationsAttentionSummary(int $companyId): array
    {
        $needsClassification = (int) $this->db->table('airport_turo_access_receipts')->where(['company_id' => $companyId, 'receipt_classification' => 'unresolved'])->countAllResults();
        $missingRun = (int) $this->expenseBuilder($companyId)->where('expenses.airport_operations_run_id', null)->countAllResults();
        $unallocated = (int) $this->expenseBuilder($companyId)->join('airport_operations_expense_allocations allocations', 'allocations.airport_operations_expense_id = expenses.id', 'left')->where('allocations.id', null)->countAllResults();
        $missingImages = (int) $this->expenseBuilder($companyId)->where('expenses.file_id', null)->countAllResults();
        $monthTotal = (float) ($this->expenseBuilder($companyId)->selectSum('expenses.amount', 'amount')->where('expenses.expense_date >=', date('Y-m-01'))->get()->getRowArray()['amount'] ?? 0);

        return ['needs_classification' => $needsClassification, 'expenses_missing_run' => $missingRun, 'runs_with_unallocated_expenses' => $unallocated, 'operations_receipts_missing_images' => $missingImages, 'month_operations_total' => $monthTotal];
    }

    /** @param array<int, array<string, mixed>> $allocations */
    public function replaceExpenseAllocations(int $companyId, int $expenseId, array $allocations): bool
    {
        $expense = $this->operationsExpense($companyId, $expenseId);
        if ($expense === null) {
            return false;
        }
        $total = 0.0;
        foreach ($allocations as $allocation) {
            $total += (float) ($allocation['allocated_amount'] ?? 0);
            $vehicleId = isset($allocation['fleet_vehicle_id']) ? (int) $allocation['fleet_vehicle_id'] : 0;
            if ($vehicleId > 0 && ! $this->vehicleBelongsToCompany($companyId, $vehicleId)) {
                return false;
            }
        }
        if ($total > (float) $expense['amount']) {
            return false;
        }

        return (bool) $this->transaction(function () use ($companyId, $expenseId, $allocations): bool {
            if ($this->operationsExpense($companyId, $expenseId) === null) {
                return false;
            }
            $this->db->table('airport_operations_expense_allocations')->where('airport_operations_expense_id', $expenseId)->delete();
            $now = date('Y-m-d H:i:s');
            foreach ($allocations as $allocation) {
                $this->db->table('airport_operations_expense_allocations')->insert(array_merge($allocation, ['airport_operations_expense_id' => $expenseId, 'created_at' => $now, 'updated_at' => $now]));
            }
            $this->audit(null, 'airport_operations_expense_allocated', null, ['airport_operations_expense_id' => $expenseId, 'allocations' => $allocations]);

            return true;
        });
    }

    public function createReceiptSplit(int $companyId, array $data): int|false
    {
        if ($this->receipt($companyId, (int) ($data['airport_turo_access_receipt_id'] ?? 0)) === null) {
            return false;
        }
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

    private function workflowBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, trips.guest_name, fv.company_id, fv.fleet_code, fv.display_name')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id AND trips.fleet_vehicle_id = fv.id')
            ->where('fv.company_id', $companyId);
    }

    private function incidentBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_turo_access_override_incidents incidents')
            ->select('incidents.*, trips.guest_name, trips.turo_trip_id, fv.company_id, fv.fleet_code, fv.display_name')
            ->join('airport_movement_workflows workflows', 'workflows.id = incidents.airport_movement_workflow_id')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id AND fv.id = incidents.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id AND trips.id = incidents.turo_trip_normalized_id')
            ->where('fv.company_id', $companyId);
    }

    private function receiptBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_turo_access_receipts receipts')
            ->select('receipts.*, files.storage_disk AS file_storage_disk, files.path AS file_path, files.mime_type AS file_mime_type, files.size_bytes AS file_size_bytes, files.checksum AS file_checksum, files.original_filename AS file_original_filename, files.deleted_at AS file_deleted_at')
            ->join('files', 'files.id = receipts.file_id', 'left')
            ->where('receipts.company_id', $companyId);
    }

    private function candidateWorkflowBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, trips.guest_name, trips.turo_trip_id, fv.company_id, fv.fleet_code, fv.display_name', false)
            ->select('incidents.id AS existing_incident_id, incidents.claim_status AS existing_claim_status, incidents.ticket_number AS existing_ticket_number, incidents.parking_amount_paid AS existing_parking_amount_paid', false)
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id AND trips.fleet_vehicle_id = fv.id')
            ->join('airport_turo_access_override_incidents incidents', 'incidents.airport_movement_workflow_id = workflows.id', 'left')
            ->where('company_id', $companyId);
    }

    private function expenseBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_operations_expenses expenses')
            ->join('airport_turo_access_receipts receipts', 'receipts.id = expenses.airport_turo_access_receipt_id')
            ->join('airport_operations_runs runs', 'runs.id = expenses.airport_operations_run_id', 'left')
            ->where('receipts.company_id', $companyId)
            ->groupStart()->where('runs.id', null)->orWhere('runs.company_id', $companyId)->groupEnd();
    }

    private function companyVehicleIds(int $companyId): BaseBuilder
    {
        return $this->db->table('fleet_vehicles')->select('id')->where('company_id', $companyId);
    }

    private function companyWorkflowIds(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_movement_workflows workflows')->select('workflows.id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = workflows.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId);
    }

    private function vehicleBelongsToCompany(int $companyId, int $vehicleId): bool
    {
        return $vehicleId > 0 && $this->db->table('fleet_vehicles')->where(['id' => $vehicleId, 'company_id' => $companyId])->countAllResults() === 1;
    }

    private function tripBelongsToCompany(int $companyId, int $tripId): bool
    {
        return $tripId > 0 && $this->db->table('turo_trips_normalized trips')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where(['trips.id' => $tripId, 'vehicles.company_id' => $companyId])->countAllResults() === 1;
    }

    public function validateReceiptRelationships(int $companyId, array $data): void
    {
        $this->validateOptionalCompanyResource($companyId, $data, 'airport_turo_access_override_incident_id', fn (int $id): bool => $this->incident($companyId, $id) !== null);
        $this->validateOptionalCompanyResource($companyId, $data, 'fleet_vehicle_id', fn (int $id): bool => $this->vehicleBelongsToCompany($companyId, $id));
        $this->validateOptionalCompanyResource($companyId, $data, 'turo_trip_normalized_id', fn (int $id): bool => $this->tripBelongsToCompany($companyId, $id));
    }

    private function validateOptionalCompanyResource(int $companyId, array $data, string $key, callable $validator): void
    {
        $id = isset($data[$key]) ? (int) $data[$key] : 0;
        if ($id > 0 && ! $validator($id)) {
            throw new InvalidArgumentException('Airport relationship is invalid.');
        }
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
