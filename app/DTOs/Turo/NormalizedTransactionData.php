<?php

namespace App\DTOs\Turo;

class NormalizedTransactionData
{
    public function __construct(
        public readonly int $turoTransactionRawId,
        public readonly ?int $turoTripNormalizedId,
        public readonly ?int $fleetVehicleId,
        public readonly ?string $externalTransactionId,
        public readonly ?string $externalTripId,
        public readonly string $transactionType,
        public readonly string $normalizedType,
        public readonly ?string $description,
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly ?string $transactionDate,
        public readonly string $rowFingerprint,
    ) {
    }
}
