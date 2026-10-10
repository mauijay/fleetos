<?php

namespace App\Services\Fleet;

/** Describes existing lifecycle authority without changing readiness or movement facts. */
class MovementStagingPresentationService
{
    /**
     * @param array<string, mixed> $checklist
     * @param array<string, mixed> $readiness
     * @param array<string, mixed>|null $pickupFact
     * @param array<string, mixed>|null $custody
     * @param list<array<string, mixed>> $timeline
     * @return array<string, mixed>
     */
    public function forChecklist(array $checklist, array $readiness, ?array $pickupFact, ?array $custody, array $timeline, bool $hasLaterTripLifecycle, bool $canRecordHistoricalHandoff): array
    {
        $presentation = ['historical_explanation' => null, 'staging_blocked_reason' => null, 'pickup_location_help' => null, 'handoff_help' => null];
        if (($checklist['movement_type'] ?? null) !== 'pickup') {
            return $presentation;
        }
        $stage = ($pickupFact['event_code'] ?? null) === 'vehicle_staged';
        $historical = $stage && ((int) ($custody['basis_trip_id'] ?? 0) !== (int) ($checklist['turo_trip_normalized_id'] ?? 0)
            || (int) ($custody['basis_event_id'] ?? 0) !== (int) ($pickupFact['event_id'] ?? 0));
        $requirements = array_column($readiness['requirements'] ?? [], null, 'code');
        if (($requirements['location_confirmed']['status'] ?? null) === 'unsatisfied' && $historical) {
            $presentation['pickup_location_help'] = 'This pickup needs a current staging or actual handoff location for this reservation. Historical staging and current vehicle position alone do not satisfy it.';
        }
        if (! $historical) {
            if (($requirements['airport_staging']['status'] ?? null) === 'unsatisfied'
                && ($requirements['guest_handoff']['status'] ?? null) === 'unsatisfied') {
                $presentation['handoff_help'] = 'Guest pickup confirmation becomes available when staging for this pickup is current. Staging does not confirm that the guest received the vehicle.';
            }
            return $presentation;
        }

        $explanation = 'Earlier staging is historical and no longer satisfies this pickup.';
        $afterStage = array_values(array_filter($timeline, static fn (array $event): bool =>
            in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            && (string) ($event['occurred_at'] ?? '') > (string) ($pickupFact['occurred_at'] ?? '')
            && ($event['custody_trip_canceled_at'] ?? null) === null
            && ! str_starts_with((string) ($event['custody_trip_status_code'] ?? ''), 'canceled')
            && ($event['custody_trip_status_code'] ?? '') !== 'invalid'));
        usort($afterStage, static fn (array $left, array $right): int => strcmp((string) $right['occurred_at'], (string) $left['occurred_at']) ?: (int) $right['id'] <=> (int) $left['id']);
        $recovery = $afterStage[0] ?? null;
        if ($recovery !== null) {
            $eventLabel = $recovery['event_code'] === 'vehicle_recovered' ? 'vehicle recovery' : 'vehicle return';
            $explanation .= ' A later ' . $eventLabel . ' occurred on ' . $this->timeLabel($recovery['occurred_at'])
                . ', after staging on ' . $this->timeLabel($pickupFact['occurred_at']) . '.';
        } elseif (($custody['occurred_at'] ?? null) !== null) {
            $explanation .= ' Vehicle lifecycle activity recorded on ' . $this->timeLabel($custody['occurred_at']) . ' establishes the current pickup state.';
        }
        $presentation['historical_explanation'] = $explanation;
        $presentation['handoff_help'] = 'Guest pickup confirmation is unavailable from this historical stage because it is no longer the current staging for this reservation.';
        if (! $canRecordHistoricalHandoff) {
            $presentation['handoff_help'] .= ' Historical handoff entry is unavailable for this reservation in its current state. Review the movement chronology before recording any new facts.';
        }
        // This decision comes from the same custody guard used by restaging.
        if ($hasLaterTripLifecycle) {
            $basis = $custody['basis_event'] ?? [];
            $reason = 'Staging is unavailable because the current vehicle lifecycle belongs to a later reservation';
            if (($basis['custody_trip_starts_at'] ?? null) !== null) {
                $reason .= ' scheduled for ' . $this->timeLabel($basis['custody_trip_starts_at']);
            }
            if (($custody['occurred_at'] ?? null) !== null) {
                $reason .= ', with vehicle activity recorded on ' . $this->timeLabel($custody['occurred_at']);
            }
            $presentation['staging_blocked_reason'] = $reason . '. Review movement chronology and reservation assignment.';
        }
        return $presentation;
    }

    /** @param array<string, mixed> $readiness @param array<string, mixed> $presentation @return array<string, mixed> */
    public function readinessForDisplay(array $readiness, array $presentation): array
    {
        foreach ($readiness['requirements'] ?? [] as $index => $requirement) {
            if (($requirement['code'] ?? null) === 'location_confirmed' && $presentation['pickup_location_help'] !== null) {
                $readiness['requirements'][$index]['presentation_help'] = $presentation['pickup_location_help'];
            }
            if ($presentation['staging_blocked_reason'] !== null
                && in_array($requirement['code'] ?? null, ['location_confirmed', 'airport_staging', 'parking_location_recorded'], true)
                && ($requirement['status'] ?? null) === 'unsatisfied'
                && ($requirement['actionable'] ?? true)
                && ($requirement['action']['type'] ?? null) === 'record_fact') {
                $readiness['requirements'][$index]['actionable'] = false;
                $readiness['requirements'][$index]['action'] = null;
                $readiness['requirements'][$index]['deferred_label'] = 'A later reservation owns the current staging opportunity. Review the staging explanation.';
            }
        }
        return $readiness;
    }

    private function timeLabel(string $time): string
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Pacific/Honolulu')))->format('M j, Y g:i A') . ' Honolulu';
    }
}
