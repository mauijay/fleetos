<?php

namespace App\Services\Fleet;

class MovementReadinessService
{
    /** @param array<int, array<string, mixed>> $items */
    public function status(string $movementType, array $items, ?string $disposition = null, bool $completed = false): string
    {
        if ($completed) {
            return 'completed';
        }

        $applicable = array_values(array_filter($items, static fn (array $item): bool => ($item['applicability'] ?? 'applicable') === 'applicable'));
        $criticalOpen = array_values(array_filter($applicable, static fn (array $item): bool => (bool) ($item['is_critical'] ?? false) && ($item['completion_state'] ?? 'open') !== 'complete'));

        if ($movementType === 'return' && $disposition === null) {
            return 'blocked';
        }

        if ($criticalOpen !== []) {
            return count($criticalOpen) === count(array_filter($applicable, static fn (array $item): bool => (bool) ($item['is_critical'] ?? false))) ? 'not_started' : 'in_progress';
        }

        return 'ready';
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, mixed> */
    public function progress(array $items): array
    {
        $required = array_values(array_filter($items, static fn (array $item): bool => (bool) ($item['is_required'] ?? false) && ($item['applicability'] ?? 'applicable') === 'applicable'));
        $complete = array_values(array_filter($required, static fn (array $item): bool => ($item['completion_state'] ?? 'open') === 'complete'));
        $remainingCritical = array_values(array_filter($required, static fn (array $item): bool => (bool) ($item['is_critical'] ?? false) && ($item['completion_state'] ?? 'open') !== 'complete'));

        return [
            'required_count' => count($required),
            'required_complete_count' => count($complete),
            'required_remaining_count' => max(0, count($required) - count($complete)),
            'percent' => count($required) === 0 ? 100 : (int) round((count($complete) / count($required)) * 100),
            'remaining_critical_labels' => array_map(static fn (array $item): string => (string) $item['label'], $remainingCritical),
        ];
    }
}
