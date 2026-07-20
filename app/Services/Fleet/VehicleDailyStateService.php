<?php

namespace App\Services\Fleet;

use DateTimeImmutable;

class VehicleDailyStateService
{
    public const CRITICAL_TURNAROUND_MINUTES = 120;
    public const TIGHT_TURNAROUND_MINUTES = 240;

    /** @return array<int, array<string, mixed>> */
    public function movementBoard(array $vehicles, array $today, array $health, DateTimeImmutable $asOf): array
    {
        $pickups = $this->byVehicle($today['todays_pickups'] ?? [], 'starts_at');
        $returns = $this->byVehicle($today['todays_returns'] ?? [], 'ends_at');
        $cleaning = $this->ids($health['vehicles_needing_cleaning'] ?? []);
        $maintenance = $this->ids($health['vehicles_due_for_maintenance'] ?? []);

        return array_map(function (array $vehicle) use ($pickups, $returns, $cleaning, $maintenance, $asOf): array {
            $vehicleId = (int) $vehicle['fleet_vehicle_id'];
            $return = $returns[$vehicleId][0] ?? null;
            $pickup = $pickups[$vehicleId][0] ?? null;
            $turnaround = $this->turnaround($return, $pickup);
            $flags = $this->flags($vehicle, $return, $pickup, $turnaround, isset($cleaning[$vehicleId]), isset($maintenance[$vehicleId]), $asOf);
            $primaryStatus = $this->primaryStatus($flags, (string) ($vehicle['status'] ?? 'available'));

            return [
                'fleet_vehicle_id' => $vehicleId,
                'fleet_code' => (string) ($vehicle['fleet_code'] ?? $vehicle['display_name'] ?? 'Vehicle'),
                'display_name' => (string) ($vehicle['display_name'] ?? $vehicle['fleet_code'] ?? 'Vehicle'),
                'model' => (string) ($vehicle['model'] ?? ''),
                'primary_status' => $primaryStatus,
                'primary_status_label' => $this->statusLabel($primaryStatus, $return, $pickup),
                'status_tone' => $this->statusTone($primaryStatus, $turnaround),
                'flags' => $flags,
                'return' => $return,
                'pickup' => $pickup,
                'turnaround' => $turnaround,
                'guest_name' => (string) ($pickup['guest_name'] ?? $return['guest_name'] ?? 'Guest not captured'),
                'location_label' => $this->locationLabel($vehicle),
                'delivery_type' => (bool) ($vehicle['airport_delivery_scheduled'] ?? false) ? 'Airport' : 'Not captured',
                'cleaning_status_label' => isset($cleaning[$vehicleId]) || $turnaround !== null ? 'Cleaning status not confirmed' : 'No cleaning task known',
                'charging_status_label' => $pickup !== null || $turnaround !== null ? 'Charge level not captured; confirm charge' : 'Battery telemetry not connected',
                'battery_label' => $vehicle['current_battery'] === null ? 'Battery not captured' : (string) $vehicle['current_battery'],
                'actions' => $this->actions($flags, $turnaround),
                'sort_priority' => $this->sortPriority($primaryStatus, $pickup, $return, $asOf),
            ];
        }, $vehicles);
    }

    /** @return array<int, array<string, mixed>> */
    public function timeline(array $today, DateTimeImmutable $asOf): array
    {
        $events = [];

        foreach (($today['todays_returns'] ?? []) as $return) {
            $events[] = $this->timelineEvent('return', (string) ($return['ends_at'] ?? ''), $return, $asOf);
        }

        foreach (($today['todays_pickups'] ?? []) as $pickup) {
            $events[] = $this->timelineEvent('pickup', (string) ($pickup['starts_at'] ?? ''), $pickup, $asOf);
        }

        foreach (($today['airport_deliveries'] ?? []) as $delivery) {
            $events[] = [
                'time' => (string) ($delivery['scheduled_at'] ?? ''),
                'time_label' => $this->timeLabel((string) ($delivery['scheduled_at'] ?? '')),
                'event_type' => 'Airport delivery',
                'vehicle_label' => (string) ($delivery['fleet_code'] ?? $delivery['display_name'] ?? 'Vehicle'),
                'guest_name' => 'Guest not captured',
                'location_label' => (string) ($delivery['airport_name'] ?? $delivery['airport_code'] ?? 'Airport'),
                'status_label' => 'Scheduled',
                'action_label' => 'Prepare delivery details and confirm charge',
                'href' => '#fleet-timeline',
            ];
        }

        usort($events, static fn (array $left, array $right): int => strcmp($left['time'], $right['time']));

        return $events;
    }

