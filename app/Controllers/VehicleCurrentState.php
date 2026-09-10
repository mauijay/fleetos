<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleCurrentState extends BaseController
{
    public function recordPosition(int $vehicleId): RedirectResponse
    {
        $data = $this->withLocalTimestamp($this->request->getPost());

        return $this->record(
            $vehicleId,
            $data,
            fn (int $companyId, int $actorUserId): bool => Services::movementOperationalFactService()->recordCurrentPositionForVehicle($companyId, $vehicleId, $data, $actorUserId),
            'Current vehicle position recorded.',
            'current_position_data',
        );
    }

    public function recordReadiness(int $vehicleId): RedirectResponse
    {
        $data = $this->withLocalTimestamp($this->request->getPost());

        return $this->record(
            $vehicleId,
            $data,
            fn (int $companyId, int $actorUserId): bool => Services::movementOperationalFactService()->recordCurrentReadinessForVehicle($companyId, $vehicleId, $data, $actorUserId),
            'Current vehicle readiness recorded.',
            'current_readiness_data',
        );
    }

    /** @param callable(int, int): bool $write */
    private function record(int $vehicleId, array $data, callable $write, string $notice, string $flashKey): RedirectResponse
    {
        $response = CoreServices::redirectresponse()->to('/fleet/vehicles/' . $vehicleId . '#current-operations');
        try {
            if (! $write($this->activeCompanyId(), $this->actorUserId())) {
                throw new RuntimeException('The current vehicle state could not be recorded.');
            }

            return $response->with('vehicle_current_state_notice', $notice);
        } catch (\InvalidArgumentException|RuntimeException $exception) {
            return $response
                ->with('vehicle_current_state_error', $exception->getMessage())
                ->with($flashKey, $data);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function withLocalTimestamp(array $data): array
    {
        $date = trim((string) ($data['occurred_on'] ?? ''));
        $time = trim((string) ($data['occurred_time'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            $data['occurred_at'] = $date . 'T' . $time;
        }

        return $data;
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Current vehicle state requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }
}
