<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use DateTimeImmutable;

class RevenueService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?TuroNormalizedTransactionRepository $transactionRepository = null,
    ) {
    }

    /** Returns current-month revenue, forecast, cost, and profit metrics. */
    public function currentMonth(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->period($asOf->modify('first day of this month')->format('Y-m-01'), $asOf->format('Y-m-01'));
    }

    /** Returns previous-month revenue, forecast, cost, and profit metrics. */
    public function previousMonth(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $month = $asOf->modify('first day of previous month')->format('Y-m-01');

        return $this->period($month, $month);
    }

    /** Returns year-to-date revenue, forecast, cost, and profit metrics. */
    public function yearToDate(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->period($asOf->format('Y-01-01'), $asOf->format('Y-m-01'));
    }

    /** Returns normalized financial metrics for an inclusive month range. */
    public function period(string $fromMonth, string $toMonth): array
    {
        $monthlyRows = $this->repo()->revenueMonthly($fromMonth, $toMonth);
        $fromDate = $fromMonth;
        $toDate = (new DateTimeImmutable($toMonth))->modify('first day of next month')->format('Y-m-d');
        $costs = $this->repo()->operatingCosts($fromDate, $toDate);
        $costSignals = $this->repo()->operatingCostSignals($fromDate, $toDate);
        $totals = $this->sumRevenueRows($monthlyRows);
        $totals['completed_revenue'] = round($this->transactions()->operatingRevenueInPeriod($fromDate, $toDate), 2);
        $totals['total_revenue'] = $totals['completed_revenue'] + $totals['forecast_revenue'];
        $operatingCosts = array_sum($costs);
        $hasCostData = (bool) ($costSignals['has_operating_cost_data'] ?? false);
        $cashFlow = $hasCostData ? $totals['completed_revenue'] + $totals['forecast_revenue'] - $operatingCosts : null;
        $operatingProfit = $hasCostData ? $totals['completed_revenue'] - $operatingCosts : null;

        return array_merge($totals, [
            'months' => $monthlyRows,
            'cash_flow' => $cashFlow,
            'cash_flow_state' => $hasCostData ? 'calculated' : 'pending',
            'operating_costs' => $costs,
            'operating_profit' => $operatingProfit,
            'operating_profit_state' => $hasCostData ? 'calculated' : 'pending',
            'cost_signals' => $costSignals,
            'startup_cost_amortization' => $this->startupCostAmortization($fromMonth, $toMonth),
        ]);
    }

    /** Returns total forecast revenue for the requested month range. */
    public function forecastRevenue(string $fromMonth, string $toMonth): float
    {
        return $this->period($fromMonth, $toMonth)['forecast_revenue'];
    }

    /** Returns earned revenue from non-forecast allocations for the requested month range. */
    public function completedRevenue(string $fromMonth, string $toMonth): float
    {
        return $this->period($fromMonth, $toMonth)['completed_revenue'];
    }

    /** Returns cancelled revenue exposure for the requested date range. */
    public function cancelledRevenue(string $fromDate, string $toDate): float
    {
        $rows = $this->repo()->reservationsBetween($fromDate, $toDate);

        return array_reduce($rows, static function (float $total, array $row): float {
            return in_array($row['status_code'] ?? '', ['canceled_zero_payout', 'canceled_host_payout'], true)
                ? $total + (float) ($row['host_payout_amount'] ?? 0)
                : $total;
        }, 0.0);
    }

    /** Returns revenue grouped by vehicle for the requested month range. */
    public function byVehicle(string $fromMonth, string $toMonth): array
    {
        return $this->repo()->revenueByVehicle($fromMonth, $toMonth);
    }

    /** Returns revenue grouped by fleet ownership company for the requested month range. */
    public function byFleet(string $fromMonth, string $toMonth): array
    {
        return [[
            'fleet' => 'all',
            'metrics' => $this->period($fromMonth, $toMonth),
        ]];
    }

    /** Returns revenue grouped by vehicle model/type for the requested month range. */
    public function byVehicleType(string $fromMonth, string $toMonth): array
    {
        return $this->groupRevenueRows($this->byVehicle($fromMonth, $toMonth), 'vehicle_type');
    }

    /** Returns revenue grouped by premium and base fleet segments. */
    public function byPremiumBase(string $fromMonth, string $toMonth): array
    {
        $fromDate = $fromMonth;
        $toDate = (new DateTimeImmutable($toMonth))->modify('first day of next month')->format('Y-m-d');

        return $this->transactions()->operatingRevenueByPremiumBaseInPeriod($fromDate, $toDate);
    }

    /** Returns monthly revenue trends for the requested inclusive month range. */
    public function trends(string $fromMonth, string $toMonth): array
    {
        return $this->repo()->revenueMonthly($fromMonth, $toMonth);
    }

    /** Returns cash-flow metrics for the requested inclusive month range. */
    public function cashFlow(string $fromMonth, string $toMonth): array
    {
        $period = $this->period($fromMonth, $toMonth);

        return [
            'cash_flow' => $period['cash_flow'],
            'completed_revenue' => $period['completed_revenue'],
            'forecast_revenue' => $period['forecast_revenue'],
            'operating_costs' => $period['operating_costs'],
        ];
    }

    /** Returns operating profit for the requested inclusive month range. */
    public function operatingProfit(string $fromMonth, string $toMonth): float
    {
        return (float) ($this->period($fromMonth, $toMonth)['operating_profit'] ?? 0.0);
    }

    public function lifetimeOperatingRevenue(): float
    {
        return round($this->transactions()->lifetimeOperatingRevenue(), 2);
    }

    /** Returns estimated startup-cost amortization for the requested month range. */
    public function startupCostAmortization(string $fromMonth, string $toMonth, int $months = 36): float
    {
        $capital = $this->repo()->fleetCapital();
        $monthCount = $this->inclusiveMonthCount($fromMonth, $toMonth);

        return $months <= 0 ? 0.0 : round(($capital['startup_costs'] / $months) * $monthCount, 2);
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    private function transactions(): TuroNormalizedTransactionRepository
    {
        return $this->transactionRepository ?? new TuroNormalizedTransactionRepository();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sumRevenueRows(array $rows): array
    {
        $totals = [
            'trip_days' => 0.0,
            'billable_days' => 0.0,
            'gross_revenue' => 0.0,
            'completed_revenue' => 0.0,
            'forecast_revenue' => 0.0,
            'delivery_fees' => 0.0,
            'reimbursements' => 0.0,
        ];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (float) ($row[$key] ?? 0);
            }
        }

        $totals['total_revenue'] = $totals['completed_revenue'] + $totals['forecast_revenue'];

        return $totals;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function groupRevenueRows(array $rows, string $groupKey): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $groupKey === 'is_premium'
                ? ((bool) ($row[$groupKey] ?? false) ? 'premium' : 'base')
                : (string) ($row[$groupKey] ?? 'unknown');

            $groups[$key] ??= [
                'group' => $key,
                'trip_days' => 0.0,
                'billable_days' => 0.0,
                'gross_revenue' => 0.0,
                'completed_revenue' => 0.0,
                'forecast_revenue' => 0.0,
                'host_payout' => 0.0,
            ];

            foreach (['trip_days', 'billable_days', 'gross_revenue', 'completed_revenue', 'forecast_revenue', 'host_payout'] as $metric) {
                $groups[$key][$metric] += (float) ($row[$metric] ?? 0);
            }
        }

        return array_values($groups);
    }

    private function inclusiveMonthCount(string $fromMonth, string $toMonth): int
    {
        $from = new DateTimeImmutable($fromMonth);
        $to = new DateTimeImmutable($toMonth);

        return ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'))
            + 1;
    }
}
