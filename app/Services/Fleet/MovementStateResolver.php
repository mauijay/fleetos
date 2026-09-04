<?php

namespace App\Services\Fleet;

class MovementStateResolver
{
    /** @return array<string, mixed> */
    public function resolve(array $context, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $event = $context['latest_event'] ?? null;
        $schedule = $context['trip_schedule'] ?? null;
        $assessment = $context['assessment'] ?? null;
        $profile = $context['profile'] ?? [];
        $nextTrip = $context['next_trip'] ?? null;
        $blockers = array_values($context['blockers'] ?? []);
        $missing = [];
        $status = (string) ($context['operational_status'] ?? 'available');

        if (in_array($status, ['offline', 'out_of_service', 'maintenance'], true)) {
            return $this->state('offline', 'Offline', 'neutral', 'Vehicle is unavailable for movement.', $event, $schedule, $missing, $blockers, 'review_vehicle_status', 'Review vehicle status');
        }

        if (($event['event_code'] ?? null) === 'actual_handoff') {
            if (($assessment['energy_percent'] ?? null) === null) {
                $missing[] = 'departure_energy_percent';
            }
            $endsAt = $schedule['ends_at'] ?? null;
            if ($endsAt !== null && new \DateTimeImmutable((string) $endsAt) <= $asOf) {
                return $this->state('return_confirmation_overdue', 'Return confirmation overdue', 'danger', 'Scheduled return passed; confirm the vehicle return.', $event, $schedule, $missing, $blockers, 'confirm_return', 'Confirm return');
            }
            $primary = $endsAt === null ? 'Vehicle is on trip.' : 'On trip; due ' . $this->dateLabel((string) $endsAt) . '.';
            return $this->state('on_trip', 'Currently Rented', 'info', $primary, $event, $schedule, $missing, $blockers, 'monitor_return', 'Monitor return');
        }

        if (($event['event_code'] ?? null) === 'actual_return') {
            $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
            if (($assessment['cleanliness'] ?? null) === null) {
                $missing[] = 'return_cleanliness';
            }
            if ($target !== null && ($assessment['energy_percent'] ?? null) === null) {
                $missing[] = 'return_energy_percent';
            }
            if ($missing !== []) {
                return $this->state('returned_assessment_required', 'Return assessment needed', 'warning', 'Vehicle returned; complete the return assessment.', $event, $schedule, $missing, $blockers, 'complete_return_assessment', 'Complete return assessment');
            }
            $dirty = ($assessment['cleanliness'] ?? null) === 'dirty';
            $lowEnergy = $target !== null && (int) ($assessment['energy_percent'] ?? 0) < $target;
            if ($dirty || $lowEnergy) {
                if ($dirty) {
                    $blockers[] = ['code' => 'cleaning_required', 'label' => 'Cleaning required', 'severity' => 'meaningful'];
                }
                if ($lowEnergy) {
                    $blockers[] = ['code' => 'energy_below_target', 'label' => 'Energy below ready target', 'severity' => 'meaningful'];
                }
                return $this->state('turnaround_attention', 'Turnaround needed', 'warning', 'Vehicle returned and needs turnaround work.', $event, $schedule, $missing, $blockers, 'complete_turnaround', 'Complete turnaround');
            }
            return $this->state('ready', 'Ready', 'success', 'Return confirmed and readiness facts are complete.', $event, $schedule, $missing, $blockers, 'none', 'No action required');
        }

        $endsAt = $schedule['ends_at'] ?? null;
        if ($status === 'in_progress' && $endsAt !== null && new \DateTimeImmutable((string) $endsAt) <= $asOf) {
            return $this->state('return_confirmation_overdue', 'Return confirmation overdue', 'danger', 'Scheduled return passed; confirm the vehicle return.', $event, $schedule, $missing, $blockers, 'confirm_return', 'Confirm return');
        }

        $startsAt = $schedule['starts_at'] ?? null;
        if ($startsAt !== null && new \DateTimeImmutable((string) $startsAt) <= $asOf) {
            return $this->state('pickup_confirmation_overdue', 'Pickup confirmation overdue', 'danger', 'Scheduled pickup passed without a handoff event.', $event, $schedule, $missing, $blockers, 'confirm_handoff', 'Confirm guest handoff');
        }

        if ($nextTrip !== null) {
            if ($this->hasCriticalBlockers($context, $blockers)) {
                return $this->state('prep_required', 'Preparation required', 'warning', 'Critical blockers must be cleared before handoff.', $event, $schedule, $missing, $blockers, 'clear_blockers', 'Clear critical blockers');
            }
            $pickupLabel = isset($nextTrip['starts_at']) ? $this->dateLabel((string) $nextTrip['starts_at']) : 'handoff';
            return $this->state('ready_for_handoff', 'Ready for ' . $pickupLabel, 'success', 'Future pickup is confirmed with no critical blockers.', $event, $schedule, $missing, $blockers, 'monitor_pickup', 'Monitor pickup');
        }

        return $this->state('available', 'Available', 'success', 'No confirmed movement commitment.', $event, $schedule, $missing, $blockers, 'none', 'No action required');
    }

    private function hasCriticalBlockers(array $context, array $blockers): bool
    {
        if ((int) ($context['critical_blocker_count'] ?? 0) > 0) {
            return true;
        }
        foreach ($blockers as $blocker) {
            if (($blocker['severity'] ?? null) === 'critical') {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function state(string $code, string $label, string $tone, string $primary, ?array $event, ?array $schedule, array $missing, array $blockers, string $actionCode, string $actionLabel): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'tone' => $tone,
            'primary_line' => $primary,
            'basis_facts' => ['event' => $event, 'trip_schedule' => $schedule],
            'missing_facts' => array_values(array_unique($missing)),
            'blockers' => array_values($blockers),
            'primary_action' => ['code' => $actionCode, 'label' => $actionLabel],
        ];
    }

    private function dateLabel(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('M j, g:i A');
    }
}
