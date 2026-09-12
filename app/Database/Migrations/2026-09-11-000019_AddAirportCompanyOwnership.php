<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

class AddAirportCompanyOwnership extends Migration
{
    public function up(): void
    {
        $plan = $this->preflight();

        $this->forge->addColumn('airport_operations_runs', [
            'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
        ]);
        $this->forge->addColumn('airport_turo_access_receipts', [
            'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
        ]);

        foreach ($plan['runs'] as $id => $companyId) {
            $this->db->table('airport_operations_runs')->where('id', $id)->update(['company_id' => $companyId]);
        }
        foreach ($plan['receipts'] as $id => $companyId) {
            $this->db->table('airport_turo_access_receipts')->where('id', $id)->update(['company_id' => $companyId]);
        }

        $this->forge->modifyColumn('airport_operations_runs', [
            'company_id' => ['name' => 'company_id', 'type' => 'INT', 'unsigned' => true, 'null' => false],
        ]);
        $this->forge->modifyColumn('airport_turo_access_receipts', [
            'company_id' => ['name' => 'company_id', 'type' => 'INT', 'unsigned' => true, 'null' => false],
        ]);

        $this->forge->addKey(['company_id', 'run_date', 'run_status'], false, false, 'airport_operations_runs_company_date_status_index');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('airport_operations_runs'));
        $this->forge->processIndexes('airport_operations_runs');

