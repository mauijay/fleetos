<?php

namespace App\Services\Fleet;

/** Reservation schedule for decisions; checklist scheduled_at remains historical evidence. */
class MovementScheduleAuthorityService
{
    /** @param array<string, mixed> $movement */
    public function scheduledAt(array $movement): ?string
    {
        $field = match ($movement['movement_type'] ?? null) {
            'pickup' => 'starts_at',
            'return' => 'ends_at',
            default => null,
        };
        $value = $field === null ? '' : trim((string) ($movement[$field] ?? ''));

        return $value === '' ? null : $value;
    }
}
