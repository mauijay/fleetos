<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class FleetIntelligenceRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function fleetVehicles(): array
    {
        return $this->db->table('fleet_vehicles fv')
            ->select('fv.id, fv.fleet_code, fv.display_name, fv.vin, fv.license_plate, fv.odometer_miles')
            ->select('fv.in_service_date, fv.out_of_service_date, vs.code AS status_code, vs.name AS status_name')
            ->select('vs.is_available_for_booking, vtl.code AS trim_code, vtl.name AS trim_name, vtl.is_premium')
            ->select('vm.name AS model_name, vma.name AS make_name, vsp.model_year')
            ->join('vehicle_statuses vs', 'vs.id = fv.vehicle_status_id')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id')
            ->join('vehicle_makes vma', 'vma.id = vm.vehicle_make_id')
            ->where('fv.deleted_at', null)
            ->orderBy('fv.sort_order', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, int> */
    public function activeReservationCounts(string $asOf): array
    {
        $rows = $this->db->table('turo_trips_normalized trips')
            ->select('lookup_values.code AS status_code, COUNT(DISTINCT trips.fleet_vehicle_id) AS vehicle_count')
            ->join('lookup_values', 'lookup_values.id = trips.trip_status_lookup_value_id', 'left')
            ->where('trips.deleted_at', null)
            ->where('trips.fleet_vehicle_id IS NOT NULL')
            ->where('trips.starts_at <=', $asOf)
            ->where('trips.ends_at >=', $asOf)
            ->whereNotIn('lookup_values.code', ['canceled_zero_payout', 'canceled_host_payout'])
            ->groupBy('lookup_values.code')
            ->get()
            ->getResultArray();

        $counts = ['reserved' => 0, 'in_progress' => 0];

        foreach ($rows as $row) {
            $statusCode = (string) ($row['status_code'] ?? '');
            $vehicleCount = (int) $row['vehicle_count'];

            if ($statusCode === 'in_progress') {
                $counts['in_progress'] += $vehicleCount;
                continue;
            }

            $counts['reserved'] += $vehicleCount;
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> */
    public function reservationsBetween(string $startsAt, string $endsAt): array
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, trips.turo_trip_id AS source_reservation_id')
            ->select('trips.guest_name, trips.starts_at, trips.ends_at, trips.trip_days, trips.billable_days')
            ->select('trips.gross_revenue_amount, trips.host_payout_amount, trips.delivery_fee_amount')
            ->select('trips.reimbursement_amount, trips.is_forecast, lookup_values.code AS status_code')
            ->select("'turo' AS source", false)
            ->join('lookup_values', 'lookup_values.id = trips.trip_status_lookup_value_id', 'left')
            ->where('trips.deleted_at', null)
            ->where('trips.starts_at <', $endsAt)
            ->where('trips.ends_at >', $startsAt)
            ->orderBy('trips.starts_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function revenueMonthly(string $fromMonth, string $toMonth): array
    {
        return $this->db->table('trip_month_allocations allocations')
            ->select('allocations.allocation_month')
            ->select('SUM(allocations.allocated_trip_days) AS trip_days', false)
            ->select('SUM(allocations.allocated_billable_days) AS billable_days', false)
            ->select('SUM(allocations.allocated_gross_revenue_amount) AS gross_revenue', false)
            ->select('SUM(allocations.allocated_host_payout_amount) AS host_payout', false)
            ->select('SUM(allocations.allocated_delivery_fee_amount) AS delivery_fees', false)
            ->select('SUM(allocations.allocated_reimbursement_amount) AS reimbursements', false)
            ->select('SUM(CASE WHEN allocations.is_forecast = 1 THEN allocations.allocated_host_payout_amount ELSE 0 END) AS forecast_revenue', false)
            ->select('SUM(CASE WHEN allocations.is_forecast = 0 THEN allocations.allocated_host_payout_amount ELSE 0 END) AS completed_revenue', false)
            ->select("'turo' AS source", false)
            ->join('turo_trips_normalized trips', 'trips.id = allocations.turo_trip_normalized_id')
            ->where('trips.deleted_at', null)
            ->where('allocations.allocation_month >=', $fromMonth)
            ->where('allocations.allocation_month <=', $toMonth)
            ->groupBy('allocations.allocation_month')
            ->orderBy('allocations.allocation_month', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function revenueByVehicle(string $fromMonth, string $toMonth): array
    {
        return $this->db->table('trip_month_allocations allocations')
            ->select('fv.id AS fleet_vehicle_id, fv.fleet_code, fv.display_name')
            ->select('vtl.is_premium, vtl.code AS trim_code, vm.name AS vehicle_type')
            ->select('SUM(allocations.allocated_trip_days) AS trip_days', false)
            ->select('SUM(allocations.allocated_billable_days) AS billable_days', false)
            ->select('SUM(allocations.allocated_gross_revenue_amount) AS gross_revenue', false)
            ->select('SUM(allocations.allocated_host_payout_amount) AS host_payout', false)
            ->select('SUM(CASE WHEN allocations.is_forecast = 1 THEN allocations.allocated_host_payout_amount ELSE 0 END) AS forecast_revenue', false)
            ->select('SUM(CASE WHEN allocations.is_forecast = 0 THEN allocations.allocated_host_payout_amount ELSE 0 END) AS completed_revenue', false)
            ->join('fleet_vehicles fv', 'fv.id = allocations.fleet_vehicle_id', 'left')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id', 'left')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id', 'left')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id', 'left')
            ->join('turo_trips_normalized trips', 'trips.id = allocations.turo_trip_normalized_id')
            ->where('trips.deleted_at', null)
            ->where('allocations.allocation_month >=', $fromMonth)
            ->where('allocations.allocation_month <=', $toMonth)
            ->groupBy('fv.id, fv.fleet_code, fv.display_name, vtl.is_premium, vtl.code, vm.name')
            ->orderBy('host_payout', 'DESC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, float> */
    public function operatingCosts(string $fromDate, string $toDate): array
    {
        return [
            'maintenance' => $this->sumTableAmount('maintenance_logs', 'total_amount', 'service_on', $fromDate, $toDate),
            'charging' => $this->sumTableAmount('charging_sessions', 'cost_amount', 'started_at', $fromDate, $toDate),
            'airport_parking' => $this->sumTableAmount('airport_deliveries', 'parking_cost_amount', 'scheduled_at', $fromDate, $toDate),
            'loan_payments' => $this->activeLoanPaymentTotal(),
            'insurance_premiums' => $this->activeInsurancePremiumTotal(),
        ];
    }

    /** @return array<string, float> */
    public function fleetCapital(): array
    {
        $startupCosts = $this->sumAll('startup_costs', 'amount');
        $loanBalance = $this->sumAll('loans', 'current_balance');

        return [
            'fleet_value' => $startupCosts,
            'loan_balance' => $loanBalance,
            'fleet_equity' => $startupCosts - $loanBalance,
            'startup_costs' => $startupCosts,
        ];
    }

    /** @return array<int, array<string, float>> */
    public function fleetCapitalByVehicle(): array
    {
        $capital = [];

        foreach ($this->sumByVehicle('startup_costs', 'amount') as $fleetVehicleId => $amount) {
            $capital[$fleetVehicleId] ??= ['startup_costs' => 0.0, 'loan_balance' => 0.0];
            $capital[$fleetVehicleId]['startup_costs'] = $amount;
        }

        foreach ($this->sumByVehicle('loans', 'current_balance') as $fleetVehicleId => $amount) {
            $capital[$fleetVehicleId] ??= ['startup_costs' => 0.0, 'loan_balance' => 0.0];
            $capital[$fleetVehicleId]['loan_balance'] = $amount;
        }

        return $capital;
    }

    /** @return array<int, array<string, mixed>> */
    public function lifetimeRevenueByVehicle(): array
    {
        return $this->revenueByVehicle('1900-01-01', '2999-12-01');
    }

    /** @return array<int, array<string, mixed>> */
    public function tripAnalytics(string $fromDate, string $toDate): array
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.fleet_vehicle_id, fv.fleet_code, fv.display_name')
            ->select('COUNT(*) AS trip_count', false)
            ->select('SUM(trips.trip_days) AS trip_days', false)
            ->select('SUM(trips.billable_days) AS billable_days', false)
            ->select('AVG(trips.trip_days) AS average_trip_length', false)
            ->select('MAX(trips.trip_days) AS longest_trip', false)
            ->select('MIN(trips.trip_days) AS shortest_trip', false)
            ->select('SUM(CASE WHEN lookup_values.code IN (\'canceled_zero_payout\', \'canceled_host_payout\') THEN 1 ELSE 0 END) AS cancelled_trips', false)
            ->select('SUM(CASE WHEN airport_deliveries.id IS NOT NULL THEN 1 ELSE 0 END) AS airport_deliveries', false)
            ->select('SUM(CASE WHEN airport_deliveries.id IS NULL THEN 1 ELSE 0 END) AS home_deliveries', false)
            ->select('COUNT(DISTINCT charging_sessions.id) AS charging_events', false)
            ->join('lookup_values', 'lookup_values.id = trips.trip_status_lookup_value_id', 'left')
            ->join('fleet_vehicles fv', 'fv.id = trips.fleet_vehicle_id', 'left')
            ->join('airport_deliveries', 'airport_deliveries.turo_trip_normalized_id = trips.id', 'left')
            ->join('charging_sessions', 'charging_sessions.turo_trip_normalized_id = trips.id', 'left')
            ->where('trips.deleted_at', null)
            ->where('trips.starts_at >=', $fromDate)
            ->where('trips.starts_at <', $toDate)
            ->groupBy('trips.fleet_vehicle_id, fv.fleet_code, fv.display_name')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function repeatedGuests(string $fromDate, string $toDate): array
    {
        return $this->db->table('turo_trips_normalized')
            ->select('guest_name, COUNT(*) AS trip_count', false)
            ->where('deleted_at', null)
            ->where('guest_name IS NOT NULL')
            ->where('starts_at >=', $fromDate)
            ->where('starts_at <', $toDate)
            ->groupBy('guest_name')
            ->having('COUNT(*) >', 1)
            ->orderBy('trip_count', 'DESC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function openClaims(): array
    {
        return $this->db->table('damage_claims claims')
            ->select('claims.*, fv.fleet_code, fv.display_name, lookup_values.code AS status_code')
            ->join('fleet_vehicles fv', 'fv.id = claims.fleet_vehicle_id')
            ->join('lookup_values', 'lookup_values.id = claims.claim_status_lookup_value_id', 'left')
            ->where('claims.deleted_at', null)
            ->groupStart()
                ->where('claims.closed_on', null)
                ->orWhereNotIn('lookup_values.code', ['closed', 'paid'])
            ->groupEnd()
            ->orderBy('claims.reported_on', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function maintenanceDue(string $throughDate): array
    {
        return $this->db->table('maintenance_logs logs')
            ->select('logs.*, fv.fleet_code, fv.display_name, lookup_values.code AS status_code')
            ->join('fleet_vehicles fv', 'fv.id = logs.fleet_vehicle_id')
            ->join('lookup_values', 'lookup_values.id = logs.maintenance_status_lookup_value_id', 'left')
            ->where('logs.deleted_at', null)
            ->where('logs.service_on <=', $throughDate)
            ->groupStart()
                ->where('lookup_values.code IS NULL')
                ->orWhereNotIn('lookup_values.code', ['completed', 'canceled'])
            ->groupEnd()
            ->orderBy('logs.service_on', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function expiringRegistrations(string $throughDate): array
    {
        return $this->expiringVehicleRecord('registrations', 'expires_on', $throughDate);
    }

    /** @return array<int, array<string, mixed>> */
    public function expiringInsurance(string $throughDate): array
    {
        return $this->expiringVehicleRecord('insurance_policies', 'expires_on', $throughDate);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeLoans(): array
    {
        return $this->db->table('loans')
            ->select('loans.*, fv.fleet_code, fv.display_name, lookup_values.code AS status_code')
            ->join('fleet_vehicles fv', 'fv.id = loans.fleet_vehicle_id')
            ->join('lookup_values', 'lookup_values.id = loans.loan_status_lookup_value_id', 'left')
            ->where('loans.deleted_at', null)
            ->where('loans.paid_off_on', null)
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function vehiclesMissingPhotos(): array
    {
        return $this->vehiclesMissingRelation('vehicle_images');
    }

    /** @return array<int, array<string, mixed>> */
    public function vehiclesMissingDocuments(): array
    {
        return $this->vehiclesMissingRelation('vehicle_files');
    }

    /** @return array<int, array<string, mixed>> */
    public function vehiclesMissingTuroListings(): array
    {
        return $this->vehiclesMissingRelation('vehicle_turo_listings');
    }

    /** @return array<int, array<string, mixed>> */
    public function airportDeliveriesBetween(string $startsAt, string $endsAt): array
    {
        return $this->db->table('airport_deliveries deliveries')
            ->select('deliveries.*, fv.fleet_code, fv.display_name, airports.code AS airport_code, airports.name AS airport_name')
            ->join('fleet_vehicles fv', 'fv.id = deliveries.fleet_vehicle_id')
            ->join('airports', 'airports.id = deliveries.airport_id')
            ->where('deliveries.deleted_at', null)
            ->where('deliveries.scheduled_at >=', $startsAt)
            ->where('deliveries.scheduled_at <', $endsAt)
            ->orderBy('deliveries.scheduled_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function sumTableAmount(string $table, string $amountField, string $dateField, string $fromDate, string $toDate): float
    {
        $row = $this->db->table($table)
            ->select("SUM({$amountField}) AS total", false)
            ->where('deleted_at', null)
            ->where($dateField . ' >=', $fromDate)
            ->where($dateField . ' <', $toDate)
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    private function activeLoanPaymentTotal(): float
    {
        $row = $this->db->table('loans')
            ->select('SUM(monthly_payment) AS total', false)
            ->where('deleted_at', null)
            ->where('paid_off_on', null)
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    private function activeInsurancePremiumTotal(): float
    {
        $row = $this->db->table('insurance_policies')
            ->select('SUM(premium_amount) AS total', false)
            ->where('deleted_at', null)
            ->where('expires_on >=', date('Y-m-d'))
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    private function sumAll(string $table, string $amountField): float
    {
        $row = $this->db->table($table)
            ->select("SUM({$amountField}) AS total", false)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /** @return array<int, float> */
    private function sumByVehicle(string $table, string $amountField): array
    {
        $rows = $this->db->table($table)
            ->select('fleet_vehicle_id')
            ->select("SUM({$amountField}) AS total", false)
            ->where('deleted_at', null)
            ->groupBy('fleet_vehicle_id')
            ->get()
            ->getResultArray();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['fleet_vehicle_id']] = (float) ($row['total'] ?? 0);
        }

        return $totals;
    }

    /** @return array<int, array<string, mixed>> */
    private function expiringVehicleRecord(string $table, string $dateField, string $throughDate): array
    {
        return $this->db->table($table . ' records')
            ->select('records.*, fv.fleet_code, fv.display_name')
            ->join('fleet_vehicles fv', 'fv.id = records.fleet_vehicle_id')
            ->where('records.deleted_at', null)
            ->where('records.' . $dateField . ' <=', $throughDate)
            ->orderBy('records.' . $dateField, 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function vehiclesMissingRelation(string $relationTable): array
    {
        return $this->db->table('fleet_vehicles fv')
            ->select('fv.id, fv.fleet_code, fv.display_name')
            ->join($relationTable . ' relation', 'relation.fleet_vehicle_id = fv.id', 'left')
            ->where('fv.deleted_at', null)
            ->where('relation.id', null)
            ->orderBy('fv.sort_order', 'ASC')
            ->get()
            ->getResultArray();
    }
}
