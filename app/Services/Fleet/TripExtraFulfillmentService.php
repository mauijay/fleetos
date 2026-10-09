<?php

namespace App\Services\Fleet;

use App\Repositories\TripExtraFulfillmentRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

class TripExtraFulfillmentService
{
    public const ACTIONABLE_TYPES = ['pack', 'install', 'configure', 'logistics'];

    public function __construct(private readonly ?TripExtraFulfillmentRepository $repository = null, private readonly ?DateTimeImmutable $clock = null)
    {
    }

    /** @param list<int> $selectionIds @param list<int> $reactivatedSelectionIds */
    public function reconcileSelectionIds(int $companyId, array $selectionIds, array $reactivatedSelectionIds = [], ?int $actorUserId = null): void
    {
        if (! $this->repo()->storageExists() || $selectionIds === []) {
            return;
        }
        $reactivated = array_fill_keys(array_map('intval', $reactivatedSelectionIds), true);
        foreach ($this->repo()->operationalRows($companyId, $selectionIds) as $row) {
            $this->reconcileRow($row, isset($reactivated[(int) $row['selection_id']]), $actorUserId);
        }
    }

    public function reconcileForExtra(int $companyId, int $fleetExtraId, ?int $actorUserId = null): void
    {
        if (! $this->repo()->storageExists()) {
            return;
        }
        foreach ($this->repo()->operationalRows($companyId, [], $fleetExtraId) as $row) {
            $this->reconcileRow($row, false, $actorUserId);
        }
    }

    public function reconcileForSource(int $companyId, string $sourceExtraId, ?int $actorUserId = null): void
    {
        if (! $this->repo()->storageExists()) {
            return;
        }
        foreach ($this->repo()->operationalRows($companyId, [], null, $sourceExtraId) as $row) {
            $this->reconcileRow($row, false, $actorUserId);
        }
    }

    public function reconcileForTrip(int $companyId, int $tripId, ?int $actorUserId = null): void
    {
        if (! $this->repo()->storageExists()) {
            return;
        }
        $this->repo()->attachUnmatchedForTrip($companyId, $tripId);
        foreach ($this->repo()->currentForTrips($companyId, [$tripId]) as $row) {
            $this->reconcileRow($row, false, $actorUserId);
        }
    }

    /** @param list<int> $tripIds @return array<int, list<array<string, mixed>>> */
    public function forTrips(int $companyId, array $tripIds): array
    {
        if (! $this->repo()->storageExists() || $tripIds === []) {
            return [];
        }
        $tripIds = array_values(array_unique(array_filter(array_map('intval', $tripIds), static fn (int $id): bool => $id > 0)));
        $commitments = $this->repo()->linkedCommitments($companyId, $tripIds);
        $handoffs = $this->repo()->activeHandoffTripIds($companyId, $tripIds);
        $result = [];
        foreach ($this->repo()->forTripsWithHistory($companyId, $tripIds) as $row) {
            $tripId = (int) $row['turo_trip_normalized_id'];
            $linked = array_values(array_filter(
                $commitments[$tripId] ?? [],
                static fn (array $commitment): bool => (int) ($commitment['fleet_extra_id'] ?? 0) === (int) $row['fleet_extra_id'],
            ));
            $result[$tripId][] = $this->present($row, $linked, isset($handoffs[$tripId]));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function complete(int $companyId, int $tripId, int $fulfillmentId, int $actorUserId, ?string $note = null): array
    {
        return $this->repo()->transaction(function () use ($companyId, $tripId, $fulfillmentId, $actorUserId, $note): array {
            $this->repo()->lockOwnedTrip($companyId, $tripId);
            return $this->completeLocked($companyId, $tripId, $fulfillmentId, $actorUserId, $note);
        });
    }

    /** @return array<string, mixed> */
    private function completeLocked(int $companyId, int $tripId, int $fulfillmentId, int $actorUserId, ?string $note): array
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated operator is required.');
        }
        $row = $this->repo()->rowForCompany($companyId, $fulfillmentId);
        if ($row === null || (int) $row['turo_trip_normalized_id'] !== $tripId) {
            throw new RuntimeException('Extra fulfillment not found for this trip.');
        }
        if (! $this->tripIsOperational($row) || $row['removed_at'] !== null || ! $this->isActionableConfiguration($row)) {
            throw new InvalidArgumentException('This Extra is not currently actionable for the trip.');
        }
        $basis = $this->basisHash($row);
        $reactivated = ($row['fulfillment_state'] ?? null) === 'completed' && $this->reappearedSinceConfirmation(
            $row,
            $this->repo()->audits($companyId, $fulfillmentId),
            $this->repo()->observationsForRow($row),
            $this->clock ?? new DateTimeImmutable(),
        );
        if (($row['fulfillment_state'] ?? null) === 'completed' && hash_equals((string) $row['completed_basis_hash'], $basis) && ! $reactivated) {
            return $this->present(array_merge($row, ['current_basis_hash' => $basis]), [], false);
        }
        if (in_array((string) $row['fulfillment_phase'], ['preparation', 'pickup'], true)
            && $this->repo()->hasActiveEvent($companyId, $tripId, 'actual_handoff')) {
            throw new InvalidArgumentException('Pickup preparation can no longer be completed after guest handoff.');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Completion note must be 2,000 characters or fewer.');
        }
        $now = date('Y-m-d H:i:s');
        $before = $this->fulfillmentValues($row);
        $changes = [
            'state' => 'completed',
            'current_basis_hash' => $basis,
            'completed_basis_hash' => $basis,
            'completed_at' => $now,
            'completed_by_user_id' => $actorUserId,
            'completion_note' => $note === '' ? null : $note,
            'updated_at' => $now,
        ];
        $this->repo()->transaction(function () use ($companyId, $fulfillmentId, $actorUserId, $before, $changes): void {
            $this->repo()->update($companyId, $fulfillmentId, $changes);
            $this->repo()->audit($fulfillmentId, $companyId, 'completed', $actorUserId, $before, array_merge($before, $changes));
        });

        return $this->present(array_merge($row, $this->aliasedChanges($changes)), [], false);
    }

