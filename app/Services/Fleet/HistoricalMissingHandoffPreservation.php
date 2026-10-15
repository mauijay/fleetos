<?php

namespace App\Services\Fleet;

use RuntimeException;

/** Exception policy used exclusively by historical_missing_handoff.v1. */
final class HistoricalMissingHandoffPreservation
{
    public function closedTrip(int $tripId, array $inputs): bool
    {
        $trip = array_column($inputs['commercial']['trips'], null, 'id')[$tripId] ?? null;
        if ($trip === null || $trip['deleted_at'] !== null || $trip['canceled_at'] !== null
            || ! in_array($trip['trip_status_code'], ['completed', 'in_progress'], true)) {
            return false;
        }
        $events = array_values(array_filter($inputs['timeline'], static fn ($e) => (int) $e['turo_trip_normalized_id'] === $tripId));
        $closing = array_values(array_filter($events, static fn ($e) => in_array($e['event_code'], ['actual_return', 'vehicle_recovered'], true)));
        if (count($closing) !== 1) {
            return false;
        }
        foreach ($events as $event) {
            if (in_array($event['event_code'], ['actual_handoff', 'guest_return_staged'], true) && $event['occurred_at'] >= $closing[0]['occurred_at']) {
                return false;
            }
        }
        return true;
    }

    /** Both complete projections are retained; only this exact four-field retirement may compare equal. */
    public function compare(array $r, array $inputs, array $before, array $after, array $currentBefore, array $currentAfter): array
    {
        if ($currentBefore !== $currentAfter) {
            throw new RuntimeException('Historical handoff changes current/future actionable authority.');
        }
        $ignored = [];
        foreach ($before['readiness'] as $id => $row) {
            foreach ($row['requirements'] as $index => $action) {
                $next = $after['readiness'][$id]['requirements'][$index] ?? null;
                if ($action === $next) {
                    continue;
                }
                $context = $inputs['contexts'][$id] ?? [];
                $target = (int) ($action['trip_id'] ?? 0);
                $family = match ($action['code'] ?? null) {
                    'vehicle_clean' => 'cleaning', 'energy_ready' => 'energy', default => null,
                };
                $retired = array_replace($action, ['action' => null, 'relevant' => false, 'actionable' => false, 'retired_reason' => 'target_handoff']);
                if ($family === null || ! is_array($next) || count($next) !== count($retired) || array_replace($retired, $next) !== $retired || ($action['actionable'] ?? null) !== true || ($action['relevant'] ?? null) !== true
                    || ($action['retired_reason'] ?? null) !== null || ($action['status'] ?? null) !== 'unsatisfied'
                    || ($action['source_type'] ?? null) !== 'derived' || ($action['phase'] ?? null) !== 'next_pickup_preparation'
                    || ($context['movement_type'] ?? null) !== 'return' || $target !== $r['trip_id']
                    || (int) ($context['next_trip']['id'] ?? 0) !== $target || (int) ($action['target_trip_id'] ?? 0) !== $target
                    || ($action['action']['type'] ?? null) !== 'record_fact' || ($action['selection_id'] ?? null) !== null || ($action['commitment_id'] ?? null) !== null || ($action['fulfillment_id'] ?? null) !== null
                    || array_key_exists($id, $currentBefore['readiness'])
                    || ! $this->closedTrip((int) $row['trip_id'], $inputs) || ! $this->closedTrip($target, $inputs)) {
                    throw new RuntimeException('Historical derived affordance is not an eligible retirement.');
                }
                // An independently unresolved target obligation is never historical
                // merely because its source workspace was recovered or superseded.
                $verification = $currentBefore['extras'][$target]['verification'] ?? null;
                if ($verification === null || $verification['refresh_required'] || $verification['advisory'] || $verification['optional_active_refresh']) {
                    throw new RuntimeException('Historical target still has current Extras verification work.');
                }
                foreach (['cleaning', 'energy'] as $authorityFamily) {
                    foreach ($currentBefore[$authorityFamily] as $need) {
                        if ((int) ($need['target_trip_id'] ?? 0) === $target) {
                            throw new RuntimeException('Historical target still has current ' . $authorityFamily . ' work.');
                        }
                    }
                }
                // Restore only the four derived action fields for exact equality.
                $after['readiness'][$id]['requirements'][$index] = $action;
                $ignored[] = ['checklist_id' => (int) $id, 'source_trip_id' => (int) $row['trip_id'], 'target_trip_id' => $target,
                    'family' => $family, 'code' => $action['code'], 'phase' => $action['phase'],
                    'reason' => 'canonical_source_and_target_closed; persisted_rows_unchanged; current_future_authority_identical',
                    'lifecycle_fingerprint' => hash('sha256', json_encode([$context, $inputs['timeline'], $inputs['commercial']['trips']], JSON_THROW_ON_ERROR))];
            }
        }
        if ($before !== $after) {
            throw new RuntimeException('Historical handoff changes protected authority beyond approved retirements.');
        }
        return $ignored;
    }
}
