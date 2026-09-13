<?php

namespace App\Services\Fleet\DecisionSupport;

use App\DTOs\Fleet\Recommendation;
use DateTimeImmutable;

class DecisionSupportDashboardService
{
    public function __construct(
        private readonly ?PricingRecommendationService $pricingService = null,
        private readonly ?MaintenancePredictionService $maintenanceService = null,
        private readonly ?FleetOptimizationService $optimizationService = null,
        private readonly ?RevenueForecastService $revenueForecastService = null,
        private readonly ?GuestRiskService $guestRiskService = null,
        private readonly ?BusinessInsightService $businessInsightService = null,
    ) {
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function recommendations(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $categories = $this->categories($asOf);

        return [
            'todays_recommendations' => $this->serialize($this->topRecommendations($categories)),
            'pricing' => $this->serialize($categories['pricing']),
            'maintenance' => $this->serialize($categories['maintenance']),
            'fleet_health' => $this->serialize($categories['fleet_health']),
            'revenue' => $this->serialize($categories['revenue']),
            'guest_risk' => $this->serialize($categories['guest_risk']),
            'business_insights' => $this->serialize($categories['business_insights']),
        ];
    }

    /** @return array<string, array<int, Recommendation>> */
    private function categories(DateTimeImmutable $asOf): array
    {
        return [
            // Financial/per-vehicle recommendations are deferred until the
            // company-scoped vehicle read model lands in 3B.3B-2.
            'pricing' => [],
            'maintenance' => $this->maintenance()->recommendations($asOf),
            'fleet_health' => [],
            'revenue' => [],
            'guest_risk' => $this->guestRisk()->recommendations($asOf),
            'business_insights' => [],
        ];
    }

    /** @return array<int, Recommendation> */
    private function topRecommendations(array $categories): array
    {
        $recommendations = array_merge(...array_values($categories));

        usort($recommendations, static function (Recommendation $left, Recommendation $right): int {
            $priorityOrder = ['Critical' => 5, 'High' => 4, 'Medium' => 3, 'Low' => 2, 'Informational' => 1];
            $priorityComparison = ($priorityOrder[$right->priority] ?? 0) <=> ($priorityOrder[$left->priority] ?? 0);

            return $priorityComparison !== 0 ? $priorityComparison : $right->confidence <=> $left->confidence;
        });

        return array_slice($recommendations, 0, 6);
    }

    /** @param array<int, Recommendation> $recommendations */
    private function serialize(array $recommendations): array
    {
        return array_map(static fn (Recommendation $recommendation): array => $recommendation->toArray(), $recommendations);
    }

    protected function pricing(): PricingRecommendationService
    {
        return $this->pricingService ?? service('pricingRecommendationService');
    }

    private function maintenance(): MaintenancePredictionService
    {
        return $this->maintenanceService ?? service('maintenancePredictionService');
    }

    protected function optimization(): FleetOptimizationService
    {
        return $this->optimizationService ?? service('fleetOptimizationService');
    }

    protected function revenueForecast(): RevenueForecastService
    {
        return $this->revenueForecastService ?? service('revenueForecastService');
    }

    private function guestRisk(): GuestRiskService
    {
        return $this->guestRiskService ?? service('guestRiskService');
    }

    protected function businessInsights(): BusinessInsightService
    {
        return $this->businessInsightService ?? service('businessInsightService');
    }

}