    /** @return array<string, mixed> */
    public function reopen(int $companyId, int $tripId, int $fulfillmentId, int $actorUserId): array
    {
        return $this->repo()->transaction(function () use ($companyId, $tripId, $fulfillmentId, $actorUserId): array {
            $this->repo()->lockOwnedTrip($companyId, $tripId);
            return $this->reopenLocked($companyId, $tripId, $fulfillmentId, $actorUserId);
        });
    }

    /** @return array<string, mixed> */
    private function reopenLocked(int $companyId, int $tripId, int $fulfillmentId, int $actorUserId): array
    {
        $row = $this->repo()->rowForCompany($companyId, $fulfillmentId);
        if ($actorUserId < 1 || $row === null || (int) $row['turo_trip_normalized_id'] !== $tripId) {
            throw new RuntimeException('Extra fulfillment not found for this trip.');
        }
        if (! $this->tripIsOperational($row) || $row['removed_at'] !== null || ! $this->isActionableConfiguration($row)) {
            throw new InvalidArgumentException('This Extra is not currently actionable for the trip.');
        }
        $changes = $this->pendingChanges($this->basisHash($row));
        if (in_array((string) $row['fulfillment_phase'], ['preparation', 'pickup'], true)
            && $this->repo()->hasActiveEvent($companyId, $tripId, 'actual_handoff')) {
            throw new InvalidArgumentException('Pickup preparation can no longer be reopened after guest handoff.');
        }
        $before = $this->fulfillmentValues($row);
        $this->repo()->transaction(function () use ($companyId, $fulfillmentId, $actorUserId, $before, $changes): void {
            $this->repo()->update($companyId, $fulfillmentId, $changes);
            $this->repo()->audit($fulfillmentId, $companyId, 'reopened', $actorUserId, $before, array_merge($before, $changes));
        });

        return $this->present(array_merge($row, $this->aliasedChanges($changes)), [], false);
    }

