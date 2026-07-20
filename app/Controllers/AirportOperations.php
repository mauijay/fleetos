<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class AirportOperations extends BaseController
{
    public function index(): string
    {
        return view('airport_operations/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'workflows' => service('airportMovementWorkflowService')->today(new \DateTimeImmutable((string) ($this->request->getGet('date') ?? 'now')), [
                'status' => $this->request->getGet('status'),
            ]),
            'notice' => session()->getFlashdata('airport_workflow_notice'),
            'error' => session()->getFlashdata('airport_workflow_error'),
        ]);
    }

    public function show(int $id): string
    {
        return view('airport_operations/show', [
            'assets' => service('assetManifestService')->appAssets(),
            'workflow' => service('airportMovementWorkflowService')->workflow($id),
            'notice' => session()->getFlashdata('airport_workflow_notice'),
            'error' => session()->getFlashdata('airport_workflow_error'),
        ]);
    }

    public function recordStaging(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->recordStaging($id, $this->request->getPost()), 'Staging details saved.', 'Staging details could not be saved.');
    }

    public function markStaged(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->markStaged($id, $this->request->getPost()), 'Vehicle marked staged.', 'Confirm vehicle parked, locked, key card placed, and parking details verified before staging.');
    }

    public function markInstructionsSent(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->markInstructionsSent($id), 'Guest instructions marked sent.', 'Instructions are incomplete. Record verified parking details first.');
    }

    public function confirmPickup(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->confirmGuestPickup($id), 'Guest pickup confirmed.', 'Pickup cannot be confirmed from the current workflow state.');
    }

    public function recordReturnLocation(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->recordReturnLocation($id, $this->request->getPost()), 'Return location saved.', 'Return location could not be saved.');
    }

    public function confirmVehicleLocated(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->confirmVehicleLocated($id), 'Vehicle located.', 'Vehicle could not be marked located.');
    }

    public function recordParkingCost(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->recordParkingCost($id, $this->request->getPost('actual_parking_cost_amount'), (string) $this->request->getPost('parking_cost_responsibility')), 'Parking cost saved.', 'Parking cost or responsibility was invalid.');
    }

    public function complete(int $id): RedirectResponse
    {
        return $this->back(service('airportMovementWorkflowService')->complete($id), 'Airport workflow completed.', 'Complete the linked movement checklist before closing this airport workflow.');
    }

    public function createException(int $id): RedirectResponse
    {
        $exceptionId = service('airportMovementWorkflowService')->createException($id, (string) $this->request->getPost('exception_type'), (string) ($this->request->getPost('severity') ?? 'today'), (string) $this->request->getPost('note'));

        return $this->back($exceptionId > 0, 'Airport exception recorded.', 'Airport exception could not be recorded.');
    }

    public function createTuroAccessOverride(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->createIncident($id, $this->request->getPost(), $this->request->getPost('confirm_duplicate') === '1');

        return $this->back((bool) ($result['success'] ?? false), (string) ($result['message'] ?? 'Incident recorded.'), (string) ($result['message'] ?? 'Incident could not be recorded.'));
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'airport_workflow_notice' : 'airport_workflow_error', $ok ? $notice : $error);
    }
}
