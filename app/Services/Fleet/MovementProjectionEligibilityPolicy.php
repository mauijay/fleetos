<?php

namespace App\Services\Fleet;

use Config\App;
use Config\MovementIntelligence;
use DateTimeImmutable;
use DateTimeZone;

class MovementProjectionEligibilityPolicy
{
    private const ELIGIBLE_STATUSES = ['booked', 'in_progress'];

    public function __construct(
        private readonly ?MovementIntelligence $config = null,
        private readonly ?App $app = null,
    ) {
    }

    /** @return array{start:DateTimeImmutable,end:DateTimeImmutable} */
    public function automaticWindow(?DateTimeImmutable $asOf = null): array
    {
        $timezone = new DateTimeZone(($this->app ?? new App())->appTimezone);
        $start = ($asOf ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);

        return [
            'start' => $start,
            'end' => $start->modify('+' . ($this->settings()->projectionHorizonDays + 1) . ' days'),
        ];
    }

    /** @param array<string, mixed> $trip */
    public function evaluate(array $trip, string $scheduledAt, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        if (! in_array((string) ($trip['trip_status_code'] ?? ''), self::ELIGIBLE_STATUSES, true)) {
            return 'terminal_status';
        }

        $scheduledAt = trim($scheduledAt);
        if ($scheduledAt === '') {
            return 'outside_window';
        }

        $scheduled = new DateTimeImmutable($scheduledAt, $start->getTimezone());

        return $scheduled >= $start && $scheduled < $end ? 'eligible' : 'outside_window';
    }

    private function settings(): MovementIntelligence
    {
        return $this->config ?? new MovementIntelligence();
    }
}