    /** @param array<string, mixed> $row */
    private function reconcileRow(array $row, bool $reactivated, ?int $actorUserId): void
    {
        $this->repo()->transaction(function () use ($row, $reactivated, $actorUserId): void {
            $this->repo()->lockOwnedTrip((int) $row['company_id'], (int) $row['turo_trip_normalized_id']);
            $fresh = $this->repo()->operationalRows((int) $row['company_id'], [(int) $row['selection_id']])[0] ?? null;
            if ($fresh !== null) {
                $this->reconcileLockedRow($fresh, $reactivated, $actorUserId);
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function reconcileLockedRow(array $row, bool $reactivated, ?int $actorUserId): void
    {
        if ($row['removed_at'] !== null || ! $this->isActionableConfiguration($row)) {
            return;
        }
        $companyId = (int) $row['company_id'];
        $selectionId = (int) $row['selection_id'];
        $basis = $this->basisHash($row);
        if (in_array((string) $row['fulfillment_phase'], ['preparation', 'pickup'], true)
            && $this->repo()->hasActiveEvent($companyId, (int) $row['turo_trip_normalized_id'], 'actual_handoff')) {
            return;
        }
        if ($row['fulfillment_id'] === null) {
            $now = date('Y-m-d H:i:s');
            $data = [
                'company_id' => $companyId,
                'turo_extra_selection_id' => $selectionId,
                'state' => 'pending',
                'current_basis_hash' => $basis,
                'completed_basis_hash' => null,
                'completed_at' => null,
                'completed_by_user_id' => null,
                'completion_note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $context = $this->operationalContext($row);
            $this->repo()->transaction(function () use ($data, $companyId, $actorUserId, $context): void {
                $id = $this->repo()->create($data);
                $this->repo()->audit($id, $companyId, 'created', $actorUserId, null, array_merge($data, ['id' => $id, 'operational_context' => $context]));
            });

            return;
        }
        $fulfillmentId = (int) $row['fulfillment_id'];
        if ($reactivated && ($row['fulfillment_state'] ?? null) === 'completed' && (int) ($row['last_snapshot_id'] ?? 0) > 0) {
            foreach (array_reverse($this->repo()->audits($companyId, $fulfillmentId)) as $audit) {
                if ($audit['action'] === 'completed') {
                    $values = json_decode((string) $audit['after_values'], true);
                    // Import reconciliation can arrive after an operator confirms the new epoch.
                    $reactivated = (int) ($values['operational_context']['snapshot_id'] ?? 0) !== (int) $row['last_snapshot_id'];
                    break;
                }
            }
        }
        $basisChanged = ! hash_equals((string) ($row['current_basis_hash'] ?? ''), $basis);
        $completedMismatch = ($row['fulfillment_state'] ?? null) === 'completed'
            && ! hash_equals((string) ($row['completed_basis_hash'] ?? ''), $basis);
        if (! $reactivated && ! $basisChanged && ! $completedMismatch) {
            return;
        }
        $before = $this->fulfillmentValues($row);
        if ($reactivated || $completedMismatch) {
            $changes = $this->pendingChanges($basis);
            $action = $reactivated ? 'reactivated' : 'automatic_reopen';
        } else {
            $changes = ['current_basis_hash' => $basis, 'updated_at' => date('Y-m-d H:i:s')];
            $action = 'reopened';
        }
        $this->repo()->transaction(function () use ($companyId, $fulfillmentId, $actorUserId, $before, $changes, $action): void {
            $this->repo()->update($companyId, $fulfillmentId, $changes);
            $this->repo()->audit($fulfillmentId, $companyId, $action, $actorUserId, $before, array_merge($before, $changes));
        });
    }

    /** @param array<string, mixed> $row @param list<array<string, mixed>> $linkedCommitments @return array<string, mixed> */
    public function present(array $row, array $linkedCommitments, bool $handoffRecorded): array
    {
        $quantity = $this->quantityLabel($row['quantity'] ?? null);
        $title = (string) ($row['fleet_extra_name'] ?? $row['source_label'] ?? 'Purchased Extra') . ($quantity === null ? '' : ' ×' . $quantity);
        $action = trim((string) ($row['default_action_label'] ?? ''));
        $action = str_replace('{quantity}', $quantity ?? 'quantity not supplied', $action);
        $operational = $this->tripIsOperational($row);
        $current = $row['removed_at'] === null;
        $configured = $this->isConfigured($row);
        $basis = $current ? $this->basisHash($row) : (string) ($row['current_basis_hash'] ?? '');
        $basisCurrent = ! $current || hash_equals((string) ($row['current_basis_hash'] ?? ''), $basis);
        $complete = ($row['fulfillment_state'] ?? null) === 'completed'
            && ($row['completed_basis_hash'] ?? null) !== null
            && hash_equals((string) $row['completed_basis_hash'], $basis);
        $physicalPhaseClosed = $handoffRecorded && in_array((string) ($row['fulfillment_phase'] ?? ''), ['preparation', 'pickup'], true);

        return array_merge($row, [
            'title' => $title,
            'is_mapped' => ($row['fleet_extra_id'] ?? null) !== null,
            'quantity_label' => $quantity,
            'quantity_unknown' => $quantity === null,
            'action_label' => $action,
            'confirmation_label' => ($row['fulfillment_type'] ?? null) === 'informational' && ! (bool) ($row['requires_operator_confirmation'] ?? false) ? null : $this->confirmationLabel((string) ($row['fulfillment_type'] ?? '')),
            'trip_is_operational' => $operational,
            'configured' => $configured,
            'is_removed' => ! $current,
            'is_informational' => ($row['fulfillment_type'] ?? null) === 'informational',
            'is_completed' => $complete,
            'operational_basis_hash' => $basis,
            'basis_current' => $basisCurrent,
            'synchronization_required' => $current && $this->isActionableConfiguration($row) && (($row['fulfillment_id'] ?? null) === null || ! $basisCurrent),
            'is_actionable' => $current && $operational && $configured && $this->isActionableConfiguration($row) && ($row['fulfillment_id'] ?? null) !== null && ! $complete && ! $physicalPhaseClosed,
            'can_reopen' => $current && $operational && $complete && ! $physicalPhaseClosed && $this->isActionableConfiguration($row),
            'is_suppressed_after_handoff' => $physicalPhaseClosed && ! $complete,
            'linked_commitments' => $linkedCommitments,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function basisHash(array $row): string
    {
        return hash('sha256', json_encode([
            'selection_id' => (int) $row['selection_id'],
            'reservation_id' => (string) $row['turo_reservation_id'],
            'reservation_state_extra_id' => (string) $row['reservation_state_extra_id'],
            'fleet_extra_id' => (int) $row['fleet_extra_id'],
            'quantity' => $row['quantity'] === null ? null : (string) $row['quantity'],
            'fulfillment_type' => (string) $row['fulfillment_type'],
            'fulfillment_phase' => (string) $row['fulfillment_phase'],
            'requires_confirmation' => (bool) $row['requires_operator_confirmation'],
            'readiness_blocking' => (bool) $row['readiness_blocking'],
            'action_label' => (string) $row['default_action_label'],
            'fleet_vehicle_id' => (int) $row['fleet_vehicle_id'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $row */
    private function isConfigured(array $row): bool
    {
        return in_array((string) ($row['fulfillment_type'] ?? 'none'), [...self::ACTIONABLE_TYPES, 'informational'], true);
    }

    /** @param array<string, mixed> $row */
    private function isActionableConfiguration(array $row): bool
    {
        return in_array((string) ($row['fulfillment_type'] ?? ''), [...self::ACTIONABLE_TYPES, 'informational'], true)
            && (bool) ($row['requires_operator_confirmation'] ?? false)
            && trim((string) ($row['default_action_label'] ?? '')) !== ''
            && trim((string) ($row['fulfillment_phase'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $row */
    private function tripIsOperational(array $row): bool
    {
        $status = strtolower(trim((string) ($row['trip_status_code'] ?? '')));

        return ($row['trip_canceled_at'] ?? null) === null && $status !== 'invalid' && ! str_starts_with($status, 'canceled');
    }

    private function quantityLabel(mixed $quantity): ?string
    {
        if ($quantity === null || trim((string) $quantity) === '') {
            return null;
        }
        $value = str_contains((string) $quantity, '.') ? rtrim(rtrim((string) $quantity, '0'), '.') : (string) $quantity;

        return $value === '' ? '0' : $value;
    }

    private function confirmationLabel(string $fulfillmentType): ?string
    {
        return match ($fulfillmentType) {
            'pack' => 'Confirm packed',
            'install' => 'Confirm installed',
            'configure' => 'Confirm configured',
            'logistics' => 'Confirm reviewed',
            'informational' => 'Confirm reviewed',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function pendingChanges(string $basis): array
    {
        return [
            'state' => 'pending',
            'current_basis_hash' => $basis,
            'completed_basis_hash' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'completion_note' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function fulfillmentValues(array $row): array
    {
        return [
            'id' => (int) ($row['fulfillment_id'] ?? $row['id'] ?? 0),
            'state' => $row['fulfillment_state'] ?? $row['state'] ?? null,
            'current_basis_hash' => $row['current_basis_hash'] ?? null,
            'completed_basis_hash' => $row['completed_basis_hash'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'completed_by_user_id' => $row['completed_by_user_id'] ?? null,
            'completion_note' => $row['completion_note'] ?? null,
            'operational_context' => $this->operationalContext($row),
        ];
    }

    /** Prospective versioned audit JSON; existing audits are never rewritten. @return array<string, mixed> */
    private function operationalContext(array $row): array
    {
        return [
            'version' => 1, 'selection_id' => (int) $row['selection_id'], 'snapshot_id' => $row['last_snapshot_id'] ?? null,
            'source_extra_id' => $row['source_extra_id'], 'source_label' => $row['source_label'] ?? null,
            'fleet_extra_id' => $row['fleet_extra_id'], 'fleet_extra_name' => $row['fleet_extra_name'],
            'quantity' => $row['quantity'], 'fleet_vehicle_id' => $row['fleet_vehicle_id'],
            'fulfillment_type' => $row['fulfillment_type'], 'fulfillment_phase' => $row['fulfillment_phase'],
            'action_label' => $row['default_action_label'], 'readiness_blocking' => (bool) $row['readiness_blocking'],
        ];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function aliasedChanges(array $changes): array
    {
        if (isset($changes['state'])) {
            $changes['fulfillment_state'] = $changes['state'];
        }

        return $changes;
    }

    /** A missed reactivation reconciliation must not reuse a confirmation from a previous purchase epoch. */
    public function reappearedSinceConfirmation(array $row, array $audits, array $observations, DateTimeImmutable $asOf): bool
    {
        $observations = array_values(array_filter($observations, static fn (array $snapshot): bool =>
            (int) $snapshot['company_id'] === (int) $row['company_id'] && (string) $snapshot['turo_reservation_id'] === (string) $row['turo_reservation_id']));
        $confirmedSnapshot = (int) ($row['first_snapshot_id'] ?? 0);
        $hasSnapshotContext = false;
        foreach ($audits as $audit) {
            if ($audit['action'] === 'completed') {
                $values = json_decode((string) $audit['after_values'], true);
                $hasSnapshotContext = isset($values['operational_context']['snapshot_id']);
                $confirmedSnapshot = (int) ($values['operational_context']['snapshot_id'] ?? $row['first_snapshot_id'] ?? 0);
            }
        }
        $confirmedAt = null;
        if (! $hasSnapshotContext && trim((string) ($row['completed_at'] ?? '')) !== '') {
            // Legacy confirmations have no frozen snapshot. Source time is UTC;
            // confirmation-record time retains the application's existing timezone.
            $confirmedAt = (new DateTimeImmutable((string) $row['completed_at'], new DateTimeZone((new \Config\App())->appTimezone)))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } else {
            foreach ($observations as $snapshot) {
                if ((int) $snapshot['id'] === $confirmedSnapshot) {
                    $confirmedAt = $snapshot['observed_at'];
                }
            }
        }
        if ($confirmedAt === null) {
            return false;
        }
        foreach ($observations as $snapshot) {
            if (! $snapshot['snapshot_complete'] || $snapshot['observed_at'] <= $confirmedAt
                || new DateTimeImmutable((string) $snapshot['observed_at'], new DateTimeZone('UTC')) > $asOf) {
                continue;
            }
            $payload = json_decode((string) $snapshot['source_payload'], true);
            if (! in_array((string) $row['reservation_state_extra_id'], array_map('strval', array_column($payload['extras'] ?? [], 'reservation_state_extra_id')), true)) {
                return true;
            }
        }
        return false;
    }

    private function repo(): TripExtraFulfillmentRepository
    {
        return $this->repository ?? new TripExtraFulfillmentRepository();
    }
}
