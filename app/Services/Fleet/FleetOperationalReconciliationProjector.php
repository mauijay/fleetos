<?php

namespace App\Services\Fleet;

use DateTimeImmutable;

/** Pure comparisons of existing authority outputs. Never creates or repairs facts. */
final class FleetOperationalReconciliationProjector
{
    public const IMMINENT_HOURS = 48;
    public const RETURN_GRACE_HOURS = 2;

    /** @return list<array<string, mixed>> */
    public static function generations(array $trips, array $checklists): array
    {
        $trips = array_column($trips, null, 'id');
        $authority = new MovementScheduleAuthorityService();
        $result = [];
        foreach ($checklists as $checklist) {
            $trip = $trips[(int) $checklist['turo_trip_normalized_id']] ?? null;
            if ($trip === null) {
                continue;
            }
            $scheduled = $authority->scheduledAt(array_merge($trip, ['movement_type' => $checklist['movement_type']]));
            $current = $scheduled !== null && $checklist['scheduled_at'] === $scheduled;
            $result[] = [
                'id' => (int) $checklist['id'], 'vehicle_id' => (int) $checklist['fleet_vehicle_id'],
                'trip_id' => (int) $trip['id'], 'direction' => $checklist['movement_type'],
                'scheduled_at_snapshot' => $checklist['scheduled_at'], 'authoritative_scheduled_at' => $scheduled,
                'is_current' => $current, 'obsolete_reason' => $current ? null : ($scheduled === null ? 'schedule_unknown' : 'schedule_revised'),
                'persisted_workflow_state' => $checklist['readiness_status'], 'completed_at' => $checklist['completed_at'],
            ];
        }
        usort($result, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function project(int $companyId, array $data, DateTimeImmutable $asOf): array
    {
        $byVehicle = [];
        foreach (['trips', 'events'] as $key) {
            foreach ($data[$key] as $row) {
                $byVehicle[(int) $row['fleet_vehicle_id']][$key][] = $row;
            }
        }
        foreach ($data['generations'] as $row) {
            $byVehicle[$row['vehicle_id']]['generations'][] = $row;
        }
        foreach ($data['readiness'] as $row) {
            $byVehicle[(int) $row['vehicle_id']]['readiness'][] = $row;
        }
        $result = [];
        foreach ($data['vehicles'] as $vehicle) {
            $id = (int) $vehicle['id'];
            $local = $byVehicle[$id] ?? [];
            $local['custody'] = $data['custody'][$id];
            $local['position'] = $data['positions'][$id];
            $local['commitments'] = [];
            foreach ($local['trips'] ?? [] as $trip) {
                if (isset($data['commitments'][(int) $trip['id']])) {
                    $local['commitments'][(int) $trip['id']] = $data['commitments'][(int) $trip['id']];
                }
            }
            $result[$id] = $this->vehicle($companyId, $vehicle, $local, $asOf);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private function vehicle(int $companyId, array $vehicle, array $data, DateTimeImmutable $asOf): array
    {
        $id = (int) $vehicle['id'];
        $now = $asOf->format('Y-m-d H:i:s');
        $trips = array_column($data['trips'] ?? [], null, 'id');
        $operational = array_filter($trips, static fn (array $trip): bool => ($trip['canceled_at'] ?? null) === null && ! str_starts_with((string) $trip['trip_status_code'], 'canceled') && $trip['trip_status_code'] !== 'invalid');
        $current = $next = $prior = null;
        foreach ($operational as $trip) {
            if ($trip['starts_at'] !== null && $trip['ends_at'] !== null && $trip['starts_at'] <= $now && $trip['ends_at'] > $now && in_array($trip['trip_status_code'], ['in_progress', 'booked'], true)) {
                if ($current === null || $trip['trip_status_code'] === 'in_progress') {
                    $current = $trip;
                }
            }
            if ($next === null && $trip['trip_status_code'] === 'booked' && $trip['starts_at'] > $now && $trip['ends_at'] > $trip['starts_at']) {
                $next = $trip;
            }
            if ($trip['ends_at'] !== null && $trip['ends_at'] <= $now && ($prior === null || [$trip['ends_at'], (int) $trip['id']] > [$prior['ends_at'], (int) $prior['id']])) {
                $prior = $trip;
            }
        }
        $custody = $data['custody'];
        $movementTrip = $trips[(int) ($custody['active_trip_id'] ?? 0)] ?? null;
        $basisTrip = $trips[(int) ($custody['basis_trip_id'] ?? 0)] ?? null;
        $events = $data['events'] ?? [];
        $byTrip = [];
        foreach ($events as $event) {
            $byTrip[(int) ($event['turo_trip_normalized_id'] ?? 0)][] = $event;
        }
        $issues = [];
        $add = static function (string $code, string $severity, string $scope, string $summary, array $tripIds, array $evidence, string $authority, string $review) use (&$issues, $companyId, $id): void {
            $issues[] = ReconciliationIssue::make($companyId, $id, $code, $severity, $scope, $summary, $tripIds, $evidence, $authority, $review);
        };
        $custodyCode = null;
        $currentEvents = $byTrip[(int) ($current['id'] ?? 0)] ?? [];
        $handoff = $this->latest($currentEvents, ['actual_handoff']);
        $recovery = $this->latest($currentEvents, ['actual_return', 'vehicle_recovered']);
        $endedBasis = $basisTrip !== null && $basisTrip['ends_at'] !== null && $basisTrip['ends_at'] < $now;
        if ($custody['integrity_conflict']) {
            $custodyCode = 'movement.overlapping_guest_intervals';
        } elseif ($custody['custody'] === 'guest' && $endedBasis && $current !== null && (int) $current['id'] !== (int) $basisTrip['id'] && $current['starts_at'] > $basisTrip['starts_at']) {
            $custodyCode = 'movement.old_guest_custody';
        } elseif ($custody['custody'] === 'guest' && $current !== null && (int) $current['id'] !== (int) $custody['basis_trip_id']) {
            $custodyCode = 'movement.custody_trip_mismatch';
        } elseif ($current !== null && $current['trip_status_code'] === 'in_progress' && $handoff === null && $recovery === null) {
            $custodyCode = 'movement.import_current_missing_handoff';
        }
        // Equal-time incompatible lifecycle facts require review even though authority breaks ties by ID.
        $ambiguous = [];
        foreach ($byTrip as $tripEvents) {
            $groups = [];
            foreach ($tripEvents as $event) {
                if (in_array($event['event_code'], ['actual_handoff', 'actual_return', 'vehicle_recovered'], true)) {
                    $groups[$event['occurred_at']][] = $event;
                }
            }
            foreach ($groups as $group) {
                $codes = array_column($group, 'event_code');
                if (in_array('actual_handoff', $codes, true) && (in_array('actual_return', $codes, true) || in_array('vehicle_recovered', $codes, true))) {
                    $ambiguous[] = $group;
                }
            }
        }
        if ($custodyCode === null && $ambiguous !== []) {
            $relevant = array_filter($ambiguous, static fn (array $group): bool => (int) $group[0]['turo_trip_normalized_id'] === (int) ($custody['basis_trip_id'] ?? 0));
            $add('movement.ambiguous_authority', $relevant !== [] ? 'P0' : 'P2', $relevant !== [] ? 'CURRENT' : 'HISTORICAL', 'Conflicting lifecycle facts share an occurrence time.', array_map('intval', array_column(array_merge(...$ambiguous), 'turo_trip_normalized_id')), ['events' => $this->eventBasis(array_merge(...$ambiguous))], 'movement_events', 'movement');
        }
        $position = $data['position'];
        $positionIssues = [];
        if ($position['location_class'] === 'unknown' || $position['position_semantics'] === 'unverified_guest_report') {
            $positionIssues[] = 'position.unknown';
        }
        $basisEvent = null;
        $later = [];
        foreach ($events as $event) {
            if ((int) $event['id'] === (int) ($position['event_id'] ?? 0)) {
                $basisEvent = $event;
            }
            if (in_array($event['event_code'], ['vehicle_staged', 'actual_handoff', 'actual_return', 'vehicle_recovered', 'vehicle_positioned'], true)
                && [$event['occurred_at'], (int) $event['id']] > [(string) ($position['observed_at'] ?? $position['reported_at'] ?? ''), (int) ($position['event_id'] ?? 0)]) {
                $later[] = $event;
            }
        }
        // Handoff locations are provenance while rented; ordinary later observations do not assert a parked guest position.
        $stale = $custody['custody'] === 'guest' ? array_filter($later, static fn (array $event): bool => in_array($event['event_code'], ['actual_handoff', 'actual_return', 'vehicle_recovered'], true)) : $later;
        if ($stale !== [] && $position['event_id'] !== null) {
            $positionIssues[] = 'position.stale_basis';
        }
        if ($custody['custody'] === 'guest' && $position['trip_id'] !== null && (int) $position['trip_id'] !== (int) $custody['basis_trip_id']) {
            $positionIssues[] = 'position.trip_mismatch';
        }
        $positionEvidence = ['position_event_id' => $position['event_id'], 'position_trip_id' => $position['trip_id'], 'position_semantics' => $position['position_semantics'], 'position_class' => $position['location_class'], 'later_events' => $this->eventBasis(array_values($stale)), 'supporting_codes' => $positionIssues];
        if ($custodyCode !== null) {
            $add($custodyCode, 'P0', 'CURRENT', match ($custodyCode) {
                'movement.old_guest_custody' => 'An ended trip still holds guest custody while a later reservation is current.',
                'movement.overlapping_guest_intervals' => 'Multiple trips retain unrecovered guest custody.',
                'movement.custody_trip_mismatch' => 'Guest custody belongs to a different trip than the current reservation.',
                default => 'Imported in-progress trip has no handoff establishing guest custody.',
            }, array_values(array_unique(array_filter([(int) ($basisTrip['id'] ?? 0), (int) ($current['id'] ?? 0)]))), ['custody_event_id' => $custody['basis_event_id'], 'custody_trip_id' => $custody['basis_trip_id'], 'current_trip' => $current, 'conflicting_guest_states' => $custody['conflicting_guest_states'], 'position' => $positionEvidence], 'current_vehicle_custody_and_normalized_trip', 'movement');
        } elseif ($positionIssues !== []) {
            // One primary position issue contains all related integrity evidence.
            $code = in_array('position.trip_mismatch', $positionIssues, true) ? 'position.trip_mismatch' : (in_array('position.stale_basis', $positionIssues, true) ? 'position.stale_basis' : 'position.unknown');
            $add($code, 'P0', 'CURRENT', 'Current position authority is incomplete or disagrees with movement chronology.', array_filter([(int) ($position['trip_id'] ?? 0)]), $positionEvidence, 'current_vehicle_location_and_movement_events', 'position');
        }
        foreach ($operational as $tripId => $trip) {
            $tripEvents = $byTrip[$tripId] ?? [];
            $lastHandoff = $this->latest($tripEvents, ['actual_handoff']);
            $lastRecovery = $this->latest($tripEvents, ['actual_return', 'vehicle_recovered']);
            $scope = $this->scope($trip, $current, $next, $custody, $now);
            $evidence = ['trip' => $trip, 'events' => $this->eventBasis($tripEvents)];
            $unrecovered = $lastHandoff !== null && ($lastRecovery === null || [$lastRecovery['occurred_at'], (int) $lastRecovery['id']] < [$lastHandoff['occurred_at'], (int) $lastHandoff['id']]);
            if ($unrecovered && $trip['ends_at'] !== null && $trip['ends_at'] < $asOf->modify('-' . self::RETURN_GRACE_HOURS . ' hours')->format('Y-m-d H:i:s')) {
                if (! ($custodyCode !== null && (int) $custody['basis_trip_id'] === (int) $tripId)) {
                    $isBasis = $custody['custody'] === 'guest' && (int) $custody['basis_trip_id'] === (int) $tripId;
                    $add('movement.overdue_return_missing_recovery', $isBasis ? 'P0' : 'P2', $isBasis ? 'CURRENT' : 'HISTORICAL', 'Handoff has no subsequent recovery after the scheduled return.', [(int) $tripId], $evidence, 'movement_events_and_trip_schedule', 'movement');
                }
            }
            if ($lastRecovery !== null) {
                $preceding = array_filter($tripEvents, static fn (array $event): bool => $event['event_code'] === 'actual_handoff' && [$event['occurred_at'], (int) $event['id']] < [$lastRecovery['occurred_at'], (int) $lastRecovery['id']]);
                if ($preceding === []) {
                    $add('movement.recovery_without_handoff', 'P2', $scope, 'Recovery has no preceding recorded handoff for this trip.', [(int) $tripId], $evidence, 'movement_events', 'movement_history');
                }
            }
            if ($trip['trip_status_code'] === 'in_progress' && $lastRecovery !== null && ! $unrecovered) {
                $add('import.status_stale', 'P2', $scope, 'Imported in-progress status remains after authoritative recovery.', [(int) $tripId], $evidence, 'normalized_trip_and_movement_events', 'trip_import');
            } elseif ($trip['trip_status_code'] === 'in_progress' && $trip['ends_at'] !== null && $trip['ends_at'] < $now && $lastHandoff === null && $lastRecovery === null) {
                $add('import.status_stale', 'P2', 'HISTORICAL', 'Imported in-progress status lies outside its schedule without a movement lifecycle.', [(int) $tripId], $evidence, 'normalized_trip_and_schedule', 'trip_import');
            } elseif ($lastHandoff !== null && $unrecovered && in_array($trip['trip_status_code'], ['completed', 'booked'], true) && $custodyCode === null) {
                $isBasis = (int) $custody['basis_trip_id'] === (int) $tripId;
                // An overdue recovery already explains a completed import mismatch.
                $overdue = $trip['ends_at'] !== null && $trip['ends_at'] < $asOf->modify('-' . self::RETURN_GRACE_HOURS . ' hours')->format('Y-m-d H:i:s');
                if (! $overdue) {
                    $add('import.status_movement_conflict', 'P2', $isBasis ? 'CURRENT' : 'HISTORICAL', 'Imported status disagrees with the recorded handoff lifecycle.', [(int) $tripId], $evidence, 'normalized_trip_and_movement_events', 'trip_import');
                }
            }
        }
        $intervals = [];
        foreach ($operational as $tripId => $trip) {
            $open = null;
            foreach ($byTrip[$tripId] ?? [] as $event) {
                if ($event['event_code'] === 'actual_handoff' && $open === null) {
                    $open = $event;
                } elseif ($open !== null && in_array($event['event_code'], ['actual_return', 'vehicle_recovered'], true)) {
                    $intervals[] = ['trip_id' => (int) $tripId, 'handoff_id' => (int) $open['id'], 'return_id' => (int) $event['id'], 'start' => $open['occurred_at'], 'end' => $event['occurred_at']];
                    $open = null;
                }
            }
            if ($open !== null) {
                $intervals[] = ['trip_id' => (int) $tripId, 'handoff_id' => (int) $open['id'], 'return_id' => null, 'start' => $open['occurred_at'], 'end' => null];
            }
        }
        usort($intervals, static fn (array $a, array $b): int => [$a['start'], $a['handoff_id']] <=> [$b['start'], $b['handoff_id']]);
        $overlaps = [];
        $activeIntervals = [];
        foreach ($intervals as $interval) {
            $activeIntervals = array_values(array_filter($activeIntervals, static fn (array $prior): bool => $prior['end'] === null || $prior['end'] > $interval['start']));
            foreach ($activeIntervals as $other) {
                if ($other['trip_id'] !== $interval['trip_id'] && ($other['end'] !== null || $interval['end'] !== null)) {
                    $overlaps[$other['handoff_id']] = $other;
                    $overlaps[$interval['handoff_id']] = $interval;
                }
            }
            $activeIntervals[] = $interval;
        }
        if ($overlaps !== []) {
            $add('movement.overlapping_guest_intervals', 'P2', 'HISTORICAL', 'Recorded guest intervals overlapped before subsequent recovery.', array_column($overlaps, 'trip_id'), ['intervals' => array_values($overlaps)], 'movement_event_intervals', 'movement_history');
        }
        $generations = $data['generations'] ?? [];
        $groups = [];
        $readinessByChecklist = array_column($data['readiness'] ?? [], null, 'checklist_id');
        foreach ($generations as $generation) {
            $groups[$generation['trip_id'] . ':' . $generation['direction']][] = $generation;
        }
        foreach ($groups as $group) {
            $trip = $trips[$group[0]['trip_id']];
            $obsolete = array_filter($group, static fn (array $row): bool => ! $row['is_current']);
            if ($obsolete !== []) {
                $add('checklist.obsolete_schedule_generation', 'P2', 'HISTORICAL', 'Checklist schedule snapshots differ from the current reservation schedule.', [(int) $trip['id']], ['generations' => $group], 'normalized_schedule_and_checklist_snapshots', 'checklist_history');
            } elseif (count($group) > 1) {
                $add('checklist.duplicate_generation', 'P3', $this->scope($trip, $current, $next, $custody, $now), 'Multiple checklist generations require schedule review.', [(int) $trip['id']], ['generations' => $group], 'schedule_authority', 'checklist_history');
            }
            foreach ($group as $generation) {
                if (! $generation['is_current'] || $generation['completed_at'] === null) {
                    continue;
                }
                $lifecycle = $this->latest($byTrip[$generation['trip_id']] ?? [], $generation['direction'] === 'pickup' ? ['actual_handoff'] : ['actual_return', 'vehicle_recovered']);
                $projection = $readinessByChecklist[$generation['id']] ?? null;
                $claims = array_values(array_filter($projection['workflow_history']['legacy_items'] ?? [], static fn (array $item): bool => ($item['item_code'] ?? null) === 'guest_handoff_completed' && ($item['completion_state'] ?? null) === 'complete'));
                $returnDisagreement = $generation['direction'] === 'return' && $projection !== null && ! $projection['ready'];
                if ($lifecycle === null && ($claims !== [] || $returnDisagreement)) {
                    $add('checklist.movement_disagreement', 'P2', $this->scope($trip, $current, $next, $custody, $now), 'Persisted lifecycle confirmation disagrees with canonical movement facts.', [(int) $trip['id']], ['generation' => $generation, 'confirmed_item_ids' => array_map('intval', array_column($claims, 'id')), 'canonical_ready' => $projection['ready'] ?? null], 'persisted_workflow_compared_to_movement', 'checklist');
                }
            }
        }
        $readiness = $this->readiness($data['readiness'] ?? [], $trips, $current, $next, $custody, $asOf, $add);
        $extras = $this->extras($data['commitments'], $trips, $current, $next, $custody, $readiness['items'], $asOf, $add);
        foreach (array_filter([$current, $next]) as $target) {
            if (! array_filter($generations, static fn (array $row): bool => $row['trip_id'] === (int) $target['id'] && $row['direction'] === 'pickup' && $row['is_current'])) {
                $add('readiness.source_refresh_needed', 'P3', $target === $current ? 'CURRENT' : 'FUTURE', 'Current schedule has no canonical pickup readiness projection.', [(int) $target['id']], ['trip' => $target, 'source' => 'missing_canonical_pickup_checklist'], 'schedule_and_readiness_coverage', 'checklist');
            }
        }
        usort($issues, static fn (array $a, array $b): int => [$a['severity'], $a['code'], $a['fingerprint']] <=> [$b['severity'], $b['code'], $b['fingerprint']]);
        $fingerprints = array_column($issues, 'fingerprint');
        sort($fingerprints);
        $pickupSeconds = $next === null ? null : (new DateTimeImmutable($next['starts_at'], $asOf->getTimezone()))->getTimestamp() - $asOf->getTimestamp();
        $nextWork = array_values(array_filter($readiness['actionable_work'], static fn (array $item): bool => (int) $item['trip_id'] === (int) ($next['id'] ?? 0)));
        return [
            'vehicle' => $vehicle, 'custody' => $custody, 'custody_basis_event' => $custody['basis_event'],
            'position' => $position, 'position_basis_event' => $basisEvent,
            'availability' => ['status' => (new VehicleAvailabilityService())->statusForVehicle($vehicle, $current === null ? null : array_merge($current, ['status_code' => $current['trip_status_code']])), 'custody' => $custody['custody'], 'operational_state' => $position['operational_state'], 'basis' => 'existing_custody_and_position_projection'],
            'import_current_trip' => $current, 'movement_current_trip' => $movementTrip, 'previous_relevant_trip' => $prior, 'next_confirmed_trip' => $next,
            'checklists' => $generations, 'readiness' => $readiness, 'actionable_work' => $readiness['actionable_work'], 'guest_commitments' => $extras,
            'next_trip_urgency' => ['pickup_at' => $next['starts_at'] ?? null, 'seconds_until_pickup' => $pickupSeconds,
                'state' => $next === null ? 'NO_NEXT_TRIP' : ($custody['custody'] === 'guest' ? 'AWAIT_RECOVERY' : (($pickupSeconds <= self::IMMINENT_HOURS * 3600 && (array_filter($nextWork, static fn (array $item): bool => $item['blocking']) !== [] || $positionIssues !== [])) ? 'ACTION_REQUIRED' : 'SCHEDULED')),
                'blocker_count' => count(array_filter($nextWork, static fn (array $item): bool => $item['blocking'])), 'position_known' => $positionIssues === [],
                'required_movement_codes' => array_column(array_filter($nextWork, static fn (array $item): bool => in_array($item['family'], ['pickup_location', 'staging_parking'], true)), 'code')],
            'issues' => $issues, 'highest_severity' => $issues[0]['severity'] ?? null,
            'issue_fingerprint' => ReconciliationIssue::fingerprint(['company_id' => $companyId, 'vehicle_id' => $id, 'issues' => $fingerprints]),
        ];
    }

    private function readiness(array $projections, array $trips, ?array $current, ?array $next, array $custody, DateTimeImmutable $asOf, callable $add): array
    {
        $items = $byPackage = $states = [];
        foreach ($projections as $projection) {
            $states[] = ['checklist_id' => $projection['checklist_id'], 'trip_id' => $projection['trip_id'], 'movement_type' => $projection['movement_type'], 'coverage' => $projection['coverage'] ?? 'FULL', 'ready' => $projection['ready'], 'blocking_remaining_count' => $projection['blocking_remaining_count'], 'persisted_workflow_state' => $projection['workflow_history']['stored_readiness_status'], 'completed_at' => $projection['workflow_history']['completed_at']];
            foreach ($projection['requirements'] as $requirement) {
                $tripId = (int) $requirement['trip_id'];
                $trip = $trips[$tripId] ?? null;
                if ($trip === null) {
                    continue;
                }
                $scope = $this->scope($trip, $current, $next, $custody, $asOf->format('Y-m-d H:i:s'));
                $pending = $requirement['status'] === 'unsatisfied';
                $historical = $scope === 'HISTORICAL' || $trip['trip_status_code'] === 'completed' || ! $requirement['relevant'] || $requirement['retired_reason'] !== null;
                $refresh = $requirement['source_type'] === 'extras_verification' || ($requirement['synchronization_required'] ?? false);
                $classification = $historical ? 'HISTORICAL_DERIVED' : ($refresh && $pending ? 'SOURCE_REFRESH_NEEDED' : ($pending && $requirement['actionable'] && $requirement['deferred_reason'] === null ? 'CURRENT_GENUINE' : 'ADVISORY'));
                $item = [
                    'trip_id' => $tripId, 'checklist_id' => $projection['checklist_id'], 'code' => $requirement['code'], 'phase' => $requirement['phase'],
                    'family' => $this->family($requirement), 'classification' => $classification, 'scope' => $historical ? 'HISTORICAL' : $scope,
                    'status' => $requirement['status'], 'blocking' => MovementReadinessProjectionService::isBlocking($requirement),
                    'actionable' => $pending && $requirement['actionable'] && ! $historical && $requirement['deferred_reason'] === null,
                    'deferred_reason' => $requirement['deferred_reason'], 'source_type' => $requirement['source_type'],
                    'source_basis' => ['satisfied_by' => $requirement['satisfied_by'], 'basis_at' => $requirement['basis_at'], 'selection_id' => $requirement['selection_id'], 'fulfillment_id' => $requirement['fulfillment_id'], 'commitment_id' => $requirement['commitment_id'], 'verification_snapshot_id' => $requirement['verification']['snapshot_id'] ?? null, 'assessment_id' => $projection['preparation_assessment']['id'] ?? null, 'assessment_event_id' => $projection['preparation_assessment']['trip_movement_event_id'] ?? null, 'energy_commitment_id' => $projection['energy_rule']['commitment_id'] ?? null, 'authority_source_ids' => $projection['source_ids'] ?? []],
                ];
                $normalizedPhase = $requirement['phase'] === 'next_pickup_preparation' ? 'pickup_preparation' : $requirement['phase'];
                $key = $tripId . ':' . $normalizedPhase . ':' . $requirement['code'];
                // Prefer the target pickup projection over next-prep derived on a prior return.
                if (! isset($items[$key]) || $projection['movement_type'] === 'pickup') {
                    $items[$key] = $item;
                }
            }
        }
        ksort($items);
        foreach ($items as $item) {
            if ($item['status'] !== 'unsatisfied') {
                continue;
            }
            if (in_array($item['family'], ['extras', 'guest_commitments'], true)) {
                continue; // Unified Extras comparisons own these root causes.
            }
            $code = match ($item['classification']) {
                'HISTORICAL_DERIVED' => 'readiness.historical_derived_only',
                'SOURCE_REFRESH_NEEDED' => 'readiness.source_refresh_needed',
                'CURRENT_GENUINE' => $item['blocking'] && in_array($item['phase'], ['pickup_preparation', 'next_pickup_preparation'], true) ? 'readiness.current_blocker' : null,
                default => null,
            };
            if ($code !== null) {
                $byPackage[$item['trip_id'] . ':' . $code]['code'] = $code;
                $byPackage[$item['trip_id'] . ':' . $code]['items'][] = $item;
            }
        }
        foreach ($byPackage as $package) {
            $first = $package['items'][0];
            $severity = $first['classification'] === 'HISTORICAL_DERIVED' ? 'P2' : ($first['classification'] === 'CURRENT_GENUINE' && $this->imminent($trips[$first['trip_id']], $asOf) ? 'P1' : 'P3');
            $add($package['code'], $severity, $first['scope'], 'Readiness authority identifies a preparation or source-review package.', [$first['trip_id']], ['items' => $package['items'], 'schedule' => $trips[$first['trip_id']]], 'movement_readiness_projection', 'readiness');
        }
        return ['projections' => $states, 'items' => array_values($items), 'actionable_work' => array_values(array_filter($items, static fn (array $item): bool => $item['actionable']))];
    }

    private function extras(array $projections, array $trips, ?array $current, ?array $next, array $custody, array $readiness, DateTimeImmutable $asOf, callable $add): array
    {
        $summaries = [];
        foreach ($projections as $tripId => $projection) {
            $trip = $trips[$tripId];
            $scope = $this->scope($trip, $current, $next, $custody, $asOf->format('Y-m-d H:i:s'));
            $verification = $projection['verification'];
            if ($verification['lifecycle'] === 'inactive') {
                $scope = 'HISTORICAL';
            }
            $summary = ['trip_id' => (int) $tripId, 'scope' => $scope, 'verification_state' => $verification['state'], 'refresh_required' => $verification['refresh_required'], 'snapshot_id' => $verification['snapshot_id'] ?? null, 'purchased_count' => count($projection['purchased']), 'manual_count' => count($projection['manual']), 'historical_count' => count($projection['history']), 'issues' => []];
            $groups = [];
            if ($scope !== 'HISTORICAL' && $verification['refresh_required']) {
                $groups['extras.refresh_required'][] = ['snapshot_id' => $verification['snapshot_id'] ?? null, 'state' => $verification['state'], 'observed_at' => $verification['observed_at']];
            }
            foreach (array_merge($projection['purchased'], $projection['history']) as $row) {
                if (($row['source_kind'] ?? '') !== 'purchased_extra') {
                    continue;
                }
                $isHistory = $scope === 'HISTORICAL' || ($row['completeness'] ?? '') === 'historical';
                if (! $isHistory && ! $row['is_mapped']) {
                    $code = 'extras.unmapped_purchase';
                } elseif (! ($row['is_completed'] ?? false) && ($isHistory || ($row['is_blocking'] ?? false) || ($row['is_actionable'] ?? false))) {
                    $code = 'extras.missing_fulfillment';
                } else {
                    continue;
                }
                $groups[$code . ($isHistory ? ':history' : '')][] = ['identity' => $row['identity'], 'selection_id' => $row['selection_id'], 'fulfillment_id' => $row['fulfillment_id'], 'snapshot_id' => $row['snapshot_id'] ?? null, 'operational_basis_hash' => $row['operational_basis_hash'] ?? null, 'blocking' => ! $isHistory && ($row['is_blocking'] ?? false), 'actionable' => ! $isHistory && ($row['is_actionable'] ?? false), 'synchronization_required' => $row['synchronization_required'] ?? false];
            }
            foreach ($projection['manual'] as $row) {
                if ($row['is_blocking'] && $scope !== 'HISTORICAL') {
                    $groups['extras.manual_commitment_incomplete'][] = ['commitment_id' => (int) $row['id'], 'phase' => $row['phase'], 'blocking' => true, 'state' => $row['state'], 'updated_at' => $row['updated_at']];
                }
            }
            foreach ($groups as $group => $evidence) {
                $code = explode(':', $group)[0];
                $issueScope = str_ends_with($group, ':history') ? 'HISTORICAL' : $scope;
                $canonicalItems = array_filter($readiness, static fn (array $item): bool => $item['trip_id'] === (int) $tripId && $item['scope'] !== 'HISTORICAL' && $item['deferred_reason'] === null && $item['blocking'] && in_array($item['family'], ['extras', 'guest_commitments'], true));
                $genuine = match ($code) {
                    'extras.refresh_required' => $verification['refresh_required'],
                    'extras.unmapped_purchase' => false,
                    default => $canonicalItems !== [] && (bool) array_filter($evidence, static fn (array $row): bool => $row['blocking'] ?? false),
                };
                $severity = $issueScope === 'HISTORICAL' ? 'P2' : ($genuine && $this->imminent($trip, $asOf) ? 'P1' : 'P3');
                $add($code, $severity, $issueScope, match ($code) {
                    'extras.refresh_required' => 'Extras source verification is required for pickup preparation.',
                    'extras.unmapped_purchase' => 'A purchased Extra has no operational mapping.',
                    'extras.manual_commitment_incomplete' => 'A blocking manual Guest Commitment remains incomplete.',
                    default => $issueScope === 'HISTORICAL' ? 'Historical Extra fulfillment remains unresolved for review.' : 'Required Extra fulfillment remains incomplete.',
                }, [(int) $tripId], ['rows' => $evidence, 'schedule' => $trip], 'unified_guest_commitment_projection', 'guest_commitments');
                $summary['issues'][] = ['code' => $code, 'scope' => $issueScope, 'severity' => $severity];
            }
            $summaries[] = $summary;
        }
        return $summaries;
    }

    private function family(array $requirement): string
    {
        return match (true) {
            in_array($requirement['source_type'], ['extra_fulfillment', 'extras_verification'], true) => 'extras',
            $requirement['source_type'] === 'trip_commitment' => 'guest_commitments',
            $requirement['code'] === 'vehicle_clean' => 'cleaning',
            in_array($requirement['code'], ['energy_known', 'energy_ready'], true) => 'energy_fuel',
            $requirement['code'] === 'photos_complete' => 'photos',
            in_array($requirement['code'], ['key_card_confirmed', 'charging_adapter_confirmed'], true) => 'keys_adapter',
            $requirement['code'] === 'location_confirmed' => 'pickup_location',
            in_array($requirement['code'], ['airport_staging', 'parking_location_recorded'], true) => 'staging_parking',
            default => 'other',
        };
    }

    private function imminent(array $trip, DateTimeImmutable $asOf): bool
    {
        return $trip['starts_at'] !== null && $trip['starts_at'] <= $asOf->modify('+' . self::IMMINENT_HOURS . ' hours')->format('Y-m-d H:i:s');
    }

    private function scope(array $trip, ?array $current, ?array $next, array $custody, string $now): string
    {
        if ((int) $trip['id'] === (int) ($current['id'] ?? 0) || ($custody['custody'] === 'guest' && (int) $trip['id'] === (int) $custody['basis_trip_id'])) {
            return 'CURRENT';
        }
        return $trip['starts_at'] > $now ? 'FUTURE' : 'HISTORICAL';
    }

    private function latest(array $events, array $codes): ?array
    {
        $latest = null;
        foreach ($events as $event) {
            if (in_array($event['event_code'], $codes, true) && ($latest === null || [$event['occurred_at'], (int) $event['id']] > [$latest['occurred_at'], (int) $latest['id']])) {
                $latest = $event;
            }
        }
        return $latest;
    }

    private function eventBasis(array $events): array
    {
        return array_map(static fn (array $event): array => ['id' => (int) $event['id'], 'trip_id' => isset($event['turo_trip_normalized_id']) ? (int) $event['turo_trip_normalized_id'] : null, 'code' => $event['event_code'], 'occurred_at' => $event['occurred_at'], 'location_class' => $event['location_class'] ?? null], $events);
    }
}
