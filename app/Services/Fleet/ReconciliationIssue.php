<?php

namespace App\Services\Fleet;

/** Stable domain vocabulary. Severity is selected from relevance, never from age alone. */
final class ReconciliationIssue
{
    public const CODES = [
        'movement.old_guest_custody', 'movement.import_current_missing_handoff',
        'movement.overdue_return_missing_recovery', 'movement.recovery_without_handoff',
        'movement.custody_trip_mismatch', 'movement.overlapping_guest_intervals', 'movement.ambiguous_authority',
        'position.unknown', 'position.stale_basis', 'position.trip_mismatch',
        'checklist.duplicate_generation', 'checklist.obsolete_schedule_generation', 'checklist.movement_disagreement',
        'import.status_stale', 'import.status_movement_conflict',
        'readiness.current_blocker', 'readiness.source_refresh_needed', 'readiness.historical_derived_only',
        'extras.refresh_required', 'extras.unmapped_purchase', 'extras.missing_fulfillment', 'extras.manual_commitment_incomplete',
    ];
    public const SEVERITIES = ['P0', 'P1', 'P2', 'P3'];
    public const SCOPES = ['CURRENT', 'FUTURE', 'HISTORICAL'];
    public const CLASSIFICATIONS = ['CURRENT_GENUINE', 'SOURCE_REFRESH_NEEDED', 'HISTORICAL_DERIVED', 'ADVISORY'];

    public static function fingerprint(array $basis): string
    {
        return hash('sha256', json_encode(self::canonical($basis), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, mixed> */
    public static function make(int $companyId, int $vehicleId, string $code, string $severity, string $scope, string $summary, array $trips, array $evidence, string $authority, string $review): array
    {
        if (! in_array($code, self::CODES, true) || ! in_array($severity, self::SEVERITIES, true) || ! in_array($scope, self::SCOPES, true)
            || ($scope === 'HISTORICAL' && in_array($severity, ['P0', 'P1'], true))) {
            throw new \InvalidArgumentException('Invalid reconciliation issue contract.');
        }
        sort($trips, SORT_NUMERIC);
        $basis = ['company_id' => $companyId, 'vehicle_id' => $vehicleId, 'code' => $code, 'trip_ids' => array_values(array_unique($trips)), 'authority' => $authority, 'evidence' => $evidence];
        return $basis + [
            'severity' => $severity, 'scope' => $scope, 'summary' => $summary,
            'authority_basis' => $authority, 'review_category' => $review,
            'fingerprint' => self::fingerprint($basis),
        ];
    }

    private static function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        return $data;
    }
}
