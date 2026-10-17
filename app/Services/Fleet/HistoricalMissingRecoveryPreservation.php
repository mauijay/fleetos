<?php

namespace App\Services\Fleet;

use RuntimeException;

/** Expected recovery dependencies, with exact preservation of independent authority. */
final class HistoricalMissingRecoveryPreservation
{
    public function compare(array $r, array $inputs, array $before, array $after): array
    {
        $a = $before['authority'];
        $b = $after['authority'];
        $release = $a['custody']['custody'] === 'guest' && (int) $a['custody']['active_trip_id'] === $r['trip_id'];
        if ($release) {
            if ($b['custody']['custody'] !== 'operator' || $b['custody']['basis_event_id'] !== -1 || $b['custody']['integrity_conflict']) {
                throw new RuntimeException('Unrelated custody change; target recovery must release guest possession.');
            }
        } elseif ($a['custody'] !== $b['custody']) {
            throw new RuntimeException('Later custody authority must remain unchanged.');
        }
        $position = $b['position'];
        if ($a['position'] !== $position && (int) $position['event_id'] !== -1) {
            $later = $inputs['location'];
            if ($later === null || $later['event_code'] !== 'vehicle_positioned' || $later['occurred_at'] <= $r['recovery']['occurred_at'] || (int) $later['id'] !== (int) $position['event_id']) {
                throw new RuntimeException('Unrelated vehicle position change.');
            }
        }
        if ($a['availability'] !== $b['availability']) {
            throw new RuntimeException('Unrelated availability or reservation change.');
        }
        if ($a['current_readiness'] !== $b['current_readiness']) {
            $expected = $a['current_readiness'];
            if (! $release || $expected === null) {
                throw new RuntimeException('Recovery cannot invent or replace unrelated current readiness.');
            }
            foreach (['cleanliness', 'energy_percent'] as $field) {
                if (($expected['_' . $field . '_captured_at'] ?? '') <= $r['recovery']['occurred_at']) {
                    $expected[$field] = null;
                    unset($expected['_' . $field . '_captured_at']);
                }
            }
            $expected = $expected['cleanliness'] === null && $expected['energy_percent'] === null ? null : $expected;
            if ($expected !== $b['current_readiness']) {
                throw new RuntimeException('Unrelated current readiness change.');
            }
        }
        if (array_keys($a['readiness']) !== array_keys($b['readiness'])) {
            throw new RuntimeException('Checklist membership changed.');
        }
        foreach ($a['readiness'] as $id => $row) {
            $this->readiness($r, $row, $b['readiness'][$id], $release);
        }
        $retired = [];
        $currentA = $before['current_future'];
        $currentB = $after['current_future'];
        foreach (array_unique(array_merge(array_keys($currentA['readiness']), array_keys($currentB['readiness']))) as $id) {
            $left = $currentA['readiness'][$id] ?? null;
            $right = $currentB['readiness'][$id] ?? null;
            if ($left === $right) {
                continue;
            }
            if ($left !== null && $right !== null && isset($left['requirements'], $right['requirements'])) {
                $this->readiness($r, $left, $right, $release);
                continue;
            }
            $owner = $a['readiness'][$id] ?? null;
            if ($owner === null) {
                throw new RuntimeException('Unknown current/future readiness owner.');
            }
            if ((int) $owner['trip_id'] === $r['trip_id']) {
                // Closing the target may remove its historical workspace or
                // retain only its canonical next-pickup preparation requirements.
                $next = array_values(array_filter($b['readiness'][$id]['requirements'], static fn ($q) => $q['phase'] === MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION));
                if ($left === null || ($right !== null && $right !== $next)) {
                    throw new RuntimeException('Unrelated target readiness membership changed.');
                }
                continue;
            }
            if ($this->inactiveTargetPreparation($r, $inputs, $id, $owner, $left, $right, $before, $after, $retired)) {
                continue;
            }
            if ($left === null || $right === null || ! array_is_list($left) || ! array_is_list($right)) {
                throw new RuntimeException('Unrelated current/future readiness membership changed.');
            }
            $this->readiness($r, ['trip_id' => $owner['trip_id'], 'requirements' => $left], ['trip_id' => $owner['trip_id'], 'requirements' => $right], $release);
        }
        if ($currentA['extras'] !== $currentB['extras'] || $currentA['next_trip'] !== $currentB['next_trip']) {
            throw new RuntimeException('Unrelated Extras, Guest Commitment or future schedule change.');
        }
        $execution = array_values(array_filter($currentA['execution'], static fn ($w) => ! ((int) $w['trip_id'] === $r['trip_id'] && $w['movement_type'] === 'return')));
        if ($execution !== $currentB['execution']) {
            throw new RuntimeException('Unrelated movement execution work changed.');
        }
        foreach ($currentA['preparation'] as $id => $row) {
            if (! isset($currentB['preparation'][$id])) {
                if ((int) $id !== $r['trip_id']) {
                    throw new RuntimeException('Unrelated preparation membership changed.');
                }
                continue;
            }
            $this->readiness($r, $row, $currentB['preparation'][$id], $release);
        }
        if (array_diff(array_keys($currentB['preparation']), array_keys($currentA['preparation'])) !== []) {
            throw new RuntimeException('Unrelated preparation workspace appeared.');
        }
        foreach (['cleaning', 'energy'] as $family) {
            foreach ($currentB[$family] as $work) {
                if ((int) $work['fleet_vehicle_id'] !== $r['vehicle_id']) {
                    throw new RuntimeException('Unrelated vehicle work changed.');
                }
            }
        }
        $changes = [];
        foreach (['custody', 'position', 'current_readiness', 'readiness'] as $family) {
            if ($a[$family] !== $b[$family]) {
                $changes[] = ['family' => $family, 'classification' => 'expected_recovery_dependency', 'before' => $a[$family], 'after' => $b[$family]];
            }
        }
        if ($before['return_confirmation'] !== $after['return_confirmation']) {
            $changes[] = ['family' => 'return_confirmation', 'classification' => 'target_return_confirmation_retired', 'before' => $before['return_confirmation'], 'after' => $after['return_confirmation']];
        }
        if ($currentA['readiness'] !== $currentB['readiness']) {
            $changes[] = ['family' => 'current_future_readiness', 'classification' => 'expected_recovery_dependency', 'before' => $currentA['readiness'], 'after' => $currentB['readiness']];
        }
        foreach (['cleaning', 'energy', 'preparation', 'execution'] as $family) {
            if ($currentA[$family] !== $currentB[$family]) {
                $changes[] = ['family' => $family, 'classification' => 'expected_recovery_dependency', 'before' => $currentA[$family], 'after' => $currentB[$family]];
            }
        }
        if ($retired !== []) {
            $changes[] = ['family' => 'historical_extra_membership', 'classification' => 'inactive_historical_purchased_extra_retirement', 'entries' => $retired,
                'commercial_fingerprint' => hash('sha256', json_encode([$inputs['commercial'], $currentA['extras'], $currentB['extras']], JSON_THROW_ON_ERROR))];
        }
        return $changes;
    }

