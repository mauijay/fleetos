<?php

namespace App\Services\Turo;

use App\Repositories\FleetVehicleRepository;
use App\Repositories\VehicleTuroListingRepository;

class TuroVehicleMatcher
{
    public function __construct(
        private readonly FleetVehicleRepository $fleetVehicles = new FleetVehicleRepository(),
        private readonly VehicleTuroListingRepository $turoListings = new VehicleTuroListingRepository(),
    ) {
    }

    public function match(?string $turoVehicleId, ?string $fleetCode = null): ?int
    {
        $fleetVehicleId = $this->fleetVehicles->findIdByTuroVehicleId($turoVehicleId);

        if ($fleetVehicleId !== null) {
            return $fleetVehicleId;
        }

        $fleetVehicleId = $this->fleetVehicles->findIdByFleetCode($fleetCode);

        if ($fleetVehicleId !== null) {
            $this->rememberTuroVehicleId($turoVehicleId, $fleetVehicleId);
        }

        return $fleetVehicleId;
    }

    private function rememberTuroVehicleId(?string $turoVehicleId, int $fleetVehicleId): void
    {
        if ($turoVehicleId === null || trim($turoVehicleId) === '') {
            return;
        }

        $turoVehicleId = trim($turoVehicleId);

        if ($this->turoListings->findActiveByTuroVehicleId($turoVehicleId) !== null) {
            return;
        }

        if ($this->turoListings->activeListingsForFleetVehicle($fleetVehicleId) !== []) {
            return;
        }

        $this->turoListings->createMapping($turoVehicleId, $fleetVehicleId, 'Auto-mapped from imported Turo vehicle ID after FleetOS vehicle label matched.');
    }
}
