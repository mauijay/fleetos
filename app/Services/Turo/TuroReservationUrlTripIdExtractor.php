<?php

namespace App\Services\Turo;

class TuroReservationUrlTripIdExtractor
{
    public function extract(?string $reservationUrl): ?string
    {
        if ($reservationUrl === null) {
            return null;
        }

        $reservationUrl = trim($reservationUrl);
        if ($reservationUrl === '') {
            return null;
        }

        $host = parse_url($reservationUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && ! str_contains(strtolower($host), 'turo.com')) {
            return null;
        }

        $path = parse_url($reservationUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if (! preg_match('#(?:^|/)reservation/(\d+)$#i', $path, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }
}
