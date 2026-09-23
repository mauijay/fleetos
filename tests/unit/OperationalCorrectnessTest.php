<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\ChecklistActionFocusService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\MovementStateResolver;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\OperationalMovementWorkService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripEnergyRuleResolver;
use App\Services\Fleet\VehicleDailyStateService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalCorrectnessTest extends CIUnitTestCase
{
    public function testRecoveredCleaningAndEnergyWorkReconcilesWithVisibleTodayQueue(): void
    {
        $schedules = $this->createStub(FleetIntelligenceRepository::class);
        $schedules->method('operationalReservationsBetween')->willReturn([]);
        $schedules->method('airportDeliveriesBetween')->willReturn([]);
        $health = $this->createStub(FleetHealthService::class);
        $health->method('vehiclesNeedingCleaning')->willReturn([['fleet_vehicle_id' => 10]]);
        foreach (['vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'] as $method) {
            $health->method($method)->willReturn([]);
        }
        $work = $this->createStub(OperationalMovementWorkService::class);
        $work->method('singleActiveCompanyId')->willReturn(1);
        $work->method('completionsForCompany')->willReturn([]);
        $work->method('awaitingRecoveryForCompany')->willReturn([]);
        $work->method('energyNeedsForCompany')->willReturn([[
            'fleet_vehicle_id' => 10, 'condition_code' => 'charge_required', 'label' => 'Charge/Fuel to 80%',
        ]]);

        $today = (new TaskService($schedules, $health, $work))->today(new DateTimeImmutable('2026-09-17 12:00:00'));
        $command = new FleetCommandCenterViewModelService();
        $queue = (new ReflectionMethod($command, 'queueView'))->invoke($command, 'today', $today, [], [], []);

        $this->assertCount(1, $today['cleaning_tasks']);
        $this->assertCount(1, $today['charging_tasks']);
        $this->assertSame(2, $queue['scopes'][1]['count']);
        $this->assertSame(2, array_sum(array_column($queue['items'], 'count')));
        $this->assertSame(['Cleaning Tasks', 'Charge/Fuel & Energy Checks'], array_column($queue['items'], 'label'));
    }

    public function testOperationalEnergyWorkUsesRecoveryOnlyForExactSameDayNextTrip(): void
    {
        $sameDay = true;
        $repo = $this->getMockBuilder(OperationalFactsRepository::class)->disableOriginalConstructor()->onlyMethods([
            'activeFleetVehiclesForCompany', 'latestCustodyEventsForCompany', 'latestEnergyForCompany', 'profile', 'movementChecklistHref',
        ])->getMock();
        $repo->method('activeFleetVehiclesForCompany')->willReturn([['id' => 10, 'display_name' => 'Synthetic EV']]);
        $repo->method('latestCustodyEventsForCompany')->willReturn([10 => [
            'id' => 301, 'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-22 06:45:00',
            'turo_trip_normalized_id' => 691,
        ]]);
        $repo->method('latestEnergyForCompany')->willReturn([10 => [
            'trip_movement_event_id' => 301, 'energy_percent' => 43, 'captured_at' => '2026-09-22 06:45:00',
        ]]);
        $repo->method('profile')->willReturn(['energy_kind' => 'electric']);
        $repo->method('movementChecklistHref')->willReturn('/operations/checklists/691');
        $nextTrips = $this->getMockBuilder(NextConfirmedTripService::class)->disableOriginalConstructor()->onlyMethods(['forVehicle'])->getMock();
        $nextTrips->method('forVehicle')->willReturnCallback(static function () use (&$sameDay): array {
            return ['id' => 692, 'starts_at' => '2026-09-22 15:00:00', 'is_same_day_turnaround' => $sameDay];
        });
        $rules = $this->getMockBuilder(TripEnergyRuleResolver::class)->disableOriginalConstructor()->onlyMethods(['forTrip'])->getMock();
        $rules->expects($this->any())->method('forTrip')->with(1, 692)->willReturn([
            'source' => 'vehicle_profile', 'mode' => 'preferred_range', 'energy_kind' => 'electric',
            'minimum_percent' => 70, 'preferred_max_percent' => 80, 'target_percent' => null,
            'hard_max_percent' => null, 'required' => true,
        ]);
        $work = new OperationalMovementWorkService($repo, $nextTrips, $rules);

        $sameDayNeed = $work->energyNeedsForCompany(1, new DateTimeImmutable('2026-09-22 07:00:00'))[0];
        $this->assertSame('charge_required', $sameDayNeed['condition_code']);
        $this->assertSame(43, $sameDayNeed['energy_percent']);
        $this->assertStringStartsWith('Charge to 70', $sameDayNeed['action_label']);
        $this->assertStringEndsWith('80%', $sameDayNeed['action_label']);

        $sameDay = false;
        $staleNeed = $work->energyNeedsForCompany(1, new DateTimeImmutable('2026-09-22 07:00:00'))[0];
        $this->assertSame('measurement_needed', $staleNeed['condition_code']);
        $this->assertNull($staleNeed['energy_percent']);
        $this->assertSame(43, $staleNeed['last_known_energy_percent']);
        $this->assertSame('Record current Charge percentage', $staleNeed['action_label']);
    }

    public function testOperationalQueueCountsPhysicalReadinessWorkOnlyOnce(): void
    {
        $today = [
            'todays_pickups' => [], 'todays_returns' => [], 'airport_deliveries' => [],
            'cleaning_tasks' => [['fleet_vehicle_id' => 10]],
            'charging_tasks' => [['fleet_vehicle_id' => 10]],
            'awaiting_recovery' => [], 'recovery_exceptions' => [],
        ];
        $checklists = [[
            'fleet_vehicle_id' => 10,
            'blocking_remaining_count' => 3,
            'additional_actions_remaining_count' => 0,
            'readiness_projection' => [
                'readiness_phase' => MovementReadinessProjectionService::PHASE_PICKUP_PREPARATION,
                'requirements' => [
                    ['code' => 'vehicle_clean', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied'],
                    ['code' => 'energy_ready', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied'],
                    ['code' => 'photos_complete', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied'],
                ],
            ],
        ]];
        $empty = ['total_unresolved' => 0, 'unique_unmatched_vehicles' => 0, 'awaiting_reconciliation' => 0,
            'airport_workflows_requiring_action' => 0, 'total_actionable' => 0, 'total' => 0, 'href' => '/'];

        $queue = (new ReflectionMethod(DailyOperationsDashboardService::class, 'operationalQueue'))->invoke(
            new DailyOperationsDashboardService(),
            $today,
            [],
            $empty,
            $empty,
            $empty,
            $empty,
            $empty,
            $empty,
            $empty,
            $checklists,
        );
        $byCode = array_column($queue, null, 'code');

        $this->assertSame(1, $byCode['readiness']['count']);
        $this->assertSame(1, $byCode['cleaning']['count']);
        $this->assertSame(1, $byCode['charging']['count']);
        $this->assertSame(3, array_sum(array_column($queue, 'count')));
    }

    public function testRecoveryExceptionsHaveIndependentStableTodayActionsAndReconciledBadge(): void
    {
        $exceptions = [];
        foreach (['damage', 'missing_key', 'missing_charge_adapter', 'not_drivable', 'other'] as $index => $code) {
            $exceptions[] = ['id' => 701 + $index, 'exception_code' => $code, 'fleet_code' => 'LOCAL-RETURN', 'checklist_id' => 901];
        }
        $today = ['recovery_exceptions' => $exceptions];
        $command = new FleetCommandCenterViewModelService();
        $queue = (new ReflectionMethod($command, 'queueView'))->invoke($command, 'today', $today, [], [], []);

        $this->assertSame(5, $queue['scopes'][1]['count']);
        $this->assertSame(5, array_sum(array_column($queue['items'], 'count')));
        $this->assertSame(['recovery_exception_701', 'recovery_exception_702', 'recovery_exception_703', 'recovery_exception_704', 'recovery_exception_705'], array_column($queue['items'], 'code'));
        $this->assertSame('Recovery exception: Damage', $queue['items'][0]['label']);
        $this->assertSame('Recovery exception: Missing Key', $queue['items'][1]['label']);
        $this->assertSame('Recovery exception: Missing Charge Adapter', $queue['items'][2]['label']);
        $this->assertSame('Recovery exception: Not Drivable', $queue['items'][3]['label']);
        $this->assertSame('/operations/checklists/901#recovery-exceptions', $queue['items'][0]['href']);
        $this->assertSame(0, (new ReflectionMethod($command, 'queueView'))->invoke($command, 'tomorrow', $today, [], [], [])['scopes'][2]['count']);
    }

    public function testOnlyAuthoritativeFactsCompleteDistinctScheduledMovements(): void
    {
        $rows = [
            ['id' => 11, 'fleet_vehicle_id' => 1, 'starts_at' => '2026-09-17 08:00:00', 'ends_at' => '2026-09-19 08:00:00'],
            ['id' => 12, 'fleet_vehicle_id' => 1, 'starts_at' => '2026-09-16 08:00:00', 'ends_at' => '2026-09-17 09:00:00'],
            ['id' => 13, 'fleet_vehicle_id' => 2, 'starts_at' => '2026-09-17 07:00:00', 'ends_at' => '2026-09-17 10:00:00'],
            ['id' => 14, 'fleet_vehicle_id' => 3, 'starts_at' => '2026-09-18 08:00:00', 'ends_at' => '2026-09-18 10:00:00'],
        ];
        $repo = $this->getMockBuilder(FleetIntelligenceRepository::class)->disableOriginalConstructor()->onlyMethods(['operationalReservationsBetween', 'airportDeliveriesBetween'])->getMock();
        $repo->method('operationalReservationsBetween')->willReturn($rows);
        $repo->method('airportDeliveriesBetween')->willReturn([]);
        $health = $this->getMockBuilder(FleetHealthService::class)->disableOriginalConstructor()->onlyMethods(['vehiclesNeedingCleaning', 'vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'])->getMock();
        foreach (['vehiclesNeedingCleaning', 'vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'] as $method) {
            $health->method($method)->willReturn([]);
        }
        $facts = $this->getMockBuilder(OperationalFactsRepository::class)->disableOriginalConstructor()->onlyMethods(['authoritativeMovementCompletionsForCompany', 'awaitingRecoveryForCompany'])->getMock();
        $facts->method('awaitingRecoveryForCompany')->willReturn([]);
        $facts->method('authoritativeMovementCompletionsForCompany')->willReturn([
            ['turo_trip_normalized_id' => 11, 'event_code' => 'actual_handoff', 'created_at' => '2026-09-17 08:05:00'],
            ['turo_trip_normalized_id' => 12, 'event_code' => 'actual_return', 'created_at' => '2026-09-17 08:30:00'],
            ['turo_trip_normalized_id' => 13, 'event_code' => 'vehicle_positioned', 'created_at' => '2026-09-17 08:30:00'],
        ]);
        $work = $this->getMockBuilder(OperationalMovementWorkService::class)->setConstructorArgs([$facts])->onlyMethods(['singleActiveCompanyId', 'energyNeedsForCompany'])->getMock();
        $work->method('singleActiveCompanyId')->willReturn(1);
        $work->method('energyNeedsForCompany')->willReturn([]);
        $tasks = new TaskService($repo, $health, $work);

        $today = $tasks->today(new DateTimeImmutable('2026-09-17 12:00:00'));
        $tomorrow = $tasks->tomorrow(new DateTimeImmutable('2026-09-17 12:00:00'));
        $this->assertSame([13], array_column($today['todays_pickups'], 'id'));
        $this->assertSame([13], array_column($today['todays_returns'], 'id'));
        $this->assertSame([14], array_column($tomorrow['todays_pickups'], 'id'));
        $this->assertSame([14], array_column($tomorrow['todays_returns'], 'id'));
    }

    public function testGuestReturnReplacesDueReturnAndPersistsAcrossMidnightWithoutPhysicalWork(): void
    {
        $reservation = ['id' => 71, 'fleet_vehicle_id' => 9, 'starts_at' => '2026-09-16 08:00:00', 'ends_at' => '2026-09-17 19:00:00'];
        $schedules = $this->getMockBuilder(FleetIntelligenceRepository::class)->disableOriginalConstructor()->onlyMethods(['operationalReservationsBetween', 'airportDeliveriesBetween'])->getMock();
        $schedules->method('operationalReservationsBetween')->willReturn([$reservation]);
        $schedules->method('airportDeliveriesBetween')->willReturn([]);
        $health = $this->getMockBuilder(FleetHealthService::class)->disableOriginalConstructor()->onlyMethods(['vehiclesNeedingCleaning', 'vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'])->getMock();
        $health->method('vehiclesNeedingCleaning')->willReturn([['fleet_vehicle_id' => 9, 'id' => 9]]);
        foreach (['vehiclesDueForMaintenance', 'registrationExpiring', 'insuranceExpiring', 'loanPaymentDue', 'claimsRequiringFollowUp'] as $method) {
            $health->method($method)->willReturn([]);
        }
        $facts = $this->getMockBuilder(OperationalFactsRepository::class)->disableOriginalConstructor()->onlyMethods(['authoritativeMovementCompletionsForCompany', 'awaitingRecoveryForCompany', 'movementChecklistHref'])->getMock();
        $facts->method('authoritativeMovementCompletionsForCompany')->willReturn([]);
        $facts->method('movementChecklistHref')->willReturn('/operations/checklists/41');
        $facts->method('awaitingRecoveryForCompany')->willReturn([[
            'id' => 15, 'turo_trip_normalized_id' => 71, 'fleet_vehicle_id' => 9,
            'display_name' => 'Synthetic EV', 'fleet_code' => 'Synthetic EV',
            'event_code' => 'guest_return_staged', 'occurred_at' => '2026-09-17 18:00:00',
            'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'G',
        ]]);
        $work = $this->getMockBuilder(OperationalMovementWorkService::class)->setConstructorArgs([$facts])->onlyMethods(['singleActiveCompanyId', 'energyNeedsForCompany'])->getMock();
        $work->method('singleActiveCompanyId')->willReturn(1);
        $work->method('energyNeedsForCompany')->willReturn([]);
        $tasks = new TaskService($schedules, $health, $work);
        $today = $tasks->today(new DateTimeImmutable('2026-09-17 20:00:00'));
        $nextDay = $tasks->today(new DateTimeImmutable('2026-09-18 08:00:00'));
        $tomorrow = $tasks->tomorrow(new DateTimeImmutable('2026-09-17 20:00:00'));

        $this->assertSame([], $today['todays_returns']);
        $this->assertCount(1, $today['awaiting_recovery']);
        $this->assertSame([], $today['cleaning_tasks']);
        $this->assertSame([], $today['charging_tasks']);
        $this->assertCount(1, $nextDay['awaiting_recovery']);
        $this->assertSame([], $tomorrow['awaiting_recovery']);

        $command = new FleetCommandCenterViewModelService();
        $actions = (new ReflectionMethod($command, 'timeScopedActions'))->invoke($command, $today, 'today');
        $recover = array_values(array_filter($actions, static fn (array $row): bool => ($row['label'] ?? null) === 'Recover Vehicle'));
        $returns = array_values(array_filter($actions, static fn (array $row): bool => ($row['label'] ?? null) === "Today's Returns"));
        $this->assertCount(1, $recover);
        $this->assertSame(1, $recover[0]['count']);
        $this->assertStringContainsString('unverified', $recover[0]['detail']);
        $this->assertSame([], $returns);
        $queue = (new ReflectionMethod($command, 'queueView'))->invoke($command, 'today', $today, $tomorrow, [], []);
        $this->assertSame($actions, $queue['items']);
        $this->assertSame(array_sum(array_column($queue['items'], 'count')), $queue['scopes'][1]['count']);
        $tomorrowQueue = (new ReflectionMethod($command, 'queueView'))->invoke($command, 'tomorrow', $today, $tomorrow, [], []);
        $urgentQueue = (new ReflectionMethod($command, 'queueView'))->invoke($command, 'urgent', $today, $tomorrow, [], []);
        $this->assertSame(array_sum(array_column($tomorrowQueue['items'], 'count')), $tomorrowQueue['scopes'][2]['count']);
        $this->assertSame(array_sum(array_column($urgentQueue['items'], 'count')), $urgentQueue['scopes'][3]['count']);
    }

    public function testGuestReturnStateIsDistinctFromPickupStagingAndRecovery(): void
    {
        $resolver = new MovementStateResolver();
        $context = [
            'operational_status' => 'available',
            'trip_schedule' => ['ends_at' => '2026-09-17 19:00:00'],
            'latest_event' => ['event_code' => 'guest_return_staged', 'occurred_at' => '2026-09-17 18:00:00'],
            'blockers' => [['code' => 'energy_ready', 'label' => 'Charge/Fuel to 80%', 'severity' => 'critical']],
        ];
        $staged = $resolver->resolve($context, new DateTimeImmutable('2026-09-17 20:00:00'));
        $this->assertSame('awaiting_recovery', $staged['code']);
        $this->assertSame('Recover Vehicle', $staged['primary_action']['label']);
        $this->assertSame([], $staged['blockers']);
        $this->assertStringContainsString('unverified', $staged['primary_line']);

        $context['latest_event']['event_code'] = 'vehicle_staged';
        $context['trip_schedule']['starts_at'] = '2026-09-18 09:00:00';
        $pickup = $resolver->resolve($context, new DateTimeImmutable('2026-09-17 20:00:00'));
        $this->assertSame('staged_for_pickup', $pickup['code']);

        $context['latest_event']['event_code'] = 'vehicle_recovered';
        $recovered = $resolver->resolve($context, new DateTimeImmutable('2026-09-17 20:00:00'));
        $this->assertNotSame('awaiting_recovery', $recovered['code']);
    }

    public function testMovementCountsPreserveTwoTripsOnOneVehicle(): void
    {
        $board = [['fleet_vehicle_id' => 1, 'flags' => ['departing_today', 'returning_today'], 'primary_status' => 'departing_today']];
        $today = [
            'todays_pickups' => [['id' => 11], ['id' => 12]],
            'todays_returns' => [['id' => 13], ['id' => 14]],
        ];

        $counts = (new VehicleDailyStateService())->statusCounts($board, 0.0, null, $today);

        $this->assertSame(2, $counts['going_out_today']);
        $this->assertSame(2, $counts['returning_today']);
    }

    public function testCleaningTracksLatestDirtyFactAndCustodyAcrossDays(): void
    {
        $custody = [
            1 => ['event_code' => 'actual_return', 'occurred_at' => '2026-09-16 08:00:00', 'turo_trip_normalized_id' => 11],
            2 => ['event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-17 06:00:00', 'turo_trip_normalized_id' => 12],
        ];
        $cleanliness = [
            1 => ['cleanliness' => 'dirty', 'captured_at' => '2026-09-16 08:01:00'],
            2 => ['cleanliness' => 'dirty', 'captured_at' => '2026-09-17 06:01:00'],
        ];
        $repo = $this->getMockBuilder(OperationalFactsRepository::class)->disableOriginalConstructor()->onlyMethods(['activeFleetVehiclesForCompany', 'latestCustodyEventsForCompany', 'latestCleanlinessForCompany'])->getMock();
        $repo->method('activeFleetVehiclesForCompany')->willReturn([['id' => 1, 'display_name' => 'A'], ['id' => 2, 'display_name' => 'B']]);
        $repo->method('latestCustodyEventsForCompany')->willReturnCallback(static function () use (&$custody): array {
            return $custody;
        });
        $repo->method('latestCleanlinessForCompany')->willReturnCallback(static function () use (&$cleanliness): array {
            return $cleanliness;
        });
        $work = new OperationalMovementWorkService($repo);
        $asOf = new DateTimeImmutable('2026-09-17 12:00:00');

        $this->assertSame([1, 2], array_column($work->cleaningNeedsForCompany(1, $asOf), 'fleet_vehicle_id'));
        $cleanliness[1] = ['cleanliness' => 'clean', 'captured_at' => '2026-09-17 11:00:00'];
        $this->assertSame([2], array_column($work->cleaningNeedsForCompany(1, $asOf), 'fleet_vehicle_id'));
        $custody[2] = ['event_code' => 'actual_handoff', 'occurred_at' => '2026-09-17 11:30:00', 'turo_trip_normalized_id' => 13];
        $this->assertSame([], $work->cleaningNeedsForCompany(1, $asOf));
    }

    public function testEnergyDeficitRemainsFactualButStopsBeingActionableAfterHandoff(): void
    {
        $context = [
            'id' => 1, 'company_id' => 1, 'turo_trip_normalized_id' => 11, 'fleet_vehicle_id' => 1,
            'movement_type' => 'pickup', 'active_events' => [], 'active_assessment' => ['energy_percent' => 25, 'cleanliness' => 'clean', 'captured_at' => '2026-09-17 07:00:00'],
            'current_readiness_assessment' => null, 'profile' => ['ready_energy_target_percent' => 80],
            'airport_workflow' => null, 'scheduled_location' => null, 'items_by_code' => [], 'capabilities' => [],
            'completed_at' => null, 'readiness_status' => 'open', 'positioning_plan' => null, 'next_trip' => null,
        ];
        $service = new MovementReadinessProjectionService();
        $before = $service->project($context);
        $energyBefore = array_values(array_filter($before['requirements'], static fn (array $row): bool => $row['code'] === 'energy_ready'))[0];
        $this->assertSame('unsatisfied', $energyBefore['status']);
        $this->assertSame('Charge/Fuel to at least 80%', $energyBefore['action']['label']);

        $context['active_events']['actual_handoff'] = ['occurred_at' => '2026-09-17 08:00:00'];
        $after = $service->project($context);
        $energyAfter = array_values(array_filter($after['requirements'], static fn (array $row): bool => $row['code'] === 'energy_ready'))[0];
        $this->assertSame('unsatisfied', $energyAfter['status']);
        $this->assertFalse($energyAfter['actionable']);
        $this->assertNull($energyAfter['action']);
        $this->assertLessThan($before['blocking_remaining_count'], $after['blocking_remaining_count']);

        $context['active_events'] = [];
        $context['active_assessment']['energy_percent'] = null;
        $missing = $service->project($context);
        $known = array_values(array_filter($missing['requirements'], static fn (array $row): bool => $row['code'] === 'energy_known'))[0];
        $this->assertSame('Record current Charge/Fuel percentage', $known['action']['label']);
    }

    public function testChecklistFocusPrioritizesBlockingThenSummary(): void
    {
        $focus = new ChecklistActionFocusService();
        $requirements = [
            ['code' => 'optional', 'status' => 'unsatisfied', 'blocking' => false, 'action' => ['label' => 'Review']],
            ['code' => 'energy_known', 'status' => 'unsatisfied', 'blocking' => true, 'action' => ['label' => 'Record']],
        ];
        $projection = ['requirements' => $requirements, 'workflow_history' => ['legacy_items' => []]];
        $this->assertSame('checklist-action-energy_known', $focus->nextAnchor($projection));
        $projection['requirements'][1]['status'] = 'satisfied';
        $projection['requirements'][] = ['code' => 'required_review', 'status' => 'unsatisfied', 'blocking' => false, 'action' => ['label' => 'Confirm']];
        $projection['workflow_history']['legacy_items'][] = ['item_code' => 'required_review', 'is_required' => true];
        $this->assertSame('checklist-action-required_review', $focus->nextAnchor($projection));
        $this->assertSame('readiness-heading', $focus->nextAnchor(['requirements' => [], 'workflow_history' => ['legacy_items' => []]]));
    }

    public function testRecoveryAllowsNextPickupPreparationUntilThatPickupIsHandedOff(): void
    {
        $context = [
            'id' => 2, 'company_id' => 1, 'turo_trip_normalized_id' => 11, 'fleet_vehicle_id' => 1,
            'movement_type' => 'return', 'active_events' => ['vehicle_recovered' => ['occurred_at' => '2026-09-17 09:00:00']],
            'active_assessment' => ['energy_percent' => 25, 'cleanliness' => 'dirty', 'captured_at' => '2026-09-17 09:05:00'],
            'current_readiness_assessment' => null, 'target_pickup_assessment' => null,
            'profile' => ['ready_energy_target_percent' => 80], 'airport_workflow' => null,
            'scheduled_location' => null, 'items_by_code' => [], 'capabilities' => [],
            'completed_at' => null, 'readiness_status' => 'open', 'positioning_plan' => null,
            'next_trip' => ['id' => 12, 'starts_at' => '2026-09-17 15:00:00', 'is_same_day_turnaround' => true],
            'next_pickup_handoff' => null,
        ];
        $service = new MovementReadinessProjectionService();
        $before = $service->project($context);
        $energy = array_values(array_filter($before['requirements'], static fn (array $row): bool => $row['phase'] === 'next_pickup_preparation' && $row['code'] === 'energy_ready'))[0];
        $this->assertSame('Charge/Fuel to at least 80%', $energy['action']['label']);

        $context['next_pickup_handoff'] = ['occurred_at' => '2026-09-17 15:00:00'];
        $after = $service->project($context);
        $energy = array_values(array_filter($after['requirements'], static fn (array $row): bool => $row['phase'] === 'next_pickup_preparation' && $row['code'] === 'energy_ready'))[0];
        $this->assertFalse($energy['actionable']);
        $this->assertNull($energy['action']);
    }

    public function testMovementBoardDistinguishesMissingEnergyFromBelowTargetAndSuppressesHandoffActions(): void
    {
        $dashboard = new DailyOperationsDashboardService();
        $method = new ReflectionMethod($dashboard, 'attachChecklistSummaries');
        $board = [['fleet_vehicle_id' => 1, 'flags' => [], 'actions' => ['No action due']]];
        $summary = ['fleet_vehicle_id' => 1, 'href' => '/operations/checklists/1', 'blocking_remaining_count' => 1, 'additional_actions_remaining_count' => 0];
        $projection = ['readiness_phase' => 'pickup_preparation', 'requirements' => [
            ['code' => 'energy_known', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'action' => ['label' => 'Record current Charge/Fuel percentage']],
            ['code' => 'energy_ready', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'action' => ['label' => 'Charge/Fuel to 80%']],
        ]];
        $missing = $method->invoke($dashboard, $board, [array_merge($summary, ['readiness_projection' => $projection])])[0];
        $this->assertContains('energy_check_required', $missing['flags']);
        $this->assertNotContains('charging_required', $missing['flags']);

        $projection['requirements'][0]['status'] = 'satisfied';
        $below = $method->invoke($dashboard, $board, [array_merge($summary, ['readiness_projection' => $projection])])[0];
        $this->assertContains('charging_required', $below['flags']);
        $this->assertNotContains('energy_check_required', $below['flags']);

        $projection['requirements'][1]['actionable'] = false;
        $suppressed = $method->invoke($dashboard, $board, [array_merge($summary, ['readiness_projection' => $projection])])[0];
        $this->assertNotContains('charging_required', $suppressed['flags']);
        $this->assertSame(['No action due'], $suppressed['actions']);
    }
}
