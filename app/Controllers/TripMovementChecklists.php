<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class TripMovementChecklists extends BaseController
{
    public function show(int $id): string
    {
        $checklist = Services::tripMovementChecklistService()->checklist($id);
        $latestFacts = ($checklist['exists'] ?? false) ? Services::movementOperationalFactPresentationService()->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $flashedFormData = CoreServices::session()->getFlashdata('movement_fact_data');
        $correctingFacts = $latestFacts !== null && ($this->request->getGet('correct') === '1' || is_array($flashedFormData) && isset($flashedFormData['assessment_id']));
        $factFormData = $correctingFacts && is_array($flashedFormData)
            ? Services::movementOperationalFactPresentationService()->mergeCorrectionFormData($latestFacts['form_data'], $flashedFormData)
            : ($correctingFacts ? $latestFacts['form_data'] : (is_array($flashedFormData) ? $flashedFormData : []));
        return view('trip_movement_checklists/show', [
            'assets' => Services::assetManifestService()->appAssets(),
            'checklist' => $checklist,
            'currentLocation' => ($checklist['exists'] ?? false) ? Services::currentVehicleLocationService()->resolve((int) $checklist['fleet_vehicle_id']) : null,
            'latestFacts' => $latestFacts,
            'correctingFacts' => $correctingFacts,
            'factFormData' => $factFormData,
            'notice' => session()->getFlashdata('movement_checklist_notice'),
            'error' => session()->getFlashdata('movement_checklist_error'),
        ]);
    }

    public function completeItem(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->completeItem($id, $this->request->getPost('note'), $this->actorUserId()), 'Item completed.', 'That checklist item could not be completed.');
    }

    public function undoItem(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->undoItem($id, $this->actorUserId()), 'Item reopened.', 'That checklist item could not be reopened.');
    }

    public function markNotApplicable(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->markNotApplicable($id, $this->request->getPost('note'), $this->actorUserId()), 'Item marked not applicable.', 'That checklist item could not be changed.');
    }

    public function setDisposition(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->setDisposition($id, (string) $this->request->getPost('vehicle_disposition'), $this->actorUserId()), 'Vehicle disposition saved.', 'Choose a valid vehicle disposition.');
    }

    public function complete(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->completeChecklist($id, $this->request->getPost('completion_note'), $this->actorUserId()), 'Movement workflow completed.', 'Complete required critical items before closing this workflow.');
    }

    public function reopen(int $id): RedirectResponse
    {
        $confirmed = $this->request->getPost('confirm_reopen') === '1';
        return $this->back($confirmed && Services::tripMovementChecklistService()->reopenChecklist($id, $this->actorUserId()), 'Movement workflow reopened.', 'Confirm before reopening a completed workflow.');
    }

    public function recordFacts(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $ok = Services::movementOperationalFactService()->recordForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($ok, 'Operational facts recorded.', 'That movement could not be recorded.');
        } catch (\InvalidArgumentException $exception) {
            return $this->back(false, '', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    public function correctFacts(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $ok = Services::movementOperationalFactService()->correctForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return CoreServices::redirectresponse()->to('/operations/checklists/' . $id)->with('movement_checklist_notice', 'Recorded facts corrected.');
            }

            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?correct=1')->with('movement_checklist_error', 'Those recorded facts could not be corrected.')->with('movement_fact_data', $data);
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?correct=1')->with('movement_checklist_error', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'movement_checklist_notice' : 'movement_checklist_error', $ok ? $notice : $error);
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new \RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }
}
