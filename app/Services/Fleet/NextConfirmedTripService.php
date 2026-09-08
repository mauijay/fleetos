<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class NextConfirmedTripService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null, private readonly PlanningHorizonService $horizons = new PlanningHorizonService())
    {
    }

    /** @return array<string, mixed>|null */
    public function forVehicle(int $vehicleId, ?\DateTimeImmutable $asOf = null): ?array
    {
        $asOf ??= new \DateTimeImmutable();
        $after = $asOf;
        $activeTripId = null;
        $activeEvent = $this->repo()->latestActiveLifecycleEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if (in_array($activeEvent['event_code'] ?? null, ['actual_handoff', 'vehicle_staged'], true) && isset($activeEvent['turo_trip_normalized_id'])) {
            $activeTripId = (int) $activeEvent['turo_trip_normalized_id'];
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
        return $trip;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
