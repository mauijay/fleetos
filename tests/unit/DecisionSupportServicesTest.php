<?php

use App\Services\Fleet\DecisionSupport\BusinessInsightService;
use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Fleet\DecisionSupport\FleetOptimizationService;
use App\Services\Fleet\DecisionSupport\GuestRiskService;
use App\Services\Fleet\DecisionSupport\MaintenancePredictionService;
use App\Services\Fleet\DecisionSupport\PricingRecommendationService;
use App\Services\Fleet\DecisionSupport\RecommendationFactory;
use App\Services\Fleet\DecisionSupport\RevenueForecastService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TripAnalyticsService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\DecisionSupport;

/**
 * @internal
 */
final class DecisionSupportServicesTest extends CIUnitTestCase
{
    public function testPricingRecommendationExplainsIncreaseWithMeasuredMetrics(): void
    {
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['vehiclePerformance'])->getMock();
        $revenue = $this->getMockBuilder(RevenueService::class)->disableOriginalConstructor()->onlyMethods(['trends'])->getMock();
        $statistics->method('vehiclePerformance')->willReturn([
            ['fleet_code' => 'Spaceship-007', 'is_premium' => true, 'utilization' => 0.98, 'average_daily_rate' => 89.0, 'billable_days' => 90.0, 'completed_revenue' => 2000.0],
            ['fleet_code' => 'Spaceship-003', 'is_premium' => false, 'utilization' => 0.40, 'average_daily_rate' => 70.0, 'billable_days' => 30.0, 'completed_revenue' => 500.0],
        ]);
        $revenue->method('trends')->willReturn([
            ['allocation_month' => '2026-04-01', 'completed_revenue' => 1000.0],
            ['allocation_month' => '2026-05-01', 'completed_revenue' => 1000.0],
            ['allocation_month' => '2026-06-01', 'completed_revenue' => 1400.0],
        ]);

