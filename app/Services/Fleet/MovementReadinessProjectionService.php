<?php

namespace App\Services\Fleet;

class MovementReadinessProjectionService
{
    public const PHASE_PICKUP_PREPARATION = 'pickup_preparation';
    public const PHASE_PICKUP_LIFECYCLE = 'pickup_lifecycle';
    public const PHASE_RETURN_INTAKE = 'return_intake';
    public const PHASE_NEXT_PICKUP_PREPARATION = 'next_pickup_preparation';
    public const KIND_DERIVED = 'derived';
    public const KIND_HUMAN = 'human';
    public const KIND_HYBRID = 'hybrid';
    public const STATUS_SATISFIED = 'satisfied';
    public const STATUS_UNSATISFIED = 'unsatisfied';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function project(array $context): array
    {
        $movementType = (string) $context['movement_type'];
        $requirements = $movementType === 'return'
            ? $this->returnRequirements($context)
            : $this->pickupRequirements($context);
        $readinessPhase = $movementType === 'return' ? self::PHASE_RETURN_INTAKE : self::PHASE_PICKUP_PREPARATION;
        $readinessRequirements = array_values(array_filter(
            $requirements,
            static fn (array $requirement): bool => $requirement['phase'] === $readinessPhase,
        ));
        $blockingRemaining = count(array_filter(
            $readinessRequirements,
            static fn (array $requirement): bool => $requirement['blocking'] && $requirement['status'] === self::STATUS_UNSATISFIED,
        ));
        $additionalActionsRemaining = count(array_filter(
            $readinessRequirements,
            static fn (array $requirement): bool => ! $requirement['blocking'] && $requirement['status'] === self::STATUS_UNSATISFIED,
        ));
        $humanRemaining = count(array_filter(
            $readinessRequirements,
            static fn (array $requirement): bool => $requirement['blocking']
                && $requirement['status'] === self::STATUS_UNSATISFIED
                && in_array($requirement['kind'], [self::KIND_HUMAN, self::KIND_HYBRID], true),
        ));
        $knownFacts = count(array_filter(
            $requirements,
            static fn (array $requirement): bool => $requirement['status'] === self::STATUS_SATISFIED
                && ! in_array($requirement['satisfied_by'], ['human_checklist', 'vehicle_disposition'], true),
        ));

        return [
            'checklist_id' => (int) $context['id'],
            'company_id' => (int) $context['company_id'],
            'trip_id' => (int) $context['turo_trip_normalized_id'],
            'vehicle_id' => (int) $context['fleet_vehicle_id'],
            'movement_type' => $movementType,
            'readiness_phase' => $readinessPhase,
            'ready' => $blockingRemaining === 0,
            'blocking_remaining_count' => $blockingRemaining,
            'additional_actions_remaining_count' => $additionalActionsRemaining,
            'human_actions_remaining_count' => $humanRemaining,
            'known_facts_satisfied_count' => $knownFacts,
            'requirements' => $requirements,
            'next_trip' => $context['next_trip'] ?? null,
            'is_same_day_turnaround' => ($context['next_trip']['is_same_day_turnaround'] ?? false) === true,
            'positioning_plan' => $context['positioning_plan'],
            'workflow_history' => [
                'historically_completed' => $context['completed_at'] !== null,
                'completed_at' => $context['completed_at'],
                'stored_readiness_status' => $context['readiness_status'],
                'legacy_items' => array_values($context['items_by_code']),
            ],
        ];
    }

