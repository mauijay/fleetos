<?php

namespace App\Services\Turo;

use App\DTOs\Turo\NormalizedTransactionData;
use App\DTOs\Turo\RawTransactionRow;
use App\Repositories\TuroNormalizedTripRepository;

class TuroEarningsNormalizer
{
    public function __construct(
        private readonly TuroNormalizedTripRepository $trips = new TuroNormalizedTripRepository(),
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

        return new NormalizedTransactionData(
            turoTransactionRawId: $rawTransactionId,
            turoTripNormalizedId: $trip === null ? null : (int) $trip['id'],
            fleetVehicleId: $fleetVehicleId,
            externalTransactionId: $row->externalTransactionId,
            externalTripId: $row->externalTripId,
            transactionType: $transactionType,
            normalizedType: $this->normalizedType($transactionType, $description),
            description: $description,
            amount: $this->money($this->value($row->payload, ['amount', 'total', 'net_amount', 'host_earnings']) ?? '0') ?? '0.00',
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

    private function normalizedType(string $transactionType, ?string $description): string
    {
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

        if (str_contains($haystack, 'payment') || str_contains($haystack, 'earning') || str_contains($haystack, 'payout')) {
            return 'payment';
        }

        return 'other';
    }

    private function money(string $value): ?string
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
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
        $components = [
            strtolower($row->externalTransactionId ?? ''),
            strtolower($row->externalTripId ?? ''),
            strtolower($this->value($row->payload, ['transaction_type', 'type', 'category', 'details']) ?? ''),
            strtolower($this->money($this->value($row->payload, ['amount', 'total', 'net_amount', 'host_earnings']) ?? '0') ?? '0.00'),
            strtolower($this->date($row->transactionDate) ?? ''),
            $row->rowHash,
        ];

        return hash('sha256', implode('|', $components));
    }
}