        $this->forge->addKey(['company_id', 'receipt_classification', 'document_date'], false, false, 'airport_turo_access_receipts_company_classification_date_index');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('airport_turo_access_receipts'));
        $this->forge->processIndexes('airport_turo_access_receipts');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('airport_turo_access_receipts', $this->dropForeignKeyName('airport_turo_access_receipts'));
        $this->forge->dropKey('airport_turo_access_receipts', 'airport_turo_access_receipts_company_classification_date_index', false);
        $this->forge->dropColumn('airport_turo_access_receipts', 'company_id');

        $this->forge->dropForeignKey('airport_operations_runs', $this->dropForeignKeyName('airport_operations_runs'));
        $this->forge->dropKey('airport_operations_runs', 'airport_operations_runs_company_date_status_index', false);
        $this->forge->dropColumn('airport_operations_runs', 'company_id');
    }

    /** @return array{runs: array<int, int>, receipts: array<int, int>} */
    public function preflight(): array
    {
        $issues = [];
        $runs = [];
        foreach ($this->db->table('airport_operations_runs')->select('id, chase_fleet_vehicle_id')->orderBy('id')->get()->getResultArray() as $run) {
            $id = (int) $run['id'];
            $candidates = [];
            $rowIssues = [];
            $this->addVehicleCompany($run['chase_fleet_vehicle_id'] ?? null, $candidates, $rowIssues, 'chase vehicle');
            foreach ($this->db->table('airport_operations_run_activities')->where('airport_operations_run_id', $id)->get()->getResultArray() as $activity) {
                $activityId = (int) $activity['id'];
                $this->addVehicleCompany($activity['fleet_vehicle_id'] ?? null, $candidates, $rowIssues, "activity {$activityId} vehicle");
                $this->addTripCompany($activity['turo_trip_normalized_id'] ?? null, $candidates, $rowIssues, "activity {$activityId} trip");
                $this->addWorkflowCompany($activity['airport_movement_workflow_id'] ?? null, $candidates, $rowIssues, "activity {$activityId} workflow");
            }
            $companyId = $this->singleCompany($candidates, $rowIssues, 'airport_operations_runs', $id, $issues);
            if ($companyId !== null) {
                $runs[$id] = $companyId;
            }
        }

        $receipts = [];
        foreach ($this->db->table('airport_turo_access_receipts')->orderBy('id')->get()->getResultArray() as $receipt) {
            $id = (int) $receipt['id'];
            $candidates = [];
            $rowIssues = [];
            $this->addVehicleCompany($receipt['fleet_vehicle_id'] ?? null, $candidates, $rowIssues, 'vehicle');
            $this->addTripCompany($receipt['turo_trip_normalized_id'] ?? null, $candidates, $rowIssues, 'trip');
            $this->addIncidentCompany($receipt['airport_turo_access_override_incident_id'] ?? null, $candidates, $rowIssues);
            $expenseId = (int) ($receipt['airport_operations_expense_id'] ?? 0);
            if ($expenseId > 0) {
                $expense = $this->db->table('airport_operations_expenses')->where('id', $expenseId)->get()->getRowArray();
                if ($expense === null) {
                    $rowIssues[] = "operations expense {$expenseId} is orphaned";
                } else {
                    $runId = (int) ($expense['airport_operations_run_id'] ?? 0);
                    if ($runId > 0) {
                        if (! isset($runs[$runId])) {
                            $rowIssues[] = "operations expense {$expenseId} references unowned run {$runId}";
                        } else {
                            $candidates[] = $runs[$runId];
                        }
                    }
                }
            }
            $companyId = $this->singleCompany($candidates, $rowIssues, 'airport_turo_access_receipts', $id, $issues);
            if ($companyId !== null) {
                $receipts[$id] = $companyId;
            }
        }

        if ($issues !== []) {
            throw new RuntimeException("Airport company ownership preflight failed:\n- " . implode("\n- ", $issues));
        }

        return ['runs' => $runs, 'receipts' => $receipts];
    }

    /** @param list<int> $candidates @param list<string> $rowIssues */
    private function addVehicleCompany(mixed $vehicleId, array &$candidates, array &$rowIssues, string $label): void
    {
        $vehicleId = (int) ($vehicleId ?? 0);
        if ($vehicleId < 1) {
            return;
        }
        $row = $this->db->table('fleet_vehicles vehicles')->select('vehicles.company_id')
            ->join('companies', 'companies.id = vehicles.company_id')
            ->where('vehicles.id', $vehicleId)->get()->getRowArray();
        if ($row === null) {
            $rowIssues[] = "{$label} {$vehicleId} is orphaned";
            return;
        }
        $candidates[] = (int) $row['company_id'];
    }

    /** @param list<int> $candidates @param list<string> $rowIssues */
    private function addTripCompany(mixed $tripId, array &$candidates, array &$rowIssues, string $label): void
    {
        $tripId = (int) ($tripId ?? 0);
        if ($tripId < 1) {
            return;
        }
        $row = $this->db->table('turo_trips_normalized trips')->select('vehicles.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('companies', 'companies.id = vehicles.company_id')
            ->where('trips.id', $tripId)->get()->getRowArray();
        if ($row === null) {
            $rowIssues[] = "{$label} {$tripId} is orphaned";
            return;
        }
        $candidates[] = (int) $row['company_id'];
    }

    /** @param list<int> $candidates @param list<string> $rowIssues */
    private function addWorkflowCompany(mixed $workflowId, array &$candidates, array &$rowIssues, string $label): void
    {
        $workflowId = (int) ($workflowId ?? 0);
        if ($workflowId < 1) {
            return;
        }
        $row = $this->db->table('airport_movement_workflows workflows')->select('vehicles.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = workflows.fleet_vehicle_id')
            ->join('companies', 'companies.id = vehicles.company_id')
            ->where('workflows.id', $workflowId)->get()->getRowArray();
        if ($row === null) {
            $rowIssues[] = "{$label} {$workflowId} is orphaned";
            return;
        }
        $candidates[] = (int) $row['company_id'];
    }

    /** @param list<int> $candidates @param list<string> $rowIssues */
    private function addIncidentCompany(mixed $incidentId, array &$candidates, array &$rowIssues): void
    {
        $incidentId = (int) ($incidentId ?? 0);
        if ($incidentId < 1) {
            return;
        }
        $incident = $this->db->table('airport_turo_access_override_incidents')->where('id', $incidentId)->get()->getRowArray();
        if ($incident === null) {
            $rowIssues[] = "incident {$incidentId} is orphaned";
            return;
        }
        $workflowId = (int) ($incident['airport_movement_workflow_id'] ?? 0);
        if ($workflowId < 1) {
            $rowIssues[] = "incident {$incidentId} has no workflow ownership";
        } else {
            $this->addWorkflowCompany($workflowId, $candidates, $rowIssues, "incident {$incidentId} workflow");
        }
        $this->addVehicleCompany($incident['fleet_vehicle_id'] ?? null, $candidates, $rowIssues, "incident {$incidentId} vehicle");
        $this->addTripCompany($incident['turo_trip_normalized_id'] ?? null, $candidates, $rowIssues, "incident {$incidentId} trip");
    }

    /** @param list<int> $candidates @param list<string> $rowIssues @param list<string> $issues */
    private function singleCompany(array $candidates, array $rowIssues, string $table, int $id, array &$issues): ?int
    {
        $companies = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));
        if ($rowIssues !== []) {
            $issues[] = "{$table} row {$id}: " . implode('; ', $rowIssues);
            return null;
        }
        if (count($companies) === 0) {
            $issues[] = "{$table} row {$id}: ownership cannot be proven from existing relationships";
            return null;
        }
        if (count($companies) !== 1) {
            $issues[] = "{$table} row {$id}: conflicting company IDs " . implode(', ', $companies);
            return null;
        }

        return $companies[0];
    }

    private function foreignKeyName(string $table): string
    {
        return $this->db->getPlatform() === 'SQLite3' ? '' : $table . '_company_id_foreign';
    }

    private function dropForeignKeyName(string $table): string
    {
        return $this->db->getPlatform() === 'SQLite3'
            ? $this->baseConnection()->prefixTable($table) . '_company_id_foreign'
            : $table . '_company_id_foreign';
    }

    private function baseConnection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('Airport ownership migration requires a CodeIgniter base database connection.');
        }

        return $this->db;
    }
}
