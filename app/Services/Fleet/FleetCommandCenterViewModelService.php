<?php

namespace App\Services\Fleet;

use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use Config\App;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class FleetCommandCenterViewModelService
{
    public function __construct(
        private readonly ?FleetCommandService $commandService = null,
        private readonly ?FleetStatisticsService $statisticsService = null,
        private readonly ?FleetHealthService $healthService = null,
        private readonly ?TaskService $taskService = null,
        private readonly ?VehicleAvailabilityService $availabilityService = null,
        private readonly ?TripAnalyticsService $tripAnalyticsService = null,
        private readonly ?DecisionSupportDashboardService $decisionSupportService = null,
        private readonly ?TuroImportIssueService $importIssueService = null,
        private readonly ?TuroVehicleMappingService $vehicleMappingService = null,
        private readonly ?TuroTripReconciliationService $tripReconciliationService = null,
        private readonly ?DailyOperationsDashboardService $dailyOperationsService = null,
    ) {
    }

    /** Returns the complete display model for the Fleet Command Center. */
    public function forToday(?DateTimeImmutable $asOf = null, ?string $queueScope = null, ?string $movementFilter = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $dailyOperations = $this->dailyOperations()->forToday($asOf, $movementFilter);
        $companyId = (int) $dailyOperations['fleet_snapshot']['company_id'];
        $financialSummary = $dailyOperations['financial_summary'] ?? [
            'realized_operating_revenue' => 0.0,
            'realized_recoveries' => 0.0,
            'recorded_operating_costs' => 0.0,
            'net_realized_operating_result' => 0.0,
            'forecast_host_payout' => 0.0,
        ];
        $command = $this->command()->snapshot($asOf, $companyId, $financialSummary);
        $statistics = $this->statistics()->summary($asOf, $companyId, $financialSummary);
        $health = $this->health()->summary($asOf);
        $today = $this->tasks()->today($asOf);
        $tomorrow = $this->tasks()->tomorrow($asOf);
        $timelineStart = $asOf->setTimezone($this->businessTimezone())->setTime(0, 0);
        $timelineEnd = $timelineStart->modify('+7 days');
        $tripAnalytics = $this->tripAnalytics()->summary(new DateTimeImmutable($asOf->format('Y-01-01 00:00:00')), $timelineEnd);
        $decisionSupport = $this->decisionSupport()->recommendations($asOf);
        $importIssues = $this->importIssues()->attentionSummary();
        $vehicleMappings = $this->vehicleMappings()->attentionSummary();
        $tripReconciliation = $this->tripReconciliation()->attentionSummary();
        $queueView = $this->queueView($queueScope, $today, $tomorrow, $command['urgent_items'], $dailyOperations['operational_queue']);
        $dailyOperations['queue_view'] = $queueView;

        return [
            'page_title' => 'Fleet Command Center',
            'as_of' => $asOf->format('M j, Y g:i A'),
            'navigation' => $this->navigation(),
            'fleet_status' => $this->fleetStatusCards($statistics, $dailyOperations['fleet_snapshot']),
            'mission' => $this->missionCards($today),
            'mission_clear' => $this->missionClear($today),
            'import_issues' => $importIssues,
            'vehicle_mappings' => $vehicleMappings,
            'trip_reconciliation' => $tripReconciliation,
            'daily_operations' => $dailyOperations,
            'decision_support' => $decisionSupport,
            'vehicles' => $this->vehicleCards($command['vehicle_statuses'], $health),
            'timeline' => $this->timelineCards($command['todays_timeline'], $timelineStart, $timelineEnd),
            'financial' => $this->financialSnapshot($financialSummary),
            'health_alerts' => $this->healthAlerts($health),
            'executive_kpis' => $this->executiveKpis($tripAnalytics),
            'activity' => $this->activityPanel($today, $tomorrow, $health, $command, $dailyOperations['fleet_snapshot'], $queueView),
            'future_integrations' => $this->futureIntegrations(),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'true'],
            ['label' => 'Fleet Activity', 'href' => '#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Incidentals Review', 'href' => '/operations/incidentals', 'active' => 'false'],
            ['label' => 'Location Aliases', 'href' => '/operations/movement-locations', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Decision Support', 'href' => '#decision-support', 'active' => 'false'],
            ['label' => 'Reservations', 'href' => '#fleet-timeline', 'active' => 'false'],
            ['label' => 'Trips', 'href' => '#executive-kpis', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '#financial-snapshot', 'active' => 'false'],
            ['label' => 'Expenses & Receipts', 'href' => '/operations/expenses?view=needs_attention', 'active' => 'false'],
            ['label' => 'Maintenance', 'href' => '#fleet-health', 'active' => 'false'],
            ['label' => 'Claims', 'href' => '#fleet-health', 'active' => 'false'],
            ['label' => 'Charging', 'href' => '#todays-mission', 'active' => 'false'],
            ['label' => 'Airport', 'href' => '/operations/airport', 'active' => 'false'],
            ['label' => 'Airport Receipts', 'href' => '/operations/airport/reimbursements', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '#executive-kpis', 'active' => 'false'],
            ['label' => 'Administration', 'href' => '#future-integrations', 'active' => 'false'],
            ['label' => 'Settings', 'href' => '#future-integrations', 'active' => 'false'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fleetStatusCards(array $statistics, array $fleetSnapshot): array
    {
        $counts = array_column($fleetSnapshot['buckets'], 'count', 'code');

        return [
            $this->metricCard('Fleet Size', $fleetSnapshot['total'], 'Active vehicles in current snapshot', '#fleet-snapshot', 'neutral'),
            $this->metricCard('Rented', $counts['rented'] ?? 0, 'Confirmed guest possession', '#fleet-snapshot', 'info'),
            $this->metricCard('Home', $counts['home'] ?? 0, 'Current position', '#fleet-snapshot', 'success'),
            $this->metricCard('HNL', $counts['hnl'] ?? 0, 'Current position', '#fleet-snapshot', 'info'),
            $this->metricCard('Other', $counts['other'] ?? 0, 'Current position outside Home/HNL', '#fleet-snapshot', 'warning'),
            $this->metricCard('Unknown', $counts['unknown'] ?? 0, 'Current position not recorded', '#fleet-snapshot', 'warning'),
            $this->metricCard('Maintenance', $statistics['maintenance_required'], 'Service attention', '#fleet-health', 'danger'),
            $this->metricCard('Claims Open', $statistics['claim_open'], 'Follow-up queue', '#fleet-health', 'warning'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function missionCards(array $today): array
    {
        return [
            $this->taskCard('Today\'s Pickups', $today['todays_pickups'], 'pickup', 'info'),
            $this->taskCard('Today\'s Returns', $today['todays_returns'], 'return', 'info'),
            $this->taskCard('Airport Deliveries', $today['airport_deliveries'], 'airport', 'warning'),
            $this->taskCard('Airport Returns', [], 'airport_return', 'neutral'),
            $this->taskCard('Cleaning Required', $today['cleaning_tasks'], 'cleaning', 'warning'),
            $this->taskCard('Charging Required', $today['charging_tasks'], 'charging', 'neutral'),
            $this->taskCard('Registration Due', $today['registration_renewals'], 'registration', 'danger'),
            $this->taskCard('Insurance Due', $today['insurance_renewals'], 'insurance', 'danger'),
            $this->taskCard('Loan Payments Due', $today['loan_payments'], 'loan', 'warning'),
            $this->taskCard('Claims Requiring Follow-up', $today['claims'], 'claim', 'danger'),
        ];
    }

    private function missionClear(array $today): bool
    {
        foreach ($today as $tasks) {
            if (is_array($tasks) && count($tasks) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function vehicleCards(array $vehicles, array $health): array
    {
        $issues = $this->issuesByVehicle($health);

        return array_map(function (array $vehicle) use ($issues): array {
            $vehicleIssues = $issues[(int) $vehicle['fleet_vehicle_id']] ?? [];

            return array_merge($vehicle, [
                'segment' => (bool) ($vehicle['is_premium'] ?? false) ? 'Premium' : 'Base',
                'segment_tone' => (bool) ($vehicle['is_premium'] ?? false) ? 'info' : 'neutral',
                'model_label' => ($vehicle['model'] ?? '') === '' ? 'Model pending' : (string) $vehicle['model'],
                'status_label' => ucwords(str_replace('_', ' ', (string) $vehicle['status'])),
                'next_reservation_label' => $vehicle['next_reservation'] === null ? 'Open' : (string) $vehicle['next_reservation']['starts_at'],
                'current_battery_label' => $vehicle['current_battery'] === null ? 'Future' : (string) $vehicle['current_battery'],
                'current_location_label' => $vehicle['current_location'] === null ? 'Future' : (string) $vehicle['current_location'],
                'current_odometer_label' => $vehicle['current_odometer'] === null ? 'Future' : (string) $vehicle['current_odometer'],
                'priority' => $this->vehiclePriority((string) $vehicle['status'], $vehicleIssues),
                'issues' => $vehicleIssues,
            ]);
        }, $vehicles);
    }

    /** @return array<string, array<string, mixed>> */
    private function timelineCards(array $today, DateTimeImmutable $timelineStart, DateTimeImmutable $timelineEnd): array
    {
        $tomorrowStart = $timelineStart->modify('+1 day');
        $dayAfterTomorrow = $timelineStart->modify('+2 days');

        return [
            'today' => $this->timelineCard('Today', $this->scheduledWithin($today, $timelineStart, $tomorrowStart)),
            'tomorrow' => $this->timelineCard('Tomorrow', $this->scheduledWithin($this->availability()->timeline($tomorrowStart, $dayAfterTomorrow), $tomorrowStart, $dayAfterTomorrow)),
            'next_7_days' => $this->timelineCard('Next 7 Days', $this->scheduledWithin($this->availability()->timeline($dayAfterTomorrow, $timelineEnd), $dayAfterTomorrow, $timelineEnd)),
        ];
    }

    private function timelineCard(string $label, array $items): array
    {
        return [
            'label' => $label,
            'count' => count($items),
            'items' => array_map(fn (array $item): array => array_merge($item, [
                'type_label' => ucwords(str_replace('_', ' ', (string) ($item['type'] ?? 'scheduled'))),
                'title_label' => $this->timelineTitle($item),
                'starts_at_label' => $this->friendlyDateTime($item['starts_at'] ?? null),
            ]), array_slice($items, 0, 8)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function financialSnapshot(array $financialSummary): array
    {
        return [
            $this->financialCard('Realized Operating Revenue', $this->money((float) $financialSummary['realized_operating_revenue']), 'Signed Turo operating-revenue postings'),
            $this->financialCard('Realized Recoveries', $this->money((float) $financialSummary['realized_recoveries']), 'Validated posted recovery transactions'),
            $this->financialCard('Recorded Operating Costs', $this->money((float) $financialSummary['recorded_operating_costs']), 'Recorded/incurred operational costs; not proof of bank settlement'),
            $this->financialCard('Net Realized Operating Result', $this->money((float) $financialSummary['net_realized_operating_result']), 'Realized revenue plus recoveries minus recorded operating costs'),
            $this->financialCard('Forecast Host Payout', $this->money((float) $financialSummary['forecast_host_payout']), 'Booked future payout; excluded from realized result'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function healthAlerts(array $health): array
    {
        return array_values(array_filter([
            $this->alertCard('Maintenance overdue', $health['vehicles_due_for_maintenance'], 'danger'),
            $this->alertCard('Registration expires soon', $health['registration_expiring'], 'warning'),
            $this->alertCard('Insurance renewal due', $health['insurance_expiring'], 'warning'),
            $this->alertCard('Claim waiting on follow-up', $health['claims_requiring_follow_up'], 'danger'),
            $this->alertCard('Vehicle missing photos', $health['missing_photos'], 'neutral'),
            $this->alertCard('Vehicle missing Turo listing', $health['missing_turo_listing_data'], 'neutral'),
            $this->alertCard('Missing documentation', $health['missing_documents'], 'neutral'),
            $this->alertCard('Battery below threshold', $health['vehicles_below_battery_threshold'], 'neutral'),
        ], static fn (array $alert): bool => $alert['count'] > 0));
    }

    /** @return array<int, array<string, mixed>> */
    private function executiveKpis(array $tripAnalytics): array
    {
        return [
            $this->metricCard('Average Trip Length', number_format((float) $tripAnalytics['average_trip_length'], 1) . ' days', 'Year to date', '#fleet-timeline', 'neutral'),
            $this->metricCard('Year-to-Date + 7-Day Utilization', $this->percent((float) $tripAnalytics['utilization']), 'Occupied vehicle-days through the operational horizon', '#fleet-timeline', 'neutral'),
        ];
    }

    /** @return array<string, mixed> */
    private function activityPanel(array $today, array $tomorrow, array $health, array $command, array $fleetSnapshot, array $queueView): array
    {
        return [
            'fleet_snapshot' => $fleetSnapshot,
            'today_count' => $this->taskCount($today),
            'tomorrow_count' => $this->taskCount($tomorrow),
            'urgent_count' => $this->taskCount($command['urgent_items']),
            'queue_scopes' => $queueView['scopes'],
            'weather_alerts' => $command['weather_alerts'],
            'traffic_alerts' => $command['traffic_alerts'],
            'battery_alerts' => $health['vehicles_below_battery_threshold'],
            'weather_status' => $command['weather_alerts'] === [] ? 'Reserved' : 'Active',
            'traffic_status' => $command['traffic_alerts'] === [] ? 'Reserved' : 'Active',
            'battery_status' => $health['vehicles_below_battery_threshold'] === [] ? 'Reserved' : 'Active',
        ];
    }

    /** @return array<string, mixed> */
    private function queueView(?string $activeScope, array $today, array $tomorrow, array $urgent, array $defaultActions): array
    {
        $activeScope = in_array($activeScope, ['today', 'tomorrow', 'urgent'], true) ? $activeScope : null;
        $scopes = [
            ['code' => 'all', 'label' => 'All', 'count' => null, 'href' => '/#operational-queue', 'active' => $activeScope === null, 'actionable' => true],
            ['code' => 'today', 'label' => 'Today', 'count' => $this->taskCount($today), 'href' => '/?queue=today#operational-queue', 'active' => $activeScope === 'today'],
            ['code' => 'tomorrow', 'label' => 'Tomorrow', 'count' => $this->taskCount($tomorrow), 'href' => '/?queue=tomorrow#operational-queue', 'active' => $activeScope === 'tomorrow'],
            ['code' => 'urgent', 'label' => 'Urgent', 'count' => $this->taskCount($urgent), 'href' => '/?queue=urgent#operational-queue', 'active' => $activeScope === 'urgent'],
        ];
        $scopes = array_map(static fn (array $scope): array => array_merge($scope, [
            'actionable' => ($scope['count'] ?? null) === null || (int) $scope['count'] > 0,
        ]), $scopes);

        $items = match ($activeScope) {
            'today' => $this->timeScopedActions($today, 'today'),
            'tomorrow' => $this->timeScopedActions($tomorrow, 'tomorrow'),
            'urgent' => $this->urgentActions($urgent),
            default => $defaultActions,
        };

        return [
            'active_scope' => $activeScope,
            'label' => $activeScope === null ? 'All operational work' : ucfirst($activeScope) . ' work',
            'items' => $items,
            'scopes' => $scopes,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function timeScopedActions(array $tasks, string $scope): array
    {
        $dayLabel = $scope === 'tomorrow' ? 'Tomorrow\'s' : 'Today\'s';
        $definitions = [
            'todays_pickups' => [$dayLabel . ' Pickups', $scope === 'today' ? '/?queue=today&movement=pickup#movement-board' : '/?queue=tomorrow#fleet-timeline'],
            'todays_returns' => [$dayLabel . ' Returns', $scope === 'today' ? '/?queue=today&movement=return#movement-board' : '/?queue=tomorrow#fleet-timeline'],
            'cleaning_tasks' => ['Cleaning Tasks', '/#todays-mission'],
            'charging_tasks' => ['Charging Tasks', '/#todays-mission'],
            'airport_deliveries' => [$dayLabel . ' Airport Deliveries', '/operations/airport'],
            'maintenance_tasks' => ['Maintenance Tasks', '/#fleet-health'],
            'registration_renewals' => ['Registration Renewals', '/#fleet-health'],
            'insurance_renewals' => ['Insurance Renewals', '/#fleet-health'],
            'loan_payments' => ['Loan Payments', '/#financial-snapshot'],
            'claims' => ['Claims Follow-up', '/#fleet-health'],
        ];

        return $this->scopedActions($tasks, $definitions);
    }

    /** @return list<array<string, mixed>> */
    private function urgentActions(array $tasks): array
    {
        return $this->scopedActions($tasks, [
            'claims' => ['Claims Follow-up', '/#fleet-health'],
            'maintenance_tasks' => ['Maintenance Attention', '/#fleet-health'],
            'registration_renewals' => ['Registration Renewals', '/#fleet-health'],
            'insurance_renewals' => ['Insurance Renewals', '/#fleet-health'],
            'battery_alerts' => ['Battery Attention', '/#fleet-health'],
        ]);
    }

    /** @param array<string, array{string, string}> $definitions @return list<array<string, mixed>> */
    private function scopedActions(array $tasks, array $definitions): array
    {
        $actions = [];
        foreach ($definitions as $code => [$label, $href]) {
            $count = count(is_array($tasks[$code] ?? null) ? $tasks[$code] : []);
            if ($count === 0) {
                continue;
            }
            $actions[] = ['code' => $code, 'label' => $label, 'count' => $count, 'detail' => $count . ' item' . ($count === 1 ? '' : 's'), 'href' => $href, 'actionable' => true];
        }

        return $actions;
    }

    private function taskCount(array $tasks): int
    {
        return array_sum(array_map(static fn (mixed $items): int => is_array($items) ? count($items) : 0, $tasks));
    }

    /** @return array<int, array<string, string>> */
    private function futureIntegrations(): array
    {
        return [
            ['name' => 'Tesla API', 'status' => 'Reserved'],
            ['name' => 'Weather', 'status' => 'Reserved'],
            ['name' => 'Traffic', 'status' => 'Reserved'],
            ['name' => 'Google Maps', 'status' => 'Reserved'],
            ['name' => 'Airport flight tracking', 'status' => 'Reserved'],
            ['name' => 'Push notifications', 'status' => 'Reserved'],
            ['name' => 'SMS', 'status' => 'Reserved'],
            ['name' => 'Email', 'status' => 'Reserved'],
            ['name' => 'Calendar sync', 'status' => 'Reserved'],
        ];
    }

    private function metricCard(string $label, mixed $value, string $detail, string $href, string $tone): array
    {
        return compact('label', 'value', 'detail', 'href', 'tone');
    }

    private function taskCard(string $label, array $items, string $type, string $tone): array
    {
        return [
            'label' => $label,
            'items' => $items,
            'count' => count($items),
            'preview_items' => array_map(fn (array $item): string => $this->taskPreview($item, $type), array_slice($items, 0, 3)),
            'empty_text' => 'No action due.',
            'type' => $type,
            'tone' => count($items) > 0 ? $tone : 'neutral',
        ];
    }

    private function taskPreview(array $item, string $type): string
    {
        $vehicle = (string) ($item['display_name'] ?? $item['fleet_code'] ?? $item['guest_name'] ?? $item['source_reservation_id'] ?? 'Task ready');

        return $type === 'loan'
            ? $vehicle . ' — ' . (string) ($item['due_label'] ?? 'Due date unavailable')
            : $vehicle;
    }

    private function timelineTitle(array $item): string
    {
        if (($item['type'] ?? '') !== 'reservation') {
            return ucwords(str_replace('_', ' ', (string) ($item['type'] ?? 'scheduled')));
        }

        $guestName = trim((string) ($item['guest_name'] ?? $item['reservation']['guest_name'] ?? ''));
        if ($guestName === '' || in_array(strtolower($guestName), ['unknown guest', 'guest not captured'], true)) {
            return 'Reservation';
        }

        $nameParts = preg_split('/\s+/u', $guestName);

        return $nameParts[0] ?? 'Reservation';
    }

    private function friendlyDateTime(mixed $value): string
    {
        $dateTime = trim((string) $value);
        if ($dateTime === '') {
            return 'Pending time';
        }

        try {
            $timezone = $this->businessTimezone();

            return (new DateTimeImmutable($dateTime, $timezone))->setTimezone($timezone)->format('M j · g:i A');
        } catch (Throwable) {
            return 'Pending time';
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function scheduledWithin(array $items, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        return array_values(array_filter($items, function (array $item) use ($startsAt, $endsAt): bool {
            $scheduledAt = trim((string) ($item['starts_at'] ?? ''));
            if ($scheduledAt === '') {
                return false;
            }

            try {
                $localScheduledAt = (new DateTimeImmutable($scheduledAt, $this->businessTimezone()))
                    ->setTimezone($this->businessTimezone());

                return $localScheduledAt >= $startsAt && $localScheduledAt < $endsAt;
            } catch (Throwable) {
                return false;
            }
        }));
    }

    private function businessTimezone(): DateTimeZone
    {
        return new DateTimeZone((new App())->appTimezone);
    }

    private function financialCard(string $label, string $value, string $detail): array
    {
        return compact('label', 'value', 'detail');
    }

    private function alertCard(string $label, array $items, string $tone): array
    {
        return [
            'label' => $label,
            'items' => $items,
            'count' => count($items),
            'message' => count($items) . ' ' . (count($items) === 1 ? 'item requires' : 'items require') . ' attention.',
            'tone' => $tone,
        ];
    }

    private function vehiclePriority(string $status, array $issues): string
    {
        if (count($issues) > 0 || in_array($status, ['maintenance', 'out_of_service'], true)) {
            return 'danger';
        }

        if (in_array($status, ['reserved', 'in_progress'], true)) {
            return 'info';
        }

        return 'success';
    }

    /** @return array<int, array<int, string>> */
    private function issuesByVehicle(array $health): array
    {
        $issues = [];
        $sources = [
            'Maintenance due' => $health['vehicles_due_for_maintenance'],
            'Registration due' => $health['registration_expiring'],
            'Insurance due' => $health['insurance_expiring'],
            'Open claim' => $health['claims_requiring_follow_up'],
            'Missing photos' => $health['missing_photos'],
            'Missing documents' => $health['missing_documents'],
            'Missing listing' => $health['missing_turo_listing_data'],
        ];

        foreach ($sources as $label => $rows) {
            foreach ($rows as $row) {
                $fleetVehicleId = (int) ($row['fleet_vehicle_id'] ?? $row['id'] ?? 0);

                if ($fleetVehicleId > 0) {
                    $issues[$fleetVehicleId][] = $label;
                }
            }
        }

        return $issues;
    }

    private function money(?float $amount): string
    {
        if ($amount === null) {
            return 'Pending';
        }

        return '$' . number_format($amount, 2);
    }

    private function percent(float $value): string
    {
        return number_format($value * 100, 1) . '%';
    }

    private function command(): FleetCommandService
    {
        return $this->commandService ?? service('fleetCommandService');
    }

    private function statistics(): FleetStatisticsService
    {
        return $this->statisticsService ?? service('fleetStatisticsService');
    }

    private function health(): FleetHealthService
    {
        return $this->healthService ?? service('fleetHealthService');
    }

    private function tasks(): TaskService
    {
        return $this->taskService ?? service('taskService');
    }

    private function availability(): VehicleAvailabilityService
    {
        return $this->availabilityService ?? service('vehicleAvailabilityService');
    }

    private function tripAnalytics(): TripAnalyticsService
    {
        return $this->tripAnalyticsService ?? service('tripAnalyticsService');
    }

    private function decisionSupport(): DecisionSupportDashboardService
    {
        return $this->decisionSupportService ?? service('decisionSupportDashboardService');
    }

    private function importIssues(): TuroImportIssueService
    {
        return $this->importIssueService ?? service('turoImportIssueService');
    }

    private function vehicleMappings(): TuroVehicleMappingService
    {
        return $this->vehicleMappingService ?? service('turoVehicleMappingService');
    }

    private function tripReconciliation(): TuroTripReconciliationService
    {
        return $this->tripReconciliationService ?? service('turoTripReconciliationService');
    }

    private function dailyOperations(): DailyOperationsDashboardService
    {
        return $this->dailyOperationsService ?? service('dailyOperationsDashboardService');
    }
}
