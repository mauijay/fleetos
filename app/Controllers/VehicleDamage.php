<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleDamage extends BaseController
{
    public function createForVehicle(int $vehicleId): RedirectResponse
    {
        return $this->vehicleResult(
            $vehicleId,
            Services::vehicleDamageService()->create(
                $this->activeCompanyId(),
                $vehicleId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Damage item recorded.',
            'new',
        );
    }

    public function createForChecklist(int $checklistId): RedirectResponse
    {
        $companyId = $this->activeCompanyId();
        $checklist = Services::tripMovementChecklistService()->checklistForCompany($companyId, $checklistId);
        if ($checklist === null) {
            return $this->checklistFailure($checklistId, 'Movement not found in the active fleet company.', 'new');
        }
        $data = $this->request->getPost();
        $result = Services::vehicleDamageService()->create(
            $companyId,
            (int) $checklist['fleet_vehicle_id'],
            $data,
            $this->actorUserId(),
            [
                'trip_id' => (int) $checklist['turo_trip_normalized_id'],
                'movement_event_id' => null,
                'recovery_exception_id' => $data['recovery_exception_id'] ?? null,
            ],
        );

        return $this->checklistResult($checklistId, $result, 'Damage item recorded.', 'new');
    }

    public function correct(int $vehicleId, int $itemId): RedirectResponse
    {
        return $this->vehicleResult(
            $vehicleId,
            Services::vehicleDamageService()->correctDetails(
                $this->activeCompanyId(),
                $vehicleId,
                $itemId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Damage details corrected.',
            'correct_' . $itemId,
        );
    }

    public function changeSeverity(int $vehicleId, int $itemId): RedirectResponse
    {
        return $this->vehicleResult(
            $vehicleId,
            Services::vehicleDamageService()->changeSeverity(
                $this->activeCompanyId(),
                $vehicleId,
                $itemId,
                (string) $this->request->getPost('severity_code'),
                (string) $this->request->getPost('note'),
                $this->actorUserId(),
            ),
            'Damage severity updated.',
            'severity_' . $itemId,
        );
    }

    public function worsenForVehicle(int $vehicleId, int $itemId): RedirectResponse
    {
        return $this->vehicleResult(
            $vehicleId,
            Services::vehicleDamageService()->worsen(
                $this->activeCompanyId(),
                $vehicleId,
                $itemId,
                $this->request->getPost(),
                $this->actorUserId(),
            ),
            'Damage worsening recorded.',
            'worsen_' . $itemId,
        );
    }

    public function worsenForChecklist(int $checklistId, int $itemId): RedirectResponse
    {
        $companyId = $this->activeCompanyId();
        $checklist = Services::tripMovementChecklistService()->checklistForCompany($companyId, $checklistId);
        if ($checklist === null) {
            return $this->checklistFailure($checklistId, 'Movement not found in the active fleet company.', 'worsen_' . $itemId);
        }
        $result = Services::vehicleDamageService()->worsen(
            $companyId,
            (int) $checklist['fleet_vehicle_id'],
            $itemId,
            $this->request->getPost(),
            $this->actorUserId(),
            ['trip_id' => (int) $checklist['turo_trip_normalized_id']],
        );

        return $this->checklistResult($checklistId, $result, 'Damage worsening recorded.', 'worsen_' . $itemId);
    }

    public function transition(int $vehicleId, int $itemId, string $status): RedirectResponse
    {
        return $this->vehicleResult(
            $vehicleId,
            Services::vehicleDamageService()->transitionStatus(
                $this->activeCompanyId(),
                $vehicleId,
                $itemId,
                $status,
                (string) $this->request->getPost('note'),
                $this->actorUserId(),
            ),
            'Damage status updated.',
            'status_' . $itemId,
        );
    }

    /** @param array{success:bool,id?:int,errors:array<string,string>} $result */
    private function vehicleResult(int $vehicleId, array $result, string $notice, string $formKey): RedirectResponse
    {
        $response = CoreServices::redirectresponse()->to('/fleet/vehicles/' . $vehicleId . '#vehicle-damage');
        if (! $result['success']) {
            return $response
                ->with('vehicle_damage_errors', $result['errors'])
                ->with('vehicle_damage_form', $formKey)
                ->with('vehicle_damage_data', $this->request->getPost());
        }

        return $response->with('vehicle_damage_notice', $notice);
    }

    /** @param array{success:bool,id?:int,errors:array<string,string>} $result */
    private function checklistResult(int $checklistId, array $result, string $notice, string $formKey): RedirectResponse
    {
        if (! $result['success']) {
            return $this->checklistFailure($checklistId, implode(' ', $result['errors']), $formKey);
        }

        return CoreServices::redirectresponse()->to('/operations/checklists/' . $checklistId . '#known-vehicle-damage')
            ->with('vehicle_damage_notice', $notice);
    }

    private function checklistFailure(int $checklistId, string $message, string $formKey): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/operations/checklists/' . $checklistId . '#known-vehicle-damage')
            ->with('vehicle_damage_errors', ['damage' => $message])
            ->with('vehicle_damage_form', $formKey)
            ->with('vehicle_damage_data', $this->request->getPost());
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
            throw new RuntimeException('Vehicle Damage requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }
}
