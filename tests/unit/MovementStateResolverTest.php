<?php

use App\Services\Fleet\MovementStateResolver;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class MovementStateResolverTest extends CIUnitTestCase
{
    #[DataProvider('stateProvider')]
    public function testStatePrecedence(array $context, string $expected): void
    {
        $state = (new MovementStateResolver())->resolve($context, new DateTimeImmutable('2026-09-03 12:00:00'));
        $this->assertSame($expected, $state['code']);
        $this->assertArrayHasKey('primary_action', $state);
        $this->assertArrayHasKey('basis_facts', $state);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function stateProvider(): array
    {
        $handoff = ['id' => 9, 'event_code' => 'actual_handoff', 'location_class' => 'airport_hnl'];
        $return = ['id' => 10, 'event_code' => 'actual_return', 'location_class' => 'home'];
        $future = ['id' => 20, 'starts_at' => '2026-09-04 09:00:00'];
        return [
            'offline wins' => [['operational_status' => 'out_of_service', 'latest_event' => $return, 'assessment' => ['cleanliness' => 'clean']], 'offline'],
            'handoff means on trip without assessment' => [['latest_event' => $handoff, 'trip_schedule' => ['ends_at' => '2026-09-04 17:00:00']], 'on_trip'],
            'passed return after handoff' => [['latest_event' => $handoff, 'trip_schedule' => ['ends_at' => '2026-09-03 11:00:00']], 'return_confirmation_overdue'],
            'passed return while imported in progress' => [['operational_status' => 'in_progress', 'trip_schedule' => ['starts_at' => '2026-09-01 09:00:00', 'ends_at' => '2026-09-03 11:00:00']], 'return_confirmation_overdue'],
            'passed pickup is not handoff' => [['trip_schedule' => ['starts_at' => '2026-09-03 11:00:00', 'ends_at' => '2026-09-04 11:00:00']], 'pickup_confirmation_overdue'],
            'scheduled return alone is not actual return' => [['trip_schedule' => ['starts_at' => '2026-09-04 11:00:00', 'ends_at' => '2026-09-05 11:00:00'], 'next_trip' => $future], 'ready_for_handoff'],
            'return assessment incomplete' => [['latest_event' => $return, 'assessment' => ['cleanliness' => null, 'energy_percent' => null], 'profile' => ['ready_energy_target_percent' => 80]], 'returned_assessment_required'],
            'dirty return' => [['latest_event' => $return, 'assessment' => ['cleanliness' => 'dirty', 'energy_percent' => 90], 'profile' => ['ready_energy_target_percent' => 80]], 'turnaround_attention'],
            'low energy return' => [['latest_event' => $return, 'assessment' => ['cleanliness' => 'clean', 'energy_percent' => 25], 'profile' => ['ready_energy_target_percent' => 80]], 'turnaround_attention'],
            'complete return ready' => [['latest_event' => $return, 'assessment' => ['cleanliness' => 'clean', 'energy_percent' => 90], 'profile' => ['ready_energy_target_percent' => 80]], 'ready'],
            'future critical blocker' => [['next_trip' => $future, 'critical_blocker_count' => 1], 'prep_required'],
            'future clear handoff' => [['next_trip' => $future], 'ready_for_handoff'],
            'no commitment' => [[], 'available'],
        ];
    }

    public function testMissingDepartureEnergyDoesNotUndoOnTrip(): void
    {
        $state = (new MovementStateResolver())->resolve([
            'latest_event' => ['event_code' => 'actual_handoff'],
            'trip_schedule' => ['ends_at' => '2026-09-04 17:00:00'],
        ], new DateTimeImmutable('2026-09-03 12:00:00'));

        $this->assertSame('on_trip', $state['code']);
        $this->assertContains('departure_energy_percent', $state['missing_facts']);
        $this->assertStringContainsString('due Sep 4, 5:00 PM', $state['primary_line']);
    }

    public function testOperatorFacingLabelsMatchAcceptedMovementLanguage(): void
    {
        $resolver = new MovementStateResolver();
        $asOf = new DateTimeImmutable('2026-09-03 12:00:00');

        $onTrip = $resolver->resolve(['latest_event' => ['event_code' => 'actual_handoff'], 'trip_schedule' => ['ends_at' => '2026-09-04 17:00:00']], $asOf);
        $assessment = $resolver->resolve(['latest_event' => ['event_code' => 'actual_return'], 'assessment' => [], 'profile' => ['ready_energy_target_percent' => 80]], $asOf);
        $turnaround = $resolver->resolve(['latest_event' => ['event_code' => 'actual_return'], 'assessment' => ['cleanliness' => 'dirty', 'energy_percent' => 20], 'profile' => ['ready_energy_target_percent' => 80]], $asOf);
        $ready = $resolver->resolve(['next_trip' => ['starts_at' => '2026-09-04 09:00:00']], $asOf);
        $pickupOverdue = $resolver->resolve(['trip_schedule' => ['starts_at' => '2026-09-03 11:00:00']], $asOf);
        $returnOverdue = $resolver->resolve(['latest_event' => ['event_code' => 'actual_handoff'], 'trip_schedule' => ['ends_at' => '2026-09-03 11:00:00']], $asOf);

        $this->assertSame('Currently Rented', $onTrip['label']);
        $this->assertSame('Return assessment needed', $assessment['label']);
        $this->assertSame('Turnaround needed', $turnaround['label']);
        $this->assertSame('Ready for Sep 4, 9:00 AM', $ready['label']);
        $this->assertSame('Pickup confirmation overdue', $pickupOverdue['label']);
        $this->assertSame('Return confirmation overdue', $returnOverdue['label']);
    }
}
