<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

class AirportMovementRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function airportDeliveriesBetween(int $companyId, string $start, string $end): array
    {
        return $this->db->table('airport_deliveries deliveries')
            ->select('deliveries.*, airports.code AS airport_code, airports.name AS airport_name')
            ->select('trips.starts_at AS trip_starts_at, trips.ends_at AS trip_ends_at, trips.guest_name, trips.turo_trip_id')
            ->select('fv.company_id, fv.fleet_code, fv.display_name')
            ->join('airports', 'airports.id = deliveries.airport_id')
            ->join('fleet_vehicles fv', 'fv.id = deliveries.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = deliveries.turo_trip_normalized_id AND trips.fleet_vehicle_id = fv.id')
            ->where('fv.company_id', $companyId)
            ->where('deliveries.deleted_at', null)
            ->where('deliveries.scheduled_at >=', $start)
            ->where('deliveries.scheduled_at <', $end)
            ->orderBy('deliveries.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function airportDeliveriesForTrip(int $companyId, int $tripId): array
    {
        return $this->db->table('airport_deliveries deliveries')
            ->select('deliveries.*, airports.code AS airport_code, airports.name AS airport_name')
            ->select('trips.starts_at AS trip_starts_at, trips.ends_at AS trip_ends_at, trips.guest_name, trips.turo_trip_id')
            ->select('fv.company_id, fv.fleet_code, fv.display_name')
            ->join('airports', 'airports.id = deliveries.airport_id')
            ->join('fleet_vehicles fv', 'fv.id = deliveries.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = deliveries.turo_trip_normalized_id AND trips.fleet_vehicle_id = fv.id')
            ->where('fv.company_id', $companyId)
            ->where('deliveries.turo_trip_normalized_id', $tripId)
            ->where('deliveries.deleted_at', null)
            ->orderBy('deliveries.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function findWorkflow(int $companyId, int $tripId, string $movementType, string $scheduledAt): ?array
    {
        $row = $this->workflowBuilder($companyId)
            ->where('workflows.turo_trip_normalized_id', $tripId)
            ->where('workflows.movement_type', $movementType)
            ->where('workflows.scheduled_at', $scheduledAt)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function workflow(int $companyId, int $id): ?array
    {
        $row = $this->workflowBuilder($companyId)->where('workflows.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createWorkflow(int $companyId, array $data): int
    {
        if (! $this->vehicleBelongsToCompany($companyId, (int) ($data['fleet_vehicle_id'] ?? 0))
            || ! $this->tripBelongsToCompany($companyId, (int) ($data['turo_trip_normalized_id'] ?? 0))
            || ! $this->checklistBelongsToCompany($companyId, (int) ($data['trip_movement_checklist_id'] ?? 0))) {
            throw new InvalidArgumentException('Airport workflow relationships are invalid.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_movement_workflows')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    public function updateWorkflow(int $companyId, int $id, array $data, string $action, ?int $actorUserId = null): bool
    {
        $old = $this->workflow($companyId, $id);
        if ($old === null) {
            return false;
        }

        $this->db->table('airport_movement_workflows')
            ->where('id', $id)
            ->whereIn('fleet_vehicle_id', $this->companyVehicleIds($companyId))
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        if ($this->db->affectedRows() < 1) {
            return false;
        }
        $this->audit($id, $action, $old, array_merge($old, $data), $actorUserId);

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function workflowsBetween(int $companyId, string $start, string $end): array
    {
        return $this->workflowBuilder($companyId)
            ->where('workflows.scheduled_at >=', $start)
            ->where('workflows.scheduled_at <', $end)
            ->orderBy('workflows.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function createException(int $companyId, int $workflowId, string $type, string $severity, string $note): int
    {
        if ($this->workflow($companyId, $workflowId) === null) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_movement_exceptions')->insert([
            'airport_movement_workflow_id' => $workflowId,
            'exception_type' => $type,
            'severity' => $severity,
            'note' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<int, array<string, mixed>> */
    public function openExceptions(int $companyId, int $workflowId): array
    {
        return $this->db->table('airport_movement_exceptions exceptions')
            ->join('airport_movement_workflows workflows', 'workflows.id = exceptions.airport_movement_workflow_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = workflows.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('exceptions.airport_movement_workflow_id', $workflowId)
            ->where('exceptions.resolved_at', null)
            ->orderBy('exceptions.created_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Airport workflow transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function workflowBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, airports.code AS airport_code, airports.name AS airport_name, fv.company_id, fv.fleet_code, fv.display_name, trips.guest_name')
            ->join('airports', 'airports.id = workflows.airport_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id AND trips.fleet_vehicle_id = fv.id')
            ->where('fv.company_id', $companyId);
    }

    private function companyVehicleIds(int $companyId): BaseBuilder
    {
        return $this->db->table('fleet_vehicles')->select('id')->where('company_id', $companyId);
    }

    private function vehicleBelongsToCompany(int $companyId, int $vehicleId): bool
    {
        return $vehicleId > 0 && $this->db->table('fleet_vehicles')->where(['id' => $vehicleId, 'company_id' => $companyId])->countAllResults() === 1;
    }

    private function tripBelongsToCompany(int $companyId, int $tripId): bool
    {
        return $tripId > 0 && $this->db->table('turo_trips_normalized trips')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where(['trips.id' => $tripId, 'vehicles.company_id' => $companyId])
            ->countAllResults() === 1;
    }

    private function checklistBelongsToCompany(int $companyId, int $checklistId): bool
    {
        return $checklistId > 0 && $this->db->table('trip_movement_checklists checklists')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id')
            ->where(['checklists.id' => $checklistId, 'vehicles.company_id' => $companyId])
            ->countAllResults() === 1;
    }

    private function audit(int $workflowId, string $action, array $old, array $new, ?int $actorUserId): void
    {
        $this->db->table('airport_movement_audits')->insert([
            'airport_movement_workflow_id' => $workflowId,
            'action' => $action,
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
