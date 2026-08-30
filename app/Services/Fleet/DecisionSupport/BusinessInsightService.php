<?php

namespace App\Services\Fleet\DecisionSupport;

use App\DTOs\Fleet\Recommendation;
use App\Services\Fleet\FleetStatisticsService;
use Config\DecisionSupport;
use DateTimeImmutable;

class BusinessInsightService
{
    public function __construct(
        private readonly ?FleetStatisticsService $statisticsService = null,
        private readonly ?DecisionSupport $config = null,
        private readonly ?RecommendationFactory $factory = null,
    ) {
    }

    /** @return array<int, Recommendation> */
    public function recommendations(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $summary = $this->statistics()->summary($asOf);
        $vehicles = $this->statistics()->vehiclePerformance($asOf);

        return array_values(array_merge(
            $this->premiumRevenueInsight($vehicles, $asOf),
            $this->profitInsight($summary, $asOf),
            $this->zeroRevenueInsight($summary, $asOf),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function insights(?DateTimeImmutable $asOf = null): array
    {
        return array_map(static fn (Recommendation $recommendation): array => $recommendation->toArray(), $this->recommendations($asOf));
    }

    /** @param array<int, array<string, mixed>> $vehicles */
    private function premiumRevenueInsight(array $vehicles, DateTimeImmutable $asOf): array
    {
        if ($vehicles === []) {
            return [];
        }

        $premiumVehicles = 0;
        $premiumRevenue = 0.0;
        $totalRevenue = 0.0;

        foreach ($vehicles as $vehicle) {
            $revenue = (float) ($vehicle['completed_revenue'] ?? $vehicle['host_payout'] ?? 0);
            $totalRevenue += $revenue;

            if ((bool) ($vehicle['is_premium'] ?? false)) {
                $premiumVehicles++;
                $premiumRevenue += $revenue;
            }
        }

        if ($totalRevenue <= 0.0) {
            return [];
        }

        $premiumInventoryShare = $premiumVehicles / count($vehicles);
        $premiumRevenueShare = $premiumRevenue / $totalRevenue;

        if ($premiumRevenueShare <= $premiumInventoryShare) {
            return [];
        }

        return [$this->factory()->make(
            'Premium inventory is producing outsized revenue',
            'Business Insight',
            'Informational',
            86,
            'Premium vehicles generated more revenue share than their inventory share.',
            ['premium_revenue_share' => $this->percent($premiumRevenueShare), 'premium_inventory_share' => $this->percent($premiumInventoryShare), 'premium_revenue' => round($premiumRevenue, 2)],
            'Continue measuring premium expansion opportunities before purchasing additional inventory.',
            $asOf,
            self::class,
        )];
    }

    /** @param array<string, mixed> $summary */
    private function profitInsight(array $summary, DateTimeImmutable $asOf): array
    {
        $profit = (float) ($summary['lifetime_profit'] ?? 0);

        if ($profit >= 0.0) {
            return [];
        }

        return [$this->factory()->make(
            'Revenue has not recovered recorded startup costs',
            'Business Insight',
            'High',
            90,
            'Tracked lifetime revenue does not yet exceed startup capital.',
            ['revenue_less_recorded_startup_costs' => round($profit, 2), 'lifetime_revenue' => round((float) ($summary['lifetime_revenue'] ?? 0), 2)],
            'Prioritize revenue growth and cost control before expanding fleet capital commitments.',
            $asOf,
            self::class,
        )];
    }

    /** @param array<string, mixed> $summary */
    private function zeroRevenueInsight(array $summary, DateTimeImmutable $asOf): array
    {
        $currentMonth = $summary['current_month'] ?? [];

        if ((float) ($currentMonth['completed_revenue'] ?? 0) > 0.0 || (int) ($summary['fleet_size'] ?? 0) === 0) {
            return [];
        }

        return [$this->factory()->make(
            'Fleet has no completed revenue this month',
            'Business Insight',
            'High',
            82,
            'Active fleet inventory exists but current-month completed revenue is zero.',
            ['fleet_size' => (int) ($summary['fleet_size'] ?? 0), 'completed_revenue' => 0.0],
            'Review listing availability, pricing, and upcoming reservations immediately.',
            $asOf,
            self::class,
        )];
    }

    private function percent(float $value): int
    {
        return (int) round($value * 100);
    }

    private function statistics(): FleetStatisticsService
    {
        return $this->statisticsService ?? service('fleetStatisticsService');
    }

    private function config(): DecisionSupport
    {
        return $this->config ?? config(DecisionSupport::class);
    }

    private function factory(): RecommendationFactory
    {
        return $this->factory ?? new RecommendationFactory($this->config());
    }
}
