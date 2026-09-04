<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class VehicleOperationalProfileService
{
    public const ENERGY_KINDS = ['electric', 'gasoline', 'diesel', 'hybrid', 'unknown'];
    public const CAPABILITIES = ['key_card', 'charging_adapter'];

    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    /** @return array<string, mixed>|null */
    public function profile(int $vehicleId): ?array
    {
        return $this->repo()->profile($vehicleId);
    }

    public function save(int $vehicleId, string $energyKind, mixed $target, array $capabilities, int $actorUserId): void
    {
        if (! in_array($energyKind, self::ENERGY_KINDS, true) || array_diff($capabilities, self::CAPABILITIES) !== [] || $actorUserId < 1) {
            throw new \InvalidArgumentException('Invalid operational profile.');
        }
        $targetPercent = $target === null || $target === '' ? null : filter_var($target, FILTER_VALIDATE_INT);
        if ($targetPercent === false || ($targetPercent !== null && ($targetPercent < 0 || $targetPercent > 100))) {
            throw new \InvalidArgumentException('Ready energy target must be between 0 and 100.');
        }
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            throw new \InvalidArgumentException('Vehicle not found.');
        }
        $this->repo()->saveProfile((int) $vehicle['company_id'], $vehicleId, $energyKind, $targetPercent, $capabilities, $actorUserId);
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
