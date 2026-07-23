<?php

namespace App\Services\Turo;

use App\DTOs\Turo\NormalizedTransactionData;
use App\DTOs\Turo\RawTransactionRow;
use App\Repositories\TuroNormalizedTripRepository;

class TuroEarningsNormalizer
{
    public function __construct(
        private readonly TuroNormalizedTripRepository $trips = new TuroNormalizedTripRepository(),
        private readonly TuroEarningsAmountResolver $amountResolver = new TuroEarningsAmountResolver(),
        private readonly TuroReservationUrlTripIdExtractor $reservationUrlTripIdExtractor = new TuroReservationUrlTripIdExtractor(),
        private readonly TuroTransactionEventClassMapper $eventClassMapper = new TuroTransactionEventClassMapper(),
    ) {
    }

    public function normalize(RawTransactionRow $row, int $rawTransactionId): NormalizedTransactionData
    {
        $transactionType = $this->value($row->payload, ['transaction_type', 'type', 'category', 'details']) ?? 'Other';
        $description = $this->value($row->payload, ['description', 'details', 'note', 'memo']);
        $trip = $row->externalTripId === null ? null : $this->trips->findByTuroTripId($row->externalTripId);
        $fleetVehicleId = $trip === null ? null : (int) ($trip['fleet_vehicle_id'] ?? 0);
        if ($fleetVehicleId === 0) {
            $fleetVehicleId = null;
        }

        $resolvedAmount = $this->amountResolver->resolve($row->payload);
        $normalizedType = $this->normalizedType($row->payload, $transactionType, $description);

        return new NormalizedTransactionData(
            turoTransactionRawId: $rawTransactionId,
            turoTripNormalizedId: $trip === null ? null : (int) $trip['id'],
            fleetVehicleId: $fleetVehicleId,
            externalTransactionId: $row->externalTransactionId,
            externalTripId: $row->externalTripId,
            transactionType: $transactionType,
            normalizedType: $normalizedType,
            eventClass: $this->eventClassMapper->eventClassForType($normalizedType),
            description: $description,
            amount: $resolvedAmount?->parsedValue ?? '0.00',
            currencyCode: strtoupper($this->value($row->payload, ['currency', 'currency_code']) ?? 'USD'),
            transactionDate: $this->date($row->transactionDate),
            rowFingerprint: $this->fingerprint($row),
        );
    }

    public function value(array $payload, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (isset($payload[$alias]) && trim((string) $payload[$alias]) !== '') {
                return trim((string) $payload[$alias]);
            }
        }

        return null;
    }

    private function normalizedType(array $payload, string $transactionType, ?string $description): string
    {
        if ($this->isTripEarningType($payload, $transactionType, $description)) {
            return 'trip_earning';
        }

        if ($this->isPaymentType($transactionType)) {
            return 'payment';
        }

        $haystack = strtolower(trim($transactionType . ' ' . ($description ?? '')));

        if (str_contains($haystack, 'reimbursement')) {
            return 'reimbursement';
        }

        if (str_contains($haystack, 'tax')) {
            return 'tax';
        }

        if (str_contains($haystack, 'adjustment')) {
            return 'adjustment';
        }

        if (str_contains($haystack, 'fee')) {
            return 'fee';
        }

        return 'other';
    }

    private function isTripEarningType(array $payload, string $transactionType, ?string $description): bool
    {
        $reservationUrl = $this->value($payload, ['reservation_url']);
        if ($this->reservationUrlTripIdExtractor->extract($reservationUrl) === null) {
            return false;
        }

        $combined = trim($transactionType . ' ' . ($description ?? ''));

        return preg_match('/\btrip\s+with\b/i', $combined) === 1;
    }

    private function isPaymentType(string $transactionType): bool
    {
        return preg_match('/^payment\s*\(.+\)$/i', trim($transactionType)) === 1;
    }

    private function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fingerprint(RawTransactionRow $row): string
    {
        $resolvedAmount = $this->amountResolver->resolve($row->payload);

        $components = [
            strtolower($row->externalTransactionId ?? ''),
            strtolower($row->externalTripId ?? ''),
            strtolower($this->value($row->payload, ['transaction_type', 'type', 'category', 'details']) ?? ''),
            strtolower($resolvedAmount?->parsedValue ?? '0.00'),
            strtolower($resolvedAmount?->column ?? ''),
            strtolower($this->date($row->transactionDate) ?? ''),
            $row->rowHash,
        ];

        return hash('sha256', implode('|', $components));
    }

    public function resolveAmount(array $payload): ?\App\DTOs\Turo\ResolvedEarningsAmount
    {
        return $this->amountResolver->resolve($payload);
    }
}
