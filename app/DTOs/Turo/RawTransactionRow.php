<?php

namespace App\DTOs\Turo;

class RawTransactionRow
{
    /** @param array<string, string|null> $payload */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $payload,
        public readonly ?string $externalTransactionId,
        public readonly ?string $externalTripId,
        public readonly ?string $transactionDate,
        public readonly string $rowHash,
    ) {
    }
}
