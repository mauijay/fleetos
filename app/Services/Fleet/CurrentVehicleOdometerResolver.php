<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleHealthObservationRepository;

class CurrentVehicleOdometerResolver
{
    public function __construct(private readonly ?VehicleHealthObservationRepository $repository = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function resolve(int $companyId, int $vehicleId, ?\DateTimeImmutable $asOf = null): ?array
    {
        $asOf ??= new \DateTimeImmutable();
        $row = $this->repo()->latestOdometer($companyId, $vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($row === null) {
            return null;
        }

        return [
            'observation_id' => (int) $row['id'],
            'odometer_miles' => (int) $row['odometer_miles'],
            'observed_at' => (string) $row['observed_at'],
            'received_at' => (string) $row['received_at'],
            'source' => (string) $row['source'],
            'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
        ];
    }

    private function repo(): VehicleHealthObservationRepository
    {
        return $this->repository ?? new VehicleHealthObservationRepository();
    }
}
