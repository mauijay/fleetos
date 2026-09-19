<?php

namespace App\Services\Fleet;

use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use Config\Services;
use DateTimeImmutable;

class DailyOperationsDashboardService
{
    public function __construct(
        private readonly ?TaskService $taskService = null,
        private readonly ?VehicleAvailabilityService $availabilityService = null,
        private readonly ?FleetHealthService $healthService = null,
        private readonly ?FleetStatisticsService $statisticsService = null,
        private readonly ?RevenueService $revenueService = null,
        private readonly ?TuroImportIssueService $importIssueService = null,
        private readonly ?TuroVehicleMappingService $vehicleMappingService = null,
        private readonly ?TuroTripReconciliationService $reconciliationService = null,
        private readonly ?TripMovementChecklistService $checklistService = null,
        private readonly ?AirportMovementWorkflowService $airportWorkflowService = null,
        private readonly ?TuroAccessReimbursementService $turoAccessReimbursementService = null,
        private readonly ?MovementBoardIntelligenceService $movementBoardIntelligenceService = null,
        private readonly ?MovementReadinessReadService $movementReadinessReadService = null,
        private readonly ?FleetSnapshotService $fleetSnapshotService = null,
        private readonly VehicleDailyStateService $stateService = new VehicleDailyStateService(),
        private readonly MorningBriefingService $briefingService = new MorningBriefingService(),
        private readonly ?TripIncidentalReviewService $incidentalReviewService = null,
        private readonly ?OperatingExpenseService $operatingExpenseService = null,
        private readonly ?FinancialSummaryService $financialSummaryService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function forToday(?DateTimeImmutable $asOf = null, ?string $movementFilter = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $fleetSnapshot = $this->fleetSnapshot()->forSingleFleetCompany($asOf);
        $companyId = (int) $fleetSnapshot['company_id'];
        $financialSummary = $this->financialSummary()->currentMonth($companyId, $asOf);
        $today = $this->tasks()->today($asOf);
        $health = $this->health()->summary($asOf);
        $vehicles = $this->availability()->vehicleStatus($asOf);
        $currentMonth = $this->statistics()->currentMonth($asOf, $companyId, $financialSummary);
        $importIssues = $this->importIssues()->attentionSummary();
        $vehicleMappings = $this->vehicleMappings()->attentionSummary();
        $reconciliation = $this->reconciliation()->attentionSummary();
        $airport = $this->airport()->attentionSummary($companyId, $asOf);
        $reimbursements = $this->reimbursements()->attentionSummary($companyId);
        $incidentals = $this->incidentals()->attentionSummaryForSingleCompany($asOf);
        $expenses = $this->operatingExpenses()->attentionSummary($companyId);
        $checklistSummaries = $this->filterActionableMovementChecklists(
            $this->checklists()->summariesForDay($asOf),
            $today,
        );
        $checklists = $this->attachReadinessProjections($checklistSummaries, $asOf);
        $awaitingVehicleIds = array_fill_keys(array_map('intval', array_column($today['awaiting_recovery'] ?? [], 'fleet_vehicle_id')), true);
        $actionableChecklists = array_values(array_filter($checklists, static fn (array $checklist): bool => ! isset($awaitingVehicleIds[(int) ($checklist['fleet_vehicle_id'] ?? 0)])));
        $board = $this->stateService->movementBoard($vehicles, $today, $health, $asOf);
        $board = $this->attachCurrentPositions($board, $fleetSnapshot['vehicles']);
        $board = $this->attachChecklistSummaries($board, $actionableChecklists);
        $board = $this->movementBoardIntelligence()->enrich($board, $asOf, $companyId);

        $externalAlerts = $this->externalAlerts($importIssues, $vehicleMappings, $reconciliation, $airport, $reimbursements, $health);
        $attention = $this->stateService->immediateAttention($board, $externalAlerts);
        $filteredBoard = $this->filterMovementBoard($board, $movementFilter);

        return [
            'briefing' => $this->briefingService->briefing($board, $attention, count($today['todays_pickups']), count($today['todays_returns'])),
            'fleet_snapshot' => $fleetSnapshot,
            'movement_board' => $filteredBoard,
            'movement_filter' => $this->movementFilterView($movementFilter, count($board), count($filteredBoard)),
            'timeline' => $this->attachChecklistTimeline($this->stateService->timeline($today, $asOf), $checklists),
            'attention' => $attention,
            'fleet_status' => $this->stateService->statusCounts($board, (float) $currentMonth['fleet_utilization'], $fleetSnapshot, $today),
            'operational_queue' => $this->operationalQueue($today, $attention, $importIssues, $vehicleMappings, $reconciliation, $airport, $reimbursements, $incidentals, $expenses, $actionableChecklists),
            'financial' => [
                'Realized Operating Revenue' => '$' . number_format((float) $financialSummary['realized_operating_revenue'], 2),
                'Realized Recoveries' => '$' . number_format((float) $financialSummary['realized_recoveries'], 2),
                'Recorded Operating Costs' => '$' . number_format((float) $financialSummary['recorded_operating_costs'], 2),
                'Net Realized Operating Result' => '$' . number_format((float) $financialSummary['net_realized_operating_result'], 2),
                'Forecast Host Payout' => '$' . number_format((float) $financialSummary['forecast_host_payout'], 2),
            ],
            'financial_summary' => $financialSummary,
            'data_honesty' => [
                'Condition and energy are shown from the latest recorded movement assessment; missing observations remain explicitly not captured.',
                'Current locations and rented possession come from active authoritative movement events; scheduled and planned locations never replace current position.',
                'Confirmed future trips and recommendation strength depend on Turo import freshness; stale snapshots require operator review.',
                'Recorded Operating Costs are operational records recognized by FleetOS; they are not proof of bank settlement.',
                'Positioning recommendations do not include live GPS, traffic, travel time, or automatic transportation availability.',
            ],
        ];
    }

    private function financialSummary(): FinancialSummaryService
    {
        return $this->financialSummaryService ?? Services::financialSummaryService();
    }

    private function externalAlerts(array $importIssues, array $vehicleMappings, array $reconciliation, array $airport, array $reimbursements, array $health): array
    {
        return array_values(array_filter([
            ['count' => (int) $importIssues['total_unresolved'], 'severity' => 'today', 'label' => 'Import issues require review.', 'detail' => (string) $importIssues['total_unresolved'] . ' unresolved import issue(s).', 'href' => $importIssues['href']],
            ['count' => (int) $vehicleMappings['unique_unmatched_vehicles'], 'severity' => 'today', 'label' => 'Turo vehicles need mapping.', 'detail' => (string) $vehicleMappings['affected_issues'] . ' affected row(s).', 'href' => $vehicleMappings['href']],
            ['count' => (int) $reconciliation['awaiting_reconciliation'], 'severity' => 'today', 'label' => 'Mapped rows need reconciliation.', 'detail' => (string) $reconciliation['awaiting_reconciliation'] . ' historical row(s).', 'href' => $reconciliation['href']],
            ['count' => (int) $airport['airport_workflows_requiring_action'], 'severity' => 'today', 'label' => 'Airport workflows need attention.', 'detail' => (string) $airport['airport_workflows_requiring_action'] . ' airport movement(s).', 'href' => $airport['href']],
            ['count' => (int) $reimbursements['total_actionable'], 'severity' => 'today', 'label' => 'Airport follow-up requires attention.', 'detail' => (string) $reimbursements['ready_to_file'] . ' ready · ' . (string) $reimbursements['needs_setup'] . ' needs setup · ' . (string) $reimbursements['filed_pending'] . ' awaiting outcome.', 'href' => $reimbursements['href']],
            ['count' => count($health['claims_requiring_follow_up'] ?? []), 'severity' => 'today', 'label' => 'Claims require follow-up.', 'detail' => count($health['claims_requiring_follow_up'] ?? []) . ' open claim(s).', 'href' => '#fleet-health'],
        ], static fn (array $alert): bool => (int) $alert['count'] > 0));
    }

    private function operationalQueue(array $today, array $attention, array $importIssues, array $vehicleMappings, array $reconciliation, array $airport, array $reimbursements, array $incidentals, array $expenses, array $checklists): array
    {
        $blockingActions = array_sum(array_map(static fn (array $checklist): int => (int) ($checklist['blocking_remaining_count'] ?? 0), $checklists));
        $additionalActions = array_sum(array_map(static fn (array $checklist): int => (int) ($checklist['additional_actions_remaining_count'] ?? 0), $checklists));
        $pendingAirportDeliveries = count(array_filter($today['airport_deliveries'], static fn (array $delivery): bool => ($delivery['completed_at'] ?? null) === null));

        $actions = [
            ['code' => 'readiness', 'label' => 'Movement-readiness Actions', 'count' => $blockingActions, 'href' => '/?movement=readiness#movement-board'],
            ['code' => 'additional', 'label' => 'Review Additional Movement Actions', 'count' => $additionalActions, 'href' => '/?movement=additional#movement-board'],
            ['code' => 'pickup', 'label' => 'Review Today\'s Pickups', 'count' => count($today['todays_pickups']), 'href' => '/?movement=pickup#movement-board'],
            ['code' => 'return', 'label' => 'Review Today\'s Returns', 'count' => count($today['todays_returns']), 'href' => '/?movement=return#movement-board'],
            ['code' => 'cleaning', 'label' => 'Cleaning Required', 'count' => count($today['cleaning_tasks'] ?? []), 'href' => '/#todays-mission'],
            ['code' => 'charging', 'label' => 'Charge/Fuel or Energy Check', 'count' => count($today['charging_tasks'] ?? []), 'href' => '/#todays-mission'],
            ['code' => 'turnaround', 'label' => 'Review Same-Day Turnarounds', 'count' => count(array_filter($attention, static fn (array $item): bool => str_contains($item['label'], 'turnaround'))), 'href' => '/?movement=turnaround#movement-board'],
            ['code' => 'airport_preparation', 'label' => 'Prepare Airport Deliveries', 'count' => $pendingAirportDeliveries, 'href' => '/operations/airport'],
            ['code' => 'import_issues', 'label' => 'Review Import Issues', 'count' => (int) $importIssues['total_unresolved'], 'href' => $importIssues['href']],
            ['code' => 'vehicle_mapping', 'label' => 'Map Turo Vehicles', 'count' => (int) $vehicleMappings['unique_unmatched_vehicles'], 'href' => $vehicleMappings['href']],
            ['code' => 'reconciliation', 'label' => 'Reprocess Import Rows', 'count' => (int) $reconciliation['awaiting_reconciliation'], 'href' => $reconciliation['href']],
            ['code' => 'airport_workflows', 'label' => 'Today\'s Airport Deliveries', 'count' => (int) $airport['airport_workflows_requiring_action'], 'href' => $airport['href']],
            ['code' => 'airport_receipts', 'label' => 'Airport Follow-up', 'count' => (int) $reimbursements['total_actionable'], 'href' => $reimbursements['href']],
            ['code' => 'incidentals_review', 'label' => 'Incidentals Review', 'count' => (int) $incidentals['total'], 'href' => $incidentals['href']],
            ['code' => 'operating_expenses', 'label' => 'Expenses to classify', 'count' => (int) $expenses['total'], 'href' => $expenses['href']],
        ];

        foreach ($today['awaiting_recovery'] ?? [] as $report) {
            $actions[] = [
                'code' => 'recover_vehicle_' . (int) $report['turo_trip_normalized_id'],
                'label' => 'Recover Vehicle',
                'count' => 1,
                'href' => $report['href'],
                'detail' => $report['fleet_label'] . ' — Returned to HNL, awaiting recovery. ' . $report['reported_location_label'],
            ];
        }
        foreach ($today['recovery_exceptions'] ?? [] as $exception) {
            $actions[] = [
                'code' => 'recovery_exception_' . (int) $exception['id'],
                'label' => 'Recovery exception: ' . ucwords(str_replace('_', ' ', (string) $exception['exception_code'])),
                'count' => 1,
                'href' => '/operations/checklists/' . (int) $exception['checklist_id'] . '#recovery-exceptions',
                'detail' => (string) $exception['fleet_code'] . ' — follow up on recovery exception #' . (int) $exception['id'],
            ];
        }

        return array_values(array_map(static function (array $action): array {
            $count = (int) $action['count'];

            return array_merge($action, [
                'actionable' => true,
                'detail' => $action['detail'] ?? $count . ' item' . ($count === 1 ? '' : 's'),
            ]);
        }, array_filter($actions, static fn (array $action): bool => (int) $action['count'] > 0)));
    }

    /** @return array<int, array<string, mixed>> */
    public function filterMovementBoard(array $board, ?string $filter): array
    {
        if ($filter === null) {
            return $board;
        }

        return array_values(array_filter($board, static fn (array $vehicle): bool => match ($filter) {
            'readiness' => (int) ($vehicle['readiness_display_remaining'] ?? 0) > 0,
            'additional' => (int) ($vehicle['readiness_additional_remaining'] ?? 0) > 0,
            'pickup' => ($vehicle['pickup'] ?? null) !== null,
            'return' => ($vehicle['return'] ?? null) !== null,
            'turnaround' => ($vehicle['turnaround'] ?? null) !== null,
            default => true,
        }));
    }

    /** @return array{active:?string,label:?string,total_count:int,visible_count:int,clear_href:string} */
    private function movementFilterView(?string $filter, int $totalCount, int $visibleCount): array
    {
        $labels = [
            'readiness' => 'Blocking readiness work',
            'additional' => 'Additional movement actions',
            'pickup' => 'Today\'s pickups',
            'return' => 'Today\'s returns',
            'turnaround' => 'Same-day turnarounds',
        ];

        return [
            'active' => isset($labels[$filter ?? '']) ? $filter : null,
            'label' => $labels[$filter ?? ''] ?? null,
            'total_count' => $totalCount,
            'visible_count' => $visibleCount,
            'clear_href' => '/#movement-board',
        ];
    }

    private function tasks(): TaskService
    {
        return $this->taskService ?? service('taskService');
    }
    private function availability(): VehicleAvailabilityService
    {
        return $this->availabilityService ?? service('vehicleAvailabilityService');
    }
    private function health(): FleetHealthService
    {
        return $this->healthService ?? service('fleetHealthService');
    }
    private function statistics(): FleetStatisticsService
    {
        return $this->statisticsService ?? service('fleetStatisticsService');
    }
    private function importIssues(): TuroImportIssueService
    {
        return $this->importIssueService ?? service('turoImportIssueService');
    }
    private function vehicleMappings(): TuroVehicleMappingService
    {
        return $this->vehicleMappingService ?? service('turoVehicleMappingService');
    }
    private function reconciliation(): TuroTripReconciliationService
    {
        return $this->reconciliationService ?? service('turoTripReconciliationService');
    }
    private function checklists(): TripMovementChecklistService
    {
        return $this->checklistService ?? service('tripMovementChecklistService');
    }
    private function airport(): AirportMovementWorkflowService
    {
        return $this->airportWorkflowService ?? service('airportMovementWorkflowService');
    }
    private function reimbursements(): TuroAccessReimbursementService
    {
        return $this->turoAccessReimbursementService ?? service('turoAccessReimbursementService');
    }
    private function incidentals(): TripIncidentalReviewService
    {
        return $this->incidentalReviewService ?? Services::tripIncidentalReviewService();
    }
    private function operatingExpenses(): OperatingExpenseService
    {
        return $this->operatingExpenseService ?? Services::operatingExpenseService();
    }
    private function movementBoardIntelligence(): MovementBoardIntelligenceService
    {
        return $this->movementBoardIntelligenceService ?? new MovementBoardIntelligenceService();
    }

    private function movementReadiness(): MovementReadinessReadService
    {
        return $this->movementReadinessReadService ?? Services::movementReadinessReadService();
    }

    private function fleetSnapshot(): FleetSnapshotService
    {
        return $this->fleetSnapshotService ?? Services::fleetSnapshotService();
    }

    /** @param array<int, array<string, mixed>> $checklists @return array<int, array<string, mixed>> */
    private function attachReadinessProjections(array $checklists, DateTimeImmutable $asOf): array
    {
        $idsByCompany = [];
        foreach ($checklists as $checklist) {
            $companyId = (int) ($checklist['company_id'] ?? 0);
            if ($companyId < 1) {
                throw new \RuntimeException('Dashboard readiness requires a company-scoped checklist.');
            }
            $idsByCompany[$companyId][] = (int) $checklist['id'];
        }

        $projections = [];
        foreach ($idsByCompany as $companyId => $checklistIds) {
            $projections += $this->movementReadiness()->forCompany($companyId, $checklistIds, $asOf);
        }

        return array_map(static function (array $checklist) use ($projections): array {
            $projection = $projections[(int) $checklist['id']] ?? null;
            if ($projection === null) {
                throw new \RuntimeException('A company-scoped readiness projection is missing for a dashboard checklist.');
            }
            $blocking = (int) $projection['blocking_remaining_count'];
            $additional = (int) $projection['additional_actions_remaining_count'];
            $movementType = (string) ($projection['movement_type'] ?? $checklist['movement_type'] ?? 'movement');

            return array_merge($checklist, [
                'readiness_projection' => $projection,
                'blocking_remaining_count' => $blocking,
                'additional_actions_remaining_count' => $additional,
                'status_label' => $blocking === 0 ? 'Ready' : $blocking . ' ' . $movementType . ' action' . ($blocking === 1 ? '' : 's') . ' remaining',
            ]);
        }, $checklists);
    }

    private function attachChecklistSummaries(array $board, array $checklists): array
    {
        $byVehicle = [];
        foreach ($checklists as $checklist) {
            $byVehicle[(int) $checklist['fleet_vehicle_id']][] = $checklist;
        }

        return array_map(static function (array $vehicle) use ($byVehicle): array {
            $vehicleChecklists = $byVehicle[(int) $vehicle['fleet_vehicle_id']] ?? [];
            $remaining = array_sum(array_map(static fn (array $summary): int => (int) $summary['blocking_remaining_count'], $vehicleChecklists));
            $additional = array_sum(array_map(static fn (array $summary): int => (int) $summary['additional_actions_remaining_count'], $vehicleChecklists));
            $blockers = [];
            $turnaroundRemaining = 0;
            $energyMissing = false;
            $energyBelowTarget = false;
            foreach ($vehicleChecklists as $summary) {
                $projection = $summary['readiness_projection'];
                foreach ($projection['requirements'] as $requirement) {
                    if (in_array($requirement['phase'] ?? null, [MovementReadinessProjectionService::PHASE_PICKUP_PREPARATION, MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION], true)
                        && ($requirement['status'] ?? null) === MovementReadinessProjectionService::STATUS_UNSATISFIED
                        && ($requirement['actionable'] ?? true)) {
                        $energyMissing = $energyMissing || ($requirement['code'] ?? null) === 'energy_known';
                        $energyBelowTarget = $energyBelowTarget || ($requirement['code'] ?? null) === 'energy_ready';
                    }
                    if (($requirement['phase'] ?? null) === ($projection['readiness_phase'] ?? null)
                        && ($requirement['blocking'] ?? false)
                        && ($requirement['status'] ?? null) === MovementReadinessProjectionService::STATUS_UNSATISFIED
                        && ($requirement['actionable'] ?? true)) {
                        $blockers[] = array_merge($requirement, ['href' => $summary['href']]);
                    }
                    if (($projection['is_same_day_turnaround'] ?? false)
                        && ($requirement['phase'] ?? null) === MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION
                        && ($requirement['blocking'] ?? false)
                        && ($requirement['status'] ?? null) === MovementReadinessProjectionService::STATUS_UNSATISFIED
                        && ($requirement['actionable'] ?? true)) {
                        $turnaroundRemaining++;
                        $blockers[] = array_merge($requirement, ['href' => $summary['href']]);
                    }
                }
            }
            $first = $vehicleChecklists[0] ?? null;
            $flags = array_values(array_unique(array_merge($vehicle['flags'] ?? [], $energyMissing ? ['energy_check_required'] : ($energyBelowTarget ? ['charging_required'] : []))));
            $chargeAction = $energyMissing ? 'Record Charge/Fuel percentage' : ($energyBelowTarget ? 'Charge/Fuel to pickup target' : null);
            $actions = $vehicle['actions'] ?? [];
            if ($chargeAction !== null) {
                $actions = array_values(array_filter($actions, static fn (string $action): bool => $action !== 'No action due'));
                $actions[] = $chargeAction;
            }

            return array_merge($vehicle, [
                'flags' => $flags,
                'actions' => $actions,
                'charging_status_label' => $energyMissing ? 'Charge/Fuel not captured' : ($energyBelowTarget ? 'Below pickup target' : 'No actionable Charge/Fuel preparation recorded'),
                'checklists' => $vehicleChecklists,
                'checklist_progress_label' => $vehicleChecklists === [] ? 'No movement workflow today' : ($remaining === 0 ? 'Ready' : $remaining . ' action' . ($remaining === 1 ? '' : 's') . ' across today\'s movements'),
                'checklist_ready' => $vehicleChecklists !== [] && $remaining === 0,
                'checklist_required_remaining' => $remaining,
                'checklist_critical_open' => $remaining,
                'readiness_blocking_remaining' => $remaining,
                'readiness_additional_remaining' => $additional,
                'readiness_blockers' => $blockers,
                'readiness_primary_action' => $blockers[0]['action']['label'] ?? null,
                'turnaround_readiness_remaining' => $turnaroundRemaining,
                'checklist_href' => $blockers[0]['href'] ?? $first['href'] ?? null,
            ]);
        }, $board);
    }

    /** @param array<int, array<string, mixed>> $board @param array<int, array<string, mixed>> $positions @return array<int, array<string, mixed>> */
    private function attachCurrentPositions(array $board, array $positions): array
    {
        $byVehicle = array_column($positions, null, 'id');

        return array_map(static fn (array $vehicle): array => array_merge($vehicle, [
            'current_position' => $byVehicle[(int) $vehicle['fleet_vehicle_id']] ?? null,
        ]), $board);
    }

    private function attachChecklistTimeline(array $timeline, array $checklists): array
    {
        $byMovement = [];
        foreach ($checklists as $checklist) {
            $tripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
            $movementType = strtolower((string) ($checklist['movement_type'] ?? ''));
            if ($tripId > 0 && in_array($movementType, ['pickup', 'return'], true)) {
                $byMovement[$tripId . ':' . $movementType] = $checklist;
            }
        }

        return array_map(static function (array $event) use ($byMovement): array {
            $tripId = (int) ($event['reservation']['id'] ?? 0);
            $movementType = strtolower((string) ($event['event_type'] ?? ''));
            $summary = $byMovement[$tripId . ':' . $movementType] ?? null;

            return array_merge($event, [
                'checklist_status_label' => $summary['status_label'] ?? 'Checklist not created',
                'checklist_href' => $summary['href'] ?? '#operational-queue',
            ]);
        }, $timeline);
    }

    /**
     * Checklist rows are historical storage. Current readiness is limited to the
     * movement identities TaskService has already classified as actionable.
     *
     * @param array<int, array<string, mixed>> $checklists
     * @param array<string, array<int, array<string, mixed>>> $today
     * @return array<int, array<string, mixed>>
     */
    private function filterActionableMovementChecklists(array $checklists, array $today): array
    {
        $allowed = [];
        foreach ([
            'todays_pickups' => 'pickup',
            'todays_returns' => 'return',
        ] as $taskKey => $movementType) {
            foreach ($today[$taskKey] ?? [] as $movement) {
                $tripId = (int) ($movement['id'] ?? $movement['turo_trip_normalized_id'] ?? 0);
                if ($tripId > 0) {
                    $allowed[$tripId . ':' . $movementType] = true;
                }
            }
        }

        return array_values(array_filter(
            $checklists,
            static function (array $checklist) use ($allowed): bool {
                $tripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
                $movementType = (string) ($checklist['movement_type'] ?? '');

                return isset($allowed[$tripId . ':' . $movementType]);
            },
        ));
    }
}
