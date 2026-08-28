<?php

namespace App\Services\Turo;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroTransactionRelinkingService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{examined:int,relinked:int,unchanged:int} */
    public function relinkForTuroVehicle(string $turoVehicleId): array
    {
        $listing = $this->db->table('vehicle_turo_listings')
            ->where('turo_vehicle_id', trim($turoVehicleId))
            ->where('is_active', true)
            ->get()->getRowArray();
        if ($listing === null) {
            return ['examined' => 0, 'relinked' => 0, 'unchanged' => 0];
        }

        $fleetVehicleId = (int) $listing['fleet_vehicle_id'];
        $trips = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.turo_trip_id, trips.fleet_vehicle_id')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id', 'left')
            ->where('raw.external_vehicle_id', trim($turoVehicleId))
            ->where('trips.fleet_vehicle_id', $fleetVehicleId)
            ->where('trips.deleted_at', null)
            ->get()->getResultArray();

        $examined = 0;
        $relinked = 0;
        foreach ($trips as $trip) {
            $transactions = $this->db->table('turo_transactions_normalized')
                ->groupStart()
                    ->where('turo_trip_normalized_id', (int) $trip['id'])
                    ->orGroupStart()
                        ->where('turo_trip_normalized_id', null)
                        ->where('external_trip_id', (string) $trip['turo_trip_id'])
                    ->groupEnd()
                ->groupEnd()
                ->get()->getResultArray();
            foreach ($transactions as $transaction) {
                $examined++;
                if ((int) ($transaction['turo_trip_normalized_id'] ?? 0) === (int) $trip['id'] && (int) ($transaction['fleet_vehicle_id'] ?? 0) === $fleetVehicleId) {
                    continue;
                }
                $this->db->table('turo_transactions_normalized')->where('id', (int) $transaction['id'])->update([
                    'turo_trip_normalized_id' => (int) $trip['id'],
                    'fleet_vehicle_id' => $fleetVehicleId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $relinked++;
            }
        }

        return ['examined' => $examined, 'relinked' => $relinked, 'unchanged' => $examined - $relinked];
    }
}
