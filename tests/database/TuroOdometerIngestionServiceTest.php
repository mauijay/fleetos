<?php

use App\Database\Migrations\CreateVehicleHealthObservationFoundation;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleHealthObservationRepository;
use App\Services\Fleet\CurrentVehicleOdometerResolver;
use App\Services\Fleet\VehicleHealthObservationService;
use App\Services\Turo\TuroOdometerIngestionService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-24-000026_CreateVehicleHealthObservationFoundation.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class TuroOdometerIngestionServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleHealthObservationRepository $repository;
    private CurrentVehicleOdometerResolver $resolver;
    private VehicleHealthObservationService $observations;
    private TuroOdometerIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->dropTables();
        $this->createPrerequisites();
        (new CreateVehicleHealthObservationFoundation(Database::forge($this->connection)))->up();
        $this->seed();
        $this->repository = new VehicleHealthObservationRepository($this->connection);
        $this->resolver = new CurrentVehicleOdometerResolver($this->repository);
        $this->observations = new VehicleHealthObservationService(
            $this->connection,
            $this->repository,
            $this->resolver,
            new AuditLogRepository($this->connection),
            new LookupRepository($this->connection),
        );
        $this->service = new TuroOdometerIngestionService($this->connection, $this->observations, $this->repository);
    }

    public function testPickupAndReturnBecomeDistinctImmutableObservations(): void
    {
        [$tripId, $rawId] = $this->trip('trip-1', [
            'check_in_odometer' => '12000',
            'check_out_odometer' => '12200',
            'distance_traveled' => '200',
        ]);

        $result = $this->service->ingest($tripId, $rawId, 7, $this->now());
        $history = $this->repository->history(1, 10, 'odometer');

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['issues']);
        $this->assertCount(2, $history);
        $this->assertSame([12200, 12000], array_map(static fn (array $row): int => (int) $row['odometer_miles'], $history));
        $this->assertSame('2026-09-23 18:00:00', $history[0]['observed_at']);
        $this->assertSame('2026-09-23 09:00:00', $history[1]['observed_at']);
        $this->assertStringContainsString(':return:', (string) $history[0]['source_external_id']);
        $this->assertStringContainsString(':pickup:', (string) $history[1]['source_external_id']);
        $this->assertStringContainsString('scheduled trip_end', (string) $history[0]['note']);
        $this->assertSame(12200, $this->resolver->resolve(1, 10, new DateTimeImmutable('2026-09-23 19:00:00'))['odometer_miles']);
        $this->assertSame(12000, $this->resolver->resolve(1, 10, new DateTimeImmutable('2026-09-23 12:00:00'))['odometer_miles']);
        $this->assertSame(11000, (int) $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRow('odometer_miles'));
    }

    public function testIdenticalReplayIsIdempotent(): void
    {
        [$tripId, $rawId] = $this->trip('trip-2', ['check_in_odometer' => '12000', 'check_out_odometer' => '12200']);

        $first = $this->service->ingest($tripId, $rawId, null, $this->now());
        $again = $this->service->ingest($tripId, $rawId, null, $this->now());

        $this->assertSame(2, $first['created']);
        $this->assertSame(2, $again['existing']);
        $this->assertSame(2, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testChangedSourceReadingSupersedesAndReplayRemainsIdempotent(): void
    {
        [$tripId, $rawId] = $this->trip('trip-3', ['check_in_odometer' => '12000']);
        $first = $this->service->ingest($tripId, $rawId, null, $this->now());
        $firstId = (int) $this->repository->history(1, 10, 'odometer')[0]['id'];

        $replacementRawId = $this->replaceSource($tripId, 'trip-3', ['check_in_odometer' => '12100']);
        $corrected = $this->service->ingest($tripId, $replacementRawId, null, $this->now());
        $again = $this->service->ingest($tripId, $replacementRawId, null, $this->now());
        $history = $this->repository->history(1, 10, 'odometer');

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $corrected['corrected']);
        $this->assertSame(1, $again['existing']);
        $this->assertCount(2, $history);
        $this->assertSame($firstId, (int) $history[0]['supersedes_observation_id']);
        $this->assertSame(12100, $this->resolver->resolve(1, 10, $this->now())['odometer_miles']);
    }

    public function testReturnImportedBeforePickupStillResolvesByObservedTime(): void
    {
        [$tripId, $rawId] = $this->trip('trip-4', ['check_out_odometer' => '12200']);
        $return = $this->service->ingest($tripId, $rawId, null, $this->now());

        $replacementRawId = $this->replaceSource($tripId, 'trip-4', [
            'check_in_odometer' => '12000',
            'check_out_odometer' => '12200',
        ]);
        $pickup = $this->service->ingest($tripId, $replacementRawId, null, $this->now());

        $this->assertSame(1, $return['created']);
        $this->assertSame(1, $pickup['created']);
        $this->assertSame(1, $pickup['existing']);
        $this->assertSame(12000, $this->resolver->resolve(1, 10, new DateTimeImmutable('2026-09-23 12:00:00'))['odometer_miles']);
        $this->assertSame(12200, $this->resolver->resolve(1, 10, new DateTimeImmutable('2026-09-23 19:00:00'))['odometer_miles']);
    }

    public function testMissingReadingsAndTripDistanceDoNotCreateObservations(): void
    {
        [$tripId, $rawId] = $this->trip('trip-5', ['distance_traveled' => '200']);

        $result = $this->service->ingest($tripId, $rawId, null, $this->now());

        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testMalformedNegativeAndMissingTimestampReadingsAreRejected(): void
    {
        [$tripId, $rawId] = $this->trip('trip-6', ['check_in_odometer' => '12.5', 'check_out_odometer' => '-1']);
        $invalid = $this->service->ingest($tripId, $rawId, null, $this->now());

        $replacementRawId = $this->replaceSource($tripId, 'trip-6', ['check_in_odometer' => '12000', 'trip_start' => null]);
        $missingTime = $this->service->ingest($tripId, $replacementRawId, null, $this->now());

        $this->assertSame(['turo_pickup_odometer_invalid', 'turo_return_odometer_invalid'], array_map(static fn ($issue): string => $issue->code, $invalid['issues']));
        $this->assertSame('turo_pickup_odometer_time_missing', $missingTime['issues'][0]->code);
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testWrongTripVehicleMappingAndRawRowAreRejected(): void
    {
        [$tripId, $rawId] = $this->trip('trip-7', ['check_in_odometer' => '12000']);
        $this->connection->table('vehicle_turo_listings')->where('turo_vehicle_id', 'turo-10')->update(['fleet_vehicle_id' => 20]);
        $wrongVehicle = $this->service->ingest($tripId, $rawId, null, $this->now());

        $this->connection->table('vehicle_turo_listings')->where('turo_vehicle_id', 'turo-10')->update(['fleet_vehicle_id' => 10]);
        $wrongRaw = $this->service->ingest($tripId, $rawId + 999, null, $this->now());
        $this->connection->table('turo_trip_raw')->where('id', $rawId)->update(['external_trip_id' => 'another-trip']);
        $wrongTrip = $this->service->ingest($tripId, $rawId, null, $this->now());

        $this->assertSame('turo_odometer_vehicle_mismatch', $wrongVehicle['issues'][0]->code);
        $this->assertSame('turo_odometer_source_mismatch', $wrongRaw['issues'][0]->code);
        $this->assertSame('turo_odometer_trip_mismatch', $wrongTrip['issues'][0]->code);
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testCanceledTripReadingIsNotAuthoritative(): void
    {
        [$tripId, $rawId] = $this->trip('trip-8', ['check_in_odometer' => '12000'], statusId: 11);

        $result = $this->service->ingest($tripId, $rawId, null, $this->now());

        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testFutureImportedReadingAndManualReadingUseExistingAuthorityRules(): void
    {
        [$tripId, $rawId] = $this->trip('trip-9', [
            'trip_start' => '2026-09-24 09:00 AM',
            'trip_end' => '2026-09-24 06:00 PM',
            'check_in_odometer' => '12000',
        ], startsAt: '2026-09-24 09:00:00', endsAt: '2026-09-24 18:00:00');
        $this->service->ingest($tripId, $rawId, null, $this->now());

        $manual = $this->observations->recordOdometer(1, 10, [
            'odometer_miles' => '11900',
            'observed_at' => '2026-09-23 09:30:00',
        ], 7, now: $this->now());

        $this->assertTrue($manual['success']);
        $this->assertSame(11900, $this->resolver->resolve(1, 10, $this->now())['odometer_miles']);
        $this->assertSame(12000, $this->resolver->resolve(1, 10, new DateTimeImmutable('2026-09-24 10:00:00'))['odometer_miles']);
    }

    public function testVoidedOrManuallyReplacedImportRequiresReviewInsteadOfReactivation(): void
    {
        [$tripId, $rawId] = $this->trip('trip-10', ['check_in_odometer' => '12000']);
        $this->service->ingest($tripId, $rawId, null, $this->now());
        $observationId = (int) $this->repository->history(1, 10, 'odometer')[0]['id'];
        $this->observations->void(1, 10, $observationId, 'Source needs operator review', 7, $this->now());

        $replacementRawId = $this->replaceSource($tripId, 'trip-10', ['check_in_odometer' => '12100']);
        $result = $this->service->ingest($tripId, $replacementRawId, null, $this->now());

        $this->assertSame('turo_pickup_odometer_conflict', $result['issues'][0]->code);
        $this->assertSame(1, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    /** @return array{0:int,1:int} */
    private function trip(
        string $turoTripId,
        array $payloadOverrides = [],
        int $vehicleId = 10,
        int $statusId = 10,
        string $startsAt = '2026-09-23 09:00:00',
        string $endsAt = '2026-09-23 18:00:00',
    ): array {
        $payload = array_merge([
            'reservation_id' => $turoTripId,
            'vehicle_id' => 'turo-10',
            'trip_start' => '2026-09-23 09:00 AM',
            'trip_end' => '2026-09-23 06:00 PM',
            'trip_status' => 'Completed',
            'check_in_odometer' => null,
            'check_out_odometer' => null,
            'distance_traveled' => null,
        ], $payloadOverrides);
        $this->connection->table('turo_trip_raw')->insert([
            'external_trip_id' => $turoTripId,
            'external_vehicle_id' => (string) $payload['vehicle_id'],
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $rawId = (int) $this->connection->insertID();
        $this->connection->table('turo_trips_normalized')->insert([
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_raw_id' => $rawId,
            'trip_status_lookup_value_id' => $statusId,
            'turo_trip_id' => $turoTripId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'deleted_at' => null,
        ]);

        return [(int) $this->connection->insertID(), $rawId];
    }

    private function replaceSource(int $tripId, string $turoTripId, array $payloadOverrides): int
    {
        $payload = array_merge([
            'reservation_id' => $turoTripId,
            'vehicle_id' => 'turo-10',
            'trip_start' => '2026-09-23 09:00 AM',
            'trip_end' => '2026-09-23 06:00 PM',
            'trip_status' => 'Completed',
            'check_in_odometer' => null,
            'check_out_odometer' => null,
            'distance_traveled' => null,
        ], $payloadOverrides);
        $this->connection->table('turo_trip_raw')->insert([
            'external_trip_id' => $turoTripId,
            'external_vehicle_id' => (string) $payload['vehicle_id'],
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $rawId = (int) $this->connection->insertID();
        $this->connection->table('turo_trips_normalized')->where('id', $tripId)->update(['turo_trip_raw_id' => $rawId]);

        return $rawId;
    }

    private function dropTables(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach ([
            'vehicle_health_policies', 'vehicle_odometer_observations', 'vehicle_tire_pressure_observations', 'vehicle_health_observations',
            'turo_trips_normalized', 'turo_trip_raw', 'vehicle_turo_listings', 'audit_logs', 'lookup_values', 'lookup_types',
            'fleet_vehicles', 'vehicle_statuses', 'companies',
        ] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_statuses') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80), name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, vehicle_status_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), odometer_miles INTEGER NULL, in_service_date DATE NULL, out_of_service_date DATE NULL, deleted_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, lookup_type_id INTEGER, code VARCHAR(80), is_active BOOLEAN)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER NULL, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_vehicle_id VARCHAR(80), is_active BOOLEAN)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trip_raw') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, external_trip_id VARCHAR(80), external_vehicle_id VARCHAR(80), raw_payload TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NULL, turo_trip_raw_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, turo_trip_id VARCHAR(80), starts_at DATETIME, ends_at DATETIME, deleted_at DATETIME NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('vehicle_statuses')->insert(['id' => 1, 'code' => 'active', 'name' => 'Active']);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'vehicle_status_id' => 1, 'fleet_number' => 10, 'fleet_code' => 'Vehicle10', 'display_name' => 'Vehicle 10', 'odometer_miles' => 11000],
            ['id' => 20, 'company_id' => 2, 'vehicle_status_id' => 1, 'fleet_number' => 20, 'fleet_code' => 'Vehicle20', 'display_name' => 'Vehicle 20', 'odometer_miles' => 9000],
        ]);
        $this->connection->table('vehicle_turo_listings')->insertBatch([
            ['fleet_vehicle_id' => 10, 'turo_vehicle_id' => 'turo-10', 'is_active' => 1],
            ['fleet_vehicle_id' => 20, 'turo_vehicle_id' => 'turo-20', 'is_active' => 1],
        ]);
        $this->connection->table('lookup_types')->insertBatch([
            ['id' => 1, 'code' => 'audit_action'],
            ['id' => 2, 'code' => 'trip_status'],
        ]);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'lookup_type_id' => 1, 'code' => 'created', 'is_active' => 1],
            ['id' => 2, 'lookup_type_id' => 1, 'code' => 'updated', 'is_active' => 1],
            ['id' => 10, 'lookup_type_id' => 2, 'code' => 'completed', 'is_active' => 1],
            ['id' => 11, 'lookup_type_id' => 2, 'code' => 'canceled_zero_payout', 'is_active' => 1],
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-23 20:00:00', new DateTimeZone('Pacific/Honolulu'));
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
