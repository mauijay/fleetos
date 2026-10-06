<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleDamageRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function migrated(): bool
    {
        return $this->db->tableExists('vehicle_damage_items');
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $companyId, int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles vehicles')
            ->select('vehicles.*')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('vehicles.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function trip(int $companyId, int $vehicleId, int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('trips.fleet_vehicle_id', $vehicleId)
            ->where('trips.id', $tripId)
            ->where('trips.deleted_at', null)
            ->where('vehicles.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function movementEvent(int $companyId, int $vehicleId, int $tripId, int $eventId): ?array
    {
        $row = $this->db->table('trip_movement_events events')
            ->select('events.*')
            ->where('events.company_id', $companyId)
            ->where('events.fleet_vehicle_id', $vehicleId)
            ->where('events.turo_trip_normalized_id', $tripId)
            ->where('events.id', $eventId)
            ->where('events.voided_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function recoveryException(int $companyId, int $vehicleId, int $tripId, int $exceptionId): ?array
    {
        $row = $this->db->table('vehicle_recovery_exceptions exceptions')
            ->select('exceptions.*')
            ->join('trip_movement_events recovery', 'recovery.id = exceptions.trip_movement_event_id AND recovery.company_id = exceptions.company_id')
            ->where('exceptions.company_id', $companyId)
            ->where('exceptions.fleet_vehicle_id', $vehicleId)
            ->where('exceptions.turo_trip_normalized_id', $tripId)
            ->where('exceptions.id', $exceptionId)
            ->where('exceptions.exception_code', 'damage')
            ->where('recovery.event_code', 'vehicle_recovered')
            ->where('recovery.voided_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function claim(int $companyId, int $vehicleId, int $claimId): ?array
    {
        $row = $this->db->table('damage_claims claims')
            ->select('claims.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = claims.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('claims.fleet_vehicle_id', $vehicleId)
            ->where('claims.id', $claimId)
            ->where('claims.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function fileBelongsToVehicle(int $companyId, int $vehicleId, int $fileId): bool
    {
        $metadata = $this->db->table('vehicle_files links')->select('evidence.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = links.fleet_vehicle_id')
            ->join('files evidence', 'evidence.id = links.file_id')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('links.file_id', $fileId)
            ->where('evidence.deleted_at', null)
            ->where('vehicles.deleted_at', null)
            ->get()->getRowArray();

        return $metadata !== null && $this->privateMetadata($metadata);
    }

    public function imageBelongsToVehicle(int $companyId, int $vehicleId, int $imageId): bool
    {
        $metadata = $this->db->table('vehicle_images links')->select('evidence.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = links.fleet_vehicle_id')
            ->join('images evidence', 'evidence.id = links.image_id')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('links.image_id', $imageId)
            ->where('evidence.deleted_at', null)
            ->where('vehicles.deleted_at', null)
            ->get()->getRowArray();

        return $metadata !== null && $this->privateMetadata($metadata);
    }

    /** @return list<array<string, mixed>> */
    public function currentForVehicle(int $companyId, int $vehicleId): array
    {
        if (! $this->migrated()) {
            return [];
        }

        $builder = $this->itemBuilder($companyId, $vehicleId);
        if ($this->db->fieldExists('current_condition_item_id', 'vehicle_damage_items')) {
            $builder->where('damage.current_condition_item_id', null);
        }

        return $builder
            ->whereIn('damage.status_code', ['open', 'accepted_unrepaired'])
            ->orderBy("damage.severity_code = 'unsafe'", 'DESC', false)
            ->orderBy('damage.discovered_at', 'ASC')
            ->orderBy('damage.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function historyForVehicle(int $companyId, int $vehicleId): array
    {
        if (! $this->migrated()) {
            return [];
        }

        return $this->itemBuilder($companyId, $vehicleId)
            ->whereIn('damage.status_code', ['repaired', 'resolved_other'])
            ->orderBy('damage.resolved_at', 'DESC')
            ->orderBy('damage.id', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function item(int $companyId, int $vehicleId, int $itemId): ?array
    {
        if (! $this->migrated()) {
            return null;
        }

        $row = $this->itemBuilder($companyId, $vehicleId)
            ->where('damage.id', $itemId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $companyId, int $itemId): array
    {
        return $this->db->table('vehicle_damage_item_events events')
            ->select('events.*, trips.turo_reservation_id')
            ->join('vehicle_damage_items parent', 'parent.id = events.vehicle_damage_item_id AND parent.company_id = events.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = parent.fleet_vehicle_id AND vehicles.company_id = parent.company_id AND vehicles.deleted_at IS NULL')
            ->join('turo_trips_normalized trips', 'trips.id = events.source_turo_trip_normalized_id AND trips.fleet_vehicle_id = parent.fleet_vehicle_id AND trips.deleted_at IS NULL', 'left')
            ->where('events.company_id', $companyId)
            ->where('events.vehicle_damage_item_id', $itemId)
            ->orderBy('events.occurred_at', 'ASC')
            ->orderBy('events.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function evidence(int $companyId, int $itemId): array
    {
        $rows = $this->db->table('vehicle_damage_item_evidence evidence')
            ->select('evidence.*, files.original_filename, files.path AS file_path, files.storage_disk AS file_storage_disk, images.path AS image_path, images.storage_disk AS image_storage_disk, images.alt_text')
            ->join('files', 'files.id = evidence.file_id', 'left')
            ->join('images', 'images.id = evidence.image_id', 'left')
            ->join('vehicle_damage_items parent', 'parent.id = evidence.vehicle_damage_item_id AND parent.company_id = evidence.company_id')
            ->select('parent.fleet_vehicle_id AS evidence_vehicle_id')
            ->where('evidence.company_id', $companyId)
            ->where('evidence.vehicle_damage_item_id', $itemId)
            ->groupStart()->where('evidence.file_id', null)->orWhere('files.deleted_at', null)->groupEnd()
            ->groupStart()->where('evidence.image_id', null)->orWhere('images.deleted_at', null)->groupEnd()
            ->orderBy('evidence.id', 'ASC')
            ->get()->getResultArray();

        return array_values(array_filter($rows, fn (array $row): bool =>
            ($row['file_id'] === null || $this->fileBelongsToVehicle($companyId, (int) $row['evidence_vehicle_id'], (int) $row['file_id']))
            && ($row['image_id'] === null || $this->imageBelongsToVehicle($companyId, (int) $row['evidence_vehicle_id'], (int) $row['image_id']))));
    }

    /** @return list<array<string, mixed>> */
    public function availableDamageExceptions(int $companyId, int $vehicleId, int $tripId): array
    {
        if (! $this->migrated()) {
            return [];
        }

        return $this->db->table('vehicle_recovery_exceptions exceptions')
            ->select('exceptions.*')
            ->join('trip_movement_events recovery', 'recovery.id = exceptions.trip_movement_event_id AND recovery.company_id = exceptions.company_id')
            ->join('vehicle_damage_items damage', 'damage.vehicle_recovery_exception_id = exceptions.id', 'left')
            ->where('exceptions.company_id', $companyId)
            ->where('exceptions.fleet_vehicle_id', $vehicleId)
            ->where('exceptions.turo_trip_normalized_id', $tripId)
            ->where('exceptions.exception_code', 'damage')
            ->where('recovery.voided_at', null)
            ->where('damage.id', null)
            ->orderBy('exceptions.id', 'ASC')
            ->get()->getResultArray();
    }

    public function insertItem(array $values): int
    {
        $this->db->table('vehicle_damage_items')->insert($values);

        return (int) $this->db->insertID();
    }

    /** Resolve exactly one hop; malformed relationships fail closed. @return array<string,mixed>|null */
    public function canonicalItem(int $companyId, int $vehicleId, int $itemId): ?array
    {
        $item = $this->item($companyId, $vehicleId, $itemId);
        if ($item === null || ($item['current_condition_item_id'] ?? null) === null) {
            return $item;
        }
        $target = $this->item($companyId, $vehicleId, (int) $item['current_condition_item_id']);
        if ((int) $item['current_condition_item_id'] < 1 || $target === null || ($target['current_condition_item_id'] ?? null) !== null || (int) $target['id'] === $itemId) {
            throw new \RuntimeException('Invalid canonical condition relationship.');
        }

        return $target;
    }

    /** Serialize all condition relationship writes for a vehicle, including incoming links. */
    public function lockVehicle(int $companyId, int $vehicleId): void
    {
        $sql = $this->db->table('fleet_vehicles')->where('company_id', $companyId)->where('id', $vehicleId)->where('deleted_at', null)->getCompiledSelect();
        $result = $this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'));
        if ($result === false) {
            throw new \RuntimeException('Vehicle state is locked by another update. Reload and retry.');
        }
        $row = $result->getRowArray();
        if ($row === null) {
            throw new \InvalidArgumentException('Vehicle not found in the active fleet company.');
        }
    }

    public function lockItem(int $companyId, int $vehicleId, int $itemId): void
    {
        $sql = $this->db->table('vehicle_damage_items')->where('company_id', $companyId)->where('fleet_vehicle_id', $vehicleId)->where('id', $itemId)->getCompiledSelect();
        $result = $this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'));
        if ($result === false) {
            throw new \RuntimeException('Condition state is locked by another update. Reload and retry.');
        }
        if ($result->getRowArray() === null) {
            throw new \InvalidArgumentException('Condition not found for this vehicle and company.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function relatedForVehicle(int $companyId, int $vehicleId): array
    {
        if (! $this->db->fieldExists('current_condition_item_id', 'vehicle_damage_items')) {
            return [];
        }

        return $this->itemBuilder($companyId, $vehicleId)->where('damage.current_condition_item_id IS NOT NULL', null, false)->orderBy('damage.id')->get()->getResultArray();
    }

    public function updateItem(int $companyId, int $vehicleId, int $itemId, array $values): bool
    {
        $updated = $this->db->table('vehicle_damage_items')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('id', $itemId)
            ->update($values);

        if (! $updated) {
            return false;
        }

        return $this->db->affectedRows() === 1 || $this->item($companyId, $vehicleId, $itemId) !== null;
    }

    public function insertEvent(array $values): int
    {
        if (! $this->db->table('vehicle_damage_item_events')->insert($values)) {
            throw new \RuntimeException('Damage event could not be recorded.');
        }

        return (int) $this->db->insertID();
    }

    public function insertEvidence(array $values): int
    {
        $this->db->table('vehicle_damage_item_evidence')->insert($values);

        return (int) $this->db->insertID();
    }

    private function itemBuilder(int $companyId, int $vehicleId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('vehicle_damage_items damage')
            ->select('damage.*, trips.turo_reservation_id, claims.claim_number')
            ->select('claim_status.code AS claim_status_code, claim_status.name AS claim_status_name')
            ->select('exceptions.status AS recovery_exception_status, exceptions.note AS recovery_exception_note')
            ->join('fleet_vehicles vehicles', 'vehicles.id = damage.fleet_vehicle_id AND vehicles.company_id = damage.company_id AND vehicles.deleted_at IS NULL')
            ->join('turo_trips_normalized trips', 'trips.id = damage.discovered_turo_trip_normalized_id AND trips.fleet_vehicle_id = damage.fleet_vehicle_id AND trips.deleted_at IS NULL', 'left')
            ->join('damage_claims claims', 'claims.id = damage.damage_claim_id AND claims.fleet_vehicle_id = damage.fleet_vehicle_id', 'left')
            ->join('lookup_values claim_status', 'claim_status.id = claims.claim_status_lookup_value_id', 'left')
            ->join('vehicle_recovery_exceptions exceptions', 'exceptions.id = damage.vehicle_recovery_exception_id AND exceptions.company_id = damage.company_id', 'left')
            ->where('damage.company_id', $companyId)
            ->where('damage.fleet_vehicle_id', $vehicleId);
    }

    private function privateMetadata(array $metadata): bool
    {
        $path = str_replace('\\', '/', (string) ($metadata['path'] ?? ''));
        if (($metadata['storage_disk'] ?? '') !== 'local' || $path === '' || str_starts_with($path, '/') || str_starts_with($path, 'public/') || preg_match('/[:\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
