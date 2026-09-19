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

        if (($event['event_code'] ?? null) === 'guest_return_staged') {
            return $this->state('awaiting_recovery', 'Awaiting Recovery', 'warning', 'Returned to HNL — awaiting recovery. Guest-reported location is unverified.', $event, $schedule, $missing, [], 'recover_vehicle', 'Recover Vehicle');
        }

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

        if (in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)) {
            $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
            $needsCleaning = (bool) ($context['cleaning_required'] ?? (($assessment['cleanliness'] ?? null) !== 'clean'));
            $energyCondition = array_key_exists('energy_condition', $context) ? $context['energy_condition'] : ($target === null ? 'target_needed' : (($assessment['energy_percent'] ?? null) === null ? 'measurement_needed' : ((int) $assessment['energy_percent'] < $target ? 'charge_required' : null)));
            $hasPickupPreparation = $this->hasCriticalBlockers($context, $blockers);
            if ($needsCleaning || $energyCondition !== null || $hasPickupPreparation) {
                if ($needsCleaning) {
                    $blockers[] = ['code' => 'cleaning_required', 'label' => 'Cleaning required', 'severity' => 'meaningful'];
                }
                if ($energyCondition === 'charge_required') {
                    $blockers[] = ['code' => 'energy_below_target', 'label' => 'Charge/Fuel to ' . $target . '%', 'severity' => 'meaningful'];
                } elseif ($energyCondition === 'measurement_needed') {
                    $missing[] = 'return_energy_percent';
                    $blockers[] = ['code' => 'energy_measurement_needed', 'label' => 'Record charge/fuel level', 'severity' => 'meaningful'];
                } elseif ($energyCondition === 'target_needed') {
                    $missing[] = 'ready_energy_target_percent';
                    $blockers[] = ['code' => 'energy_target_needed', 'label' => $this->energyTargetLabel($profile) . ' not configured', 'severity' => 'meaningful'];
                }
                $recovered = ($event['event_code'] ?? null) === 'vehicle_recovered';
                $prefix = $recovered ? 'Vehicle recovered; ' : 'Vehicle returned; ';
                $energyWork = match ($energyCondition) {
                    'charge_required' => $this->energyWorkLabel($profile) . ' remains.',
                    'measurement_needed' => 'record the ' . $this->energyLevelLabel($profile) . '.',
                    'target_needed' => $this->energyTargetLabel($profile) . ' configuration is missing.',
                    default => null,
                };
                $primary = match (true) {
                    $needsCleaning && $energyCondition === 'charge_required' => $prefix . 'cleaning and ' . $this->energyWorkLabel($profile) . ' remain.',
                    $needsCleaning && $energyCondition === 'measurement_needed' => $prefix . 'cleaning remains; record the ' . $this->energyLevelLabel($profile) . '.',
                    $needsCleaning && $energyCondition === 'target_needed' => $prefix . 'cleaning remains; ' . $this->energyTargetLabel($profile) . ' configuration is missing.',
                    $needsCleaning => $prefix . 'cleaning remains.',
                    $energyWork !== null => $prefix . $energyWork,
                    default => $prefix . 'pickup preparation remains.',
                };

                return $this->state('turnaround_attention', $recovered ? 'Recovered — turnaround needed' : 'Returned — turnaround needed', 'warning', $primary, $event, $schedule, $missing, $blockers, 'complete_turnaround', 'Continue Turnaround');
            }
            return $this->state('ready', 'Ready', 'success', 'Ready for the next trip.', $event, $schedule, $missing, $blockers, 'none', 'No action required');
        }

        if (($event['event_code'] ?? null) === 'vehicle_staged') {
            $startsAt = $schedule['starts_at'] ?? null;
            if ($startsAt !== null && new \DateTimeImmutable((string) $startsAt) <= $asOf) {
                return $this->state('staged_pickup_confirmation_needed', 'Guest pickup confirmation needed', 'warning', 'Vehicle remains staged at HNL; confirm when the guest has possession.', $event, $schedule, $missing, $blockers, 'confirm_handoff', 'Confirm Guest Pickup');
            }

            $pickup = $startsAt === null ? 'pickup time pending' : 'pickup ' . $this->dateLabel((string) $startsAt);
            return $this->state('staged_for_pickup', 'Staged at HNL', 'success', 'Staged at HNL; ' . $pickup . '.', $event, $schedule, $missing, $blockers, 'confirm_handoff', 'Confirm Guest Pickup');
        }

        $endsAt = $schedule['ends_at'] ?? null;
        if ($status === 'in_progress' && $endsAt !== null && new \DateTimeImmutable((string) $endsAt) <= $asOf) {
            return $this->state('return_confirmation_overdue', 'Return confirmation overdue', 'danger', 'Scheduled return passed; confirm the vehicle return.', $event, $schedule, $missing, $blockers, 'confirm_return', 'Confirm return');
        }

        $startsAt = $schedule['starts_at'] ?? null;
        if ($startsAt !== null && new \DateTimeImmutable((string) $startsAt) <= $asOf) {
            return $this->state('pickup_confirmation_overdue', 'Pickup awaiting confirmation', 'danger', 'Scheduled pickup time passed; guest possession is not recorded.', $event, $schedule, $missing, $blockers, 'confirm_handoff', 'Record Guest Handoff');
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

    /** @param array<string, mixed> $profile */
    private function energyWorkLabel(array $profile): string
    {
        return ($profile['energy_kind'] ?? null) === 'electric'
            ? 'charging'
            : (($profile['energy_kind'] ?? null) === 'unknown' ? 'fuel/charge work' : 'fueling');
    }

    /** @param array<string, mixed> $profile */
    private function energyLevelLabel(array $profile): string
    {
        return ($profile['energy_kind'] ?? null) === 'electric'
            ? 'charge level'
            : (($profile['energy_kind'] ?? null) === 'unknown' ? 'fuel/charge level' : 'fuel level');
    }

    /** @param array<string, mixed> $profile */
    private function energyTargetLabel(array $profile): string
    {
        return ($profile['energy_kind'] ?? null) === 'electric'
            ? 'Charge target'
            : (($profile['energy_kind'] ?? null) === 'unknown' ? 'Fuel/charge target' : 'Fuel target');
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
