<?php

namespace App\Services\Fleet;

use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
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
        private readonly VehicleDailyStateService $stateService = new VehicleDailyStateService(),
        private readonly MorningBriefingService $briefingService = new MorningBriefingService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function forToday(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $today = $this->tasks()->today($asOf);
        $health = $this->health()->summary($asOf);
        $vehicles = $this->availability()->vehicleStatus($asOf);
        $currentMonth = $this->statistics()->currentMonth($asOf);
        $importIssues = $this->importIssues()->attentionSummary();
        $vehicleMappings = $this->vehicleMappings()->attentionSummary();
        $reconciliation = $this->reconciliation()->attentionSummary();
        $airport = $this->airport()->attentionSummary($asOf);
        $reimbursements = $this->reimbursements()->attentionSummary();
        $this->checklists()->ensureForDay($today);
        $checklists = $this->checklists()->summariesForDay($asOf);
        $board = $this->stateService->movementBoard($vehicles, $today, $health, $asOf);
        $board = $this->attachChecklistSummaries($board, $checklists);
        usort($board, static fn (array $left, array $right): int => strcmp($left['sort_priority'], $right['sort_priority']));

        $externalAlerts = $this->externalAlerts($importIssues, $vehicleMappings, $reconciliation, $airport, $reimbursements, $health);
        $attention = $this->stateService->immediateAttention($board, $externalAlerts);

        return [
            'briefing' => $this->briefingService->briefing($board, $attention, count($today['todays_pickups']), count($today['todays_returns'])),
            'movement_board' => $board,
            'timeline' => $this->attachChecklistTimeline($this->stateService->timeline($today, $asOf), $checklists),
            'attention' => $attention,
            'fleet_status' => $this->stateService->statusCounts($board, (float) $currentMonth['fleet_utilization']),
            'operational_queue' => $this->operationalQueue($today, $attention, $importIssues, $vehicleMappings, $reconciliation, $airport, $reimbursements),
            'financial' => [
                'current_month_revenue' => '$' . number_format((float) $currentMonth['completed_revenue'], 0),
                'forecast_revenue' => '$' . number_format((float) $currentMonth['forecast_revenue'], 0),
                'average_daily_rate' => '$' . number_format((float) $currentMonth['average_daily_rate'], 0),
                'fleet_utilization' => number_format((float) $currentMonth['fleet_utilization'] * 100, 1) . '%',
                'revenue_today' => 'Not captured reliably',
                'revenue_this_week' => 'Not captured reliably',
            ],
            'data_honesty' => [
                'Battery telemetry is not connected, so charge levels require confirmation.',
                'Cleaning completion is not tracked as a workflow yet; same-day returns are flagged for confirmation.',
                'Pickup and return locations are only available when airport delivery records exist; otherwise location is not captured.',
                'Travel time and staging deadlines are not calculated without a maps/traffic integration.',
            ],
        ];
    }

    private function externalAlerts(array $importIssues, array $vehicleMappings, array $reconciliation, array $airport, array $reimbursements, array $health): array
    {
        return array_values(array_filter([
            ['count' => (int) $importIssues['total_unresolved'], 'severity' => 'today', 'label' => 'Import issues require review.', 'detail' => (string) $importIssues['total_unresolved'] . ' unresolved import issue(s).', 'href' => $importIssues['href']],
            ['count' => (int) $vehicleMappings['unique_unmatched_vehicles'], 'severity' => 'today', 'label' => 'Turo vehicles need mapping.', 'detail' => (string) $vehicleMappings['affected_issues'] . ' affected row(s).', 'href' => $vehicleMappings['href']],
            ['count' => (int) $reconciliation['awaiting_reconciliation'], 'severity' => 'today', 'label' => 'Mapped rows need reconciliation.', 'detail' => (string) $reconciliation['awaiting_reconciliation'] . ' historical row(s).', 'href' => $reconciliation['href']],
            ['count' => (int) $airport['airport_workflows_requiring_action'], 'severity' => 'today', 'label' => 'Airport workflows need attention.', 'detail' => (string) $airport['airport_workflows_requiring_action'] . ' airport movement(s).', 'href' => $airport['href']],
            ['count' => (int) $reimbursements['ready_to_file'], 'severity' => 'today', 'label' => 'Airport parking reimbursements are ready to file.', 'detail' => (string) $reimbursements['ready_to_file'] . ' claim-ready item(s), expected $' . number_format((float) $reimbursements['expected_reimbursement_total'], 0) . '.', 'href' => $reimbursements['href']],
            ['count' => (int) $reimbursements['needs_classification'], 'severity' => 'today', 'label' => 'Airport receipts need classification.', 'detail' => (string) $reimbursements['needs_classification'] . ' receipt(s) need a business bucket.', 'href' => $reimbursements['href']],
            ['count' => (int) $reimbursements['expenses_missing_run'], 'severity' => 'later', 'label' => 'Chase-vehicle expenses are missing a run.', 'detail' => (string) $reimbursements['expenses_missing_run'] . ' operations expense(s) need an airport run.', 'href' => $reimbursements['href']],
            ['count' => (int) $reimbursements['runs_with_unallocated_expenses'], 'severity' => 'later', 'label' => 'Airport run expenses need allocation review.', 'detail' => (string) $reimbursements['runs_with_unallocated_expenses'] . ' expense(s) are not allocated to vehicles.', 'href' => $reimbursements['href']],
            ['count' => count($health['claims_requiring_follow_up'] ?? []), 'severity' => 'today', 'label' => 'Claims require follow-up.', 'detail' => count($health['claims_requiring_follow_up'] ?? []) . ' open claim(s).', 'href' => '#fleet-health'],
        ], static fn (array $alert): bool => (int) $alert['count'] > 0));
    }

    private function operationalQueue(array $today, array $attention, array $importIssues, array $vehicleMappings, array $reconciliation, array $airport, array $reimbursements): array
    {
        return array_values(array_filter([
            ['label' => 'Review Today\'s Pickups', 'count' => count($today['todays_pickups']), 'href' => '#daily-timeline'],
            ['label' => 'Review Today\'s Returns', 'count' => count($today['todays_returns']), 'href' => '#daily-timeline'],
            ['label' => 'Review Same-Day Turnarounds', 'count' => count(array_filter($attention, static fn (array $item): bool => str_contains($item['label'], 'turnaround'))), 'href' => '#movement-board'],
            ['label' => 'Prepare Airport Deliveries', 'count' => count($today['airport_deliveries']), 'href' => '#daily-timeline'],
            ['label' => 'Review Import Issues', 'count' => (int) $importIssues['total_unresolved'], 'href' => $importIssues['href']],
            ['label' => 'Map Turo Vehicles', 'count' => (int) $vehicleMappings['unique_unmatched_vehicles'], 'href' => $vehicleMappings['href']],
            ['label' => 'Reprocess Import Rows', 'count' => (int) $reconciliation['awaiting_reconciliation'], 'href' => $reconciliation['href']],
            ['label' => 'Today\'s Airport Deliveries', 'count' => (int) $airport['airport_workflows_requiring_action'], 'href' => $airport['href']],
            ['label' => 'Airport Receipt Inbox', 'count' => (int) $reimbursements['needs_classification'] + (int) $reimbursements['ready_to_file'] + (int) $reimbursements['filed_pending'] + (int) $reimbursements['expenses_missing_run'], 'href' => $reimbursements['href']],
            ['label' => 'Import Turo Trips', 'count' => 1, 'href' => '/turo/imports'],
        ], static fn (array $action): bool => (int) $action['count'] > 0 || $action['label'] === 'Import Turo Trips'));
    }

    private function tasks(): TaskService { return $this->taskService ?? service('taskService'); }
    private function availability(): VehicleAvailabilityService { return $this->availabilityService ?? service('vehicleAvailabilityService'); }
    private function health(): FleetHealthService { return $this->healthService ?? service('fleetHealthService'); }
    private function statistics(): FleetStatisticsService { return $this->statisticsService ?? service('fleetStatisticsService'); }
    private function importIssues(): TuroImportIssueService { return $this->importIssueService ?? service('turoImportIssueService'); }
    private function vehicleMappings(): TuroVehicleMappingService { return $this->vehicleMappingService ?? service('turoVehicleMappingService'); }
    private function reconciliation(): TuroTripReconciliationService { return $this->reconciliationService ?? service('turoTripReconciliationService'); }
    private function checklists(): TripMovementChecklistService { return $this->checklistService ?? service('tripMovementChecklistService'); }
    private function airport(): AirportMovementWorkflowService { return $this->airportWorkflowService ?? service('airportMovementWorkflowService'); }
    private function reimbursements(): TuroAccessReimbursementService { return $this->turoAccessReimbursementService ?? service('turoAccessReimbursementService'); }

    private function attachChecklistSummaries(array $board, array $checklists): array
    {
        $byVehicle = [];
        foreach ($checklists as $checklist) {
            $byVehicle[(int) $checklist['fleet_vehicle_id']][] = $checklist;
        }

        return array_map(static function (array $vehicle) use ($byVehicle): array {
            $vehicleChecklists = $byVehicle[(int) $vehicle['fleet_vehicle_id']] ?? [];
            $remaining = array_sum(array_map(static fn (array $summary): int => (int) $summary['required_remaining_count'], $vehicleChecklists));
            $critical = array_sum(array_map(static fn (array $summary): int => (int) $summary['critical_open_count'], $vehicleChecklists));
            $first = $vehicleChecklists[0] ?? null;

            return array_merge($vehicle, [
                'checklists' => $vehicleChecklists,
                'checklist_progress_label' => $vehicleChecklists === [] ? 'No movement checklist today' : ($remaining === 0 ? 'Ready for movement' : $remaining . ' required checklist item' . ($remaining === 1 ? '' : 's') . ' remaining'),
                'checklist_ready' => $vehicleChecklists !== [] && $remaining === 0,
                'checklist_required_remaining' => $remaining,
                'checklist_critical_open' => $critical,
                'checklist_href' => $first['href'] ?? null,
            ]);
        }, $board);
    }

    private function attachChecklistTimeline(array $timeline, array $checklists): array
    {
        $byVehicleType = [];
        foreach ($checklists as $checklist) {
            $eventType = $checklist['movement_type'] === 'return' ? 'Return' : 'Pickup';
            $byVehicleType[(int) $checklist['fleet_vehicle_id'] . ':' . $eventType] = $checklist;
        }

        return array_map(static function (array $event) use ($byVehicleType): array {
            $key = (int) ($event['reservation']['fleet_vehicle_id'] ?? 0) . ':' . ($event['event_type'] ?? '');
            $summary = $byVehicleType[$key] ?? null;

            return array_merge($event, [
                'checklist_status_label' => $summary['status_label'] ?? 'Checklist not created',
                'checklist_href' => $summary['href'] ?? '#operational-queue',
            ]);
        }, $timeline);
    }
}