    /** Only explanatory membership of already-inactive target preparation may retire. */
    private function inactiveTargetPreparation(array $r, array $inputs, int $id, array $owner, ?array $before, ?array $after, array $beforeViews, array $afterViews, array &$retired): bool
    {
        $context = $inputs['canonical_contexts'][$id] ?? [];
        if ($after !== null || $before === null || ! array_is_list($before)
            || ($context['movement_type'] ?? null) !== 'return' || (int) ($context['next_trip']['id'] ?? 0) !== $r['trip_id']) {
            return false;
        }
        $policy = new HistoricalMissingHandoffPreservation();
        $closed = $inputs;
        $closed['timeline'][] = ['turo_trip_normalized_id' => $r['trip_id'], 'event_code' => 'vehicle_recovered', 'occurred_at' => $r['recovery']['occurred_at']];
        if (! $policy->closedTrip((int) $owner['trip_id'], $inputs) || ! $policy->closedTrip($r['trip_id'], $closed)) {
            return false;
        }
        $canonical = array_values(array_filter($beforeViews['authority']['readiness'][$id]['requirements'], static fn ($row) => $row['phase'] === MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION));
        if ($before !== $canonical) {
            return false;
        }
        $candidates = [];
        foreach ($before as $row) {
            if (($row['source_type'] ?? null) === 'extra_fulfillment') {
                $evidence = $this->inactivePurchasedExtra($r, $inputs, $id, $owner, $row, $beforeViews, $afterViews, $closed);
                if ($evidence === null) {
                    return false;
                }
                $candidates[] = $evidence;
                continue;
            }
            $physical = in_array($row['code'], ['vehicle_clean', 'energy_known', 'energy_ready'], true) && ($row['source_type'] ?? null) === 'derived';
            $verification = ($row['source_type'] ?? null) === 'extras_verification' && $row['code'] === 'extras_verification_' . $r['company_id'] . '_' . $r['trip_id'];
            if ((! $physical && ! $verification) || $row['phase'] !== MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION
                || (int) $row['company_id'] !== $r['company_id'] || (int) $row['vehicle_id'] !== $r['vehicle_id']
                || (int) $row['trip_id'] !== $r['trip_id'] || (int) $row['target_trip_id'] !== $r['trip_id']
                || ! in_array($row['status'] ?? null, ['satisfied', 'not_applicable', 'unsatisfied'], true) || ($row['action'] ?? null) !== null
                || (($row['status'] ?? null) === 'unsatisfied' && (($row['actionable'] ?? null) !== false || ($row['relevant'] ?? null) !== false || ($row['retired_reason'] ?? null) !== 'target_handoff'))
                || ($row['selection_id'] ?? null) !== null || ($row['fulfillment_id'] ?? null) !== null || ($row['commitment_id'] ?? null) !== null
                || ($verification && (($row['verification']['refresh_required'] ?? true) || ($row['verification']['advisory'] ?? true) || ($row['verification']['optional_active_refresh'] ?? true)))) {
                return false;
            }
        }
        array_push($retired, ...$candidates);
        return true;
    }