    /** @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function pickupRequirements(array $context): array
    {
        $events = $context['active_events'];
        $staged = $events['vehicle_staged'] ?? null;
        $handoff = $events['actual_handoff'] ?? null;
        $actualLocation = $handoff ?? $staged;
        $assessment = $context['active_assessment'];
        $profile = $context['profile'] ?? [];
        $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        $energy = isset($assessment['energy_percent']) ? (int) $assessment['energy_percent'] : null;
        $clean = ($assessment['cleanliness'] ?? null) === 'clean';
        $workflow = $context['airport_workflow'];
        $scheduledLocation = $context['scheduled_location'];
        $isAirport = ($actualLocation['location_class'] ?? null) === 'airport_hnl'
            || ($scheduledLocation['location_class'] ?? null) === 'airport_hnl'
            || $workflow !== null;

        $requirements = [
            $this->humanRequirement($context, 'vehicle_inspected', 'Vehicle inspected', self::PHASE_PICKUP_PREPARATION),
            $this->humanRequirement($context, 'exterior_photos_completed', 'Exterior condition photos completed', self::PHASE_PICKUP_PREPARATION),
            $this->humanRequirement($context, 'interior_photos_completed', 'Interior condition photos completed', self::PHASE_PICKUP_PREPARATION),
            $this->derivedRequirement('vehicle_clean', 'Vehicle clean', self::PHASE_PICKUP_PREPARATION, $clean, true, $clean ? 'movement_assessment' : null, $assessment['captured_at'] ?? null, 'Record clean pickup condition'),
            $this->derivedRequirement('energy_known', 'Energy known', self::PHASE_PICKUP_PREPARATION, $energy !== null, true, $energy !== null ? 'movement_assessment' : null, $assessment['captured_at'] ?? null, 'Record Charge/Fuel percentage'),
            $target === null
                ? $this->notApplicableRequirement('energy_ready', 'Energy ready', self::PHASE_PICKUP_PREPARATION)
                : $this->derivedRequirement('energy_ready', 'Energy ready', self::PHASE_PICKUP_PREPARATION, $energy !== null && $energy >= $target, true, $energy !== null ? 'movement_assessment_and_profile' : null, $assessment['captured_at'] ?? null, 'Charge/Fuel to ' . $target . '%'),
            $this->locationRequirement($context, $actualLocation),
            $this->keyCardRequirement($context, $workflow, $isAirport),
            $this->airportStagingRequirement($staged, $workflow, $isAirport),
            $this->airportMilestoneRequirement('parking_location_recorded', 'Parking location recorded', $staged, $workflow, $isAirport, $this->hasStructuredParking($staged) || $this->hasWorkflowParking($workflow), 'Record parking location'),
            $this->airportMilestoneRequirement('guest_pickup_instructions_confirmed', 'Guest pickup instructions confirmed', null, $workflow, $isAirport, ($workflow['guest_instructions_sent_at'] ?? null) !== null, 'Confirm guest pickup instructions'),
            $this->airportMilestoneRequirement('turo_access_instructions_confirmed', 'Turo Access instructions confirmed', null, $workflow, $isAirport, ($workflow['guest_instructions_sent_at'] ?? null) !== null, 'Confirm Turo Access instructions'),
            $this->derivedRequirement('guest_handoff', 'Guest handoff', self::PHASE_PICKUP_LIFECYCLE, $handoff !== null, false, $handoff !== null ? 'movement_event' : null, $handoff['occurred_at'] ?? null, 'Record actual guest handoff'),
        ];

        return $requirements;
    }

    /** @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function returnRequirements(array $context): array
    {
        $events = $context['active_events'];
        $return = $events['actual_return'] ?? null;
        $recovery = $events['vehicle_recovered'] ?? null;
        $received = $return ?? $recovery;
        $assessment = $context['active_assessment'];
        $profile = $context['profile'] ?? [];
        $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        $energy = isset($assessment['energy_percent']) ? (int) $assessment['energy_percent'] : null;
        $cleanlinessKnown = ($assessment['cleanliness'] ?? null) !== null;
        $clean = ($assessment['cleanliness'] ?? null) === 'clean';
        $disposition = trim((string) ($context['vehicle_disposition'] ?? ''));
        $nextTrip = $context['next_trip'] ?? null;

        $requirements = [
            $this->derivedRequirement('vehicle_received', 'Vehicle returned or recovered', self::PHASE_RETURN_INTAKE, $received !== null, true, $received !== null ? 'movement_event' : null, $received['occurred_at'] ?? null, 'Record actual return or recovery'),
            $this->derivedRequirement('return_time_confirmed', 'Actual return time known', self::PHASE_RETURN_INTAKE, $return !== null, true, $return !== null ? 'movement_event' : null, $return['occurred_at'] ?? null, 'Record actual return time'),
            $this->derivedRequirement('energy_known', 'Energy known', self::PHASE_RETURN_INTAKE, $energy !== null, true, $energy !== null ? 'movement_assessment' : null, $assessment['captured_at'] ?? null, 'Record Charge/Fuel percentage'),
            $this->derivedRequirement('cleaning_status_known', 'Cleaning status known', self::PHASE_RETURN_INTAKE, $cleanlinessKnown, true, $cleanlinessKnown ? 'movement_assessment' : null, $assessment['captured_at'] ?? null, 'Record return cleanliness'),
            $this->humanRequirement($context, 'exterior_inspected', 'Exterior inspected', self::PHASE_RETURN_INTAKE),
            $this->humanRequirement($context, 'interior_inspected', 'Interior inspected', self::PHASE_RETURN_INTAKE),
            $this->humanRequirement($context, 'damage_check_completed', 'Damage check completed', self::PHASE_RETURN_INTAKE),
            $this->humanRequirement($context, 'return_photos_completed', 'Return photos completed', self::PHASE_RETURN_INTAKE),
            $this->derivedRequirement('vehicle_disposition', 'Vehicle disposition assigned', self::PHASE_RETURN_INTAKE, $disposition !== '', true, $disposition !== '' ? 'vehicle_disposition' : null, null, 'Assign vehicle disposition'),
        ];

        if ($nextTrip !== null) {
            $requirements[] = $this->nextPickupRequirement(
                $this->derivedRequirement('vehicle_clean', 'Vehicle clean for next pickup', self::PHASE_NEXT_PICKUP_PREPARATION, $clean, true, $clean ? 'movement_assessment' : null, $assessment['captured_at'] ?? null, 'Clean vehicle'),
                $nextTrip,
            );
            $requirements[] = $this->nextPickupRequirement(
                $target === null
                    ? $this->notApplicableRequirement('energy_ready', 'Energy ready for next pickup', self::PHASE_NEXT_PICKUP_PREPARATION)
                    : $this->derivedRequirement('energy_ready', 'Energy ready for next pickup', self::PHASE_NEXT_PICKUP_PREPARATION, $energy !== null && $energy >= $target, true, $energy !== null ? 'movement_assessment_and_profile' : null, $assessment['captured_at'] ?? null, 'Charge/Fuel to ' . $target . '%'),
                $nextTrip,
            );
        }

        return $requirements;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function humanRequirement(array $context, string $code, string $label, string $phase): array
    {
        $item = $context['items_by_code'][$code] ?? null;
        $satisfied = $item !== null
            && ($item['applicability'] ?? 'applicable') === 'applicable'
            && ($item['completion_state'] ?? 'open') === 'complete';

        return $this->requirement(
            $code,
            $label,
            $phase,
            self::KIND_HUMAN,
            $satisfied ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
            true,
            $satisfied ? 'human_checklist' : null,
            $satisfied ? ($item['completed_at'] ?? null) : null,
            $satisfied ? null : ['type' => 'checklist_item', 'item_id' => $item === null ? null : (int) $item['id'], 'label' => $this->humanActionLabel($code, $label)],
        );
    }

    private function humanActionLabel(string $code, string $label): string
    {
        return match ($code) {
            'exterior_inspected' => 'Inspect exterior',
            'interior_inspected' => 'Inspect interior',
            'damage_check_completed' => 'Check for damage',
            'return_photos_completed' => 'Confirm return photos',
            default => 'Confirm ' . strtolower($label),
        };
    }

    /** @param array<string, mixed> $requirement @param array<string, mixed> $nextTrip @return array<string, mixed> */
    private function nextPickupRequirement(array $requirement, array $nextTrip): array
    {
        return array_merge($requirement, [
            'target_trip_id' => (int) $nextTrip['id'],
            'target_at' => (string) $nextTrip['starts_at'],
        ]);
    }

