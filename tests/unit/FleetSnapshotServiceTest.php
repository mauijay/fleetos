<?php

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\FleetSnapshotService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FleetSnapshotServiceTest extends CIUnitTestCase
{
    public function testSnapshotUsesMutuallyExclusiveAuthoritativeCurrentStateAndStableLabels(): void
    {
        $locations = $this->createMock(CurrentVehicleLocationService::class);
        $locations->expects($this->once())->method('forCompany')->with(1, $this->isInstanceOf(DateTimeImmutable::class))->willReturn([
            $this->row(101, 2, 'rented', 'airport_hnl', 'actual_handoff'),
            $this->row(102, 3, 'rented', 'home', 'actual_handoff'),
            $this->row(103, 4, 'parked', 'airport_hnl', 'vehicle_staged'),
            $this->row(104, 5, 'parked', 'home', 'vehicle_positioned'),
            $this->row(105, 6, 'parked', 'home', 'vehicle_positioned'),
            $this->row(106, 7, 'parked', 'home', 'vehicle_positioned'),
            $this->row(107, 8, 'parked', 'waikiki_hotel', 'actual_return'),
            $this->row(108, 9, 'parked', 'home', 'vehicle_positioned'),
            $this->row(109, 10, 'parked', 'airport_hnl', 'vehicle_staged'),
            $this->row(110, null, 'unknown', 'unknown', null, 'Fleet-X'),
        ]);
        $snapshot = (new FleetSnapshotService($locations))->forCompany(1, new DateTimeImmutable('2026-09-09 12:00:00'));
        $buckets = array_column($snapshot['buckets'], null, 'code');

        $this->assertSame(10, $snapshot['total']);
        $this->assertSame(['2', '3'], array_column($buckets['rented']['vehicles'], 'label'));
        $this->assertSame(['5', '6', '7', '9'], array_column($buckets['home']['vehicles'], 'label'));
        $this->assertSame(['4', '10'], array_column($buckets['hnl']['vehicles'], 'label'));
        $this->assertSame(['8'], array_column($buckets['other']['vehicles'], 'label'));
        $this->assertSame(['Fleet-X'], array_column($buckets['unknown']['vehicles'], 'label'));
        $this->assertSame(10, array_sum(array_column($snapshot['buckets'], 'count')));
        $this->assertCount(10, array_unique(array_merge(...array_map(static fn (array $bucket): array => array_column($bucket['vehicles'], 'id'), $snapshot['buckets']))));
    }

    public function testBatchedLocationReadUsesExactlyTwoCompanyScopedRepositoryCalls(): void
    {
        $repository = $this->createMock(OperationalFactsRepository::class);
        $repository->expects($this->once())->method('activeFleetVehiclesForCompany')->with(7, '2026-09-09')->willReturn([
            ['id' => 1, 'company_id' => 7, 'fleet_number' => 1, 'fleet_code' => 'One', 'display_name' => 'One'],
            ['id' => 2, 'company_id' => 7, 'fleet_number' => 2, 'fleet_code' => 'Two', 'display_name' => 'Two'],
        ]);
        $repository->expects($this->once())->method('latestCurrentStateEventsForCompany')->with(7, [1, 2], '2026-09-09 12:00:00')->willReturn([
            1 => ['id' => 11, 'event_code' => 'vehicle_positioned', 'occurred_at' => '2026-09-09 10:00:00', 'location_class' => 'home'],
        ]);

        $rows = (new CurrentVehicleLocationService($repository))->forCompany(7, new DateTimeImmutable('2026-09-09 12:00:00'));

        $this->assertCount(2, $rows);
        $this->assertSame('home', $rows[0]['location_class']);
        $this->assertSame('unknown', $rows[1]['location_class']);
    }

    public function testNullFleetNumberFallbackOrderingIsDeterministic(): void
    {
        $locations = $this->createStub(CurrentVehicleLocationService::class);
        $locations->method('forCompany')->willReturn([
            $this->row(3, null, 'unknown', 'unknown', null, 'Zulu'),
            $this->row(2, null, 'unknown', 'unknown', null, 'Alpha'),
            $this->row(1, 12, 'unknown', 'unknown', null),
        ]);
        $unknown = array_column((new FleetSnapshotService($locations))->forCompany(1)['buckets'], null, 'code')['unknown'];

        $this->assertSame(['12', 'Alpha', 'Zulu'], array_column($unknown['vehicles'], 'label'));
    }

    public function testMissingOrAmbiguousCompanyContextFailsClosed(): void
    {
        $repository = $this->createMock(OperationalFactsRepository::class);
        $repository->expects($this->once())->method('activeFleetCompanyIds')->willReturn([1, 2]);
        $service = new FleetSnapshotService($this->createStub(CurrentVehicleLocationService::class), $repository);

        $this->expectException(RuntimeException::class);
        $service->forSingleFleetCompany(new DateTimeImmutable('2026-09-09 12:00:00'));
    }

    public function testInvalidExplicitCompanyContextIsRejectedBeforeReading(): void
    {
        $locations = $this->createMock(CurrentVehicleLocationService::class);
        $locations->expects($this->never())->method('forCompany');

        $this->expectException(InvalidArgumentException::class);
        (new FleetSnapshotService($locations))->forCompany(0);
    }

    /** @return array<string, mixed> */
    private function row(int $id, ?int $fleetNumber, string $state, string $location, ?string $eventCode, ?string $fleetCode = null): array
    {
        return [
            'id' => $id,
            'company_id' => 1,
            'fleet_number' => $fleetNumber,
            'fleet_code' => $fleetCode ?? 'Fleet-' . $id,
            'display_name' => 'Vehicle ' . $id,
            'operational_state' => $state,
            'location_class' => $location,
            'event_code' => $eventCode,
        ];
    }
}
