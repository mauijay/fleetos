<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class NextConfirmedTripService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly PlanningHorizonService $horizons = new PlanningHorizonService(),
        private readonly ?CurrentVehicleCustodyService $custodyService = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function forVehicle(int $vehicleId, ?\DateTimeImmutable $asOf = null): ?array
    {
        $asOf ??= new \DateTimeImmutable();
        $after = $asOf;
        $custody = $this->custody()->resolve($vehicleId, $asOf);
        $activeEvent = $custody['basis_event'];
        $activeTripId = isset($custody['active_trip_id']) ? (int) $custody['active_trip_id'] : null;
        if ($activeTripId !== null) {
            $activeTrip = $this->repo()->tripSchedule($activeTripId);
            if (! empty($activeTrip['starts_at'])) {
                $activeStartsAt = new \DateTimeImmutable((string) $activeTrip['starts_at']);
                if ($activeStartsAt > $after) {
                    $after = $activeStartsAt;
                }
            }
        }

        $trip = $this->repo()->nextConfirmedTrip($vehicleId, $after->format('Y-m-d H:i:s'), $activeTripId);
        if ($trip === null) {
            return null;
        }
        $trip['planning_horizon'] = $this->horizons->classify(new \DateTimeImmutable((string) $trip['starts_at']), $asOf);
        $trip['is_same_day_turnaround'] = false;
        if (in_array($activeEvent['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && (int) ($activeEvent['turo_trip_normalized_id'] ?? 0) > 0) {
            $sourceTrip = $this->repo()->tripSchedule((int) $activeEvent['turo_trip_normalized_id']);
            try {
                $returnAt = new \DateTimeImmutable((string) ($sourceTrip['ends_at'] ?? ''));
                $pickupAt = new \DateTimeImmutable((string) $trip['starts_at']);
                $trip['is_same_day_turnaround'] = $returnAt <= $pickupAt
                    && $returnAt->format('Y-m-d') === $pickupAt->format('Y-m-d');
            } catch (\Exception) {
                $trip['is_same_day_turnaround'] = false;
            }
        }
        return $trip;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }

    private function custody(): CurrentVehicleCustodyService
    {
        return $this->custodyService ?? new CurrentVehicleCustodyService($this->repo());
    }
}
