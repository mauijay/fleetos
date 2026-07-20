<?php

namespace App\Services\Fleet;

use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use DateTimeImmutable;

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
    public function forToday(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $command = $this->command()->snapshot($asOf);
        $statistics = $this->statistics()->summary($asOf);
        $health = $this->health()->summary($asOf);
        $today = $this->tasks()->today($asOf);
        $tomorrow = $this->tasks()->tomorrow($asOf);
        $currentMonth = $statistics['current_month'];
        $timelineStart = $asOf->setTime(0, 0);
        $timelineEnd = $timelineStart->modify('+7 days');
        $tripAnalytics = $this->tripAnalytics()->summary(new DateTimeImmutable($asOf->format('Y-01-01 00:00:00')), $timelineEnd);
        $vehiclePerformance = $this->statistics()->vehiclePerformance($asOf);
        $decisionSupport = $this->decisionSupport()->recommendations($asOf);
        $importIssues = $this->importIssues()->attentionSummary();
        $vehicleMappings = $this->vehicleMappings()->attentionSummary();
        $tripReconciliation = $this->tripReconciliation()->attentionSummary();
        $dailyOperations = $this->dailyOperations()->forToday($asOf);

        return [
            'page_title' => 'Fleet Command Center',
            'as_of' => $asOf->format('M j, Y g:i A'),
            'navigation' => $this->navigation(),
            'fleet_status' => $this->fleetStatusCards($statistics, $command),
            'mission' => $this->missionCards($today),
            'mission_clear' => $this->missionClear($today),
            'import_issues' => $importIssues,
            'vehicle_mappings' => $vehicleMappings,
            'trip_reconciliation' => $tripReconciliation,
            'daily_operations' => $dailyOperations,
            'decision_support' => $decisionSupport,
            'vehicles' => $this->vehicleCards($command['vehicle_statuses'], $health),
            'timeline' => $this->timelineCards($command['todays_timeline'], $timelineStart, $timelineEnd),
            'financial' => $this->financialSnapshot($currentMonth, $statistics),
            'health_alerts' => $this->healthAlerts($health),
            'executive_kpis' => $this->executiveKpis($statistics, $tripAnalytics, $vehiclePerformance),
            'activity' => $this->activityPanel($today, $tomorrow, $health, $command),
            'future_integrations' => $this->futureIntegrations(),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'true'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Decision Support', 'href' => '#decision-support', 'active' => 'false'],
            ['label' => 'Fleet', 'href' => '#fleet-activity', 'active' => 'false'],
            ['label' => 'Reservations', 'href' => '#fleet-timeline', 'active' => 'false'],
            ['label' => 'Trips', 'href' => '#executive-kpis', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '#financial-snapshot', 'active' => 'false'],
            ['label' => 'Expenses', 'href' => '#financial-snapshot', 'active' => 'false'],
            ['label' => 'Maintenance', 'href' => '#fleet-health', 'active' => 'false'],
            ['label' => 'Claims', 'href' => '#fleet-health', 'active' => 'false'],
            ['label' => 'Charging', 'href' => '#todays-mission', 'active' => 'false'],
            ['label' => 'Airport', 'href' => '#fleet-timeline', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '#executive-kpis', 'active' => 'false'],
            ['label' => 'Administration', 'href' => '#future-integrations', 'active' => 'false'],
            ['label' => 'Settings', 'href' => '#future-integrations', 'active' => 'false'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fleetStatusCards(array $statistics, array $command): array
    {
        return [
            $this->metricCard('Fleet Size', $statistics['fleet_size'], 'Total active fleet', '#fleet-activity', 'neutral'),
            $this->metricCard('Available', $command['fleet_status']['available'], 'Ready for booking', '#fleet-activity', 'success'),
            $this->metricCard('Reserved', $command['fleet_status']['reserved'], 'Currently reserved', '#fleet-timeline', 'info'),
            $this->metricCard('In Progress', $command['fleet_status']['in_progress'], 'Trips underway', '#fleet-timeline', 'info'),
            $this->metricCard('Needs Cleaning', $command['fleet_status']['cleaning'], 'Turnaround required', '#todays-mission', 'warning'),
            $this->metricCard('Maintenance', $command['fleet_status']['maintenance'], 'Service attention', '#fleet-health', 'danger'),
            $this->metricCard('Out of Service', $command['fleet_status']['out_of_service'], 'Unavailable vehicles', '#fleet-health', 'danger'),
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
        return [
            'today' => $this->timelineCard('Today', $today),
            'tomorrow' => $this->timelineCard('Tomorrow', $this->availability()->timeline($timelineStart->modify('+1 day'), $timelineStart->modify('+2 days'))),
            'next_7_days' => $this->timelineCard('Next 7 Days', $this->availability()->timeline($timelineStart, $timelineEnd)),
        ];
    }

    private function timelineCard(string $label, array $items): array
    {
        return [
            'label' => $label,
            'count' => count($items),
            'items' => array_map(static fn (array $item): array => array_merge($item, [
                'type_label' => ucwords(str_replace('_', ' ', (string) ($item['type'] ?? 'scheduled'))),
                'starts_at_label' => (string) ($item['starts_at'] ?? 'Pending time'),
            ]), array_slice($items, 0, 8)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function financialSnapshot(array $currentMonth, array $statistics): array
    {
        $premiumBase = $statistics['premium_vs_base'];

        return [
            $this->financialCard('Current Month Revenue', $this->money((float) $currentMonth['completed_revenue']), 'Actual host payout'),
            $this->financialCard('Forecast Revenue', $this->money((float) $currentMonth['forecast_revenue']), 'Booked future payout'),
            $this->financialCard('Cash Flow', $this->money((float) $currentMonth['cash_flow']), 'Revenue less known costs'),
            $this->financialCard('Operating Profit', $this->money((float) $currentMonth['operating_profit']), 'Completed revenue less costs'),
            $this->financialCard('Fleet Utilization', $this->percent((float) $currentMonth['fleet_utilization']), 'Current month'),
            $this->financialCard('ADR', $this->money((float) $currentMonth['average_daily_rate']), 'Average daily rate'),
            $this->financialCard('RevPAD', $this->money((float) $currentMonth['revenue_per_available_day']), 'Revenue per available day'),
            $this->financialCard('Premium Revenue', $this->money($this->segmentRevenue($premiumBase, 'premium')), 'Premium fleet segment'),
            $this->financialCard('Base Revenue', $this->money($this->segmentRevenue($premiumBase, 'base')), 'Base fleet segment'),
            $this->financialCard('Fleet Value', $this->money((float) $statistics['fleet_value']['fleet_value']), 'Tracked startup capital'),
            $this->financialCard('Loan Balance', $this->money((float) $statistics['fleet_value']['loan_balance']), 'Current principal'),
            $this->financialCard('Fleet Equity', $this->money((float) $statistics['fleet_value']['fleet_equity']), 'Value less loans'),
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
    private function executiveKpis(array $statistics, array $tripAnalytics, array $vehiclePerformance): array
    {
        return [
            $this->metricCard('Fleet ROI', $this->averageRoi($statistics['vehicle_roi']), 'Known vehicle capital only', '#financial-snapshot', 'neutral'),
            $this->metricCard('Lifetime Revenue', $this->money((float) $statistics['lifetime_revenue']), 'All completed history', '#financial-snapshot', 'success'),
            $this->metricCard('Lifetime Profit', $this->money((float) $statistics['lifetime_profit']), 'Revenue less startup capital', '#financial-snapshot', 'success'),
            $this->metricCard('Average Trip Length', number_format((float) $tripAnalytics['average_trip_length'], 1) . ' days', 'Year to date', '#fleet-timeline', 'neutral'),
            $this->metricCard('Average Occupancy', $this->percent((float) $tripAnalytics['utilization']), 'Billable trip utilization', '#fleet-timeline', 'neutral'),
            $this->metricCard('Revenue per Vehicle', $this->money((float) $statistics['current_month']['revenue_per_vehicle']), 'Current month', '#financial-snapshot', 'success'),
            $this->metricCard('Revenue per Available Day', $this->money((float) $statistics['current_month']['revenue_per_available_day']), 'Current month', '#financial-snapshot', 'success'),
            $this->metricCard('Highest Performing Vehicle', $this->vehicleLabel($vehiclePerformance[0] ?? null), 'By revenue', '#fleet-activity', 'success'),
            $this->metricCard('Lowest Performing Vehicle', $this->vehicleLabel($vehiclePerformance[count($vehiclePerformance) - 1] ?? null), 'By revenue', '#fleet-activity', 'warning'),
            $this->metricCard('Most Utilized Vehicle', $this->vehicleLabel($this->mostUtilizedVehicle($vehiclePerformance)), 'By utilization', '#fleet-activity', 'info'),
        ];
    }

    /** @return array<string, mixed> */
    private function activityPanel(array $today, array $tomorrow, array $health, array $command): array
    {
        return [
            'today_count' => count(array_merge(...array_values(array_filter($today, 'is_array')))),
            'tomorrow_count' => count(array_merge(...array_values(array_filter($tomorrow, 'is_array')))),
            'urgent_count' => count(array_merge(...array_values(array_filter($command['urgent_items'], 'is_array')))),
            'weather_alerts' => $command['weather_alerts'],
            'traffic_alerts' => $command['traffic_alerts'],
            'battery_alerts' => $health['vehicles_below_battery_threshold'],
            'weather_status' => $command['weather_alerts'] === [] ? 'Reserved' : 'Active',
            'traffic_status' => $command['traffic_alerts'] === [] ? 'Reserved' : 'Active',
            'battery_status' => $health['vehicles_below_battery_threshold'] === [] ? 'Reserved' : 'Active',
        ];
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
            'preview_items' => array_map(static fn (array $item): string => (string) ($item['fleet_code'] ?? $item['display_name'] ?? $item['guest_name'] ?? $item['source_reservation_id'] ?? 'Task ready'), array_slice($items, 0, 3)),
            'empty_text' => 'No action due.',
            'type' => $type,
            'tone' => count($items) > 0 ? $tone : 'neutral',
        ];
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

    private function segmentRevenue(array $segments, string $group): float
    {
        foreach ($segments as $segment) {
            if (($segment['group'] ?? '') === $group) {
                return (float) ($segment['completed_revenue'] ?? 0);
            }
        }

        return 0.0;
    }

    private function averageRoi(array $vehicles): string
    {
        $known = array_values(array_filter($vehicles, static fn (array $vehicle): bool => $vehicle['roi'] !== null));

        if ($known === []) {
            return 'Pending';
        }

        $total = array_reduce($known, static fn (float $carry, array $vehicle): float => $carry + (float) $vehicle['roi'], 0.0);

        return $this->percent($total / count($known));
    }

    private function mostUtilizedVehicle(array $vehiclePerformance): ?array
    {
        usort($vehiclePerformance, static fn (array $left, array $right): int => (float) ($right['utilization'] ?? 0) <=> (float) ($left['utilization'] ?? 0));

        return $vehiclePerformance[0] ?? null;
    }

    private function vehicleLabel(?array $vehicle): string
    {
        if ($vehicle === null) {
            return 'Pending';
        }

        return (string) ($vehicle['fleet_code'] ?? $vehicle['display_name'] ?? 'Pending');
    }

    private function money(float $amount): string
    {
        return '$' . number_format($amount, 0);
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
