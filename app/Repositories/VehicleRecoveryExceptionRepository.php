<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleRecoveryExceptionRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string, mixed>> */
    public function forTrip(int $companyId, int $tripId): array
    {
        if ($this->unmigratedTestFixture()) {
            return [];
        }

        return $this->scopedQuery($companyId)
            ->where('exceptions.turo_trip_normalized_id', $tripId)
            ->orderBy('exceptions.id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     * @phpstan-impure
     */
    public function openForCompany(int $companyId): array
    {
        if ($this->unmigratedTestFixture()) {
            return [];
        }

        return $this->scopedQuery($companyId)
            ->where('exceptions.status', 'open')
            ->where('recovery.voided_at', null)
            ->orderBy('exceptions.id', 'ASC')
            ->get()->getResultArray();
    }

    public function createForRecovery(int $companyId, int $tripId, int $vehicleId, int $eventId, string $code, ?string $note, int $actorUserId): int
    {
        $recovery = $this->db->table('trip_movement_events recovery')
            ->select('recovery.id')
            ->join('turo_trips_normalized trips', 'trips.id = recovery.turo_trip_normalized_id AND trips.fleet_vehicle_id = recovery.fleet_vehicle_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = recovery.fleet_vehicle_id')
            ->where([
                'recovery.id' => $eventId,
                'recovery.company_id' => $companyId,
                'recovery.turo_trip_normalized_id' => $tripId,
                'recovery.fleet_vehicle_id' => $vehicleId,
                'recovery.event_code' => 'vehicle_recovered',
                'vehicles.company_id' => $companyId,
                'recovery.voided_at' => null,
            ])->get()->getRowArray();
        if ($recovery === null || $actorUserId < 1) {
            throw new \InvalidArgumentException('Recovery does not belong to the active fleet company.');
        }

        $this->db->table('vehicle_recovery_exceptions')->insert([
            'company_id' => $companyId,
            'turo_trip_normalized_id' => $tripId,
            'fleet_vehicle_id' => $vehicleId,
            'trip_movement_event_id' => $eventId,
            'exception_code' => $code,
            'note' => $note,
            'status' => 'open',
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    public function resolveForCompany(int $companyId, int $tripId, int $vehicleId, int $exceptionId, int $actorUserId, ?string $note): bool
    {
        if ($actorUserId < 1) {
            return false;
        }
        $exception = $this->scopedQuery($companyId)
            ->where('exceptions.id', $exceptionId)
            ->where('exceptions.turo_trip_normalized_id', $tripId)
            ->where('exceptions.fleet_vehicle_id', $vehicleId)
            ->where('exceptions.status', 'open')
            ->where('recovery.voided_at', null)
            ->get()->getRowArray();
        if ($exception === null) {
            return false;
        }

        $this->db->table('vehicle_recovery_exceptions')
            ->where('id', $exceptionId)
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_by' => $actorUserId,
                'resolved_at' => date('Y-m-d H:i:s'),
                'resolution_note' => $note,
            ]);

        return $this->db->affectedRows() === 1;
    }

    private function scopedQuery(int $companyId): \CodeIgniter\Database\BaseBuilder
    {
        $checklistsTable = $this->db->prefixTable('trip_movement_checklists');

        return $this->db->table('vehicle_recovery_exceptions exceptions')
            ->select('exceptions.*, vehicles.fleet_code, vehicles.display_name, recovery.voided_at AS recovery_voided_at')
            ->select('(SELECT MAX(checklists.id) FROM ' . $checklistsTable . ' checklists WHERE checklists.turo_trip_normalized_id = exceptions.turo_trip_normalized_id AND checklists.fleet_vehicle_id = exceptions.fleet_vehicle_id AND checklists.movement_type = \'return\') AS checklist_id', false)
            ->join('trip_movement_events recovery', 'recovery.id = exceptions.trip_movement_event_id AND recovery.company_id = exceptions.company_id AND recovery.turo_trip_normalized_id = exceptions.turo_trip_normalized_id AND recovery.fleet_vehicle_id = exceptions.fleet_vehicle_id AND recovery.event_code = \'vehicle_recovered\'')
            ->join('turo_trips_normalized trips', 'trips.id = exceptions.turo_trip_normalized_id AND trips.fleet_vehicle_id = exceptions.fleet_vehicle_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = exceptions.fleet_vehicle_id AND vehicles.company_id = exceptions.company_id')
            ->where('exceptions.company_id', $companyId);
    }

    private function unmigratedTestFixture(): bool
    {
        // Older isolated SQLite fixtures model only their subject tables. Production
        // must fail loudly if migration 000022 has not been applied.
        return defined('ENVIRONMENT') && constant('ENVIRONMENT') === 'testing' && ! $this->db->tableExists('vehicle_recovery_exceptions');
    }
}
