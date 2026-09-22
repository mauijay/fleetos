<?php

use App\Services\Fleet\MovementReadinessProjectionService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TripCommitmentReadinessTest extends CIUnitTestCase
{
    public function testRequiredTaskAddsExactlyOneBlockerAndInformationAddsNone(): void
    {
        $task = $this->commitment(501, 'task', true, 'Cooler with ice');
        $information = $this->commitment(502, 'informational', false, 'Meet at hotel valet entrance');
        $projection = (new MovementReadinessProjectionService())->project($this->context([$task, $information]));

        $this->assertSame(1, $projection['blocking_remaining_count']);
        $requirement = $this->requirement($projection, 'guest_commitment_501');
        $this->assertSame('guest_commitment_complete', $requirement['action']['type']);
        $this->assertNotContains('guest_commitment_502', array_column($projection['requirements'], 'code'));
    }

    public function testCompletedTaskClearsBlockerBecauseOnlyActiveCommitmentsAreProjected(): void
    {
        $before = (new MovementReadinessProjectionService())->project($this->context([$this->commitment(501, 'task', true, 'Cooler with ice')]));
        $after = (new MovementReadinessProjectionService())->project($this->context([]));

        $this->assertSame(1, $before['blocking_remaining_count']);
        $this->assertSame(0, $after['blocking_remaining_count']);
    }

    public function testRequiredAcknowledgmentBlocksUntilAcknowledged(): void
    {
        $open = $this->commitment(503, 'acknowledgment', true, 'Review valet meeting point');
        $acknowledged = array_merge($open, ['acknowledged_at' => '2026-11-11 12:00:00']);

        $openProjection = (new MovementReadinessProjectionService())->project($this->context([$open]));
        $acknowledgedProjection = (new MovementReadinessProjectionService())->project($this->context([$acknowledged]));

        $this->assertSame(1, $openProjection['blocking_remaining_count']);
        $this->assertSame('guest_commitment_acknowledge', $this->requirement($openProjection, 'guest_commitment_503')['action']['type']);
        $this->assertSame(0, $acknowledgedProjection['blocking_remaining_count']);
    }

    public function testGuestPossessionSuppressesImpossiblePreparationWithoutRemovingFact(): void
    {
        $context = $this->context([$this->commitment(504, 'task', true, 'Install child seats')]);
        $context['active_events']['actual_handoff'] = [
            'id' => 90, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-11-11 14:00:00', 'location_class' => 'other_delivery',
        ];
        $projection = (new MovementReadinessProjectionService())->project($context);
        $requirement = $this->requirement($projection, 'guest_commitment_504');

        $this->assertSame(0, $projection['blocking_remaining_count']);
        $this->assertFalse($requirement['actionable']);
        $this->assertNull($requirement['action']);
        $this->assertSame('unsatisfied', $requirement['status']);
    }

    public function testReturnCommitmentAddsExactlyOneReturnIntakeBlocker(): void
    {
        $context = $this->context([$this->commitment(505, 'task', true, 'Leave key card in center console')]);
        $context['movement_type'] = 'return';
        $projection = (new MovementReadinessProjectionService())->project($context);

        $this->assertSame(2, $projection['blocking_remaining_count']);
        $this->assertSame(
            ['vehicle_recovery', 'guest_commitment_505'],
            array_column(array_filter(
                $projection['requirements'],
                static fn (array $requirement): bool => $requirement['phase'] === MovementReadinessProjectionService::PHASE_RETURN_INTAKE,
            ), 'code'),
        );
    }

    public function testPurchasedExtraAddsOneSharedReadinessBlockerAndCompletionClearsIt(): void
    {
        $context = $this->context([]);
        $context['extra_fulfillments'] = [$this->fulfillment(701, false, true)];
        $pending = (new MovementReadinessProjectionService())->project($context);
        $requirement = $this->requirement($pending, 'extra_fulfillment_701');

        $this->assertSame(1, $pending['blocking_remaining_count']);
        $this->assertSame('extra_fulfillment_complete', $requirement['action']['type']);
        $this->assertSame('Pack 2 beach gear set(s)', $requirement['action']['label']);

        $context['extra_fulfillments'] = [$this->fulfillment(701, true, false)];
        $completed = (new MovementReadinessProjectionService())->project($context);
        $this->assertSame(0, $completed['blocking_remaining_count']);
        $this->assertSame('satisfied', $this->requirement($completed, 'extra_fulfillment_701')['status']);
    }

    public function testInformationalExtraCreatesNoRequirementAndHandoffSuppressesPhysicalWork(): void
    {
        $context = $this->context([]);
        $context['extra_fulfillments'] = [array_merge($this->fulfillment(702, false, false), [
            'fulfillment_type' => 'informational', 'requires_operator_confirmation' => false,
        ])];
        $informational = (new MovementReadinessProjectionService())->project($context);
        $this->assertNotContains('extra_fulfillment_702', array_column($informational['requirements'], 'code'));

        $context['extra_fulfillments'] = [$this->fulfillment(703, false, false)];
        $context['active_events']['actual_handoff'] = ['id' => 91, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-11-11 14:00:00'];
        $suppressed = (new MovementReadinessProjectionService())->project($context);
        $requirement = $this->requirement($suppressed, 'extra_fulfillment_703');
        $this->assertFalse($requirement['actionable']);
        $this->assertNull($requirement['action']);
    }

    public function testRemovedFulfillmentCreatesNoRequirementOrBlocker(): void
    {
        $context = $this->context([]);
        $context['active_events']['actual_handoff'] = [
            'id' => 92, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-11-11 14:00:00',
        ];
        $context['extra_fulfillments'] = [array_merge($this->fulfillment(704, true, true), [
            'removed_at' => '2026-11-11 15:00:00', 'is_removed' => true,
        ])];

        $removed = (new MovementReadinessProjectionService())->project($context);

        $this->assertNotContains('extra_fulfillment_704', array_column($removed['requirements'], 'code'));
        $this->assertSame(0, $removed['blocking_remaining_count']);
    }

    public function testUnknownPickupEnergyEmitsOnlyTheMeasurementActionForElectricAndGasoline(): void
    {
        foreach (['electric', 'gasoline'] as $energyKind) {
            $context = $this->context([]);
            $context['profile']['energy_kind'] = $energyKind;
            $context['active_assessment']['energy_percent'] = null;

            $projection = (new MovementReadinessProjectionService())->project($context);
            $energy = $this->energyRequirements($projection);

            $this->assertSame(['energy_known'], array_column($energy, 'code'));
            $this->assertSame('unsatisfied', $energy[0]['status']);
            $this->assertSame('Record Charge/Fuel percentage', $energy[0]['action']['label']);
            $this->assertSame(1, $projection['blocking_remaining_count']);
        }
    }

    public function testKnownEnergySequencesMeasurementBeforeNormalTargetReadiness(): void
    {
        $below = $this->context([]);
        $below['active_assessment']['energy_percent'] = 40;
        $belowProjection = (new MovementReadinessProjectionService())->project($below);

        $this->assertSame('satisfied', $this->requirement($belowProjection, 'energy_known')['status']);
        $this->assertSame('unsatisfied', $this->requirement($belowProjection, 'energy_ready')['status']);
        $this->assertSame('Charge/Fuel to 75%', $this->requirement($belowProjection, 'energy_ready')['action']['label']);

        $ready = $this->context([]);
        $readyProjection = (new MovementReadinessProjectionService())->project($ready);
        $this->assertSame('satisfied', $this->requirement($readyProjection, 'energy_known')['status']);
        $this->assertSame('satisfied', $this->requirement($readyProjection, 'energy_ready')['status']);
        $this->assertSame([], $this->actionableEnergyRequirements($readyProjection));
    }

    public function testTripOverrideRulesKeepTheSameMeasurementThenReadinessSequence(): void
    {
        foreach ([
            ['comparison' => 'minimum', 'energy' => 40, 'expected' => 'Charge/Fuel to 50%'],
            ['comparison' => 'target', 'energy' => 40, 'expected' => 'Charge/Fuel toward 50%'],
            ['comparison' => 'maximum', 'energy' => 60, 'expected' => 'Above guest-requested maximum of 50%'],
        ] as $case) {
            $context = $this->context([]);
            $context['active_assessment']['energy_percent'] = $case['energy'];
            $context['energy_rule'] = [
                'source' => 'trip_commitment',
                'comparison' => $case['comparison'],
                'percent' => 50,
                'normal_vehicle_target' => 75,
                'commitment_id' => 900,
                'required' => true,
            ];

            $projection = (new MovementReadinessProjectionService())->project($context);
            $this->assertSame('satisfied', $this->requirement($projection, 'energy_known')['status']);
            $ready = $this->requirement($projection, 'energy_ready');
            $this->assertSame('unsatisfied', $ready['status']);
            $this->assertStringContainsString($case['expected'], $ready['action']['label']);
            $this->assertSame('trip_commitment', $ready['energy_rule']['source']);
        }

        foreach (['minimum', 'target', 'maximum'] as $comparison) {
            $context = $this->context([]);
            $context['active_assessment']['energy_percent'] = null;
            $context['energy_rule'] = [
                'source' => 'trip_commitment', 'comparison' => $comparison, 'percent' => 50,
                'normal_vehicle_target' => 75, 'commitment_id' => 901, 'required' => true,
            ];
            $unknown = (new MovementReadinessProjectionService())->project($context);
            $this->assertSame(['energy_known'], array_column($this->energyRequirements($unknown), 'code'));
            $this->assertSame(1, $unknown['blocking_remaining_count']);
        }

        foreach ([['minimum', 50], ['target', 60], ['maximum', 40]] as [$comparison, $energy]) {
            $context = $this->context([]);
            $context['active_assessment']['energy_percent'] = $energy;
            $context['energy_rule'] = [
                'source' => 'trip_commitment', 'comparison' => $comparison, 'percent' => 50,
                'normal_vehicle_target' => 75, 'commitment_id' => 902, 'required' => true,
            ];
            $satisfied = (new MovementReadinessProjectionService())->project($context);
            $this->assertSame('satisfied', $this->requirement($satisfied, 'energy_known')['status']);
            $this->assertSame('satisfied', $this->requirement($satisfied, 'energy_ready')['status']);
            $this->assertSame([], $this->actionableEnergyRequirements($satisfied));
        }
    }

    public function testEnergySequenceRemainsScopedToExactTripIdentityAndHandoffSuppression(): void
    {
        $first = $this->context([]);
        $first['id'] = 45;
        $first['turo_trip_normalized_id'] = 101;
        $first['scheduled_at'] = '2026-11-11 15:00:00';
        $first['active_assessment']['energy_percent'] = 40;
        $firstProjection = (new MovementReadinessProjectionService())->project($first);

        $second = $this->context([]);
        $second['id'] = 46;
        $second['turo_trip_normalized_id'] = 102;
        $second['scheduled_at'] = '2026-11-11 15:00:00';
        $second['active_assessment']['energy_percent'] = null;
        $second['active_events']['actual_handoff'] = [
            'id' => 99, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-11-11 14:00:00', 'location_class' => 'home',
        ];
        $secondProjection = (new MovementReadinessProjectionService())->project($second);

        $this->assertSame(101, $firstProjection['trip_id']);
        $this->assertSame('Charge/Fuel to 75%', $this->requirement($firstProjection, 'energy_ready')['action']['label']);
        $this->assertSame(102, $secondProjection['trip_id']);
        $this->assertSame(['energy_known'], array_column($this->energyRequirements($secondProjection), 'code'));
        $this->assertFalse($this->requirement($secondProjection, 'energy_known')['actionable']);
        $this->assertNull($this->requirement($secondProjection, 'energy_known')['action']);
    }

    /** @param list<array<string, mixed>> $commitments @return array<string, mixed> */
    private function context(array $commitments): array
    {
        $completeItem = static fn (int $id, string $code): array => [
            'id' => $id, 'item_code' => $code, 'applicability' => 'applicable', 'completion_state' => 'complete', 'completed_at' => '2026-11-11 12:00:00',
        ];

        return [
            'id' => 45,
            'company_id' => 1,
            'turo_trip_normalized_id' => 101,
            'fleet_vehicle_id' => 11,
            'movement_type' => 'pickup',
            'readiness_status' => 'not_started',
            'vehicle_disposition' => null,
            'completed_at' => null,
            'active_events' => [
                'vehicle_staged' => ['id' => 80, 'event_code' => 'vehicle_staged', 'occurred_at' => '2026-11-11 12:00:00', 'location_class' => 'home'],
            ],
            'active_assessment' => ['cleanliness' => 'clean', 'energy_percent' => 80, 'captured_at' => '2026-11-11 12:05:00'],
            'current_readiness_assessment' => null,
            'latest_custody_event' => null,
            'profile' => ['energy_kind' => 'electric', 'ready_energy_target_percent' => 75],
            'energy_rule' => ['source' => 'vehicle_profile', 'comparison' => 'minimum', 'percent' => 75, 'normal_vehicle_target' => 75, 'required' => true],
            'airport_workflow' => null,
            'scheduled_location' => ['location_class' => 'home'],
            'capabilities' => [],
            'items_by_code' => [
                'exterior_photos_completed' => $completeItem(1, 'exterior_photos_completed'),
                'interior_photos_completed' => $completeItem(2, 'interior_photos_completed'),
                'key_card_confirmed' => $completeItem(3, 'key_card_confirmed'),
            ],
            'next_trip' => null,
            'next_pickup_handoff' => null,
            'positioning_plan' => null,
            'active_commitments' => $commitments,
            'next_trip_commitments' => [],
            'extra_fulfillments' => [],
            'next_trip_extra_fulfillments' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function commitment(int $id, string $handling, bool $required, string $instruction): array
    {
        return [
            'id' => $id,
            'turo_trip_normalized_id' => 101,
            'instruction' => $instruction,
            'handling_mode' => $handling,
            'required_before_dispatch' => $required,
            'acknowledged_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function fulfillment(int $id, bool $complete, bool $blocking): array
    {
        return [
            'fulfillment_id' => $id,
            'turo_trip_normalized_id' => 101,
            'title' => 'Premium Beach Gear ×2',
            'action_label' => 'Pack 2 beach gear set(s)',
            'fulfillment_type' => 'pack',
            'fulfillment_phase' => 'preparation',
            'requires_operator_confirmation' => true,
            'readiness_blocking' => $blocking,
            'is_completed' => $complete,
            'is_actionable' => ! $complete,
            'completed_at' => $complete ? '2026-11-11 12:30:00' : null,
        ];
    }

    /** @param array<string, mixed> $projection @return list<array<string, mixed>> */
    private function energyRequirements(array $projection): array
    {
        return array_values(array_filter(
            $projection['requirements'],
            static fn (array $requirement): bool => str_starts_with((string) $requirement['code'], 'energy_'),
        ));
    }

    /** @param array<string, mixed> $projection @return list<array<string, mixed>> */
    private function actionableEnergyRequirements(array $projection): array
    {
        return array_values(array_filter(
            $this->energyRequirements($projection),
            static fn (array $requirement): bool => $requirement['status'] === 'unsatisfied'
                && ($requirement['actionable'] ?? true),
        ));
    }

    /** @param array<string, mixed> $projection @return array<string, mixed> */
    private function requirement(array $projection, string $code): array
    {
        foreach ($projection['requirements'] as $requirement) {
            if ($requirement['code'] === $code) {
                return $requirement;
            }
        }
        $this->fail('Requirement not found: ' . $code);
    }
}
