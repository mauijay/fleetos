<?php

namespace App\Repositories;

use App\DTOs\Turo\TripMonthAllocationData;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class TripMonthAllocationRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @param TripMonthAllocationData[] $allocations */
    public function replaceForTrip(int $tripId, array $allocations): void
    {
        $this->db->transStart();

        $this->db->table('trip_month_allocations')
            ->where('turo_trip_normalized_id', $tripId)
            ->delete();

        foreach ($allocations as $allocation) {
            $this->db->table('trip_month_allocations')->insert([
                'turo_trip_normalized_id' => $tripId,
                'fleet_vehicle_id' => $allocation->fleetVehicleId,
                'allocation_month' => $allocation->allocationMonth,
                'allocation_starts_at' => $allocation->allocationStartsAt,
                'allocation_ends_at' => $allocation->allocationEndsAt,
                'allocated_trip_days' => $allocation->allocatedTripDays,
                'allocated_billable_days' => $allocation->allocatedBillableDays,
                'allocated_gross_revenue_amount' => $allocation->allocatedGrossRevenueAmount,
                'allocated_host_payout_amount' => $allocation->allocatedHostPayoutAmount,
                'allocated_delivery_fee_amount' => $allocation->allocatedDeliveryFeeAmount,
                'allocated_reimbursement_amount' => $allocation->allocatedReimbursementAmount,
                'is_forecast' => $allocation->isForecast,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new RuntimeException("Unable to replace month allocations for Turo trip record {$tripId}.");
        }
    }

    /** @return list<array<string, mixed>> */
    public function forecastActivityForCompany(int $companyId, string $fromDate, string $toDateExclusive): array
    {
        $builder = $this->db->table('trip_month_allocations allocation')
            ->select('allocation.id, allocation.fleet_vehicle_id, allocation.turo_trip_normalized_id')
            ->select('allocation.allocation_month, allocation.allocated_host_payout_amount')
            ->join('turo_trips_normalized trip', 'trip.id = allocation.turo_trip_normalized_id AND trip.fleet_vehicle_id = allocation.fleet_vehicle_id')
            ->join('fleet_vehicles vehicle', 'vehicle.id = allocation.fleet_vehicle_id')
            ->join('lookup_values trip_status', 'trip_status.id = trip.trip_status_lookup_value_id', 'left')
            ->where('vehicle.company_id', $companyId)
            ->where('trip.deleted_at', null)
            ->where('allocation.is_forecast', true)
            ->where('allocation.allocation_month >=', $fromDate)
            ->where('allocation.allocation_month <', $toDateExclusive)
            ->where('allocation.allocated_host_payout_amount !=', 0)
            ->groupStart()
                ->whereNotIn('trip_status.code', ['canceled', 'canceled_zero_payout', 'canceled_host_payout'])
                ->orWhere('trip_status.code', null)
            ->groupEnd();

        return $builder->orderBy('allocation.id', 'ASC')->get()->getResultArray();
    }
}