    /** @param array<string, mixed> $context @param array<string, mixed>|null $actualLocation @return array<string, mixed> */
    private function locationRequirement(array $context, ?array $actualLocation): array
    {
        $actualKnown = $actualLocation !== null && ! in_array($actualLocation['location_class'] ?? null, [null, '', 'unknown'], true);
        if ($actualKnown) {
            return $this->derivedRequirement('location_confirmed', 'Pickup location confirmed', self::PHASE_PICKUP_PREPARATION, true, true, 'movement_event', $actualLocation['occurred_at'] ?? null, 'Confirm pickup location');
        }
        if ($actualLocation !== null) {
            return $this->derivedRequirement('location_confirmed', 'Pickup location confirmed', self::PHASE_PICKUP_PREPARATION, false, true, null, null, 'Record actual pickup location');
        }

        $human = $this->humanRequirement($context, 'location_confirmed', 'Pickup location confirmed', self::PHASE_PICKUP_PREPARATION);
        $human['kind'] = self::KIND_HYBRID;

        return $human;
    }

    /** @param array<string, mixed> $context @param array<string, mixed>|null $workflow @return array<string, mixed> */
    private function keyCardRequirement(array $context, ?array $workflow, bool $isAirport): array
    {
        $applicable = $isAirport || in_array('key_card', $context['capabilities'], true);
        if (! $applicable) {
            return $this->notApplicableRequirement('key_card_confirmed', 'Key card confirmed', self::PHASE_PICKUP_PREPARATION);
        }
        if (($workflow['key_card_confirmed_at'] ?? null) !== null) {
            return $this->derivedRequirement('key_card_confirmed', 'Key card confirmed', self::PHASE_PICKUP_PREPARATION, true, true, 'airport_milestone', $workflow['key_card_confirmed_at'], 'Confirm key card');
        }

        $human = $this->humanRequirement($context, 'key_card_confirmed', 'Key card confirmed', self::PHASE_PICKUP_PREPARATION);
        $human['kind'] = self::KIND_HYBRID;

        return $human;
    }

