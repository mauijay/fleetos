<?php

use App\Repositories\MovementReadinessReadModelRepository;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\ExtrasVerificationFreshnessPolicy;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetExtraService;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\MovementReadinessReadService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class ExtrasVerificationSurfacesTest extends CIUnitTestCase
{
    public function testReadinessBatchesEvidenceWithOneClockAndKeepsFutureOwnership(): void
    {
        $asOf = new DateTimeImmutable('2030-01-02 22:00:00 UTC');
        $future = ['id' => 101, 'starts_at' => '2030-01-02 14:00:00', 'turo_reservation_id' => '70000001'];
        $contexts = [10 => $this->context(10, 100, 'return', ['next_trip' => $future]), 11 => $this->context(11, 101, 'pickup')];
        $repo = $this->createMock(MovementReadinessReadModelRepository::class);
        $repo->expects($this->once())->method('loadForCompany')->with(1, [10, 11], $this->callback(static fn (DateTimeImmutable $clock): bool => $clock->getTimestamp() === $asOf->getTimestamp()))->willReturn($contexts);
        $verification = $this->createMock(FleetExtraService::class);
        $model = $this->model(101);
        $verification->expects($this->once())->method('verificationForTrips')->with(1, [100, 101], $this->callback(static fn (DateTimeImmutable $clock): bool => $clock->getTimestamp() === $asOf->getTimestamp()))->willReturn([101 => $model]);
        $service = new MovementReadinessReadService($repo, new MovementReadinessProjectionService(), extraVerificationService: $verification);
        $projections = $service->forCompany(1, [10, 11], $asOf);
        $returnRequirements = $this->sourceRequirements($projections[10]);
        $pickupRequirements = $this->sourceRequirements($projections[11]);
        $this->assertCount(1, $returnRequirements);
        $this->assertCount(1, $pickupRequirements);
        $this->assertSame('next_pickup_preparation', $returnRequirements[0]['phase']);
        $this->assertSame(101, $returnRequirements[0]['trip_id']);
        $this->assertSame($returnRequirements[0]['work_identity'], $pickupRequirements[0]['work_identity']);
        $this->assertTrue($projections[10]['ready']);
        $this->assertNotSame('checklist-action-extras_verification_1_101', (new \App\Services\Fleet\ChecklistActionFocusService())->nextAnchor($projections[10]));
        $this->assertFalse($projections[11]['ready']);
        $this->assertSame($model, $projections[11]['extra_verification']);
        $queue = (new ReflectionMethod(DailyOperationsDashboardService::class, 'extrasVerificationQueue'))->invoke(new DailyOperationsDashboardService(), [
            ['readiness_projection' => $projections[10]], ['readiness_projection' => $projections[11]],
        ]);
        $this->assertCount(1, $queue);
        $this->assertSame('Refresh Turo Extras', $queue[0]['label']);
        $this->assertSame($model['action_href'], $queue[0]['href']);
        $this->assertSame('required', $queue[0]['urgency']);
        $focus = (new \App\Services\Fleet\ChecklistActionFocusService())->nextAnchor(['trip_id' => 101, 'requirements' => $pickupRequirements]);
        $this->assertSame('checklist-action-extras_verification_1_101', $focus);
    }

    public function testQueueScopesKeepUrgentOverdueAndTomorrowActions(): void
    {
        $method = new ReflectionMethod(FleetCommandCenterViewModelService::class, 'queueView');
        $service = new FleetCommandCenterViewModelService();
        $asOf = new DateTimeImmutable('2030-01-02 22:00:00 UTC');
        $base = ['label' => 'Refresh Turo Extras', 'count' => 1, 'detail' => 'Synthetic verification', 'href' => '/turo/extras?reservation_id=70000001#export-heading', 'actionable' => true];
        $actions = [array_merge($base, ['code' => 'overdue', 'pickup_at' => '2030-01-01 09:00:00', 'urgency' => 'required']),
            array_merge($base, ['code' => 'tomorrow', 'pickup_at' => '2030-01-03 09:00:00', 'urgency' => 'required']),
            array_merge($base, ['code' => 'upcoming', 'pickup_at' => '2030-01-04 09:00:00', 'urgency' => 'advisory'])];
        $today = $method->invoke($service, 'today', [], [], [], $actions, $actions, $asOf);
        $tomorrow = $method->invoke($service, 'tomorrow', [], [], [], $actions, $actions, $asOf);
        $urgent = $method->invoke($service, 'urgent', [], [], [], $actions, $actions, $asOf);
        $all = $method->invoke($service, null, [], [], [], $actions, $actions, $asOf);
        $this->assertSame(['overdue'], array_column($today['items'], 'code'));
        $this->assertSame(['tomorrow'], array_column($tomorrow['items'], 'code'));
        $this->assertSame(['overdue', 'tomorrow'], array_column($urgent['items'], 'code'));
        $this->assertCount(3, $all['items']);
    }

    public function testOtherTripHandoffDoesNotRetireTargetButOwnHandoffDoes(): void
    {
        $projector = new MovementReadinessProjectionService();
        $context = ['company_id' => 1, 'turo_trip_normalized_id' => 101, 'fleet_vehicle_id' => 9, 'extra_verification' => $this->model(101)];
        $other = $projector->projectTripPreparation(array_merge($context, ['active_events' => ['actual_handoff' => ['turo_trip_normalized_id' => 100]]]));
        $own = $projector->projectTripPreparation(array_merge($context, ['active_events' => ['actual_handoff' => ['turo_trip_normalized_id' => 101]]]));
        $this->assertFalse($other['ready']);
        $this->assertTrue($other['requirements'][0]['actionable']);
        $this->assertTrue($own['ready']);
        $this->assertFalse($own['requirements'][0]['actionable']);
        $this->assertSame('target_handoff', $own['requirements'][0]['retired_reason']);
    }

    public function testChecklistSourceActionIsNavigationRatherThanManualCompletion(): void
    {
        $model = $this->model(101);
        $projection = (new MovementReadinessProjectionService())->project($this->context(11, 101, 'pickup', ['extra_verification' => $model]));
        $html = html_entity_decode(\Config\Services::renderer()->setData(['checklist' => ['id' => 11, 'completed_at' => null, 'items' => []], 'readiness' => $projection, 'tripFacts' => ['pickup' => null, 'return' => null]])->render('trip_movement_checklists/_readiness'));
        $this->assertStringContainsString($model['action_href'], $html);
        $this->assertStringContainsString('checklist-action-extras_verification_1_101', $html);
        $this->assertStringNotContainsString('extras_verification_1_101/complete', $html);
        $futureContext = $this->context(10, 100, 'return', ['next_trip' => ['id' => 101, 'starts_at' => '2030-01-02 14:00:00'], 'next_trip_extra_verification' => $model]);
        $future = (new MovementReadinessProjectionService())->project($futureContext);
        $futureHtml = html_entity_decode(\Config\Services::renderer()->setData(['readiness' => $future, 'checklist' => ['id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'], 'futureExtraPreparation' => []])->render('trip_movement_checklists/_future_preparation'));
        $this->assertStringContainsString($model['action_href'], $futureHtml);
        $this->assertStringContainsString('does not affect this movement', $futureHtml);
    }

    private function model(int $tripId): array
    {
        return (new ExtrasVerificationFreshnessPolicy())->assess(
            ['observed_at' => '2029-12-17 22:00:00', 'extra_count' => 0],
            ['company_id' => 1, 'trip_id' => $tripId, 'fleet_vehicle_id' => 9, 'reservation_id' => '70000001', 'starts_at' => '2030-01-02 14:00:00'],
            new DateTimeImmutable('2030-01-02 22:00:00 UTC'),
            'Pacific/Honolulu'
        );
    }

    private function context(int $id, int $tripId, string $type, array $override = []): array
    {
        return array_merge(['id' => $id, 'company_id' => 1, 'turo_trip_normalized_id' => $tripId, 'fleet_vehicle_id' => 9,
            'movement_type' => $type, 'starts_at' => '2030-01-02 14:00:00', 'active_events' => $type === 'return' ? ['actual_return' => ['occurred_at' => '2030-01-02 11:00:00']] : [],
            'items_by_code' => [], 'capabilities' => [], 'profile' => [], 'airport_workflow' => null, 'scheduled_location' => null,
            'positioning_plan' => null, 'completed_at' => null, 'readiness_status' => 'open', 'next_trip' => null], $override);
    }

    private function sourceRequirements(array $projection): array
    {
        return array_values(array_filter($projection['requirements'], static fn (array $row): bool => $row['source_type'] === 'extras_verification'));
    }
}
