<?php

namespace App\Validation\Turo;

use App\DTOs\Turo\ValidationIssue;
use App\Services\Turo\TuroEarningsAmountResolver;
use DateTimeImmutable;

class TuroEarningsCsvValidator
{
    public function __construct(
        private readonly TuroEarningsAmountResolver $amountResolver = new TuroEarningsAmountResolver(),
    ) {
    }

    /** @return ValidationIssue[] */
    public function validate(array $row): array
    {
        $issues = [];

        $resolvedAmount = $this->amountResolver->resolve($row);
        if ($resolvedAmount === null) {
            $issues[] = new ValidationIssue('missing_amount', 'Earnings row is missing an amount value.', 'amount');
        } elseif ($resolvedAmount->parsedValue === null) {
            $issues[] = new ValidationIssue('invalid_money', "Money value in amount could not be read. Use a format like 100.00 or $100.00; received '{$this->preview($resolvedAmount->rawValue)}'.", 'amount');
        }

        $transactionDate = $this->value($row, ['transaction_date', 'date', 'created_at', 'processed_at']);
        if ($transactionDate !== null && $this->date($transactionDate) === null) {
            $issues[] = new ValidationIssue('invalid_transaction_date', "Transaction date could not be read. Use a date like 2026-01-15; received '{$this->preview($transactionDate)}'.", 'transaction_date');
        }

        if ($this->value($row, ['transaction_type', 'type', 'category', 'details', 'description']) === null) {
            $issues[] = new ValidationIssue('missing_transaction_type', 'Earnings row is missing a transaction type. It will be categorized as Other unless the CSV value is corrected.', 'transaction_type', 'warning');
        }

        return $issues;
    }

    public function value(array $row, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                return trim((string) $row[$alias]);
            }
        }

        return null;
    }

    public function resolveAmount(array $row): ?\App\DTOs\Turo\ResolvedEarningsAmount
    {
        return $this->amountResolver->resolve($row);
    }

    private function date(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function preview(string $value): string
    {
        $value = trim($value);

        if (strlen($value) <= 40) {
            return $value;
        }

        return substr($value, 0, 37) . '...';
    }
}
