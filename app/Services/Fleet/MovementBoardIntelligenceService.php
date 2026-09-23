<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use App\Repositories\VehicleRecoveryExceptionRepository;
use Config\Services;

class MovementBoardIntelligenceService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly ?NextConfirmedTripService $nextTripService = null,
        private readonly ?ImportFreshnessService $freshnessService = null,
        private readonly ?MovementStateResolver $stateResolver = null,
        private readonly ?VehiclePositioningRecommendationService $positioningService = null,
        private readonly ?VehiclePositioningPlanService $positioningPlanService = null,
        private readonly HnlGarageCatalog $hnlGarages = new HnlGarageCatalog(),
        private readonly ?TripCommitmentService $tripCommitmentService = null,
        private readonly ?TripEnergyRuleResolver $energyRuleResolver = null,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function enrich(array $cards, ?\DateTimeImmutable $asOf = null, ?int $companyId = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $vehicleIds = array_values(array_unique(array_map(static fn (array $card): int => (int) ($card['fleet_vehicle_id'] ?? 0), $cards)));
        $cleanliness = $companyId !== null && $companyId > 0
            ? $this->repo()->latestCleanlinessForCompany($companyId, $vehicleIds, $asOf->format('Y-m-d H:i:s'))
            : [];
        $energy = $companyId !== null && $companyId > 0
            ? $this->repo()->latestEnergyForCompany($companyId, $vehicleIds, $asOf->format('Y-m-d H:i:s'))
            : [];
        $work = new OperationalMovementWorkService($this->repo(), $this->nextTrips(), $this->energyRules());
        $cleaningNeeds = $companyId !== null && $companyId > 0
            ? array_column($work->cleaningNeedsForCompany($companyId, $asOf), null, 'fleet_vehicle_id')
            : [];
        $energyNeeds = $companyId !== null && $companyId > 0
            ? array_column($work->energyNeedsForCompany($companyId, $asOf), null, 'fleet_vehicle_id')
            : [];
        $exceptionsByVehicle = [];
        if ($companyId !== null && $companyId > 0) {
            foreach ((new VehicleRecoveryExceptionRepository())->openForCompany($companyId) as $exception) {
                $exceptionsByVehicle[(int) $exception['fleet_vehicle_id']][] = $exception;
            }
        }

        return array_map(fn (array $card): array => $this->enrichCard(
            $card,
            $asOf,
            $cleanliness[(int) ($card['fleet_vehicle_id'] ?? 0)] ?? null,
            $energy[(int) ($card['fleet_vehicle_id'] ?? 0)] ?? null,
            $cleaningNeeds[(int) ($card['fleet_vehicle_id'] ?? 0)] ?? null,
            $energyNeeds[(int) ($card['fleet_vehicle_id'] ?? 0)] ?? null,
            $companyId,
            $exceptionsByVehicle[(int) ($card['fleet_vehicle_id'] ?? 0)] ?? [],
        ), $cards);
    }

    /** @return array<string, mixed> */
    private function enrichCard(array $card, \DateTimeImmutable $asOf, ?array $latestCleanliness, ?array $latestEnergy, ?array $cleaningNeed, ?array $energyNeed, ?int $companyId, array $recoveryExceptions = []): array
    {
        $vehicleId = (int) ($card['fleet_vehicle_id'] ?? 0);
        $event = $this->repo()->latestActiveMovementEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        $latestLifecycleEvent = $this->repo()->latestActiveLifecycleEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        $commitment = $this->activeOperationalCommitment($latestLifecycleEvent, $card, $asOf);
        $lifecycleEvent = $commitment['event'];
        $awaitingRecovery = ($lifecycleEvent['event_code'] ?? null) === 'guest_return_staged';
        $tripId = $commitment['trip_id'];
        $schedule = $commitment['schedule'];
        $assessment = $this->repo()->assessmentForEventOrTrip(isset($lifecycleEvent['id']) ? (int) $lifecycleEvent['id'] : null, $tripId);
        if ($awaitingRecovery) {
            $assessment = null;
        }
        if (in_array($lifecycleEvent['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && $latestCleanliness !== null
            && (string) ($latestCleanliness['captured_at'] ?? '') > (string) ($lifecycleEvent['occurred_at'] ?? '')
            && (string) ($latestCleanliness['captured_at'] ?? '') >= (string) ($assessment['captured_at'] ?? '')) {
            $assessment = array_merge($assessment ?? [], ['cleanliness' => $latestCleanliness['cleanliness']]);
        }
        if (in_array($lifecycleEvent['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && $latestEnergy !== null
            && ((int) ($latestEnergy['trip_movement_event_id'] ?? 0) === (int) ($lifecycleEvent['id'] ?? 0)
                || (string) $latestEnergy['captured_at'] > (string) $lifecycleEvent['occurred_at'])
            && (string) $latestEnergy['captured_at'] >= (string) ($assessment['captured_at'] ?? '')) {
            $assessment = array_merge($assessment ?? [], ['energy_percent' => $latestEnergy['energy_percent']]);
        }
        $cleaningRequired = $cleaningNeed !== null || ($companyId === null
            && in_array($lifecycleEvent['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && (($assessment['cleanliness'] ?? null) !== 'clean'
                || (string) ($assessment['captured_at'] ?? '') <= (string) $lifecycleEvent['occurred_at']));
        $profile = $this->repo()->profile($vehicleId) ?? $this->emptyProfile();
        $nextTrip = $this->nextTrips()->forVehicle($vehicleId, $asOf);
        $freshness = $this->freshness()->assess($nextTrip['import_completed_at'] ?? $schedule['import_completed_at'] ?? null, $asOf);
        $location = $this->positionBasis($lifecycleEvent ?? $event, $schedule, $card['current_position'] ?? null);
        $blockers = $this->blockers($card);
        if ($location['basis'] === 'actual' && $location['approved_turo_garage'] === false) {
            $blockers[] = ['code' => 'wrong_airport_garage', 'label' => 'Wrong airport garage - recovery / relocation required', 'severity' => 'critical'];
        }
        $state = $this->states()->resolve([
            'operational_status' => $card['status'] ?? $card['primary_status'] ?? 'available',
            'latest_event' => $lifecycleEvent,
            'trip_schedule' => $schedule,
            'assessment' => $assessment,
            'cleaning_required' => $cleaningRequired,
            ...($companyId !== null ? ['energy_condition' => $energyNeed['condition_code'] ?? null] : []),
            ...($companyId !== null ? ['energy_rule' => $energyNeed['energy_rule'] ?? $this->energyRules()->forProfile($profile)] : []),
            ...($companyId !== null ? ['energy_action_label' => $energyNeed['action_label'] ?? null] : []),
            'profile' => $profile,
            'next_trip' => $nextTrip,
            'blockers' => $blockers,
            'critical_blocker_count' => (int) ($card['readiness_blocking_remaining'] ?? 0),
        ], $asOf);
        $activePlan = $this->plans()->active($vehicleId, $event, $nextTrip, $asOf);
        $usablePlan = $activePlan !== null && ! (bool) ($activePlan['is_basis_stale'] ?? true) ? $activePlan : null;
        $recommendation = $this->positioning()->recommend([
            'awaiting_recovery' => $awaitingRecovery,
            'guest_possession' => ($lifecycleEvent['event_code'] ?? null) === 'actual_handoff',
            'basis_location_class' => $location['class'],
            'basis_type' => $location['basis'],
            'basis_airport_garage_code' => $location['airport_garage_code'],
            'next_trip' => $nextTrip,
            'freshness' => $freshness,
            'assessment' => $assessment,
            'cleaning_required' => $cleaningRequired,
            'profile' => $profile,
            'energy_condition' => $energyNeed['condition_code'] ?? null,
            'capabilities' => $profile['capabilities'] ?? [],
            'blockers' => $state['blockers'],
            'transportation_state' => $card['transportation_state'] ?? $usablePlan['transportation_state'] ?? 'unknown',
            'active_override' => $activePlan,
        ]);
        $energyKind = (string) ($profile['energy_kind'] ?? 'unknown');
        $recommendation = $this->presentRecommendation($recommendation, $assessment, $profile, $cleaningRequired, $energyNeed);
        $currentTrip = $this->presentCurrentTrip($schedule, (string) $state['code']);
        $currentMovementHref = $currentTrip === null ? null : $this->movementHref($state, $card, $schedule);
        $readinessRemaining = (int) ($card['readiness_blocking_remaining'] ?? 0);
        if (($card['turnaround'] ?? null) !== null) {
            $readinessRemaining += (int) ($card['turnaround_readiness_remaining'] ?? 0);
        }
        $compactReadiness = $this->compactReadiness($card, $readinessRemaining);
        if ($awaitingRecovery) {
            $compactReadiness = ['blocking_count' => 0, 'additional_count' => 0, 'summary' => 'Physical preparation resumes after recovery', 'next_actions' => []];
            $readinessRemaining = 0;
        }
        $lifecycleCode = (string) ($lifecycleEvent['event_code'] ?? '');
        $commitmentTripId = match (true) {
            in_array($lifecycleCode, ['actual_handoff', 'guest_return_staged'], true) => (int) ($tripId ?? 0),
            ($nextTrip['planning_horizon'] ?? 'distant') !== 'distant' => (int) ($nextTrip['id'] ?? 0),
            in_array($lifecycleCode, ['actual_return', 'vehicle_recovered'], true) => 0,
            default => (int) ($tripId ?? 0),
        };
        $commitmentPhases = in_array($lifecycleCode, ['actual_handoff', 'guest_return_staged'], true)
            ? ['return', 'entire_trip']
            : ['preparation', 'pickup', 'entire_trip'];
        $commitmentEnergyRule = $companyId !== null && $companyId > 0 && $commitmentTripId > 0
            ? $this->energyRules()->forTrip($companyId, $commitmentTripId, $profile)
            : null;
        $guestCommitments = $companyId !== null && $companyId > 0 && $commitmentTripId > 0 && $this->tripCommitmentService !== null
            ? $this->tripCommitmentService->activeForTrip($companyId, $commitmentTripId, $commitmentPhases, $commitmentEnergyRule)
            : [];
        $guestCommitmentPreview = array_slice($guestCommitments, 0, 2);
        $hasCustodyFact = in_array($lifecycleCode, ['actual_handoff', 'guest_return_staged', 'actual_return', 'vehicle_recovered'], true);
        $usesResolvedState = $hasCustodyFact || in_array($state['code'], ['pickup_confirmation_overdue', 'return_confirmation_overdue'], true);
        $primaryStatus = $usesResolvedState ? match ($state['code']) {
            'on_trip' => 'currently_rented',
            'return_confirmation_overdue' => 'late_return',
            'ready' => 'available',
            default => (string) $state['code'],
        } : ($card['primary_status'] ?? null);
        $flags = $card['flags'] ?? [];
        if ($lifecycleCode === 'actual_handoff') {
            $flags = array_values(array_unique([...$flags, 'currently_rented']));
        } elseif ($state['code'] === 'pickup_confirmation_overdue') {
            $flags = array_values(array_diff($flags, ['currently_rented']));
        } elseif ($awaitingRecovery) {
            $flags = array_values(array_diff($flags, ['currently_rented', 'cleaning_required', 'charging_required', 'energy_check_required', 'late_return', 'returning_today']));
        } elseif (in_array($lifecycleCode, ['actual_return', 'vehicle_recovered'], true)) {
            $flags = array_values(array_diff($flags, ['currently_rented', 'late_return', 'returning_today']));
            $flags = array_values(array_diff($flags, ['cleaning_required', 'charging_required', 'energy_check_required']));
            if ($cleaningRequired) {
                $flags[] = 'cleaning_required';
            }
            if (($energyNeed['condition_code'] ?? null) === 'charge_required') {
                $flags[] = 'charging_required';
            } elseif (($energyNeed['condition_code'] ?? null) === 'measurement_needed') {
                $flags[] = 'energy_check_required';
            } elseif (($energyNeed['condition_code'] ?? null) === 'above_maximum') {
                $flags[] = 'energy_attention_required';
            }
        }

        $projectedEnergyAction = array_intersect($card['flags'] ?? [], ['charging_required', 'energy_check_required', 'energy_attention_required']) !== [];

        return array_merge($card, [
            'recovery_exceptions' => $recoveryExceptions,
            'primary_status' => $primaryStatus,
            'primary_status_label' => $usesResolvedState ? $state['label'] : ($card['primary_status_label'] ?? null),
            'status_tone' => $usesResolvedState ? $state['tone'] : ($card['status_tone'] ?? null),
            'state' => $state,
            'primary_line' => $state['primary_line'],
            'location' => $location,
            'location_heading' => $location['heading'],
            'location_class' => $location['class'],
            'location_class_label' => $this->locationClassLabel($location['class']),
            'location_detail' => $location['detail'],
            'airport_garage_line' => $location['garage_line'],
            'airport_position_line' => $location['position_line'],
            'airport_location_label' => $location['location_label'],
            'approved_turo_garage' => $location['approved_turo_garage'],
            'location_basis' => $location['basis'],
            'guest_reported_at_label' => $awaitingRecovery ? (new \DateTimeImmutable((string) $lifecycleEvent['occurred_at']))->format('M j, g:i A') : null,
            'guest_report_time_basis' => $awaitingRecovery ? ($lifecycleEvent['source'] ?? null) : null,
            'current_trip' => $currentTrip,
            'current_movement_href' => $currentMovementHref,
            'next_trip' => $this->presentNextTrip($nextTrip),
            'condition_label' => $assessment === null || ($assessment['cleanliness'] ?? null) === null ? 'Condition not captured' : ucfirst((string) $assessment['cleanliness']),
            'energy_label' => $energyKind === 'electric' ? 'Charge' : (in_array($energyKind, ['gasoline', 'diesel', 'hybrid'], true) ? 'Fuel' : 'Energy'),
            'energy_value' => $assessment === null || ($assessment['energy_percent'] ?? null) === null ? 'Not captured' : (int) $assessment['energy_percent'] . '%',
            'blockers' => $state['blockers'],
            'readiness_compact' => $compactReadiness,
            'readiness_summary' => $compactReadiness['summary'],
            'readiness_display_remaining' => $readinessRemaining,
            'readiness_blocking_remaining' => $readinessRemaining,
            'readiness_additional_remaining' => $awaitingRecovery ? 0 : ($card['readiness_additional_remaining'] ?? 0),
            'readiness_blockers' => $awaitingRecovery ? [] : ($card['readiness_blockers'] ?? []),
            'flags' => $flags,
            'actions' => $awaitingRecovery ? ['Recover Vehicle'] : array_values(array_unique(array_merge(
                $card['actions'] ?? [],
                $cleaningRequired ? ['Cleaning Required'] : [],
                $energyNeed === null || $projectedEnergyAction ? [] : [(string) $energyNeed['label']],
            ))),
            'cleaning_status_label' => $awaitingRecovery ? 'Not actionable until recovery' : ($cleaningRequired ? 'Cleaning required after return' : 'No cleaning task known'),
            'charging_status_label' => $awaitingRecovery ? 'Not actionable until recovery' : ($energyNeed['label'] ?? 'No Charge/Fuel action needed'),
            'recommendation' => $recommendation,
            'operator_plan' => $this->presentOperatorPlan($activePlan, (string) ($recommendation['code'] ?? '')),
            'positioning_plan_href' => '/fleet/vehicles/' . $vehicleId . '/positioning-plan',
            'freshness' => $freshness,
            'action' => $this->presentAction($state, $card, $vehicleId, $schedule),
            'guest_commitments' => [
                'trip_id' => $commitmentTripId > 0 ? $commitmentTripId : null,
                'count' => count($guestCommitments),
                'preview' => $guestCommitmentPreview,
                'required' => array_any($guestCommitments, static fn (array $row): bool => (bool) ($row['is_blocking'] ?? false)),
                'href' => $commitmentTripId > 0 ? '/operations/trips/' . $commitmentTripId . '/commitments' : null,
            ],
        ]);
    }

    /** @return array{event:?array,trip_id:?int,schedule:?array} */
    private function activeOperationalCommitment(?array $event, array $card, \DateTimeImmutable $asOf): array
    {
        if (in_array($event['event_code'] ?? null, ['actual_handoff', 'guest_return_staged', 'vehicle_staged'], true)
            && isset($event['turo_trip_normalized_id'])) {
            $tripId = (int) $event['turo_trip_normalized_id'];

            return ['event' => $event, 'trip_id' => $tripId, 'schedule' => $this->repo()->tripSchedule($tripId)];
        }

        $pickup = $card['pickup'] ?? null;
        $pickupId = (int) ($pickup['id'] ?? $pickup['turo_trip_normalized_id'] ?? 0);
        $pickupSchedule = $pickupId > 0 ? ($this->repo()->tripSchedule($pickupId) ?? $pickup) : null;
        $pickupAt = trim((string) ($pickupSchedule['starts_at'] ?? ''));
        if ($pickupId > 0 && $pickupAt !== '' && new \DateTimeImmutable($pickupAt) <= $asOf
            && $this->repo()->activeMovementConflict($pickupId, ['actual_handoff', 'actual_return', 'vehicle_recovered']) === null) {
            return ['event' => null, 'trip_id' => $pickupId, 'schedule' => $pickupSchedule];
        }

        $return = $card['return'] ?? null;
        $returnId = (int) ($return['id'] ?? $return['turo_trip_normalized_id'] ?? 0);
        $returnSchedule = $returnId > 0 ? ($this->repo()->tripSchedule($returnId) ?? $return) : null;
        $returnAt = trim((string) ($returnSchedule['ends_at'] ?? ''));
        if ($returnId > 0 && $returnAt !== '' && new \DateTimeImmutable($returnAt) <= $asOf
            && $this->repo()->activeMovementConflict($returnId, ['actual_return', 'vehicle_recovered']) === null) {
            return ['event' => null, 'trip_id' => $returnId, 'schedule' => $returnSchedule];
        }

        if (in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && isset($event['turo_trip_normalized_id'])) {
            $tripId = (int) $event['turo_trip_normalized_id'];

            return ['event' => $event, 'trip_id' => $tripId, 'schedule' => $this->repo()->tripSchedule($tripId)];
        }

        if ($event !== null) {
            return ['event' => $event, 'trip_id' => null, 'schedule' => null];
        }

        foreach ([$return, $pickup] as $movement) {
            $movementId = (int) ($movement['id'] ?? $movement['turo_trip_normalized_id'] ?? 0);
            if ($movementId > 0) {
                return ['event' => null, 'trip_id' => $movementId, 'schedule' => $this->repo()->tripSchedule($movementId) ?? $movement];
            }
        }

        return ['event' => null, 'trip_id' => null, 'schedule' => null];
    }

    /** @return array{heading:string,class:string,detail:?string,basis:string,airport_garage_code:?string,garage_line:?string,position_line:?string,location_label:?string,approved_turo_garage:?bool} */
    private function positionBasis(?array $event, ?array $schedule, ?array $currentPosition): array
    {
        if (($event['event_code'] ?? null) === 'guest_return_staged') {
            return array_merge($this->eventAirportParking($event), [
                'heading' => 'Guest-reported location — unverified',
                'class' => (string) ($event['location_class'] ?? 'unknown'),
                'detail' => $this->nullableText($event['location_detail'] ?? null),
                'basis' => 'guest_reported',
            ]);
        }
        if (($event['event_code'] ?? null) === 'actual_handoff') {
            return array_merge($this->emptyAirportParking(), [
                'heading' => 'Planned return',
                'class' => (string) ($schedule['return_location_class'] ?? 'unknown'),
                'detail' => $this->nullableText($schedule['return_location_source_text'] ?? null),
                'basis' => 'scheduled',
            ]);
        }
        if (($currentPosition['position_semantics'] ?? null) === 'current') {
            return array_merge($this->eventAirportParking($currentPosition), [
                'heading' => 'Current location',
                'class' => (string) ($currentPosition['location_class'] ?? 'unknown'),
                'detail' => $this->nullableText($currentPosition['location_detail'] ?? null),
                'basis' => 'actual',
            ]);
        }
        if (($event['event_code'] ?? null) === 'actual_return') {
            return array_merge($this->eventAirportParking($event), [
                'heading' => 'Current location',
                'class' => (string) ($event['location_class'] ?? 'unknown'),
                'detail' => $this->nullableText($event['location_detail'] ?? null),
                'basis' => 'actual',
            ]);
        }
        if ($event !== null && ($event['location_class'] ?? null) !== null) {
            return array_merge($this->eventAirportParking($event), [
                'heading' => 'Last known location',
                'class' => (string) $event['location_class'],
                'detail' => $this->nullableText($event['location_detail'] ?? null),
                'basis' => 'actual',
            ]);
        }
        return array_merge($this->emptyAirportParking(), [
            'heading' => 'Planned pickup location',
            'class' => (string) ($schedule['pickup_location_class'] ?? 'unknown'),
            'detail' => $this->nullableText($schedule['pickup_location_source_text'] ?? null),
            'basis' => $schedule === null ? 'unknown' : 'scheduled',
        ]);
    }

    /** @return array{airport_garage_code:?string,garage_line:?string,position_line:?string,location_label:?string,approved_turo_garage:?bool} */
    private function eventAirportParking(array $event): array
    {
        $parking = null;
        try {
            if (($event['airport_garage_code'] ?? null) !== null) {
                $parking = $this->hnlGarages->validate($event['airport_garage_code'], $event['airport_parking_level'] ?? null, $event['airport_parking_row'] ?? null);
            }
        } catch (\InvalidArgumentException) {
            $parking = null;
        }
        $parking ??= $this->hnlGarages->parseLegacyDetail($event['location_detail'] ?? null);
        if ($parking === null) {
            return $this->emptyAirportParking();
        }
        $presentation = $this->hnlGarages->presentation($parking['garage_code'], $parking['level'], $parking['row']);

        return [
            'airport_garage_code' => $parking['garage_code'],
            'garage_line' => $presentation['garage_line'] ?? null,
            'position_line' => $presentation['position_line'] ?? null,
            'location_label' => $presentation['location_label'] ?? null,
            'approved_turo_garage' => $presentation['approved_turo_garage'] ?? null,
        ];
    }

    /** @return array{airport_garage_code:null,garage_line:null,position_line:null,location_label:null,approved_turo_garage:null} */
    private function emptyAirportParking(): array
    {
        return ['airport_garage_code' => null, 'garage_line' => null, 'position_line' => null, 'location_label' => null, 'approved_turo_garage' => null];
    }

    /** @return array<int, array<string, string>> */
    private function blockers(array $card): array
    {
        $blockers = array_map(static function (array $requirement): array {
            return [
                'code' => (string) ($requirement['code'] ?? 'readiness_action'),
                'label' => (string) ($requirement['action']['label'] ?? $requirement['label'] ?? 'Complete readiness action'),
                'severity' => 'critical',
                'href' => $requirement['href'] ?? null,
            ];
        }, $card['readiness_blockers'] ?? []);
        $deduplicated = [];
        foreach ($blockers as $blocker) {
            $deduplicated[$blocker['code']] ??= $blocker;
        }
        if (in_array('maintenance_required', $card['flags'] ?? [], true)) {
            $deduplicated['maintenance_required'] = ['code' => 'maintenance_required', 'label' => 'Maintenance required', 'severity' => 'critical'];
        }
        return array_values($deduplicated);
    }

    /** @return array{blocking_count:int,additional_count:int,summary:string,next_actions:list<array{code:string,label:string,href:?string}>} */
    private function compactReadiness(array $card, int $blockingRemaining): array
    {
        $additional = (int) ($card['readiness_additional_remaining'] ?? 0);
        $tripPreparationRequirements = array_values(array_filter(
            $card['readiness_blockers'] ?? [],
            static fn (array $requirement): bool => str_starts_with((string) ($requirement['code'] ?? ''), 'extra_fulfillment_'),
        ));
        $tripPreparationCount = count($tripPreparationRequirements);
        $nextActions = [];
        foreach ($card['readiness_blockers'] ?? [] as $requirement) {
            $label = trim((string) ($requirement['action']['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $nextActions[] = [
                'code' => (string) ($requirement['code'] ?? 'readiness_action'),
                'label' => $label,
                'href' => isset($requirement['href']) ? (string) $requirement['href'] : null,
            ];
            break;
        }

        if (($card['checklists'] ?? []) === []) {
            $summary = 'No movement workflow today';
        } elseif ($blockingRemaining === 0) {
            $summary = (($card['turnaround'] ?? null) !== null ? 'Ready for next pickup' : 'Ready');
        } else {
            $summary = $blockingRemaining . ' action' . ($blockingRemaining === 1 ? '' : 's') . ' across today\'s movements'
                . ($additional > 0 ? ' · ' . $additional . ' additional' : '');
        }

        return [
            'blocking_count' => $blockingRemaining,
            'additional_count' => $additional,
            'summary' => $summary,
            'next_actions' => $nextActions,
            'trip_preparation_count' => $tripPreparationCount,
            'trip_preparation_items' => array_slice(array_map(
                static fn (array $requirement): string => (string) ($requirement['label'] ?? 'Purchased Extra'),
                $tripPreparationRequirements,
            ), 0, 3),
        ];
    }

    /** @return array{id:int,guest_name:string,timing_label:?string}|null */
    private function presentCurrentTrip(?array $schedule, string $stateCode): ?array
    {
        $guestName = $this->nullableText($schedule['guest_name'] ?? null);
        if ($schedule === null || $guestName === null) {
            return null;
        }

        $timing = match ($stateCode) {
            'on_trip', 'return_confirmation_overdue' => ['Due', $schedule['ends_at'] ?? null],
            'awaiting_recovery' => ['Scheduled return', $schedule['ends_at'] ?? null],
            'staged_for_pickup' => ['Pickup', $schedule['starts_at'] ?? null],
            'staged_pickup_confirmation_needed', 'pickup_confirmation_overdue' => ['Scheduled', $schedule['starts_at'] ?? null],
            default => null,
        };
        if ($timing === null) {
            return null;
        }

        return [
            'id' => (int) ($schedule['id'] ?? 0),
            'guest_name' => $guestName,
            'timing_label' => $timing[1] === null ? null : $timing[0] . ' ' . (new \DateTimeImmutable((string) $timing[1]))->format('M j, g:i A'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentNextTrip(?array $trip): ?array
    {
        if ($trip === null) {
            return null;
        }
        return array_merge($trip, [
            'starts_at_label' => (new \DateTimeImmutable((string) $trip['starts_at']))->format('M j, g:i A'),
            'pickup_location_label' => $this->locationClassLabel((string) ($trip['pickup_location_class'] ?? 'unknown')),
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function locationClassLabel(string $code): string
    {
        return [
            'airport_hnl' => 'Airport HNL',
            'waikiki_hotel' => 'Waikiki Hotel',
            'home' => 'Home',
            'other_delivery' => 'Other delivery',
            'unknown' => 'Unknown',
        ][$code] ?? 'Unknown';
    }

    /** @return array<string, mixed> */
    private function presentRecommendation(array $recommendation, ?array $assessment, array $profile, bool $cleaningRequired = false, ?array $energyNeed = null): array
    {
        $actionLabels = [
            'await_recovery' => 'Recover Vehicle before preparation',
            'await_return' => 'Await return before physical preparation',
            'leave_at_airport' => 'Leave at HNL',
            'retrieve_home' => 'Retrieve to home',
            'move_to_airport' => 'Move to HNL',
            'hold_home_flexible' => 'Hold at home',
            'operator_decision_needed' => 'Confirm location and next move',
            'relocate_to_international' => 'Relocate to International Garage',
        ];
        $reasonLabels = [
            'already_at_hnl' => 'Already at HNL.',
            'next_pickup_hnl' => 'Next pickup is at HNL.',
            'immediate' => 'Pickup is imminent.',
            'near_term' => 'Pickup is approaching.',
            'medium_term' => 'Pickup is later.',
            'no_confirmed_trip' => 'No confirmed upcoming trip.',
            'distant_hnl_pickup' => 'HNL pickup is not near term.',
            'next_pickup_not_hnl' => 'Next pickup is not at HNL.',
            'retrieve_home' => 'Home staging preserves control.',
            'waikiki_basis' => 'Vehicle is at a Waikiki hotel.',
            'transport_confirmed' => 'Transportation is confirmed.',
            'transport_unavailable' => 'Transportation is unavailable.',
            'transport_unknown' => 'Transportation needs confirmation.',
            'default_home_retrieval' => 'Return to home staging.',
            'vehicle_at_home' => 'Vehicle is already home.',
            'preserve_flexibility' => 'Home staging preserves flexibility.',
            'location_requires_operator_decision' => 'Location needs operator review.',
            'hnl_energy_service_unavailable' => 'Required energy service is unavailable at HNL.',
            'retrieve_for_turnaround' => 'Retrieve for turnaround work.',
            'stale_import_data' => 'Turo trip data is stale.',
            'wrong_airport_garage' => 'Wrong airport garage.',
        ];
        $reasonCodes = $recommendation['reason_codes'] ?? [];
        $isElectric = ($profile['energy_kind'] ?? null) === 'electric';
        $isDirty = $cleaningRequired || ($assessment['cleanliness'] ?? null) === 'dirty';
        $isBelowTarget = ($energyNeed['condition_code'] ?? null) === 'charge_required';
        $needsTurnaround = $isElectric && ($isDirty || $isBelowTarget);
        $reasons = [];
        foreach ($reasonCodes as $code) {
            if ($code === 'hnl_turnaround_supported') {
                if ($needsTurnaround) {
                    $reasons[] = $isBelowTarget ? 'Clean and charge on site.' : 'Clean on site.';
                }
                continue;
            }
            if (isset($reasonLabels[$code])) {
                $reasons[] = $reasonLabels[$code];
            }
        }

        return array_merge($recommendation, [
            'action_label' => $actionLabels[$recommendation['code'] ?? ''] ?? 'Review positioning',
            'display_label' => ($recommendation['strength'] ?? 'Consider') . ': ' . ($actionLabels[$recommendation['code'] ?? ''] ?? 'Review positioning'),
            'reason_labels' => array_values(array_unique($reasons)),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function presentOperatorPlan(?array $plan, string $recommendationCode): ?array
    {
        if ($plan === null) {
            return null;
        }
        $labels = [
            'leave_at_airport' => 'Leave at HNL',
            'retrieve_home' => 'Retrieve to home',
            'move_to_airport' => 'Move to HNL',
            'hold_home_flexible' => 'Hold at home',
            'operator_decision_needed' => 'Operator decision needed',
        ];
        $stale = (bool) ($plan['is_basis_stale'] ?? true);
        $differs = (string) ($plan['positioning_code'] ?? '') !== $recommendationCode;

        return array_merge($plan, [
            'label' => $labels[$plan['positioning_code'] ?? ''] ?? 'Positioning plan',
            'target_label' => $this->locationClassLabel((string) ($plan['target_location_class'] ?? 'unknown')),
            'reason_label' => ucwords(str_replace('_', ' ', (string) ($plan['reason_code'] ?? 'Operator decision'))),
            'actor_label' => trim((string) ($plan['actor_username'] ?? '')) ?: 'User #' . (int) ($plan['created_by'] ?? 0),
            'created_at_label' => isset($plan['created_at']) ? (new \DateTimeImmutable((string) $plan['created_at']))->format('M j, g:i A') : 'Time not captured',
            'expires_at_label' => isset($plan['expires_at']) ? (new \DateTimeImmutable((string) $plan['expires_at']))->format('M j, g:i A') : null,
            'transportation_label' => ucwords(str_replace('_', ' ', (string) ($plan['transportation_state'] ?? 'unknown'))),
            'status_label' => $stale ? 'Stale - needs review' : ($differs ? 'Operator plan differs from recommendation' : 'Operator plan agrees with recommendation'),
            'is_basis_stale' => $stale,
            'differs_from_recommendation' => $differs,
        ]);
    }

    /** @return array{code:string,label:string,href:string}|null */
    private function presentAction(array $state, array $card, int $vehicleId, ?array $schedule): ?array
    {
        $code = (string) ($state['primary_action']['code'] ?? 'none');
        $checklistHref = $this->movementHref($state, $card, $schedule);
        $checklistHref ??= $card['checklist_href'] ?? null;
        $checklistLabels = [
            'recover_vehicle' => 'Recover Vehicle',
            'confirm_handoff' => in_array($state['code'] ?? null, ['staged_for_pickup', 'staged_pickup_confirmation_needed'], true) ? 'Confirm Guest Pickup' : 'Record Guest Handoff',
            'monitor_pickup' => 'Continue Pickup',
            'confirm_return' => 'Record return',
            'monitor_return' => 'Record return',
            'complete_return_assessment' => 'Review return assessment',
            'complete_turnaround' => 'Continue Turnaround',
            'clear_blockers' => 'Continue Pickup',
        ];
        if ($checklistHref !== null && isset($checklistLabels[$code])) {
            $action = in_array($state['code'] ?? null, ['staged_for_pickup', 'staged_pickup_confirmation_needed'], true) ? 'confirm-pickup' : 'record-handoff';
            $href = $code === 'confirm_handoff'
                ? (string) $checklistHref . '?action=' . $action
                : (string) $checklistHref;
            if ($code === 'confirm_handoff' && $action === 'record-handoff') {
                $href .= '#pickup-fact-heading';
            }
            if ($code === 'recover_vehicle') {
                $href .= '#recover-vehicle-entry';
            }

            return ['code' => $code, 'label' => $checklistLabels[$code], 'href' => $href];
        }

        if ($code === 'none') {
            return null;
        }

        $isPositioningAction = $code === 'review_vehicle_status';
        return [
            'code' => $code,
            'label' => $isPositioningAction ? 'Set positioning plan' : 'View vehicle',
            'href' => '/fleet/vehicles/' . $vehicleId . ($isPositioningAction ? '/positioning-plan' : ''),
        ];
    }

    private function movementHref(array $state, array $card, ?array $schedule): ?string
    {
        $code = (string) ($state['primary_action']['code'] ?? 'none');
        $movementType = in_array($code, ['confirm_handoff', 'monitor_pickup', 'clear_blockers'], true) ? 'pickup'
            : (in_array($code, ['confirm_return', 'monitor_return', 'recover_vehicle', 'complete_return_assessment', 'complete_turnaround'], true) ? 'return' : null);
        $tripId = (int) ($schedule['id'] ?? 0);
        foreach ($card['checklists'] ?? [] as $checklist) {
            if (($movementType === null || ($checklist['movement_type'] ?? null) === $movementType)
                && ($tripId === 0 || ! isset($checklist['turo_trip_normalized_id']) || (int) $checklist['turo_trip_normalized_id'] === $tripId)) {
                return isset($checklist['href']) ? (string) $checklist['href'] : null;
            }
        }

        return $tripId > 0 ? $this->repo()->movementChecklistHref($tripId, $movementType) : null;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
    private function nextTrips(): NextConfirmedTripService
    {
        return $this->nextTripService ?? new NextConfirmedTripService($this->repo());
    }
    private function freshness(): ImportFreshnessService
    {
        return $this->freshnessService ?? new ImportFreshnessService();
    }
    private function states(): MovementStateResolver
    {
        return $this->stateResolver ?? new MovementStateResolver($this->energyRules());
    }
    private function positioning(): VehiclePositioningRecommendationService
    {
        return $this->positioningService ?? new VehiclePositioningRecommendationService(null, $this->energyRules());
    }
    private function plans(): VehiclePositioningPlanService
    {
        return $this->positioningPlanService ?? new VehiclePositioningPlanService($this->repo());
    }
    private function energyRules(): TripEnergyRuleResolver
    {
        return $this->energyRuleResolver ?? Services::tripEnergyRuleResolver();
    }

    /** @return array<string, mixed> */
    private function emptyProfile(): array
    {
        return [
            'energy_kind' => 'unknown',
            'ready_energy_target_percent' => null,
            'ready_energy_min_percent' => null,
            'ready_energy_preferred_max_percent' => null,
            'capabilities' => [],
        ];
    }
}
