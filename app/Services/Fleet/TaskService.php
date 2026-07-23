<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateTimeImmutable;

class TaskService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?FleetHealthService $healthService = null,
    ) {
    }

    /** Returns all operational tasks for today. */
    public function today(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->tasksForDay($asOf);
    }

    /** Returns all operational tasks for tomorrow. */
    public function tomorrow(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->tasksForDay($asOf->modify('+1 day'));
    }

    /** Returns tasks whose due date or scheduled time is before now. */
    public function overdue(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $today = $this->today($asOf);

        return array_filter($today, static fn (array $tasks): bool => count($tasks) > 0);
    }

    /** Returns the highest-priority operational tasks. */
    public function highPriority(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $health = $this->health()->summary($asOf);

        return [
            'claims' => $health['claims_requiring_follow_up'],
            'maintenance_tasks' => $health['vehicles_due_for_maintenance'],
            'registration_renewals' => $health['registration_expiring'],
            'insurance_renewals' => $health['insurance_expiring'],
            'battery_alerts' => $health['vehicles_below_battery_threshold'],
        ];
    }

    private function tasksForDay(DateTimeImmutable $day): array
    {
        $start = $day->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
        $reservations = $this->repo()->operationalReservationsBetween($start, $end);

        return [
            'todays_pickups' => $this->startingReservations($reservations, $start, $end),
            'todays_returns' => $this->endingReservations($reservations, $start, $end),
            'cleaning_tasks' => $this->health()->vehiclesNeedingCleaning($day),
            'charging_tasks' => [],
            'airport_deliveries' => $this->repo()->airportDeliveriesBetween($start, $end),
            'maintenance_tasks' => $this->health()->vehiclesDueForMaintenance($day, 0),
            'registration_renewals' => $this->health()->registrationExpiring($day, 0),
            'insurance_renewals' => $this->health()->insuranceExpiring($day, 0),
            'loan_payments' => $this->health()->loanPaymentDue($day),
            'claims' => $this->health()->claimsRequiringFollowUp(),
        ];
    }

    /** @param array<int, array<string, mixed>> $reservations */
    private function startingReservations(array $reservations, string $start, string $end): array
    {
        return array_values(array_filter($reservations, static fn (array $reservation): bool => ($reservation['starts_at'] ?? '') >= $start
            && ($reservation['starts_at'] ?? '') < $end
            && ! str_starts_with((string) ($reservation['status_code'] ?? ''), 'canceled')));
    }

    /** @param array<int, array<string, mixed>> $reservations */
    private function endingReservations(array $reservations, string $start, string $end): array
    {
        return array_values(array_filter($reservations, static fn (array $reservation): bool => ($reservation['ends_at'] ?? '') >= $start
            && ($reservation['ends_at'] ?? '') < $end
            && ! str_starts_with((string) ($reservation['status_code'] ?? ''), 'canceled')));
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    private function health(): FleetHealthService
    {
        return $this->healthService ?? service('fleetHealthService');
    }
}
