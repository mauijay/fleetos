<?php

namespace App\Services\Fleet;

use App\Repositories\GuestCommitmentProjectionRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Derived operational visibility only; source and completion authorities remain independent. */
class GuestCommitmentProjectionService
{
    public function __construct(
        private readonly GuestCommitmentProjectionRepository $repository = new GuestCommitmentProjectionRepository(),
        private readonly TripExtraFulfillmentService $fulfillments = new TripExtraFulfillmentService(),
        private readonly TripCommitmentService $manuals = new TripCommitmentService(),
        private readonly ExtrasVerificationFreshnessPolicy $freshness = new ExtrasVerificationFreshnessPolicy(),
    ) {
    }

    /** @return array<string, mixed> */
    public function forTrip(int $companyId, int $tripId, ?DateTimeImmutable $asOf = null): array
    {
        return $this->forTrips($companyId, [$tripId], $asOf)[$tripId] ?? throw new RuntimeException('Guest commitment trip not found for this company.');
    }

    /** @param list<int> $tripIds @return array<int, array<string, mixed>> */
    public function forTrips(int $companyId, array $tripIds, ?DateTimeImmutable $asOf = null): array
    {
        $tripIds = array_values(array_unique(array_filter(array_map('intval', $tripIds), static fn (int $id): bool => $id > 0)));
        if ($companyId < 1 || $tripIds === []) {
            return [];
        }
        $asOf ??= new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
        return $this->compose($this->repository->load($companyId, $tripIds, $asOf), $asOf);
    }

