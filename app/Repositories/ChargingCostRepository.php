<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class ChargingCostRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string, mixed>> */
    public function recordedActivityForCompany(int $companyId, string $fromAt, string $toAtExclusive): array
    {
        return $this->db->table('charging_sessions charging')
            ->select('charging.id, charging.fleet_vehicle_id, charging.turo_trip_normalized_id, charging.ended_at, charging.cost_amount, charging.charging_location')
            ->join('fleet_vehicles vehicle', 'vehicle.id = charging.fleet_vehicle_id')
            ->where('vehicle.company_id', $companyId)
            ->where('charging.deleted_at', null)
            ->where('charging.ended_at IS NOT NULL', null, false)
            ->where('charging.cost_amount >', 0)
            ->where('charging.ended_at >=', $fromAt)
            ->where('charging.ended_at <', $toAtExclusive)
            ->orderBy('charging.id', 'ASC')
            ->get()->getResultArray();
    }
}
