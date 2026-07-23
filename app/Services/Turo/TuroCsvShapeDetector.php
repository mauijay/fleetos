<?php

namespace App\Services\Turo;

class TuroCsvShapeDetector
{
    /** @param array<int, string> $headers */
    public function isTripExport(array $headers): bool
    {
        $headers = $this->normalize($headers);

        $hasTripId = $this->containsAny($headers, ['trip_id', 'reservation_id', 'booking_id']);
        $hasStarts = $this->containsAny($headers, ['starts_at', 'start_time', 'start_date', 'trip_start', 'reservation_start']);
        $hasEnds = $this->containsAny($headers, ['ends_at', 'end_time', 'end_date', 'trip_end', 'reservation_end']);

        return $hasTripId && $hasStarts && $hasEnds;
    }

    /** @param array<int, string> $headers */
    public function isEarningsExport(array $headers): bool
    {
        $headers = $this->normalize($headers);

        $hasTransactionMarker = $this->containsAny($headers, ['transaction_id', 'earnings_id', 'payout_id', 'transaction_date']);
        $hasTypeMarker = $this->containsAny($headers, ['transaction_type', 'type', 'category', 'details', 'description']);
        $hasAmountMarker = $this->containsAny($headers, ['amount', 'total', 'net_amount', 'host_earnings']);

        return $hasTransactionMarker && $hasTypeMarker && $hasAmountMarker;
    }

    /** @param array<int, string> $headers */
    private function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            $header = strtolower(trim($header));
            if ($header !== '') {
                $normalized[] = $header;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** @param array<int, string> $headers @param array<int, string> $aliases */
    private function containsAny(array $headers, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (in_array($alias, $headers, true)) {
                return true;
            }
        }

        return false;
    }
}
