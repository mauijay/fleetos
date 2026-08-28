<?php

namespace App\Services\Fleet;

use App\Services\Turo\TuroTransactionRelinkingService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;

class UnknownVehicleOnboardingService
{
    public function __construct(
        private readonly FleetVehicleService $vehicles,
        private readonly TuroVehicleMappingService $mappings,
        private readonly TuroTripReconciliationService $reconciliation,
        private readonly TuroTransactionRelinkingService $transactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function onboard(string $requestedTuroVehicleId, array $vehicleData, ?int $actorUserId = null): array
    {
        $unmatched = $this->mappings->unmatchedVehicle($requestedTuroVehicleId);
        if ($unmatched === null) {
            return ['success' => false, 'stage' => 'authority', 'errors' => ['turo_vehicle_id' => 'The authoritative unmatched vehicle could not be verified. No vehicle was created.']];
        }

        $turoVehicleId = (string) $unmatched['turo_vehicle_id'];
        $created = $this->vehicles->create($vehicleData, $actorUserId, $turoVehicleId);
        if (! $created['success']) {
            return array_merge($created, ['stage' => 'creation', 'turo_vehicle_id' => $turoVehicleId]);
        }

        $reconciliation = $this->reconciliation->execute($turoVehicleId, 'Vehicle created from the unmatched vehicle workflow.', $actorUserId);
        $transactions = $this->transactions->relinkForTuroVehicle($turoVehicleId);

        return [
            'success' => true,
            'stage' => 'complete',
            'id' => $created['id'],
            'turo_vehicle_id' => $turoVehicleId,
            'reconciliation' => $reconciliation,
            'transactions' => $transactions,
        ];
    }
}
