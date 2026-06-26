<?php

namespace App\Services\Fleet;

use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\VehicleAvailabilityService;
use DateTimeImmutable;

class FleetCommandService
{
    public function __construct(
        private readonly ?FleetStatisticsService $statisticsService = null,
        private readonly ?FleetHealthService $healthService = null,
        private readonly ?VehicleAvailabilityService $availabilityService = null,
        private readonly ?TaskService $taskService = null,
    ) {
    }

    /** Returns the mission-control operational snapshot for FleetOS. */
    public function snapshot(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $fleetStatus = $this->statistics()->summary($asOf);
        $health = $this->health()->summary($asOf);
        $today = $this->tasks()->today($asOf);

        return [
            'as_of' => $asOf->format('Y-m-d H:i:s'),
            'fleet_status' => [
                'available' => $fleetStatus['available_vehicles'],
                'reserved' => $fleetStatus['reserved_vehicles'],
                'in_progress' => $fleetStatus['in_progress_vehicles'],
                'cleaning' => count($health['vehicles_needing_cleaning']),
                'maintenance' => $fleetStatus['maintenance_required'],
                'out_of_service' => $fleetStatus['vehicles_out_of_service'],
            ],
            'vehicle_statuses' => $this->availability()->vehicleStatus($asOf),
            'todays_timeline' => $this->availability()->timeline($asOf->setTime(0, 0), $asOf->modify('+1 day')->setTime(0, 0)),
            'todays_pickups' => $today['todays_pickups'],
            'todays_returns' => $today['todays_returns'],
            'airport_deliveries' => $today['airport_deliveries'],
            'urgent_items' => $this->tasks()->highPriority($asOf),
            'battery_alerts' => $health['vehicles_below_battery_threshold'],
            'maintenance' => $health['vehicles_due_for_maintenance'],
            'registrations' => $health['registration_expiring'],
            'claims' => $health['claims_requiring_follow_up'],
            'weather_alerts' => [],
            'traffic_alerts' => [],
        ];
    }

    /** Returns the fleet status portion of the command snapshot. */
    public function fleetStatus(?DateTimeImmutable $asOf = null): array
    {
        return $this->snapshot($asOf)['fleet_status'];
    }

    /** Returns today's operational timeline for command-center consumers. */
    public function todaysTimeline(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->availability()->timeline($asOf->setTime(0, 0), $asOf->modify('+1 day')->setTime(0, 0));
    }

    /** Returns urgent operational items for command-center consumers. */
    public function urgentItems(?DateTimeImmutable $asOf = null): array
    {
        return $this->snapshot($asOf)['urgent_items'];
    }

    private function statistics(): FleetStatisticsService
    {
        return $this->statisticsService ?? service('fleetStatisticsService');
    }

    private function health(): FleetHealthService
    {
        return $this->healthService ?? service('fleetHealthService');
    }

    private function availability(): VehicleAvailabilityService
    {
        return $this->availabilityService ?? service('vehicleAvailabilityService');
    }

    private function tasks(): TaskService
    {
        return $this->taskService ?? service('taskService');
    }
}
