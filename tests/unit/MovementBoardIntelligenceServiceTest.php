<?php

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\ImportFreshnessService;
use App\Services\Fleet\MovementBoardIntelligenceService;
use App\Services\Fleet\MovementStateResolver;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\VehiclePositioningPlanService;
use App\Services\Fleet\VehiclePositioningRecommendationService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class MovementBoardIntelligenceServiceTest extends CIUnitTestCase
{
    public function testHandoffWithoutAssessmentIsOnTripAndUsesPlannedReturnForPositioning(): void
    {
        $service = $this->service(
            ['id' => 90, 'turo_trip_normalized_id' => 900, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-09-03 08:05:00', 'location_class' => 'waikiki_hotel', 'location_detail' => 'Guest handoff'],
            ['id' => 900, 'starts_at' => '2026-09-03 08:00:00', 'ends_at' => '2026-09-05 17:00:00', 'return_location_class' => 'airport_hnl', 'return_location_source_text' => 'HNL Terminal 2'],
            ['cleanliness' => 'dirty', 'energy_percent' => null],
            ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80, 'capabilities' => ['key_card']],
            ['id' => 901, 'starts_at' => '2026-09-06 08:00:00', 'pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term', 'import_completed_at' => '2026-09-03 10:00:00'],
        );

        $card = $service->enrich([[
            'fleet_vehicle_id' => 9,
            'fleet_code' => 'Spaceship-09',
            'status' => 'available',
            'checklist_href' => '/operations/checklists/40',
            'checklists' => [
                ['movement_type' => 'pickup', 'href' => '/operations/checklists/40'],
                ['movement_type' => 'return', 'href' => '/operations/checklists/41'],
            ],
        ]], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('on_trip', $card['state']['code']);
        $this->assertContains('departure_energy_percent', $card['state']['missing_facts']);
        $this->assertSame('Currently Rented', $card['state']['label']);
        $this->assertSame('Planned return', $card['location_heading']);
        $this->assertSame('airport_hnl', $card['location_class']);
        $this->assertSame('Airport HNL', $card['location_class_label']);
        $this->assertSame('scheduled', $card['location_basis']);
        $this->assertSame('leave_at_airport', $card['recommendation']['code']);
        $this->assertSame('Recommended: Leave at HNL', $card['recommendation']['display_label']);
        $this->assertContains('Clean and charge on site.', $card['recommendation']['reason_labels']);
        $this->assertSame('Airport HNL', $card['next_trip']['pickup_location_label']);
        $this->assertSame('Charge', $card['energy_label']);
        $this->assertSame('Spaceship-09', $card['fleet_code']);
        $this->assertSame('Record return', $card['action']['label']);
        $this->assertSame('/operations/checklists/41', $card['action']['href']);
    }

    public function testActualReturnUsesActualLocationAndIncompleteAssessmentRequiresAction(): void
    {
        $service = $this->service(
            ['id' => 91, 'turo_trip_normalized_id' => 900, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-03 11:30:00', 'location_class' => 'home', 'location_detail' => 'Fleet yard'],
            ['id' => 900, 'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-03 11:00:00', 'return_location_class' => 'airport_hnl'],
            ['cleanliness' => null, 'energy_percent' => null],
            ['energy_kind' => 'gasoline', 'ready_energy_target_percent' => 70, 'capabilities' => []],
            null,
        );

        $card = $service->enrich([['fleet_vehicle_id' => 9, 'status' => 'in_progress', 'checklist_required_remaining' => 4, 'checklist_critical_open' => 0]], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('returned_assessment_required', $card['state']['code']);
        $this->assertSame('Return assessment needed', $card['state']['label']);
        $this->assertSame('Current location', $card['location_heading']);
        $this->assertSame('home', $card['location_class']);
        $this->assertSame('actual', $card['location_basis']);
        $this->assertSame('Fuel', $card['energy_label']);
        $this->assertSame('complete_return_assessment', $card['action']['code']);
        $this->assertSame([], $card['blockers']);
    }

    public function testStaleOperatorPlanIsVisibleButDoesNotSupplyTransportationTruth(): void
    {
        $service = $this->service(
            ['id' => 92, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-03 10:00:00', 'location_class' => 'waikiki_hotel', 'location_detail' => 'Front drive'],
            null,
            ['cleanliness' => 'clean', 'energy_percent' => 90],
            ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80, 'capabilities' => []],
            ['id' => 902, 'starts_at' => '2026-09-04 08:00:00', 'pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term', 'import_completed_at' => '2026-09-03 10:00:00'],
            ['positioning_code' => 'move_to_airport', 'transportation_state' => 'confirmed', 'is_basis_stale' => true],
        );

        $card = $service->enrich([['fleet_vehicle_id' => 9, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('operator_decision_needed', $card['recommendation']['code']);
        $this->assertSame('Stale - needs review', $card['operator_plan']['status_label']);
        $this->assertTrue($card['operator_plan']['is_basis_stale']);
    }

    public function testPositioningActionLinksToPositioningPlanWorkflow(): void
    {
        $service = $this->service(null, null, null, ['energy_kind' => 'unknown', 'capabilities' => []], null);

        $card = $service->enrich([['fleet_vehicle_id' => 9, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('Set positioning plan', $card['action']['label']);
        $this->assertSame('/fleet/vehicles/9/positioning-plan', $card['action']['href']);
    }

    public function testOperatorPlanAgreementAndDisagreementNeverReplaceRecommendation(): void
    {
        $event = ['id' => 94, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-03 10:00:00', 'location_class' => 'airport_hnl'];
        $nextTrip = ['id' => 903, 'starts_at' => '2026-09-04 08:00:00', 'pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term', 'import_completed_at' => '2026-09-03 10:00:00'];
        $plan = ['positioning_code' => 'leave_at_airport', 'target_location_class' => 'airport_hnl', 'reason_code' => 'turnaround', 'transportation_state' => 'not_applicable', 'created_by' => 7, 'actor_username' => 'jlamping', 'created_at' => '2026-09-03 11:45:00', 'is_basis_stale' => false];

        $agreeing = $this->service($event, null, ['cleanliness' => 'dirty', 'energy_percent' => 26], ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80], $nextTrip, $plan)
            ->enrich([['fleet_vehicle_id' => 3, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];
        $plan['positioning_code'] = 'retrieve_home';
        $plan['target_location_class'] = 'home';
        $disagreeing = $this->service($event, null, ['cleanliness' => 'dirty', 'energy_percent' => 26], ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80], $nextTrip, $plan)
            ->enrich([['fleet_vehicle_id' => 3, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('Recommended: Leave at HNL', $agreeing['recommendation']['display_label']);
        $this->assertSame('Operator plan agrees with recommendation', $agreeing['operator_plan']['status_label']);
        $this->assertSame('jlamping', $agreeing['operator_plan']['actor_label']);
        $this->assertSame('Recommended: Leave at HNL', $disagreeing['recommendation']['display_label']);
        $this->assertSame('Retrieve to home', $disagreeing['operator_plan']['label']);
        $this->assertSame('Operator plan differs from recommendation', $disagreeing['operator_plan']['status_label']);
    }

    public function testActualTerminalGarageSurfacesRelocationAttention(): void
    {
        $service = $this->service(
            ['id' => 93, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-03 10:00:00', 'location_class' => 'airport_hnl', 'airport_garage_code' => 'terminal_2', 'airport_parking_level' => 4, 'airport_parking_row' => 'M'],
            null,
            ['cleanliness' => 'clean', 'energy_percent' => 90],
            ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80, 'capabilities' => []],
            ['id' => 903, 'starts_at' => '2026-09-04 08:00:00', 'pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term', 'import_completed_at' => '2026-09-03 10:00:00'],
        );

        $card = $service->enrich([['fleet_vehicle_id' => 9, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('Terminal 2 Garage · Red', $card['airport_garage_line']);
        $this->assertSame('Level 4 · Row M', $card['airport_position_line']);
        $this->assertFalse($card['approved_turo_garage']);
        $this->assertSame('wrong_airport_garage', $card['blockers'][0]['code']);
        $this->assertSame('relocate_to_international', $card['recommendation']['code']);
    }

    public function testLegacySpaceship03DetailPresentsAsStructuredInternationalGarage(): void
    {
        $service = $this->service(
            ['id' => 94, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-03 10:00:00', 'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7 RF'],
            null,
            ['cleanliness' => 'dirty', 'energy_percent' => 26],
            ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80, 'capabilities' => []],
            null,
        );

        $card = $service->enrich([['fleet_vehicle_id' => 3, 'status' => 'available']], new DateTimeImmutable('2026-09-03 12:00:00'))[0];

        $this->assertSame('International Garage · Blue', $card['airport_garage_line']);
        $this->assertSame('Level 7 · Row F', $card['airport_position_line']);
        $this->assertTrue($card['approved_turo_garage']);
        $this->assertSame('International Garage L7 RF', $card['location_detail']);
    }

    private function service(?array $event, ?array $schedule, ?array $assessment, array $profile, ?array $nextTrip, ?array $plan = null): MovementBoardIntelligenceService
    {
        $repository = $this->createStub(OperationalFactsRepository::class);
        $repository->method('latestActiveMovementEvent')->willReturn($event);
        $repository->method('latestActiveLifecycleEvent')->willReturn($event);
        $repository->method('tripSchedule')->willReturn($schedule);
        $repository->method('assessmentForEventOrTrip')->willReturn($assessment);
        $repository->method('profile')->willReturn($profile);

        $nextTrips = $this->createStub(NextConfirmedTripService::class);
        $nextTrips->method('forVehicle')->willReturn($nextTrip);
        $plans = $this->createStub(VehiclePositioningPlanService::class);
        $plans->method('active')->willReturn($plan);

        return new MovementBoardIntelligenceService(
            $repository,
            $nextTrips,
            new ImportFreshnessService(),
            new MovementStateResolver(),
            new VehiclePositioningRecommendationService(),
            $plans,
        );
    }
}
