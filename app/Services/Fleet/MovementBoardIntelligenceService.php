<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

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
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function enrich(array $cards, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();

        return array_map(fn (array $card): array => $this->enrichCard($card, $asOf), $cards);
    }

    /** @return array<string, mixed> */
    private function enrichCard(array $card, \DateTimeImmutable $asOf): array
    {
        $vehicleId = (int) ($card['fleet_vehicle_id'] ?? 0);
        $event = $this->repo()->latestActiveMovementEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        $lifecycleEvent = $this->repo()->latestActiveLifecycleEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        $tripId = $this->relevantTripId($lifecycleEvent, $card);
        $schedule = $tripId === null ? null : $this->repo()->tripSchedule($tripId);
        $assessment = $this->repo()->assessmentForEventOrTrip(isset($lifecycleEvent['id']) ? (int) $lifecycleEvent['id'] : null, $tripId);
        $profile = $this->repo()->profile($vehicleId) ?? ['energy_kind' => 'unknown', 'ready_energy_target_percent' => null, 'capabilities' => []];
        $nextTrip = $this->nextTrips()->forVehicle($vehicleId, $asOf);
        $freshness = $this->freshness()->assess($nextTrip['import_completed_at'] ?? $schedule['import_completed_at'] ?? null, $asOf);
        $location = $this->positionBasis($lifecycleEvent, $schedule);
        $blockers = $this->blockers($card);
        if ($location['basis'] === 'actual' && $location['approved_turo_garage'] === false) {
            $blockers[] = ['code' => 'wrong_airport_garage', 'label' => 'Wrong airport garage - recovery / relocation required', 'severity' => 'critical'];
        }
        $state = $this->states()->resolve([
            'operational_status' => $card['status'] ?? $card['primary_status'] ?? 'available',
            'latest_event' => $lifecycleEvent,
            'trip_schedule' => $schedule,
            'assessment' => $assessment,
            'profile' => $profile,
            'next_trip' => $nextTrip,
            'blockers' => $blockers,
            'critical_blocker_count' => (int) ($card['checklist_critical_open'] ?? 0),
        ], $asOf);
        $activePlan = $this->plans()->active($vehicleId, $event, $nextTrip, $asOf);
        $usablePlan = $activePlan !== null && ! (bool) ($activePlan['is_basis_stale'] ?? true) ? $activePlan : null;
        $recommendation = $this->positioning()->recommend([
            'basis_location_class' => $location['class'],
            'basis_type' => $location['basis'],
            'basis_airport_garage_code' => $location['airport_garage_code'],
            'next_trip' => $nextTrip,
            'freshness' => $freshness,
            'assessment' => $assessment,
            'profile' => $profile,
            'capabilities' => $profile['capabilities'] ?? [],
            'blockers' => $state['blockers'],
            'transportation_state' => $card['transportation_state'] ?? $usablePlan['transportation_state'] ?? 'unknown',
            'active_override' => $activePlan,
        ]);
        $energyKind = (string) ($profile['energy_kind'] ?? 'unknown');
        $recommendation = $this->presentRecommendation($recommendation, $assessment, $profile);

        return array_merge($card, [
            'state' => $state,
            'primary_line' => $state['primary_line'],
            'location' => $location,
            'location_heading' => $location['heading'],
            'location_class' => $location['class'],
            'location_class_label' => $this->locationClassLabel($location['class']),
            'location_detail' => $location['detail'],
            'airport_garage_line' => $location['garage_line'],
            'airport_position_line' => $location['position_line'],
            'approved_turo_garage' => $location['approved_turo_garage'],
            'location_basis' => $location['basis'],
            'next_trip' => $this->presentNextTrip($nextTrip),
            'condition_label' => $assessment === null || ($assessment['cleanliness'] ?? null) === null ? 'Condition not captured' : ucfirst((string) $assessment['cleanliness']),
            'energy_label' => $energyKind === 'electric' ? 'Charge' : (in_array($energyKind, ['gasoline', 'diesel', 'hybrid'], true) ? 'Fuel' : 'Energy'),
            'energy_value' => $assessment === null || ($assessment['energy_percent'] ?? null) === null ? 'Not captured' : (int) $assessment['energy_percent'] . '%',
            'blockers' => $state['blockers'],
            'recommendation' => $recommendation,
            'operator_plan' => $this->presentOperatorPlan($activePlan, (string) ($recommendation['code'] ?? '')),
            'positioning_plan_href' => '/fleet/vehicles/' . $vehicleId . '/positioning-plan',
            'freshness' => $freshness,
            'action' => $this->presentAction($state, $card, $vehicleId),
        ]);
    }

    private function relevantTripId(?array $event, array $card): ?int
    {
        if (isset($event['turo_trip_normalized_id'])) {
            return (int) $event['turo_trip_normalized_id'];
        }
        foreach ([$card['return']['id'] ?? null, $card['pickup']['id'] ?? null] as $tripId) {
            if ($tripId !== null && (int) $tripId > 0) {
                return (int) $tripId;
            }
        }
        return null;
    }

    /** @return array{heading:string,class:string,detail:?string,basis:string,airport_garage_code:?string,garage_line:?string,position_line:?string,approved_turo_garage:?bool} */
    private function positionBasis(?array $event, ?array $schedule): array
    {
        if (($event['event_code'] ?? null) === 'actual_handoff') {
            return array_merge($this->emptyAirportParking(), [
                'heading' => 'Planned return',
                'class' => (string) ($schedule['return_location_class'] ?? 'unknown'),
                'detail' => $this->nullableText($schedule['return_location_source_text'] ?? null),
                'basis' => 'scheduled',
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

    /** @return array{airport_garage_code:?string,garage_line:?string,position_line:?string,approved_turo_garage:?bool} */
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
            'approved_turo_garage' => $presentation['approved_turo_garage'] ?? null,
        ];
    }

    /** @return array{airport_garage_code:null,garage_line:null,position_line:null,approved_turo_garage:null} */
    private function emptyAirportParking(): array
    {
        return ['airport_garage_code' => null, 'garage_line' => null, 'position_line' => null, 'approved_turo_garage' => null];
    }

    /** @return array<int, array<string, string>> */
    private function blockers(array $card): array
    {
        $blockers = [];
        if ((int) ($card['checklist_critical_open'] ?? 0) > 0) {
            $blockers[] = ['code' => 'critical_checklist_items', 'label' => 'Critical checklist items open', 'severity' => 'critical'];
        }
        if (in_array('maintenance_required', $card['flags'] ?? [], true)) {
            $blockers[] = ['code' => 'maintenance_required', 'label' => 'Maintenance required', 'severity' => 'critical'];
        }
        return $blockers;
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
    private function presentRecommendation(array $recommendation, ?array $assessment, array $profile): array
    {
        $actionLabels = [
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
        $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        $isElectric = ($profile['energy_kind'] ?? null) === 'electric';
        $isDirty = ($assessment['cleanliness'] ?? null) === 'dirty';
        $isBelowTarget = $target !== null && isset($assessment['energy_percent']) && (int) $assessment['energy_percent'] < $target;
        $needsTurnaround = $isElectric && ($isDirty || $isBelowTarget);
        $reasons = [];
        foreach ($reasonCodes as $code) {
            if ($code === 'hnl_turnaround_supported') {
                if ($needsTurnaround) {
                    $reasons[] = 'Clean and charge on site.';
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

    /** @return array{code:string,label:string,href:string} */
    private function presentAction(array $state, array $card, int $vehicleId): array
    {
        $code = (string) ($state['primary_action']['code'] ?? 'none');
        $movementType = in_array($code, ['confirm_handoff', 'monitor_pickup'], true) ? 'pickup'
            : (in_array($code, ['confirm_return', 'monitor_return', 'complete_return_assessment', 'complete_turnaround'], true) ? 'return' : null);
        $checklistHref = null;
        foreach ($card['checklists'] ?? [] as $checklist) {
            if ($movementType === null || ($checklist['movement_type'] ?? null) === $movementType) {
                $checklistHref = $checklist['href'] ?? null;
                break;
            }
        }
        $checklistHref ??= $card['checklist_href'] ?? null;
        $checklistLabels = [
            'confirm_handoff' => 'Record handoff',
            'monitor_pickup' => 'Record handoff',
            'confirm_return' => 'Record return',
            'monitor_return' => 'Record return',
            'complete_return_assessment' => 'Review return assessment',
            'complete_turnaround' => 'Review turnaround',
            'clear_blockers' => 'Review blockers',
        ];
        if ($checklistHref !== null && isset($checklistLabels[$code])) {
            return ['code' => $code, 'label' => $checklistLabels[$code], 'href' => (string) $checklistHref];
        }

        $isPositioningAction = in_array($code, ['none', 'review_vehicle_status'], true);
        return [
            'code' => $code,
            'label' => $isPositioningAction ? 'Set positioning plan' : 'View vehicle',
            'href' => '/fleet/vehicles/' . $vehicleId . ($isPositioningAction ? '/positioning-plan' : ''),
        ];
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
        return $this->stateResolver ?? new MovementStateResolver();
    }
    private function positioning(): VehiclePositioningRecommendationService
    {
        return $this->positioningService ?? new VehiclePositioningRecommendationService();
    }
    private function plans(): VehiclePositioningPlanService
    {
        return $this->positioningPlanService ?? new VehiclePositioningPlanService($this->repo());
    }
}
