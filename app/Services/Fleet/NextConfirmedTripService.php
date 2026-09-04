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
        $trip = $this->repo()->nextConfirmedTrip($vehicleId, $asOf->format('Y-m-d H:i:s'));
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
