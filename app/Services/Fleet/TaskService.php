<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\VehicleRecoveryExceptionRepository;
use DateTimeImmutable;

class TaskService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?FleetHealthService $healthService = null,
        private readonly ?OperationalMovementWorkService $movementWorkService = null,
    ) {
    }

    /** Returns all operational tasks for today. */
    public function today(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->tasksForDay($asOf, $asOf);
    }

    /** Returns all operational tasks for tomorrow. */
    public function tomorrow(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->tasksForDay($asOf->modify('+1 day'), $asOf);
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
            'vehicle_health_reminders' => array_values(array_filter(
                $health['vehicle_health_reminders'] ?? [],
                static fn (array $reminder): bool => (bool) ($reminder['blocking'] ?? false),
            )),
        ];
    }

    private function tasksForDay(DateTimeImmutable $day, DateTimeImmutable $asOf): array
    {
        $start = $day->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
        $companyId = $this->movementWork()->singleActiveCompanyId($asOf);
        $reservations = $this->repo()->operationalReservationsBetween($start, $end, $companyId);
        $completions = $this->movementWork()->completionsForCompany($companyId, array_map('intval', array_column($reservations, 'id')), $asOf);
        $awaitingRecovery = $this->movementWork()->awaitingRecoveryForCompany($companyId, $asOf);
        $stagedTripIds = array_fill_keys(array_map('intval', array_column($awaitingRecovery, 'turo_trip_normalized_id')), true);
        $stagedVehicleIds = array_fill_keys(array_map('intval', array_column($awaitingRecovery, 'fleet_vehicle_id')), true);

        return [
            'todays_pickups' => $this->startingReservations($reservations, $start, $end, $completions),
            'todays_returns' => $this->endingReservations($reservations, $start, $end, $completions, $stagedTripIds),
            'awaiting_recovery' => $day->format('Y-m-d') === $asOf->format('Y-m-d') ? $awaitingRecovery : [],
            'recovery_exceptions' => $day->format('Y-m-d') === $asOf->format('Y-m-d')
                ? (new VehicleRecoveryExceptionRepository())->openForCompany($companyId) : [],
            'cleaning_tasks' => array_values(array_filter($this->health()->vehiclesNeedingCleaning($asOf), static fn (array $vehicle): bool => ! isset($stagedVehicleIds[(int) ($vehicle['fleet_vehicle_id'] ?? $vehicle['id'] ?? 0)]))),
            'charging_tasks' => array_values(array_filter($this->movementWork()->energyNeedsForCompany($companyId, $asOf), static fn (array $vehicle): bool => ! isset($stagedVehicleIds[(int) $vehicle['fleet_vehicle_id']]))),
            'airport_deliveries' => $this->repo()->airportDeliveriesBetween($start, $end, $companyId),
            'maintenance_tasks' => $this->health()->vehiclesDueForMaintenance($day, 0),
            'registration_renewals' => $this->health()->registrationExpiring($day, 0),
            'insurance_renewals' => $this->health()->insuranceExpiring($day, 0),
            'loan_payments' => $this->health()->loanPaymentDue($day),
            'claims' => $this->health()->claimsRequiringFollowUp(),
            'vehicle_health_reminders' => $this->vehicleHealthForDay($companyId, $day, $asOf),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vehicleHealthForDay(int $companyId, DateTimeImmutable $day, DateTimeImmutable $asOf): array
    {
        $reminders = array_values(array_filter(
            $this->health()->vehicleHealthReminders($companyId, $asOf, true),
            static fn (array $reminder): bool => ($reminder['reminder_code'] ?? null) !== 'current_odometer',
        ));
        if ($day->format('Y-m-d') === $asOf->format('Y-m-d')) {
            return array_values(array_filter(
                $reminders,
                static fn (array $reminder): bool => in_array($reminder['state'] ?? null, ['attention', 'overdue', 'due'], true)
                    || (($reminder['due_at'] ?? null) !== null
                        && substr((string) $reminder['due_at'], 0, 10) === $day->format('Y-m-d')),
            ));
        }

        return array_values(array_filter(
            $reminders,
            static fn (array $reminder): bool => ($reminder['due_at'] ?? null) !== null
                && substr((string) $reminder['due_at'], 0, 10) === $day->format('Y-m-d'),
        ));
    }

    /** @param array<int, array<string, mixed>> $reservations */
    private function startingReservations(array $reservations, string $start, string $end, array $completions): array
    {
        return array_values(array_filter($reservations, static fn (array $reservation): bool => ! isset($completions[(int) ($reservation['id'] ?? 0) . ':pickup'])
            && ($reservation['starts_at'] ?? '') >= $start
            && ($reservation['starts_at'] ?? '') < $end
            && ! str_starts_with((string) ($reservation['status_code'] ?? ''), 'canceled')));
    }

    /** @param array<int, array<string, mixed>> $reservations */
    private function endingReservations(array $reservations, string $start, string $end, array $completions, array $stagedTripIds): array
    {
        return array_values(array_filter($reservations, static fn (array $reservation): bool => ! isset($completions[(int) ($reservation['id'] ?? 0) . ':return'])
            && ! isset($stagedTripIds[(int) ($reservation['id'] ?? 0)])
            && ($reservation['ends_at'] ?? '') >= $start
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

    private function movementWork(): OperationalMovementWorkService
    {
        return $this->movementWorkService ?? new OperationalMovementWorkService();
    }
}
