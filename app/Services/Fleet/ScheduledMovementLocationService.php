<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class ScheduledMovementLocationService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly LocationClassificationService $classifier = new LocationClassificationService(),
        private readonly ?MovementLocationAliasService $aliases = null,
    ) {
    }

    /** @return array{pickup:int,return:int,material_changed:bool} */
    public function retainForTrip(int $tripId, ?int $vehicleId, ?string $pickupText, ?string $returnText): array
    {
        $before = [
            'pickup' => $this->repo()->scheduledLocation($tripId, 'pickup'),
            'return' => $this->repo()->scheduledLocation($tripId, 'return'),
        ];
        $pickupId = $this->retain($tripId, $vehicleId, 'pickup', $pickupText);
        $returnId = $this->retain($tripId, $vehicleId, 'return', $returnText);
        $after = [
            'pickup' => $this->repo()->scheduledLocation($tripId, 'pickup'),
            'return' => $this->repo()->scheduledLocation($tripId, 'return'),
        ];

        return [
            'pickup' => $pickupId,
            'return' => $returnId,
            'material_changed' => $this->materialFacts($before) !== $this->materialFacts($after),
        ];
    }

    private function materialFacts(array $locations): array
    {
        return array_map(static fn (?array $location): ?array => $location === null ? null : [
            'fleet_vehicle_id' => $location['fleet_vehicle_id'] ?? null,
            'location_class' => $location['location_class'] ?? null,
            'source_text' => $location['source_text'] ?? null,
        ], $locations);
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
        $trip = $this->repo()->trip($tripId);
        $alias = $trip === null ? null : $this->aliasService()->match((int) $trip['company_id'], $sourceText);
        if ($classification['location_class'] === 'unknown' && $alias !== null) {
            $classification['location_class'] = $alias['location_class'];
            $classification['classification_source'] = 'company_alias';
            $classification['classification_status'] = 'classified';
        }
        return $this->repo()->upsertScheduledLocation($tripId, $vehicleId, $movementType, $classification);
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }

    private function aliasService(): MovementLocationAliasService
    {
        return $this->aliases ?? new MovementLocationAliasService($this->repo());
    }
}
