<?php

namespace App\Services\Fleet\DecisionSupport;

use App\DTOs\Fleet\Recommendation;
use App\Services\Fleet\TripAnalyticsService;
use Config\DecisionSupport;
use DateTimeImmutable;

class GuestRiskService
{
    public function __construct(
        private readonly ?TripAnalyticsService $tripAnalyticsService = null,
        private readonly ?DecisionSupport $config = null,
        private readonly ?RecommendationFactory $factory = null,
    ) {
    }

    /** @return array<int, Recommendation> */
    public function recommendations(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $startsAt = $asOf->modify('-' . $this->config()->pricingLookbackDays . ' days');
        $summary = $this->analytics()->summary($startsAt, $asOf);
        $recommendations = [];

        if ((float) ($summary['cancellation_rate'] ?? 0) >= $this->config()->guestCancellationRate) {
            $recommendations[] = $this->factory()->make(
                'Review guest cancellation exposure',
                'Guest Risk',
                'Medium',
                $this->confidence((int) ($summary['trip_count'] ?? 0)),
                'Cancellation rate exceeds the configured guest-risk threshold for the measured period.',
                ['cancellation_rate' => $this->percent((float) ($summary['cancellation_rate'] ?? 0)), 'trip_count' => (int) ($summary['trip_count'] ?? 0)],
                'Review cancellation patterns before changing guest screening or booking rules.',
                $asOf,
                self::class,
            );
        }

        if ((float) ($summary['average_trip_length'] ?? 0) >= $this->config()->longTermRentalDays) {
            $recommendations[] = $this->factory()->make(
                'Review long-term rental exposure',
                'Guest Risk',
                'Informational',
                $this->confidence((int) ($summary['trip_count'] ?? 0)),
                'Average trip length meets or exceeds the configured long-term rental threshold.',
                ['average_trip_length' => round((float) ($summary['average_trip_length'] ?? 0), 2), 'long_term_rental_days' => $this->config()->longTermRentalDays],
                'Confirm long-term rental pricing, mileage, charging, and maintenance expectations are still appropriate.',
                $asOf,
                self::class,
            );
        }

        return $recommendations;
    }

    private function confidence(int $tripCount): int
    {
        return min(92, 55 + ($tripCount * 5));
    }

    private function percent(float $value): int
    {
        return (int) round($value * 100);
    }

    private function analytics(): TripAnalyticsService
    {
        return $this->tripAnalyticsService ?? service('tripAnalyticsService');
    }

    private function config(): DecisionSupport
    {
        return $this->config ?? config(DecisionSupport::class);
    }

    private function factory(): RecommendationFactory
    {
        return $this->factory ?? new RecommendationFactory($this->config());
    }
}
