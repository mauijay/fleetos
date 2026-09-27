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
        $occurredAt = "COALESCE(charging.ended_at, CASE WHEN charging.source_type = 'tesla_invoice_csv' THEN charging.started_at END)";

        return $this->db->table('charging_sessions charging')
            ->select("charging.id, charging.fleet_vehicle_id, charging.turo_trip_normalized_id, {$occurredAt} AS occurred_at, charging.cost_amount, charging.charging_location", false)
            ->join('fleet_vehicles vehicle', 'vehicle.id = charging.fleet_vehicle_id')
            ->where('vehicle.company_id', $companyId)
            ->where('charging.deleted_at', null)
            ->where("{$occurredAt} IS NOT NULL", null, false)
            ->where('charging.cost_amount >', 0)
            ->where($occurredAt . ' >= ' . $this->db->escape($fromAt), null, false)
            ->where($occurredAt . ' < ' . $this->db->escape($toAtExclusive), null, false)
            ->orderBy('charging.id', 'ASC')
            ->get()->getResultArray();
    }
}
