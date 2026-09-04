<?php

namespace App\Services\Fleet;

use App\Repositories\TuroNormalizedTripRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

class MovementProjectionService
{
    public function __construct(
        private readonly ?TuroNormalizedTripRepository $trips = null,
        private readonly ?TripMovementChecklistService $checklists = null,
        private readonly ?AirportMovementWorkflowService $airportWorkflows = null,
        private readonly MovementProjectionEligibilityPolicy $eligibility = new MovementProjectionEligibilityPolicy(),
    ) {
    }

    /** @return array<string, int> */
    public function projectTrip(int $tripId, bool $apply = false, string $source = 'import', ?DateTimeImmutable $asOf = null): array
    {
        $summary = $this->summary();
        $trip = $this->tripRepository()->find($tripId);
        if ($trip === null) {
            $summary['errors']++;

            return $summary;
        }

        $summary['trips_examined'] = 1;
        $window = $this->eligibility->automaticWindow($asOf);
        $this->project($trip, $apply, $source, $summary, $window['start'], $window['end']);

        return $summary;
    }

    /** @return array<string, int> */
    public function projectDate(DateTimeImmutable $day, bool $apply = false, string $source = 'backfill'): array
    {
        return $this->projectRange($day->setTime(0, 0), $day->modify('+1 day')->setTime(0, 0), $apply, $source);
    }

    /** @return array<string, int> */
    public function projectRange(DateTimeImmutable $start, DateTimeImmutable $end, bool $apply = false, string $source = 'backfill'): array
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('Projection range end must be after its start.');
        }

        $summary = $this->summary();
        foreach ($this->tripRepository()->movementsBetween($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')) as $trip) {
            $summary['trips_examined']++;
            $this->project($trip, $apply, $source, $summary, $start, $end);
        }

        return $summary;
    }

    /** @param array<string, mixed> $trip @param array<string, int> $summary */
    private function project(array $trip, bool $apply, string $source, array &$summary, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $tripId = (int) ($trip['id'] ?? 0);
        if ($tripId <= 0 || (int) ($trip['fleet_vehicle_id'] ?? 0) <= 0) {
            $summary['skipped']++;

            return;
        }

        $deliveries = $this->airports()->deliveriesForTrip($tripId);
        foreach (['pickup' => 'starts_at', 'return' => 'ends_at'] as $movementType => $field) {
            $scheduledAt = trim((string) ($trip[$field] ?? ''));
            if ($scheduledAt === '') {
                $summary['skipped']++;
                continue;
            }
            if (! $this->isEligible($trip, $scheduledAt, $start, $end, $summary)) {
                continue;
            }

            if ($this->checklistService()->existingForMovement($tripId, $movementType, $scheduledAt) !== null) {
                $summary['already_present']++;
                continue;
            }

            if (! $apply) {
                $summary['would_project_checklists']++;
                continue;
            }

            try {
                $checklist = $this->checklistService()->ensureForMovement($trip, $movementType, $deliveries !== [], $source);
                ($checklist['exists'] ?? false) ? $summary['checklists_projected']++ : $summary['errors']++;
            } catch (Throwable) {
                $this->recordFailure(
                    $this->checklistService()->existingForMovement($tripId, $movementType, $scheduledAt) !== null,
                    $summary,
                );
            }
        }

        foreach ($deliveries as $delivery) {
            $pickupAt = (string) ($delivery['scheduled_at'] ?? $trip['starts_at'] ?? '');
            if ($this->isEligible($trip, $pickupAt, $start, $end, $summary)) {
                $this->projectAirportWorkflow($delivery, 'pickup', $pickupAt, $apply, $summary);
            }
            if (trim((string) ($trip['ends_at'] ?? '')) !== '') {
                $returnAt = (string) $trip['ends_at'];
                if ($this->isEligible($trip, $returnAt, $start, $end, $summary)) {
                    $this->projectAirportWorkflow($delivery, 'return', $returnAt, $apply, $summary);
                }
            }
        }
    }

    /** @param array<string, mixed> $trip @param array<string, int> $summary */
    private function isEligible(array $trip, string $scheduledAt, DateTimeImmutable $start, DateTimeImmutable $end, array &$summary): bool
    {
        $reason = $this->eligibility->evaluate($trip, $scheduledAt, $start, $end);
        if ($reason === 'eligible') {
            return true;
        }

        $summary['skipped']++;
        $summary['skipped_' . $reason]++;

        return false;
    }

    /** @param array<string, mixed> $delivery @param array<string, int> $summary */
    private function projectAirportWorkflow(array $delivery, string $movementType, string $scheduledAt, bool $apply, array &$summary): void
    {
        $tripId = (int) ($delivery['turo_trip_normalized_id'] ?? 0);
        if ($tripId <= 0 || trim($scheduledAt) === '') {
            $summary['skipped']++;
            return;
        }

        if ($this->airports()->existingForMovement($tripId, $movementType, $scheduledAt) !== null) {
            $summary['already_present']++;
            return;
        }

        if (! $apply) {
            $summary['would_project_airport_workflows']++;
            return;
        }

        try {
            $workflow = $this->airports()->ensure($delivery, $movementType, $scheduledAt);
            ($workflow['exists'] ?? false) ? $summary['airport_workflows_projected']++ : $summary['errors']++;
        } catch (Throwable) {
            $this->recordFailure(
                $this->airports()->existingForMovement($tripId, $movementType, $scheduledAt) !== null,
                $summary,
            );
        }
    }

    /** @param array<string, int> $summary */
    private function recordFailure(bool $nowExists, array &$summary): void
    {
        $nowExists ? $summary['conflicts']++ : $summary['errors']++;
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        return [
            'trips_examined' => 0,
            'checklists_projected' => 0,
            'airport_workflows_projected' => 0,
            'would_project_checklists' => 0,
            'would_project_airport_workflows' => 0,
            'already_present' => 0,
            'skipped' => 0,
            'skipped_terminal_status' => 0,
            'skipped_outside_window' => 0,
            'errors' => 0,
            'conflicts' => 0,
        ];
    }

    private function tripRepository(): TuroNormalizedTripRepository
    {
        return $this->trips ?? \Config\Services::turoNormalizedTripRepository();
    }

    private function checklistService(): TripMovementChecklistService
    {
        return $this->checklists ?? \Config\Services::tripMovementChecklistService();
    }

    private function airports(): AirportMovementWorkflowService
    {
        return $this->airportWorkflows ?? \Config\Services::airportMovementWorkflowService();
    }
}