    /** Shared pure composer, also used by synthetic verification. @return array<int, array<string, mixed>> */
    public function compose(array $data, DateTimeImmutable $asOf): array
    {
        $result = [];
        $mappings = array_column($data['mappings'], null, 'source_extra_id');
        $handoffs = array_fill_keys($data['handoffs'], true);
        $selections = $manuals = $audits = $observations = $failures = [];
        foreach ($data['selections'] as $row) {
            $selections[(int) $row['turo_trip_normalized_id']][] = $row;
        }
        foreach ($data['manuals'] as $row) {
            $manuals[(int) $row['turo_trip_normalized_id']][] = $row;
        }
        foreach ($data['audits'] as $row) {
            $audits[(int) $row['trip_extra_fulfillment_id']][] = $row;
        }
        foreach ($data['snapshots'] as $row) {
            $observations[(string) $row['turo_reservation_id']][] = $row;
        }
        foreach ($data['failures'] as $row) {
            $payload = json_decode((string) $row['raw_payload'], true);
            $failures[(string) ($payload['reservation_id'] ?? '')][] = array_merge($row, $payload ?? []);
        }
        foreach ($data['trips'] as $trip) {
            $tripId = (int) $trip['id'];
            $companyId = (int) $trip['company_id'];
            $reservationId = trim((string) ($trip['turo_reservation_id'] ?? '')) ?: (string) $trip['turo_trip_id'];
            $aliases = array_values(array_unique(array_filter([$reservationId, (string) $trip['turo_trip_id']])));
            $tripObservations = $tripFailures = [];
            foreach ($aliases as $alias) {
                $tripObservations = array_merge($tripObservations, array_values(array_filter($observations[$alias] ?? [], static fn (array $row): bool =>
                    (int) ($row['turo_trip_normalized_id'] ?? 0) === $tripId || (($row['turo_trip_normalized_id'] ?? null) === null && $alias === $reservationId))));
                $tripFailures = array_merge($tripFailures, $failures[$alias] ?? []);
            }
            [$snapshot, $issue] = $this->evidence($tripObservations, $tripFailures, $asOf);
            $sourceReservationId = (string) ($snapshot['turo_reservation_id'] ?? $reservationId);
            $payload = $snapshot === null ? null : json_decode((string) $snapshot['source_payload'], true);
            $items = array_column($payload['extras'] ?? [], null, 'reservation_state_extra_id');
            $verification = $this->freshness->assess([
                'observed_at' => $snapshot['observed_at'] ?? null,
                'snapshot_id' => $snapshot['id'] ?? null, 'extra_count' => count($items),
                'snapshot_complete' => $snapshot !== null,
                'issue' => $issue['message'] ?? null, 'issue_observed_at' => $issue['observed_at'] ?? null,
            ], array_merge($trip, ['reservation_id' => $reservationId, 'has_actual_handoff' => isset($handoffs[$tripId])]), $asOf, (new \Config\App())->appTimezone);
            $activeManuals = $historyManuals = [];
            foreach ($manuals[$tripId] ?? [] as $row) {
                if ((int) $row['company_id'] !== $companyId) {
                    continue;
                }
                $row = array_merge($this->manuals->present($row), [
                    'source_kind' => 'manual', 'identity' => 'manual_commitment:' . $row['id'],
                    'qualified_identity' => $companyId . ':' . $tripId . ':manual_commitment:' . $row['id'],
                    'phase' => $row['applies_during'], 'title' => $row['instruction'],
                ]);
                if ($row['state'] === 'active') {
                    $activeManuals[] = $row;
                } else {
                    $historyManuals[] = $row;
                }
            }
            $purchased = $history = [];
            foreach ($selections[$tripId] ?? [] as $row) {
                if ((int) $row['company_id'] !== $companyId || ! in_array((string) $row['turo_reservation_id'], $aliases, true)) {
                    continue;
                }
                $item = (string) $row['turo_reservation_id'] === $sourceReservationId ? ($items[(string) $row['reservation_state_extra_id']] ?? null) : null;
                $current = $snapshot !== null && $item !== null;
                $storedRemoved = $row['removed_at'] !== null;
                $storedBasis = $current ? $this->fulfillments->present(array_merge($row, ['removed_at' => null]), [], isset($handoffs[$tripId]))['operational_basis_hash'] : null;
                if ($current) {
                    $sourceId = (string) $item['extra_id'];
                    $quantity = $item['quantity'];
                    if ($quantity !== null && $row['quantity'] !== null && (float) $quantity === (float) $row['quantity']) {
                        // Preserve the existing writer's database quantity representation for its basis hash.
                        $quantity = $row['quantity'];
                    }
                    $row = array_merge($row, [
                        'source_extra_id' => $sourceId, 'source_label' => $item['label'], 'quantity' => $quantity, 'source_quantity' => $item['quantity'],
                        'unit_price' => $item['price'], 'currency_code' => $item['currency'],
                        'last_snapshot_id' => $snapshot['id'], 'last_observed_at' => $snapshot['observed_at'], 'removed_at' => null,
                    ], $mappings[$sourceId] ?? [
                        'fleet_extra_id' => null, 'fleet_extra_name' => null, 'fleet_extra_active' => null,
                        'fulfillment_type' => null, 'requires_operator_confirmation' => false, 'readiness_blocking' => false,
                        'default_action_label' => null, 'fulfillment_phase' => null,
                    ]);
                } else {
                    $row['removed_at'] ??= $snapshot['observed_at'] ?? $row['last_observed_at'];
                }
                $rowAudits = $audits[(int) ($row['fulfillment_id'] ?? 0)] ?? [];
                $row = $this->fulfillments->present($row, [], isset($handoffs[$tripId]));
                if ($current && $row['is_completed'] && $this->fulfillments->reappearedSinceConfirmation($row, $rowAudits, $tripObservations, $asOf)) {
                    $canConfirmAgain = $row['can_reopen'];
                    $row['is_completed'] = false;
                    $row['basis_current'] = false;
                    $row['synchronization_required'] = true;
                    $row['can_reopen'] = false;
                    $row['is_actionable'] = $canConfirmAgain;
                    $row['is_suppressed_after_handoff'] = isset($handoffs[$tripId]) && in_array((string) $row['fulfillment_phase'], ['preparation', 'pickup'], true);
                }
                // Commands read persisted selections. Never offer a command against a different
                // operational basis than the trusted evidence displayed here (e.g. a future import).
                if ($current && ($storedRemoved || ! hash_equals((string) $storedBasis, $row['operational_basis_hash']))) {
                    $row['synchronization_required'] = true;
                    $row['is_actionable'] = $row['can_reopen'] = false;
                }
                $row = array_merge($row, [
                    'source_kind' => 'purchased_extra', 'identity' => 'extra_selection:' . $row['selection_id'],
                    'qualified_identity' => $companyId . ':' . $tripId . ':extra_selection:' . $row['selection_id'],
                    'phase' => $row['fulfillment_phase'] ?? null, 'verification' => $verification,
                    'snapshot_id' => $row['last_snapshot_id'], 'observed_at' => $row['last_observed_at'],
                    'completeness' => $current ? 'complete' : 'historical', 'freshness_state' => $verification['state'],
                    'is_last_known' => ! $verification['qualifies_for_preparation'],
                    'configuration_state' => ! $row['is_mapped'] ? 'unmapped' : (! $row['configured'] ? 'unconfigured' : ((bool) $row['fleet_extra_active'] ? 'configured' : 'disabled')),
                    'instruction' => $row['action_label'] ?: $row['title'],
                    'is_blocking' => $current && $row['configured'] && (bool) $row['readiness_blocking'] && ! $row['is_completed'] && ! $row['is_suppressed_after_handoff'],
                    'audits' => $rowAudits,
                    'permitted_actions' => $row['is_actionable'] ? ['complete'] : ($row['can_reopen'] ? ['reopen'] : []),
                    'review_required' => ! $row['is_mapped'] || ! $row['configured'] || $row['synchronization_required'] || $row['is_suppressed_after_handoff'],
                    'planned_return' => $trip['return_location_source_text'] ?? $trip['return_location_class'] ?? null,
                    'return_workflow_href' => (int) ($trip['return_checklist_id'] ?? 0) > 0 ? '/operations/checklists/' . (int) $trip['return_checklist_id'] : null,
                ]);
                if ($current) {
                    $purchased[] = $row;
                } else {
                    // History may only claim configuration from prospectively frozen audit context.
                    $context = null;
                    foreach ($rowAudits as $audit) {
                        $values = json_decode((string) $audit['after_values'], true);
                        $context = $values['operational_context'] ?? $context;
                    }
                    $row['historical_configuration'] = $context;
                    $row['configuration_state'] = $context === null ? 'historical_unknown' : 'historical_frozen';
                    $row['title'] = (string) ($context['fleet_extra_name'] ?? $row['source_label']);
                    $row['fleet_extra_name'] = $context['fleet_extra_name'] ?? null;
                    $row['fulfillment_type'] = $context['fulfillment_type'] ?? null;
                    $row['fulfillment_phase'] = $row['phase'] = $context['fulfillment_phase'] ?? null;
                    $row['action_label'] = $context['action_label'] ?? '';
                    $row['readiness_blocking'] = $context['readiness_blocking'] ?? false;
                    $row['source_label'] = $context['source_label'] ?? $row['source_label'];
                    $row['fleet_extra_id'] = $context['fleet_extra_id'] ?? null;
                    $row['is_actionable'] = $row['can_reopen'] = $row['is_blocking'] = false;
                    $row['permitted_actions'] = [];
                    $history[] = $row;
                }
            }
            usort($purchased, static fn (array $a, array $b): int => (int) $a['selection_id'] <=> (int) $b['selection_id']);
            foreach ($activeManuals as &$manual) {
                $matches = array_values(array_filter($purchased, fn (array $extra): bool => (int) ($manual['fleet_extra_id'] ?? 0) > 0
                    && (int) $manual['fleet_extra_id'] === (int) $extra['fleet_extra_id'] && $this->phasesOverlap((string) $manual['phase'], $extra['phase'])));
                $manual['overlap_warning'] = $matches === [] ? null : (count($matches) > 1
                    ? 'Possible overlap with multiple purchased selections; the related Extra does not identify an exact selection.'
                    : 'Possible overlap with a purchased Extra. Each commitment requires its own confirmation.');
                foreach ($matches as $match) {
                    foreach ($purchased as &$extra) {
                        if ($extra['identity'] === $match['identity']) {
                            $extra['overlap_warning'] = $manual['overlap_warning'];
                        }
                    }
                    unset($extra);
                }
            }
            unset($manual);
            $missingSelections = max(0, count($items) - count($purchased));
            $warning = ! $verification['qualifies_for_preparation'] || $missingSelections > 0;
            $rows = array_merge($purchased, $activeManuals);
            $result[$tripId] = [
                'trip' => $trip, 'company_id' => $companyId, 'trip_id' => $tripId, 'as_of' => $asOf->format(DATE_ATOM),
                'rows' => $rows, 'purchased' => $purchased, 'manual' => $activeManuals,
                'history' => array_merge($history, $historyManuals), 'purchased_history' => $history,
                'verification' => $verification, 'verification_warning' => $warning,
                'missing_selection_count' => $missingSelections,
                'can_show_empty' => $rows === [] && ! $warning, 'count' => count($rows),
                'has_actual_handoff' => isset($handoffs[$tripId]),
            ];
        }
        return $result;
    }

