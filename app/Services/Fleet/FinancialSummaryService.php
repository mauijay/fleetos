<?php

namespace App\Services\Fleet;

use Config\Services;
use DateTimeImmutable;

class FinancialSummaryService
{
    public function __construct(private readonly ?FinancialActivityReadService $activityService = null)
    {
    }

    /** @return array<string, mixed> */
    public function currentMonth(int $companyId, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $from = $asOf->modify('first day of this month')->format('Y-m-01');
        $to = $asOf->modify('first day of next month')->format('Y-m-01');

        return $this->period($companyId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function period(int $companyId, string $fromDate, string $toDateExclusive): array
    {
        $result = $this->activities()->forPeriod($companyId, $fromDate, $toDateExclusive);
        $cents = [
            'realized_operating_revenue' => 0,
            'realized_recoveries' => 0,
            'recorded_operating_costs' => 0,
            'forecast_host_payout' => 0,
        ];
        $costsBySource = [];

        foreach ($result['activities'] as $activity) {
            $group = (string) ($activity['financial_group'] ?? '');
            if (! array_key_exists($group, $cents)) {
                continue;
            }
            $amountCents = $this->decimalToCents((string) ($activity['amount'] ?? '0'));
            $cents[$group] += $amountCents;
            if ($group === 'recorded_operating_costs') {
                $source = (string) $activity['source_type'];
                $costsBySource[$source] = ($costsBySource[$source] ?? 0) + $amountCents;
            }
        }

        $netCents = $cents['realized_operating_revenue'] + $cents['realized_recoveries'] - $cents['recorded_operating_costs'];

        return [
            'company_id' => $companyId,
            'period_from' => $fromDate,
            'period_to_exclusive' => $toDateExclusive,
            'realized_operating_revenue' => $this->centsToFloat($cents['realized_operating_revenue']),
            'realized_recoveries' => $this->centsToFloat($cents['realized_recoveries']),
            'recorded_operating_costs' => $this->centsToFloat($cents['recorded_operating_costs']),
            'net_realized_operating_result' => $this->centsToFloat($netCents),
            'forecast_host_payout' => $this->centsToFloat($cents['forecast_host_payout']),
            'costs_by_source' => array_map(fn (int $value): float => $this->centsToFloat($value), $costsBySource),
            'activities' => $result['activities'],
            'diagnostics' => $result['diagnostics'],
        ];
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new \UnexpectedValueException('Financial activity contains an invalid decimal amount.');
        }
        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    private function centsToFloat(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function activities(): FinancialActivityReadService
    {
        return $this->activityService ?? Services::financialActivityReadService();
    }
}
