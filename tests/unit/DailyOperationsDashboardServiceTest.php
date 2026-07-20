<?php

use App\Services\Fleet\MorningBriefingService;
use App\Services\Fleet\VehicleDailyStateService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DailyOperationsDashboardServiceTest extends CIUnitTestCase
{
    private VehicleDailyStateService $states;
    private MorningBriefingService $briefing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new VehicleDailyStateService();
        $this->briefing = new MorningBriefingService();
    }

    public function testVehiclesGoingOutReturningAndSameDayTurnaroundsAreClassified(): void
    {
        $board = $this->board();
        $byVehicle = array_column($board, null, 'fleet_vehicle_id');

        $this->assertSame('same_day_turnaround', $byVehicle[6]['primary_status']);
        $this->assertContains('departing_today', $byVehicle[9]['flags']);
        $this->assertContains('returning_today', $byVehicle[4]['flags']);
        $this->assertSame('currently_rented', $byVehicle[3]['primary_status']);
        $this->assertSame('maintenance_required', $byVehicle[2]['primary_status']);
        $this->assertSame('available', $byVehicle[1]['primary_status']);
        $this->assertSame(count($board), count(array_unique(array_column($board, 'fleet_vehicle_id'))));
    }

    public function testSameDayTurnaroundDurationAndThresholdsWork(): void
    {
        $byVehicle = array_column($this->board(), null, 'fleet_vehicle_id');

        $this->assertSame(150, $byVehicle[6]['turnaround']['minutes']);
        $this->assertSame('tight', $byVehicle[6]['turnaround']['severity']);
        $this->assertSame('2 hrs 30 min', $byVehicle[6]['turnaround']['label']);

        $critical = $this->states->movementBoard([$this->vehicle(7)], [
            'todays_returns' => [$this->reservation(7, '2026-07-19 11:00:00', '2026-07-19 12:00:00')],
            'todays_pickups' => [$this->reservation(7, '2026-07-19 13:30:00', '2026-07-21 10:00:00')],
        ], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00'))[0];

        $this->assertSame('critical', $critical['turnaround']['severity']);
    }

    public function testTimelineEventsAppearInChronologicalOrder(): void
    {
        $timeline = $this->states->timeline($this->today(), new DateTimeImmutable('2026-07-19 08:00:00'));

        $this->assertSame(['Return', 'Return', 'Pickup', 'Pickup', 'Airport delivery'], array_column($timeline, 'event_type'));
        $this->assertSame('Spaceship-004', $timeline[0]['vehicle_label']);
        $this->assertSame('Location not captured', $timeline[0]['location_label']);
    }

    public function testImmediateAttentionPrioritizesOverdueAndTightTurnaround(): void
    {
        $board = $this->states->movementBoard([$this->vehicle(6, 'in_progress')], [
            'todays_returns' => [$this->reservation(6, '2026-07-18 09:00:00', '2026-07-19 07:00:00')],
            'todays_pickups' => [$this->reservation(6, '2026-07-19 10:00:00', '2026-07-20 10:00:00')],
        ], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00'));
        $attention = $this->states->immediateAttention($board, [['count' => 1, 'severity' => 'today', 'label' => 'Import reconciliation waiting.', 'detail' => '1 row', 'href' => '/turo/vehicle-matches']]);

        $this->assertSame('critical', $attention[0]['severity']);
        $this->assertStringContainsString('return time has passed', $attention[0]['label']);
        $this->assertSame('Import reconciliation waiting.', $attention[count($attention) - 1]['label']);
    }

    public function testFleetStatusCountsMatchVehicleStates(): void
    {
        $counts = $this->states->statusCounts($this->board(), 0.42);

        $this->assertSame(6, $counts['fleet_size']);
        $this->assertSame(2, $counts['going_out_today']);
        $this->assertSame(2, $counts['returning_today']);
        $this->assertSame(1, $counts['same_day_turnarounds']);
        $this->assertSame(1, $counts['offline_or_unavailable']);
        $this->assertSame(42, $counts['utilization_percent']);
    }

    public function testMorningBriefingCountsAndStaysConcise(): void
    {
        $board = $this->board();
        $attention = $this->states->immediateAttention($board, []);
        $briefing = $this->briefing->briefing($board, $attention, 2, 2);

        $this->assertSame('Good Morning, Jay.', $briefing['greeting']);
        $this->assertStringContainsString('same-day turnaround', $briefing['message']);
        $this->assertStringContainsString('2 pickups and 2 returns', $briefing['message']);
        $this->assertLessThanOrEqual(220, strlen($briefing['message']));
    }

    public function testPositiveMorningBriefingWhenNoUrgentIssuesExist(): void
    {
        $briefing = $this->briefing->briefing($this->states->movementBoard([$this->vehicle(1)], ['todays_pickups' => [], 'todays_returns' => []], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00')), [], 2, 1);

        $this->assertStringContainsString('no tight turnarounds or urgent fleet issues', $briefing['message']);
    }

    public function testDashboardHandlesPartialDataGracefully(): void
    {
        $board = $this->states->movementBoard([['fleet_vehicle_id' => 1, 'fleet_code' => 'Spaceship-001', 'status' => 'available', 'current_battery' => null, 'current_location' => null]], ['todays_pickups' => [], 'todays_returns' => []], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00'));

        $this->assertSame('Battery not captured', $board[0]['battery_label']);
        $this->assertSame('Location not captured', $board[0]['location_label']);
    }

    private function board(): array
    {
        return $this->states->movementBoard([
            $this->vehicle(1),
            $this->vehicle(2, 'maintenance'),
            $this->vehicle(3, 'in_progress'),
            $this->vehicle(4),
            $this->vehicle(6),
            $this->vehicle(9),
        ], $this->today(), $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00'));
    }

    private function today(): array
    {
        return [
            'todays_returns' => [
                $this->reservation(4, '2026-07-17 10:00:00', '2026-07-19 08:30:00'),
                $this->reservation(6, '2026-07-17 10:00:00', '2026-07-19 11:00:00'),
            ],
            'todays_pickups' => [
                $this->reservation(6, '2026-07-19 13:30:00', '2026-07-21 10:00:00'),
                $this->reservation(9, '2026-07-19 14:00:00', '2026-07-21 10:00:00'),
            ],
            'airport_deliveries' => [['fleet_vehicle_id' => 9, 'fleet_code' => 'Spaceship-009', 'scheduled_at' => '2026-07-19 14:00:00', 'completed_at' => null, 'airport_name' => 'Honolulu Airport']],
        ];
    }

    private function vehicle(int $id, string $status = 'available'): array
    {
        return ['fleet_vehicle_id' => $id, 'fleet_code' => 'Spaceship-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT), 'display_name' => 'Spaceship-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT), 'model' => '2026 Tesla Model Y', 'status' => $status, 'current_battery' => null, 'current_location' => null, 'airport_delivery_scheduled' => $id === 9];
    }

    private function reservation(int $vehicleId, string $startsAt, string $endsAt): array
    {
        return ['fleet_vehicle_id' => $vehicleId, 'fleet_code' => 'Spaceship-' . str_pad((string) $vehicleId, 3, '0', STR_PAD_LEFT), 'guest_name' => 'Guest ' . $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'status_code' => 'booked'];
    }

    private function emptyHealth(): array
    {
        return ['vehicles_needing_cleaning' => [], 'vehicles_due_for_maintenance' => []];
    }
}
