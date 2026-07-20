<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetCommandService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripAnalyticsService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @internal
 */
final class FleetIntelligenceServicesTest extends CIUnitTestCase
{
    public function testRevenueServiceSeparatesCompletedForecastAndCancelledRevenue(): void
    {
        $repository = $this->repositoryMock(['revenueMonthly', 'operatingCosts', 'fleetCapital', 'reservationsBetween']);
        $repository->method('revenueMonthly')->willReturn([
            [
                'allocation_month' => '2026-06-01',
                'trip_days' => '7.000',
                'billable_days' => '7.000',
                'gross_revenue' => '1400.00',
                'completed_revenue' => '800.00',
                'forecast_revenue' => '320.00',
                'delivery_fees' => '100.00',
                'reimbursements' => '40.00',
            ],
        ]);
        $repository->method('operatingCosts')->willReturn([
            'maintenance' => 100.0,
            'charging' => 25.0,
            'airport_parking' => 15.0,
            'loan_payments' => 300.0,
            'insurance_premiums' => 60.0,
        ]);
        $repository->method('fleetCapital')->willReturn(['startup_costs' => 36000.0]);
        $repository->method('reservationsBetween')->willReturn([
            ['status_code' => 'completed', 'host_payout_amount' => '400.00'],
            ['status_code' => 'canceled_host_payout', 'host_payout_amount' => '75.00'],
            ['status_code' => 'canceled_zero_payout', 'host_payout_amount' => '0.00'],
        ]);

        $service = new RevenueService($repository);
        $currentMonth = $service->currentMonth(new DateTimeImmutable('2026-06-15 12:00:00'));

        $this->assertSame(800.0, $currentMonth['completed_revenue']);
        $this->assertSame(320.0, $currentMonth['forecast_revenue']);
        $this->assertSame(620.0, $currentMonth['cash_flow']);
        $this->assertSame(300.0, $currentMonth['operating_profit']);
        $this->assertSame(1000.0, $currentMonth['startup_cost_amortization']);
        $this->assertSame(75.0, $service->cancelledRevenue('2026-06-01', '2026-07-01'));
    }

    public function testRevenueServiceGroupsPremiumBaseAndVehicleTypes(): void
    {
        $repository = $this->repositoryMock(['revenueByVehicle']);
        $repository->method('revenueByVehicle')->willReturn([
            ['vehicle_type' => 'Model Y', 'is_premium' => true, 'trip_days' => '5.000', 'billable_days' => '5.000', 'gross_revenue' => '1000.00', 'completed_revenue' => '800.00', 'forecast_revenue' => '0.00', 'host_payout' => '800.00'],
            ['vehicle_type' => 'Model 3', 'is_premium' => false, 'trip_days' => '3.000', 'billable_days' => '3.000', 'gross_revenue' => '450.00', 'completed_revenue' => '360.00', 'forecast_revenue' => '120.00', 'host_payout' => '480.00'],
        ]);

        $service = new RevenueService($repository);

        $this->assertSame('premium', $service->byPremiumBase('2026-01-01', '2026-06-01')[0]['group']);
        $this->assertSame('Model Y', $service->byVehicleType('2026-01-01', '2026-06-01')[0]['group']);
    }

