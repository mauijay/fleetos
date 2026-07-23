<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateTimeImmutable;

class TripAnalyticsService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?FleetCapacityService $capacityService = null,
    ) {
    }

    /** Returns trip analytics for the requested date range. */
    public function summary(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        $rows = $this->repo()->tripAnalytics($startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s'));
        $totals = $this->totals($rows, $startsAt, $endsAt);

        return array_merge($totals, [
            'by_vehicle' => $rows,
            'repeat_guests' => $this->repeatGuests($startsAt, $endsAt),
            'average_review' => null,
            'late_returns' => [],
            'battery_violations' => [],
        ]);
    }

    /** Returns total trip count for the requested date range. */
    public function tripCount(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        return (int) $this->summary($startsAt, $endsAt)['trip_count'];
    }

    /** Returns total trip days for the requested date range. */
    public function tripDays(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['trip_days'];
    }

    /** Returns utilization for the requested date range. */
    public function utilization(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['utilization'];
    }

    /** Returns average trip length for the requested date range. */
    public function averageTripLength(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['average_trip_length'];
    }

    /** Returns longest trip length for the requested date range. */
    public function longestTrip(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['longest_trip'];
    }

    /** Returns shortest trip length for the requested date range. */
    public function shortestTrip(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['shortest_trip'];
    }

    /** Returns guests with more than one reservation in the requested date range. */
    public function repeatGuests(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        return $this->repo()->repeatedGuests($startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s'));
    }

    /** Returns average review rating when review data is available. */
    public function averageReview(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): ?float
    {
        return null;
    }

    /** Returns cancellation rate for the requested date range. */
    public function cancellationRate(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): float
    {
        return (float) $this->summary($startsAt, $endsAt)['cancellation_rate'];
    }

    /** Returns late return events when return telemetry is available. */
    public function lateReturns(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        return [];
    }

    /** Returns airport delivery count for the requested date range. */
    public function airportDeliveries(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        return (int) $this->summary($startsAt, $endsAt)['airport_deliveries'];
    }

    /** Returns home delivery count for the requested date range. */
    public function homeDeliveries(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        return (int) $this->summary($startsAt, $endsAt)['home_deliveries'];
    }

    /** Returns charging event count for the requested date range. */
    public function chargingEvents(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        return (int) $this->summary($startsAt, $endsAt)['charging_events'];
    }

    /** Returns battery violations when telemetry rules are available. */
    public function batteryViolations(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        return [];
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    private function capacity(): FleetCapacityService
    {
        return $this->capacityService ?? new FleetCapacityService($this->repo());
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function totals(array $rows, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        $totals = [
            'trip_count' => 0,
            'trip_days' => 0.0,
            'billable_days' => 0.0,
            'cancelled_trips' => 0,
            'airport_deliveries' => 0,
            'home_deliveries' => 0,
            'charging_events' => 0,
            'longest_trip' => 0.0,
            'shortest_trip' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['trip_count'] += (int) ($row['trip_count'] ?? 0);
            $totals['trip_days'] += (float) ($row['trip_days'] ?? 0);
            $totals['billable_days'] += (float) ($row['billable_days'] ?? 0);
            $totals['cancelled_trips'] += (int) ($row['cancelled_trips'] ?? 0);
            $totals['airport_deliveries'] += (int) ($row['airport_deliveries'] ?? 0);
            $totals['home_deliveries'] += (int) ($row['home_deliveries'] ?? 0);
            $totals['charging_events'] += (int) ($row['charging_events'] ?? 0);
            $totals['longest_trip'] = max($totals['longest_trip'], (float) ($row['longest_trip'] ?? 0));
            $shortest = (float) ($row['shortest_trip'] ?? 0);
            $totals['shortest_trip'] = $totals['shortest_trip'] === 0.0 ? $shortest : min($totals['shortest_trip'], $shortest);
        }

        $capacity = $this->capacity()->utilizationForRange($startsAt, $endsAt);
        $occupiedVehicleDays = (float) $capacity['occupied_vehicle_days'];

        $totals['average_trip_length'] = $totals['trip_count'] === 0 ? 0.0 : round($totals['trip_days'] / $totals['trip_count'], 3);
        $totals['cancellation_rate'] = $totals['trip_count'] === 0 ? 0.0 : round($totals['cancelled_trips'] / $totals['trip_count'], 4);
        $totals['utilization'] = (float) $capacity['utilization'];
        $totals['occupied_vehicle_days'] = $occupiedVehicleDays;
        $totals['available_vehicle_days'] = (float) $capacity['available_vehicle_days'];

        return $totals;
    }
}
