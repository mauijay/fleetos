<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AirportMovementRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function airportDeliveriesBetween(string $start, string $end): array
    {
        return $this->db->table('airport_deliveries deliveries')
            ->select('deliveries.*, airports.code AS airport_code, airports.name AS airport_name')
            ->select('trips.starts_at AS trip_starts_at, trips.ends_at AS trip_ends_at, trips.guest_name, trips.turo_trip_id')
            ->select('fv.fleet_code, fv.display_name')
            ->join('airports', 'airports.id = deliveries.airport_id')
            ->join('turo_trips_normalized trips', 'trips.id = deliveries.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = deliveries.fleet_vehicle_id', 'left')
            ->where('deliveries.deleted_at', null)
            ->where('deliveries.scheduled_at >=', $start)
            ->where('deliveries.scheduled_at <', $end)
            ->orderBy('deliveries.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function findWorkflow(int $tripId, string $movementType, string $scheduledAt): ?array
    {
        $row = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, airports.code AS airport_code, airports.name AS airport_name, fv.fleet_code, fv.display_name, trips.guest_name')
            ->join('airports', 'airports.id = workflows.airport_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->where('workflows.turo_trip_normalized_id', $tripId)
            ->where('workflows.movement_type', $movementType)
            ->where('workflows.scheduled_at', $scheduledAt)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function workflow(int $id): ?array
    {
        $row = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, airports.code AS airport_code, airports.name AS airport_name, fv.fleet_code, fv.display_name, trips.guest_name')
            ->join('airports', 'airports.id = workflows.airport_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->where('workflows.id', $id)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    public function createWorkflow(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('airport_movement_workflows')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    public function updateWorkflow(int $id, array $data, string $action, ?int $actorUserId = null): bool
    {
        $old = $this->workflow($id);
        if ($old === null) {
            return false;
        }

        $this->db->table('airport_movement_workflows')->where('id', $id)->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        $this->audit($id, $action, $old, array_merge($old, $data), $actorUserId);

        return $this->db->affectedRows() > 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function workflowsBetween(string $start, string $end): array
    {
        return $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*, airports.code AS airport_code, airports.name AS airport_name, fv.fleet_code, fv.display_name, trips.guest_name')
            ->join('airports', 'airports.id = workflows.airport_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = workflows.fleet_vehicle_id', 'left')
            ->join('turo_trips_normalized trips', 'trips.id = workflows.turo_trip_normalized_id', 'left')
            ->where('workflows.scheduled_at >=', $start)
            ->where('workflows.scheduled_at <', $end)
            ->orderBy('workflows.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function createException(int $workflowId, string $type, string $severity, string $note): int
    {
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
    public function openExceptions(int $workflowId): array
    {
        return $this->db->table('airport_movement_exceptions')
            ->where('airport_movement_workflow_id', $workflowId)
            ->where('resolved_at', null)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();
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
