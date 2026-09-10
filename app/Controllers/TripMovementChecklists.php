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
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $readiness = ($checklist['exists'] ?? false) && $companyId > 0
            ? (Services::movementReadinessReadService()->forCompany($companyId, [$id])[$id] ?? null)
            : null;
        $factsPresenter = Services::movementOperationalFactPresentationService();
        $tripFacts = ($checklist['exists'] ?? false) ? $factsPresenter->tripFacts((int) $checklist['turo_trip_normalized_id']) : ['pickup' => null, 'return' => null];
        $latestFacts = ($checklist['exists'] ?? false) ? $factsPresenter->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $latestEvent = ($checklist['exists'] ?? false) ? Services::movementEventService()->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $isStagedPickup = ($tripFacts['pickup']['event_code'] ?? null) === 'vehicle_staged';
        $handoffRequirement = array_values(array_filter(
            $readiness['requirements'] ?? [],
            static fn (array $requirement): bool => ($requirement['code'] ?? null) === 'guest_handoff',
        ))[0] ?? null;
        $isPickupConfirmed = ($handoffRequirement['status'] ?? null) === 'satisfied';
        $flashedFormData = CoreServices::session()->getFlashdata('movement_fact_data');
        $positionFormData = CoreServices::session()->getFlashdata('vehicle_position_data');
        $isEarlyHandoffWarning = CoreServices::session()->getFlashdata('movement_early_handoff_warning') === '1';
        $factTarget = $this->factTarget((string) $this->request->getGet('fact'));
        if ($factTarget === null && is_array($flashedFormData)) {
            $factTarget = $this->factTarget((string) ($flashedFormData['fact_target'] ?? ''));
        }
        $selectedFacts = $factTarget === null ? $latestFacts : $tripFacts[$factTarget];
        $correctingFacts = $selectedFacts !== null && ($this->request->getGet('correct') === '1' || is_array($flashedFormData) && isset($flashedFormData['assessment_id']));
        $repairingFacts = $selectedFacts !== null && $this->request->getGet('repair') === '1';
        $factFormData = $correctingFacts && is_array($flashedFormData)
            ? $factsPresenter->mergeCorrectionFormData($selectedFacts['form_data'], $flashedFormData)
            : ($correctingFacts ? $selectedFacts['form_data'] : (is_array($flashedFormData) ? $flashedFormData : []));
        return view('trip_movement_checklists/show', [
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'checklist' => $checklist,
            'readiness' => $readiness,
            'currentLocation' => ($checklist['exists'] ?? false) ? Services::currentVehicleLocationService()->resolve((int) $checklist['fleet_vehicle_id']) : null,
            'tripContext' => ($checklist['exists'] ?? false) ? Services::operationalFactsRepository()->tripContext((int) $checklist['turo_trip_normalized_id']) : null,
            'latestFacts' => $selectedFacts,
            'tripFacts' => $tripFacts,
            'factTarget' => $factTarget,
            'latestEvent' => $latestEvent,
            'isStagedPickup' => $isStagedPickup,
            'isPickupConfirmed' => $isPickupConfirmed,
            'pickupConfirmedAt' => $handoffRequirement['basis_at'] ?? null,
            'correctingFacts' => $correctingFacts,
            'repairingFacts' => $repairingFacts,
            'repairCandidates' => $repairingFacts ? Services::movementOperationalFactService()->wrongTripCandidates($checklist, (int) $selectedFacts['event_id']) : [],
            'repairConflicts' => $repairingFacts ? Services::movementOperationalFactService()->wrongTripConflicts($checklist, (int) $selectedFacts['event_id']) : [],
            'factFormData' => $factFormData,
            'isEarlyHandoffWarning' => $isEarlyHandoffWarning,
            'positionFormData' => is_array($positionFormData) ? $positionFormData : [],
            'showPositionForm' => $this->request->getGet('action') === 'position',
            'hnlGarages' => (new \App\Services\Fleet\HnlGarageCatalog())->definitions(),
            'notice' => session()->getFlashdata('movement_checklist_notice'),
            'error' => session()->getFlashdata('movement_checklist_error'),
        ]);
    }

    public function vehicleTripHistory(int $vehicleId): string
    {
        $companyId = $this->activeCompanyId();
        $vehicle = Services::operationalFactsRepository()->vehicleForCompany($companyId, $vehicleId);
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
            'navigation' => $this->navigation(),
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

    public function completePhotos(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->completePickupPhotos($id, $this->activeCompanyId(), $this->actorUserId()), 'Photos marked complete.', 'Pickup photos could not be completed.');
    }

    public function undoPhotos(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->undoPickupPhotos($id, $this->activeCompanyId(), $this->actorUserId()), 'Photos reopened.', 'Pickup photos could not be reopened.');
    }

    public function confirmChargingAdapter(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->confirmChargingAdapter($id, $this->activeCompanyId(), $this->actorUserId()), 'Charging adapter confirmed.', 'Charging adapter could not be confirmed.');
    }

    public function undoChargingAdapter(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->undoChargingAdapter($id, $this->activeCompanyId(), $this->actorUserId()), 'Charging adapter reopened.', 'Charging adapter could not be reopened.');
    }

    public function setDisposition(int $id): RedirectResponse
    {
        return $this->back(Services::tripMovementChecklistService()->setDisposition($id, (string) $this->request->getPost('vehicle_disposition'), $this->actorUserId()), 'Exceptional hold saved.', 'Choose a valid exceptional hold.');
    }

    public function complete(int $id): RedirectResponse
    {
        $checklist = Services::tripMovementChecklistService()->checklist($id);
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $readiness = ($checklist['exists'] ?? false) && $companyId > 0
            ? (Services::movementReadinessReadService()->forCompany($companyId, [$id])[$id] ?? null)
            : null;

        return $this->back(Services::tripMovementChecklistService()->completeChecklist($id, $this->request->getPost('completion_note'), $this->actorUserId(), ($readiness['ready'] ?? false) === true), 'Movement workflow completed.', 'Complete current readiness actions before closing this workflow.');
    }

    public function reopen(int $id): RedirectResponse
    {
        $confirmed = $this->request->getPost('confirm_reopen') === '1';
        return $this->back($confirmed && Services::tripMovementChecklistService()->reopenChecklist($id, $this->actorUserId()), 'Movement workflow reopened.', 'Confirm before reopening a completed workflow.');
    }

    public function recordFacts(int $id): RedirectResponse
    {
        $data = $this->movementFactData();
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
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($ok, 'Vehicle staged at HNL. Guest pickup is not yet confirmed.', 'That vehicle could not be staged.');
        } catch (\InvalidArgumentException $exception) {
            return $this->back(false, '', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    public function confirmGuestPickup(int $id): RedirectResponse
    {
        $data = $this->movementFactData();
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

    public function recordVehiclePosition(int $id): RedirectResponse
    {
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->recordVehiclePosition(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return CoreServices::redirectresponse()->to('/operations/checklists/' . $id)
                    ->with('movement_checklist_notice', 'Current vehicle position recorded.');
            }

            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=position')
                ->with('movement_checklist_error', 'That vehicle position could not be recorded.')
                ->with('vehicle_position_data', $data);
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=position')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('vehicle_position_data', $data);
        }
    }

    public function correctFacts(int $id): RedirectResponse
    {
        $data = $this->movementFactData();
        $target = $this->factTarget((string) ($data['fact_target'] ?? ''));
        $correctionHref = '/operations/checklists/' . $id . '?correct=1' . ($target === null ? '' : '&fact=' . $target);
        try {
            $ok = Services::movementOperationalFactService()->correctForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return CoreServices::redirectresponse()->to('/operations/checklists/' . $id)->with('movement_checklist_notice', 'Recorded facts corrected.');
            }

            return CoreServices::redirectresponse()->to($correctionHref)->with('movement_checklist_error', 'Those recorded facts could not be corrected.')->with('movement_fact_data', $data);
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to($correctionHref)->with('movement_checklist_error', $exception->getMessage())->with('movement_fact_data', $data);
        }
    }

    public function repairWrongTrip(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        $target = $this->factTarget((string) ($data['fact_target'] ?? ''));
        $repairHref = '/operations/checklists/' . $id . '?repair=1' . ($target === null ? '' : '&fact=' . $target);
        try {
            $ok = Services::movementOperationalFactService()->repairWrongTrip(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return CoreServices::redirectresponse()->to('/operations/checklists/' . $id)->with('movement_checklist_notice', 'Recorded facts moved to the correct trip.');
            }

            return CoreServices::redirectresponse()->to($repairHref)->with('movement_checklist_error', 'Those facts could not be repaired.');
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to($repairHref)->with('movement_checklist_error', $exception->getMessage());
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

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new \RuntimeException('Movement operations require exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
        ];
    }

    private function factTarget(string $target): ?string
    {
        return in_array($target, ['pickup', 'return'], true) ? $target : null;
    }

    /** @return array<string, mixed> */
    private function movementFactData(): array
    {
        $data = $this->request->getPost();
        $occurredOn = trim((string) ($data['occurred_on'] ?? ''));
        $occurredTime = trim((string) ($data['occurred_time'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredOn) === 1 && preg_match('/^\d{2}:\d{2}$/', $occurredTime) === 1) {
            $data['occurred_at'] = $occurredOn . 'T' . $occurredTime;
        }

        return $data;
    }
}
