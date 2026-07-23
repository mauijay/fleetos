<?php

use App\DTOs\Turo\RawTripRow;
use App\Repositories\LookupRepository;
use App\Services\Turo\TuroTripNormalizer;
use App\Services\Turo\TuroVehicleMatcher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroTripNormalizerTest extends CIUnitTestCase
{
    public function testCanceledTripWithHostPayoutKeepsBillableDays(): void
    {
        $trip = $this->normalizer()->normalize($this->rawRow([
            'trip_id' => 'trip-canceled-paid',
            'status' => 'Canceled',
            'starts_at' => '2026-03-01 10:00:00',
            'ends_at' => '2026-03-03 10:00:00',
            'host_payout' => '$120.00',
            'billable_days' => '2',
        ]), 44);

        $this->assertSame(102, $trip->tripStatusLookupValueId);
        $this->assertSame('2.000', $trip->billableDays);
        $this->assertFalse($trip->isForecast);
    }

    public function testCanceledTripWithZeroPayoutHasZeroBillableDays(): void
    {
        $trip = $this->normalizer()->normalize($this->rawRow([
            'trip_id' => 'trip-canceled-zero',
            'status' => 'Cancelled',
            'starts_at' => '2026-03-01 10:00:00',
            'ends_at' => '2026-03-03 10:00:00',
            'host_payout' => '$0.00',
            'billable_days' => '2',
        ]), 44);

        $this->assertSame(103, $trip->tripStatusLookupValueId);
        $this->assertSame('0.000', $trip->billableDays);
        $this->assertFalse($trip->isForecast);
    }

    public function testInProgressTripIsNotForecast(): void
    {
        $trip = $this->normalizer()->normalize($this->rawRow([
            'trip_id' => 'trip-active',
            'status' => 'In Progress',
            'starts_at' => '2026-04-01 10:00:00',
            'ends_at' => '2026-04-04 10:00:00',
            'host_payout' => '$300.00',
        ]), 44);

        $this->assertSame(104, $trip->tripStatusLookupValueId);
        $this->assertFalse($trip->isForecast);
    }

    public function testUnmappedVehicleLeavesFleetVehicleNull(): void
    {
        $trip = $this->normalizer(null)->normalize($this->rawRow([
            'trip_id' => 'trip-unmapped',
            'vehicle_id' => 'missing-turo-car',
            'fleet_code' => 'missing-fleet-code',
            'status' => 'Completed',
            'starts_at' => '2026-05-01 10:00:00',
            'ends_at' => '2026-05-03 10:00:00',
            'host_payout' => '$220.00',
        ]), 44);

        $this->assertNull($trip->fleetVehicleId);
        $this->assertSame(101, $trip->tripStatusLookupValueId);
    }

    public function testVehicleColumnIsUsedForFleetMatching(): void
    {
        $trip = $this->normalizer(matcherCallback: function (?string $turoVehicleId, ?string $fleetCode): int {
            $this->assertSame('3775859', $turoVehicleId);
            $this->assertSame('Spaceship-008 (HI #651359)', $fleetCode);

            return 8;
        })->normalize($this->rawRow([
            'trip_id' => 'trip-spaceship-label',
            'vehicle_id' => '3775859',
            'vehicle' => 'Spaceship-008 (HI #651359)',
            'vehicle_name' => 'Tesla Model Y 2026',
            'status' => 'Completed',
            'starts_at' => '2026-05-01 10:00:00',
            'ends_at' => '2026-05-03 10:00:00',
            'host_payout' => '$220.00',
        ]), 44);

        $this->assertSame(8, $trip->fleetVehicleId);
    }

    private function normalizer(?int $matchedVehicleId = 9, ?callable $matcherCallback = null): TuroTripNormalizer
    {
        $lookups = $this->getMockBuilder(LookupRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['valueId'])
            ->getMock();
        $lookups->method('valueId')->willReturnCallback(static function (string $typeCode, string $valueCode): int {
            $ids = [
                'trip_status:booked' => 100,
                'trip_status:completed' => 101,
                'trip_status:canceled_host_payout' => 102,
                'trip_status:canceled_zero_payout' => 103,
                'trip_status:in_progress' => 104,
            ];

            return $ids[$typeCode . ':' . $valueCode];
        });

        $matcher = $this->getMockBuilder(TuroVehicleMatcher::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['match'])
            ->getMock();
        if ($matcherCallback !== null) {
            $matcher->method('match')->willReturnCallback($matcherCallback);
        } else {
            $matcher->method('match')->willReturn($matchedVehicleId);
        }

        return new TuroTripNormalizer($lookups, $matcher);
    }

    private function rawRow(array $payload): RawTripRow
    {
        return new RawTripRow(
            rowNumber: 2,
            payload: $payload,
            externalTripId: $payload['trip_id'],
            externalVehicleId: $payload['vehicle_id'] ?? null,
            rowHash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }
}
