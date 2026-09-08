<?php

namespace App\Controllers;

use App\Exceptions\EarlyHandoffConfirmationRequired;
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
        $latestEvent = ($checklist['exists'] ?? false) ? Services::movementEventService()->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $isStagedPickup = ($latestEvent['event_code'] ?? null) === 'vehicle_staged';
        $isPickupConfirmed = ($latestEvent['event_code'] ?? null) === 'actual_handoff';
        $flashedFormData = CoreServices::session()->getFlashdata('movement_fact_data');
        $isEarlyHandoffWarning = CoreServices::session()->getFlashdata('movement_early_handoff_warning') === '1';
        $correctingFacts = $latestFacts !== null && ($this->request->getGet('correct') === '1' || is_array($flashedFormData) && isset($flashedFormData['assessment_id']));
        $repairingFacts = $latestFacts !== null && $this->request->getGet('repair') === '1';
        $factFormData = $correctingFacts && is_array($flashedFormData)
            ? Services::movementOperationalFactPresentationService()->mergeCorrectionFormData($latestFacts['form_data'], $flashedFormData)
            : ($correctingFacts ? $latestFacts['form_data'] : (is_array($flashedFormData) ? $flashedFormData : []));
        return view('trip_movement_checklists/show', [
            'assets' => Services::assetManifestService()->appAssets(),
            'checklist' => $checklist,
            'currentLocation' => ($checklist['exists'] ?? false) ? Services::currentVehicleLocationService()->resolve((int) $checklist['fleet_vehicle_id']) : null,
            'tripContext' => ($checklist['exists'] ?? false) ? Services::operationalFactsRepository()->tripContext((int) $checklist['turo_trip_normalized_id']) : null,
            'latestFacts' => $latestFacts,
            'latestEvent' => $latestEvent,
            'isStagedPickup' => $isStagedPickup,
            'isPickupConfirmed' => $isPickupConfirmed,
            'correctingFacts' => $correctingFacts,
            'repairingFacts' => $repairingFacts,
            'repairCandidates' => $repairingFacts ? Services::movementOperationalFactService()->wrongTripCandidates($checklist, (int) $latestFacts['event_id']) : [],
            'factFormData' => $factFormData,
            'isEarlyHandoffWarning' => $isEarlyHandoffWarning,
            'hnlGarages' => (new \App\Services\Fleet\HnlGarageCatalog())->definitions(),
            'notice' => session()->getFlashdata('movement_checklist_notice'),
            'error' => session()->getFlashdata('movement_checklist_error'),
        ]);
    }

    public function vehicleTripHistory(int $vehicleId): string
    {
        $vehicle = Services::operationalFactsRepository()->vehicle($vehicleId);
        $trips = $vehicle === null ? [] : Services::operationalFactsRepository()->vehicleTripHistory($vehicleId);
        $requestedTripId = (int) $this->request->getGet('trip');
        $selectedTripId = in_array($requestedTripId, array_map(static fn (array $trip): int => (int) $trip['id'], $trips), true)
            ? $requestedTripId
            : null;

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'vehicle' => $vehicle,
            'trips' => $trips,
            'selectedTripId' => $selectedTripId,
        ])->render('trip_movement_checklists/history');
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
        } catch (EarlyHandoffConfirmationRequired $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=handoff')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('movement_fact_data', $data)
                ->with('movement_early_handoff_warning', '1');
        } catch (\InvalidArgumentException $exception) {
            return $this->back(false, '', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    public function stageAtHnl(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $ok = Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($ok, 'Vehicle staged at HNL. Guest pickup is not yet confirmed.', 'That vehicle could not be staged.');
        } catch (\InvalidArgumentException $exception) {
            return $this->back(false, '', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    public function confirmGuestPickup(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $ok = Services::movementOperationalFactService()->confirmGuestPickup(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($ok, 'Guest pickup confirmed.', 'Guest pickup could not be confirmed.');
        } catch (EarlyHandoffConfirmationRequired $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=confirm-pickup')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('movement_fact_data', $data)
                ->with('movement_early_handoff_warning', '1');
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

    public function repairWrongTrip(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $ok = Services::movementOperationalFactService()->repairWrongTrip(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return CoreServices::redirectresponse()->to('/operations/checklists/' . $id)->with('movement_checklist_notice', 'Recorded facts moved to the correct trip.');
            }

            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?repair=1')->with('movement_checklist_error', 'Those facts could not be repaired.');
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?repair=1')->with('movement_checklist_error', $exception->getMessage());
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