    /** Operator-visible rows for a movement; unknown operational meaning remains visible for review. @return list<array<string, mixed>> */
    public function forPhases(array $projection, array $phases, ?array $energyRule = null): array
    {
        if (isset($projection['trip']) && ! $this->manuals->tripIsOperational($projection['trip'])) {
            return [];
        }
        $rows = array_values(array_filter($projection['rows'] ?? [], static fn (array $row): bool =>
            ($row['source_kind'] === 'purchased_extra' && ($row['phase'] ?? null) === null) || in_array($row['phase'], $phases, true)));
        // Callers already resolve the independent energy authority. Preserve its
        // presentation context without another read or changing projection membership.
        return $energyRule === null ? $rows : array_map(fn (array $row): array =>
            $row['source_kind'] === 'manual' ? $this->manuals->present($row, $energyRule) : $row, $rows);
    }

    private function phasesOverlap(string $manual, ?string $extra): bool
    {
        return $extra !== null && ($manual === 'entire_trip' || $extra === 'entire_trip' || $manual === $extra
            || (in_array($manual, ['preparation', 'pickup'], true) && in_array($extra, ['preparation', 'pickup'], true)));
    }

    /** @return array{0:?array,1:?array} */
    private function evidence(array $observations, array $failures, DateTimeImmutable $asOf): array
    {
        $complete = $issue = null;
        usort($observations, static fn (array $a, array $b): int => [$b['observed_at'], $b['id']] <=> [$a['observed_at'], $a['id']]);
        foreach ($observations as $row) {
            $future = new DateTimeImmutable((string) $row['observed_at'], new DateTimeZone('UTC')) > $asOf;
            if ($future || ! (bool) $row['snapshot_complete']) {
                $issue ??= ['observed_at' => $row['observed_at'], 'message' => $future ? 'Future-dated observation is untrusted; refresh Extras.' : 'Latest observation was incomplete; refresh Extras.'];
            } elseif ($complete === null) {
                $complete = $row;
            }
        }
        foreach ($failures as $failure) {
            if (($failure['observed_at'] ?? '') >= ($issue['observed_at'] ?? '') && ($failure['observed_at'] ?? '') >= ($complete['observed_at'] ?? '')) {
                $issue = ['observed_at' => $failure['observed_at'], 'message' => $failure['error_code'] === 'extras_observation_conflict' ? 'Conflicting observation; refresh Extras.' : 'Latest Extras refresh failed; refresh Extras.'];
            }
        }
        if ($issue !== null && $complete !== null && $issue['observed_at'] < $complete['observed_at']) {
            $issue = null;
        }
        return [$complete, $issue];
    }
}
