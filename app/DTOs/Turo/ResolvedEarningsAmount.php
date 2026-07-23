<?php

namespace App\DTOs\Turo;

class ResolvedEarningsAmount
{
    /** @param array<string, string> $populatedColumns */
    public function __construct(
        public readonly string $column,
        public readonly string $rawValue,
        public readonly ?string $parsedValue,
        public readonly array $populatedColumns,
    ) {
    }
}
