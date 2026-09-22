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
    public const EXCEPTIONAL_DISPOSITIONS = [
        'maintenance_required' => 'Maintenance required',
        'claim_review_required' => 'Claim / damage review required',
        'offline' => 'Offline / unavailable',
    ];

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function project(array $context): array
    {
        $movementType = (string) $context['movement_type'];
        $preparationAssessment = $this->preparationAssessment($context, $movementType === 'return');
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
            static fn (array $requirement): bool => $requirement['blocking'] && $requirement['status'] === self::STATUS_UNSATISFIED && ($requirement['actionable'] ?? true),
        ));
        $additionalActionsRemaining = count(array_filter(
            $readinessRequirements,
            static fn (array $requirement): bool => ! $requirement['blocking'] && $requirement['status'] === self::STATUS_UNSATISFIED && ($requirement['actionable'] ?? true),
        ));
        $humanRemaining = count(array_filter(
            $readinessRequirements,
            static fn (array $requirement): bool => $requirement['blocking']
                && $requirement['status'] === self::STATUS_UNSATISFIED
                && ($requirement['actionable'] ?? true)
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
            'preparation_assessment' => $preparationAssessment,
            'energy_rule' => $context['energy_rule'] ?? null,
            'exceptional_dispositions' => self::EXCEPTIONAL_DISPOSITIONS,
            'workflow_history' => [
                'historically_completed' => $context['completed_at'] !== null,
                'completed_at' => $context['completed_at'],
                'stored_readiness_status' => $context['readiness_status'],
                'vehicle_disposition' => $context['vehicle_disposition'] ?? null,
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
        $assessment = $this->preparationAssessment($context, false);
        $assessmentAuthority = $assessment['_authority'] ?? 'movement_assessment';
        $profile = $context['profile'] ?? [];
        $energyRule = $context['energy_rule'] ?? $this->profileEnergyRule($profile);
        $energy = isset($assessment['energy_percent']) ? (int) $assessment['energy_percent'] : null;
        $clean = ($assessment['cleanliness'] ?? null) === 'clean';
        $workflow = $context['airport_workflow'];
        $scheduledLocation = $context['scheduled_location'];
        $isAirport = ($actualLocation['location_class'] ?? null) === 'airport_hnl'
            || ($scheduledLocation['location_class'] ?? null) === 'airport_hnl'
            || $workflow !== null;

        $requirements = [
            $this->photosRequirement($context),
            $this->derivedRequirement('vehicle_clean', 'Vehicle clean', self::PHASE_PICKUP_PREPARATION, $clean, true, $clean ? $assessmentAuthority : null, $assessment['captured_at'] ?? null, 'Record clean pickup condition'),
            $this->derivedRequirement('energy_known', 'Energy known', self::PHASE_PICKUP_PREPARATION, $energy !== null, true, $energy !== null ? $assessmentAuthority : null, $assessment['captured_at'] ?? null, 'Record Charge/Fuel percentage'),
        ];
        if ($energy !== null) {
            $requirements[] = $this->energyRequirement('energy_ready', 'Energy ready', self::PHASE_PICKUP_PREPARATION, $energyRule, $energy, $assessmentAuthority, $assessment['captured_at'] ?? null);
        }
        array_push(
            $requirements,
            $this->locationRequirement($context, $actualLocation),
            $this->keyRequirement($context, $workflow),
            $this->chargingAdapterRequirement($context),
            $this->airportStagingRequirement($staged, $workflow, $isAirport),
            $this->airportMilestoneRequirement('parking_location_recorded', 'Parking location recorded', $staged, $workflow, $isAirport, $this->hasStructuredParking($staged) || $this->hasWorkflowParking($workflow), 'Record parking location'),
            $this->derivedRequirement('guest_handoff', 'Guest handoff', self::PHASE_PICKUP_LIFECYCLE, $handoff !== null, false, $handoff !== null ? 'movement_event' : null, $handoff['occurred_at'] ?? null, 'Record actual guest handoff'),
        );
        $requirements = array_merge($requirements, $this->commitmentRequirements($context['active_commitments'] ?? [], self::PHASE_PICKUP_PREPARATION));
        $requirements = array_merge($requirements, $this->extraFulfillmentRequirements(
            $context['extra_fulfillments'] ?? [],
            self::PHASE_PICKUP_PREPARATION,
            ['preparation', 'pickup', 'entire_trip'],
        ));

        if ($handoff !== null) {
            $requirements = $this->suppressCompletedMovementPreparation($requirements, self::PHASE_PICKUP_PREPARATION);
        }

        return $requirements;
    }

    /** @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function returnRequirements(array $context): array
    {
        $events = $context['active_events'];
        $return = $events['actual_return'] ?? null;
        $recovery = $events['vehicle_recovered'] ?? null;
        $received = $return ?? $recovery;
        $nextTrip = $context['next_trip'] ?? null;
        $energyRule = $context['next_trip_energy_rule'] ?? ($nextTrip === null ? null : $this->profileEnergyRule($context['profile'] ?? []));
        $nextPickupAssessment = $this->preparationAssessment($context, true);
        $nextPickupAuthority = $nextPickupAssessment['_authority'] ?? 'movement_assessment';
        $receivedAt = (string) ($received['occurred_at'] ?? '');
        $cleanAt = (string) ($nextPickupAssessment['_cleanliness_captured_at'] ?? '');
        $energyAt = (string) ($nextPickupAssessment['_energy_percent_captured_at'] ?? '');
        $nextPickupEnergy = isset($nextPickupAssessment['energy_percent'])
            && ($receivedAt === '' || $energyAt > $receivedAt
                || ($energyAt === $receivedAt && ($nextPickupAssessment['_energy_percent_authority'] ?? null) === 'movement_assessment'))
            ? (int) $nextPickupAssessment['energy_percent'] : null;
        $nextPickupClean = ($nextPickupAssessment['cleanliness'] ?? null) === 'clean'
            && ($receivedAt === '' || $cleanAt > $receivedAt);
        $requirements = [
            $this->derivedRequirement('vehicle_recovery', 'Vehicle recovery', self::PHASE_RETURN_INTAKE, $received !== null, true, $received !== null ? 'movement_event' : null, $received['occurred_at'] ?? null, 'Recover Vehicle'),
        ];
        $requirements = array_merge(
            $requirements,
            $this->commitmentRequirements($context['active_commitments'] ?? [], self::PHASE_RETURN_INTAKE),
        );
        $requirements = array_merge($requirements, $this->extraFulfillmentRequirements(
            $context['extra_fulfillments'] ?? [],
            self::PHASE_RETURN_INTAKE,
            ['return', 'entire_trip'],
        ));

        if ($nextTrip !== null) {
            $requirements[] = $this->nextPickupRequirement(
                $this->derivedRequirement('vehicle_clean', 'Vehicle clean for next pickup', self::PHASE_NEXT_PICKUP_PREPARATION, $nextPickupClean, true, $nextPickupClean ? ($nextPickupAssessment['_cleanliness_authority'] ?? $nextPickupAuthority) : null, $cleanAt ?: null, 'Clean vehicle'),
                $nextTrip,
            );
            $requirements[] = $this->nextPickupRequirement(
                $this->energyRequirement('energy_ready', 'Energy ready for next pickup', self::PHASE_NEXT_PICKUP_PREPARATION, $energyRule ?? $this->profileEnergyRule([]), $nextPickupEnergy, $nextPickupAssessment['_energy_percent_authority'] ?? $nextPickupAuthority, $energyAt ?: null),
                $nextTrip,
            );
            foreach ($this->commitmentRequirements($context['next_trip_commitments'] ?? [], self::PHASE_NEXT_PICKUP_PREPARATION) as $requirement) {
                $requirements[] = $this->nextPickupRequirement($requirement, $nextTrip);
            }
            foreach ($this->extraFulfillmentRequirements(
                $context['next_trip_extra_fulfillments'] ?? [],
                self::PHASE_NEXT_PICKUP_PREPARATION,
                ['preparation', 'pickup', 'entire_trip'],
            ) as $requirement) {
                $requirements[] = $this->nextPickupRequirement($requirement, $nextTrip);
            }
        }

        if (($context['next_pickup_handoff'] ?? null) !== null) {
            $requirements = $this->suppressCompletedMovementPreparation($requirements, self::PHASE_NEXT_PICKUP_PREPARATION);
        }

        return $requirements;
    }

    /** Keep the recorded deficit visible while removing impossible physical actions. */
    private function suppressCompletedMovementPreparation(array $requirements, string $phase): array
    {
        return array_map(static function (array $requirement) use ($phase): array {
            if ($requirement['phase'] === $phase && $requirement['status'] === self::STATUS_UNSATISFIED) {
                $requirement['actionable'] = false;
                $requirement['action'] = null;
            }

            return $requirement;
        }, $requirements);
    }

    /** @param array<string, mixed> $context @return array<string, mixed>|null */
    private function preparationAssessment(array $context, bool $forNextPickup): ?array
    {
        $candidates = $forNextPickup
            ? [
                [3, 'target_pickup_assessment', $context['target_pickup_assessment'] ?? null],
                [2, 'current_readiness_assessment', $context['current_readiness_assessment'] ?? null],
                [1, 'movement_assessment', $context['active_assessment'] ?? null],
            ]
            : [
                [3, 'movement_assessment', $context['active_assessment'] ?? null],
                [2, 'current_readiness_assessment', $context['current_readiness_assessment'] ?? null],
            ];
        $selected = null;
        $selectedTimestamp = '';
        $selectedPriority = 0;
        $fields = [];
        foreach ($candidates as [$priority, $authority, $assessment]) {
            if (! is_array($assessment)) {
                continue;
            }
            $timestamp = (string) ($assessment['captured_at'] ?? '');
            if ($timestamp > $selectedTimestamp || ($timestamp === $selectedTimestamp && $priority > $selectedPriority)) {
                $selected = array_merge($assessment, ['_authority' => $authority]);
                $selectedTimestamp = $timestamp;
                $selectedPriority = $priority;
            }
            foreach (['cleanliness', 'energy_percent'] as $field) {
                if (($assessment[$field] ?? null) === null) {
                    continue;
                }
                $fieldTimestamp = (string) ($assessment['_' . $field . '_captured_at'] ?? $timestamp);
                if (! isset($fields[$field]) || $fieldTimestamp > $fields[$field]['timestamp']
                    || ($fieldTimestamp === $fields[$field]['timestamp'] && $priority > $fields[$field]['priority'])) {
                    $fields[$field] = ['value' => $assessment[$field], 'timestamp' => $fieldTimestamp, 'priority' => $priority, 'authority' => $authority];
                }
            }
        }

        if ($selected === null) {
            return null;
        }
        foreach (['cleanliness', 'energy_percent'] as $field) {
            $selected[$field] = $fields[$field]['value'] ?? null;
            $selected['_' . $field . '_captured_at'] = $fields[$field]['timestamp'] ?? null;
            $selected['_' . $field . '_authority'] = $fields[$field]['authority'] ?? null;
        }
        $custody = $context['latest_custody_event'] ?? null;
        if (in_array($custody['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)) {
            $recoveredAt = (string) ($custody['occurred_at'] ?? '');
            if ($selected['cleanliness'] === 'clean' && (string) ($selected['_cleanliness_captured_at'] ?? '') <= $recoveredAt) {
                $selected['cleanliness'] = null;
            }
            if ($selected['energy_percent'] !== null && (string) ($selected['_energy_percent_captured_at'] ?? '') <= $recoveredAt
                && ! (($selected['_energy_percent_authority'] ?? null) === 'movement_assessment'
                    && (int) ($context['active_assessment']['trip_movement_event_id'] ?? 0) === (int) ($custody['id'] ?? 0))) {
                $selected['energy_percent'] = null;
            }
        }

        return $selected;
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
            'key_card_confirmed' => $label,
            default => 'Confirm ' . strtolower($label),
        };
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function photosRequirement(array $context): array
    {
        $items = array_map(static fn (string $code): mixed => $context['items_by_code'][$code] ?? null, ['exterior_photos_completed', 'interior_photos_completed']);
        $satisfied = count(array_filter($items, static fn (mixed $item): bool => is_array($item)
            && ($item['applicability'] ?? 'applicable') === 'applicable'
            && ($item['completion_state'] ?? 'open') === 'complete')) === 2;
        $completedAt = null;
        if ($satisfied) {
            foreach ($items as $item) {
                if (is_array($item) && (string) ($item['completed_at'] ?? '') > (string) $completedAt) {
                    $completedAt = (string) $item['completed_at'];
                }
            }
        }

        return $this->requirement(
            'photos_complete',
            'Photos complete',
            self::PHASE_PICKUP_PREPARATION,
            self::KIND_HUMAN,
            $satisfied ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
            true,
            $satisfied ? 'human_checklist' : null,
            $completedAt,
            ['type' => 'photos_composite', 'checklist_id' => (int) $context['id'], 'label' => 'Photos complete'],
        );
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
    private function keyRequirement(array $context, ?array $workflow): array
    {
        $usesKeyCard = in_array('key_card', $context['capabilities'], true);
        $label = $usesKeyCard ? 'Key card present' : 'Keys present';
        if ($usesKeyCard && ($workflow['key_card_confirmed_at'] ?? null) !== null) {
            return $this->derivedRequirement('key_card_confirmed', $label, self::PHASE_PICKUP_PREPARATION, true, true, 'airport_milestone', $workflow['key_card_confirmed_at'], $label);
        }

        $human = $this->humanRequirement($context, 'key_card_confirmed', $label, self::PHASE_PICKUP_PREPARATION);
        $human['kind'] = self::KIND_HYBRID;

        return $human;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function chargingAdapterRequirement(array $context): array
    {
        if (! in_array('charging_adapter', $context['capabilities'], true)) {
            return $this->notApplicableRequirement('charging_adapter_confirmed', 'Charging adapter present', self::PHASE_PICKUP_PREPARATION);
        }
        $item = $context['items_by_code']['charging_adapter_confirmed'] ?? null;
        $satisfied = is_array($item)
            && ($item['applicability'] ?? 'applicable') === 'applicable'
            && ($item['completion_state'] ?? 'open') === 'complete';

        return $this->requirement(
            'charging_adapter_confirmed',
            'Charging adapter present',
            self::PHASE_PICKUP_PREPARATION,
            self::KIND_HUMAN,
            $satisfied ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
            true,
            $satisfied ? 'human_checklist' : null,
            $satisfied ? ($item['completed_at'] ?? null) : null,
            ['type' => 'charging_adapter', 'checklist_id' => (int) $context['id'], 'label' => 'Charging adapter present'],
        );
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

    /** @param array<string, mixed> $rule @return array<string, mixed> */
    private function energyRequirement(string $code, string $label, string $phase, array $rule, ?int $energy, string $authority, mixed $basisAt): array
    {
        if (($rule['percent'] ?? null) === null) {
            return $this->notApplicableRequirement($code, $label, $phase);
        }
        $evaluation = (new TripEnergyRuleResolver())->evaluate($rule, $energy);
        $ruleAuthority = ($rule['source'] ?? null) === 'trip_commitment' ? 'trip_commitment' : 'profile';
        $requirement = $this->derivedRequirement(
            $code,
            $label,
            $phase,
            (bool) $evaluation['ready'],
            (bool) ($rule['required'] ?? true),
            $evaluation['ready'] ? $authority . '_and_' . $ruleAuthority : null,
            $basisAt,
            (string) ($evaluation['action_label'] ?? 'Review energy'),
        );
        $requirement['energy_rule'] = $rule;
        $requirement['energy_condition'] = $evaluation['condition'];
        $requirement['attention_label'] = $evaluation['attention_label'];

        return $requirement;
    }

    /** @param list<array<string, mixed>> $commitments @return list<array<string, mixed>> */
    private function commitmentRequirements(array $commitments, string $phase): array
    {
        $requirements = [];
        foreach ($commitments as $commitment) {
            if (! (bool) ($commitment['required_before_dispatch'] ?? false)
                || ! in_array($commitment['handling_mode'], ['task', 'acknowledgment'], true)) {
                continue;
            }
            $satisfied = $commitment['handling_mode'] === 'acknowledgment'
                && ($commitment['acknowledged_at'] ?? null) !== null;
            $id = (int) $commitment['id'];
            $requirements[] = $this->requirement(
                'guest_commitment_' . $id,
                (string) $commitment['instruction'],
                $phase,
                self::KIND_HUMAN,
                $satisfied ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
                true,
                $satisfied ? 'guest_commitment_acknowledgment' : null,
                $satisfied ? $commitment['acknowledged_at'] : null,
                $satisfied ? null : [
                    'type' => $commitment['handling_mode'] === 'task' ? 'guest_commitment_complete' : 'guest_commitment_acknowledge',
                    'commitment_id' => $id,
                    'trip_id' => (int) $commitment['turo_trip_normalized_id'],
                    'label' => (string) $commitment['instruction'],
                ],
            );
        }

        return $requirements;
    }

    /** @param list<array<string, mixed>> $fulfillments @param list<string> $applicablePhases @return list<array<string, mixed>> */
    private function extraFulfillmentRequirements(array $fulfillments, string $phase, array $applicablePhases): array
    {
        $requirements = [];
        foreach ($fulfillments as $fulfillment) {
            if (($fulfillment['removed_at'] ?? null) !== null
                || ! in_array((string) ($fulfillment['fulfillment_phase'] ?? ''), $applicablePhases, true)
                || ! (bool) ($fulfillment['requires_operator_confirmation'] ?? false)
                || (int) ($fulfillment['fulfillment_id'] ?? 0) < 1) {
                continue;
            }
            $complete = (bool) ($fulfillment['is_completed'] ?? false);
            $actionable = (bool) ($fulfillment['is_actionable'] ?? false);
            $id = (int) $fulfillment['fulfillment_id'];
            $requirement = $this->requirement(
                'extra_fulfillment_' . $id,
                (string) ($fulfillment['title'] ?? 'Purchased Extra'),
                $phase,
                self::KIND_HUMAN,
                $complete ? self::STATUS_SATISFIED : self::STATUS_UNSATISFIED,
                (bool) ($fulfillment['readiness_blocking'] ?? false),
                $complete ? 'extra_fulfillment_confirmation' : null,
                $complete ? ($fulfillment['completed_at'] ?? null) : null,
                $complete || ! $actionable ? null : [
                    'type' => 'extra_fulfillment_complete',
                    'fulfillment_id' => $id,
                    'trip_id' => (int) ($fulfillment['turo_trip_normalized_id'] ?? 0),
                    'label' => (string) ($fulfillment['action_label'] ?? 'Confirm Extra prepared'),
                ],
            );
            $requirement['actionable'] = $actionable;
            $requirement['extra_fulfillment'] = $fulfillment;
            $requirements[] = $requirement;
        }

        return $requirements;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function profileEnergyRule(array $profile): array
    {
        $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;

        return [
            'source' => $target === null ? 'unconfigured' : 'vehicle_profile',
            'comparison' => $target === null ? null : 'minimum',
            'percent' => $target,
            'normal_vehicle_target' => $target,
            'commitment_id' => null,
            'required' => true,
        ];
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
