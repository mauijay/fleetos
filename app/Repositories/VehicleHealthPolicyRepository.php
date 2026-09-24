<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleHealthPolicyRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function tirePressurePolicy(int $companyId, int $vehicleId, bool $enabledOnly = false): ?array
    {
        $builder = $this->db->table('vehicle_health_policies')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('reminder_code', 'tire_pressure_check');
        if ($enabledOnly) {
            $builder->where('is_enabled', true);
        }
        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function insert(int $companyId, int $vehicleId, array $values): int
    {
        $this->db->table('vehicle_health_policies')->insert(array_merge($values, [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
        ]));

        return (int) $this->db->insertID();
    }

    public function update(int $companyId, int $vehicleId, int $policyId, array $values): bool
    {
        $this->db->table('vehicle_health_policies')
            ->where('company_id', $companyId)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('id', $policyId)
            ->update($values);

        return $this->db->affectedRows() >= 0;
    }
}
