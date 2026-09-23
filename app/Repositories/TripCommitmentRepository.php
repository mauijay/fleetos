<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class TripCommitmentRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function storageExists(): bool
    {
        return $this->db->tableExists('fleet_trip_commitments');
    }

    public function supportsExtraLink(): bool
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $rows = $this->db->query('PRAGMA table_info(' . $this->db->prefixTable('fleet_trip_commitments') . ')')->getResultArray();

            return in_array('fleet_extra_id', array_column($rows, 'name'), true);
        }

        return $this->storageExists() && $this->db->fieldExists('fleet_extra_id', 'fleet_trip_commitments');
    }

    /** @return array<string, mixed>|null */
    public function tripForCompany(int $companyId, int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, statuses.code AS trip_status_code, vehicles.company_id, vehicles.fleet_code, vehicles.display_name')
            ->select('profiles.energy_kind, profiles.ready_energy_target_percent, profiles.ready_energy_min_percent, profiles.ready_energy_preferred_max_percent')
            ->select('pickup.location_class AS pickup_location_class, pickup.source_text AS pickup_location_source_text')
            ->select('returns.location_class AS return_location_class, returns.source_text AS return_location_source_text')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->join('vehicle_operational_profiles profiles', 'profiles.fleet_vehicle_id = vehicles.id', 'left')
            ->join('scheduled_movement_locations pickup', 'pickup.turo_trip_normalized_id = trips.id AND pickup.movement_type = \'pickup\'', 'left')
            ->join('scheduled_movement_locations returns', 'returns.turo_trip_normalized_id = trips.id AND returns.movement_type = \'return\'', 'left')
            ->where('trips.id', $tripId)
            ->where('trips.deleted_at', null)
            ->where('vehicles.company_id', $companyId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function forTrip(int $companyId, int $tripId): array
    {
        if (! $this->storageExists()) {
            return [];
        }
        return $this->db->table('fleet_trip_commitments commitments')
            ->select('commitments.*')
            ->join('turo_trips_normalized trips', 'trips.id = commitments.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id AND vehicles.company_id = commitments.company_id')
            ->where('commitments.company_id', $companyId)
            ->where('commitments.turo_trip_normalized_id', $tripId)
            ->where('trips.deleted_at', null)
            ->where('vehicles.company_id', $companyId)
            ->orderBy("CASE commitments.state WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END", '', false)
            ->orderBy('commitments.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function activeForTrip(int $companyId, int $tripId): array
    {
        return array_values(array_filter(
            $this->forTrip($companyId, $tripId),
            static fn (array $row): bool => ($row['state'] ?? null) === 'active',
        ));
    }

    /** @return array<string, mixed>|null */
    public function activeEnergyOverride(int $companyId, int $tripId, ?int $excludingId = null): ?array
    {
        if (! $this->storageExists()) {
            return null;
        }
        $builder = $this->db->table('fleet_trip_commitments commitments')
            ->select('commitments.*')
            ->join('turo_trips_normalized trips', 'trips.id = commitments.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id AND vehicles.company_id = commitments.company_id')
            ->where('commitments.company_id', $companyId)
            ->where('commitments.turo_trip_normalized_id', $tripId)
            ->where('commitments.category', 'energy_override')
            ->where('commitments.state', 'active')
            ->where('commitments.active_override_slot', 'energy')
            ->where('trips.deleted_at', null)
            ->where('vehicles.company_id', $companyId);
        if ($excludingId !== null) {
            $builder->where('commitments.id !=', $excludingId);
        }
        $row = $builder->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findForCompany(int $companyId, int $tripId, int $commitmentId): ?array
    {
        foreach ($this->forTrip($companyId, $tripId) as $commitment) {
            if ((int) $commitment['id'] === $commitmentId) {
                return $commitment;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function auditsForTrip(int $companyId, int $tripId): array
    {
        if (! $this->db->tableExists('fleet_trip_commitment_audits')) {
            return [];
        }
        return $this->db->table('fleet_trip_commitment_audits audits')
            ->select('audits.*')
            ->join('fleet_trip_commitments commitments', 'commitments.id = audits.commitment_id AND commitments.company_id = audits.company_id')
            ->where('audits.company_id', $companyId)
            ->where('commitments.turo_trip_normalized_id', $tripId)
            ->orderBy('audits.id', 'DESC')
            ->get()->getResultArray();
    }

    public function create(array $data): int
    {
        $this->db->table('fleet_trip_commitments')->insert($data);

        return (int) $this->db->insertID();
    }

    public function update(int $companyId, int $commitmentId, array $data): bool
    {
        $this->db->table('fleet_trip_commitments')
            ->where('id', $commitmentId)
            ->where('company_id', $companyId)
            ->update($data);

        return $this->db->affectedRows() > 0;
    }

    public function audit(int $commitmentId, int $companyId, string $action, ?array $before, array $after, int $actorUserId): void
    {
        $this->db->table('fleet_trip_commitment_audits')->insert([
            'commitment_id' => $commitmentId,
            'company_id' => $companyId,
            'action' => $action,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_user_id' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Guest commitment transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
