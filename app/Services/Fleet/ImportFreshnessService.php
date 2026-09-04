<?php

namespace App\Services\Fleet;

use Config\MovementIntelligence;

class ImportFreshnessService
{
    public function __construct(private readonly ?MovementIntelligence $config = null)
    {
    }

    /** @return array{age_seconds:?int,age_label:string,is_stale:bool,warning:?string} */
    public function assess(?string $completedAt, ?\DateTimeImmutable $asOf = null): array
    {
        if ($completedAt === null || trim($completedAt) === '') {
            return ['age_seconds' => null, 'age_label' => 'Import time unknown', 'is_stale' => true, 'warning' => 'Refresh Turo data before finalizing.'];
        }
        $asOf ??= new \DateTimeImmutable();
        $age = max(0, $asOf->getTimestamp() - (new \DateTimeImmutable($completedAt))->getTimestamp());
        $stale = $age >= $this->settings()->importFreshnessWarningHours * 3600;

        return [
            'age_seconds' => $age,
            'age_label' => $this->ageLabel($age),
            'is_stale' => $stale,
            'warning' => $stale ? 'Refresh Turo data before finalizing.' : null,
        ];
    }

    private function ageLabel(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        if ($days > 0) {
            return $days . 'd' . ($hours > 0 ? ' ' . $hours . 'h' : '') . ' old';
        }

        return max(0, $hours) . 'h old';
    }

    private function settings(): MovementIntelligence
    {
        return $this->config ?? new MovementIntelligence();
    }
}
