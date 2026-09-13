<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class MaintenanceCostRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string, mixed>> */
    public function recordedActivityForCompany(int $companyId, string $fromDate, string $toDateExclusive): array
    {
        return $this->db->table('maintenance_logs maintenance')
            ->select('maintenance.id, maintenance.fleet_vehicle_id, maintenance.service_on, maintenance.total_amount, maintenance.description')
            ->join('fleet_vehicles vehicle', 'vehicle.id = maintenance.fleet_vehicle_id')
            ->join('lookup_values status', 'status.id = maintenance.maintenance_status_lookup_value_id')
            ->join('lookup_types status_type', 'status_type.id = status.lookup_type_id')
            ->where('vehicle.company_id', $companyId)
            ->where('status_type.code', 'maintenance_status')
            ->where('status.code', 'completed')
            ->where('maintenance.deleted_at', null)
            ->where('maintenance.total_amount >', 0)
            ->where('maintenance.service_on >=', $fromDate)
            ->where('maintenance.service_on <', $toDateExclusive)
            ->orderBy('maintenance.id', 'ASC')
            ->get()->getResultArray();
    }
}
