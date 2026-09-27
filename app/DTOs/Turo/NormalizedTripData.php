<?php

namespace App\DTOs\Turo;

class NormalizedTripData
{
    public function __construct(
        public readonly ?int $fleetVehicleId,
        public readonly int $turoTripRawId,
        public readonly int $tripStatusLookupValueId,
        public readonly string $turoTripId,
        public readonly ?string $turoReservationId,
        public readonly ?string $guestName,
        public readonly ?string $bookedAt,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly ?string $canceledAt,
        public readonly string $tripDays,
        public readonly string $billableDays,
        public readonly string $grossRevenueAmount,
        public readonly string $hostPayoutAmount,
        public readonly string $deliveryFeeAmount,
        public readonly string $discountAmount,
        public readonly string $reimbursementAmount,
        public readonly string $airportFeeAmount,
        public readonly string $onTripEvChargingAmount,
        public readonly string $postTripEvChargingAmount,
        public readonly string $currencyCode,
        public readonly bool $isForecast,
    ) {
    }
}
