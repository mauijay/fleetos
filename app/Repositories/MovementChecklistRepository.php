<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class MovementChecklistRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function findByMovement(int $tripId, string $movementType, string $scheduledAt): ?array
    {
        $row = $this->db->table('trip_movement_checklists')
            ->where('turo_trip_normalized_id', $tripId)
            ->where('movement_type', $movementType)
            ->where('scheduled_at', $scheduledAt)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    public function createChecklist(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('trip_movement_checklists')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    public function createItem(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('trip_movement_checklist_items')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function checklist(int $id): ?array
    {
        $row = $this->db->table('trip_movement_checklists checklists')
            ->select('checklists.*, trips.guest_name, trips.starts_at, trips.ends_at, trips.turo_trip_id, fv.company_id, fv.fleet_code, fv.display_name')
            ->join('turo_trips_normalized trips', 'trips.id = checklists.turo_trip_normalized_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = checklists.fleet_vehicle_id', 'left')
            ->where('checklists.id', $id)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function checklistForCompany(int $companyId, int $id): ?array
    {
        $row = $this->db->table('trip_movement_checklists checklists')
            ->select('checklists.*, trips.guest_name, trips.starts_at, trips.ends_at, trips.turo_trip_id, fv.company_id, fv.fleet_code, fv.display_name')
            ->join('turo_trips_normalized trips', 'trips.id = checklists.turo_trip_normalized_id AND trips.fleet_vehicle_id = checklists.fleet_vehicle_id')
            ->join('fleet_vehicles fv', 'fv.id = checklists.fleet_vehicle_id')
            ->where(['checklists.id' => $id, 'fv.company_id' => $companyId])
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $checklistId): array
    {
        return $this->db->table('trip_movement_checklist_items')
            ->where('trip_movement_checklist_id', $checklistId)
            ->orderBy('sort_order', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function audits(int $checklistId): array
    {
        return $this->db->table('trip_movement_checklist_audits')
            ->where('trip_movement_checklist_id', $checklistId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function itemForCompany(int $companyId, int $itemId): ?array
    {
        return $this->db->table('trip_movement_checklist_items items')
            ->select('items.id, items.item_code, items.trip_movement_checklist_id')
            ->join('trip_movement_checklists checklists', 'checklists.id = items.trip_movement_checklist_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id')
            ->where('items.id', $itemId)
            ->where('vehicles.company_id', $companyId)
            ->get()->getRowArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function summariesForDate(string $start, string $end): array
    {
        return $this->db->table('trip_movement_checklists checklists')
            ->select('checklists.id, checklists.turo_trip_normalized_id, checklists.fleet_vehicle_id, checklists.movement_type, checklists.scheduled_at, checklists.readiness_status, checklists.vehicle_disposition, checklists.completed_at, vehicles.company_id')
            ->select('SUM(CASE WHEN items.is_required = 1 AND items.applicability = \'applicable\' THEN 1 ELSE 0 END) AS required_count', false)
            ->select('SUM(CASE WHEN items.is_required = 1 AND items.applicability = \'applicable\' AND items.completion_state = \'complete\' THEN 1 ELSE 0 END) AS required_complete_count', false)
            ->select('SUM(CASE WHEN items.is_critical = 1 AND items.applicability = \'applicable\' AND items.completion_state != \'complete\' THEN 1 ELSE 0 END) AS critical_open_count', false)
            ->join('trip_movement_checklist_items items', 'items.trip_movement_checklist_id = checklists.id', 'left')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id')
            ->where('checklists.scheduled_at >=', $start)
            ->where('checklists.scheduled_at <', $end)
            ->groupBy('checklists.id, checklists.turo_trip_normalized_id, checklists.fleet_vehicle_id, checklists.movement_type, checklists.scheduled_at, checklists.readiness_status, checklists.vehicle_disposition, checklists.completed_at, vehicles.company_id')
            ->get()
            ->getResultArray();
    }

    public function updateItem(int $itemId, array $data, ?int $actorUserId = null): bool
    {
        $old = $this->db->table('trip_movement_checklist_items')->where('id', $itemId)->get()->getRowArray();
        if ($old === null) {
            return false;
        }

        $this->db->table('trip_movement_checklist_items')->where('id', $itemId)->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        $this->audit((int) $old['trip_movement_checklist_id'], $itemId, 'item_updated', $old, array_merge($old, $data), $actorUserId);

        return $this->db->affectedRows() > 0;
    }

    public function updateItemForCompany(int $companyId, int $itemId, array $data, ?int $actorUserId = null): bool
    {
        $old = $this->db->table('trip_movement_checklist_items items')
            ->select('items.*')
            ->join('trip_movement_checklists checklists', 'checklists.id = items.trip_movement_checklist_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id')
            ->where(['items.id' => $itemId, 'vehicles.company_id' => $companyId])
            ->get()
            ->getRowArray();
        if ($old === null) {
            return false;
        }

        $companyChecklistIds = $this->db->table('trip_movement_checklists checklists')
            ->select('checklists.id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId);
        $this->db->table('trip_movement_checklist_items')
            ->where('id', $itemId)
            ->whereIn('trip_movement_checklist_id', $companyChecklistIds)
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        if ($this->db->affectedRows() < 1) {
            return false;
        }
        $this->audit((int) $old['trip_movement_checklist_id'], $itemId, 'item_updated', $old, array_merge($old, $data), $actorUserId);

        return true;
    }

    public function updateChecklist(int $checklistId, array $data, ?int $actorUserId = null): bool
    {
        $old = $this->db->table('trip_movement_checklists')->where('id', $checklistId)->get()->getRowArray();
        if ($old === null) {
            return false;
        }

        $this->db->table('trip_movement_checklists')->where('id', $checklistId)->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
        $this->audit($checklistId, null, 'checklist_updated', $old, array_merge($old, $data), $actorUserId);

        return $this->db->affectedRows() > 0;
    }

    /** @param list<string> $itemCodes */
    public function setItemCompletionByCodes(int $checklistId, array $itemCodes, bool $complete, int $actorUserId): bool
    {
        $this->db->transBegin();
        try {
            $items = $this->db->table('trip_movement_checklist_items')
                ->where('trip_movement_checklist_id', $checklistId)
                ->whereIn('item_code', $itemCodes)
                ->get()
                ->getResultArray();
            $itemsByCode = array_column($items, null, 'item_code');
            if (count($itemsByCode) !== count($itemCodes)) {
                $this->db->transRollback();

                return false;
            }

            $changed = false;
            $now = date('Y-m-d H:i:s');
            foreach ($itemCodes as $itemCode) {
                $old = $itemsByCode[$itemCode];
                $isComplete = ($old['completion_state'] ?? 'open') === 'complete';
                if ($isComplete === $complete) {
                    continue;
                }
                $data = $complete
                    ? ['completion_state' => 'complete', 'completion_source' => 'manual', 'completed_at' => $now]
                    : ['completion_state' => 'open', 'completion_source' => null, 'completed_at' => null];
                $this->db->table('trip_movement_checklist_items')->where('id', $old['id'])->update(array_merge($data, ['updated_at' => $now]));
                $this->audit($checklistId, (int) $old['id'], 'item_updated', $old, array_merge($old, $data), $actorUserId);
                $changed = true;
            }
            if (! $changed) {
                $this->db->transRollback();

                return false;
            }
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Checklist item transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function completeOrCreateOptionalItem(int $checklistId, string $itemCode, string $label, int $sortOrder, int $actorUserId): bool
    {
        $this->db->transBegin();
        try {
            $old = $this->db->table('trip_movement_checklist_items')
                ->where('trip_movement_checklist_id', $checklistId)
                ->where('item_code', $itemCode)
                ->get()
                ->getRowArray();
            if (($old['completion_state'] ?? null) === 'complete') {
                $this->db->transRollback();

                return false;
            }

            $now = date('Y-m-d H:i:s');
            if ($old === null) {
                $new = [
                    'trip_movement_checklist_id' => $checklistId,
                    'item_code' => $itemCode,
                    'label' => $label,
                    'is_required' => false,
                    'is_critical' => false,
                    'sort_order' => $sortOrder,
                    'applicability' => 'applicable',
                    'completion_state' => 'complete',
                    'completion_source' => 'manual',
                    'completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $this->db->table('trip_movement_checklist_items')->insert($new);
                $itemId = (int) $this->db->insertID();
                $this->audit($checklistId, $itemId, 'item_created', [], $new, $actorUserId);
            } else {
                $data = ['completion_state' => 'complete', 'completion_source' => 'manual', 'completed_at' => $now];
                $this->db->table('trip_movement_checklist_items')->where('id', $old['id'])->update(array_merge($data, ['updated_at' => $now]));
                $this->audit($checklistId, (int) $old['id'], 'item_updated', $old, array_merge($old, $data), $actorUserId);
            }
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Checklist item transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function vehicleHasCapability(int $vehicleId, string $capabilityCode): bool
    {
        return $this->db->table('vehicle_operational_capabilities')
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('capability_code', $capabilityCode)
            ->where('is_applicable', true)
            ->countAllResults() > 0;
    }

    private function audit(int $checklistId, ?int $itemId, string $action, array $old, array $new, ?int $actorUserId): void
    {
        $this->db->table('trip_movement_checklist_audits')->insert([
            'trip_movement_checklist_id' => $checklistId,
            'trip_movement_checklist_item_id' => $itemId,
            'action' => $action,
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
