<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class ScheduledMovementLocationService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null, private readonly LocationClassificationService $classifier = new LocationClassificationService())
    {
    }

    /** @return array{pickup:int,return:int} */
    public function retainForTrip(int $tripId, ?int $vehicleId, ?string $pickupText, ?string $returnText): array
    {
        return [
            'pickup' => $this->retain($tripId, $vehicleId, 'pickup', $pickupText),
            'return' => $this->retain($tripId, $vehicleId, 'return', $returnText),
        ];
    }

    private function retain(int $tripId, ?int $vehicleId, string $movementType, ?string $sourceText): int
    {
        $workflow = $this->repo()->airportWorkflow($tripId, $movementType);
        $classification = $this->classifier->classify(
            $sourceText,
            $workflow['airport_code'] ?? null,
            isset($workflow['airport_id']) ? (int) $workflow['airport_id'] : null,
            isset($workflow['id']) ? (int) $workflow['id'] : null,
        );
        return $this->repo()->upsertScheduledLocation($tripId, $vehicleId, $movementType, $classification);
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
