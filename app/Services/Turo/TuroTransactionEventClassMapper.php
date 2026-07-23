<?php

namespace App\Services\Turo;

class TuroTransactionEventClassMapper
{
    /** @var array<string, string> */
    private const MAP = [
        'trip_earning' => 'operating_revenue',
        'payment' => 'cash_movement',
        'fee' => 'expense',
        'reimbursement' => 'reimbursement',
        'adjustment' => 'adjustment',
        'tax' => 'tax',
        'other' => 'other',
    ];

    public function eventClassForType(string $normalizedType): string
    {
        $normalizedType = strtolower(trim($normalizedType));

        return self::MAP[$normalizedType] ?? 'other';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return self::MAP;
    }
}