        $recommendations = (new PricingRecommendationService($statistics, $revenue, $this->config(), $this->factory()))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertSame('Increase Spaceship-007 pricing', $recommendations[0]->title);
        $this->assertSame('Pricing', $recommendations[0]->category);
        $this->assertSame(98, $recommendations[0]->metrics['occupancy']);
        $this->assertSame('Increase daily price by $5.', $recommendations[0]->action);
        $this->assertSame(PricingRecommendationService::class, $recommendations[0]->sourceService);
        $this->assertSame('Demand trend is increasing', $recommendations[2]->title);
    }

    public function testMaintenancePredictionUsesKnownOperationalRecordsOnly(): void
    {
        $health = $this->getMockBuilder(FleetHealthService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'claimsRequiringFollowUp', 'vehiclesNeedingCleaning'])
            ->getMock();
        $health->method('vehiclesDueForMaintenance')->willReturn([['fleet_vehicle_id' => 7, 'fleet_code' => 'Spaceship-007']]);
        $health->method('registrationExpiring')->willReturn([]);
        $health->method('insuranceExpiring')->willReturn([['fleet_vehicle_id' => 7, 'fleet_code' => 'Spaceship-007', 'expires_on' => '2026-07-01']]);
        $health->method('claimsRequiringFollowUp')->willReturn([]);
        $health->method('vehiclesNeedingCleaning')->willReturn([]);

        $recommendations = (new MaintenancePredictionService($health, $this->config(), $this->factory()))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertCount(2, $recommendations);
        $this->assertSame('Maintenance due for Spaceship-007', $recommendations[0]->title);
        $this->assertSame(30, $recommendations[0]->metrics['horizon_days']);
        $this->assertSame('Insurance expiring for Spaceship-007', $recommendations[1]->title);
    }

    public function testRevenueForecastCalculatesThirtySixtyNinetyDayProjection(): void
    {
        $revenue = $this->getMockBuilder(RevenueService::class)->disableOriginalConstructor()->onlyMethods(['period'])->getMock();
        $revenue->method('period')->willReturn([
            'completed_revenue' => 9000.0,
            'operating_costs' => ['maintenance' => 600.0, 'charging' => 300.0, 'airport_parking' => 0.0, 'loan_payments' => 1500.0, 'insurance_premiums' => 600.0],
            'months' => [['allocation_month' => '2026-04-01'], ['allocation_month' => '2026-05-01'], ['allocation_month' => '2026-06-01']],
        ]);

        $service = new RevenueForecastService($revenue, $this->config(), $this->factory());
        $forecast = $service->forecast(new DateTimeImmutable('2026-06-15 08:00:00'));
        $recommendation = $service->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'))[0];

        $this->assertSame(3000.0, $forecast['forecast_30_day']);
        $this->assertSame(6000.0, $forecast['forecast_60_day']);
        $this->assertSame(9000.0, $forecast['forecast_90_day']);
        $this->assertSame(2000.0, $forecast['cash_flow_30_day']);
        $this->assertSame(85, $recommendation->confidence);
        $this->assertNotEmpty($forecast['assumptions']);
    }

    public function testBusinessInsightsReferencePremiumRevenueAndZeroRevenue(): void
    {
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['summary', 'vehiclePerformance'])->getMock();
        $statistics->method('summary')->willReturn([
            'fleet_size' => 2,
            'current_month' => ['completed_revenue' => 0.0],
            'lifetime_profit' => -5000.0,
            'lifetime_revenue' => 1000.0,
        ]);
        $statistics->method('vehiclePerformance')->willReturn([
            ['fleet_code' => 'Spaceship-007', 'is_premium' => true, 'completed_revenue' => 1800.0],
            ['fleet_code' => 'Spaceship-003', 'is_premium' => false, 'completed_revenue' => 200.0],
        ]);

        $insights = (new BusinessInsightService($statistics, $this->config(), $this->factory()))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertSame('Premium inventory is producing outsized revenue', $insights[0]->title);
        $this->assertSame(90, $insights[0]->metrics['premium_revenue_share']);
        $this->assertSame('Revenue has not recovered recorded startup costs', $insights[1]->title);
        $this->assertSame('Fleet has no completed revenue this month', $insights[2]->title);
    }

    public function testFleetOptimizationHandlesIdleSingleAndLargeFleetScenarios(): void
    {
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['vehiclePerformance'])->getMock();
        $largeFleet = [];

        for ($vehicle = 1; $vehicle <= 20; $vehicle++) {
            $largeFleet[] = [
                'fleet_code' => 'Spaceship-' . str_pad((string) $vehicle, 3, '0', STR_PAD_LEFT),
                'is_premium' => $vehicle <= 10,
                'utilization' => $vehicle === 20 ? 0.0 : 0.82,
                'completed_revenue' => $vehicle === 1 ? 9000.0 : ($vehicle === 20 ? 0.0 : 1000.0),
            ];
        }

        $statistics->method('vehiclePerformance')->willReturn($largeFleet);

        $recommendations = (new FleetOptimizationService($statistics, $this->config(), $this->factory()))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));
        $titles = array_map(static fn ($recommendation): string => $recommendation->title, $recommendations);

        $this->assertContains('Investigate idle Spaceship-020', $titles);
        $this->assertContains('Premium fleet is operating near capacity', $titles);
        $this->assertNotEmpty($recommendations);
    }

    public function testEmptyFleetReturnsNoDecisionSupportRecommendations(): void
    {
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['vehiclePerformance'])->getMock();
        $statistics->method('vehiclePerformance')->willReturn([]);
        $revenue = $this->getMockBuilder(RevenueService::class)->disableOriginalConstructor()->onlyMethods(['trends'])->getMock();
        $revenue->method('trends')->willReturn([]);

        $this->assertSame([], (new PricingRecommendationService($statistics, $revenue, $this->config(), $this->factory()))->recommendations(new DateTimeImmutable('2026-06-15')));
        $this->assertSame([], (new FleetOptimizationService($statistics, $this->config(), $this->factory()))->recommendations(new DateTimeImmutable('2026-06-15')));
    }

    public function testGuestRiskCoversCancelledTripsRepeatGuestsAndLongTermRentals(): void
    {
        $analytics = $this->getMockBuilder(TripAnalyticsService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $analytics->method('summary')->willReturn([
            'trip_count' => 5,
            'cancellation_rate' => 0.4,
            'average_trip_length' => 16.0,
            'repeat_guests' => [['guest_name' => 'Repeat Guest', 'trip_count' => 3]],
        ]);

        $recommendations = (new GuestRiskService($analytics, $this->config(), $this->factory()))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertSame('Review guest cancellation exposure', $recommendations[0]->title);
        $this->assertSame(40, $recommendations[0]->metrics['cancellation_rate']);
        $this->assertSame('Review long-term rental exposure', $recommendations[1]->title);
        $this->assertSame('Prioritize repeat guest Repeat Guest', $recommendations[2]->title);
    }

    public function testZeroRevenueDoesNotCreateRevenueForecastRecommendation(): void
    {
        $revenue = $this->getMockBuilder(RevenueService::class)->disableOriginalConstructor()->onlyMethods(['period'])->getMock();
        $revenue->method('period')->willReturn([
            'completed_revenue' => 0.0,
            'operating_costs' => ['maintenance' => 0.0, 'charging' => 0.0, 'airport_parking' => 0.0, 'loan_payments' => 0.0, 'insurance_premiums' => 0.0],
            'months' => [],
        ]);

        $this->assertSame([], (new RevenueForecastService($revenue, $this->config(), $this->factory()))->recommendations(new DateTimeImmutable('2026-06-15')));
    }

    public function testDashboardServiceExposesCategorizedRecommendationDtos(): void
    {
        $asOf = new DateTimeImmutable('2026-06-15 08:00:00');
        $pricing = $this->getMockBuilder(PricingRecommendationService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $maintenance = $this->getMockBuilder(MaintenancePredictionService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $optimization = $this->getMockBuilder(FleetOptimizationService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $revenue = $this->getMockBuilder(RevenueForecastService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $guestRisk = $this->getMockBuilder(GuestRiskService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $insights = $this->getMockBuilder(BusinessInsightService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();

        $pricing->expects($this->once())->method('recommendations')->willReturn([$this->factory()->make('Pricing rec', 'Pricing', 'Medium', 80, 'Measured pricing reason.', ['occupancy' => 90], 'Change price.', $asOf, PricingRecommendationService::class)]);
        $maintenance->expects($this->once())->method('recommendations')->willReturn([$this->factory()->make('Maintenance rec', 'Maintenance', 'High', 85, 'Known maintenance reason.', ['fleet_vehicle_id' => 7], 'Schedule service.', $asOf, MaintenancePredictionService::class)]);
        $optimization->expects($this->once())->method('recommendations')->willReturn([]);
        $revenue->expects($this->once())->method('recommendations')->willReturn([]);
        $guestRisk->expects($this->once())->method('recommendations')->willReturn([]);
        $insights->expects($this->once())->method('recommendations')->willReturn([]);

        $dashboard = (new DecisionSupportDashboardService($pricing, $maintenance, $optimization, $revenue, $guestRisk, $insights))->recommendations($asOf);

        $this->assertSame('Maintenance rec', $dashboard['todays_recommendations'][0]['title']);
        $this->assertSame('Pricing rec', $dashboard['pricing'][0]['title']);
        $this->assertSame('Maintenance rec', $dashboard['maintenance'][0]['title']);
        $this->assertSame([], $dashboard['fleet_health']);
    }

    private function config(): DecisionSupport
    {
        return new DecisionSupport();
    }

    private function factory(): RecommendationFactory
    {
        return new RecommendationFactory($this->config());
    }
}
