<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\FleetCommandService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripAnalyticsService;
use App\Services\Fleet\VehicleAvailabilityService;
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

    /** @param array<int, string> $methods */
    private function repositoryMock(array $methods): FleetIntelligenceRepository&MockObject
    {
        return $this->getMockBuilder(FleetIntelligenceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }
}