    /** @param array<string, mixed>|null $staged @param array<string, mixed>|null $workflow @return array<string, mixed> */
    private function airportStagingRequirement(?array $staged, ?array $workflow, bool $isAirport): array
    {
        if (! $isAirport) {
            return $this->notApplicableRequirement('airport_staging', 'Airport staging', self::PHASE_PICKUP_PREPARATION);
        }
        $satisfied = $staged !== null || ($workflow['vehicle_staged_at'] ?? null) !== null;

        return $this->derivedRequirement(
            'airport_staging',
            'Airport staging',
            self::PHASE_PICKUP_PREPARATION,
            $satisfied,
            true,
            $staged !== null ? 'movement_event' : ($satisfied ? 'airport_milestone' : null),
            $staged['occurred_at'] ?? $workflow['vehicle_staged_at'] ?? null,
            'Stage vehicle at HNL',
        );
    }

    /** @param array<string, mixed>|null $event @param array<string, mixed>|null $workflow @return array<string, mixed> */
    private function airportMilestoneRequirement(string $code, string $label, ?array $event, ?array $workflow, bool $isAirport, bool $satisfied, string $actionLabel): array
    {
        if (! $isAirport) {
            return $this->notApplicableRequirement($code, $label, self::PHASE_PICKUP_PREPARATION);
        }

        return $this->derivedRequirement(
            $code,
            $label,
            self::PHASE_PICKUP_PREPARATION,
            $satisfied,
            false,
            $event !== null ? 'movement_event' : ($satisfied ? 'airport_milestone' : null),
            $event['occurred_at'] ?? $workflow['updated_at'] ?? null,
            $actionLabel,
        );
    }

    /** @param array<string, mixed>|null $event */
    private function hasStructuredParking(?array $event): bool
    {
        return $event !== null
            && ($event['airport_garage_code'] ?? null) !== null
            && ($event['airport_parking_level'] ?? null) !== null
            && ($event['airport_parking_row'] ?? null) !== null;
    }

    /** @param array<string, mixed>|null $workflow */
    private function hasWorkflowParking(?array $workflow): bool
    {
        return $workflow !== null
            && trim((string) ($workflow['garage'] ?? '')) !== ''
            && trim((string) ($workflow['parking_level'] ?? '')) !== ''
            && trim((string) ($workflow['parking_row'] ?? '')) !== '';
    }

    /** @return array<string, mixed> */
    private function derivedRequirement(string $code, string $label, string $phase, bool $satisfied, bool $blocking, ?string $satisfiedBy, mixed $basisAt, string $actionLabel): array
    {
        return $this->requirement(
            $code,
            $label,
            $phase,
            self::KIND_DERIVED,
            $satisfied ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
            $blocking,
            $satisfiedBy,
            $satisfied ? $basisAt : null,
            $satisfied ? null : ['type' => 'record_fact', 'label' => $actionLabel],
        );
    }

    /** @return array<string, mixed> */
    private function notApplicableRequirement(string $code, string $label, string $phase): array
    {
        return $this->requirement($code, $label, $phase, self::KIND_DERIVED, self::STATUS_NOT_APPLICABLE, false, 'structural_applicability', null, null);
    }

    /** @return array<string, mixed> */
    private function requirement(string $code, string $label, string $phase, string $kind, string $status, bool $blocking, ?string $satisfiedBy, mixed $basisAt, ?array $action): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'phase' => $phase,
            'kind' => $kind,
            'status' => $status,
            'blocking' => $blocking,
            'satisfied_by' => $satisfiedBy,
            'basis_at' => $basisAt,
            'action' => $action,
            'allows_na' => false,
        ];
    }

}
