<?php

namespace App\Validation\Turo;

use App\DTOs\Turo\ValidationIssue;
use DateTimeImmutable;

class TuroTripCsvValidator
{
    /** @return ValidationIssue[] */
    public function validate(array $row): array
    {
        $issues = [];

        if ($this->value($row, ['trip_id', 'reservation_id', 'booking_id']) === null) {
            $issues[] = new ValidationIssue('missing_trip_id', 'Trip row is missing a trip or reservation id.', 'trip_id');
        }

        $startsAt = $this->value($row, ['starts_at', 'start_time', 'start_date', 'trip_start', 'reservation_start']);
        $endsAt = $this->value($row, ['ends_at', 'end_time', 'end_date', 'trip_end', 'reservation_end']);

        if ($startsAt === null) {
            $issues[] = new ValidationIssue('missing_start', 'Trip row is missing a start date/time.', 'starts_at');
        } elseif ($this->date($startsAt) === null) {
            $issues[] = new ValidationIssue('invalid_start', "Trip start date/time could not be read. Use a date like 2026-01-15 10:00 AM; received '{$this->preview($startsAt)}'.", 'starts_at');
        }

        if ($endsAt === null) {
            $issues[] = new ValidationIssue('missing_end', 'Trip row is missing an end date/time.', 'ends_at');
        } elseif ($this->date($endsAt) === null) {
            $issues[] = new ValidationIssue('invalid_end', "Trip end date/time could not be read. Use a date like 2026-01-17 10:00 AM; received '{$this->preview($endsAt)}'.", 'ends_at');
        }

        $startDate = $startsAt === null ? null : $this->date($startsAt);
        $endDate = $endsAt === null ? null : $this->date($endsAt);

        if ($startDate !== null && $endDate !== null && $endDate <= $startDate) {
            $issues[] = new ValidationIssue('invalid_date_range', 'Trip end must be after trip start.', 'ends_at');
        }

        foreach (['gross_revenue', 'host_payout', 'delivery_fee', 'discount', 'reimbursement', 'airport_fee', 'on_trip_ev_charging', 'post_trip_ev_charging'] as $field) {
            $value = $this->value($row, $this->moneyAliases($field));
            if ($value !== null && $this->money($value) === null) {
                $issues[] = new ValidationIssue('invalid_money', "Money value in {$field} could not be read. Use a format like 100.00 or $100.00; received '{$this->preview($value)}'.", $field);
            }
        }

        if ($this->value($row, ['status', 'trip_status']) === null) {
            $issues[] = new ValidationIssue('missing_status', 'Trip row is missing a status. It will be imported as Booked unless the CSV status is corrected.', 'status', 'warning');
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

    private function date(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(string $value): ?string
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function moneyAliases(string $field): array
    {
        return match ($field) {
            'gross_revenue' => ['gross_revenue', 'trip_price', 'total_revenue', 'gross_earnings'],
            'host_payout' => ['host_payout', 'host_earnings', 'earnings', 'net_earnings'],
            'delivery_fee' => ['delivery_fee', 'delivery_fee_amount'],
            'discount' => ['discount', 'discount_amount'],
            'reimbursement' => ['reimbursement', 'reimbursement_amount'],
            'airport_fee' => ['airport_fee', 'airport_fee_amount'],
            'on_trip_ev_charging' => ['on_trip_ev_charging', 'on_trip_ev_charging_amount'],
            'post_trip_ev_charging' => ['post_trip_ev_charging', 'post_trip_ev_charging_amount'],
            default => [$field],
        };
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
