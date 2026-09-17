<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\ChecklistActionFocusService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\OperationalMovementWorkService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\VehicleDailyStateService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalCorrectnessTest extends CIUnitTestCase
{
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
        $facts = $this->getMockBuilder(OperationalFactsRepository::class)->disableOriginalConstructor()->onlyMethods(['authoritativeMovementCompletionsForCompany'])->getMock();
        $facts->method('authoritativeMovementCompletionsForCompany')->willReturn([
            ['turo_trip_normalized_id' => 11, 'event_code' => 'actual_handoff', 'created_at' => '2026-09-17 08:05:00'],
            ['turo_trip_normalized_id' => 12, 'event_code' => 'actual_return', 'created_at' => '2026-09-17 08:30:00'],
            ['turo_trip_normalized_id' => 13, 'event_code' => 'vehicle_positioned', 'created_at' => '2026-09-17 08:30:00'],
        ]);
        $work = $this->getMockBuilder(OperationalMovementWorkService::class)->setConstructorArgs([$facts])->onlyMethods(['singleActiveCompanyId'])->getMock();
        $work->method('singleActiveCompanyId')->willReturn(1);
        $tasks = new TaskService($repo, $health, $work);

        $today = $tasks->today(new DateTimeImmutable('2026-09-17 12:00:00'));
        $tomorrow = $tasks->tomorrow(new DateTimeImmutable('2026-09-17 12:00:00'));
        $this->assertSame([13], array_column($today['todays_pickups'], 'id'));
        $this->assertSame([13], array_column($today['todays_returns'], 'id'));
        $this->assertSame([14], array_column($tomorrow['todays_pickups'], 'id'));
        $this->assertSame([14], array_column($tomorrow['todays_returns'], 'id'));
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
        $this->assertSame('Charge/Fuel to 80%', $energyBefore['action']['label']);

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
        $this->assertSame('Record Charge/Fuel percentage', $known['action']['label']);
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
        $this->assertSame('Charge/Fuel to 80%', $energy['action']['label']);

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
            ['code' => 'energy_known', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'action' => ['label' => 'Record Charge/Fuel percentage']],
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
