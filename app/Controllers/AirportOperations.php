<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class AirportOperations extends BaseController
{
    public function index(): string
    {
        $companyId = $this->activeCompanyId();

        return view('airport_operations/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'workflows' => service('airportMovementWorkflowService')->today($companyId, new \DateTimeImmutable((string) ($this->request->getGet('date') ?? 'now')), [
                'status' => $this->request->getGet('status'),
            ]),
            'notice' => session()->getFlashdata('airport_workflow_notice'),
            'error' => session()->getFlashdata('airport_workflow_error'),
        ]);
    }

    public function show(int $id): string
    {
        $companyId = $this->activeCompanyId();

        return view('airport_operations/show', [
            'assets' => service('assetManifestService')->appAssets(),
            'workflow' => service('airportMovementWorkflowService')->workflow($companyId, $id),
            'notice' => session()->getFlashdata('airport_workflow_notice'),
            'error' => session()->getFlashdata('airport_workflow_error'),
        ]);
    }

    public function recordStaging(int $id): RedirectResponse
    {
        try {
            return $this->back(service('airportMovementWorkflowService')->recordStaging($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId()), 'Staging details saved.', 'Staging details could not be saved.');
        } catch (\InvalidArgumentException $exception) {
            return $this->back(false, '', $exception->getMessage());
        }
    }

    public function markStaged(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->markStaged($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId()), 'Vehicle marked staged.', 'Confirm vehicle parked, locked, key card placed, and parking details verified before staging.');
    }

    public function markInstructionsSent(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->markInstructionsSent($this->activeCompanyId(), $id, $this->actorUserId()), 'Guest instructions marked sent.', 'Instructions are incomplete. Record verified parking details first.');
    }

    public function confirmPickup(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->confirmGuestPickup($this->activeCompanyId(), $id, $this->actorUserId()), 'Guest pickup confirmed.', 'Pickup cannot be confirmed from the current workflow state.');
    }

    public function recordReturnLocation(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->recordReturnLocation($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId()), 'Return location saved.', 'Return location could not be saved.');
    }

    public function confirmVehicleLocated(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->confirmVehicleLocated($this->activeCompanyId(), $id, $this->actorUserId()), 'Vehicle located.', 'Vehicle could not be marked located.');
    }

    public function recordParkingCost(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->recordParkingCost($this->activeCompanyId(), $id, $this->request->getPost('actual_parking_cost_amount'), (string) $this->request->getPost('parking_cost_responsibility'), $this->actorUserId()), 'Parking cost saved.', 'Parking cost or responsibility was invalid.');
    }

    public function complete(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->complete($this->activeCompanyId(), $id, $this->actorUserId()), 'Airport workflow completed.', 'Complete the linked movement checklist before closing this airport workflow.');
    }

    public function createException(int $id): RedirectResponse
    {
        $exceptionId = service('airportMovementWorkflowService')->createException($this->activeCompanyId(), $id, (string) $this->request->getPost('exception_type'), (string) ($this->request->getPost('severity') ?? 'today'), (string) $this->request->getPost('note'), $this->actorUserId());

        return $this->back($exceptionId > 0, 'Airport exception recorded.', 'Airport exception could not be recorded.');
    }

    public function createTuroAccessOverride(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->createIncident($this->activeCompanyId(), $id, $this->request->getPost(), $this->request->getPost('confirm_duplicate') === '1');

        return $this->back((bool) ($result['success'] ?? false), (string) ($result['message'] ?? 'Incident recorded.'), (string) ($result['message'] ?? 'Incident could not be recorded.'));
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'airport_workflow_notice' : 'airport_workflow_error', $ok ? $notice : $error);
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new \RuntimeException('Airport Operations requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null || (int) $user->id < 1) {
            throw new \RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }
}
