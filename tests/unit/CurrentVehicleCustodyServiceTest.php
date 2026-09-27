<?php

use App\Services\Fleet\CurrentVehicleCustodyService;
use PHPUnit\Framework\TestCase;

/** @internal */
final class CurrentVehicleCustodyServiceTest extends TestCase
{
    public function testFutureTripStagingCannotOverrideUnresolvedGuestHandoff(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(1, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00'),
            $this->event(2, 30, 'vehicle_staged', '2026-09-22 07:16:00', '2026-09-30 13:00:00'),
        ]);

        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(20, $custody['active_trip_id']);
        $this->assertSame(1, $custody['basis_event_id']);
    }

    public function testFutureTripStagingCannotOverrideAwaitingRecovery(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(1, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00'),
            $this->event(2, 20, 'guest_return_staged', '2026-09-24 18:00:00', '2026-09-23 08:30:00'),
            $this->event(3, 30, 'vehicle_staged', '2026-09-22 07:16:00', '2026-09-30 13:00:00'),
        ]);

        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(20, $custody['active_trip_id']);
        $this->assertSame('guest_return_staged', $custody['basis_event_code']);
    }

    public function testInterveningReturnPreventsOlderFutureStageFromResurfacing(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(1, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00'),
            $this->event(2, 30, 'vehicle_staged', '2026-09-22 07:16:00', '2026-09-30 13:00:00'),
            $this->event(3, 20, 'actual_return', '2026-09-24 18:30:00', '2026-09-23 08:30:00'),
        ]);

        $this->assertSame('operator', $custody['custody']);
        $this->assertNull($custody['active_trip_id']);
        $this->assertSame('actual_return', $custody['basis_event_code']);
    }

    public function testNewFutureStageMayBecomeCurrentAfterPriorGuestCustodyEnds(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(1, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00'),
            $this->event(2, 20, 'vehicle_recovered', '2026-09-24 18:30:00', '2026-09-23 08:30:00'),
            $this->event(3, 30, 'vehicle_staged', '2026-09-29 17:00:00', '2026-09-30 13:00:00'),
        ]);

        $this->assertSame('operator', $custody['custody']);
        $this->assertSame(30, $custody['active_trip_id']);
        $this->assertSame('vehicle_staged', $custody['basis_event_code']);
    }

    public function testLaterTripHandoffStillWinsOverEarlierTripRecoveryBackfill(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(1, 10, 'actual_handoff', '2026-09-14 10:05:00', '2026-09-14 12:00:00'),
            $this->event(2, 10, 'vehicle_recovered', '2026-09-23 12:42:00', '2026-09-14 12:00:00'),
            $this->event(3, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00'),
        ]);

        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(20, $custody['active_trip_id']);
        $this->assertSame(3, $custody['basis_event_id']);
    }

    public function testCanceledFutureTripStagingIsIgnored(): void
    {
        $activeHandoff = $this->event(1, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-23 08:30:00');
        $canceledStage = $this->event(2, 30, 'vehicle_staged', '2026-09-24 09:00:00', '2026-09-30 13:00:00');
        $canceledStage['custody_trip_status_code'] = 'canceled_zero_payout';
        $canceledStage['custody_trip_canceled_at'] = '2026-09-24 08:00:00';

        $custody = (new CurrentVehicleCustodyService())->resolveEvents([$activeHandoff, $canceledStage]);

        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(20, $custody['active_trip_id']);
    }

    public function testLaterGuestLifecycleBeatsFarFutureTripScheduleAndReportsConflict(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(9901, 8801, 'actual_handoff', '2026-09-19 21:05:00', '2026-10-07 10:00:00'),
            $this->event(91, 8802, 'actual_handoff', '2026-09-19 23:15:00', '2026-09-19 23:00:00'),
            $this->event(9902, 8802, 'guest_return_staged', '2026-09-26 17:00:00', '2026-09-19 23:00:00'),
        ]);

        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(8802, $custody['active_trip_id']);
        $this->assertSame(9902, $custody['basis_event_id']);
        $this->assertSame('guest_return_staged', $custody['basis_event_code']);
        $this->assertTrue($custody['integrity_conflict']);
        $this->assertSame([8801, 8802], array_column($custody['conflicting_guest_states'], 'trip_id'));
        $this->assertSame([9901, 9902], array_column($custody['conflicting_guest_states'], 'event_id'));
    }

    public function testGuestLifecycleTimestampTieUsesEventIdDeterministically(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(41, 20, 'actual_handoff', '2026-09-23 08:30:00', '2026-10-01 08:00:00'),
            $this->event(42, 30, 'actual_handoff', '2026-09-23 08:30:00', '2026-09-24 08:00:00'),
        ]);

        $this->assertSame(30, $custody['active_trip_id']);
        $this->assertSame(42, $custody['basis_event_id']);
        $this->assertTrue($custody['integrity_conflict']);
    }

    public function testSingleGuestLifecycleHasNoIntegrityConflict(): void
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents([
            $this->event(9902, 8802, 'guest_return_staged', '2026-09-26 17:00:00', '2026-09-19 23:00:00'),
        ]);

        $this->assertFalse($custody['integrity_conflict']);
        $this->assertSame([], $custody['conflicting_guest_states']);
    }

    /** @return array<string, mixed> */
    private function event(int $id, int $tripId, string $code, string $occurredAt, string $startsAt): array
    {
        return [
            'id' => $id,
            'turo_trip_normalized_id' => $tripId,
            'event_code' => $code,
            'occurred_at' => $occurredAt,
            'custody_trip_starts_at' => $startsAt,
            'custody_trip_status_code' => 'booked',
            'custody_trip_canceled_at' => null,
        ];
    }
}
