<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\OperationalMovementWorkService;
use App\Services\Fleet\VehicleAvailabilityService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class CommandCenterPlanningConsolidationTest extends CIUnitTestCase
{
    public function testTimelineSourceReadsForwardOneCompanyScopeToTwoBoundedQueries(): void
    {
        $start = new DateTimeImmutable('2026-09-13 00:00:00', new DateTimeZone('Pacific/Honolulu'));
        $end = $start->modify('+7 days');
        $repository = $this->getMockBuilder(FleetIntelligenceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['operationalReservationsBetween', 'airportDeliveriesBetween'])
            ->getMock();
        $repository->expects($this->once())->method('operationalReservationsBetween')
            ->with('2026-09-13 00:00:00', '2026-09-20 00:00:00', 7)
            ->willReturn([]);
        $repository->expects($this->once())->method('airportDeliveriesBetween')
            ->with('2026-09-13 00:00:00', '2026-09-20 00:00:00', 7)
            ->willReturn([]);

        $this->assertSame([], (new VehicleAvailabilityService($repository))->timeline($start, $end, 7));
    }

    public function testCommandCenterRendersOneTimelineDirectlyAfterBriefing(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/fleet_command_center/index.php');

        $this->assertIsString($view);
        $this->assertSame(1, substr_count($view, '>Fleet Timeline</h2>'));
        $this->assertStringNotContainsString('>Chronological<', $view);
        $this->assertStringNotContainsString('>Scheduling<', $view);
        $this->assertStringNotContainsString('timeline-layout', $view);
        $this->assertLessThan(strpos($view, 'id="fleet-timeline"'), strpos($view, 'id="morning-briefing"'));
        $this->assertLessThan(strpos($view, 'id="movement-board"'), strpos($view, 'id="fleet-timeline"'));
        $this->assertStringContainsString('id="todays-mission"', $view);
        $this->assertStringContainsString('id="financial-snapshot"', $view);
        $this->assertStringContainsString('id="fleet-status"', $view);
        $this->assertStringContainsString('<?= esc((string) $commandCenter[\'timeline\'][\'count\']) ?> upcoming', $view);
        $this->assertStringContainsString('app-frame command-center-frame', $view);
    }

    public function testTimelineUsesScheduledBoundariesHonoluluGroupingAndDeterministicVehicleOrder(): void
    {
        $start = new DateTimeImmutable('2026-09-13 00:00:00', new DateTimeZone('Pacific/Honolulu'));
        $items = [
            $this->reservation(90, 9, 'Past Guest', '2026-09-12 08:00:00', '2026-09-12 18:00:00'),
            $this->reservation(30, 3, '', '2026-09-13 12:00:00', '2026-09-14 06:30:00', ['pickup_location_source_text' => 'Waikīkī']),
            $this->reservation(20, 2, 'Joy Kealoha', '2026-09-13 12:00:00', '2026-09-15 10:00:00'),
            $this->reservation(40, 4, 'Michael Stone', '2026-09-14 20:00:00+00:00', '2026-09-20 00:00:00'),
            [
                'type' => 'airport_delivery',
                'fleet_vehicle_id' => 2,
                'starts_at' => '2026-09-13 12:00:00',
                'delivery' => ['id' => 8, 'fleet_vehicle_id' => 2, 'scheduled_at' => '2026-09-13 12:00:00', 'airport_code' => 'HNL'],
            ],
        ];
        $vehicles = [
            ['fleet_vehicle_id' => 3, 'fleet_number' => 3, 'fleet_code' => 'Spaceship03', 'display_name' => 'Spaceship03-519'],
            ['fleet_vehicle_id' => 2, 'fleet_number' => 2, 'fleet_code' => 'Spaceship02', 'display_name' => 'Spaceship02-90U'],
            ['fleet_vehicle_id' => 4, 'fleet_number' => 4, 'fleet_code' => 'Spaceship04', 'display_name' => 'Spaceship04-608'],
        ];

        $readiness = [[
            'event_type' => 'Pickup',
            'reservation' => ['id' => 20],
            'checklist_status_label' => 'Ready',
            'checklist_href' => '/operations/checklists/20',
        ]];
        $method = new ReflectionMethod(FleetCommandCenterViewModelService::class, 'fleetTimeline');
        $timeline = $method->invoke(new FleetCommandCenterViewModelService(), $items, $vehicles, $readiness, $start, $start->modify('+7 days'));

        $this->assertSame(['Today — Sep 13', 'Tomorrow — Sep 14', 'Tuesday — Sep 15'], array_column($timeline['groups'], 'label'));
        $events = array_merge(...array_column($timeline['groups'], 'events'));
        $this->assertSame([2, 3, 3, 4, 2], array_column($events, 'fleet_vehicle_id'));
        $this->assertSame(['Pickup', 'Pickup', 'Return', 'Pickup', 'Return'], array_column($events, 'movement_label'));
        $this->assertSame(['Joy', 'Reservation', 'Reservation', 'Michael', 'Joy'], array_column($events, 'guest_label'));
        $this->assertSame('HNL', $events[0]['location_label']);
        $this->assertSame('Ready', $events[0]['readiness_label']);
        $this->assertSame('/operations/checklists/20', $events[0]['readiness_href']);
        $this->assertSame('Waikīkī', $events[1]['location_label']);
        $this->assertSame('10:00 AM', $events[3]['time_label']);
        $this->assertNotContains(90, array_column($events, 'trip_id'));
        $this->assertSame(5, $timeline['count']);
        $this->assertSame([], $timeline['completed_today']);
        $this->assertSame(0, $timeline['completed_count']);
    }

    public function testAuthoritativeFactsSplitUpcomingFromCompletedTodayPerMovementBoundary(): void
    {
        $start = new DateTimeImmutable('2026-09-13 00:00:00', new DateTimeZone('Pacific/Honolulu'));
        $items = [
            $this->reservation(10, 1, 'Pickup Complete', '2026-09-13 09:00:00', '2026-09-17 10:00:00'),
            $this->reservation(20, 2, 'Return Complete', '2026-09-12 09:00:00', '2026-09-13 12:00:00'),
            $this->reservation(30, 3, 'Position Only', '2026-09-12 09:00:00', '2026-09-13 13:00:00'),
            $this->reservation(40, 4, 'Time Passed', '2026-09-13 08:00:00', '2026-09-20 09:00:00'),
            $this->reservation(50, 5, 'Completed Yesterday', '2026-09-13 07:00:00', '2026-09-20 10:00:00'),
        ];
        $vehicles = array_map(
            static fn (int $id): array => ['fleet_vehicle_id' => $id, 'fleet_number' => $id, 'fleet_code' => 'Fleet-' . $id, 'display_name' => 'Vehicle ' . $id],
            range(1, 5),
        );
        $facts = [
            ['turo_trip_normalized_id' => 10, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-09-13 09:05:00', 'created_at' => '2026-09-13 09:06:00'],
            ['turo_trip_normalized_id' => 20, 'event_code' => 'actual_return', 'occurred_at' => '2026-09-12 20:15:00', 'created_at' => '2026-09-13 10:00:00'],
            ['turo_trip_normalized_id' => 30, 'event_code' => 'vehicle_positioned', 'occurred_at' => '2026-09-13 11:00:00', 'created_at' => '2026-09-13 11:00:00'],
            ['turo_trip_normalized_id' => 50, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-09-12 07:00:00', 'created_at' => '2026-09-12 07:05:00'],
        ];

        $timeline = $this->timeline($items, $vehicles, [], $start, $facts);
        $upcoming = array_merge(...array_column($timeline['groups'], 'events'));

        $this->assertSame(3, $timeline['count']);
        $this->assertSame([[40, 'Pickup'], [30, 'Return'], [10, 'Return']], array_map(
            static fn (array $event): array => [$event['trip_id'], $event['movement_label']],
            $upcoming,
        ));
        $this->assertSame([[10, 'Pickup'], [20, 'Return']], array_map(
            static fn (array $event): array => [$event['trip_id'], $event['movement_label']],
            $timeline['completed_today'],
        ));
        $this->assertSame(2, $timeline['completed_count']);
        $this->assertNotContains(50, array_column($upcoming, 'trip_id'));
        $this->assertNotContains(50, array_column($timeline['completed_today'], 'trip_id'));
        $this->assertSame('/operations/vehicles/1/trip-history?trip=10', $upcoming[2]['href']);
    }

    public function testVehicleRecoveryAlsoCompletesReturnButStagingDoesNotCompletePickup(): void
    {
        $start = new DateTimeImmutable('2026-09-13 00:00:00', new DateTimeZone('Pacific/Honolulu'));
        $items = [
            $this->reservation(60, 6, 'Recovery', '2026-09-12 08:00:00', '2026-09-13 15:00:00'),
            $this->reservation(70, 7, 'Staged', '2026-09-13 16:00:00', '2026-09-19 12:00:00'),
        ];
        $vehicles = [
            ['fleet_vehicle_id' => 6, 'fleet_number' => 6, 'fleet_code' => 'Fleet-6', 'display_name' => 'Vehicle 6'],
            ['fleet_vehicle_id' => 7, 'fleet_number' => 7, 'fleet_code' => 'Fleet-7', 'display_name' => 'Vehicle 7'],
        ];
        $facts = [
            ['turo_trip_normalized_id' => 60, 'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-13 14:50:00', 'created_at' => '2026-09-13 14:51:00'],
            ['turo_trip_normalized_id' => 70, 'event_code' => 'vehicle_staged', 'occurred_at' => '2026-09-13 15:30:00', 'created_at' => '2026-09-13 15:31:00'],
        ];

        $timeline = $this->timeline($items, $vehicles, [], $start, $facts);
        $upcoming = array_merge(...array_column($timeline['groups'], 'events'));

        $this->assertSame([[60, 'Return']], array_map(static fn (array $event): array => [$event['trip_id'], $event['movement_label']], $timeline['completed_today']));
        $this->assertSame([[70, 'Pickup'], [70, 'Return']], array_map(static fn (array $event): array => [$event['trip_id'], $event['movement_label']], $upcoming));
    }

    public function testCompactTimelineKeepsAllTodayAndOnlyMarksFutureEventsAfterTheNextThree(): void
    {
        $todayEvents = [
            $this->viewEvent(1, 'Today One', '2026-09-13 09:00:00'),
            $this->viewEvent(2, 'Today Two', '2026-09-13 11:00:00'),
            $this->viewEvent(3, 'Today Three', '2026-09-13 13:00:00'),
            $this->viewEvent(4, 'Today Four', '2026-09-13 15:00:00'),
        ];
        $futureEvents = [
            $this->viewEvent(5, 'Future One', '2026-09-14 06:30:00'),
            $this->viewEvent(6, 'Future Two', '2026-09-14 10:00:00'),
            $this->viewEvent(7, 'Future Three', '2026-09-15 12:00:00'),
            $this->viewEvent(8, 'Future Four', '2026-09-15 16:00:00'),
            $this->viewEvent(9, 'Future Five', '2026-09-16 09:00:00'),
        ];
        $completed = $this->viewEvent(10, 'Completed Today', '2026-09-13 08:00:00');
        $timeline = [
            'today_date' => '2026-09-13',
            'groups' => [
                ['date' => '2026-09-13', 'label' => 'Today — Sep 13', 'events' => $todayEvents],
                ['date' => '2026-09-14', 'label' => 'Tomorrow — Sep 14', 'events' => array_slice($futureEvents, 0, 2)],
                ['date' => '2026-09-15', 'label' => 'Tuesday — Sep 15', 'events' => array_slice($futureEvents, 2, 2)],
                ['date' => '2026-09-16', 'label' => 'Wednesday — Sep 16', 'events' => array_slice($futureEvents, 4, 1)],
            ],
            'count' => 9,
            'completed_today' => [$completed],
            'completed_count' => 1,
        ];

        $html = CoreServices::renderer()->setData(['timeline' => $timeline])
            ->render('fleet_command_center/components/fleet_timeline');

        foreach (['Today One', 'Today Two', 'Today Three', 'Today Four', 'Future One', 'Future Two', 'Future Three', 'Future Four', 'Future Five'] as $guest) {
            $this->assertStringContainsString($guest, $html);
        }
        $this->assertSame(2, substr_count($html, ' data-fleet-timeline-extra>'));
        $this->assertSame(1, substr_count($html, ' data-fleet-timeline-extra-group>'));
        $this->assertStringContainsString('data-fleet-timeline-toggle', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-controls="fleet-timeline-pending"', $html);
        $this->assertStringContainsString('Show next 7 days', $html);
        $this->assertStringContainsString('Completed today <span>(1)</span>', $html);
        $this->assertSame(1, substr_count($html, 'Completed Today'));
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;vehicles&#x2F;8&#x2F;trip-history&#x3F;trip&#x3D;8"', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;vehicles&#x2F;9&#x2F;trip-history&#x3F;trip&#x3D;9"', $html);
    }

    public function testSmallOrFutureOnlyTimelineDoesNotRenderAnUnneededDisclosureOrEmptyTodayHeading(): void
    {
        $timeline = [
            'today_date' => '2026-09-13',
            'groups' => [[
                'date' => '2026-09-14',
                'label' => 'Tomorrow — Sep 14',
                'events' => [
                    $this->viewEvent(11, 'Future One', '2026-09-14 08:00:00'),
                    $this->viewEvent(12, 'Future Two', '2026-09-14 10:00:00'),
                ],
            ]],
            'count' => 2,
            'completed_today' => [],
            'completed_count' => 0,
        ];

        $html = CoreServices::renderer()->setData(['timeline' => $timeline])
            ->render('fleet_command_center/components/fleet_timeline');

        $this->assertStringContainsString('Tomorrow — Sep 14', $html);
        $this->assertStringNotContainsString('Today — Sep 13', $html);
        $this->assertStringNotContainsString('data-fleet-timeline-toggle', $html);
        $this->assertStringNotContainsString('data-fleet-timeline-extra', $html);
    }

    public function testFutureOnlyTimelineKeepsExactlyTheNextThreeMovementsInCompactMode(): void
    {
        $futureEvents = [
            $this->viewEvent(21, 'Future One', '2026-09-14 08:00:00'),
            $this->viewEvent(22, 'Future Two', '2026-09-14 10:00:00'),
            $this->viewEvent(23, 'Future Three', '2026-09-15 09:00:00'),
            $this->viewEvent(24, 'Future Four', '2026-09-16 11:00:00'),
            $this->viewEvent(25, 'Future Five', '2026-09-16 13:00:00'),
        ];
        $timeline = [
            'today_date' => '2026-09-13',
            'groups' => [
                ['date' => '2026-09-14', 'label' => 'Tomorrow — Sep 14', 'events' => array_slice($futureEvents, 0, 2)],
                ['date' => '2026-09-15', 'label' => 'Tuesday — Sep 15', 'events' => array_slice($futureEvents, 2, 1)],
                ['date' => '2026-09-16', 'label' => 'Wednesday — Sep 16', 'events' => array_slice($futureEvents, 3, 2)],
            ],
            'count' => 5,
            'completed_today' => [],
            'completed_count' => 0,
        ];

        $html = CoreServices::renderer()->setData(['timeline' => $timeline])
            ->render('fleet_command_center/components/fleet_timeline');

        $this->assertStringNotContainsString('Today — Sep 13', $html);
        $this->assertSame(2, substr_count($html, ' data-fleet-timeline-extra>'));
        $this->assertSame(1, substr_count($html, ' data-fleet-timeline-extra-group>'));
        $this->assertStringContainsString('data-fleet-timeline-toggle', $html);
    }

    public function testCompletedDisclosureEmptyStateNavigationAndResponsiveStylesArePresent(): void
    {
        $completedEvent = [
            'tone' => 'danger', 'href' => '/operations/checklists/88', 'date_time' => new DateTimeImmutable('2026-09-13 12:00:00'),
            'time_label' => '12:00 PM', 'guest_label' => 'Chauntae', 'vehicle_label' => 'Spaceship09-294', 'movement_label' => 'Return',
            'location_label' => 'Home', 'readiness_label' => null,
        ];
        $html = CoreServices::renderer()->setData(['timeline' => ['groups' => [], 'count' => 0, 'completed_today' => [$completedEvent], 'completed_count' => 1]])
            ->render('fleet_command_center/components/fleet_timeline');
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('No upcoming fleet movements in the next 7 days.', $html);
        $this->assertStringContainsString('<details class="fleet-timeline__completed">', $html);
        $this->assertStringNotContainsString('<details class="fleet-timeline__completed" open', $html);
        $this->assertStringContainsString('Completed today <span>(1)</span>', $html);
        $this->assertStringContainsString('is-completed', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;88"', $html);
        $this->assertIsString($css);
        $this->assertStringContainsString('.fleet-timeline__event', $css);
        $this->assertStringContainsString('.fleet-timeline__event-link', $css);
        $this->assertStringContainsString('.fleet-timeline__disclosure', $css);
        $this->assertStringContainsString('.fleet-timeline__disclosure:focus-visible', $css);
        $this->assertStringContainsString('grid-template-columns:', $css);
        $this->assertStringContainsString('.movement-badge.tone-success', $css);
        $this->assertStringContainsString('.movement-badge.tone-danger', $css);
        $this->assertStringContainsString('.command-center-frame', $css);
        $this->assertStringNotContainsString('max-width: 1040px', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 700px\).*?\.fleet-timeline__event-link\s*\{\s*grid-template-columns: minmax\(0, 1fr\)/s', $css);

        $withoutCompleted = CoreServices::renderer()->setData(['timeline' => ['groups' => [], 'count' => 0, 'completed_today' => [], 'completed_count' => 0]])
            ->render('fleet_command_center/components/fleet_timeline');
        $this->assertStringNotContainsString('Completed today', $withoutCompleted);
    }

    /** @param list<array<string, mixed>> $items @param list<array<string, mixed>> $vehicles @param list<array<string, mixed>> $readiness @param list<array<string, mixed>> $facts */
    private function timeline(array $items, array $vehicles, array $readiness, DateTimeImmutable $start, array $facts): array
    {
        $method = new ReflectionMethod(FleetCommandCenterViewModelService::class, 'fleetTimeline');

        return $method->invoke(new FleetCommandCenterViewModelService(), $items, $vehicles, $readiness, $start, $start->modify('+7 days'), (new OperationalMovementWorkService())->completionByMovement($facts));
    }

    /** @param array<string, mixed> $extra */
    private function reservation(int $tripId, int $vehicleId, string $guest, string $startsAt, string $endsAt, array $extra = []): array
    {
        $reservation = array_merge([
            'id' => $tripId,
            'fleet_vehicle_id' => $vehicleId,
            'guest_name' => $guest,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $extra);

        return [
            'type' => 'reservation',
            'fleet_vehicle_id' => $vehicleId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'guest_name' => $guest,
            'reservation' => $reservation,
        ];
    }

    /** @return array<string, mixed> */
    private function viewEvent(int $tripId, string $guest, string $dateTime): array
    {
        $scheduledAt = new DateTimeImmutable($dateTime, new DateTimeZone('Pacific/Honolulu'));

        return [
            'trip_id' => $tripId,
            'tone' => 'success',
            'href' => '/operations/vehicles/' . $tripId . '/trip-history?trip=' . $tripId,
            'date_time' => $scheduledAt,
            'time_label' => $scheduledAt->format('g:i A'),
            'guest_label' => $guest,
            'vehicle_label' => 'Vehicle ' . $tripId,
            'movement_label' => 'Pickup',
            'location_label' => 'HNL',
            'readiness_label' => null,
        ];
    }
}
