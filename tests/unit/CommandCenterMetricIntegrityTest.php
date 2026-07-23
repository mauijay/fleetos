<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Fleet\FleetCapacityService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\VehicleAvailabilityService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @internal
 */
final class CommandCenterMetricIntegrityTest extends CIUnitTestCase
{
    public function testFleetUtilizationDeduplicatesOverlapsAndNeverExceedsOneHundredPercent(): void
    {
        $repository = $this->repositoryMock(['operationalReservationsBetween', 'fleetVehicles']);
        $repository->method('operationalReservationsBetween')->willReturn([
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-01 08:00:00', 'ends_at' => '2026-07-02 10:00:00', 'status_code' => 'booked'],
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-01 18:00:00', 'ends_at' => '2026-07-02 06:00:00', 'status_code' => 'in_progress'],
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-02 11:00:00', 'ends_at' => '2026-07-03 08:00:00', 'status_code' => 'completed'],
        ]);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 10, 'is_available_for_booking' => true, 'in_service_date' => null, 'out_of_service_date' => null],
        ]);

        $capacity = new FleetCapacityService($repository);
        $metrics = $capacity->utilizationForRange(new DateTimeImmutable('2026-07-01 00:00:00'), new DateTimeImmutable('2026-07-03 00:00:00'));

        $this->assertSame(2, $metrics['occupied_vehicle_days']);
        $this->assertSame(2, $metrics['available_vehicle_days']);
        $this->assertSame(1.0, $metrics['utilization']);
    }

    public function testCanceledReservationsDoNotConsumeOccupiedVehicleDays(): void
    {
        $repository = $this->repositoryMock(['operationalReservationsBetween', 'fleetVehicles']);
        $repository->method('operationalReservationsBetween')->willReturn([
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-01 08:00:00', 'ends_at' => '2026-07-02 10:00:00', 'status_code' => 'canceled_zero_payout'],
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-01 09:00:00', 'ends_at' => '2026-07-01 22:00:00', 'status_code' => 'booked'],
        ]);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 10, 'is_available_for_booking' => true, 'in_service_date' => null, 'out_of_service_date' => null],
        ]);

        $capacity = new FleetCapacityService($repository);
        $metrics = $capacity->utilizationForRange(new DateTimeImmutable('2026-07-01 00:00:00'), new DateTimeImmutable('2026-07-02 00:00:00'));

        $this->assertSame(1, $metrics['occupied_vehicle_days']);
        $this->assertSame(1, $metrics['available_vehicle_days']);
        $this->assertSame(1.0, $metrics['utilization']);
    }

    public function testCanceledReservationsDoNotCreatePickupsOrReturnsButValidFutureTripsDo(): void
    {
        $repository = $this->repositoryMock(['operationalReservationsBetween', 'airportDeliveriesBetween']);
        $repository->method('operationalReservationsBetween')->willReturn([
            ['starts_at' => '2026-07-01 09:00:00', 'ends_at' => '2026-07-02 09:00:00', 'status_code' => 'booked'],
            ['starts_at' => '2026-07-01 10:00:00', 'ends_at' => '2026-07-01 15:00:00', 'status_code' => 'canceled_zero_payout'],
            ['starts_at' => '2026-06-30 10:00:00', 'ends_at' => '2026-07-01 11:00:00', 'status_code' => 'completed'],
            ['starts_at' => '2026-06-30 10:00:00', 'ends_at' => '2026-07-01 13:00:00', 'status_code' => 'canceled_zero_payout'],
        ]);
        $repository->method('airportDeliveriesBetween')->willReturn([]);

        $health = $this->getMockBuilder(App\Services\Fleet\FleetHealthService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['vehiclesNeedingCleaning', 'vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'])
            ->getMock();
        $health->method('vehiclesNeedingCleaning')->willReturn([]);
        $health->method('vehiclesDueForMaintenance')->willReturn([]);
        $health->method('registrationExpiring')->willReturn([]);
        $health->method('insuranceExpiring')->willReturn([]);
        $health->method('loanPaymentDue')->willReturn([]);
        $health->method('claimsRequiringFollowUp')->willReturn([]);

        $tasks = new TaskService($repository, $health);
        $today = $tasks->today(new DateTimeImmutable('2026-07-01 08:00:00'));

        $this->assertCount(1, $today['todays_pickups']);
        $this->assertCount(1, $today['todays_returns']);
    }

    public function testVehicleAvailabilityTimelineExcludesCanceledReservations(): void
    {
        $repository = $this->repositoryMock(['operationalReservationsBetween', 'airportDeliveriesBetween', 'fleetVehicles']);
        $repository->method('operationalReservationsBetween')->willReturn([
            ['fleet_vehicle_id' => 10, 'source' => 'turo', 'starts_at' => '2026-07-01 09:00:00', 'ends_at' => '2026-07-01 18:00:00', 'status_code' => 'booked'],
            ['fleet_vehicle_id' => 10, 'source' => 'turo', 'starts_at' => '2026-07-01 10:00:00', 'ends_at' => '2026-07-01 12:00:00', 'status_code' => 'canceled_zero_payout'],
        ]);
        $repository->method('airportDeliveriesBetween')->willReturn([]);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 10, 'fleet_code' => 'Spaceship-10', 'display_name' => 'Spaceship-10', 'model_year' => 2026, 'make_name' => 'Tesla', 'model_name' => 'Model 3', 'trim_name' => 'Premium', 'is_premium' => true, 'is_available_for_booking' => true, 'odometer_miles' => 100],
        ]);

        $service = new VehicleAvailabilityService($repository);
        $timeline = $service->timeline(new DateTimeImmutable('2026-07-01 00:00:00'), new DateTimeImmutable('2026-07-02 00:00:00'));

        $this->assertCount(1, $timeline);
        $this->assertSame('booked', $timeline[0]['status']);
    }

    public function testCurrentMonthRevenueUsesOperatingRevenueOnlyAndExcludesCashMovement(): void
    {
        $repository = $this->repositoryMock([
            'revenueMonthly',
            'operatingCosts',
            'operatingCostSignals',
            'fleetVehicles',
            'operationalReservationsBetween',
            'activeReservationCounts',
            'openClaims',
            'fleetCapital',
            'revenueByVehicle',
            'lifetimeRevenueByVehicle',
            'fleetCapitalByVehicle',
        ]);
        $repository->method('revenueMonthly')->willReturn([
            ['allocation_month' => '2026-07-01', 'trip_days' => '3.000', 'billable_days' => '3.000', 'gross_revenue' => '900.00', 'completed_revenue' => '9999.99', 'forecast_revenue' => '400.00', 'delivery_fees' => '0.00', 'reimbursements' => '0.00'],
        ]);
        $repository->method('operatingCosts')->willReturn(['maintenance' => 100.0, 'charging' => 0.0, 'airport_parking' => 0.0, 'loan_payments' => 0.0, 'insurance_premiums' => 0.0]);
        $repository->method('operatingCostSignals')->willReturn([
            'maintenance_rows' => 1,
            'charging_rows' => 0,
            'airport_delivery_rows' => 0,
            'active_loan_rows' => 0,
            'active_insurance_rows' => 0,
            'has_operating_cost_data' => true,
        ]);
        $repository->method('fleetVehicles')->willReturn([
            ['id' => 10, 'status_code' => 'active', 'is_available_for_booking' => true, 'in_service_date' => null, 'out_of_service_date' => null],
        ]);
        $repository->method('operationalReservationsBetween')->willReturn([
            ['fleet_vehicle_id' => 10, 'starts_at' => '2026-07-01 09:00:00', 'ends_at' => '2026-07-02 09:00:00', 'status_code' => 'booked'],
        ]);
        $repository->method('activeReservationCounts')->willReturn(['reserved' => 0, 'in_progress' => 0]);
        $repository->method('openClaims')->willReturn([]);
        $repository->method('fleetCapital')->willReturn(['fleet_value' => 0.0, 'loan_balance' => 0.0, 'fleet_equity' => 0.0, 'startup_costs' => 0.0]);
        $repository->method('revenueByVehicle')->willReturn([]);
        $repository->method('lifetimeRevenueByVehicle')->willReturn([]);
        $repository->method('fleetCapitalByVehicle')->willReturn([]);

        $transactions = $this->transactionRepositoryMock(['operatingRevenueInPeriod', 'lifetimeOperatingRevenue', 'operatingRevenueByPremiumBaseInPeriod']);
        $transactions->method('operatingRevenueInPeriod')->willReturn(1200.0);
        $transactions->method('lifetimeOperatingRevenue')->willReturn(1200.0);
        $transactions->method('operatingRevenueByPremiumBaseInPeriod')->willReturn([
            ['group' => 'premium', 'completed_revenue' => 1200.0, 'row_count' => 1],
            ['group' => 'base', 'completed_revenue' => 0.0, 'row_count' => 0],
        ]);

        $stats = new FleetStatisticsService($repository, new RevenueService($repository, $transactions));
        $month = $stats->currentMonth(new DateTimeImmutable('2026-07-02 08:00:00'));

        $this->assertSame(1200.0, $month['completed_revenue']);
        $this->assertSame(400.0, $month['forecast_revenue']);
        $this->assertSame(1500.0, $month['cash_flow']);
    }

    public function testCashFlowAndProfitBecomePendingWhenCostSignalsAreIncomplete(): void
    {
        $repository = $this->repositoryMock(['revenueMonthly', 'operatingCosts', 'operatingCostSignals', 'fleetCapital']);
        $repository->method('revenueMonthly')->willReturn([
            ['allocation_month' => '2026-07-01', 'trip_days' => '0.000', 'billable_days' => '0.000', 'gross_revenue' => '0.00', 'completed_revenue' => '0.00', 'forecast_revenue' => '0.00', 'delivery_fees' => '0.00', 'reimbursements' => '0.00'],
        ]);
        $repository->method('operatingCosts')->willReturn(['maintenance' => 0.0, 'charging' => 0.0, 'airport_parking' => 0.0, 'loan_payments' => 0.0, 'insurance_premiums' => 0.0]);
        $repository->method('operatingCostSignals')->willReturn([
            'maintenance_rows' => 0,
            'charging_rows' => 0,
            'airport_delivery_rows' => 0,
            'active_loan_rows' => 0,
            'active_insurance_rows' => 0,
            'has_operating_cost_data' => false,
        ]);
        $repository->method('fleetCapital')->willReturn(['startup_costs' => 0.0]);

        $transactions = $this->transactionRepositoryMock(['operatingRevenueInPeriod']);
        $transactions->method('operatingRevenueInPeriod')->willReturn(300.0);

        $service = new RevenueService($repository, $transactions);
        $period = $service->period('2026-07-01', '2026-07-01');

        $this->assertNull($period['cash_flow']);
        $this->assertNull($period['operating_profit']);
        $this->assertSame('pending', $period['cash_flow_state']);
    }

    /** @param array<int, string> $methods */
    private function repositoryMock(array $methods): FleetIntelligenceRepository&MockObject
    {
        return $this->getMockBuilder(FleetIntelligenceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    /** @param array<int, string> $methods */
    private function transactionRepositoryMock(array $methods): TuroNormalizedTransactionRepository&MockObject
    {
        return $this->getMockBuilder(TuroNormalizedTransactionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }
}
