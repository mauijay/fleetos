<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepository;

class VehicleDamageReadService
{
    public function __construct(private readonly VehicleDamageService $conditions = new VehicleDamageService(), private readonly VehicleDamageRepository $repository = new VehicleDamageRepository(), private readonly \App\Repositories\VehicleDamageRepairRepository $repairs = new \App\Repositories\VehicleDamageRepairRepository())
    {
    }

    /** Both vehicle detail and Movement Checklist consume this projection. */
    public function workspace(int $companyId, int $vehicleId): array
    {
        return $this->conditions->workspace($companyId, $vehicleId) + ['work' => $this->repairs->workspace($companyId, $vehicleId)];
    }

    public function canonicalItem(int $companyId, int $vehicleId, int $itemId): ?array
    {
        return $this->repository->canonicalItem($companyId, $vehicleId, $itemId);
    }
}
