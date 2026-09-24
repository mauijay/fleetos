<?php

use App\Repositories\VehicleHealthObservationRepository;
use App\Repositories\VehicleHealthPolicyRepository;
use App\Services\Fleet\CurrentVehicleOdometerResolver;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\VehicleHealthReminderProjectionService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleHealthReminderProjectionServiceTest extends CIUnitTestCase
{
    public function testInRangeObservationIsUpcomingAndCreatesNoActiveAction(): void
    {
        $service = $this->service($this->pressure('2026-09-20 10:00:00', 40, 42, 43, 44), $this->policy());

        $all = $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), true);
        $active = $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), false);

        $this->assertSame('upcoming', $all[0]['state']);
        $this->assertNull($all[0]['action_label']);
        $this->assertSame([], $active);
    }

    public function testLowAndHighReadingsProduceOneAttentionInsteadOfRoutineCheck(): void
    {
        $service = $this->service($this->pressure('2026-08-01 10:00:00', 39, 42, 44, 45), $this->policy());

        $rows = $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), false);

        $this->assertCount(1, $rows);
        $this->assertSame('attention', $rows[0]['state']);
        $this->assertSame('Correct tire pressure', $rows[0]['action_label']);
        $this->assertSame(['LF', 'RR'], array_column($rows[0]['affected_wheels'], 'wheel'));
        $this->assertFalse($rows[0]['blocking']);
        $this->assertStringNotContainsString('unsafe', strtolower($rows[0]['context']));
        $this->assertStringNotContainsString('danger', strtolower($rows[0]['context']));

        $fleetRows = $service->forCompany(1, new DateTimeImmutable('2026-09-23 10:00:00'));

        $this->assertCount(1, $fleetRows);
        $this->assertSame('tire_pressure_check', $fleetRows[0]['reminder_code']);
        $this->assertSame('Correct tire pressure', $fleetRows[0]['action_label']);
    }

    public function testSafetyBoundCrossingIsBlockingOnlyWhenConfigured(): void
    {
        $policy = $this->policy();
        $policy['safety_min_psi'] = '35';
        $service = $this->service($this->pressure('2026-09-23 09:00:00', 34, 42, 42, 42), $policy);

        $row = $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), false)[0];

        $this->assertTrue($row['blocking']);
        $this->assertStringContainsString('configured safety range', $row['context']);
    }

    public function testExactBoundaryIsDueAndLaterIsOverdue(): void
    {
        $pressure = $this->pressure('2026-09-01 10:00:00', 42, 42, 42, 42);
        $due = $this->service($pressure, $this->policy())->forVehicle(1, 3, new DateTimeImmutable('2026-10-01 10:00:00'), false)[0];
        $overdue = $this->service($pressure, $this->policy())->forVehicle(1, 3, new DateTimeImmutable('2026-10-01 10:00:01'), false)[0];

        $this->assertSame('due', $due['state']);
        $this->assertSame('overdue', $overdue['state']);
        $this->assertSame('Check tire pressure', $due['action_label']);
        $this->assertSame($due['identity'], $overdue['identity']);
    }

    public function testMissingPressureObservationIsDueWithoutFabricatedTimestamp(): void
    {
        $row = $this->service(null, $this->policy())->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), false)[0];

        $this->assertSame('due', $row['state']);
        $this->assertNull($row['observed_at']);
        $this->assertSame('Check tire pressure', $row['action_label']);
    }

    public function testNoPolicyDoesNotClassifyPressureOrFabricateReminder(): void
    {
        $rows = $this->service($this->pressure('2026-09-23 09:00:00', 35, 35, 36, 36), null)
            ->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), true);

        $this->assertSame([], $rows);
    }

    public function testMissingOdometerCreatesSeparateNonMovementSetupAction(): void
    {
        $service = $this->service(null, null, false);
        $rows = $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00'), false);

        $this->assertCount(1, $rows);
        $this->assertSame('current_odometer', $rows[0]['reminder_code']);
        $this->assertSame('Record current odometer', $rows[0]['action_label']);
        $this->assertFalse($rows[0]['movement_relevance']);
        $this->assertStringContainsString('Legacy odometer', $rows[0]['context']);
        $this->assertSame([], $service->forCompany(1, new DateTimeImmutable('2026-09-23 10:00:00')));
    }

    public function testInactiveVehicleSuppressesAllCurrentReminders(): void
    {
        $vehicle = $this->vehicle();
        $vehicle['status_code'] = 'retired';
        $service = $this->service(null, $this->policy(), false, $vehicle);

        $this->assertSame([], $service->forVehicle(1, 3, new DateTimeImmutable('2026-09-23 10:00:00')));
    }

    public function testHonoluluDayBucketsAndCommandCenterCountsUseExactReminderRows(): void
    {
        $rows = [
            ['identity' => 'attention', 'state' => 'attention', 'fleet_number' => 3, 'action_label' => 'Correct tire pressure', 'due_at' => null],
            ['identity' => 'overdue', 'state' => 'overdue', 'fleet_number' => 4, 'action_label' => 'Check tire pressure', 'due_at' => '2026-09-22 10:00:00'],
            ['identity' => 'due-today', 'state' => 'upcoming', 'fleet_number' => 5, 'action_label' => null, 'title' => 'Tire pressure check', 'due_at' => '2026-09-23 18:00:00'],
            ['identity' => 'due-tomorrow', 'state' => 'upcoming', 'fleet_number' => 6, 'action_label' => null, 'title' => 'Tire pressure check', 'due_at' => '2026-09-24 08:00:00'],
            ['identity' => 'odometer', 'reminder_code' => 'current_odometer', 'state' => 'due', 'fleet_number' => 8, 'action_label' => 'Record current odometer', 'due_at' => null],
        ];
        $health = $this->getMockBuilder(FleetHealthService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['vehicleHealthReminders'])
            ->getMock();
        $health->method('vehicleHealthReminders')->willReturn($rows);
        $tasks = new TaskService(healthService: $health);
        $method = new ReflectionMethod($tasks, 'vehicleHealthForDay');
        $asOf = new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('Pacific/Honolulu'));

        $today = $method->invoke($tasks, 1, $asOf, $asOf);
        $tomorrow = $method->invoke($tasks, 1, $asOf->modify('+1 day'), $asOf);

        $this->assertSame(['attention', 'overdue', 'due-today'], array_column($today, 'identity'));
        $this->assertSame(['due-tomorrow'], array_column($tomorrow, 'identity'));

        $todayTasks = array_fill_keys([
            'todays_pickups', 'todays_returns', 'awaiting_recovery', 'airport_deliveries', 'cleaning_tasks',
            'charging_tasks', 'registration_renewals', 'insurance_renewals', 'loan_payments', 'claims',
        ], []);
        $todayTasks['vehicle_health_reminders'] = $today;
        $command = new FleetCommandCenterViewModelService();
        $cards = (new ReflectionMethod($command, 'missionCards'))->invoke($command, $todayTasks);
        $healthCard = array_values(array_filter($cards, static fn (array $card): bool => $card['label'] === 'Vehicle Health & Reminders'))[0];
        $queue = (new ReflectionMethod($command, 'timeScopedActions'))->invoke($command, $todayTasks, 'today');
        $healthQueue = array_values(array_filter($queue, static fn (array $item): bool => $item['code'] === 'vehicle_health_reminders'))[0];

        $this->assertSame(3, $healthCard['count']);
        $this->assertSame('#3 · Correct tire pressure', $healthCard['preview_items'][0]);
        $this->assertSame(3, $healthQueue['count']);
        $this->assertSame($healthCard['count'], count($todayTasks['vehicle_health_reminders']));
    }

    public function testFleetActivityUsesActionableHealthCopyAndDeduplicatesIt(): void
    {
        $health = array_fill_keys([
            'vehicles_due_for_maintenance', 'registration_expiring', 'insurance_expiring',
            'claims_requiring_follow_up', 'missing_photos', 'missing_documents', 'missing_turo_listing_data',
        ], []);
        $health['vehicle_health_reminders'] = [
            ['fleet_vehicle_id' => 92, 'reminder_code' => 'tire_pressure_check', 'action_label' => 'Correct tire pressure'],
            ['fleet_vehicle_id' => 92, 'reminder_code' => 'tire_pressure_check', 'action_label' => 'Correct tire pressure'],
            ['fleet_vehicle_id' => 97, 'reminder_code' => 'current_odometer', 'action_label' => 'Record current odometer'],
        ];

        $issues = (new ReflectionMethod(FleetCommandCenterViewModelService::class, 'issuesByVehicle'))
            ->invoke(new FleetCommandCenterViewModelService(), $health);

        $this->assertSame(['Correct tire pressure'], $issues[92]);
        $this->assertArrayNotHasKey(97, $issues);
        $this->assertNotContains('Vehicle health', $issues[92]);
    }

    /** @param array<string,mixed>|null $pressure @param array<string,mixed>|null $policy @param array<string,mixed>|null $vehicle */
    private function service(?array $pressure, ?array $policy, bool $odometerKnown = true, ?array $vehicle = null): VehicleHealthReminderProjectionService
    {
        $vehicle ??= $this->vehicle();
        $observations = $this->getMockBuilder(VehicleHealthObservationRepository::class)->disableOriginalConstructor()->onlyMethods(['vehicle', 'vehicles', 'latestTirePressure'])->getMock();
        $observations->method('vehicle')->willReturn($vehicle);
        $observations->method('vehicles')->willReturn([$vehicle]);
        $observations->method('latestTirePressure')->willReturn($pressure);
        $policies = $this->getMockBuilder(VehicleHealthPolicyRepository::class)->disableOriginalConstructor()->onlyMethods(['tirePressurePolicy'])->getMock();
        $policies->method('tirePressurePolicy')->willReturn($policy);
        $odometer = $this->getMockBuilder(CurrentVehicleOdometerResolver::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
        $odometer->method('resolve')->willReturn($odometerKnown ? [
            'observation_id' => 9, 'odometer_miles' => 12345, 'observed_at' => '2026-09-23 09:00:00',
            'received_at' => '2026-09-23 09:01:00', 'source' => 'manual', 'actor_user_id' => 7,
        ] : null);

        return new VehicleHealthReminderProjectionService($observations, $policies, $odometer);
    }

    /** @return array<string,mixed> */
    private function vehicle(): array
    {
        return ['id' => 3, 'company_id' => 1, 'fleet_number' => 3, 'fleet_code' => 'Spaceship03', 'display_name' => 'Spaceship03', 'odometer_miles' => 12000, 'status_code' => 'active', 'out_of_service_date' => null];
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return ['id' => 4, 'interval_value' => 30, 'recommended_psi' => '42', 'acceptable_min_psi' => '40', 'acceptable_max_psi' => '44', 'safety_min_psi' => null, 'safety_max_psi' => null];
    }

    /** @return array<string,mixed> */
    private function pressure(string $observedAt, int $lf, int $rf, int $lr, int $rr): array
    {
        return ['id' => 5, 'observed_at' => $observedAt, 'lf_psi' => $lf, 'rf_psi' => $rf, 'lr_psi' => $lr, 'rr_psi' => $rr, 'recommended_psi' => 42];
    }
}
