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

            return ['id' => (int) $this->db->insertID(), 'created' => true, 'old' => null, 'new' => $data];
        }

        $this->db->table('turo_trips_normalized')
            ->where('id', $existing['id'])
            ->update($data);

        return ['id' => (int) $existing['id'], 'created' => false, 'old' => $existing, 'new' => $data];
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
}