    public function testFleetStatisticsServiceReturnsExecutiveMetricsForMixedFleet(): void
    {
        $repository = $this->repositoryMock([
            'fleetVehicles',
            'activeReservationCounts',
            'openClaims',
            'revenueMonthly',
            'operatingCosts',
            'fleetCapital',
            'fleetCapitalByVehicle',
            'revenueByVehicle',
            'lifetimeRevenueByVehicle',
        ]);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 1, 'status_code' => 'active', 'is_available_for_booking' => true],
            ['id' => 2, 'status_code' => 'maintenance', 'is_available_for_booking' => false],
            ['id' => 3, 'status_code' => 'active', 'is_available_for_booking' => true],
        ]);
        $repository->method('activeReservationCounts')->willReturn(['reserved' => 1, 'in_progress' => 1]);
        $repository->method('openClaims')->willReturn([['id' => 10]]);
        $repository->method('revenueMonthly')->willReturn([['billable_days' => '10.000', 'completed_revenue' => '1000.00', 'forecast_revenue' => '500.00']]);
        $repository->method('operatingCosts')->willReturn(['maintenance' => 0.0, 'charging' => 0.0, 'airport_parking' => 0.0, 'loan_payments' => 0.0, 'insurance_premiums' => 0.0]);
        $repository->method('fleetCapital')->willReturn(['fleet_value' => 90000.0, 'loan_balance' => 50000.0, 'fleet_equity' => 40000.0, 'startup_costs' => 90000.0]);
        $repository->method('fleetCapitalByVehicle')->willReturn([8 => ['startup_costs' => 5000.0, 'loan_balance' => 1000.0]]);
        $repository->method('revenueByVehicle')->willReturn([['fleet_vehicle_id' => 8, 'is_premium' => true, 'host_payout' => '1000.00']]);
        $repository->method('lifetimeRevenueByVehicle')->willReturn([['fleet_vehicle_id' => 8, 'host_payout' => '10000.00']]);

        $summary = (new FleetStatisticsService($repository, new RevenueService($repository)))->summary(new DateTimeImmutable('2026-06-10'));

        $this->assertSame(3, $summary['fleet_size']);
        $this->assertSame(0, $summary['available_vehicles']);
        $this->assertSame(1, $summary['maintenance_required']);
        $this->assertSame(1, $summary['claim_open']);
        $this->assertSame(90000.0, $summary['fleet_value']['fleet_value']);
        $this->assertSame(10000.0, $summary['lifetime_revenue']);
        $this->assertSame(1.0, $summary['vehicle_roi'][0]['roi']);
    }

    public function testFleetStatisticsServiceReturnsNullRoiWhenVehicleCapitalIsMissing(): void
    {
        $repository = $this->repositoryMock(['lifetimeRevenueByVehicle', 'fleetCapitalByVehicle']);
        $repository->method('lifetimeRevenueByVehicle')->willReturn([['fleet_vehicle_id' => 99, 'host_payout' => '1000.00']]);
        $repository->method('fleetCapitalByVehicle')->willReturn([]);

        $roi = (new FleetStatisticsService($repository))->vehicleRoi();

        $this->assertNull($roi[0]['roi']);
        $this->assertSame(0.0, $roi[0]['startup_costs']);
    }

    public function testFleetHealthServiceFindsSetupGapsAndOperationalAlerts(): void
    {
        $repository = $this->repositoryMock([
            'reservationsBetween',
            'maintenanceDue',
            'expiringRegistrations',
            'expiringInsurance',
            'activeLoans',
            'openClaims',
            'vehiclesMissingPhotos',
            'vehiclesMissingDocuments',
            'vehiclesMissingTuroListings',
        ]);
        $repository->method('reservationsBetween')->willReturn([['fleet_vehicle_id' => 1, 'ends_at' => '2026-06-15 09:00:00', 'status_code' => 'completed']]);
        $repository->method('maintenanceDue')->willReturn([['fleet_vehicle_id' => 1]]);
        $repository->method('expiringRegistrations')->willReturn([['fleet_vehicle_id' => 2]]);
        $repository->method('expiringInsurance')->willReturn([['fleet_vehicle_id' => 3]]);
        $repository->method('activeLoans')->willReturn([['fleet_vehicle_id' => 4, 'monthly_payment' => '525.00']]);
        $repository->method('openClaims')->willReturn([['fleet_vehicle_id' => 5]]);
        $repository->method('vehiclesMissingPhotos')->willReturn([['id' => 6, 'fleet_code' => 'Spaceship-006']]);
        $repository->method('vehiclesMissingDocuments')->willReturn([['id' => 6, 'fleet_code' => 'Spaceship-006']]);
        $repository->method('vehiclesMissingTuroListings')->willReturn([['id' => 7, 'fleet_code' => 'Spaceship-007']]);

        $summary = (new FleetHealthService($repository))->summary(new DateTimeImmutable('2026-06-15 12:00:00'));

        $this->assertCount(1, $summary['vehicles_needing_cleaning']);
        $this->assertCount(1, $summary['vehicles_due_for_maintenance']);
        $this->assertSame(525.0, $summary['loan_payment_due'][0]['amount_due']);
        $this->assertSame(['photos', 'documents'], $summary['incomplete_vehicle_setup'][0]['missing']);
        $this->assertSame([], $summary['vehicles_below_battery_threshold']);
    }

    public function testVehicleAvailabilityServiceReturnsOperationalStatus(): void
    {
        $repository = $this->repositoryMock(['fleetVehicles', 'reservationsBetween', 'airportDeliveriesBetween']);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 1, 'fleet_code' => 'Spaceship-001', 'display_name' => 'Spaceship-001', 'is_available_for_booking' => true, 'odometer_miles' => 1200],
            ['id' => 2, 'fleet_code' => 'Spaceship-002', 'display_name' => 'Spaceship-002', 'is_available_for_booking' => true, 'odometer_miles' => 2200],
        ]);
        $repository->method('reservationsBetween')->willReturn([
            ['fleet_vehicle_id' => 1, 'starts_at' => '2026-06-15 08:00:00', 'ends_at' => '2026-06-16 10:00:00', 'status_code' => 'in_progress'],
            ['fleet_vehicle_id' => 2, 'starts_at' => '2026-06-20 08:00:00', 'ends_at' => '2026-06-22 10:00:00', 'status_code' => 'booked'],
        ]);
        $repository->method('airportDeliveriesBetween')->willReturn([['fleet_vehicle_id' => 2, 'scheduled_at' => '2026-06-20 07:00:00']]);

        $service = new VehicleAvailabilityService($repository);
        $statuses = $service->vehicleStatus(new DateTimeImmutable('2026-06-15 12:00:00'));

        $this->assertSame('in_progress', $statuses[0]['status']);
        $this->assertSame('available', $statuses[1]['status']);
        $this->assertTrue($statuses[1]['airport_delivery_scheduled']);
        $this->assertCount(1, $service->availableNow(new DateTimeImmutable('2026-06-15 12:00:00')));
    }

    public function testTripAnalyticsServiceAggregatesTripMetrics(): void
    {
        $repository = $this->repositoryMock(['tripAnalytics', 'repeatedGuests']);
        $repository->method('tripAnalytics')->willReturn([
            ['trip_count' => 2, 'trip_days' => '5.000', 'billable_days' => '4.000', 'cancelled_trips' => 1, 'airport_deliveries' => 1, 'home_deliveries' => 1, 'charging_events' => 2, 'longest_trip' => '3.000', 'shortest_trip' => '2.000'],
            ['trip_count' => 1, 'trip_days' => '10.000', 'billable_days' => '10.000', 'cancelled_trips' => 0, 'airport_deliveries' => 0, 'home_deliveries' => 1, 'charging_events' => 1, 'longest_trip' => '10.000', 'shortest_trip' => '10.000'],
        ]);
        $repository->method('repeatedGuests')->willReturn([['guest_name' => 'Repeat Guest', 'trip_count' => 2]]);

        $summary = (new TripAnalyticsService($repository))->summary(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-07-01'));

        $this->assertSame(3, $summary['trip_count']);
        $this->assertSame(15.0, $summary['trip_days']);
        $this->assertSame(10.0, $summary['longest_trip']);
        $this->assertSame(0.3333, $summary['cancellation_rate']);
        $this->assertCount(1, $summary['repeat_guests']);
    }

    public function testServicesReturnPredictableEmptyStructuresForEmptyDatasets(): void
    {
        $repository = $this->repositoryMock([
            'revenueMonthly',
            'operatingCosts',
            'fleetCapital',
            'tripAnalytics',
            'repeatedGuests',
        ]);
        $repository->method('revenueMonthly')->willReturn([]);
        $repository->method('operatingCosts')->willReturn([
            'maintenance' => 0.0,
            'charging' => 0.0,
            'airport_parking' => 0.0,
            'loan_payments' => 0.0,
            'insurance_premiums' => 0.0,
        ]);
        $repository->method('fleetCapital')->willReturn(['startup_costs' => 0.0]);
        $repository->method('tripAnalytics')->willReturn([]);
        $repository->method('repeatedGuests')->willReturn([]);

        $revenue = (new RevenueService($repository))->period('2026-06-01', '2026-06-01');
        $analytics = (new TripAnalyticsService($repository))->summary(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-07-01'));

        $this->assertSame(0.0, $revenue['completed_revenue']);
        $this->assertSame(0.0, $revenue['forecast_revenue']);
        $this->assertSame([], $revenue['months']);
        $this->assertSame(0, $analytics['trip_count']);
        $this->assertSame(0.0, $analytics['utilization']);
        $this->assertSame([], $analytics['repeat_guests']);
        $this->assertNull($analytics['average_review']);
        $this->assertSame([], $analytics['battery_violations']);
    }

    public function testTaskServiceReturnsTodayAndHighPriorityWork(): void
    {
        $repository = $this->repositoryMock(['reservationsBetween', 'airportDeliveriesBetween']);
        $repository->method('reservationsBetween')->willReturn([
            ['starts_at' => '2026-06-15 08:00:00', 'ends_at' => '2026-06-17 08:00:00'],
            ['starts_at' => '2026-06-14 08:00:00', 'ends_at' => '2026-06-15 10:00:00'],
        ]);
        $repository->method('airportDeliveriesBetween')->willReturn([['scheduled_at' => '2026-06-15 07:30:00']]);

        $health = $this->getMockBuilder(FleetHealthService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'vehiclesNeedingCleaning',
                'vehiclesDueForMaintenance',
                'registrationExpiring',
                'insuranceExpiring',
                'loanPaymentDue',
                'claimsRequiringFollowUp',
                'summary',
            ])
            ->getMock();
        $health->method('vehiclesNeedingCleaning')->willReturn([['fleet_vehicle_id' => 2]]);
        $health->method('vehiclesDueForMaintenance')->willReturn([['fleet_vehicle_id' => 3]]);
        $health->method('registrationExpiring')->willReturn([]);
        $health->method('insuranceExpiring')->willReturn([]);
        $health->method('loanPaymentDue')->willReturn([]);
        $health->method('claimsRequiringFollowUp')->willReturn([['fleet_vehicle_id' => 4]]);
        $health->method('summary')->willReturn([
            'claims_requiring_follow_up' => [['fleet_vehicle_id' => 4]],
            'vehicles_due_for_maintenance' => [['fleet_vehicle_id' => 3]],
            'registration_expiring' => [],
            'insurance_expiring' => [],
            'vehicles_below_battery_threshold' => [],
        ]);

        $service = new TaskService($repository, $health);
        $today = $service->today(new DateTimeImmutable('2026-06-15 12:00:00'));

        $this->assertCount(1, $today['todays_pickups']);
        $this->assertCount(1, $today['todays_returns']);
        $this->assertCount(1, $today['airport_deliveries']);
        $this->assertCount(1, $service->highPriority(new DateTimeImmutable('2026-06-15 12:00:00'))['claims']);
    }

    public function testFleetCommandServiceComposesMissionControlSnapshot(): void
    {
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $health = $this->getMockBuilder(FleetHealthService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $availability = $this->getMockBuilder(VehicleAvailabilityService::class)->disableOriginalConstructor()->onlyMethods(['vehicleStatus', 'timeline'])->getMock();
        $tasks = $this->getMockBuilder(TaskService::class)->disableOriginalConstructor()->onlyMethods(['today', 'highPriority'])->getMock();

        $statistics->method('summary')->willReturn([
            'available_vehicles' => 5,
            'reserved_vehicles' => 2,
            'in_progress_vehicles' => 1,
            'maintenance_required' => 1,
            'vehicles_out_of_service' => 1,
        ]);
        $health->method('summary')->willReturn([
            'vehicles_needing_cleaning' => [['fleet_vehicle_id' => 1]],
            'vehicles_below_battery_threshold' => [],
            'vehicles_due_for_maintenance' => [['fleet_vehicle_id' => 2]],
            'registration_expiring' => [],
            'claims_requiring_follow_up' => [['fleet_vehicle_id' => 3]],
        ]);
        $availability->method('vehicleStatus')->willReturn([['fleet_vehicle_id' => 1, 'status' => 'available']]);
        $availability->method('timeline')->willReturn([['type' => 'reservation']]);
        $tasks->method('today')->willReturn(['todays_pickups' => [[]], 'todays_returns' => [], 'airport_deliveries' => []]);
        $tasks->method('highPriority')->willReturn(['claims' => [[]]]);

        $snapshot = (new FleetCommandService($statistics, $health, $availability, $tasks))->snapshot(new DateTimeImmutable('2026-06-15 12:00:00'));

        $this->assertSame(5, $snapshot['fleet_status']['available']);
        $this->assertSame(1, $snapshot['fleet_status']['cleaning']);
        $this->assertCount(1, $snapshot['vehicle_statuses']);
        $this->assertSame([], $snapshot['weather_alerts']);
    }

    public function testFleetCommandCenterViewModelReturnsPredictableUiContract(): void
    {
        $command = $this->getMockBuilder(FleetCommandService::class)->disableOriginalConstructor()->onlyMethods(['snapshot'])->getMock();
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['summary', 'vehiclePerformance'])->getMock();
        $health = $this->getMockBuilder(FleetHealthService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $tasks = $this->getMockBuilder(TaskService::class)->disableOriginalConstructor()->onlyMethods(['today', 'tomorrow'])->getMock();
        $availability = $this->getMockBuilder(VehicleAvailabilityService::class)->disableOriginalConstructor()->onlyMethods(['timeline'])->getMock();
        $analytics = $this->getMockBuilder(TripAnalyticsService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $decisionSupport = $this->getMockBuilder(DecisionSupportDashboardService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $importIssues = $this->getMockBuilder(TuroImportIssueService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $vehicleMappings = $this->getMockBuilder(TuroVehicleMappingService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $tripReconciliation = $this->getMockBuilder(TuroTripReconciliationService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $dailyOperations = $this->getMockBuilder(DailyOperationsDashboardService::class)->disableOriginalConstructor()->onlyMethods(['forToday'])->getMock();

        $command->method('snapshot')->willReturn([
            'as_of' => '2026-06-15 08:00:00',
            'fleet_status' => ['available' => 1, 'reserved' => 0, 'in_progress' => 0, 'cleaning' => 0, 'maintenance' => 0, 'out_of_service' => 0],
            'vehicle_statuses' => [[
                'fleet_vehicle_id' => 8,
                'fleet_code' => 'Spaceship-008',
                'display_name' => 'Spaceship-008',
                'model' => '2026 Tesla Model Y',
                'is_premium' => true,
                'status' => 'available',
                'next_reservation' => null,
                'current_battery' => null,
                'current_location' => null,
                'current_odometer' => null,
                'cleaning_status' => 'ready',
            ]],
            'todays_timeline' => [],
            'todays_pickups' => [],
            'todays_returns' => [],
            'airport_deliveries' => [],
            'urgent_items' => ['claims' => [], 'maintenance_tasks' => [], 'registration_renewals' => [], 'insurance_renewals' => [], 'battery_alerts' => []],
            'weather_alerts' => [],
            'traffic_alerts' => [],
        ]);
        $statistics->method('summary')->willReturn($this->statisticsSummary());
        $statistics->method('vehiclePerformance')->willReturn([['fleet_code' => 'Spaceship-008', 'utilization' => 0.75]]);
        $health->method('summary')->willReturn($this->healthSummary());
        $tasks->method('today')->willReturn($this->emptyTasks());
        $tasks->method('tomorrow')->willReturn($this->emptyTasks());
        $availability->method('timeline')->willReturn([]);
        $analytics->method('summary')->willReturn(['average_trip_length' => 2.5, 'utilization' => 0.6]);
        $decisionSupport->method('recommendations')->willReturn([
            'todays_recommendations' => [['title' => 'Pricing rec', 'priority' => 'High', 'confidence' => 90]],
            'pricing' => [],
            'maintenance' => [],
            'fleet_health' => [],
            'revenue' => [],
            'guest_risk' => [],
            'business_insights' => [],
        ]);
        $importIssues->method('attentionSummary')->willReturn([
            'unresolved_errors' => 0,
            'unresolved_warnings' => 0,
            'total_unresolved' => 0,
            'has_unresolved' => false,
            'href' => '/turo/import-issues',
        ]);
        $vehicleMappings->method('attentionSummary')->willReturn([
            'unique_unmatched_vehicles' => 0,
            'affected_issues' => 0,
            'has_unmatched' => false,
            'href' => '/turo/vehicle-matches',
        ]);
        $tripReconciliation->method('attentionSummary')->willReturn([
            'awaiting_reconciliation' => 0,
            'has_reconciliation_work' => false,
            'href' => '/turo/vehicle-matches',
        ]);
        $dailyOperations->method('forToday')->willReturn($this->dailyOperations());

        $viewModel = (new FleetCommandCenterViewModelService($command, $statistics, $health, $tasks, $availability, $analytics, $decisionSupport, $importIssues, $vehicleMappings, $tripReconciliation, $dailyOperations))
            ->forToday(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertSame('Fleet Command Center', $viewModel['page_title']);
        $this->assertTrue($viewModel['mission_clear']);
        $this->assertCount(8, $viewModel['fleet_status']);
        $this->assertSame('Premium', $viewModel['vehicles'][0]['segment']);
        $this->assertSame('info', $viewModel['vehicles'][0]['segment_tone']);
        $this->assertSame('2026 Tesla Model Y', $viewModel['vehicles'][0]['model_label']);
        $this->assertSame('Open', $viewModel['vehicles'][0]['next_reservation_label']);
        $this->assertSame('Reserved', $viewModel['activity']['weather_status']);
        $this->assertSame('Reserved', $viewModel['activity']['traffic_status']);
        $this->assertSame('Reserved', $viewModel['activity']['battery_status']);
        $this->assertSame('Pricing rec', $viewModel['decision_support']['todays_recommendations'][0]['title']);
        $this->assertFalse($viewModel['import_issues']['has_unresolved']);
        $this->assertFalse($viewModel['vehicle_mappings']['has_unmatched']);
        $this->assertFalse($viewModel['trip_reconciliation']['has_reconciliation_work']);
        $this->assertSame('Good Morning, Jay.', $viewModel['daily_operations']['briefing']['greeting']);
        $this->assertSame([], $viewModel['health_alerts']);
        $this->assertSame('Reserved', $viewModel['future_integrations'][0]['status']);
    }

    public function testFleetCommandCenterViewModelIncludesImportIssueAlertCounts(): void
    {
        $command = $this->getMockBuilder(FleetCommandService::class)->disableOriginalConstructor()->onlyMethods(['snapshot'])->getMock();
        $statistics = $this->getMockBuilder(FleetStatisticsService::class)->disableOriginalConstructor()->onlyMethods(['summary', 'vehiclePerformance'])->getMock();
        $health = $this->getMockBuilder(FleetHealthService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $tasks = $this->getMockBuilder(TaskService::class)->disableOriginalConstructor()->onlyMethods(['today', 'tomorrow'])->getMock();
        $availability = $this->getMockBuilder(VehicleAvailabilityService::class)->disableOriginalConstructor()->onlyMethods(['timeline'])->getMock();
        $analytics = $this->getMockBuilder(TripAnalyticsService::class)->disableOriginalConstructor()->onlyMethods(['summary'])->getMock();
        $decisionSupport = $this->getMockBuilder(DecisionSupportDashboardService::class)->disableOriginalConstructor()->onlyMethods(['recommendations'])->getMock();
        $importIssues = $this->getMockBuilder(TuroImportIssueService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $vehicleMappings = $this->getMockBuilder(TuroVehicleMappingService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $tripReconciliation = $this->getMockBuilder(TuroTripReconciliationService::class)->disableOriginalConstructor()->onlyMethods(['attentionSummary'])->getMock();
        $dailyOperations = $this->getMockBuilder(DailyOperationsDashboardService::class)->disableOriginalConstructor()->onlyMethods(['forToday'])->getMock();

        $command->method('snapshot')->willReturn([
            'fleet_status' => ['available' => 1, 'reserved' => 0, 'in_progress' => 0, 'cleaning' => 0, 'maintenance' => 0, 'out_of_service' => 0],
            'vehicle_statuses' => [],
            'todays_timeline' => [],
            'urgent_items' => ['claims' => [], 'maintenance_tasks' => [], 'registration_renewals' => [], 'insurance_renewals' => [], 'battery_alerts' => []],
            'weather_alerts' => [],
            'traffic_alerts' => [],
        ]);
        $statistics->method('summary')->willReturn($this->statisticsSummary());
        $statistics->method('vehiclePerformance')->willReturn([]);
        $health->method('summary')->willReturn($this->healthSummary());
        $tasks->method('today')->willReturn($this->emptyTasks());
        $tasks->method('tomorrow')->willReturn($this->emptyTasks());
        $availability->method('timeline')->willReturn([]);
        $analytics->method('summary')->willReturn(['average_trip_length' => 0, 'utilization' => 0]);
        $decisionSupport->method('recommendations')->willReturn(['todays_recommendations' => []]);
        $importIssues->method('attentionSummary')->willReturn([
            'unresolved_errors' => 2,
            'unresolved_warnings' => 3,
            'total_unresolved' => 5,
            'has_unresolved' => true,
            'href' => '/turo/import-issues',
        ]);
        $vehicleMappings->method('attentionSummary')->willReturn([
            'unique_unmatched_vehicles' => 1,
            'affected_issues' => 4,
            'has_unmatched' => true,
            'href' => '/turo/vehicle-matches',
        ]);
        $tripReconciliation->method('attentionSummary')->willReturn([
            'awaiting_reconciliation' => 4,
            'has_reconciliation_work' => true,
            'href' => '/turo/vehicle-matches',
        ]);
        $dailyOperations->method('forToday')->willReturn($this->dailyOperations());

        $viewModel = (new FleetCommandCenterViewModelService($command, $statistics, $health, $tasks, $availability, $analytics, $decisionSupport, $importIssues, $vehicleMappings, $tripReconciliation, $dailyOperations))
            ->forToday(new DateTimeImmutable('2026-06-15 08:00:00'));

        $this->assertTrue($viewModel['import_issues']['has_unresolved']);
        $this->assertSame(2, $viewModel['import_issues']['unresolved_errors']);
        $this->assertSame(3, $viewModel['import_issues']['unresolved_warnings']);
        $this->assertTrue($viewModel['vehicle_mappings']['has_unmatched']);
        $this->assertSame(1, $viewModel['vehicle_mappings']['unique_unmatched_vehicles']);
        $this->assertTrue($viewModel['trip_reconciliation']['has_reconciliation_work']);
    }

    /** @param array<int, string> $methods */
    private function repositoryMock(array $methods): FleetIntelligenceRepository&MockObject
    {
        return $this->getMockBuilder(FleetIntelligenceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    /** @return array<string, mixed> */
    private function dailyOperations(): array
    {
        return [
            'briefing' => ['greeting' => 'Good Morning, Jay.', 'message' => 'No urgent issues.'],
            'movement_board' => [],
            'timeline' => [],
            'attention' => [],
            'fleet_status' => [],
            'operational_queue' => [],
            'financial' => [],
            'data_honesty' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function statisticsSummary(): array
    {
        return [
            'fleet_size' => 1,
            'available_vehicles' => 1,
            'reserved_vehicles' => 0,
            'in_progress_vehicles' => 0,
            'maintenance_required' => 0,
            'claim_open' => 0,
            'vehicles_out_of_service' => 0,
            'current_month' => [
                'completed_revenue' => 1000.0,
                'forecast_revenue' => 250.0,
                'cash_flow' => 900.0,
                'operating_profit' => 800.0,
                'fleet_utilization' => 0.5,
                'average_daily_rate' => 100.0,
                'revenue_per_available_day' => 50.0,
                'revenue_per_vehicle' => 1000.0,
            ],
            'fleet_value' => ['fleet_value' => 50000.0, 'loan_balance' => 25000.0, 'fleet_equity' => 25000.0],
            'premium_vs_base' => [['group' => 'premium', 'completed_revenue' => 1000.0], ['group' => 'base', 'completed_revenue' => 0.0]],
            'lifetime_revenue' => 10000.0,
            'lifetime_profit' => 2500.0,
            'vehicle_roi' => [['roi' => 0.2]],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function healthSummary(): array
    {
        return [
            'vehicles_needing_cleaning' => [],
            'vehicles_due_for_maintenance' => [],
            'registration_expiring' => [],
            'insurance_expiring' => [],
            'loan_payment_due' => [],
            'claims_requiring_follow_up' => [],
            'vehicles_below_battery_threshold' => [],
            'missing_photos' => [],
            'missing_documents' => [],
            'missing_turo_listing_data' => [],
            'incomplete_vehicle_setup' => [],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function emptyTasks(): array
    {
        return [
            'todays_pickups' => [],
            'todays_returns' => [],
            'cleaning_tasks' => [],
            'charging_tasks' => [],
            'airport_deliveries' => [],
            'maintenance_tasks' => [],
            'registration_renewals' => [],
            'insurance_renewals' => [],
            'loan_payments' => [],
            'claims' => [],
        ];
    }
}
