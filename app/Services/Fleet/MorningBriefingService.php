<?php

namespace App\Services\Fleet;

class MorningBriefingService
{
    /** @param array<int, array<string, mixed>> $board @param array<int, array<string, mixed>> $attention */
    public function briefing(array $board, array $attention, int $pickupCount, int $returnCount): array
    {
        $parts = [];
        $critical = array_values(array_filter($attention, static fn (array $item): bool => $item['severity'] === 'critical'));
        $turnarounds = array_values(array_filter($board, static fn (array $item): bool => in_array('same_day_turnaround', $item['flags'], true)));

        if ($critical !== []) {
            $parts[] = $critical[0]['label'];
        }
        if ($turnarounds !== []) {
            $parts[] = $turnarounds[0]['fleet_code'] . ' has a same-day turnaround with ' . $turnarounds[0]['turnaround']['label'] . ' between trips.';
        }
        if ($parts === []) {
            $parts[] = 'Today has ' . $this->countLabel($pickupCount, 'pickup') . ' and ' . $this->countLabel($returnCount, 'return') . ', with no tight turnarounds or urgent fleet issues.';
        } else {
            $parts[] = 'Today has ' . $this->countLabel($pickupCount, 'pickup') . ' and ' . $this->countLabel($returnCount, 'return') . '.';
        }

        return [
            'greeting' => 'Good Morning, Jay.',
            'message' => implode(' ', array_slice($parts, 0, 2)),
        ];
    }

    private function countLabel(int $count, string $label): string
    {
        return $count . ' ' . $label . ($count === 1 ? '' : 's');
    }
}
