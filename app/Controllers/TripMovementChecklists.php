<?php

namespace App\Controllers;

use App\Exceptions\EarlyHandoffConfirmationRequired;
use App\Repositories\VehicleRecoveryExceptionRepository;
use App\Services\Fleet\ChecklistActionFocusService;
use App\Services\Fleet\LocationClassificationService;
use App\Services\Fleet\OperationalMovementWorkService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class TripMovementChecklists extends BaseController
{
    public function show(int $id): string
    {
        $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $id) ?? ['exists' => false];
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $tripSchedule = ($checklist['exists'] ?? false)
            ? Services::operationalFactsRepository()->tripSchedule((int) $checklist['turo_trip_normalized_id'])
            : null;
        $tripIsOperational = ! ($checklist['exists'] ?? false)
            || ($tripSchedule !== null && Services::tripCommitmentService()->tripIsOperational($tripSchedule));
        $readiness = ($checklist['exists'] ?? false) && $companyId > 0
            ? (Services::movementReadinessReadService()->forCompany($companyId, [$id])[$id] ?? null)
            : null;
        $factsPresenter = Services::movementOperationalFactPresentationService();
        $tripFacts = ($checklist['exists'] ?? false) ? $factsPresenter->tripFacts((int) $checklist['turo_trip_normalized_id']) : ['pickup' => null, 'return' => null];
        $latestFacts = ($checklist['exists'] ?? false) ? $factsPresenter->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $latestEvent = ($checklist['exists'] ?? false) ? Services::movementEventService()->latestForTrip((int) $checklist['turo_trip_normalized_id']) : null;
        $guestReturn = ($checklist['exists'] ?? false) && ($checklist['movement_type'] ?? null) === 'return'
            ? Services::movementEventService()->activeForTrip((int) $checklist['turo_trip_normalized_id'], ['guest_return_staged'])
            : null;
        $guestReturnActive = $guestReturn !== null && ($checklist['exists'] ?? false)
            && (int) (Services::operationalFactsRepository()->latestActiveLifecycleEvent((int) $checklist['fleet_vehicle_id'])['id'] ?? 0) === (int) $guestReturn['id'];
        $returnCompleted = ($checklist['exists'] ?? false) && ($checklist['movement_type'] ?? null) === 'return'
            && Services::movementEventService()->activeForTrip((int) $checklist['turo_trip_normalized_id'], ['actual_return', 'vehicle_recovered']) !== null;
        $custody = ($checklist['exists'] ?? false) ? Services::operationalFactsRepository()->latestActiveLifecycleEvent((int) $checklist['fleet_vehicle_id']) : null;
        $canRecover = $tripIsOperational && ($checklist['movement_type'] ?? null) === 'return' && ! $returnCompleted
            && (int) ($custody['turo_trip_normalized_id'] ?? 0) === (int) ($checklist['turo_trip_normalized_id'] ?? 0)
            && in_array($custody['event_code'] ?? null, ['actual_handoff', 'guest_return_staged'], true);
        $recoveryExceptions = ($checklist['movement_type'] ?? null) === 'return' && $companyId > 0
            ? (new VehicleRecoveryExceptionRepository())->forTrip($companyId, (int) $checklist['turo_trip_normalized_id'])
            : [];
        $turnaroundWork = ['cleaning' => null, 'energy' => null];
        if (($checklist['movement_type'] ?? null) === 'return' && $companyId > 0 && $returnCompleted) {
            $work = new OperationalMovementWorkService();
            $vehicleId = (int) $checklist['fleet_vehicle_id'];
            $cleaning = array_column($work->cleaningNeedsForCompany($companyId, new \DateTimeImmutable()), null, 'fleet_vehicle_id');
            $energy = array_column($work->energyNeedsForCompany($companyId, new \DateTimeImmutable()), null, 'fleet_vehicle_id');
            $turnaroundWork = ['cleaning' => $cleaning[$vehicleId] ?? null, 'energy' => $energy[$vehicleId] ?? null];
        }
        $isStagedPickup = ($tripFacts['pickup']['event_code'] ?? null) === 'vehicle_staged';
        $handoffRequirement = array_values(array_filter(
            $readiness['requirements'] ?? [],
            static fn (array $requirement): bool => ($requirement['code'] ?? null) === 'guest_handoff',
        ))[0] ?? null;
        $isPickupConfirmed = ($handoffRequirement['status'] ?? null) === 'satisfied';
        $flashedFormData = CoreServices::session()->getFlashdata('movement_fact_data');
        $positionFormData = CoreServices::session()->getFlashdata('vehicle_position_data');
        $recoveryFormData = CoreServices::session()->getFlashdata('vehicle_recovery_data');
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
        $retroactiveHandoffData = CoreServices::session()->getFlashdata('retroactive_handoff_data');
        $commitmentPhases = ($checklist['movement_type'] ?? null) === 'return'
            ? ['return', 'entire_trip']
            : ['preparation', 'pickup', 'entire_trip'];
        $guestCommitments = ($checklist['exists'] ?? false) && $companyId > 0
            ? Services::tripCommitmentService()->activeForTrip(
                $companyId,
                (int) $checklist['turo_trip_normalized_id'],
                $commitmentPhases,
                $readiness['energy_rule'] ?? null,
            )
            : [];
        $currentTripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
        $nextTripId = (int) ($readiness['next_trip']['id'] ?? 0);
        $extraTripIds = array_values(array_filter([$currentTripId, $nextTripId]));
        $extraPreparationByTrip = ($checklist['exists'] ?? false) && $companyId > 0
            ? Services::tripExtraFulfillmentService()->forTrips($companyId, $extraTripIds)
            : [];
        $currentPhases = ($checklist['movement_type'] ?? null) === 'return' ? ['return', 'entire_trip'] : ['preparation', 'pickup', 'entire_trip'];
        $extraPreparation = array_map(static function (array $row) use ($currentPhases): array {
            if (! in_array((string) ($row['fulfillment_phase'] ?? ''), $currentPhases, true) && ! ($row['is_informational'] ?? false)) {
                $row['is_actionable'] = false;
            }

            return $row;
        }, $extraPreparationByTrip[$currentTripId] ?? []);
        foreach ($extraPreparationByTrip[$nextTripId] ?? [] as $row) {
            if (! in_array((string) ($row['fulfillment_phase'] ?? ''), ['preparation', 'pickup', 'entire_trip'], true) && ! ($row['is_informational'] ?? false)) {
                continue;
            }
            $row['is_next_trip'] = true;
            $extraPreparation[] = $row;
        }
        $locationClassifier = new LocationClassificationService();
        $recoveryLocationOptions = $locationClassifier->recoveryLocationOptions();
        $recoveryLocationPrefill = ($checklist['movement_type'] ?? null) === 'return'
            ? $locationClassifier->recoveryLocationFromPlannedReturn($tripSchedule['return_location_class'] ?? null)
            : null;
        $canRecordRetroactiveHandoff = $tripIsOperational && ($checklist['exists'] ?? false)
            && $tripFacts['pickup'] === null
            && $tripFacts['return'] === null
            && in_array($tripSchedule['trip_status_code'] ?? null, ['booked', 'in_progress'], true);
        $checklistNotice = session()->getFlashdata('movement_checklist_notice');
        $checklistError = session()->getFlashdata('movement_checklist_error');
        return view('trip_movement_checklists/show', [
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'checklist' => $checklist,
            'readiness' => $readiness,
            'currentLocation' => ($checklist['exists'] ?? false) ? Services::currentVehicleLocationService()->resolve((int) $checklist['fleet_vehicle_id']) : null,
            'tripContext' => ($checklist['exists'] ?? false) ? Services::operationalFactsRepository()->tripContext((int) $checklist['turo_trip_normalized_id']) : null,
            'latestFacts' => $selectedFacts,
            'tripFacts' => $tripFacts,
            'tripIsOperational' => $tripIsOperational,
            'tripStatusCode' => $tripSchedule['trip_status_code'] ?? null,
            'guestCommitments' => $guestCommitments,
            'extraPreparation' => $extraPreparation,
            'factTarget' => $factTarget,
            'latestEvent' => $latestEvent,
            'guestReturn' => $guestReturn,
            'guestReturnActive' => $guestReturnActive,
            'returnCompleted' => $returnCompleted,
            'canRecover' => $canRecover,
            'recoveryExceptions' => $recoveryExceptions,
            'turnaroundWork' => $turnaroundWork,
            'correctGuestReturn' => $this->request->getGet('correct_guest_return') === '1',
            'guestReturnFormData' => CoreServices::session()->getFlashdata('guest_return_data') ?: [],
            'recoveryFormData' => is_array($recoveryFormData) ? $recoveryFormData : [],
            'recoveryLocationOptions' => $recoveryLocationOptions,
            'recoveryLocationPrefill' => $recoveryLocationPrefill,
            'isStagedPickup' => $isStagedPickup,
            'isPickupConfirmed' => $isPickupConfirmed,
            'pickupConfirmedAt' => $handoffRequirement['basis_at'] ?? null,
            'correctingFacts' => $correctingFacts,
            'repairingFacts' => $repairingFacts,
            'repairCandidates' => $repairingFacts ? Services::movementOperationalFactService()->wrongTripCandidates($checklist, (int) $selectedFacts['event_id']) : [],
            'repairConflicts' => $repairingFacts ? Services::movementOperationalFactService()->wrongTripConflicts($checklist, (int) $selectedFacts['event_id']) : [],
            'factFormData' => $factFormData,
            'canRecordRetroactiveHandoff' => $canRecordRetroactiveHandoff,
            'showRetroactiveHandoffForm' => $canRecordRetroactiveHandoff
                && ($this->request->getGet('action') === 'record-handoff' || is_array($retroactiveHandoffData)),
            'retroactiveHandoffData' => is_array($retroactiveHandoffData) ? $retroactiveHandoffData : [],
            'isEarlyHandoffWarning' => $isEarlyHandoffWarning,
            'positionFormData' => is_array($positionFormData) ? $positionFormData : [],
            'showPositionForm' => $this->request->getGet('action') === 'position',
            'hnlGarages' => (new \App\Services\Fleet\HnlGarageCatalog())->definitions(),
            'notice' => $checklistNotice ?? CoreServices::session()->getFlashdata('vehicle_current_state_notice'),
            'error' => $checklistError ?? CoreServices::session()->getFlashdata('vehicle_current_state_error'),
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
        $item = Services::movementChecklistRepository()->itemForCompany($this->activeCompanyId(), $id);
        if ($item === null) {
            return CoreServices::redirectresponse()->to('/')->with('movement_checklist_error', 'Checklist item not found.');
        }
        if (($inactive = $this->inactiveMovementRedirect((int) $item['trip_movement_checklist_id'], (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']))) !== null) {
            return $inactive;
        }
        return $this->back((int) $item['trip_movement_checklist_id'], Services::tripMovementChecklistService()->completeItemForCompany($this->activeCompanyId(), $id, $this->request->getPost('note'), $this->actorUserId()), 'Item completed.', 'That checklist item could not be completed.', (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']));
    }

    public function undoItem(int $id): RedirectResponse
    {
        $item = Services::movementChecklistRepository()->itemForCompany($this->activeCompanyId(), $id);
        if ($item === null) {
            return CoreServices::redirectresponse()->to('/')->with('movement_checklist_error', 'Checklist item not found.');
        }
        if (($inactive = $this->inactiveMovementRedirect((int) $item['trip_movement_checklist_id'], (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']))) !== null) {
            return $inactive;
        }
        return $this->back((int) $item['trip_movement_checklist_id'], Services::tripMovementChecklistService()->undoItem($id, $this->actorUserId()), 'Item reopened.', 'That checklist item could not be reopened.', (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']));
    }

    public function markNotApplicable(int $id): RedirectResponse
    {
        $item = Services::movementChecklistRepository()->itemForCompany($this->activeCompanyId(), $id);
        if ($item === null) {
            return CoreServices::redirectresponse()->to('/')->with('movement_checklist_error', 'Checklist item not found.');
        }
        if (($inactive = $this->inactiveMovementRedirect((int) $item['trip_movement_checklist_id'], (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']))) !== null) {
            return $inactive;
        }
        return $this->back((int) $item['trip_movement_checklist_id'], Services::tripMovementChecklistService()->markNotApplicable($id, $this->request->getPost('note'), $this->actorUserId()), 'Item marked not applicable.', 'That checklist item could not be changed.', (new ChecklistActionFocusService())->actionAnchor((string) $item['item_code']));
    }

    public function completePhotos(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id, 'checklist-action-photos_complete')) !== null) {
            return $inactive;
        }
        return $this->back($id, Services::tripMovementChecklistService()->completePickupPhotos($id, $this->activeCompanyId(), $this->actorUserId()), 'Photos marked complete.', 'Pickup photos could not be completed.', 'checklist-action-photos_complete');
    }

    public function undoPhotos(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id, 'checklist-action-photos_complete')) !== null) {
            return $inactive;
        }
        return $this->back($id, Services::tripMovementChecklistService()->undoPickupPhotos($id, $this->activeCompanyId(), $this->actorUserId()), 'Photos reopened.', 'Pickup photos could not be reopened.', 'checklist-action-photos_complete');
    }

    public function confirmChargingAdapter(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id, 'checklist-action-charging_adapter_confirmed')) !== null) {
            return $inactive;
        }
        return $this->back($id, Services::tripMovementChecklistService()->confirmChargingAdapter($id, $this->activeCompanyId(), $this->actorUserId()), 'Charging adapter confirmed.', 'Charging adapter could not be confirmed.', 'checklist-action-charging_adapter_confirmed');
    }

    public function undoChargingAdapter(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id, 'checklist-action-charging_adapter_confirmed')) !== null) {
            return $inactive;
        }
        return $this->back($id, Services::tripMovementChecklistService()->undoChargingAdapter($id, $this->activeCompanyId(), $this->actorUserId()), 'Charging adapter reopened.', 'Charging adapter could not be reopened.', 'checklist-action-charging_adapter_confirmed');
    }

    public function setDisposition(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        return $this->back($id, Services::tripMovementChecklistService()->setDisposition($id, (string) $this->request->getPost('vehicle_disposition'), $this->actorUserId()), 'Exceptional hold saved.', 'Choose a valid exceptional hold.', 'exceptional-disposition');
    }

    public function complete(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $checklist = Services::tripMovementChecklistService()->checklist($id);
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $readiness = ($checklist['exists'] ?? false) && $companyId > 0
            ? (Services::movementReadinessReadService()->forCompany($companyId, [$id])[$id] ?? null)
            : null;

        return $this->back($id, Services::tripMovementChecklistService()->completeChecklist($id, $this->request->getPost('completion_note'), $this->actorUserId(), ($readiness['ready'] ?? false) === true), 'Movement workflow completed.', 'Complete current readiness actions before closing this workflow.');
    }

    public function reopen(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $confirmed = $this->request->getPost('confirm_reopen') === '1';
        return $this->back($id, $confirmed && Services::tripMovementChecklistService()->reopenChecklist($id, $this->actorUserId()), 'Movement workflow reopened.', 'Confirm before reopening a completed workflow.');
    }

    public function recordFacts(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->recordForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($id, $ok, 'Operational facts recorded.', 'That movement could not be recorded.', 'handoff-entry');
        } catch (EarlyHandoffConfirmationRequired $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=handoff#handoff-entry')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('movement_fact_data', $data)
                ->with('movement_early_handoff_warning', '1');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'handoff-entry')->with('movement_fact_data', $data);
        }
    }

    public function recordRetroactiveHandoff(int $tripId): RedirectResponse
    {
        $companyId = $this->activeCompanyId();
        $data = $this->request->getPost();
        $redirect = $this->tripMovementHrefForCompany($companyId, $tripId);

        try {
            Services::movementOperationalFactService()->recordRetroactiveHandoff($companyId, $tripId, $data, $this->actorUserId());

            return CoreServices::redirectresponse()->to($redirect . '#pickup-fact-heading')
                ->with('movement_checklist_notice', 'Guest handoff recorded.');
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return CoreServices::redirectresponse()->to($redirect . '?action=record-handoff#pickup-fact-heading')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('retroactive_handoff_data', $data);
        }
    }

    public function stageGuestReturn(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->request->getPost();
        try {
            $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $id);
            if ($checklist === null) {
                throw new \InvalidArgumentException('Return movement not found in the active company.');
            }
            $eventId = Services::movementOperationalFactService()->stageGuestReturn($checklist, $data, $this->actorUserId());

            return $this->back($id, $eventId > 0, 'Guest return reported. Vehicle is awaiting recovery.', 'Guest return report could not be recorded.', 'guest-return-entry');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'guest-return-entry')->with('guest_return_data', $data);
        }
    }

    public function recoverVehicle(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->request->getPost();
        try {
            $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $id);
            if ($checklist === null) {
                throw new \InvalidArgumentException('Return movement not found in the active company.');
            }
            $eventId = Services::movementOperationalFactService()->recoverVehicle($checklist, $data, $this->actorUserId());

            return $this->back($id, $eventId > 0, 'Vehicle recovered. Turnaround work is now actionable.', 'Vehicle recovery could not be recorded.', 'recover-vehicle-entry');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'recover-vehicle-entry')->with('vehicle_recovery_data', $data);
        }
    }

    public function resolveRecoveryException(int $id, int $exceptionId): RedirectResponse
    {
        try {
            $companyId = $this->activeCompanyId();
            $checklist = Services::tripMovementChecklistService()->checklistForCompany($companyId, $id);
            if ($checklist === null || ($checklist['movement_type'] ?? null) !== 'return') {
                throw new \InvalidArgumentException('Return movement not found in the active company.');
            }
            $note = trim((string) $this->request->getPost('resolution_note'));
            if (mb_strlen($note) > 2000) {
                throw new \InvalidArgumentException('Resolution note must be 2000 characters or fewer.');
            }
            $ok = (new VehicleRecoveryExceptionRepository())->resolveForCompany(
                $companyId,
                (int) $checklist['turo_trip_normalized_id'],
                (int) $checklist['fleet_vehicle_id'],
                $exceptionId,
                $this->actorUserId(),
                $note === '' ? null : $note,
            );

            return $this->back($id, $ok, 'Recovery exception resolved.', 'Recovery exception could not be resolved.', 'recovery-exceptions');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'recovery-exceptions');
        }
    }

    public function voidRecoveredVehicle(int $id): RedirectResponse
    {
        try {
            $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $id);
            if ($checklist === null) {
                throw new \InvalidArgumentException('Return movement not found in the active company.');
            }
            $ok = Services::movementOperationalFactService()->voidRecoveredVehicle(
                $checklist,
                (int) $this->request->getPost('event_id'),
                $this->actorUserId(),
                (string) $this->request->getPost('void_reason'),
            );

            return $this->back($id, $ok, 'Vehicle recovery voided with audit history preserved.', 'Vehicle recovery could not be voided.', 'recover-vehicle-entry');
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'recover-vehicle-entry');
        }
    }

    public function correctGuestReturn(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        try {
            $event = $this->scopedGuestReturnEvent($id, (int) ($data['event_id'] ?? 0));
            $reason = trim((string) ($data['correction_reason'] ?? ''));
            $time = trim((string) ($data['reported_parked_at'] ?? ''));
            if ($time !== '') {
                try {
                    $parsedTime = new \DateTimeImmutable($time);
                } catch (\Exception) {
                    throw new \InvalidArgumentException('Choose a valid guest-reported parked time.');
                }
                if ($parsedTime > new \DateTimeImmutable()) {
                    throw new \InvalidArgumentException('Guest-reported parked time cannot be in the future.');
                }
            }
            $replacement = [
                'occurred_at' => $time === '' ? $event['occurred_at'] : $time,
                'location_class' => 'airport_hnl',
                'location_detail' => '',
                'airport_garage_code' => $data['airport_garage_code'] ?? $event['airport_garage_code'],
                'airport_parking_level' => $data['airport_parking_level'] ?? $event['airport_parking_level'],
                'airport_parking_row' => $data['airport_parking_row'] ?? $event['airport_parking_row'],
                'source' => $time === '' ? $event['source'] : 'guest_reported_parked_time',
                'note' => $data['note'] ?? $event['note'],
            ];
            Services::movementEventService()->correct((int) $event['id'], $replacement, $this->actorUserId(), $reason);

            return $this->back($id, true, 'Guest return report corrected.', '', 'guest-return-entry');
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'guest-return-entry');
        }
    }

    public function voidGuestReturn(int $id): RedirectResponse
    {
        try {
            $event = $this->scopedGuestReturnEvent($id, (int) $this->request->getPost('event_id'));
            $ok = Services::movementEventService()->void((int) $event['id'], $this->actorUserId(), (string) $this->request->getPost('void_reason'));

            return $this->back($id, $ok, 'Guest return report voided.', 'A reason is required to void this report.', 'guest-return-entry');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'guest-return-entry');
        }
    }

    /** @return array<string, mixed> */
    private function scopedGuestReturnEvent(int $checklistId, int $eventId): array
    {
        $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $checklistId);
        $event = $eventId > 0 ? Services::movementEventService()->find($eventId) : null;
        if ($checklist === null || ($checklist['movement_type'] ?? null) !== 'return' || $event === null
            || $event['event_code'] !== 'guest_return_staged' || $event['voided_at'] !== null
            || (int) $event['company_id'] !== $this->activeCompanyId()
            || (int) $event['fleet_vehicle_id'] !== (int) $checklist['fleet_vehicle_id']
            || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']) {
            throw new \InvalidArgumentException('Guest return report not found in the active company.');
        }

        return $event;
    }

    public function stageAtHnl(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($id, $ok, 'Vehicle staged at HNL. Guest pickup is not yet confirmed.', 'That vehicle could not be staged.', 'handoff-entry');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'handoff-entry')->with('movement_fact_data', $data);
        }
    }

    public function confirmGuestPickup(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->confirmGuestPickup(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            return $this->back($id, $ok, 'Guest pickup confirmed.', 'Guest pickup could not be confirmed.', 'handoff-entry');
        } catch (EarlyHandoffConfirmationRequired $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=confirm-pickup#handoff-entry')
                ->with('movement_checklist_error', $exception->getMessage())
                ->with('movement_fact_data', $data)
                ->with('movement_early_handoff_warning', '1');
        } catch (\InvalidArgumentException $exception) {
            return $this->back($id, false, '', $exception->getMessage(), 'handoff-entry')->with('movement_fact_data', $data);
        }
    }

    public function recordVehiclePosition(int $id): RedirectResponse
    {
        if (($inactive = $this->inactiveMovementRedirect($id)) !== null) {
            return $inactive;
        }
        $data = $this->movementFactData();
        try {
            $ok = Services::movementOperationalFactService()->recordVehiclePosition(Services::tripMovementChecklistService()->checklist($id), $data, $this->actorUserId());
            if ($ok) {
                return $this->back($id, true, 'Current vehicle position recorded.', '', 'vehicle-position');
            }

            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=position#position-entry')
                ->with('movement_checklist_error', 'That vehicle position could not be recorded.')
                ->with('vehicle_position_data', $data);
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to('/operations/checklists/' . $id . '?action=position#position-entry')
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
            $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $id);
            if ($checklist === null) {
                throw new \InvalidArgumentException('Movement not found in the active company.');
            }
            $ok = Services::movementOperationalFactService()->correctForChecklist($checklist, $data, $this->actorUserId());
            if ($ok) {
                return $this->back($id, true, 'Recorded facts corrected.', '');
            }

            return CoreServices::redirectresponse()->to($correctionHref . '#handoff-entry')->with('movement_checklist_error', 'Those recorded facts could not be corrected.')->with('movement_fact_data', $data);
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to($correctionHref . '#handoff-entry')->with('movement_checklist_error', $exception->getMessage())->with('movement_fact_data', $data);
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
                return $this->back($id, true, 'Recorded facts moved to the correct trip.', '');
            }

            return CoreServices::redirectresponse()->to($repairHref . '#handoff-entry')->with('movement_checklist_error', 'Those facts could not be repaired.');
        } catch (\InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->to($repairHref . '#handoff-entry')->with('movement_checklist_error', $exception->getMessage());
        }
    }

    private function inactiveMovementRedirect(int $checklistId, string $anchor = 'historical-workflow'): ?RedirectResponse
    {
        $checklist = Services::tripMovementChecklistService()->checklistForCompany($this->activeCompanyId(), $checklistId);
        if ($checklist === null) {
            return null;
        }
        $trip = Services::operationalFactsRepository()->tripSchedule((int) $checklist['turo_trip_normalized_id']);
        if ($trip !== null && Services::tripCommitmentService()->tripIsOperational($trip)) {
            return null;
        }

        return CoreServices::redirectresponse()->to('/operations/checklists/' . $checklistId . '#' . $anchor)
            ->with('movement_checklist_error', 'This trip is canceled or invalid. Operational movement changes are not applicable.');
    }

    private function back(int $checklistId, bool $ok, string $notice, string $error, string $attemptedAnchor = 'readiness-heading'): RedirectResponse
    {
        $anchor = $attemptedAnchor;
        if ($ok) {
            $checklist = Services::tripMovementChecklistService()->checklist($checklistId);
            $companyId = (int) ($checklist['company_id'] ?? 0);
            $readiness = $companyId > 0
                ? (Services::movementReadinessReadService()->forCompany($companyId, [$checklistId])[$checklistId] ?? null)
                : null;
            $anchor = $readiness === null ? 'readiness-heading' : (new ChecklistActionFocusService())->nextAnchor($readiness);
        }

        return CoreServices::redirectresponse()->to('/operations/checklists/' . $checklistId . '#' . $anchor)
            ->with($ok ? 'movement_checklist_notice' : 'movement_checklist_error', $ok ? $notice : $error);
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

    private function tripMovementHrefForCompany(int $companyId, int $tripId): string
    {
        $trip = Services::operationalFactsRepository()->trip($tripId);
        if ($trip === null || (int) ($trip['company_id'] ?? 0) !== $companyId) {
            return '/';
        }

        return Services::operationalFactsRepository()->movementChecklistHref($tripId, 'return')
            ?? Services::operationalFactsRepository()->movementChecklistHref($tripId)
            ?? '/';
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
