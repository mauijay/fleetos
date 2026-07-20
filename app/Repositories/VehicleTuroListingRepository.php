<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleTuroListingRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function fleetVehicles(): array
    {
        return $this->db->table('fleet_vehicles fv')
            ->select('fv.id, fv.fleet_code, fv.display_name, fv.vin, fv.license_plate, fv.deleted_at')
            ->select('vsp.model_year, vm.name AS model_name, vma.name AS make_name, vtl.name AS trim_name')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id', 'left')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id', 'left')
            ->join('vehicle_makes vma', 'vma.id = vm.vehicle_make_id', 'left')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id', 'left')
            ->where('fv.deleted_at', null)
            ->orderBy('fv.sort_order', 'ASC')
            ->orderBy('fv.fleet_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function fleetVehicle(int $fleetVehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles fv')
            ->select('fv.id, fv.fleet_code, fv.display_name, fv.vin, fv.license_plate, fv.deleted_at')
            ->where('fv.id', $fleetVehicleId)
            ->where('fv.deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findActiveByTuroVehicleId(string $turoVehicleId): ?array
    {
        $row = $this->db->table('vehicle_turo_listings listings')
            ->select('listings.*')
            ->select('fv.fleet_code, fv.display_name')
            ->join('fleet_vehicles fv', 'fv.id = listings.fleet_vehicle_id', 'left')
            ->where('listings.turo_vehicle_id', trim($turoVehicleId))
            ->where('listings.is_active', true)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function activeListingsForFleetVehicle(int $fleetVehicleId): array
    {
        return $this->db->table('vehicle_turo_listings')
            ->where('fleet_vehicle_id', $fleetVehicleId)
            ->where('is_active', true)
            ->orderBy('updated_at', 'DESC')
            ->get()
            ->getResultArray();
    }

    public function createMapping(string $turoVehicleId, int $fleetVehicleId, ?string $note = null, ?int $actorUserId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('vehicle_turo_listings')->insert([
            'fleet_vehicle_id' => $fleetVehicleId,
            'turo_vehicle_id' => trim($turoVehicleId),
            'source_system' => 'turo',
            'is_active' => true,
            'listed_at' => $now,
            'mapping_note' => $note,
            'mapped_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->insertID();
        $this->recordAudit($id, 'created', trim($turoVehicleId), null, $fleetVehicleId, $note, $actorUserId);

        return $id;
    }

    public function remap(string $turoVehicleId, int $fleetVehicleId, ?string $note = null, ?int $actorUserId = null): bool
    {
        $existing = $this->findActiveByTuroVehicleId($turoVehicleId);

        if ($existing === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('vehicle_turo_listings')
            ->where('id', (int) $existing['id'])
            ->update([
                'fleet_vehicle_id' => $fleetVehicleId,
                'mapping_note' => $note,
                'mapped_by' => $actorUserId,
                'updated_at' => $now,
            ]);

        $this->recordAudit((int) $existing['id'], 'remapped', trim($turoVehicleId), (int) $existing['fleet_vehicle_id'], $fleetVehicleId, $note, $actorUserId);

        return $this->db->affectedRows() > 0;
    }

    public function deactivateFleetConflicts(int $fleetVehicleId, string $keepTuroVehicleId, ?string $note = null, ?int $actorUserId = null): int
    {
        $conflicts = $this->activeListingsForFleetVehicle($fleetVehicleId);
        $count = 0;

        foreach ($conflicts as $conflict) {
            if ((string) $conflict['turo_vehicle_id'] === trim($keepTuroVehicleId)) {
                continue;
            }

            $this->db->table('vehicle_turo_listings')
                ->where('id', (int) $conflict['id'])
                ->update([
                    'is_active' => false,
                    'unlisted_at' => date('Y-m-d H:i:s'),
                    'mapping_note' => $note,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->recordAudit((int) $conflict['id'], 'deactivated_conflict', (string) $conflict['turo_vehicle_id'], (int) $conflict['fleet_vehicle_id'], null, $note, $actorUserId);
            $count++;
        }

        return $count;
    }

    private function recordAudit(int $listingId, string $action, string $turoVehicleId, ?int $oldFleetVehicleId, ?int $newFleetVehicleId, ?string $note, ?int $actorUserId): void
    {
        $this->db->table('vehicle_turo_listing_audits')->insert([
            'vehicle_turo_listing_id' => $listingId,
            'action' => $action,
            'turo_vehicle_id' => $turoVehicleId,
            'old_fleet_vehicle_id' => $oldFleetVehicleId,
            'new_fleet_vehicle_id' => $newFleetVehicleId,
            'note' => $note,
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
