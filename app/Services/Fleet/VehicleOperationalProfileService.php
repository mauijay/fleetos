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

    public function save(int $vehicleId, string $energyKind, mixed $minimum, mixed $preferredMaximum, array $capabilities, int $actorUserId): void
    {
        if (! in_array($energyKind, self::ENERGY_KINDS, true) || array_diff($capabilities, self::CAPABILITIES) !== [] || $actorUserId < 1) {
            throw new \InvalidArgumentException('Invalid operational profile.');
        }
        [$minimumPercent, $preferredMaximumPercent] = $this->validatePolicy($minimum, $preferredMaximum);
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            throw new \InvalidArgumentException('Vehicle not found.');
        }
        $this->repo()->saveProfile(
            (int) $vehicle['company_id'],
            $vehicleId,
            $energyKind,
            $minimumPercent,
            $preferredMaximumPercent,
            $capabilities,
            $actorUserId,
        );
    }

    /** @return array{0:?int,1:?int} */
    public function validatePolicy(mixed $minimum, mixed $preferredMaximum): array
    {
        $minimumPercent = $this->percentage($minimum, 'Ready energy minimum');
        $preferredMaximumPercent = $this->percentage($preferredMaximum, 'Ready energy preferred maximum');
        if ($minimumPercent === null && $preferredMaximumPercent !== null) {
            throw new \InvalidArgumentException('Ready energy minimum is required when a preferred maximum is set.');
        }
        if ($minimumPercent !== null && $preferredMaximumPercent !== null && $minimumPercent > $preferredMaximumPercent) {
            throw new \InvalidArgumentException('Ready energy minimum must not exceed the preferred maximum.');
        }

        return [$minimumPercent, $preferredMaximumPercent];
    }

    private function percentage(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $percent = filter_var($value, FILTER_VALIDATE_INT);
        if ($percent === false || $percent < 0 || $percent > 100) {
            throw new \InvalidArgumentException($label . ' must be between 0 and 100.');
        }

        return $percent;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
