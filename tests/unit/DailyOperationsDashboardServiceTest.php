<?php

use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FinancialSummaryService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetSnapshotService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\MorningBriefingService;
use App\Services\Fleet\MovementBoardIntelligenceService;
use App\Services\Fleet\MovementReadinessReadService;
use App\Services\Fleet\OperatingExpenseService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripIncidentalReviewService;
use App\Services\Fleet\TripMovementChecklistService;
use App\Services\Fleet\TuroAccessReimbursementService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Fleet\VehicleDailyStateService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class DailyOperationsDashboardServiceTest extends CIUnitTestCase
{
    public function testNoWorkflowTodayQueuesVerificationAndSeatInstallationWithoutDuplicateAggregateWork(): void
    {
        $asOf = new DateTimeImmutable('2030-01-02 12:00:00');
        $tasks = $this->createStub(TaskService::class);
        $tasks->method('today')->willReturn(['todays_pickups' => [], 'todays_returns' => [], 'airport_deliveries' => [], 'cleaning_tasks' => [], 'charging_tasks' => []]);
        $availability = $this->createStub(VehicleAvailabilityService::class);
        $availability->method('vehicleStatus')->willReturn([$this->vehicle(9)]);
        $health = $this->createStub(FleetHealthService::class);
        $health->method('summary')->willReturn($this->emptyHealth());
        $statistics = $this->createStub(FleetStatisticsService::class);
        $statistics->method('currentMonth')->willReturn(['fleet_utilization' => 0.0]);
        $snapshot = $this->createStub(FleetSnapshotService::class);
        $snapshot->method('forSingleFleetCompany')->willReturn(['company_id' => 1, 'total' => 1, 'buckets' => [], 'vehicles' => []]);
        $financial = $this->createStub(FinancialSummaryService::class);
        $financial->method('currentMonth')->willReturn(['realized_operating_revenue' => 0.0, 'realized_recoveries' => 0.0, 'recorded_operating_costs' => 0.0, 'net_realized_operating_result' => 0.0, 'forecast_host_payout' => 0.0]);
        $imports = $this->createStub(TuroImportIssueService::class);
        $imports->method('attentionSummary')->willReturn(['total_unresolved' => 0, 'href' => '#imports']);
        $mappings = $this->createStub(TuroVehicleMappingService::class);
        $mappings->method('attentionSummary')->willReturn(['unique_unmatched_vehicles' => 0, 'affected_issues' => 0, 'href' => '#mappings']);
        $reconciliation = $this->createStub(TuroTripReconciliationService::class);
        $reconciliation->method('attentionSummary')->willReturn(['awaiting_reconciliation' => 0, 'href' => '#reconciliation']);
        $airport = $this->createStub(AirportMovementWorkflowService::class);
        $airport->method('attentionSummary')->willReturn(['airport_workflows_requiring_action' => 0, 'href' => '#airport']);
        $reimbursements = $this->createStub(TuroAccessReimbursementService::class);
        $reimbursements->method('attentionSummary')->willReturn(['total_actionable' => 0, 'ready_to_file' => 0, 'needs_setup' => 0, 'filed_pending' => 0, 'href' => '#reimbursements']);
        $checklists = $this->createMock(TripMovementChecklistService::class);
        $checklists->expects($this->never())->method('ensureForDay');
        $checklists->expects($this->exactly(2))->method('summariesForDay')->willReturn([]);
        $readiness = $this->createMock(MovementReadinessReadService::class);
        $readiness->expects($this->never())->method('forCompany');
        $incidentals = $this->createStub(TripIncidentalReviewService::class);
        $incidentals->method('attentionSummaryForSingleCompany')->willReturn(['total' => 0, 'href' => '#incidentals']);
        $expenses = $this->createStub(OperatingExpenseService::class);
        $expenses->method('attentionSummary')->willReturn(['total' => 0, 'href' => '#expenses']);
        $pendingStates = [true, false];
        $intelligence = $this->createMock(MovementBoardIntelligenceService::class);
        $intelligence->expects($this->exactly(2))->method('enrich')->willReturnCallback(static function (array $board) use (&$pendingStates, $asOf): array {
            $pending = array_shift($pendingStates);
            $source = (new \App\Services\Fleet\ExtrasVerificationFreshnessPolicy())->assess([], ['company_id' => 1, 'trip_id' => 502, 'fleet_vehicle_id' => 9, 'reservation_id' => '70000502', 'starts_at' => '2030-01-03 09:00:00', 'has_actual_handoff' => ! $pending], $asOf, 'Pacific/Honolulu');
            $projection = (new \App\Services\Fleet\MovementReadinessProjectionService())->projectTripPreparation([
                'company_id' => 1, 'turo_trip_normalized_id' => 502, 'fleet_vehicle_id' => 9, 'extra_verification' => $source,
                'extra_fulfillments' => [['company_id' => 1, 'turo_trip_normalized_id' => 502, 'fulfillment_id' => 702, 'selection_id' => 602,
                    'title' => 'Synthetic booster seat', 'fulfillment_type' => 'install', 'fulfillment_phase' => 'preparation', 'requires_operator_confirmation' => true,
                    'readiness_blocking' => true, 'is_actionable' => $pending, 'is_completed' => ! $pending, 'action_label' => 'Install the synthetic seat']],
            ]);
            $projection['href'] = '/operations/trips/502/commitments';
            $board[0]['next_trip_preparation'] = $projection;
            $board[0]['readiness_blocking_remaining'] = $projection['blocking_remaining_count'];
            $board[0]['readiness_display_remaining'] = $projection['blocking_remaining_count'];
            return $board;
        });
        $dashboard = new DailyOperationsDashboardService(
            taskService: $tasks,
            availabilityService: $availability,
            healthService: $health,
            statisticsService: $statistics,
            importIssueService: $imports,
            vehicleMappingService: $mappings,
            reconciliationService: $reconciliation,
            checklistService: $checklists,
            airportWorkflowService: $airport,
            turoAccessReimbursementService: $reimbursements,
            movementBoardIntelligenceService: $intelligence,
            movementReadinessReadService: $readiness,
            fleetSnapshotService: $snapshot,
            financialSummaryService: $financial,
            incidentalReviewService: $incidentals,
            operatingExpenseService: $expenses,
        );
        $result = $dashboard->forToday($asOf);
        $this->assertSame([], $result['timeline']);
        $this->assertSame([], $result['movement_board'][0]['checklists']);
        $queue = array_column($result['operational_queue'], null, 'code');
        $this->assertSame(1, $queue['readiness']['count']);
        $this->assertSame('Refresh Turo Extras', $queue['extras_verification_1_502']['label']);
        $this->assertSame('/turo/extras?reservation_id=70000502#export-heading', $queue['extras_verification_1_502']['href']);
        $this->assertSame(2, $result['movement_board'][0]['readiness_blocking_remaining']);
        $this->assertArrayNotHasKey('pickup', $queue);
        $this->assertCount(1, $dashboard->filterMovementBoard($result['movement_board'], 'readiness'));
        $completed = $dashboard->forToday($asOf);
        $this->assertArrayNotHasKey('readiness', array_column($completed['operational_queue'], null, 'code'));
        $this->assertArrayNotHasKey('extras_verification_1_502', array_column($completed['operational_queue'], null, 'code'));
        $this->assertSame([], $dashboard->filterMovementBoard($completed['movement_board'], 'readiness'));
    }

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

    public function testMovementBoardSortsTimedCardsChronologicallyWithStableUntimedFallback(): void
    {
        $vehicles = [
            $this->vehicle(5),
            $this->vehicle(4),
            $this->vehicle(3, 'in_progress'),
            $this->vehicle(2),
            $this->vehicle(1),
        ];
        $vehicles[0]['fleet_number'] = 20;
        $vehicles[1]['fleet_number'] = 10;
        $vehicles[2]['fleet_number'] = 30;
        $vehicles[3]['fleet_number'] = 2;
        $vehicles[4]['fleet_number'] = 1;

        $board = $this->states->movementBoard($vehicles, [
            'todays_returns' => [
                $this->reservation(4, '2026-07-18 12:00:00', '2026-07-19 17:00:00'),
                $this->reservation(3, '2026-07-18 12:00:00', '2026-07-19 17:00:00'),
            ],
            'todays_pickups' => [
                $this->reservation(5, '2026-07-19 15:00:00', '2026-07-20 15:00:00'),
                $this->reservation(2, '2026-07-19 06:00:00', '2026-07-20 06:00:00'),
            ],
        ], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 05:00:00'));

        $this->assertSame([2, 5, 4, 3, 1], array_column($board, 'fleet_vehicle_id'));
    }

    public function testMovementLabelsAndTonesMatchTuroStartAndEndSemantics(): void
    {
        $board = array_column($this->states->movementBoard([$this->vehicle(4), $this->vehicle(9)], $this->today(), $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00')), null, 'fleet_vehicle_id');

        $this->assertSame('Ending at 8:30 AM', $board[4]['primary_status_label']);
        $this->assertSame('trip-end', $board[4]['status_tone']);
        $this->assertSame('Starting at 2:00 PM', $board[9]['primary_status_label']);
        $this->assertSame('trip-start', $board[9]['status_tone']);
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

    public function testOneTripsOwnPickupAndReturnAreNotATurnaround(): void
    {
        $trip = ['id' => 701, ...$this->reservation(7, '2026-07-19 09:00:00', '2026-07-19 12:00:00')];

        $vehicle = $this->states->movementBoard([$this->vehicle(7)], [
            'todays_returns' => [$trip],
            'todays_pickups' => [$trip],
        ], $this->emptyHealth(), new DateTimeImmutable('2026-07-19 08:00:00'))[0];

        $this->assertNull($vehicle['turnaround']);
        $this->assertNotContains('same_day_turnaround', $vehicle['flags']);
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

    #[DataProvider('recoveryScenarios')]
    public function testOperationalQueueEmitsOnlyMeasuredPendingWork(bool $otherTripAwaitingRecovery): void
    {
        $tasks = $this->createStub(TaskService::class);
        $availability = $this->createStub(VehicleAvailabilityService::class);
        $health = $this->createStub(FleetHealthService::class);
        $statistics = $this->createStub(FleetStatisticsService::class);
        $importIssues = $this->createStub(TuroImportIssueService::class);
        $vehicleMappings = $this->createStub(TuroVehicleMappingService::class);
        $reconciliation = $this->createStub(TuroTripReconciliationService::class);
        $checklists = $this->getMockBuilder(TripMovementChecklistService::class)->disableOriginalConstructor()->onlyMethods(['ensureForDay', 'summariesForDay'])->getMock();
        $airport = $this->createMock(AirportMovementWorkflowService::class);
        $reimbursements = $this->createMock(TuroAccessReimbursementService::class);

        $today = $this->today();
        $today['awaiting_recovery'] = $otherTripAwaitingRecovery ? [[
            'turo_trip_normalized_id' => 258, 'fleet_vehicle_id' => 1, 'fleet_label' => 'Synthetic vehicle',
            'reported_location_label' => 'Synthetic site', 'href' => '/operations/checklists/77',
        ]] : [];
        $today['todays_pickups'][] = [
            'id' => 274,
            ...$this->reservation(1, '2026-07-19 13:30:00', '2026-07-21 10:00:00'),
        ];
        $today['airport_deliveries'][] = ['fleet_vehicle_id' => 1, 'scheduled_at' => '2026-07-19 07:00:00', 'completed_at' => '2026-07-19 07:30:00'];
        $tasks->method('today')->willReturn($today);
        $availability->method('vehicleStatus')->willReturn([$this->vehicle(1)]);
        $health->method('summary')->willReturn($this->emptyHealth());
        $statistics->method('currentMonth')->willReturn(['fleet_utilization' => 0.0, 'completed_revenue' => 0.0, 'forecast_revenue' => 0.0, 'average_daily_rate' => 0.0]);
        $importIssues->method('attentionSummary')->willReturn(['total_unresolved' => 2, 'href' => '/turo/import-issues']);
        $vehicleMappings->method('attentionSummary')->willReturn(['unique_unmatched_vehicles' => 0, 'affected_issues' => 0, 'href' => '/turo/vehicle-matches']);
        $reconciliation->method('attentionSummary')->willReturn(['awaiting_reconciliation' => 0, 'href' => '/turo/vehicle-matches']);
        $checklists->expects($this->never())->method('ensureForDay');
        $checklists->method('summariesForDay')->willReturn([
            [
                'id' => 77,
                'turo_trip_normalized_id' => 258,
                'company_id' => 1,
                'fleet_vehicle_id' => 1,
                'movement_type' => 'pickup',
                'scheduled_at' => '2026-07-19 13:30:00',
                'required_remaining_count' => 9,
                'critical_open_count' => 7,
                'href' => '/operations/checklists/77',
            ],
            [
                'id' => 106,
                'turo_trip_normalized_id' => 274,
                'company_id' => 1,
                'fleet_vehicle_id' => 1,
                'movement_type' => 'pickup',
                'scheduled_at' => '2026-07-19 13:30:00',
                'required_remaining_count' => 9,
                'critical_open_count' => 7,
                'href' => '/operations/checklists/106',
            ],
        ]);
        $airport->expects($this->once())->method('attentionSummary')->with(1, $this->isInstanceOf(DateTimeImmutable::class))->willReturn(['airport_workflows_requiring_action' => 0, 'href' => '/operations/airport']);
        $reimbursements->expects($this->once())->method('attentionSummary')->with(1)->willReturn([
            'needs_setup' => 0,
            'ready_to_file' => 0,
            'filed_pending' => 0,
            'total_actionable' => 0,
            'expected_reimbursement_total' => 0.0,
            'href' => '/operations/airport/reimbursements?filter=action',
        ]);

        $readiness = $this->createMock(MovementReadinessReadService::class);
        $readiness->expects($this->once())->method('forCompany')->with(1, [106], $this->isInstanceOf(DateTimeImmutable::class))->willReturn([106 => [
            'checklist_id' => 106,
            'readiness_phase' => 'pickup_preparation',
            'ready' => false,
            'blocking_remaining_count' => 2,
            'additional_actions_remaining_count' => 1,
            'is_same_day_turnaround' => false,
            'requirements' => [
                ['code' => 'vehicle_inspected', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'action' => ['label' => 'Confirm vehicle inspected']],
                ['code' => 'vehicle_clean', 'phase' => 'pickup_preparation', 'blocking' => true, 'status' => 'unsatisfied', 'action' => ['label' => 'Record clean pickup condition']],
                ['code' => 'parking_location_recorded', 'phase' => 'pickup_preparation', 'blocking' => false, 'status' => 'unsatisfied', 'action' => ['label' => 'Record parking location']],
            ],
        ]]);
        $fleetSnapshot = $this->createMock(FleetSnapshotService::class);
        $fleetSnapshot->expects($this->once())->method('forSingleFleetCompany')->willReturn([
            'company_id' => 1,
            'total' => 1,
            'buckets' => [
                ['code' => 'rented', 'label' => 'Rented', 'count' => 0, 'vehicles' => []],
                ['code' => 'home', 'label' => 'Home', 'count' => 1, 'vehicles' => []],
            ],
            'vehicles' => [],
        ]);

        $intelligence = $this->createMock(MovementBoardIntelligenceService::class);
        $intelligence->expects($this->once())->method('enrich')->with(
            $this->callback(static fn (array $board): bool => ($board[0]['checklist_critical_open'] ?? null) === 2
                && ($board[0]['readiness_additional_remaining'] ?? null) === 1
                && ($board[0]['readiness_primary_action'] ?? null) === 'Confirm vehicle inspected'
                && ($board[0]['checklist_href'] ?? null) === '/operations/checklists/106'),
            $this->isInstanceOf(DateTimeImmutable::class),
        )->willReturnArgument(0);
        $financialSummary = $this->createMock(FinancialSummaryService::class);
        $financialSummary->expects($this->once())->method('currentMonth')->with(1, $this->isInstanceOf(DateTimeImmutable::class))->willReturn([
            'realized_operating_revenue' => 0.0,
            'realized_recoveries' => 0.0,
            'recorded_operating_costs' => 0.0,
            'net_realized_operating_result' => 0.0,
            'forecast_host_payout' => 0.0,
        ]);

        $dashboard = new DailyOperationsDashboardService(
            $tasks,
            $availability,
            $health,
            $statistics,
            $this->createStub(RevenueService::class),
            $importIssues,
            $vehicleMappings,
            $reconciliation,
            $checklists,
            $airport,
            $reimbursements,
            $intelligence,
            $readiness,
            $fleetSnapshot,
            financialSummaryService: $financialSummary,
        );
        $result = $dashboard->forToday(new DateTimeImmutable('2026-07-19 08:00:00'));
        $queue = $result['operational_queue'];

        $byCode = array_column($queue, null, 'code');
        $this->assertSame('/?movement=readiness#movement-board', $byCode['readiness']['href']);
        $this->assertSame(2, $byCode['readiness']['count']);
        $this->assertSame('Movement-readiness Actions', $byCode['readiness']['label']);
        $this->assertSame('/?movement=additional#movement-board', $byCode['additional']['href']);
        $this->assertSame(1, $byCode['additional']['count']);
        $this->assertSame('/?movement=pickup#movement-board', $byCode['pickup']['href']);
        $this->assertSame(3, $byCode['pickup']['count']);
        $this->assertSame('/?movement=return#movement-board', $byCode['return']['href']);
        $this->assertSame('/operations/airport', $byCode['airport_preparation']['href']);
        $this->assertSame(1, $byCode['airport_preparation']['count']);
        $this->assertSame('/turo/import-issues', $byCode['import_issues']['href']);
        $this->assertArrayNotHasKey('airport_receipts', $byCode);
        $this->assertArrayNotHasKey('turo_import', $byCode);
        $this->assertStringContainsString('latest recorded movement assessment', $result['data_honesty'][0]);
    }

    public function testChecklistReadinessUsesAuthoritativeTripAndMovementIdentity(): void
    {
        $dashboard = new DailyOperationsDashboardService();
        $method = new ReflectionMethod($dashboard, 'filterActionableMovementChecklists');
        $scheduledAt = '2026-09-18 13:30:00';
        $summaries = [
            ['id' => 77, 'turo_trip_normalized_id' => 258, 'fleet_vehicle_id' => 4, 'movement_type' => 'pickup', 'scheduled_at' => $scheduledAt],
            ['id' => 78, 'turo_trip_normalized_id' => 258, 'fleet_vehicle_id' => 4, 'movement_type' => 'return', 'scheduled_at' => '2026-09-23 13:30:00'],
            ['id' => 106, 'turo_trip_normalized_id' => 274, 'fleet_vehicle_id' => 8, 'movement_type' => 'pickup', 'scheduled_at' => $scheduledAt],
            ['id' => 107, 'turo_trip_normalized_id' => 274, 'fleet_vehicle_id' => 8, 'movement_type' => 'return', 'scheduled_at' => '2026-09-23 13:30:00'],
            ['id' => 60, 'turo_trip_normalized_id' => 257, 'fleet_vehicle_id' => 8, 'movement_type' => 'return', 'scheduled_at' => '2026-09-13 12:00:00'],
            ['id' => 201, 'turo_trip_normalized_id' => 301, 'fleet_vehicle_id' => 9, 'movement_type' => 'return', 'scheduled_at' => '2026-09-18 17:00:00'],
        ];
        $today = [
            'todays_pickups' => [
                ['id' => 274, 'fleet_vehicle_id' => 8, 'starts_at' => $scheduledAt],
            ],
            'todays_returns' => [
                ['id' => 301, 'fleet_vehicle_id' => 9, 'ends_at' => '2026-09-18 17:00:00'],
            ],
        ];

        $filtered = $method->invoke($dashboard, $summaries, $today);

        $this->assertSame([106, 201], array_column($filtered, 'id'));
        $this->assertSame([274, 301], array_column($filtered, 'turo_trip_normalized_id'));
        $this->assertNotContains(77, array_column($filtered, 'id'));
    }

    public static function recoveryScenarios(): array
    {
        return ['no awaiting recovery' => [false], 'another trip awaiting recovery on the same vehicle' => [true]];
    }

    public function testTimelineReadinessAttachesByExactTripAndMovementIdentity(): void
    {
        $dashboard = new DailyOperationsDashboardService();
        $method = new ReflectionMethod($dashboard, 'attachChecklistTimeline');
        $timeline = [
            ['event_type' => 'Pickup', 'reservation' => ['id' => 501, 'fleet_vehicle_id' => 9]],
            ['event_type' => 'Pickup', 'reservation' => ['id' => 502, 'fleet_vehicle_id' => 9]],
        ];
        $checklists = [
            ['id' => 41, 'turo_trip_normalized_id' => 501, 'fleet_vehicle_id' => 9, 'movement_type' => 'pickup', 'status_label' => '1 pickup action remaining'],
            ['id' => 42, 'turo_trip_normalized_id' => 502, 'fleet_vehicle_id' => 9, 'movement_type' => 'pickup', 'status_label' => 'Ready'],
        ];

        $attached = $method->invoke($dashboard, $timeline, $checklists);

        $this->assertSame('1 pickup action remaining', $attached[0]['checklist_status_label']);
        $this->assertSame('Ready', $attached[1]['checklist_status_label']);
    }

    public function testMovementFiltersUseProjectedReadinessAndMovementSemantics(): void
    {
        $dashboard = new DailyOperationsDashboardService();
        $board = [
            ['fleet_vehicle_id' => 1, 'readiness_display_remaining' => 2, 'readiness_additional_remaining' => 0, 'checklist_critical_open' => 0, 'pickup' => ['id' => 11], 'return' => null, 'turnaround' => null],
            ['fleet_vehicle_id' => 2, 'readiness_display_remaining' => 0, 'readiness_additional_remaining' => 1, 'checklist_critical_open' => 4, 'pickup' => null, 'return' => ['id' => 12], 'turnaround' => null],
            ['fleet_vehicle_id' => 3, 'readiness_display_remaining' => 0, 'readiness_additional_remaining' => 0, 'checklist_critical_open' => 3, 'pickup' => null, 'return' => null, 'turnaround' => ['minutes' => 90]],
            ['fleet_vehicle_id' => 4, 'readiness_display_remaining' => 0, 'readiness_additional_remaining' => 0, 'checklist_critical_open' => 0, 'pickup' => null, 'return' => null, 'turnaround' => null],
        ];

        $ids = static fn (array $vehicles): array => array_column($vehicles, 'fleet_vehicle_id');
        $this->assertSame([1], $ids($dashboard->filterMovementBoard($board, 'readiness')));
        $this->assertSame([2], $ids($dashboard->filterMovementBoard($board, 'additional')));
        $this->assertSame([1], $ids($dashboard->filterMovementBoard($board, 'pickup')));
        $this->assertSame([2], $ids($dashboard->filterMovementBoard($board, 'return')));
        $this->assertSame([3], $ids($dashboard->filterMovementBoard($board, 'turnaround')));
        $this->assertSame([1, 2, 3, 4], $ids($dashboard->filterMovementBoard($board, null)));
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
