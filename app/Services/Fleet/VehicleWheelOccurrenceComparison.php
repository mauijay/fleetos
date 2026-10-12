<?php

namespace App\Services\Fleet;

/** Presentation of frozen v1 evidence. Never queries today's condition to rebuild a baseline. */
final class VehicleWheelOccurrenceComparison
{
    public static function forOccurrence(array $member): array
    {
        $read = VehicleDamageOccurrenceBasisSnapshot::read($member['basis_snapshot'] ?? null);
        $result = ['state' => $read['state'],'heading' => 'Comparison basis not recorded','snapshot' => $read['snapshot'],'baseline' => null,'before' => null,'after' => $member['note'] ?? null,'after_heading' => self::afterHeading($member),'attribution' => \Config\VehicleDamage::ATTRIBUTIONS[$member['attribution_type'] ?? 'unknown'] ?? 'Attribution not recorded'];
        if ($read['snapshot'] === null) {
            return $result;
        }
        $snapshot = $read['snapshot'];
        $comparison = $snapshot['comparison'];
        $baseline = $comparison['baseline'];
        $result['baseline'] = $baseline;
        $result['before'] = null;
        $result['heading'] = 'No documented pre-trip baseline';
        if ($baseline === null) {
            return $result;
        }
        $result['heading'] = 'Last recorded condition';
        $sameTrip = $comparison['trip_id'] !== null && (int)($member['trip_id'] ?? 0) === $comparison['trip_id'] && (int)($member['owned_trip_id'] ?? 0) === $comparison['trip_id'];
        $sameIdentity = $snapshot['panel_code'] === ($member['panel_code'] ?? null) && $snapshot['damage_type_code'] === ($member['damage_type_code'] ?? null);
        $boundary = $comparison['boundary_at'];
        $beforeBoundary = $boundary !== null && $baseline['recorded_at'] <= $boundary && ($baseline['observed_at'] === null || $baseline['observed_at'] < $boundary);
        if ($sameTrip && $sameIdentity && $beforeBoundary && $comparison['boundary_kind'] === 'scheduled_start') {
            $result['heading'] = 'Recorded before scheduled pickup';
        } elseif ($sameTrip && $sameIdentity && $beforeBoundary && $comparison['boundary_kind'] === 'actual_handoff' && $baseline['kind'] === 'observed_existing' && $baseline['incident_item_id'] !== null) {
            $result['heading'] = 'Observed before this guest';
        }
        // Prior-condition text is not automatically the explicitly selected baseline's text.
        $prior = $snapshot['prior_condition'];
        $occurrence = $snapshot['prior_occurrence'];
        if ($prior === null || $prior['canonical_condition_item_id'] !== $baseline['canonical_condition_item_id'] || $prior['latest_condition_event_id'] !== $baseline['condition_event_id']) {
            $result['before'] = null;
        } elseif ($occurrence !== null && $occurrence['condition_event_id'] === $baseline['condition_event_id']) {
            $result['before'] = $occurrence['note'] ?? $prior['description'];
        } else {
            $result['before'] = $prior['description'];
        }
        return $result;
    }

    private static function afterHeading(array $member): string
    {
        if (($member['effect_code'] ?? '') === 'observed_existing') {
            return 'Observed existing — no physical change recorded';
        }
        // A linked trip alone never proves the damage happened during that trip.
        return ($member['effect_code'] ?? '') === 'worsened' ? 'Worsening recorded' : 'New damage recorded';
    }
}
