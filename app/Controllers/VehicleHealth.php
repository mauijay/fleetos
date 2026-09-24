<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleHealth extends BaseController
{
    public function recordTirePressure(int $vehicleId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthObservationService()->recordTirePressure(
                $this->activeCompanyId(),
                $vehicleId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Tire-pressure observation recorded.',
            'record-tire-pressure',
            'tire_pressure',
        );
    }

    public function recordOdometer(int $vehicleId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthObservationService()->recordOdometer(
                $this->activeCompanyId(),
                $vehicleId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Odometer observation recorded.',
            'record-odometer',
            'odometer',
        );
    }

    public function saveTirePressurePolicy(int $vehicleId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthPolicyService()->saveTirePressurePolicy(
                $this->activeCompanyId(),
                $vehicleId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Tire-pressure policy saved.',
            'tire-pressure-policy',
            'policy',
        );
    }

    public function disableTirePressurePolicy(int $vehicleId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthPolicyService()->disableTirePressurePolicy(
                $this->activeCompanyId(),
                $vehicleId,
                $this->actorUserId(),
            ),
            'Tire-pressure policy disabled.',
            'tire-pressure-policy',
            'policy',
        );
    }

    public function correctObservation(int $vehicleId, int $observationId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthObservationService()->correct(
                $this->activeCompanyId(),
                $vehicleId,
                $observationId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Health observation corrected.',
            'health-observation-' . $observationId,
            'correction_' . $observationId,
        );
    }

    public function voidObservation(int $vehicleId, int $observationId): RedirectResponse
    {
        return $this->handle(
            $vehicleId,
            Services::vehicleHealthObservationService()->void(
                $this->activeCompanyId(),
                $vehicleId,
                $observationId,
                (string) $this->request->getPost('void_reason'),
                $this->actorUserId(),
            ),
            'Health observation voided.',
            'health-observation-' . $observationId,
            'void_' . $observationId,
        );
    }

    /** @param array{success:bool,id?:int,errors:array<string,string>} $result */
    private function handle(int $vehicleId, array $result, string $notice, string $anchor, string $formKey): RedirectResponse
    {
        $response = CoreServices::redirectresponse()->to('/fleet/vehicles/' . $vehicleId . '#' . $anchor);
        if (! $result['success']) {
            return $response
                ->with('vehicle_health_errors', $result['errors'])
                ->with('vehicle_health_form', $formKey)
                ->with('vehicle_health_data', $this->request->getPost());
        }

        return $response->with('vehicle_health_notice', $notice);
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Vehicle Health requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }
}