    /** Recovery-only membership exception; the full historical requirement never changes. */
    private function inactivePurchasedExtra(array $r, array $inputs, int $id, array $owner, array $row, array $before, array $after, array $closed): ?array
    {
        $context = $inputs['canonical_contexts'][$id];
        $extra = $row['extra_fulfillment'] ?? [];
        $selection = (int) ($row['selection_id'] ?? 0);
        $fulfillment = (int) ($row['fulfillment_id'] ?? 0);
        if (($row['code'] ?? null) !== 'extra_fulfillment_' . $fulfillment || $selection < 1 || $fulfillment < 1
            || ($row['phase'] ?? null) !== MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION
            || ($row['status'] ?? null) !== 'unsatisfied' || ($row['actionable'] ?? null) !== false || ($row['relevant'] ?? null) !== false
            || ! array_key_exists('action', $row) || $row['action'] !== null || ($row['retired_reason'] ?? null) !== 'target_handoff'
            || ($row['commitment_id'] ?? null) !== null || ($row['synchronization_required'] ?? true) !== false
            || (int) ($owner['company_id'] ?? 0) !== $r['company_id'] || (int) ($owner['vehicle_id'] ?? 0) !== $r['vehicle_id']
            || (int) ($owner['checklist_id'] ?? 0) !== $id || (int) ($context['id'] ?? 0) !== $id
            || (int) ($context['turo_trip_normalized_id'] ?? 0) !== (int) $owner['trip_id']) {
            return null;
        }
        foreach (['company_id' => $r['company_id'], 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'], 'target_trip_id' => $r['trip_id']] as $key => $value) {
            if ((int) ($row[$key] ?? 0) !== $value) {
                return null;
            }
        }
        if ((int) ($context['company_id'] ?? 0) !== $r['company_id'] || (int) ($context['fleet_vehicle_id'] ?? 0) !== $r['vehicle_id']
            || (int) ($extra['company_id'] ?? 0) !== $r['company_id'] || (int) ($extra['fleet_vehicle_id'] ?? 0) !== $r['vehicle_id']
            || (int) ($extra['turo_trip_normalized_id'] ?? 0) !== $r['trip_id']
            || (int) ($extra['selection_id'] ?? 0) !== $selection || (int) ($extra['fulfillment_id'] ?? 0) !== $fulfillment
            || ($extra['source_kind'] ?? null) !== 'purchased_extra' || ($extra['fulfillment_state'] ?? null) !== 'pending'
            || ($extra['is_completed'] ?? null) !== false || ($extra['completed_at'] ?? null) !== null
            || ($extra['completed_by_user_id'] ?? null) !== null || ($extra['completed_basis_hash'] ?? null) !== null
            || ($extra['is_actionable'] ?? null) !== false || ($extra['is_blocking'] ?? null) !== false
            || ($extra['is_suppressed_after_handoff'] ?? null) !== true || ($extra['permitted_actions'] ?? null) !== []
            || ($extra['review_required'] ?? null) !== true || ($extra['synchronization_required'] ?? true) !== false
            || ! in_array($extra['fulfillment_phase'] ?? null, ['preparation', 'pickup'], true)) {
            return null;
        }
        // The source/target closure checks are shared with the existing physical
        // rule. The target must be open before, closed only by this recovery.
        if ((new HistoricalMissingHandoffPreservation())->closedTrip($r['trip_id'], $inputs)) {
            return null;
        }
        foreach ([(int) $owner['trip_id'], $r['trip_id']] as $tripId) {
            $trips = array_values(array_filter($inputs['commercial']['trips'], static fn ($trip) => (int) $trip['id'] === $tripId
                && (int) $trip['company_id'] === $r['company_id'] && (int) $trip['fleet_vehicle_id'] === $r['vehicle_id']));
            if (count($trips) !== 1) {
                return null;
            }
        }
        $commercial = $before['current_future']['extras'][$r['trip_id']] ?? null;
        if ($commercial === null || $commercial !== ($after['current_future']['extras'][$r['trip_id']] ?? null)) {
            return null;
        }
        foreach (['refresh_required', 'advisory', 'optional_active_refresh'] as $flag) {
            if (($commercial['verification'][$flag] ?? null) !== false || ($extra['verification'][$flag] ?? null) !== false) {
                return null;
            }
        }
        foreach ($commercial['rows'] as $work) {
            if (($work['is_actionable'] ?? false) || ($work['is_blocking'] ?? false) || ($work['permitted_actions'] ?? []) !== []
                || ($work['source_kind'] ?? null) === 'manual') {
                return null;
            }
        }
        $historical = [];
        foreach ([$before, $after] as $view) {
            $matches = array_values(array_filter($view['authority']['readiness'][$id]['requirements'] ?? [], static fn ($q) => $q['phase'] === $row['phase'] && $q['code'] === $row['code']));
            if ($matches !== [$row]) {
                return null;
            }
            $historical[] = $matches[0];
        }
        $raw = array_values(array_filter($inputs['commercial']['selections'], static fn ($q) => (int) $q['selection_id'] === $selection && (int) $q['fulfillment_id'] === $fulfillment));
        $purchased = array_values(array_filter($commercial['purchased'] ?? [], static fn ($q) => (int) $q['selection_id'] === $selection && (int) $q['fulfillment_id'] === $fulfillment));
        $snapshots = array_values(array_filter($inputs['commercial']['snapshots'], static fn ($q) => (int) $q['id'] === (int) ($extra['snapshot_id'] ?? 0)
            && (int) $q['company_id'] === $r['company_id'] && (int) $q['turo_trip_normalized_id'] === $r['trip_id']));
        if (count($raw) !== 1 || count($purchased) !== 1 || count($snapshots) !== 1
            || (int) $raw[0]['company_id'] !== $r['company_id'] || (int) $raw[0]['fleet_vehicle_id'] !== $r['vehicle_id']
            || (int) $raw[0]['turo_trip_normalized_id'] !== $r['trip_id'] || $raw[0]['fulfillment_state'] !== 'pending'
            || $purchased[0] !== $extra
            || $purchased[0]['review_required'] !== true || $purchased[0]['permitted_actions'] !== []
            || $purchased[0]['fulfillment_state'] !== $extra['fulfillment_state'] || $purchased[0]['is_completed'] !== $extra['is_completed']) {
            return null;
        }
        return ['checklist_id' => $id, 'source_trip_id' => (int) $owner['trip_id'], 'target_trip_id' => $r['trip_id'],
            'code' => $row['code'], 'selection_id' => $selection, 'fulfillment_id' => $fulfillment, 'completion_state' => $extra['fulfillment_state'],
            'reason' => 'source_closed; target_closed_by_recovery; already_inactive; historical_review_and_commercial_authority_unchanged',
            'evidence_fingerprint' => hash('sha256', json_encode([$raw, $snapshots, $historical, $purchased, $context, $inputs['timeline'], $closed['timeline']], JSON_THROW_ON_ERROR))];
    }

    private function readiness(array $r, array $before, array $after, bool $release): void
    {
        $derived = ['ready', 'blocking_remaining_count', 'additional_actions_remaining_count', 'human_actions_remaining_count', 'known_facts_satisfied_count', 'requirements', 'preparation_assessment', 'last_known_vehicle_facts', 'is_same_day_turnaround'];
        if (array_diff_key($before, array_flip($derived)) !== array_diff_key($after, array_flip($derived))) {
            throw new RuntimeException('Unrelated checklist state, readiness identity or evidence changed.');
        }
        foreach ([$before, $after] as $row) {
            if (isset($row['readiness_phase'], $row['blocking_remaining_count'])) {
                $blocking = count(array_filter($row['requirements'], static fn ($q) => $q['phase'] === $row['readiness_phase'] && MovementReadinessProjectionService::isBlocking($q)));
                if ($row['blocking_remaining_count'] !== $blocking || $row['ready'] !== ($blocking === 0)) {
                    throw new RuntimeException('Unrelated readiness blocker count changed.');
                }
            }
        }
        $a = $this->requirements($before['requirements']);
        $b = $this->requirements($after['requirements']);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if ($left === $right) {
                continue;
            }
            $action = $left ?? $right;
            if ((int) ($action['vehicle_id'] ?? 0) !== $r['vehicle_id']) {
                throw new RuntimeException('Unrelated readiness vehicle changed.');
            }
            if (in_array($action['code'], ['vehicle_clean', 'energy_known', 'energy_ready'], true)) {
                if ($left !== null && $right !== null) {
                    $immutable = ['code', 'phase', 'kind', 'blocking', 'company_id', 'trip_id', 'reservation_id', 'vehicle_id', 'work_identity', 'source_type', 'allows_na', 'target_trip_id', 'target_at', 'selection_id', 'fulfillment_id', 'commitment_id', 'energy_rule'];
                    if (array_intersect_key($left, array_flip($immutable)) !== array_intersect_key($right, array_flip($immutable))) {
                        throw new RuntimeException('Unrelated cleaning/energy identity or policy changed.');
                    }
                }
                // Only canonical overlay/projector output reaches this comparison;
                // actual persisted output must independently match it during apply.
                continue;
            }
            if ($action['code'] === 'vehicle_recovery' && (int) ($before['trip_id'] ?? 0) === $r['trip_id']) {
                continue;
            }
            $availability = ['action', 'actionable', 'deferred_reason', 'deferred_label', 'custody_event_id', 'custody_trip_id'];
            if ($release && $left !== null && $right !== null
                && (int) ($left['custody_trip_id'] ?? $r['trip_id']) === $r['trip_id']
                && in_array($left['deferred_reason'] ?? null, ['target_trip_guest_custody', 'other_trip_guest_custody'], true)
                && array_diff_key($left, array_flip($availability)) === array_diff_key($right, array_flip($availability))) {
                continue;
            }
            throw new RuntimeException('Unrelated readiness family or obligation changed: ' . $action['code']);
        }
    }

    private function requirements(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = $row['phase'] . ':' . $row['code'];
            if (isset($result[$key])) {
                throw new RuntimeException('Ambiguous readiness action identity.');
            }
            $result[$key] = $row;
        }
        return $result;
    }
}