    /** @return array<string, int> */
    public function statusCounts(array $board, float $utilization): array
    {
        return [
            'fleet_size' => count($board),
            'currently_rented' => count(array_filter($board, static fn (array $item): bool => in_array('currently_rented', $item['flags'], true))),
            'available_now' => count(array_filter($board, static fn (array $item): bool => $item['primary_status'] === 'available')),
            'going_out_today' => count(array_filter($board, static fn (array $item): bool => in_array('departing_today', $item['flags'], true))),
            'returning_today' => count(array_filter($board, static fn (array $item): bool => in_array('returning_today', $item['flags'], true))),
            'same_day_turnarounds' => count(array_filter($board, static fn (array $item): bool => in_array('same_day_turnaround', $item['flags'], true))),
            'offline_or_unavailable' => count(array_filter($board, static fn (array $item): bool => in_array('offline', $item['flags'], true))),
            'cleaning_needed' => count(array_filter($board, static fn (array $item): bool => in_array('cleaning_required', $item['flags'], true))),
            'charging_needed' => count(array_filter($board, static fn (array $item): bool => in_array('charging_required', $item['flags'], true))),
            'maintenance_attention' => count(array_filter($board, static fn (array $item): bool => in_array('maintenance_required', $item['flags'], true))),
            'utilization_percent' => (int) round($utilization * 100),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function immediateAttention(array $board, array $externalAlerts): array
    {
        $items = [];

        foreach ($board as $vehicle) {
            if (in_array('late_return', $vehicle['flags'], true)) {
                $items[] = $this->attention('critical', $vehicle['fleet_code'] . ' return time has passed.', 'Confirm return status before the next action.', '#movement-board');
            } elseif (($vehicle['turnaround']['severity'] ?? null) === 'critical') {
                $items[] = $this->attention('critical', $vehicle['fleet_code'] . ' has a critical same-day turnaround.', $vehicle['turnaround']['label'], '#movement-board');
            } elseif (($vehicle['turnaround']['severity'] ?? null) === 'tight') {
                $items[] = $this->attention('today', $vehicle['fleet_code'] . ' has a tight same-day turnaround.', $vehicle['turnaround']['label'], '#movement-board');
            }

            if (in_array('cleaning_required', $vehicle['flags'], true)) {
                $items[] = $this->attention('today', $vehicle['fleet_code'] . ' needs cleaning confirmation.', 'Cleaning status is not fully tracked yet.', '#movement-board');
            }

            if (in_array('charging_required', $vehicle['flags'], true)) {
                $items[] = $this->attention('today', $vehicle['fleet_code'] . ' needs charge confirmation.', 'Battery telemetry is not connected yet.', '#movement-board');
            }
        }

        foreach ($externalAlerts as $alert) {
            if ((int) ($alert['count'] ?? 0) > 0) {
                $items[] = $this->attention((string) $alert['severity'], (string) $alert['label'], (string) $alert['detail'], (string) $alert['href']);
            }
        }

        usort($items, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $items;
    }

    private function flags(array $vehicle, ?array $return, ?array $pickup, ?array $turnaround, bool $needsCleaning, bool $needsMaintenance, DateTimeImmutable $asOf): array
    {
        $flags = [];
        $status = (string) ($vehicle['status'] ?? 'available');

        if ($return !== null && ($return['ends_at'] ?? '') < $asOf->format('Y-m-d H:i:s') && in_array($status, ['reserved', 'in_progress'], true)) {
            $flags[] = 'late_return';
        }
        if ($pickup !== null) {
            $flags[] = 'departing_today';
            $flags[] = 'charging_required';
        }
        if ($return !== null) {
            $flags[] = 'returning_today';
        }
        if ($turnaround !== null) {
            $flags[] = 'same_day_turnaround';
            $flags[] = 'cleaning_required';
            $flags[] = 'charging_required';
        }
        if (in_array($status, ['reserved', 'in_progress'], true)) {
            $flags[] = 'currently_rented';
        }
        if (! in_array($status, ['available', 'reserved', 'in_progress'], true)) {
            $flags[] = 'offline';
        }
        if (in_array($status, ['maintenance'], true)) {
            $flags[] = 'maintenance_required';
        }
        if ($needsCleaning) {
            $flags[] = 'cleaning_required';
        }
        if ($needsMaintenance) {
            $flags[] = 'maintenance_required';
        }

        return array_values(array_unique($flags));
    }

    private function primaryStatus(array $flags, string $fallback): string
    {
        foreach (['late_return', 'same_day_turnaround', 'returning_today', 'departing_today', 'currently_rented', 'maintenance_required', 'offline'] as $status) {
            if (in_array($status, $flags, true)) {
                return $status;
            }
        }

        return $fallback === 'available' ? 'available' : $fallback;
    }

    private function turnaround(?array $return, ?array $pickup): ?array
    {
        if ($return === null || $pickup === null) {
            return null;
        }

        $minutes = max(0, (int) floor(((new DateTimeImmutable((string) $pickup['starts_at']))->getTimestamp() - (new DateTimeImmutable((string) $return['ends_at']))->getTimestamp()) / 60));
        $severity = $minutes < self::CRITICAL_TURNAROUND_MINUTES ? 'critical' : ($minutes <= self::TIGHT_TURNAROUND_MINUTES ? 'tight' : 'comfortable');

        return ['minutes' => $minutes, 'severity' => $severity, 'label' => $this->durationLabel($minutes)];
    }

    private function statusLabel(string $status, ?array $return, ?array $pickup): string
    {
        return match ($status) {
            'late_return' => 'Return time passed; confirm return',
            'same_day_turnaround' => 'Same-day turnaround',
            'returning_today' => 'Returning at ' . $this->timeLabel((string) ($return['ends_at'] ?? '')),
            'departing_today' => 'Going out at ' . $this->timeLabel((string) ($pickup['starts_at'] ?? '')),
            'currently_rented' => 'Currently rented',
            'maintenance_required' => 'Maintenance required',
            'offline' => 'Offline or unavailable',
            default => 'Available today',
        };
    }

    private function actions(array $flags, ?array $turnaround): array
    {
        $actions = [];
        if (in_array('same_day_turnaround', $flags, true)) {
            $actions[] = 'Clean and confirm charge' . ($turnaround === null ? '' : ' within ' . $turnaround['label']);
        }
        if (in_array('departing_today', $flags, true) && ! in_array('same_day_turnaround', $flags, true)) {
            $actions[] = 'Confirm pickup readiness and charge';
        }
        if (in_array('returning_today', $flags, true)) {
            $actions[] = 'Inspect on return and update cleaning status';
        }
        if (in_array('maintenance_required', $flags, true)) {
            $actions[] = 'Review maintenance before release';
        }

        return $actions === [] ? ['No action due'] : $actions;
    }

    private function timelineEvent(string $type, string $time, array $reservation, DateTimeImmutable $asOf): array
    {
        return [
            'time' => $time,
            'time_label' => $this->timeLabel($time),
            'event_type' => $type === 'return' ? 'Return' : 'Pickup',
            'vehicle_label' => (string) ($reservation['fleet_code'] ?? $reservation['display_name'] ?? 'Vehicle'),
            'guest_name' => (string) ($reservation['guest_name'] ?? 'Guest not captured'),
            'location_label' => 'Location not captured',
            'status_label' => (string) ($reservation['status_code'] ?? 'scheduled'),
            'action_label' => $type === 'return' ? 'Inspect, clean, and verify charge' : 'Confirm ready before pickup',
            'href' => '#fleet-timeline',
            'reservation' => $reservation,
            'is_past' => $time !== '' && $time < $asOf->format('Y-m-d H:i:s'),
        ];
    }

    private function byVehicle(array $reservations, string $timeField): array
    {
        $grouped = [];
        foreach ($reservations as $reservation) {
            $vehicleId = (int) ($reservation['fleet_vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $grouped[$vehicleId][] = $reservation;
            }
        }
        foreach ($grouped as &$rows) {
            usort($rows, static fn (array $left, array $right): int => strcmp((string) ($left[$timeField] ?? ''), (string) ($right[$timeField] ?? '')));
        }

        return $grouped;
    }

    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['fleet_vehicle_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function locationLabel(array $vehicle): string
    {
        return $vehicle['current_location'] === null ? 'Location not captured' : (string) $vehicle['current_location'];
    }

    private function statusTone(string $status, ?array $turnaround): string
    {
        if ($status === 'late_return' || ($turnaround['severity'] ?? '') === 'critical') {
            return 'danger';
        }
        if ($status === 'same_day_turnaround' || ($turnaround['severity'] ?? '') === 'tight' || in_array($status, ['returning_today', 'departing_today'], true)) {
            return 'warning';
        }
        if ($status === 'available') {
            return 'success';
        }

        return 'info';
    }

    private function sortPriority(string $status, ?array $pickup, ?array $return, DateTimeImmutable $asOf): string
    {
        $rank = ['late_return' => 0, 'same_day_turnaround' => 1, 'returning_today' => 2, 'departing_today' => 3, 'currently_rented' => 4, 'maintenance_required' => 5, 'offline' => 6, 'available' => 7][$status] ?? 9;
        $time = (string) ($return['ends_at'] ?? $pickup['starts_at'] ?? $asOf->format('Y-m-d H:i:s'));

        return str_pad((string) $rank, 2, '0', STR_PAD_LEFT) . '-' . $time;
    }

    private function attention(string $severity, string $label, string $detail, string $href): array
    {
        return ['severity' => $severity, 'priority' => $severity === 'critical' ? 0 : 1, 'label' => $label, 'detail' => $detail, 'href' => $href];
    }

    private function timeLabel(string $datetime): string
    {
        return $datetime === '' ? 'Time not captured' : (new DateTimeImmutable($datetime))->format('g:i A');
    }

    private function durationLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ($remaining > 0 ? ' ' . $remaining . ' min' : '');
    }
}
