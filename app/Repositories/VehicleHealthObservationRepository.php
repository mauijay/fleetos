<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleHealthObservationRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $companyId, int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles vehicles')
            ->select('vehicles.*, statuses.code AS status_code, statuses.name AS status_name')
            ->join('vehicle_statuses statuses', 'statuses.id = vehicles.vehicle_status_id', 'left')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.id', $vehicleId)
            ->where('vehicles.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function vehicles(int $companyId): array
    {
        return $this->db->table('fleet_vehicles vehicles')
            ->select('vehicles.*, statuses.code AS status_code, statuses.name AS status_name')
            ->join('vehicle_statuses statuses', 'statuses.id = vehicles.vehicle_status_id', 'left')
            ->where('vehicles.company_id', $companyId)
            ->where('vehicles.deleted_at', null)
            ->orderBy('vehicles.fleet_number IS NULL', 'ASC', false)
            ->orderBy('vehicles.fleet_number', 'ASC')
            ->orderBy('vehicles.fleet_code', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function observation(int $companyId, int $vehicleId, int $observationId): ?array
    {
        $row = $this->db->table('vehicle_health_observations')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('id', $observationId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function hasReplacement(int $companyId, int $vehicleId, int $observationId): bool
    {
        return $this->db->table('vehicle_health_observations')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('supersedes_observation_id', $observationId)
            ->countAllResults() > 0;
    }

    /** @return array<string, mixed>|null */
    public function tirePressureDetail(int $companyId, int $vehicleId, int $observationId): ?array
    {
        $row = $this->db->table('vehicle_tire_pressure_observations pressure')
            ->select('pressure.*')
            ->join('vehicle_health_observations observations', 'observations.id = pressure.vehicle_health_observation_id')
            ->where('observations.company_id', $companyId)
            ->where('observations.fleet_vehicle_id', $vehicleId)
            ->where('observations.id', $observationId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function odometerDetail(int $companyId, int $vehicleId, int $observationId): ?array
    {
        $row = $this->db->table('vehicle_odometer_observations odometer')
            ->select('odometer.*')
            ->join('vehicle_health_observations observations', 'observations.id = odometer.vehicle_health_observation_id')
            ->where('observations.company_id', $companyId)
            ->where('observations.fleet_vehicle_id', $vehicleId)
            ->where('observations.id', $observationId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestValid(int $companyId, int $vehicleId, string $observationCode, string $asOf): ?array
    {
        $row = $this->validBuilder($companyId, $vehicleId, $observationCode, $asOf)
            ->orderBy('observations.observed_at', 'DESC')
            ->orderBy('observations.id', 'DESC')
            ->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestTirePressure(int $companyId, int $vehicleId, string $asOf): ?array
    {
        $row = $this->validBuilder($companyId, $vehicleId, 'tire_pressure', $asOf)
            ->select('pressure.lf_psi, pressure.rf_psi, pressure.lr_psi, pressure.rr_psi, pressure.recommended_psi')
            ->join('vehicle_tire_pressure_observations pressure', 'pressure.vehicle_health_observation_id = observations.id')
            ->orderBy('observations.observed_at', 'DESC')
            ->orderBy('observations.id', 'DESC')
            ->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestOdometer(int $companyId, int $vehicleId, string $asOf): ?array
    {
        $row = $this->validBuilder($companyId, $vehicleId, 'odometer', $asOf)
            ->select('odometer.odometer_miles')
            ->join('vehicle_odometer_observations odometer', 'odometer.vehicle_health_observation_id = observations.id')
            ->orderBy('observations.observed_at', 'DESC')
            ->orderBy('observations.id', 'DESC')
            ->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $companyId, int $vehicleId, ?string $observationCode = null, int $limit = 25): array
    {
        $builder = $this->db->table('vehicle_health_observations observations')
            ->select('observations.*')
            ->select('pressure.lf_psi, pressure.rf_psi, pressure.lr_psi, pressure.rr_psi, pressure.recommended_psi')
            ->select('odometer.odometer_miles')
            ->join('vehicle_tire_pressure_observations pressure', 'pressure.vehicle_health_observation_id = observations.id', 'left')
            ->join('vehicle_odometer_observations odometer', 'odometer.vehicle_health_observation_id = observations.id', 'left')
            ->where('observations.company_id', $companyId)
            ->where('observations.fleet_vehicle_id', $vehicleId);
        if ($observationCode !== null) {
            $builder->where('observations.observation_code', $observationCode);
        }

        return $builder->orderBy('observations.observed_at', 'DESC')
            ->orderBy('observations.id', 'DESC')
            ->get(max(1, min($limit, 100)))->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function byExternalIdentity(int $companyId, string $source, string $observationCode, string $externalId): ?array
    {
        $row = $this->db->table('vehicle_health_observations')
            ->where('company_id', $companyId)
            ->where('source', $source)
            ->where('observation_code', $observationCode)
            ->where('source_external_id', $externalId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function insertObservation(int $companyId, int $vehicleId, array $values): int
    {
        $this->db->table('vehicle_health_observations')->insert(array_merge($values, [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
        ]));

        return (int) $this->db->insertID();
    }

    public function insertTirePressure(int $companyId, int $vehicleId, int $observationId, array $values): void
    {
        if ($this->observation($companyId, $vehicleId, $observationId) === null) {
            throw new \RuntimeException('Company-scoped tire-pressure observation was not found.');
        }
        $this->db->table('vehicle_tire_pressure_observations')->insert(array_merge(
            ['vehicle_health_observation_id' => $observationId],
            $values,
        ));
    }

    public function insertOdometer(int $companyId, int $vehicleId, int $observationId, int $odometerMiles): void
    {
        if ($this->observation($companyId, $vehicleId, $observationId) === null) {
            throw new \RuntimeException('Company-scoped odometer observation was not found.');
        }
        $this->db->table('vehicle_odometer_observations')->insert([
            'vehicle_health_observation_id' => $observationId,
            'odometer_miles' => $odometerMiles,
        ]);
    }

    public function void(int $companyId, int $vehicleId, int $observationId, int $actorUserId, string $reason, string $voidedAt): bool
    {
        $this->db->table('vehicle_health_observations')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('id', $observationId)
            ->where('voided_at', null)
            ->update([
                'voided_at' => $voidedAt,
                'voided_by_user_id' => $actorUserId,
                'void_reason' => $reason,
            ]);

        return $this->db->affectedRows() === 1;
    }

    public function updateOdometerCache(int $companyId, int $vehicleId, ?int $odometerMiles, string $updatedAt): void
    {
        $this->db->table('fleet_vehicles')
            ->where('company_id', $companyId)
            ->where('id', $vehicleId)
            ->update(['odometer_miles' => $odometerMiles, 'updated_at' => $updatedAt]);
    }

    private function validBuilder(int $companyId, int $vehicleId, string $observationCode, string $asOf): \CodeIgniter\Database\BaseBuilder
    {
        $observationTable = $this->db->protectIdentifiers($this->db->getPrefix() . 'vehicle_health_observations', true, false);

        return $this->db->table('vehicle_health_observations observations')
            ->select('observations.*')
            ->where('observations.company_id', $companyId)
            ->where('observations.fleet_vehicle_id', $vehicleId)
            ->where('observations.observation_code', $observationCode)
            ->where('observations.observed_at <=', $asOf)
            ->where('observations.voided_at', null)
            ->where(
                'NOT EXISTS (SELECT 1 FROM ' . $observationTable . ' replacements WHERE replacements.supersedes_observation_id = observations.id)',
                null,
                false,
            );
    }
}
