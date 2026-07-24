<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\RevenueService;
use DateTimeImmutable;

class FleetStatisticsService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?RevenueService $revenueService = null,
        private readonly ?FleetCapacityService $capacityService = null,
    ) {
    }

    /** Returns executive fleet, revenue, utilization, equity, and ROI metrics. */
    public function summary(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $currentMonth = $this->currentMonth($asOf);
        $fleetValue = $this->fleetValue();

        return array_merge($this->fleetSize($asOf), [
            'current_month' => $currentMonth,
            'fleet_value' => $fleetValue,
            'premium_vs_base' => $this->premiumVsBase($asOf),
            'lifetime_revenue' => $this->lifetimeRevenue(),
            'lifetime_profit' => $this->lifetimeProfit(),
            'vehicle_roi' => $this->vehicleRoi(),
        ]);
    }

    /** Returns current-month executive revenue and utilization metrics. */
    public function currentMonth(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $month = $asOf->format('Y-m-01');
        $revenue = $this->revenue()->currentMonth($asOf);
        $periodStart = new DateTimeImmutable($month . ' 00:00:00');
        $periodEndExclusive = $asOf->modify('+1 day')->setTime(0, 0);
        $capacity = $this->capacity()->utilizationForRange($periodStart, $periodEndExclusive);
        $vehicleCount = max(1, count(array_filter(
            $this->repo()->fleetVehicles(),
            static fn (array $vehicle): bool => (bool) ($vehicle['is_available_for_booking'] ?? false),
        )));
        $availableDays = max(1, $capacity['available_vehicle_days']);
        $occupiedDays = max(0, $capacity['occupied_vehicle_days']);

        return array_merge($revenue, [
            'fleet_utilization' => $capacity['utilization'],
            'occupied_vehicle_days' => $occupiedDays,
            'available_vehicle_days' => $availableDays,
            'average_daily_rate' => $this->averageDailyRate((float) $revenue['completed_revenue'], (float) $occupiedDays),
            'revenue_per_available_day' => $this->revenuePerAvailableDay((float) $revenue['completed_revenue'], $availableDays),
            'revenue_per_vehicle' => $this->revenuePerAvailableDay((float) $revenue['completed_revenue'], $vehicleCount),
            'month' => $month,
        ]);
    }

    /** Returns per-vehicle revenue, trip days, utilization, ADR, RevPAD, and ROI. */
    public function vehiclePerformance(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $rows = $this->vehiclePerformanceRows($asOf);
        $daysElapsed = max(1, (int) $asOf->format('z') + 1);

        return array_map(static function (array $row) use ($daysElapsed): array {
            $billableDays = (float) ($row['billable_days'] ?? 0);
            $completedRevenue = (float) ($row['completed_revenue'] ?? 0);

            return array_merge($row, [
                'utilization' => self::utilization($billableDays, $daysElapsed),
                'average_daily_rate' => self::averageDailyRate($completedRevenue, $billableDays),
                'revenue_per_available_day' => self::revenuePerAvailableDay($completedRevenue, $daysElapsed),
            ]);
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function vehiclePerformanceRows(DateTimeImmutable $asOf): array
    {
        $allocationRows = $this->revenue()->byVehicle($asOf->format('Y-01-01'), $asOf->format('Y-m-01'));
        $revenueRows = $this->revenue()->operatingRevenueByVehicle(
            $asOf->format('Y-01-01'),
            $asOf->modify('first day of next month')->format('Y-m-d'),
        );
        $rowsByVehicle = [];

        foreach ($allocationRows as $row) {
            $fleetVehicleId = (int) ($row['fleet_vehicle_id'] ?? 0);
            if ($fleetVehicleId <= 0) {
                continue;
            }

            $rowsByVehicle[$fleetVehicleId] = $row;
        }

        foreach ($revenueRows as $row) {
            $fleetVehicleId = (int) ($row['fleet_vehicle_id'] ?? 0);
            if ($fleetVehicleId <= 0) {
                continue;
            }

            $rowsByVehicle[$fleetVehicleId] = array_merge($rowsByVehicle[$fleetVehicleId] ?? [], $row, [
                'completed_revenue' => (float) ($row['completed_revenue'] ?? 0),
                'host_payout' => (float) ($row['host_payout'] ?? 0),
            ]);
        }

        $rows = array_values($rowsByVehicle);
        usort($rows, static fn (array $left, array $right): int => (float) ($right['completed_revenue'] ?? 0) <=> (float) ($left['completed_revenue'] ?? 0));

        return $rows;
    }

    /** Returns tracked fleet value, loan balance, and equity. */
    public function fleetValue(): array
    {
        return $this->repo()->fleetCapital();
    }

    /** Returns premium fleet metrics compared to base fleet metrics. */
    public function premiumVsBase(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $month = $asOf->format('Y-m-01');

        return $this->revenue()->byPremiumBase($month, $month);
    }

    /** Returns tracked lifetime revenue across all reservation sources. */
    public function lifetimeRevenue(): float
    {
        return $this->revenue()->lifetimeOperatingRevenue();
    }

    /** Returns tracked lifetime profit after startup capital and known operating costs. */
    public function lifetimeProfit(): float
    {
        $capital = $this->fleetValue();

        return $this->lifetimeRevenue() - $capital['startup_costs'];
    }

    /** Returns per-vehicle ROI using lifetime revenue and tracked fleet capital. */
    public function vehicleRoi(): array
    {
        $capitalByVehicle = $this->repo()->fleetCapitalByVehicle();

        return array_map(static function (array $row) use ($capitalByVehicle): array {
            $fleetVehicleId = (int) ($row['fleet_vehicle_id'] ?? 0);
            $revenue = (float) ($row['host_payout'] ?? 0);
            $capital = (float) ($capitalByVehicle[$fleetVehicleId]['startup_costs'] ?? 0);

            return array_merge($row, [
                'startup_costs' => $capital,
                'loan_balance' => (float) ($capitalByVehicle[$fleetVehicleId]['loan_balance'] ?? 0),
                'roi' => self::vehicleReturnOnInvestment($revenue, $capital),
            ]);
        }, $this->repo()->lifetimeRevenueByVehicle());
    }

    private function fleetSize(DateTimeImmutable $asOf): array
    {
        $vehicles = $this->repo()->fleetVehicles();
        $activeReservations = $this->repo()->activeReservationCounts($asOf->format('Y-m-d H:i:s'));
        $maintenanceRequired = count(array_filter($vehicles, static fn (array $vehicle): bool => ($vehicle['status_code'] ?? '') === 'maintenance'));
        $outOfService = count(array_filter($vehicles, static fn (array $vehicle): bool => ! (bool) ($vehicle['is_available_for_booking'] ?? false)));
        $reserved = $activeReservations['reserved'];
        $inProgress = $activeReservations['in_progress'];

        return [
            'fleet_size' => count($vehicles),
            'available_vehicles' => max(0, count($vehicles) - $reserved - $inProgress - $outOfService),
            'reserved_vehicles' => $reserved,
            'in_progress_vehicles' => $inProgress,
            'maintenance_required' => $maintenanceRequired,
            'claim_open' => count($this->repo()->openClaims()),
            'vehicles_out_of_service' => $outOfService,
        ];
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    private function revenue(): RevenueService
    {
        return $this->revenueService ?? service('revenueService');
    }

    private function capacity(): FleetCapacityService
    {
        return $this->capacityService ?? new FleetCapacityService($this->repo());
    }

    private static function utilization(float $billableDays, int $availableDays): float
    {
        return $availableDays <= 0 ? 0.0 : round($billableDays / $availableDays, 4);
    }

    private static function averageDailyRate(float $completedRevenue, float $billableDays): float
    {
        return $billableDays <= 0.0 ? 0.0 : round($completedRevenue / $billableDays, 2);
    }

    private static function revenuePerAvailableDay(float $completedRevenue, int $availableDays): float
    {
        return $availableDays <= 0 ? 0.0 : round($completedRevenue / $availableDays, 2);
    }

    private static function vehicleReturnOnInvestment(float $lifetimeRevenue, float $startupCosts): ?float
    {
        return $startupCosts <= 0.0 ? null : round(($lifetimeRevenue - $startupCosts) / $startupCosts, 4);
    }
}
