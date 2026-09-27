<?php

use App\DTOs\Turo\NormalizedTripData;
use App\Services\Turo\TripMonthAllocationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TripMonthAllocationServiceTest extends CIUnitTestCase
{
    public function testAllocatesTripAcrossTwoMonthsWithRemainderOnLastMonth(): void
    {
        $trip = new NormalizedTripData(
            fleetVehicleId: 7,
            turoTripRawId: 11,
            tripStatusLookupValueId: 3,
            turoTripId: 'trip-123',
            turoReservationId: 'res-123',
            guestName: 'Example Guest',
            bookedAt: null,
            startsAt: '2026-01-31 12:00:00',
            endsAt: '2026-02-02 12:00:00',
            canceledAt: null,
            tripDays: '2.000',
            billableDays: '2.000',
            grossRevenueAmount: '300.00',
            hostPayoutAmount: '240.00',
            deliveryFeeAmount: '30.00',
            discountAmount: '0.00',
            reimbursementAmount: '10.00',
            airportFeeAmount: '0.00',
            onTripEvChargingAmount: '0.00',
            postTripEvChargingAmount: '0.00',
            currencyCode: 'USD',
            isForecast: false,
        );

        $allocations = (new TripMonthAllocationService())->allocate($trip);

        $this->assertCount(2, $allocations);
        $this->assertSame('2026-01-01', $allocations[0]->allocationMonth);
        $this->assertSame('2026-02-01', $allocations[1]->allocationMonth);
        $this->assertSame('0.500', $allocations[0]->allocatedTripDays);
        $this->assertSame('1.500', $allocations[1]->allocatedTripDays);
        $this->assertSame('75.00', $allocations[0]->allocatedGrossRevenueAmount);
        $this->assertSame('225.00', $allocations[1]->allocatedGrossRevenueAmount);
        $this->assertSame('240.00', number_format(
            (float) $allocations[0]->allocatedHostPayoutAmount + (float) $allocations[1]->allocatedHostPayoutAmount,
            2,
            '.',
            '',
        ));
    }

    public function testAllocatesLongTripAcrossCalendarYearMonths(): void
    {
        $trip = $this->trip(
            startsAt: '2026-12-30 00:00:00',
            endsAt: '2027-02-02 00:00:00',
            tripDays: '34.000',
            billableDays: '34.000',
            grossRevenueAmount: '3400.00',
            hostPayoutAmount: '2720.00',
        );

        $allocations = (new TripMonthAllocationService())->allocate($trip);

        $this->assertCount(3, $allocations);
        $this->assertSame('2026-12-01', $allocations[0]->allocationMonth);
        $this->assertSame('2027-01-01', $allocations[1]->allocationMonth);
        $this->assertSame('2027-02-01', $allocations[2]->allocationMonth);
        $this->assertSame('2.000', $allocations[0]->allocatedTripDays);
        $this->assertSame('31.000', $allocations[1]->allocatedTripDays);
        $this->assertSame('1.000', $allocations[2]->allocatedTripDays);
        $this->assertSame('34.000', number_format(array_sum(array_map(static fn ($allocation): float => (float) $allocation->allocatedTripDays, $allocations)), 3, '.', ''));
        $this->assertSame('3400.00', number_format(array_sum(array_map(static fn ($allocation): float => (float) $allocation->allocatedGrossRevenueAmount, $allocations)), 2, '.', ''));
    }

    private function trip(
        string $startsAt,
        string $endsAt,
        string $tripDays,
        string $billableDays,
        string $grossRevenueAmount,
        string $hostPayoutAmount,
    ): NormalizedTripData {
        return new NormalizedTripData(
            fleetVehicleId: 7,
            turoTripRawId: 11,
            tripStatusLookupValueId: 3,
            turoTripId: 'trip-123',
            turoReservationId: 'res-123',
            guestName: 'Example Guest',
            bookedAt: null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            canceledAt: null,
            tripDays: $tripDays,
            billableDays: $billableDays,
            grossRevenueAmount: $grossRevenueAmount,
            hostPayoutAmount: $hostPayoutAmount,
            deliveryFeeAmount: '30.00',
            discountAmount: '0.00',
            reimbursementAmount: '10.00',
            airportFeeAmount: '0.00',
            onTripEvChargingAmount: '0.00',
            postTripEvChargingAmount: '0.00',
            currencyCode: 'USD',
            isForecast: false,
        );
    }
}
