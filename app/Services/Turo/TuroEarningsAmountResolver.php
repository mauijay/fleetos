<?php

namespace App\Services\Turo;

use App\DTOs\Turo\ResolvedEarningsAmount;

class TuroEarningsAmountResolver
{
    /**
     * Authoritative precedence for normalized transaction amount.
     *
     * We prefer explicit amount-style columns first, then earnings-export columns.
     * For earnings_export specifically, `earnings` is treated as authoritative over
     * `payment` when both are present. Raw payload still preserves all source values.
     *
     * @var string[]
     */
    private const PRECEDENCE = [
        'amount',
        'net_amount',
        'host_earnings',
        'earnings',
        'payment',
        'failed_payment',
        'total',
    ];

    /** @return string[] */
    public static function aliases(): array
    {
        return self::PRECEDENCE;
    }

    public function resolve(array $row): ?ResolvedEarningsAmount
    {
        $populated = [];

        foreach (self::PRECEDENCE as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = trim((string) $row[$column]);
            if ($value === '') {
                continue;
            }

            $populated[$column] = $value;
        }

        if ($populated === []) {
            return null;
        }

        $column = array_key_first($populated);
        $rawValue = $populated[$column];

        return new ResolvedEarningsAmount(
            column: $column,
            rawValue: $rawValue,
            parsedValue: $this->money($rawValue),
            populatedColumns: $populated,
        );
    }

    public function money(string $value): ?string
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
