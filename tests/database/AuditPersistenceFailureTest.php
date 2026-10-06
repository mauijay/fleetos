<?php

use App\DTOs\Turo\NormalizedTripData;
use App\DTOs\Turo\RawTripRow;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Services\Fleet\ScheduledMovementLocationService;
use App\Services\Turo\TripMonthAllocationService;
use App\Services\Turo\TuroImportAuditService;
use App\Services\Turo\TuroTripImportService;
use App\Services\Turo\TuroTripNormalizer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal Shared audit contract and its importer exception boundary. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class AuditPersistenceFailureTest extends CIUnitTestCase
{
    public function testFailedAuditInsertPropagatesEvenWhenDatabaseDebugIsDisabled(): void
    {
        $db = Database::connect('tests', false);
        VehicleDamageDatabaseFixture::migrate($db);
        VehicleDamageDatabaseFixture::seed($db);
        $db->query('CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON ' . $db->prefixTable('audit_logs') . " BEGIN SELECT RAISE(FAIL, 'Synthetic audit failure'); END");
        (new ReflectionProperty($db, 'DBDebug'))->setValue($db, false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audit history could not be recorded.');
        (new AuditLogRepository($db))->record(null, (new LookupRepository($db))->valueId('audit_action', 'created'), 'fleet_extras', 1, null, ['name' => 'Synthetic extra']);
    }

    public function testTripRowAuditFailureRollsBackBusinessWritesAndClosesItsTransaction(): void
    {
        $db = Database::connect('tests', false);
        VehicleDamageDatabaseFixture::migrate($db);
        VehicleDamageDatabaseFixture::seed($db);
        $before = $db->table('fleet_vehicles')->where('id', 10)->get()->getRowArray();
        $beforeAudits = $db->table('audit_logs')->countAllResults();
        $db->query('CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON ' . $db->prefixTable('audit_logs') . " WHEN NEW.table_name = 'turo_trips_normalized' BEGIN SELECT RAISE(FAIL, 'Synthetic audit failure'); END");
        (new ReflectionProperty($db, 'DBDebug'))->setValue($db, false);
        $trip = (new ReflectionClass(NormalizedTripData::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($trip, 'fleetVehicleId'))->setValue($trip, null);
        $normalizer = $this->getMockBuilder(TuroTripNormalizer::class)->disableOriginalConstructor()->getMock();
        $normalizer->expects($this->once())->method('normalize')->willReturn($trip);
        $normalizer->expects($this->exactly(2))->method('scheduledLocationText')->willReturn(null);
        $trips = $this->getMockBuilder(TuroNormalizedTripRepository::class)->disableOriginalConstructor()->getMock();
        $trips->expects($this->once())->method('upsert')->willReturnCallback(static function () use ($db): array {
            $db->table('fleet_vehicles')->where('id', 10)->update(['license_plate' => 'SYNTHETIC-TRANSACTION']);
            return ['id' => 100, 'created' => true, 'new' => ['source' => 'synthetic'], 'old' => null, 'materially_changed' => false];
        });
        $locations = $this->getMockBuilder(ScheduledMovementLocationService::class)->disableOriginalConstructor()->getMock();
        $locations->expects($this->once())->method('retainForTrip')->willReturn(['material_changed' => false]);
        $allocationService = $this->getMockBuilder(TripMonthAllocationService::class)->disableOriginalConstructor()->getMock();
        $allocationService->expects($this->once())->method('allocate')->willReturn([]);
        $allocations = $this->getMockBuilder(TripMonthAllocationRepository::class)->disableOriginalConstructor()->getMock();
        $allocations->expects($this->once())->method('replaceForTrip')->with(100, []);
        $lookups = new LookupRepository($db);
        $service = new TuroTripImportService($db, normalizer: $normalizer, allocationService: $allocationService, lookups: $lookups, normalizedTrips: $trips, allocations: $allocations, audit: new TuroImportAuditService(new AuditLogRepository($db), $lookups), scheduledLocations: $locations);
        try {
            (new ReflectionMethod($service, 'persistNormalizedRow'))->invoke($service, new RawTripRow(1, [], 'SYNTHETIC-TRIP', null, str_repeat('a', 64)), 1, 7);
            $this->fail('Audit persistence failure must escape the importer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit history could not be recorded.', $exception->getMessage());
        }
        $this->assertSame(0, $db->transDepth);
        $this->assertSame($before, $db->table('fleet_vehicles')->where('id', 10)->get()->getRowArray());
        $this->assertSame($beforeAudits, $db->table('audit_logs')->countAllResults());
    }
}
