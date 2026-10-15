<?php

namespace App\Services\Fleet;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/** Frozen canonical inputs with one recovery/assessment overlay, never a database write. */
class HistoricalMissingRecoveryProjection
{
    private readonly HistoricalMissingHandoffProjection $canonical;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->canonical = new HistoricalMissingHandoffProjection($db);
    }

    public function capture(array $r, array $state, DateTimeImmutable $now): array
    {
        $capture = $this->canonical->capture($r, $state, $now);
        $capture['inputs']['contexts'] = $capture['inputs']['canonical_contexts'];
        return ['inputs' => $capture['inputs'], 'views' => $this->views($r, $capture['inputs'], $now)];
    }

    public function views(array $r, array $inputs, DateTimeImmutable $now): array
    {
        $custody = (new CurrentVehicleCustodyService())->resolveEvents($inputs['timeline']);
        $overdue = (int) ($custody['active_trip_id'] ?? 0) === $r['trip_id']
            && in_array($custody['basis_event_code'], ['actual_handoff', 'guest_return_staged'], true)
            && $this->trip($r['trip_id'], $inputs)['ends_at'] <= $now->format('Y-m-d H:i:s');
        return ['authority' => $this->canonical->project($r, $inputs, $now),
            'current_future' => $this->canonical->currentFuture($r, $inputs, $now),
            'return_confirmation' => ['trip_id' => $r['trip_id'], 'overdue' => $overdue,
                'action' => $overdue ? 'confirm_return' : null]];
    }

    public function withRecovery(array $r, array $inputs, array $event, array $assessment): array
    {
        $trip = $this->trip($r['trip_id'], $inputs);
        $event += ['custody_trip_starts_at' => $trip['starts_at'], 'custody_trip_ends_at' => $trip['ends_at'],
            'custody_trip_status_code' => $trip['status_code'], 'custody_trip_canceled_at' => $trip['canceled_at'],
            'fleet_code' => $inputs['availability_vehicle']['fleet_code'], 'display_name' => $inputs['availability_vehicle']['display_name']];
        $inputs['timeline'][] = $event;
        if ($inputs['location'] === null || $inputs['location']['occurred_at'] < $event['occurred_at']) {
            // latestLocationEvent has these schedule joins but no vehicle display joins.
            $location = $event;
            unset($location['fleet_code'], $location['display_name']);
            $inputs['location'] = $location;
        }
        $custodyService = new CurrentVehicleCustodyService();
        $custody = $custodyService->resolveEvents($inputs['timeline']);
        $lastUse = $custodyService->resolveEvents(array_values(array_filter($inputs['timeline'], static fn ($e) => $e['event_code'] !== 'vehicle_staged')))['basis_event'];
        foreach ($inputs['canonical_contexts'] as &$context) {
            $context['vehicle_custody'] = $custody;
            $context['latest_custody_event'] = $custody['basis_event'];
            $context['latest_vehicle_use_event'] = $lastUse;
            if ((int) $context['turo_trip_normalized_id'] === $r['trip_id'] && $context['movement_type'] === 'return') {
                $context['active_events']['vehicle_recovered'] = array_intersect_key($event, array_flip(['id', 'company_id', 'fleet_vehicle_id', 'turo_trip_normalized_id', 'event_code', 'movement_type', 'occurred_at', 'location_class', 'location_detail', 'airport_garage_code', 'airport_parking_level', 'airport_parking_row', 'source', 'actor_user_id', 'note', 'supersedes_event_id', 'voided_at', 'voided_by_user_id', 'void_reason', 'created_at', 'updated_at']));
                $context['active_assessment'] = $assessment;
            }
            $known = $context['last_known_vehicle_assessment'] ?? ['cleanliness' => null, 'energy_percent' => null];
            foreach (['cleanliness', 'energy_percent'] as $field) {
                if ($assessment[$field] !== null && ($known[$field] === null || ($known['_' . $field . '_captured_at'] ?? '') < $assessment['captured_at'])) {
                    $known[$field] = $assessment[$field];
                    $known['_' . $field . '_captured_at'] = $assessment['captured_at'];
                    $known['_' . $field . '_assessment_id'] = $assessment['id'];
                    $known['_' . $field . '_event_id'] = $event['id'];
                    $known['_' . $field . '_event_code'] = $event['event_code'];
                    $known['_' . $field . '_event_occurred_at'] = $event['occurred_at'];
                    $known['_' . $field . '_trip_id'] = $r['trip_id'];
                }
            }
            $target = $context['movement_type'] === 'return' ? (int) ($context['next_trip']['id'] ?? 0) : (int) $context['turo_trip_normalized_id'];
            $sameDayEnergy = in_array($known['_energy_percent_event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
                && $this->sameDayNext((int) ($known['_energy_percent_trip_id'] ?? 0), $target, $inputs);
            $known['_energy_percent_applicable'] = ($known['_energy_percent_event_code'] ?? null) === 'vehicle_readiness_observed' || $sameDayEnergy;
            $known['_cleanliness_applicable'] = true;
            $context['prior_trip_is_same_day_turnaround'] = $context['movement_type'] === 'pickup' && $sameDayEnergy;
            $context['last_known_vehicle_assessment'] = $known;
        }
        unset($context);
        $inputs['contexts'] = $inputs['canonical_contexts'];
        foreach (['cleanliness' => 'physical_cleanliness', 'energy_percent' => 'physical_energy'] as $field => $key) {
            $old = $inputs[$key][$r['vehicle_id']] ?? null;
            if ($assessment[$field] !== null && ($old === null || $old['captured_at'] < $assessment['captured_at'])) {
                $columns = $field === 'cleanliness' ? ['id', 'fleet_vehicle_id', 'cleanliness', 'captured_at'] : ['id', 'fleet_vehicle_id', 'trip_movement_event_id', 'energy_percent', 'captured_at'];
                $inputs[$key][$r['vehicle_id']] = array_intersect_key($assessment, array_flip($columns));
            }
        }
        $current = $inputs['current_readiness'];
        if ($current !== null) {
            foreach (['cleanliness', 'energy_percent'] as $field) {
                if (($current['_' . $field . '_captured_at'] ?? '') <= ($custody['occurred_at'] ?? '')) {
                    $current[$field] = null;
                    unset($current['_' . $field . '_captured_at']);
                }
            }
            $inputs['current_readiness'] = $current['cleanliness'] === null && $current['energy_percent'] === null ? null : $current;
        }
        return $inputs;
    }

    private function trip(int $id, array $inputs): array
    {
        return array_column($inputs['reservations'], null, 'id')[$id];
    }

    private function sameDayNext(int $source, int $target, array $inputs): bool
    {
        if ($source < 1 || $target < 1 || $source === $target) {
            return false;
        }
        $trip = $this->trip($source, $inputs);
        foreach ($inputs['reservations'] as $candidate) {
            if ((int) $candidate['id'] !== $source && $candidate['starts_at'] >= $trip['ends_at'] && $candidate['ends_at'] > $candidate['starts_at']) {
                return (int) $candidate['id'] === $target && substr($candidate['starts_at'], 0, 10) === substr($trip['ends_at'], 0, 10);
            }
        }
        return false;
    }

    /** Normalize only new artifact IDs/timestamps, not existing authority or measurements. */
    public function normalize(array $value, int $event = -1, int $assessment = -2): array
    {
        $new = ($value['source'] ?? null) === HistoricalMissingRecoveryRepairService::OPERATION
            && ((isset($value['event_code']) && (int) ($value['id'] ?? 0) === $event)
                || (! isset($value['event_code']) && (int) ($value['id'] ?? 0) === $assessment && (int) ($value['trip_movement_event_id'] ?? 0) === $event));
        if ($new) {
            $value['id'] = isset($value['event_code']) ? -1 : -2;
            $value['created_at'] = null;
            $value['updated_at'] = null;
        }
        if (! isset($value['source']) && isset($value['id'], $value['fleet_vehicle_id'], $value['captured_at']) && (int) $value['id'] === $assessment
            && array_diff(array_keys($value), ['id', 'fleet_vehicle_id', 'cleanliness', 'energy_percent', 'trip_movement_event_id', 'captured_at']) === []) {
            $value['id'] = -2;
        }
        foreach ($value as $key => &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item, $event, $assessment);
            } elseif (in_array($key, ['event_id', 'basis_event_id', 'trip_movement_event_id', '_cleanliness_event_id', '_energy_percent_event_id'], true) && $item !== null && (int) $item === $event) {
                $item = -1;
            } elseif (in_array($key, ['_cleanliness_assessment_id', '_energy_percent_assessment_id'], true) && $item !== null && (int) $item === $assessment) {
                $item = -2;
            }
        }
        unset($item);
        return $value;
    }
}
