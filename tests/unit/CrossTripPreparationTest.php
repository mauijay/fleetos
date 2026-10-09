<?php

use App\Services\Fleet\ChecklistActionFocusService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\TripPreparationViewModelService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class CrossTripPreparationTest extends CIUnitTestCase
{
    public function testFutureExtrasAreSeparatedFromTargetOnPickupAndReturn(): void
    {
        foreach (['pickup', 'return'] as $movement) {
            $checklist = ['company_id' => 1, 'turo_trip_normalized_id' => 501, 'movement_type' => $movement];
            $data = (new TripPreparationViewModelService())->forChecklist($checklist, [501 => [$this->extra(501, 601)], 502 => [$this->extra(502, 602)]], ['next_trip' => $this->futureTrip()]);
            $this->assertSame($movement === 'return' ? [] : [501], array_column($data['target'], 'turo_trip_normalized_id'));
            $this->assertSame([502], array_column($data['future'], 'turo_trip_normalized_id'));
            $html = $this->primary($checklist, $data['target']);
            $this->assertStringNotContainsString('Synthetic kit 602', $html);
        }
    }

    public function testOwnerExtraAppearsOnceAndForeignRowsAreRejectedByModelAndView(): void
    {
        $checklist = ['company_id' => 1, 'turo_trip_normalized_id' => 502, 'movement_type' => 'pickup'];
        $own = $this->extra(502, 602);
        $foreign = $this->extra(501, 601);
        $company = array_merge($this->extra(502, 603), ['company_id' => 2]);
        $data = (new TripPreparationViewModelService())->forChecklist($checklist, [502 => [$own, $own, $foreign, $company]], []);
        $this->assertCount(1, $data['target']);
        $html = $this->primary($checklist, [$own, $foreign]);
        $this->assertSame(1, substr_count($html, '<h3>Synthetic kit 602</h3>'));
        $this->assertStringNotContainsString('Synthetic kit 601', $html);
        $this->assertStringContainsString('/operations/trips/502/extra-fulfillments/702/complete', $html);
    }

    public function testInformationalAndUnknownExtrasRemainVisibleOnlyForTheirOwner(): void
    {
        $info = array_merge($this->extra(502, 604), ['is_informational' => true, 'is_actionable' => false, 'fulfillment_type' => 'informational']);
        $unknown = array_merge($this->extra(502, 605), ['is_mapped' => false, 'configured' => false, 'is_actionable' => false]);
        $checklist = ['company_id' => 1, 'turo_trip_normalized_id' => 501, 'movement_type' => 'return'];
        $data = (new TripPreparationViewModelService())->forChecklist($checklist, [502 => [$info, $unknown]], ['next_trip' => $this->futureTrip()]);
        $this->assertSame([], $data['target']);
        $this->assertCount(2, $data['future']);
        $this->assertStringNotContainsString('Synthetic kit', $this->primary($checklist, [$info, $unknown]));
        $html = $this->primary(['turo_trip_normalized_id' => 502], $data['future']);
        $this->assertStringContainsString('No operator confirmation required', $html);
        $this->assertStringContainsString('Operational mapping required', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testFutureSectionIdentifiesOwnerAndRoutesCompletionToFutureTrip(): void
    {
        $html = Services::renderer()->setData([
            'checklist' => ['company_id' => 1, 'turo_trip_normalized_id' => 501],
            'readiness' => ['next_trip' => $this->futureTrip(), 'requirements' => []],
            'futureExtraPreparation' => [$this->extra(502, 602)],
        ])->render('trip_movement_checklists/_future_preparation');
        $this->assertStringContainsString('Future vehicle preparation', $html);
        $this->assertStringContainsString('Trip 502', $html);
        $this->assertStringContainsString('Reservation 80000502', $html);
        $this->assertStringContainsString('2030-01-03 09:00:00', $html);
        $this->assertStringContainsString('does not affect this movement', $html);
        $this->assertStringContainsString('/operations/trips/502/extra-fulfillments/702/complete', $html);
        $this->assertStringNotContainsString('id="trip-preparation"', $html);
        $this->assertStringNotContainsString('/operations/trips/501/extra-fulfillments', $html);
    }

    public function testDeferredExtraHasNoCompletionButtonAndExplainsItsBlockingState(): void
    {
        $checklist = ['company_id' => 1, 'turo_trip_normalized_id' => 502, 'movement_type' => 'pickup'];
        $requirement = ['source_type' => 'extra_fulfillment', 'trip_id' => 502, 'fulfillment_id' => 702, 'actionable' => false, 'blocking' => true, 'relevant' => true, 'status' => 'unsatisfied', 'deferred_label' => 'Vehicle is currently in guest custody on another trip.'];
        $data = (new TripPreparationViewModelService())->forChecklist($checklist, [502 => [$this->extra(502, 602)]], ['requirements' => [$requirement]]);
        $html = $this->primary($checklist, $data['target']);
        $this->assertStringContainsString('Pending · Blocks readiness', $html);
        $this->assertStringContainsString('guest custody on another trip', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('Recorded at handoff', $html);
    }

    public function testManualWorkIsOwnerScopedAndDeferredControlsAreHidden(): void
    {
        $manual = ['id' => 803, 'turo_trip_normalized_id' => 502, 'instruction' => 'Synthetic manual kit', 'is_blocking' => true, 'category' => 'guest_amenity', 'category_label' => 'Guest amenity', 'phase_label' => 'Preparation', 'arranged_at' => null, 'handling_mode' => 'task'];
        $data = ['checklist' => ['turo_trip_normalized_id' => 501], 'guestCommitments' => [$manual], 'extraPreparation' => [], 'extraVerification' => null];
        $html = Services::renderer()->setData($data)->render('trip_movement_checklists/_guest_commitments');
        $this->assertStringNotContainsString('Synthetic manual kit', $html);
        $data['checklist']['turo_trip_normalized_id'] = 502;
        $data['readiness']['requirements'] = [['code' => 'guest_commitment_803', 'actionable' => false, 'deferred_label' => 'Vehicle is currently in guest custody on another trip.']];
        $html = Services::renderer()->setData($data)->render('trip_movement_checklists/_guest_commitments');
        $this->assertStringContainsString('Synthetic manual kit', $html);
        $this->assertStringContainsString('Blocks readiness', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testActionFocusExcludesFutureAndDeferredWork(): void
    {
        $focus = new ChecklistActionFocusService();
        $projection = ['trip_id' => 501, 'requirements' => [
            ['code' => 'future', 'trip_id' => 502, 'phase' => 'next_pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'actionable' => true, 'action' => ['label' => 'Future']],
            ['code' => 'deferred', 'trip_id' => 501, 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'actionable' => false, 'action' => null],
            ['code' => 'current', 'trip_id' => 501, 'phase' => 'pickup_preparation', 'blocking' => false, 'status' => 'unsatisfied', 'actionable' => true, 'action' => ['label' => 'Current']],
        ]];
        $this->assertSame('checklist-action-current', $focus->nextAnchor($projection));
        array_pop($projection['requirements']);
        $this->assertSame('readiness-heading', $focus->nextAnchor($projection));
    }

    public function testTargetExtraFocusFindsItsRenderedCompletionControl(): void
    {
        $projection = ['trip_id' => 502, 'requirements' => [
            ['code' => 'extra_fulfillment_702', 'trip_id' => 502, 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'actionable' => true, 'action' => ['label' => 'Install synthetic seat']],
        ]];
        $anchor = (new ChecklistActionFocusService())->nextAnchor($projection);
        $html = $this->primary(['turo_trip_normalized_id' => 502], [$this->extra(502, 602)]);
        $this->assertSame('extra-fulfillment-702', $anchor);
        $this->assertStringContainsString('id="' . $anchor . '"', $html);
    }

    public function testManualCommitmentHasOneCompletionFormAcrossReadinessAndGuestSections(): void
    {
        $manual = ['id' => 803, 'turo_trip_normalized_id' => 502, 'instruction' => 'Synthetic manual kit', 'is_blocking' => true, 'category' => 'guest_amenity', 'category_label' => 'Guest amenity', 'phase_label' => 'Preparation', 'arranged_at' => null, 'handling_mode' => 'task'];
        $requirement = ['code' => 'guest_commitment_803', 'label' => 'Synthetic manual kit', 'phase' => 'pickup_preparation', 'kind' => 'human', 'status' => 'unsatisfied', 'blocking' => true, 'actionable' => true,
            'action' => ['type' => 'guest_commitment_complete', 'trip_id' => 502, 'commitment_id' => 803, 'label' => 'Synthetic manual kit']];
        $data = ['checklist' => ['id' => 902, 'turo_trip_normalized_id' => 502, 'movement_type' => 'pickup', 'completed_at' => null, 'items' => []], 'readiness' => ['readiness_phase' => 'pickup_preparation', 'ready' => false, 'blocking_remaining_count' => 1, 'requirements' => [$requirement]],
            'tripFacts' => [], 'guestCommitments' => [$manual], 'extraPreparation' => [], 'extraVerification' => null];
        $renderer = Services::renderer();
        $html = $renderer->setData($data)->render('trip_movement_checklists/_readiness');
        $html .= $renderer->setData($data)->render('trip_movement_checklists/_guest_commitments');
        $this->assertSame(1, substr_count($html, 'action="/operations/trips/502/commitments/803/complete"'));
        $this->assertStringContainsString('href="#guest-commitment-803"', $html);
        $this->assertStringContainsString('id="guest-commitment-803"', $html);
    }

    public function testDashboardCountsDeferredBlockerButExcludesRetiredWork(): void
    {
        $requirement = ['code' => 'extra_fulfillment_702', 'label' => 'Synthetic pending kit', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'actionable' => false, 'relevant' => true, 'action' => null];
        $summary = ['fleet_vehicle_id' => 11, 'href' => '/operations/checklists/901', 'blocking_remaining_count' => 1, 'additional_actions_remaining_count' => 0, 'readiness_projection' => ['readiness_phase' => 'pickup_preparation', 'requirements' => [$requirement]]];
        $service = new DailyOperationsDashboardService();
        $method = new ReflectionMethod($service, 'attachChecklistSummaries');
        $board = [['fleet_vehicle_id' => 11, 'flags' => [], 'actions' => []]];
        $result = $method->invoke($service, $board, [$summary])[0];
        $this->assertSame(1, $result['readiness_blocking_remaining']);
        $this->assertCount(1, $result['readiness_blockers']);
        $this->assertFalse($result['checklist_ready']);
        $this->assertNull($result['readiness_primary_action']);
        $queue = new ReflectionMethod($service, 'operationalQueue');
        $tasks = $queue->invoke($service, ['cleaning_tasks' => [], 'charging_tasks' => [], 'airport_deliveries' => [], 'todays_pickups' => [], 'todays_returns' => []], [], ['total_unresolved' => 0, 'href' => '#issues'], ['unique_unmatched_vehicles' => 0, 'href' => '#vehicles'], ['awaiting_reconciliation' => 0, 'href' => '#rows'], ['airport_workflows_requiring_action' => 0, 'href' => '#airport'], ['total_actionable' => 0, 'href' => '#receipts'], ['total' => 0, 'href' => '#incidentals'], ['total' => 0, 'href' => '#expenses'], [$summary]);
        $byCode = array_column($tasks, null, 'code');
        $this->assertSame(1, $byCode['readiness']['count']);
        $summary['blocking_remaining_count'] = 0;
        $summary['readiness_projection']['requirements'][0]['relevant'] = false;
        $result = $method->invoke($service, $board, [$summary])[0];
        $this->assertSame([], $result['readiness_blockers']);
        $this->assertTrue($result['checklist_ready']);
    }

    public function testDashboardKeepsFutureTurnaroundBlockersOutsideCurrentReadiness(): void
    {
        $requirement = ['code' => 'extra_fulfillment_702', 'label' => 'Future kit', 'phase' => 'next_pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'actionable' => true, 'relevant' => true, 'action' => ['label' => 'Configure future kit']];
        $summary = ['fleet_vehicle_id' => 11, 'href' => '/operations/checklists/901', 'blocking_remaining_count' => 0, 'additional_actions_remaining_count' => 0, 'readiness_projection' => ['readiness_phase' => 'return_intake', 'is_same_day_turnaround' => true, 'requirements' => [$requirement]]];
        $method = new ReflectionMethod(new DailyOperationsDashboardService(), 'attachChecklistSummaries');
        $result = $method->invoke(new DailyOperationsDashboardService(), [['fleet_vehicle_id' => 11, 'flags' => [], 'actions' => []]], [$summary])[0];
        $this->assertSame(0, $result['readiness_blocking_remaining']);
        $this->assertSame([], $result['readiness_blockers']);
        $this->assertSame(1, $result['turnaround_readiness_remaining']);
        $this->assertTrue($result['checklist_ready']);
    }

    private function futureTrip(): array
    {
        return ['id' => 502, 'turo_reservation_id' => '80000502', 'starts_at' => '2030-01-03 09:00:00'];
    }

    private function primary(array $checklist, array $extras): string
    {
        return trim(Services::renderer()->setData(['checklist' => $checklist, 'extraPreparation' => $extras, 'isFuturePreparation' => false, 'preparationTrip' => null])->render('trip_movement_checklists/_trip_preparation'));
    }

    private function extra(int $tripId, int $selection): array
    {
        return ['company_id' => 1, 'turo_trip_normalized_id' => $tripId, 'selection_id' => $selection, 'fulfillment_id' => $selection + 100, 'title' => 'Synthetic kit ' . $selection, 'is_actionable' => true, 'is_removed' => false, 'is_completed' => false, 'is_informational' => false, 'is_mapped' => true, 'configured' => true, 'fulfillment_phase' => 'preparation', 'quantity_unknown' => false, 'linked_commitments' => [], 'action_label' => 'Configure the synthetic kit', 'confirmation_label' => 'Confirm configured'];
    }
}
