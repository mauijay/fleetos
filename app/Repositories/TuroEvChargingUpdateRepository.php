<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroEvChargingUpdateRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string, mixed>> */
    public function tripsByReservation(string $reservationId): array
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.turo_reservation_id, trips.turo_trip_id, trips.fleet_vehicle_id')
            ->select('trips.on_trip_ev_charging_amount, trips.post_trip_ev_charging_amount')
            ->select('vehicles.company_id, vehicles.fleet_code, vehicles.vin')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id', 'left')
            ->where('trips.turo_reservation_id', $reservationId)
            ->where('trips.deleted_at', null)
            ->where('vehicles.deleted_at', null)
            ->get()
            ->getResultArray();
    }

    /** @return list<string> */
    public function activeTuroVehicleIds(int $fleetVehicleId): array
    {
        $rows = $this->db->table('vehicle_turo_listings')
            ->select('turo_vehicle_id')
            ->where('fleet_vehicle_id', $fleetVehicleId)
            ->where('is_active', true)
            ->get()
            ->getResultArray();

        return array_values(array_map(
            static fn (array $row): string => trim((string) $row['turo_vehicle_id']),
            $rows,
        ));
    }

    public function begin(): void
    {
        $this->db->transBegin();
    }

    public function commit(): bool
    {
        return $this->db->transCommit();
    }

    public function rollback(): bool
    {
        return $this->db->transRollback();
    }

    public function updateOptimistically(
        int $tripId,
        int $fleetVehicleId,
        ?string $expectedOnTrip,
        ?string $expectedPostTrip,
        string $newOnTrip,
        string $newPostTrip,
    ): bool {
        $builder = $this->db->table('turo_trips_normalized')
            ->where('id', $tripId)
            ->where('fleet_vehicle_id', $fleetVehicleId)
            ->where('deleted_at', null);

        $expectedOnTrip === null
            ? $builder->where('on_trip_ev_charging_amount', null)
            : $builder->where('on_trip_ev_charging_amount', $expectedOnTrip);
        $expectedPostTrip === null
            ? $builder->where('post_trip_ev_charging_amount', null)
            : $builder->where('post_trip_ev_charging_amount', $expectedPostTrip);

        $builder->update([
            'on_trip_ev_charging_amount' => $newOnTrip,
            'post_trip_ev_charging_amount' => $newPostTrip,
        ]);

        return $this->db->affectedRows() === 1;
    }
}
