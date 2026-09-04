<?php

namespace App\Repositories;

use App\DTOs\Turo\NormalizedTripData;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroNormalizedTripRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function upsert(NormalizedTripData $trip): array
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'fleet_vehicle_id' => $trip->fleetVehicleId,
            'turo_trip_raw_id' => $trip->turoTripRawId,
            'trip_status_lookup_value_id' => $trip->tripStatusLookupValueId,
            'turo_trip_id' => $trip->turoTripId,
            'turo_reservation_id' => $trip->turoReservationId,
            'guest_name' => $trip->guestName,
            'booked_at' => $trip->bookedAt,
            'starts_at' => $trip->startsAt,
            'ends_at' => $trip->endsAt,
            'canceled_at' => $trip->canceledAt,
            'trip_days' => $trip->tripDays,
            'billable_days' => $trip->billableDays,
            'gross_revenue_amount' => $trip->grossRevenueAmount,
            'host_payout_amount' => $trip->hostPayoutAmount,
            'delivery_fee_amount' => $trip->deliveryFeeAmount,
            'discount_amount' => $trip->discountAmount,
            'reimbursement_amount' => $trip->reimbursementAmount,
            'airport_fee_amount' => $trip->airportFeeAmount,
            'currency_code' => $trip->currencyCode,
            'is_forecast' => $trip->isForecast,
            'normalized_at' => $now,
            'updated_at' => $now,
        ];

        $existing = $this->db->table('turo_trips_normalized')
            ->where('turo_trip_id', $trip->turoTripId)
            ->get()
            ->getRowArray();

        if ($existing === null) {
            $this->db->table('turo_trips_normalized')->insert(array_merge($data, ['created_at' => $now]));

            return ['id' => (int) $this->db->insertID(), 'created' => true, 'materially_changed' => true, 'old' => null, 'new' => $data];
        }

        $this->db->table('turo_trips_normalized')
            ->where('id', $existing['id'])
            ->update($data);

        $materialFields = ['fleet_vehicle_id', 'trip_status_lookup_value_id', 'starts_at', 'ends_at', 'canceled_at'];
        $materiallyChanged = false;
        foreach ($materialFields as $field) {
            if ((string) ($existing[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                $materiallyChanged = true;
                break;
            }
        }

        return ['id' => (int) $existing['id'], 'created' => false, 'materially_changed' => $materiallyChanged, 'old' => $existing, 'new' => $data];
    }

    /** @return array<string, mixed>|null */
    public function findByTuroTripId(string $turoTripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized')
            ->where('turo_trip_id', $turoTripId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, statuses.code AS trip_status_code')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->where('trips.id', $tripId)
            ->where('trips.deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function movementsBetween(string $start, string $end): array
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, statuses.code AS trip_status_code')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->where('trips.deleted_at', null)
            ->groupStart()
                ->groupStart()
                    ->where('trips.starts_at >=', $start)
                    ->where('trips.starts_at <', $end)
                ->groupEnd()
                ->orGroupStart()
                    ->where('trips.ends_at >=', $start)
                    ->where('trips.ends_at <', $end)
                ->groupEnd()
            ->groupEnd()
            ->orderBy('trips.starts_at', 'ASC')
            ->get()
            ->getResultArray();
    }
}
