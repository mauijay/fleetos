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
        return $this->db->table('vehicle_files links')
            ->join('fleet_vehicles vehicles', 'vehicles.id = links.fleet_vehicle_id')
            ->join('files evidence', 'evidence.id = links.file_id')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('links.file_id', $fileId)
            ->where('evidence.deleted_at', null)
            ->countAllResults() > 0;
    }

    public function imageBelongsToVehicle(int $companyId, int $vehicleId, int $imageId): bool
    {
        return $this->db->table('vehicle_images links')
            ->join('fleet_vehicles vehicles', 'vehicles.id = links.fleet_vehicle_id')
            ->join('images evidence', 'evidence.id = links.image_id')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('links.image_id', $imageId)
            ->where('evidence.deleted_at', null)
            ->countAllResults() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function currentForVehicle(int $companyId, int $vehicleId): array
    {
        if (! $this->migrated()) {
            return [];
        }

        return $this->itemBuilder($companyId, $vehicleId)
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
            ->join('turo_trips_normalized trips', 'trips.id = events.source_turo_trip_normalized_id', 'left')
            ->where('events.company_id', $companyId)
            ->where('events.vehicle_damage_item_id', $itemId)
            ->orderBy('events.occurred_at', 'ASC')
            ->orderBy('events.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function evidence(int $companyId, int $itemId): array
    {
        return $this->db->table('vehicle_damage_item_evidence evidence')
            ->select('evidence.*, files.original_filename, files.path AS file_path, images.path AS image_path, images.alt_text')
            ->join('files', 'files.id = evidence.file_id', 'left')
            ->join('images', 'images.id = evidence.image_id', 'left')
            ->where('evidence.company_id', $companyId)
            ->where('evidence.vehicle_damage_item_id', $itemId)
            ->orderBy('evidence.id', 'ASC')
            ->get()->getResultArray();
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

    public function updateItem(int $companyId, int $vehicleId, int $itemId, array $values): bool
    {
        $this->db->table('vehicle_damage_items')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('id', $itemId)
            ->update($values);

        return $this->db->affectedRows() === 1;
    }

    public function insertEvent(array $values): int
    {
        $this->db->table('vehicle_damage_item_events')->insert($values);

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
            ->join('turo_trips_normalized trips', 'trips.id = damage.discovered_turo_trip_normalized_id AND trips.fleet_vehicle_id = damage.fleet_vehicle_id', 'left')
            ->join('damage_claims claims', 'claims.id = damage.damage_claim_id AND claims.fleet_vehicle_id = damage.fleet_vehicle_id', 'left')
            ->join('lookup_values claim_status', 'claim_status.id = claims.claim_status_lookup_value_id', 'left')
            ->join('vehicle_recovery_exceptions exceptions', 'exceptions.id = damage.vehicle_recovery_exception_id AND exceptions.company_id = damage.company_id', 'left')
            ->where('damage.company_id', $companyId)
            ->where('damage.fleet_vehicle_id', $vehicleId);
    }
}
