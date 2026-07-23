<?php

namespace App\Services\Turo;

use App\DTOs\Turo\NormalizedTripData;
use App\DTOs\Turo\RawTripRow;
use App\Repositories\LookupRepository;
use DateTimeImmutable;
use RuntimeException;

class TuroTripNormalizer
{
    public function __construct(
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly TuroVehicleMatcher $vehicleMatcher = new TuroVehicleMatcher(),
    ) {
    }

    public function normalize(RawTripRow $rawTripRow, int $rawTripId): NormalizedTripData
    {
        $row = $rawTripRow->payload;
        $statusCode = $this->statusCode($this->value($row, ['status', 'trip_status']), $this->money($this->value($row, $this->moneyAliases('host_payout'))));
        $startsAt = $this->date($this->value($row, ['starts_at', 'start_time', 'start_date', 'trip_start', 'reservation_start']));
        $endsAt = $this->date($this->value($row, ['ends_at', 'end_time', 'end_date', 'trip_end', 'reservation_end']));
        $tripDays = $this->daysBetween($startsAt, $endsAt);
        $billableDays = $statusCode === 'canceled_zero_payout'
            ? '0.000'
            : $this->decimal($this->value($row, ['billable_days', 'charged_days', 'trip_days']), $tripDays, 3);

        return new NormalizedTripData(
            fleetVehicleId: $this->vehicleMatcher->match($rawTripRow->externalVehicleId, $this->value($row, ['fleet_code', 'vehicle', 'vehicle_name', 'car_name'])),
            turoTripRawId: $rawTripId,
            tripStatusLookupValueId: $this->lookups->valueId('trip_status', $statusCode),
            turoTripId: $rawTripRow->externalTripId ?? $this->requiredValue($row, ['trip_id', 'reservation_id', 'booking_id']),
            turoReservationId: $this->value($row, ['reservation_id', 'booking_id']),
            guestName: $this->value($row, ['guest_name', 'guest', 'renter_name']),
            bookedAt: $this->dateOrNull($this->value($row, ['booked_at', 'booking_date', 'created_at'])),
            startsAt: $startsAt->format('Y-m-d H:i:s'),
            endsAt: $endsAt->format('Y-m-d H:i:s'),
            canceledAt: $this->dateOrNull($this->value($row, ['canceled_at', 'cancelled_at', 'cancellation_date'])),
            tripDays: $tripDays,
            billableDays: $billableDays,
            grossRevenueAmount: $this->money($this->value($row, $this->moneyAliases('gross_revenue'))),
            hostPayoutAmount: $this->money($this->value($row, $this->moneyAliases('host_payout'))),
            deliveryFeeAmount: $this->money($this->value($row, $this->moneyAliases('delivery_fee'))),
            discountAmount: $this->money($this->value($row, $this->moneyAliases('discount'))),
            reimbursementAmount: $this->money($this->value($row, $this->moneyAliases('reimbursement'))),
            airportFeeAmount: $this->money($this->value($row, $this->moneyAliases('airport_fee'))),
            currencyCode: strtoupper($this->value($row, ['currency', 'currency_code']) ?? 'USD'),
            isForecast: $statusCode === 'booked',
        );
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

    public function money(?string $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return '0.00';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /** @return string[] */
    public function moneyAliases(string $field): array
    {
        return match ($field) {
            'gross_revenue' => ['gross_revenue', 'trip_price', 'total_revenue', 'gross_earnings'],
            'host_payout' => ['host_payout', 'host_earnings', 'earnings', 'net_earnings'],
            'delivery_fee' => ['delivery_fee', 'delivery_fee_amount'],
            'discount' => ['discount', 'discount_amount'],
            'reimbursement' => ['reimbursement', 'reimbursement_amount'],
            'airport_fee' => ['airport_fee', 'airport_fee_amount'],
            default => [$field],
        };
    }

    private function statusCode(?string $status, string $hostPayout): string
    {
        $normalized = strtolower(trim((string) $status));
        $normalized = str_replace(['-', '_'], ' ', $normalized);

        if (str_contains($normalized, 'cancel')) {
            return (float) $hostPayout > 0 ? 'canceled_host_payout' : 'canceled_zero_payout';
        }

        if (str_contains($normalized, 'complete')) {
            return 'completed';
        }

        if (str_contains($normalized, 'progress') || str_contains($normalized, 'active')) {
            return 'in_progress';
        }

        return 'booked';
    }

    private function requiredValue(array $row, array $aliases): string
    {
        return $this->value($row, $aliases) ?? '';
    }

    private function date(?string $value): DateTimeImmutable
    {
        if ($value === null) {
            throw new RuntimeException('Validated trip row is missing a required date/time value.');
        }

        return new DateTimeImmutable($value);
    }

    private function dateOrNull(?string $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    }

    private function daysBetween(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): string
    {
        $seconds = max(0, $endsAt->getTimestamp() - $startsAt->getTimestamp());

        return number_format($seconds / 86400, 3, '.', '');
    }

    private function decimal(?string $value, string $default, int $precision): string
    {
        if ($value === null) {
            return $default;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return $default;
        }

        return number_format((float) $normalized, $precision, '.', '');
    }
}
