<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use Config\ExtrasVerification;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure source-verification policy. No database, Settings writes, or fulfillment mutations. */
class ExtrasVerificationFreshnessPolicy
{
    public function __construct(private readonly ExtrasVerification $config = new ExtrasVerification())
    {
        if ($config->preparationWindowHours <= 0 || $config->maxCompleteAgeHours <= 0
            || $config->advisoryHorizonHours < $config->preparationWindowHours) {
            throw new InvalidArgumentException('Invalid Extras verification windows.');
        }
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed> $trip @return array<string, mixed> */
    public function assess(array $evidence, array $trip, DateTimeImmutable $asOf, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $observedAt = $evidence['observed_at'] ?? null;
        $observed = $observedAt === null ? null : new DateTimeImmutable((string) $observedAt, new DateTimeZone('UTC'));
        $age = $observed === null ? null : $asOf->getTimestamp() - $observed->getTimestamp();
        $trusted = $age !== null && $age >= 0;
        $stale = $observed !== null && (! $trusted || $age > $this->config->maxCompleteAgeHours * 3600);
        $count = (int) ($evidence['extra_count'] ?? 0);
        $state = $observed === null ? 'never' : (($stale ? 'stale_' : 'current_') . ($count === 0 ? 'empty' : 'nonempty'));
        $label = $observed?->setTimezone($zone)->format('M j, Y g:i:s A T');
        $ageLabel = $age === null ? null : ($age < 0 ? 'Untrusted future observation' : $this->ageLabel($age));
        $summary = match ($state) {
            'never' => 'Extras have not been verified.',
            'current_empty' => 'No Extras observed at ' . $label . ' · verified ' . $ageLabel . ' ago.',
            'current_nonempty' => 'Extras verified ' . $ageLabel . ' ago at ' . $label . '.',
            'stale_empty' => 'Extras verification stale. Last complete observation was empty at ' . $label . '.',
            default => 'Extras verification stale. Last complete observation at ' . $label . '; known purchased Extras are shown below.',
        };
        if ($observed !== null && ! $trusted) {
            $summary = 'Extras observation timestamp is untrusted (future-dated). Last complete observation: ' . $label . '.';
        }
        $issueAt = $evidence['issue_observed_at'] ?? null;
        $issue = $evidence['issue'] ?? null;
        if ($issueAt !== null && $observed !== null
            && (new DateTimeImmutable((string) $issueAt, new DateTimeZone('UTC')))->getTimestamp() < $observed->getTimestamp()) {
            $issue = $issueAt = null;
        }
        $issueLabel = $issueAt === null ? null : (new DateTimeImmutable((string) $issueAt, new DateTimeZone('UTC')))->setTimezone($zone)->format('M j, Y g:i:s A T');
        $status = (string) ($trip['trip_status_code'] ?? 'booked');
        $inactive = ($trip['canceled_at'] ?? null) !== null || ($trip['deleted_at'] ?? null) !== null
            || in_array($status, ['completed', 'invalid', 'canceled', 'cancelled'], true) || str_starts_with($status, 'canceled') || str_starts_with($status, 'cancelled');
        $handoff = (bool) ($trip['has_actual_handoff'] ?? false);
        $pickupText = trim((string) ($trip['starts_at'] ?? ''));
        $pickup = $pickupText === '' ? null : new DateTimeImmutable($pickupText, $zone);
        $seconds = $pickup === null ? null : $pickup->getTimestamp() - $asOf->getTimestamp();
        $applicable = ! $inactive && ! $handoff && $pickup !== null;
        $preparation = $applicable && $seconds <= $this->config->preparationWindowHours * 3600;
        $cutoff = $pickup === null ? null : max($pickup->getTimestamp() - $this->config->preparationWindowHours * 3600, $asOf->getTimestamp() - $this->config->maxCompleteAgeHours * 3600);
        $qualifies = $trusted && ! $stale && $issue === null && ($cutoff === null || $observed->getTimestamp() >= $cutoff);
        $problem = $observed === null || $stale || $issue !== null;
        $required = $preparation && ! $qualifies;
        $advisory = $applicable && ! $preparation && $seconds <= $this->config->advisoryHorizonHours * 3600 && $problem;
        $reason = $observed === null ? 'Extras have not been verified.' : (! $trusted ? 'Observation timestamp is untrusted.' : ($issue !== null ? 'Latest refresh attempt is unresolved.' : ($stale ? 'Last complete verification is stale.' : (! $qualifies ? 'Verification predates pickup preparation.' : null))));
        $reservationId = (string) ($trip['reservation_id'] ?? $trip['turo_reservation_id'] ?? $trip['turo_trip_id'] ?? '');
        $href = preg_match('/^\d{1,80}$/', $reservationId) === 1 ? '/turo/extras?reservation_id=' . rawurlencode($reservationId) . '#export-heading' : null;

        return array_merge($evidence, [
            'state' => $state, 'content_state' => $observed === null ? 'unknown' : ($count === 0 ? 'empty' : 'nonempty'),
            'observed_at' => $observedAt, 'verified_label' => $label, 'age_seconds' => $age, 'age_label' => $ageLabel,
            'is_stale' => $stale, 'observation_trusted' => $trusted, 'summary' => $summary,
            'issue' => $issue, 'issue_observed_at' => $issueAt, 'issue_label' => $issueLabel,
            'pickup_applicable' => $applicable, 'lifecycle' => $inactive ? 'inactive' : ($handoff ? 'handoff_closed' : ($seconds !== null && $seconds < 0 ? 'handoff_missing' : 'before_pickup')),
            'qualifies_for_preparation' => $qualifies, 'refresh_required' => $required, 'refresh_reason' => ($required || $advisory) ? $reason : null,
            'advisory' => $advisory, 'optional_active_refresh' => ! $inactive && $handoff && $problem,
            'urgency' => $required ? 'required' : ($advisory ? 'advisory' : 'informational'),
            'action_href' => $href, 'pickup_at' => $pickup?->setTimezone($zone)->format('Y-m-d H:i:s'),
            'company_id' => (int) ($trip['company_id'] ?? 0), 'trip_id' => (int) ($trip['trip_id'] ?? $trip['id'] ?? 0),
            'vehicle_id' => (int) ($trip['fleet_vehicle_id'] ?? 0), 'reservation_id' => $reservationId, 'as_of' => $asOf->format(DATE_ATOM),
        ]);
    }

    private function ageLabel(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        return $hours >= 24 ? intdiv($hours, 24) . 'd ' . ($hours % 24) . 'h' : ($hours > 0 ? $hours . 'h' : 'less than 1h');
    }
}
