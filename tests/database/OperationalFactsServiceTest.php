<?php

use App\Database\Migrations\CreateMovementOperationalFacts;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\VehicleRecoveryExceptionRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\FleetSnapshotService;
use App\Services\Fleet\ImportFreshnessService;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementBoardIntelligenceService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactPresentationService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\MovementStateResolver;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\OperationalMovementWorkService;
use App\Services\Fleet\ScheduledLocationBackfillService;
use App\Services\Fleet\VehiclePositioningPlanService;
use App\Services\Fleet\VehiclePositioningRecommendationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-31-000015_CreateMovementOperationalFacts.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class OperationalFactsServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private OperationalFactsRepository $repository;
    private MovementEventService $events;
    private MovementAssessmentService $assessments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['operational_fact_audits', 'vehicle_positioning_plans', 'vehicle_operational_capabilities', 'vehicle_operational_profiles', 'movement_assessments', 'trip_movement_events', 'scheduled_movement_locations', 'airport_movement_workflows', 'airports', 'turo_trips_normalized', 'turo_trip_raw', 'turo_import_batches', 'lookup_values', 'fleet_vehicles', 'users'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80) NULL, display_name VARCHAR(190) NULL, in_service_date DATE NULL, out_of_service_date DATE NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('users') . ' (id INTEGER PRIMARY KEY, username VARCHAR(30) NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY, import_status_lookup_value_id INTEGER NULL, source_filename VARCHAR(190) NULL, completed_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trip_raw') . ' (id INTEGER PRIMARY KEY, turo_import_batch_id INTEGER NULL, raw_payload TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('airports') . ' (id INTEGER PRIMARY KEY, code VARCHAR(20))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, turo_trip_raw_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, guest_name VARCHAR(190) NULL, starts_at DATETIME NULL, ends_at DATETIME NULL, canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        (new CreateMovementOperationalFacts(Database::forge($this->connection)))->up();
        $this->connection->query('ALTER TABLE ' . $this->table('vehicle_operational_profiles') . ' ADD COLUMN ready_energy_min_percent INTEGER NULL');
        $this->connection->query('ALTER TABLE ' . $this->table('vehicle_operational_profiles') . ' ADD COLUMN ready_energy_preferred_max_percent INTEGER NULL');
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'deleted_at' => null],
            ['id' => 20, 'company_id' => 2, 'deleted_at' => null],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 100, 'fleet_vehicle_id' => 10, 'deleted_at' => null],
            ['id' => 200, 'fleet_vehicle_id' => 20, 'deleted_at' => null],
        ]);
        $this->connection->table('users')->insertBatch([['id' => 7, 'username' => 'operator'], ['id' => 8, 'username' => 'reviewer']]);
        $this->repository = new OperationalFactsRepository($this->connection);
        $this->events = new MovementEventService($this->repository);
        $this->assessments = new MovementAssessmentService($this->repository);
    }

    public function testRecoverVehicleDerivesIndependentCleaningAndEnergyWorkWithoutDirtyFact(): void
    {
        $at = new DateTimeImmutable('+1 second');
        foreach (['airport_garage_code VARCHAR(40)', 'airport_parking_level INTEGER', 'airport_parking_row VARCHAR(4)'] as $column) {
            $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN ' . $column . ' NULL');
        }
        $this->connection->table('vehicle_operational_profiles')->insert([
            'fleet_vehicle_id' => 10, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 80,
            'created_by' => 7, 'updated_by' => 7,
        ]);
        $priorReadiness = $this->events->record(10, null, 'vehicle_readiness_observed', null, '2026-09-16 08:00:00', null, null, 'vehicle_operator', 7);
        $this->assessments->record(10, null, $priorReadiness, 'current', 'clean', 90, '2026-09-16 08:00:00', 'vehicle_operator', 7);
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-16 09:00:00', 'airport_hnl', null, 'operator', 7, null, ['garage_code' => 'international', 'level' => 7, 'row' => 'F']);
        $reportId = $this->events->record(10, 100, 'guest_return_staged', 'return', '2026-09-16 10:00:00', 'airport_hnl', null, 'guest_report_received', 7, null, ['garage_code' => 'international', 'level' => 7, 'row' => 'F']);
        $work = new OperationalMovementWorkService($this->repository);
        $this->assertSame([], $work->cleaningNeedsForCompany(1, $at));
        $this->assertSame([], $work->energyNeedsForCompany(1, $at));

        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];
        $recoveryId = $service->recoverVehicle($checklist, [
            'occurred_at' => '2026-09-16 11:00:00', 'location_class' => 'airport_hnl',
            'airport_garage_code' => 'international', 'airport_parking_level' => '7', 'airport_parking_row' => 'G',
            'recovery_location_note' => 'Near elevators', 'energy_percent' => '54', 'confirm_recovery_location' => '1',
        ], 7);
        $this->assertSame('vehicle_recovered', $this->repository->event($recoveryId)['event_code']);
        $this->assertSame('F', $this->repository->event($reportId)['airport_parking_row']);
        $this->assertSame('G', $this->repository->event($recoveryId)['airport_parking_row']);
        $currentLocation = (new CurrentVehicleLocationService($this->repository))->resolve(10, $at);
        $this->assertSame('parked', $currentLocation['operational_state']);
        $this->assertSame('Near elevators', $currentLocation['location_note']);
        $this->assertCount(1, $work->cleaningNeedsForCompany(1, $at));
        $this->assertSame('charge_required', $work->energyNeedsForCompany(1, $at)[0]['condition_code']);
        $this->assertSame(54, $work->energyNeedsForCompany(1, $at)[0]['energy_percent']);
        $this->assertSame(0, $this->connection->table('movement_assessments')->where('cleanliness', 'dirty')->countAllResults());
        try {
            $service->recoverVehicle($checklist, ['occurred_at' => '2026-09-16 11:01:00', 'location_class' => 'home', 'energy_percent' => '54', 'confirm_recovery_location' => '1'], 7);
            $this->fail('Duplicate recovery must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already complete', $exception->getMessage());
        }

        $service->recordCurrentReadinessForVehicle(1, 10, ['occurred_at' => '2026-09-16 12:00:00', 'cleanliness' => 'clean'], 7);
        $cleanObservation = $this->repository->latestCurrentReadinessAssessment(1, 10);
        $this->assertSame('clean', $cleanObservation['cleanliness']);
        $this->assertNull($cleanObservation['energy_percent']);
        $this->assertSame('vehicle_readiness_observed', $this->repository->event((int) $cleanObservation['trip_movement_event_id'])['event_code']);
        $this->assertSame([], $work->cleaningNeedsForCompany(1, $at));
        $this->assertSame('charge_required', $work->energyNeedsForCompany(1, $at)[0]['condition_code']);
        $this->assertSame(54, $work->energyNeedsForCompany(1, $at)[0]['energy_percent']);
        $service->recordCurrentReadinessForVehicle(1, 10, ['occurred_at' => '2026-09-16 13:00:00', 'energy_percent' => '82'], 7);
        $this->assertSame([], $work->cleaningNeedsForCompany(1, $at));
        $this->assertSame([], $work->energyNeedsForCompany(1, $at));
    }

    public function testUnknownRecoveryEnergyRequiresReasonAndNeverBecomesZero(): void
    {
        $this->connection->table('vehicle_operational_profiles')->insert([
            'fleet_vehicle_id' => 10, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 80,
            'created_by' => 7, 'updated_by' => 7,
        ]);
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-16 09:00:00', 'home', null, 'operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];
        $data = ['occurred_at' => '2026-09-16 11:00:00', 'location_class' => 'home', 'energy_unknown' => '1', 'confirm_recovery_location' => '1'];
        try {
            $service->recoverVehicle($checklist, $data, 7);
            $this->fail('Unknown energy without a reason must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Explain why', $exception->getMessage());
        }
        $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));
        $data['energy_unknown_reason'] = 'Display unavailable during recovery';
        $recoveryId = $service->recoverVehicle($checklist, $data, 7);
        $assessment = $this->repository->assessmentForEventOrTrip($recoveryId, 100);
        $this->assertNull($assessment['energy_percent']);
        $this->assertStringContainsString('Display unavailable', (string) $assessment['note']);
        $needs = (new OperationalMovementWorkService($this->repository))->energyNeedsForCompany(1, new DateTimeImmutable());
        $this->assertSame('measurement_needed', $needs[0]['condition_code']);
        $this->assertNull($needs[0]['energy_percent']);
        $this->assertTrue($service->voidRecoveredVehicle($checklist, $recoveryId, 8, 'Recovery was entered on the wrong trip.'));
        $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));
        $this->assertNotNull($this->assessments->find((int) $assessment['id'])['voided_at']);
        $this->assertSame([], (new OperationalMovementWorkService($this->repository))->energyNeedsForCompany(1, new DateTimeImmutable()));
    }

    public function testAbovePreferredRangeIsReadyAndCreatesNoOperationalEnergyWork(): void
    {
        $this->connection->table('vehicle_operational_profiles')->insert([
            'fleet_vehicle_id' => 10,
            'energy_kind' => 'electric',
            'ready_energy_target_percent' => 70,
            'ready_energy_min_percent' => 70,
            'ready_energy_preferred_max_percent' => 80,
            'created_by' => 7,
            'updated_by' => 7,
        ]);
        $recoveryId = $this->events->record(10, 100, 'vehicle_recovered', 'return', '2026-09-21 09:00:00', 'home', null, 'vehicle_operator', 7);
        $this->assessments->record(10, 100, $recoveryId, 'return', 'dirty', 85, '2026-09-21 09:00:00', 'vehicle_operator', 7);

        $work = new OperationalMovementWorkService($this->repository);
        $this->assertSame([], $work->energyNeedsForCompany(1, new DateTimeImmutable('+1 second')));
        $this->assertSame(85, (int) $this->repository->assessmentForEventOrTrip($recoveryId, 100)['energy_percent']);
    }

    public function testNonStagedRecoveryStoresIndependentOptionalExceptionsAtomically(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_recovery_exceptions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, trip_movement_event_id INTEGER, exception_code VARCHAR(40), note TEXT NULL, status VARCHAR(20), created_by INTEGER, created_at DATETIME, resolved_by INTEGER NULL, resolved_at DATETIME NULL, resolution_note TEXT NULL, UNIQUE (trip_movement_event_id, exception_code))');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(20))');
        $this->connection->table('trip_movement_checklists')->insert(['id' => 901, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10, 'movement_type' => 'return']);
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-16 09:00:00', 'home', null, 'operator', 7);
        $repo = new VehicleRecoveryExceptionRepository($this->connection);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments, recoveryExceptions: $repo);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];
        $data = [
            'occurred_at' => '2026-09-16 11:00:00', 'location_class' => 'home',
            'energy_percent' => '54', 'confirm_recovery_location' => '1',
            'exception_codes' => ['damage', 'missing_key', 'missing_charge_adapter', 'not_drivable', 'other'],
            'exception_notes' => ['damage' => 'Synthetic scratch', 'other' => 'Synthetic other issue'],
        ];

        foreach ([['damage', 'damage'], ['other'], ['other', 'other']] as $invalidCodes) {
            $invalid = $data;
            $invalid['exception_codes'] = $invalidCodes;
            $invalid['exception_notes'] = [];
            try {
                $service->recoverVehicle($checklist, $invalid, 7);
                $this->fail('Invalid recovery exceptions must be rejected before recording recovery.');
            } catch (InvalidArgumentException) {
                $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));
            }
        }

        $recoveryId = $service->recoverVehicle($checklist, $data, 7);
        $this->assertSame('vehicle_recovered', $this->repository->event($recoveryId)['event_code']);
        $this->assertCount(5, $repo->openForCompany(1));
        $this->assertSame(['damage', 'missing_key', 'missing_charge_adapter', 'not_drivable', 'other'], array_column($repo->forTrip(1, 100), 'exception_code'));
        $this->assertSame([], $repo->openForCompany(2));
        $this->assertFalse($repo->resolveForCompany(2, 100, 10, 1, 8, null));
        $this->assertTrue($repo->resolveForCompany(1, 100, 10, 1, 8, 'Reviewed'));
        $this->assertCount(4, $repo->openForCompany(1));
        $this->assertSame('resolved', $repo->forTrip(1, 100)[0]['status']);
    }

    public function testRecoveryRejectsWrongCompanyAndRollsBackWhenAssessmentFails(): void
    {
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-16 09:00:00', 'home', null, 'operator', 7);
        $data = ['occurred_at' => '2026-09-16 11:00:00', 'location_class' => 'home', 'energy_percent' => '54', 'confirm_recovery_location' => '1'];
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 2, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];
        try {
            $service->recoverVehicle($checklist, $data, 7);
            $this->fail('Cross-company recovery must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active fleet company', $exception->getMessage());
        }
        $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));

        $checklist['company_id'] = 1;
        foreach ([
            ['location_class' => 'airport_hnl', 'airport_garage_code' => 'international', 'airport_parking_level' => '7', 'airport_parking_row' => 'A'],
            ['location_class' => ''],
            ['location_class' => 'unknown'],
            ['location_class' => 'unmapped_location'],
            ['energy_percent' => '101'],
            ['confirm_recovery_location' => ''],
        ] as $invalid) {
            try {
                $service->recoverVehicle($checklist, array_merge($data, $invalid), 7);
                $this->fail('Invalid recovery input must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));
            }
        }
        $failingAssessments = $this->createMock(MovementAssessmentService::class);
        $failingAssessments->method('record')->willThrowException(new RuntimeException('Assessment write failed.'));
        $service = new MovementOperationalFactService($this->connection, $this->events, $failingAssessments);
        try {
            $service->recoverVehicle($checklist, $data, 7);
            $this->fail('Recovery must roll back when the assessment write fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment write failed.', $exception->getMessage());
        }
        $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));
    }

    public function testRecoveryAcceptsExistingWaikikiLocationVocabulary(): void
    {
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-16 09:00:00', 'home', null, 'operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];

        $recoveryId = $service->recoverVehicle($checklist, [
            'occurred_at' => '2026-09-16 11:00:00',
            'location_class' => 'waikiki_hotel',
            'location_detail' => 'Hotel porte cochere',
            'energy_percent' => '82',
            'confirm_recovery_location' => '1',
        ], 7);
        $recovery = $this->repository->event($recoveryId);

        $this->assertSame('vehicle_recovered', $recovery['event_code']);
        $this->assertSame('waikiki_hotel', $recovery['location_class']);
        $this->assertSame('Hotel porte cochere', $recovery['location_detail']);
    }

    public function testCurrentLocationUsesLatestNonVoidedEventAtOrBeforeAsOf(): void
    {
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-01 10:00:00', 'home', 'Yard', 'operator', 7);
        $this->events->record(10, 100, 'vehicle_positioned', 'pickup', '2026-09-01 15:00:00', 'airport_hnl', 'Terminal 2', 'operator', 7);

        $location = (new CurrentVehicleLocationService($this->repository))->resolve(10, new DateTimeImmutable('2026-09-01 12:00:00'));

        $this->assertSame('home', $location['location_class']);
        $this->assertSame('Yard', $location['location_detail']);
        $this->assertSame(7200, $location['age_seconds']);
    }

    public function testCurrentPositionChronologyPreservesReturnLocationAndRentedSemantics(): void
    {
        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 09:00:00', 'waikiki_hotel', 'Romer House', 'operator', 7);
        $this->connection->table('scheduled_movement_locations')->insert([
            'turo_trip_normalized_id' => 100,
            'fleet_vehicle_id' => 10,
            'movement_type' => 'pickup',
            'location_class' => 'airport_hnl',
            'source_text' => 'HNL future pickup',
        ]);
        $resolver = new CurrentVehicleLocationService($this->repository);

        $atReturn = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 09:30:00'));
        $this->assertSame('waikiki_hotel', $atReturn['location_class']);
        $this->assertSame('Romer House', $atReturn['location_detail']);
        $this->assertSame('parked', $atReturn['operational_state']);

        $positionedId = $this->events->record(10, 100, 'vehicle_positioned', null, '2026-09-01 10:00:00', 'home', null, 'operator', 7);
        $atHome = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 10:30:00'));
        $this->assertSame('home', $atHome['location_class']);
        $this->assertSame($positionedId, $atHome['event_id']);
        $this->assertSame('waikiki_hotel', $this->repository->event($returnId)['location_class']);
        $this->assertSame('Romer House', $this->repository->event($returnId)['location_detail']);

        $this->assertTrue($this->events->void($positionedId, 8, 'Position was recorded in error.'));
        $afterVoid = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 10:30:00'));
        $this->assertSame('waikiki_hotel', $afterVoid['location_class']);

        $this->events->record(10, 100, 'vehicle_staged', 'pickup', '2026-09-01 11:00:00', 'airport_hnl', 'International Garage L2 RG', 'operator', 7);
        $atHnl = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 11:30:00'));
        $this->assertSame('airport_hnl', $atHnl['location_class']);
        $this->assertSame('current', $atHnl['position_semantics']);
        $this->assertSame('parked', $atHnl['operational_state']);

        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-01 12:00:00', 'airport_hnl', 'International Garage L2 RG', 'operator', 7);
        $rented = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 12:30:00'));
        $this->assertSame('rented', $rented['operational_state']);
        $this->assertSame('rented', $rented['position_semantics']);
        $this->assertSame('Handoff location', $rented['location_label']);

        $this->events->record(10, 100, 'vehicle_staged', 'pickup', '2026-09-02 12:00:00', 'airport_hnl', 'Future staging', 'operator', 7);
        $beforeFutureEvent = $resolver->resolve(10, new DateTimeImmutable('2026-09-01 13:00:00'));
        $this->assertSame('actual_handoff', $beforeFutureEvent['event_code']);
    }

    public function testFleetSnapshotPossessionUsesLatestActiveAuthoritativeFact(): void
    {
        $snapshot = new FleetSnapshotService(new CurrentVehicleLocationService($this->repository), $this->repository);
        $bucket = static function (array $result): string {
            foreach ($result['buckets'] as $candidate) {
                if ($candidate['count'] === 1) {
                    return $candidate['code'];
                }
            }

            return 'missing';
        };
        $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-09-01 ' . $time);

        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-01 10:00:00', 'airport_hnl', 'Terminal 2', 'operator', 7);
        $this->assertSame('rented', $bucket($snapshot->forCompany(1, $at('10:30:00'))));

        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 11:00:00', null, null, 'operator', 7);
        $afterReturn = $snapshot->forCompany(1, $at('11:30:00'));
        $this->assertSame('unknown', $bucket($afterReturn));
        $this->assertSame('parked', (new CurrentVehicleLocationService($this->repository))->resolve(10, $at('11:30:00'))['operational_state']);
        $this->assertSame(1, array_sum(array_column($afterReturn['buckets'], 'count')));

        $this->assertTrue($this->events->void($returnId, 8, 'Return was recorded for the wrong vehicle.'));
        $this->assertSame('rented', $bucket($snapshot->forCompany(1, $at('11:30:00'))));

        $recoveryId = $this->events->record(10, 100, 'vehicle_recovered', 'return', '2026-09-01 12:00:00', null, null, 'operator', 7);
        $this->assertSame('unknown', $bucket($snapshot->forCompany(1, $at('12:30:00'))));
        $this->assertSame('parked', (new CurrentVehicleLocationService($this->repository))->resolve(10, $at('12:30:00'))['operational_state']);

        $this->events->correct($recoveryId, ['occurred_at' => '2026-09-01 09:00:00'], 8, 'Corrected recovery chronology.');
        $this->assertSame('rented', $bucket($snapshot->forCompany(1, $at('12:30:00'))));

        $this->events->record(10, 100, 'vehicle_positioned', null, '2026-09-01 13:00:00', 'home', null, 'operator', 7);
        $positioned = $snapshot->forCompany(1, $at('13:30:00'));
        $this->assertSame('rented', $bucket($positioned));
        $this->assertSame('actual_handoff', (new CurrentVehicleLocationService($this->repository))->resolve(10, $at('13:30:00'))['event_code']);
        $this->assertSame(1, array_sum(array_column($positioned['buckets'], 'count')));
    }

    public function testLaterTripHandoffSurvivesBadLateRecoveryBackfillForEarlierTrip(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 31, 'code' => 'completed'],
            ['id' => 32, 'code' => 'in_progress'],
            ['id' => 33, 'code' => 'booked'],
            ['id' => 34, 'code' => 'canceled_zero_payout'],
        ]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'trip_status_lookup_value_id' => 31,
            'guest_name' => 'Prior Guest',
            'starts_at' => '2026-09-14 12:00:00',
            'ends_at' => '2026-09-22 21:00:00',
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            [
                'id' => 101,
                'fleet_vehicle_id' => 10,
                'trip_status_lookup_value_id' => 32,
                'guest_name' => 'Current Guest',
                'starts_at' => '2026-09-23 08:30:00',
                'ends_at' => '2026-09-24 22:30:00',
                'deleted_at' => null,
            ],
            [
                'id' => 102,
                'fleet_vehicle_id' => 10,
                'trip_status_lookup_value_id' => 33,
                'guest_name' => 'Next Guest',
                'starts_at' => '2026-09-30 13:00:00',
                'ends_at' => '2026-10-05 20:30:00',
                'deleted_at' => null,
            ],
            [
                'id' => 103,
                'fleet_vehicle_id' => 10,
                'trip_status_lookup_value_id' => 34,
                'guest_name' => 'Canceled Guest',
                'starts_at' => '2026-09-23 12:00:00',
                'ends_at' => '2026-09-25 16:00:00',
                'deleted_at' => null,
            ],
        ]);

        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-14 10:05:00', 'airport_hnl', null, 'checklist_operator', 7);
        $this->events->record(10, 100, 'guest_return_staged', 'return', '2026-09-22 20:30:00', 'airport_hnl', null, 'guest_reported_parked_time', 7);
        $currentHandoff = $this->events->record(10, 101, 'actual_handoff', 'pickup', '2026-09-23 08:30:00', 'home', 'Loading zone', 'checklist_operator', 7);
        $badPriorRecovery = $this->events->record(10, 100, 'vehicle_recovered', 'return', '2026-09-23 12:42:00', 'airport_hnl', null, 'checklist_operator', 7);
        $this->events->record(10, 103, 'actual_handoff', 'pickup', '2026-09-23 07:00:00', 'other_delivery', null, 'checklist_operator', 7);
        $asOf = new DateTimeImmutable('2026-09-23 13:00:00');

        $this->assertSame($badPriorRecovery, (int) $this->repository->latestActiveLifecycleEvent(10, $asOf->format('Y-m-d H:i:s'))['id']);
        $custody = (new CurrentVehicleCustodyService($this->repository))->resolve(10, $asOf);
        $this->assertSame($currentHandoff, (int) $custody['basis_event_id'], json_encode($custody, JSON_THROW_ON_ERROR));
        $location = (new CurrentVehicleLocationService($this->repository))->resolve(10, $asOf);
        $this->assertSame($currentHandoff, (int) $location['event_id']);
        $this->assertSame('rented', $location['operational_state']);
        $this->assertSame('home', $location['location_class']);
        $snapshot = (new FleetSnapshotService(new CurrentVehicleLocationService($this->repository), $this->repository))->forCompany(1, $asOf);
        $this->assertSame(1, array_column($snapshot['buckets'], 'count', 'code')['rented']);
        $this->assertSame(102, (int) (new NextConfirmedTripService($this->repository))->forVehicle(10, $asOf)['id']);
    }

    public function testPriorTripRecoveryBackfillRejectsTimeAfterLaterHandoffAndAcceptsActualEarlierTime(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 31, 'code' => 'completed'],
            ['id' => 32, 'code' => 'in_progress'],
        ]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'trip_status_lookup_value_id' => 31,
            'starts_at' => '2026-09-14 12:00:00',
            'ends_at' => '2026-09-22 21:00:00',
        ]);
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 101,
            'fleet_vehicle_id' => 10,
            'trip_status_lookup_value_id' => 32,
            'starts_at' => '2026-09-23 08:30:00',
            'ends_at' => '2026-09-24 22:30:00',
            'deleted_at' => null,
        ]);
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-14 10:05:00', 'home', null, 'checklist_operator', 7);
        $this->events->record(10, 101, 'actual_handoff', 'pickup', '2026-09-23 08:30:00', 'home', null, 'checklist_operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100];
        $data = [
            'occurred_at' => '2026-09-23 12:42:00',
            'location_class' => 'home',
            'energy_percent' => '43',
            'confirm_recovery_location' => '1',
        ];

        try {
            $service->recoverVehicle($checklist, $data, 7);
            $this->fail('A prior-trip recovery after a later trip handoff must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('later guest handoff', $exception->getMessage());
        }
        $this->assertNull($this->events->activeForTrip(100, ['vehicle_recovered']));

        $data['occurred_at'] = '2026-09-23 08:00:00';
        $recoveryId = $service->recoverVehicle($checklist, $data, 7);
        $this->assertSame('vehicle_recovered', $this->repository->event($recoveryId)['event_code']);
        $custody = (new CurrentVehicleCustodyService($this->repository))->resolve(10, new DateTimeImmutable('2026-09-23 13:00:00'));
        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(101, $custody['active_trip_id']);
    }

    public function testPriorTripActualReturnUsesTheSameLaterHandoffChronologyGuard(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 31, 'code' => 'completed'],
            ['id' => 32, 'code' => 'in_progress'],
        ]);
        $this->connection->table('turo_trips_normalized')->where('id', 200)->update([
            'trip_status_lookup_value_id' => 31,
            'starts_at' => '2026-09-14 12:00:00',
            'ends_at' => '2026-09-22 21:00:00',
        ]);
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 201,
            'fleet_vehicle_id' => 20,
            'trip_status_lookup_value_id' => 32,
            'starts_at' => '2026-09-23 08:30:00',
            'ends_at' => '2026-09-24 22:30:00',
            'deleted_at' => null,
        ]);
        $this->events->record(20, 200, 'actual_handoff', 'pickup', '2026-09-14 10:05:00', 'home', null, 'checklist_operator', 7);
        $this->events->record(20, 201, 'actual_handoff', 'pickup', '2026-09-23 08:30:00', 'home', null, 'checklist_operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'movement_type' => 'return', 'company_id' => 2, 'fleet_vehicle_id' => 20, 'turo_trip_normalized_id' => 200];

        try {
            $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-23 12:42:00'], 7);
            $this->fail('A prior-trip actual return after a later handoff must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('later guest handoff', $exception->getMessage());
        }
        $this->assertNull($this->events->activeForTrip(200, ['actual_return']));

        $this->assertTrue($service->recordForChecklist($checklist, ['occurred_at' => '2026-09-23 08:00:00'], 7));
        $custody = (new CurrentVehicleCustodyService($this->repository))->resolve(20, new DateTimeImmutable('2026-09-23 13:00:00'));
        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(201, $custody['active_trip_id']);
    }

    public function testGuestReturnReportIsUnverifiedAndNeverCompletesReturn(): void
    {
        foreach (['airport_garage_code VARCHAR(40)', 'airport_parking_level INTEGER', 'airport_parking_row VARCHAR(4)'] as $column) {
            $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN ' . $column . ' NULL');
        }
        $repository = new OperationalFactsRepository($this->connection);
        $events = new MovementEventService($repository);
        $now = new DateTimeImmutable();
        $handoffAt = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $reportedAt = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $events->record(10, 100, 'actual_handoff', 'pickup', $handoffAt, 'airport_hnl', null, 'operator', 7);
        $reportId = $events->record(10, 100, 'guest_return_staged', 'return', $reportedAt, 'airport_hnl', null, 'guest_reported_parked_time', 7, 'Guest reported parked.', ['garage_code' => 'international', 'level' => 7, 'row' => 'G']);

        $report = $repository->event($reportId);
        $this->assertNotNull($report);
        $asOf = (new DateTimeImmutable((string) $report['created_at']))->modify('+1 second');
        $this->assertSame('guest_return_staged', $report['event_code']);
        $this->assertSame('international', $report['airport_garage_code']);
        $this->assertSame(7, (int) $report['airport_parking_level']);
        $this->assertSame('G', $report['airport_parking_row']);
        $this->assertSame('guest_reported_parked_time', $report['source']);
        $custody = $repository->latestCustodyEventsForCompany(1, [10], $asOf->format('Y-m-d H:i:s'));
        $this->assertSame('guest_return_staged', $custody[10]['event_code']);
        $awaitingRecovery = $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s'));
        $this->assertCount(1, $awaitingRecovery);
        $this->assertSame($reportId, (int) $awaitingRecovery[0]['id']);
        $this->assertSame([], $repository->awaitingRecoveryForCompany(2, $asOf->format('Y-m-d H:i:s')));
        $completions = $repository->authoritativeMovementCompletionsForCompany(1, [100], $asOf->format('Y-m-d H:i:s'));
        $this->assertSame([], array_values(array_filter($completions, static fn (array $row): bool => in_array($row['event_code'], ['actual_return', 'vehicle_recovered'], true))));
        $location = (new CurrentVehicleLocationService($repository))->resolve(10, $asOf);
        $this->assertSame('awaiting_recovery', $location['operational_state']);
        $this->assertSame('unknown', $location['location_class']);
        $this->assertSame('unverified_guest_report', $location['position_semantics']);
        $buckets = array_column((new FleetSnapshotService(new CurrentVehicleLocationService($repository), $repository))->forCompany(1, $asOf)['buckets'], null, 'code');
        $this->assertSame(1, $buckets['awaiting_recovery']['count']);
        $this->assertSame(0, $buckets['rented']['count']);

        $plans = $this->createStub(VehiclePositioningPlanService::class);
        $plans->method('active')->willReturn(null);
        $board = new MovementBoardIntelligenceService(
            $repository,
            new NextConfirmedTripService($repository),
            new ImportFreshnessService(),
            new MovementStateResolver(),
            new VehiclePositioningRecommendationService(),
            $plans,
        );
        $staleRentedCard = ['fleet_vehicle_id' => 10, 'fleet_code' => 'Synthetic EV', 'status' => 'in_progress', 'primary_status' => 'currently_rented', 'flags' => ['currently_rented', 'returning_today'], 'actions' => []];
        $card = $board->enrich([$staleRentedCard], $asOf, 1)[0];
        $this->assertSame('awaiting_recovery', $card['state']['code']);
        $this->assertSame('awaiting_recovery', $card['primary_status']);
        $this->assertNotContains('currently_rented', $card['flags']);
        $this->assertSame('guest_reported', $card['location_basis']);
        $this->assertSame('Guest-reported location — unverified', $card['location_heading']);
        $this->assertSame('await_recovery', $card['recommendation']['code']);
        $this->assertSame(['Recover Vehicle'], $card['actions']);
        $this->assertSame([], $card['readiness_blockers']);
        $this->assertNotContains('charging_required', $card['flags']);

        $correctedId = $events->correct($reportId, ['airport_parking_row' => 'F'], 8, 'Corrected guest-reported row.');
        $correctedReport = $repository->event($correctedId);
        $this->assertNotNull($correctedReport);
        $this->assertSame('F', $correctedReport['airport_parking_row']);
        $asOf = (new DateTimeImmutable((string) $correctedReport['created_at']))->modify('+1 second');
        $awaitingRecovery = $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s'));
        $this->assertCount(1, $awaitingRecovery);
        $this->assertSame($correctedId, (int) $awaitingRecovery[0]['id']);
        $this->assertTrue($events->void($correctedId, 8, 'Guest report was inaccurate.'));
        $this->assertSame([], $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s')));
        $this->assertSame('rented', (new CurrentVehicleLocationService($repository))->resolve(10, $asOf)['operational_state']);

        $secondReport = $events->record(10, 100, 'guest_return_staged', 'return', $reportedAt, 'airport_hnl', null, 'guest_report_received', 7, null, ['garage_code' => 'international', 'level' => 7, 'row' => 'G']);
        $recoveredId = $events->record(10, 100, 'vehicle_recovered', 'return', $now->format('Y-m-d H:i:s'), 'airport_hnl', null, 'operator', 7);
        $recovered = $repository->event($recoveredId);
        $this->assertNotNull($recovered);
        $asOf = (new DateTimeImmutable((string) $recovered['created_at']))->modify('+1 second');
        $this->assertSame([], $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s')));
        $recoveredCard = $board->enrich([$staleRentedCard], $asOf, 1)[0];
        $this->assertSame('turnaround_attention', $recoveredCard['primary_status']);
        $this->assertNotContains('currently_rented', $recoveredCard['flags']);
        $this->assertTrue($events->void($recoveredId, 8, 'Recovery recorded prematurely.'));
        $awaitingRecovery = $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s'));
        $this->assertCount(1, $awaitingRecovery);
        $this->assertSame($secondReport, (int) $awaitingRecovery[0]['id']);
        $this->assertSame('awaiting_recovery', $board->enrich([$staleRentedCard], $asOf, 1)[0]['primary_status']);
        $actualReturnId = $events->record(10, 100, 'actual_return', 'return', $now->format('Y-m-d H:i:s'), 'airport_hnl', null, 'operator', 7);
        $actualReturn = $repository->event($actualReturnId);
        $this->assertNotNull($actualReturn);
        $asOf = (new DateTimeImmutable((string) $actualReturn['created_at']))->modify('+1 second');
        $this->assertSame([], $repository->awaitingRecoveryForCompany(1, $asOf->format('Y-m-d H:i:s')));
        $this->assertSame('turnaround_attention', $board->enrich([$staleRentedCard], $asOf, 1)[0]['primary_status']);
    }

    public function testGuestReturnStageRejectsCrossCompanyChecklistAndTrip(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = [
            'exists' => true, 'movement_type' => 'return', 'company_id' => 2,
            'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100,
        ];
        $data = ['airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'G'];
        try {
            $service->stageGuestReturn($checklist, $data, 7);
            $this->fail('Cross-company checklist should not record a guest return.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Vehicle not found in the active fleet company.', $exception->getMessage());
        }

        $checklist['company_id'] = 1;
        $checklist['turo_trip_normalized_id'] = 200;
        try {
            $service->stageGuestReturn($checklist, $data, 7);
            $this->fail('Cross-company trip should not record a guest return.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Trip does not belong to this vehicle and company.', $exception->getMessage());
        }
        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());
    }

    public function testGuestReturnStageCanRecordUnknownParkingWithoutInventingDetails(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = [
            'exists' => true, 'movement_type' => 'return', 'company_id' => 1,
            'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100,
        ];
        $eventId = $service->stageGuestReturn($checklist, [], 7);
        $event = $this->repository->event($eventId);

        $this->assertSame('guest_return_staged', $event['event_code']);
        $this->assertSame('airport_hnl', $event['location_class']);
        $this->assertNull($event['location_detail']);
        $this->assertSame('guest_report_received', $event['source']);
        $this->assertSame(7, (int) $event['actor_user_id']);
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testVehicleScopedHnlPositionIsPhysicalStorageWithoutTripOrStaging(): void
    {
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_garage_code VARCHAR(40) NULL');
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_parking_level INTEGER NULL');
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_parking_row VARCHAR(4) NULL');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, invalidated_at DATETIME NULL, invalidation_reason VARCHAR(80) NULL, invalidated_by_user_id INTEGER NULL)');
        $repository = new OperationalFactsRepository($this->connection);
        $events = new MovementEventService($repository);
        $service = new MovementOperationalFactService($this->connection, $events, new MovementAssessmentService($repository), new VehiclePositioningPlanService($repository));
        $data = [
            'occurred_at' => '2026-09-09 16:45:00',
            'location_class' => 'airport_hnl',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
            'note' => 'Fleet storage overflow.',
        ];

        $this->assertTrue($service->recordCurrentPositionForVehicle(1, 10, $data, 7));

        $event = $repository->latestCurrentStateEvent(10);
        $this->assertSame('vehicle_positioned', $event['event_code']);
        $this->assertNull($event['turo_trip_normalized_id']);
        $this->assertNull($event['movement_type']);
        $this->assertSame('airport_hnl', $event['location_class']);
        $this->assertSame('international', $event['airport_garage_code']);
        $this->assertSame(7, (int) $event['airport_parking_level']);
        $this->assertSame('F', $event['airport_parking_row']);
        $this->assertSame('Fleet storage overflow.', $event['note']);
        $this->assertSame('vehicle_operator', $event['source']);
        $this->assertSame(7, (int) $event['actor_user_id']);
        $resolvedPosition = (new CurrentVehicleLocationService($repository))->resolve(10);
        $this->assertSame('unknown', $resolvedPosition['operational_state']);
        $this->assertSame('last_known', $resolvedPosition['position_semantics']);
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
        $this->assertSame(0, $this->connection->table('airport_movement_workflows')->countAllResults());

        try {
            $service->recordCurrentPositionForVehicle(1, 10, $data, 8);
            $this->fail('Expected an exact replay by another actor to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('This exact vehicle position is already recorded.', $exception->getMessage());
        }
        $later = array_merge($data, ['occurred_at' => '2026-09-09 17:00:00']);
        $this->assertTrue($service->recordCurrentPositionForVehicle(1, 10, $later, 8));
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());

        $laterEvent = $repository->latestCurrentStateEvent(10);
        $replacementId = $events->correct((int) $laterEvent['id'], ['location_class' => 'other_delivery', 'location_detail' => 'Service center'], 8, 'Corrected current position.');
        $this->assertSame('other_delivery', (new CurrentVehicleLocationService($repository))->resolve(10)['location_class']);
        $this->assertTrue($events->void($replacementId, 8, 'Correction was not current.'));
        $this->assertSame('airport_hnl', (new CurrentVehicleLocationService($repository))->resolve(10)['location_class']);
    }

    public function testVehicleScopedGenericPositionsSupportHomeAndOther(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, invalidated_at DATETIME NULL, invalidation_reason VARCHAR(80) NULL, invalidated_by_user_id INTEGER NULL)');
        $service = new MovementOperationalFactService(
            $this->connection,
            $this->events,
            $this->assessments,
            new VehiclePositioningPlanService($this->repository),
        );

        $this->assertTrue($service->recordCurrentPositionForVehicle(1, 10, [
            'occurred_at' => '2026-09-09 14:00:00',
            'location_class' => 'home',
            'location_detail' => 'North driveway',
        ], 7));
        $this->assertTrue($service->recordCurrentPositionForVehicle(1, 10, [
            'occurred_at' => '2026-09-09 15:00:00',
            'location_class' => 'other_delivery',
            'location_detail' => 'Service center',
        ], 8));

        $current = (new CurrentVehicleLocationService($this->repository))->resolve(10);
        $this->assertSame('other_delivery', $current['location_class']);
        $this->assertSame('Service center', $current['location_detail']);
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
    }

    public function testCurrentPositionTransactionRollsBackWhenPlanInvalidationFails(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, invalidated_at DATETIME NULL, invalidation_reason VARCHAR(80) NULL, invalidated_by_user_id INTEGER NULL)');
        $failingPlans = new class ($this->repository) extends VehiclePositioningPlanService {
            public function invalidateForWrite(int $vehicleId, string $reason, ?int $actorUserId): int
            {
                throw new RuntimeException('Injected positioning-plan failure.');
            }
        };
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments, $failingPlans);

        try {
            $service->recordCurrentPositionForVehicle(1, 10, [
                'occurred_at' => '2026-09-09 16:45:00',
                'location_class' => 'home',
            ], 7);
            $this->fail('Expected the injected positioning-plan failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected positioning-plan failure.', $exception->getMessage());
        }

        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());
    }

    public function testVehicleScopedCurrentStateRejectsCompanyMismatchAndGuestPossession(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $position = ['occurred_at' => '2026-09-09 16:45:00', 'location_class' => 'home'];

        try {
            $service->recordCurrentPositionForVehicle(1, 20, $position, 7);
            $this->fail('Expected cross-company positioning to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active fleet company', $exception->getMessage());
        }
        try {
            $service->recordCurrentReadinessForVehicle(1, 20, [
                'occurred_at' => '2026-09-09 16:45:00',
                'cleanliness' => 'clean',
                'energy_percent' => 88,
            ], 7);
            $this->fail('Expected cross-company readiness to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active fleet company', $exception->getMessage());
        }

        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-09 15:00:00', 'home', null, 'operator', 7);
        foreach (['position' => $position, 'readiness' => ['occurred_at' => '2026-09-09 16:45:00', 'cleanliness' => 'clean', 'energy_percent' => 88]] as $kind => $data) {
            try {
                $kind === 'position'
                    ? $service->recordCurrentPositionForVehicle(1, 10, $data, 7)
                    : $service->recordCurrentReadinessForVehicle(1, 10, $data, 7);
                $this->fail('Expected guest possession to reject ' . $kind . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('actual return or recovery', $exception->getMessage());
            }
        }
        $this->assertSame(1, $this->connection->table('trip_movement_events')->countAllResults());
    }

    public function testCurrentReadinessIsSeparateFromHistoricalReturnAndLocation(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, invalidated_at DATETIME NULL, invalidation_reason VARCHAR(80) NULL, invalidated_by_user_id INTEGER NULL)');
        $this->connection->table('vehicle_positioning_plans')->insert(['id' => 1, 'company_id' => 1, 'fleet_vehicle_id' => 10]);
        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-09 13:00:00', 'airport_hnl', 'International Garage', 'operator', 7);
        $this->assessments->record(10, 100, $returnId, 'return', 'dirty', 60, '2026-09-09 13:00:00', 'operator', 7);
        $positionId = $this->events->record(10, null, 'vehicle_positioned', null, '2026-09-09 14:00:00', 'home', null, 'vehicle_operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $data = ['occurred_at' => '2026-09-09 16:45:00', 'cleanliness' => 'clean', 'energy_percent' => 88, 'note' => 'Turnaround complete.'];

        $this->assertTrue($service->recordCurrentReadinessForVehicle(1, 10, $data, 8));

        $current = $this->repository->latestCurrentReadinessAssessment(1, 10);
        $this->assertSame('current', $current['movement_type']);
        $this->assertNull($current['turo_trip_normalized_id']);
        $this->assertSame('clean', $current['cleanliness']);
        $this->assertSame(88, (int) $current['energy_percent']);
        $this->assertSame('vehicle_operator', $current['source']);
        $this->assertSame(8, (int) $current['actor_user_id']);
        $this->assertSame('vehicle_readiness_observed', $this->repository->event((int) $current['trip_movement_event_id'])['event_code']);
        $this->assertSame($positionId, $this->repository->latestActiveMovementEvent(10)['id']);
        $this->assertSame('home', (new CurrentVehicleLocationService($this->repository))->resolve(10)['location_class']);
        $historical = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);
        $this->assertSame('Dirty', $historical['cleanliness_label']);
        $this->assertSame('60%', $historical['energy_value']);
        $plan = $this->connection->table('vehicle_positioning_plans')->where('id', 1)->get()->getRowArray();
        $this->assertNull($plan['invalidated_at']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact current readiness observation');
        $service->recordCurrentReadinessForVehicle(1, 10, $data, 7);
    }

    public function testCurrentReadinessTransactionRollsBackWhenAssessmentFails(): void
    {
        $failingAssessments = new class ($this->repository) extends MovementAssessmentService {
            public function record(int $vehicleId, ?int $tripId, ?int $eventId, string $movementType, ?string $cleanliness, mixed $energyPercent, string $capturedAt, string $source, int $actorUserId, ?string $note = null): int
            {
                throw new RuntimeException('Injected assessment failure.');
            }
        };
        $service = new MovementOperationalFactService($this->connection, $this->events, $failingAssessments);

        try {
            $service->recordCurrentReadinessForVehicle(1, 10, ['occurred_at' => '2026-09-09 16:45:00', 'cleanliness' => 'clean', 'energy_percent' => 88], 7);
            $this->fail('Expected the injected assessment failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected assessment failure.', $exception->getMessage());
        }

        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testRecordVehiclePositionAppendsOnlyPositionFactAndInvalidatesPlan(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, invalidated_at DATETIME NULL, invalidation_reason VARCHAR(80) NULL, invalidated_by_user_id INTEGER NULL)');
        $this->connection->table('vehicle_positioning_plans')->insert(['id' => 1, 'company_id' => 1, 'fleet_vehicle_id' => 10]);
        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 09:00:00', 'waikiki_hotel', 'Romer House', 'operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments, new VehiclePositioningPlanService($this->repository));

        $beforePosition = (new CurrentVehicleLocationService($this->repository))->resolve(10);
        $this->assertSame('waikiki_hotel', $beforePosition['location_class']);
        $this->assertSame('Romer House', $beforePosition['location_detail']);

        $this->assertTrue($service->recordVehiclePosition(
            ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'],
            ['occurred_at' => '2026-09-01 10:00:00', 'location_class' => 'home', 'note' => 'Vehicle arrived home.'],
            8,
        ));

        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
        $position = $this->repository->latestLocationEvent(10);
        $this->assertSame('vehicle_positioned', $position['event_code']);
        $this->assertSame('home', $position['location_class']);
        $this->assertNull($position['movement_type']);
        $this->assertSame('checklist_operator', $position['source']);
        $this->assertSame(8, (int) $position['actor_user_id']);
        $this->assertSame('waikiki_hotel', $this->repository->event($returnId)['location_class']);
        $plan = $this->connection->table('vehicle_positioning_plans')->where('id', 1)->get()->getRowArray();
        $this->assertNotNull($plan['invalidated_at']);
        $this->assertSame('actual_vehicle_position_recorded', $plan['invalidation_reason']);
        $this->assertSame(1, $this->connection->table('operational_fact_audits')->where('table_name', 'vehicle_positioning_plans')->where('action', 'invalidated')->countAllResults());
    }

    public function testExactPositionReplayIsRejectedButLaterReturnToSameLocationIsAllowed(): void
    {
        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 15:30:00', 'waikiki_hotel', 'LOCAL TEST HOTEL', 'operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'];
        $home = ['occurred_at' => '2026-09-01 16:50:00', 'location_class' => 'home', 'note' => 'Arrived home.'];

        $this->assertTrue($service->recordVehiclePosition($checklist, $home, 7));
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());

        try {
            $service->recordVehiclePosition($checklist, $home, 8);
            $this->fail('Expected an exact position replay to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('This exact vehicle position is already recorded.', $exception->getMessage());
        }
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());

        $this->assertTrue($service->recordVehiclePosition($checklist, [
            'occurred_at' => '2026-09-01 17:30:00',
            'location_class' => 'other_delivery',
            'location_detail' => 'Service center',
        ], 7));
        $this->assertTrue($service->recordVehiclePosition($checklist, [
            'occurred_at' => '2026-09-01 18:45:00',
            'location_class' => 'home',
        ], 7));

        $this->assertSame(4, $this->connection->table('trip_movement_events')->countAllResults());
        $return = $this->repository->event($returnId);
        $this->assertSame('actual_return', $return['event_code']);
        $this->assertSame('waikiki_hotel', $return['location_class']);
        $this->assertSame('LOCAL TEST HOTEL', $return['location_detail']);
        $this->assertSame('2026-09-01 15:30:00', $return['occurred_at']);
    }

    public function testLegacyParkingColumnIsExposedOnlyAsCanonicalRow(): void
    {
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_garage_code VARCHAR(40) NULL');
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_parking_level INTEGER NULL');
        $this->connection->query('ALTER TABLE ' . $this->table('trip_movement_events') . ' ADD COLUMN airport_parking_stall VARCHAR(4) NULL');
        $repository = new OperationalFactsRepository($this->connection);
        $events = new MovementEventService($repository);

        $eventId = $events->record(
            10,
            100,
            'vehicle_positioned',
            null,
            '2026-09-01 16:50:00',
            'airport_hnl',
            null,
            'checklist_operator',
            7,
            null,
            ['garage_code' => 'terminal_2', 'level' => 4, 'row' => 'M'],
        );

        $stored = $this->connection->table('trip_movement_events')->where('id', $eventId)->get()->getRowArray();
        $event = $repository->event($eventId);
        $this->assertSame('M', $stored['airport_parking_stall']);
        $this->assertSame('M', $event['airport_parking_row']);
        $this->assertArrayNotHasKey('airport_parking_stall', $event);
        $this->assertTrue($events->hasExactActivePosition(10, 100, '2026-09-01 16:50:00', 'airport_hnl', null, 8, [
            'garage_code' => 'terminal_2', 'level' => 4, 'row' => 'M',
        ]));
    }

    public function testPickupHnlPositionMustUseStagingWorkflow(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use Stage at HNL');
        $service->recordVehiclePosition(
            ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'],
            ['occurred_at' => '2026-09-01 10:00:00', 'location_class' => 'airport_hnl'],
            7,
        );
    }

    public function testFutureOrRentedPositionWritesAreRejectedWithoutMutation(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'];

        try {
            $service->recordVehiclePosition($checklist, ['occurred_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'), 'location_class' => 'home'], 7);
            $this->fail('Expected a future position time to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Actual position time cannot be in the future.', $exception->getMessage());
        }
        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());

        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-01 09:00:00', 'home', null, 'operator', 7);
        try {
            $service->recordVehiclePosition($checklist, ['occurred_at' => '2026-09-01 10:00:00', 'location_class' => 'home'], 7);
            $this->fail('Expected a rented vehicle position to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Record the actual return before recording a parked vehicle position.', $exception->getMessage());
        }
        $this->assertSame(1, $this->connection->table('trip_movement_events')->countAllResults());
    }

    public function testTripContextAndHistoryStayOnTheSameVehicle(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'booked'],
            ['id' => 2, 'code' => 'completed'],
            ['id' => 3, 'code' => 'canceled_zero_payout'],
            ['id' => 4, 'code' => 'canceled_host_payout'],
        ]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'guest_name' => 'Current Guest', 'starts_at' => '2026-09-03 10:00:00', 'ends_at' => '2026-09-04 10:00:00', 'trip_status_lookup_value_id' => 2,
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 80, 'fleet_vehicle_id' => 10, 'guest_name' => 'Previous Guest', 'starts_at' => '2026-08-29 10:00:00', 'ends_at' => '2026-08-30 10:00:00', 'trip_status_lookup_value_id' => 2, 'deleted_at' => null],
            ['id' => 90, 'fleet_vehicle_id' => 10, 'guest_name' => 'Canceled Previous Guest', 'starts_at' => '2026-09-01 10:00:00', 'ends_at' => '2026-09-02 10:00:00', 'trip_status_lookup_value_id' => 3, 'deleted_at' => null],
            ['id' => 110, 'fleet_vehicle_id' => 10, 'guest_name' => 'Canceled Next Guest', 'starts_at' => '2026-09-05 10:00:00', 'ends_at' => '2026-09-06 10:00:00', 'trip_status_lookup_value_id' => 4, 'deleted_at' => null],
            ['id' => 120, 'fleet_vehicle_id' => 10, 'guest_name' => 'Next Guest', 'starts_at' => '2026-09-07 10:00:00', 'ends_at' => '2026-09-08 10:00:00', 'trip_status_lookup_value_id' => 1, 'deleted_at' => null],
            ['id' => 105, 'fleet_vehicle_id' => 20, 'guest_name' => 'Other Vehicle', 'starts_at' => '2026-09-04 12:00:00', 'ends_at' => '2026-09-05 12:00:00', 'trip_status_lookup_value_id' => 1, 'deleted_at' => null],
        ]);
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, movement_type VARCHAR(20), scheduled_at DATETIME)');
        $this->connection->table('trip_movement_checklists')->insertBatch([
            ['id' => 501, 'turo_trip_normalized_id' => 80, 'movement_type' => 'pickup', 'scheduled_at' => '2026-08-29 10:00:00'],
            ['id' => 502, 'turo_trip_normalized_id' => 90, 'movement_type' => 'pickup', 'scheduled_at' => '2026-09-01 10:00:00'],
            ['id' => 503, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return', 'scheduled_at' => '2026-09-04 10:00:00'],
            ['id' => 504, 'turo_trip_normalized_id' => 120, 'movement_type' => 'pickup', 'scheduled_at' => '2026-09-07 10:00:00'],
        ]);
        $checklistCount = $this->connection->table('trip_movement_checklists')->countAllResults();

        $context = $this->repository->tripContext(100);
        $history = $this->repository->vehicleTripHistory(10);

        $this->assertSame(80, (int) $context['previous']['id']);
        $this->assertSame(100, (int) $context['current']['id']);
        $this->assertSame(120, (int) $context['next']['id']);
        $this->assertSame('/operations/checklists/501', $context['previous']['movement_href']);
        $this->assertSame('/operations/checklists/503', $context['current']['movement_href']);
        $this->assertSame('/operations/checklists/504', $context['next']['movement_href']);
        $this->assertSame('/operations/checklists/503', $this->repository->movementChecklistHref(100, 'return'));
        $this->assertSame([120, 110, 100, 90, 80], array_map(static fn (array $trip): int => (int) $trip['id'], $history));
        $this->assertSame(['booked', 'canceled_host_payout', 'completed', 'canceled_zero_payout', 'completed'], array_column($history, 'trip_status_code'));
        $this->assertNotContains(105, array_column($history, 'id'));
        $this->assertSame($checklistCount, $this->connection->table('trip_movement_checklists')->countAllResults());

        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => 3]);
        $selectedCanceledContext = $this->repository->tripContext(100);

        $this->assertSame(100, (int) $selectedCanceledContext['current']['id']);
        $this->assertSame('canceled_zero_payout', $selectedCanceledContext['current']['trip_status_code']);
        $this->assertSame(80, (int) $selectedCanceledContext['previous']['id']);
        $this->assertSame(120, (int) $selectedCanceledContext['next']['id']);
        $this->assertSame($checklistCount, $this->connection->table('trip_movement_checklists')->countAllResults());
    }

    public function testEventCorrectionAppendsOneReplacementAndVoidsOriginal(): void
    {
        $originalId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 10:00:00', 'unknown', null, 'operator', 7);

        $replacementId = $this->events->correct($originalId, ['location_class' => 'home', 'location_detail' => 'Fleet yard'], 8, 'Location confirmed');

        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
        $original = $this->repository->event($originalId);
        $replacement = $this->repository->event($replacementId);
        $this->assertNotNull($original['voided_at']);
        $this->assertSame($originalId, (int) $replacement['supersedes_event_id']);
        $this->assertSame('home', $replacement['location_class']);
        $this->assertSame(1, $this->connection->table('operational_fact_audits')->where('action', 'superseded')->countAllResults());
    }

    public function testAssessmentCorrectionAndVoidPreserveHistory(): void
    {
        $eventId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-01 10:00:00', 'home', null, 'operator', 7);
        $originalId = $this->assessments->record(10, 100, $eventId, 'return', 'dirty', 25, '2026-09-01 10:01:00', 'operator', 7);

        $replacementId = $this->assessments->correct($originalId, ['cleanliness' => 'clean', 'energy_percent' => 80], 8, 'Rechecked vehicle');

        $this->assertSame(2, $this->connection->table('movement_assessments')->countAllResults());
        $this->assertNotNull($this->repository->assessment($originalId)['voided_at']);
        $this->assertSame($originalId, (int) $this->repository->assessment($replacementId)['supersedes_assessment_id']);
        $this->assertTrue($this->assessments->void($replacementId, 9, 'Duplicate assessment'));
        $this->assertSame([], $this->assessments->forTrip(100));
    }

    public function testChecklistCorrectionSupersedesLinkedPairAndPreservesAuditHistory(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 08:05:00', 'location_class' => 'waikiki_hotel', 'cleanliness' => 'clean', 'energy_percent' => 82], 7);
        $originalEvent = $this->connection->table('trip_movement_events')->get()->getRowArray();
        $originalAssessment = $this->connection->table('movement_assessments')->get()->getRowArray();

        $this->assertTrue($service->correctForChecklist($checklist, [
            'event_id' => $originalEvent['id'],
            'assessment_id' => $originalAssessment['id'],
            'occurred_at' => '2026-09-03 08:10:00',
            'location_class' => 'waikiki_hotel',
            'location_detail' => 'Hotel lobby',
            'cleanliness' => 'dirty',
            'energy_percent' => 79,
            'correction_reason' => 'Operator rechecked the handoff notes.',
        ], 8));

        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(2, $this->connection->table('movement_assessments')->countAllResults());
        $replacementEvent = $this->connection->table('trip_movement_events')->where('voided_at', null)->get()->getRowArray();
        $replacementAssessment = $this->connection->table('movement_assessments')->where('voided_at', null)->get()->getRowArray();
        $this->assertSame((int) $replacementEvent['id'], (int) $replacementAssessment['trip_movement_event_id']);
        $this->assertSame((int) $originalEvent['id'], (int) $replacementEvent['supersedes_event_id']);
        $this->assertSame((int) $originalAssessment['id'], (int) $replacementAssessment['supersedes_assessment_id']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->where('action', 'superseded')->countAllResults());

        $returnChecklist = array_merge($checklist, ['movement_type' => 'return']);
        $this->assertTrue($service->recordForChecklist($returnChecklist, [
            'occurred_at' => '2026-09-03 21:42:00',
            'location_class' => 'home',
            'cleanliness' => 'clean',
            'energy_percent' => 87,
        ], 7));

        $tripFacts = (new MovementOperationalFactPresentationService($this->repository))->tripFacts(100);
        $this->assertSame((int) $replacementEvent['id'], (int) $tripFacts['pickup']['event_id']);
        $this->assertNotSame((int) $originalEvent['id'], (int) $tripFacts['pickup']['event_id']);
        $this->assertSame('actual_return', $tripFacts['return']['event_code']);
        $this->assertSame('2026-09-03 21:42:00', $tripFacts['return']['occurred_at']);
        $this->assertSame(1, $this->connection->table('trip_movement_events')->where('voided_at', null)->where('movement_type', 'pickup')->countAllResults());
    }

    public function testHnlStagingAndGuestPickupRemainDistinctAuthoritativeEvents(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];

        $this->assertTrue($service->stageForChecklist($checklist, [
            'occurred_at' => '2026-09-03 07:30:00',
            'location_class' => 'airport_hnl',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
            'cleanliness' => 'clean',
            'energy_percent' => 82,
            'note' => 'Ready for guest.',
        ], 7));

        $staged = $this->repository->latestActiveEventForTrip(100);
        $this->assertSame('vehicle_staged', $staged['event_code']);
        $this->assertSame('airport_hnl', $staged['location_class']);
        $this->assertSame('International Garage L7 RF', $staged['location_detail']);
        $this->assertSame(0, $this->connection->table('trip_movement_events')->where('event_code', 'actual_handoff')->countAllResults());
        $this->assertSame(1, $this->connection->table('movement_assessments')->countAllResults());

        $this->assertTrue($service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:02:00', 'note' => 'Guest confirmed possession.'], 8));

        $handoff = $this->repository->latestActiveEventForTrip(100);
        $this->assertSame('actual_handoff', $handoff['event_code']);
        $this->assertSame('International Garage L7 RF', $handoff['location_detail']);
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(1, $this->connection->table('movement_assessments')->countAllResults());
        $this->assertNull($this->repository->event((int) $staged['id'])['voided_at']);
    }

    public function testVoidedAndSupersededHandoffsDoNotSuppressValidConfirmation(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->stageForChecklist($checklist, [
            'occurred_at' => '2026-09-03 07:30:00',
            'location_class' => 'airport_hnl',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
            'cleanliness' => 'clean',
            'energy_percent' => 82,
        ], 7);
        $service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:00:00'], 7);
        $firstHandoff = $this->repository->activeMovementConflict(100, ['actual_handoff']);
        $this->assertTrue($this->events->void((int) $firstHandoff['id'], 8, 'Confirmation was premature.'));

        $this->assertTrue($service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:05:00'], 8));
        $secondHandoff = $this->repository->activeMovementConflict(100, ['actual_handoff']);
        $this->events->correct((int) $secondHandoff['id'], ['event_code' => 'vehicle_staged', 'occurred_at' => '2026-09-03 08:06:00'], 8, 'Guest had not taken possession.');

        $this->assertTrue($service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:10:00'], 8));
        $this->assertSame(1, $this->connection->table('trip_movement_events')->where(['event_code' => 'actual_handoff', 'voided_at' => null])->countAllResults());
        $this->assertSame(2, $this->connection->table('trip_movement_events')->where('event_code', 'actual_handoff')->where('voided_at IS NOT NULL', null, false)->countAllResults());
    }

    public function testDuplicateHandoffIsRejectedEvenWhenLaterStagingIsLatest(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->stageForChecklist($checklist, [
            'occurred_at' => '2026-09-03 07:30:00',
            'location_class' => 'airport_hnl',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
            'cleanliness' => 'clean',
            'energy_percent' => 82,
        ], 7);
        $service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:00:00'], 7);
        $this->events->record(10, 100, 'vehicle_staged', 'pickup', '2026-09-03 08:05:00', 'airport_hnl', 'International Garage L7 RF', 'operator', 7);

        try {
            $service->confirmGuestPickup($checklist, ['occurred_at' => '2026-09-03 08:10:00'], 8);
            $this->fail('Expected duplicate handoff rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Guest pickup is already confirmed for this movement.', $exception->getMessage());
        }

        try {
            $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 08:10:00', 'location_class' => 'home'], 8);
            $this->fail('Expected duplicate direct handoff rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Guest pickup is already confirmed for this movement.', $exception->getMessage());
        }

        $this->assertSame(1, $this->connection->table('trip_movement_events')->where(['event_code' => 'actual_handoff', 'voided_at' => null])->countAllResults());
    }

    public function testStagingRejectsNonAirportAndNonPickupMovements(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an Airport HNL pickup movement can be staged.');
        $service->stageForChecklist(
            ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'],
            ['occurred_at' => '2026-09-03 07:30:00', 'location_class' => 'home'],
            7,
        );
    }

    public function testEarlyDirectHandoffRequiresExplicitConfirmation(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup', 'scheduled_at' => '2026-09-03 12:00:00'];

        try {
            $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:30:00', 'location_class' => 'home'], 7);
            $this->fail('Expected an early handoff warning.');
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(\App\Exceptions\EarlyHandoffConfirmationRequired::class, $exception);
            $this->assertStringContainsString('This reservation does not begin until Sep 3, 2026 at 12:00 PM.', $exception->getMessage());
            $this->assertStringContainsString('more than 2 hours early', $exception->getMessage());
            $this->assertStringContainsString('Are you sure this is the correct reservation?', $exception->getMessage());
        }
        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());

        $this->assertTrue($service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:30:00', 'location_class' => 'home', 'confirm_early_handoff' => '1'], 7));
        $this->assertSame('actual_handoff', $this->repository->latestActiveEventForTrip(100)['event_code']);
    }

    public function testHnlDirectHandoffIsRejectedInFavorOfStaging(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stage the vehicle at HNL');
        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:30:00', 'location_class' => 'airport_hnl'], 7);
    }

    public function testWrongTripRepairSupersedesPairAndPreservesObservationProvenance(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2026-09-03 08:00:00', 'ends_at' => '2026-09-04 08:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 101, 'fleet_vehicle_id' => 10, 'guest_name' => 'Correct Guest', 'starts_at' => '2026-09-03 09:00:00', 'ends_at' => '2026-09-04 09:00:00', 'deleted_at' => null]);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:02:00', 'location_class' => 'waikiki_hotel', 'location_detail' => 'Lobby', 'cleanliness' => 'clean', 'energy_percent' => 80, 'note' => 'Observed handoff.'], 7);
        [$event, $assessment] = $this->activeObservation();

        $this->assertSame([101], array_map(static fn (array $trip): int => (int) $trip['id'], $service->wrongTripCandidates($checklist, (int) $event['id'])));
        $this->assertTrue($service->repairWrongTrip($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'target_trip_id' => 101, 'repair_reason' => 'Selected adjacent reservation.'], 8));

        [$replacementEvent, $replacementAssessment] = $this->activeObservation();
        $this->assertSame(101, (int) $replacementEvent['turo_trip_normalized_id']);
        $this->assertSame(101, (int) $replacementAssessment['turo_trip_normalized_id']);
        $this->assertSame('2026-09-03 09:02:00', $replacementEvent['occurred_at']);
        $this->assertSame('waikiki_hotel', $replacementEvent['location_class']);
        $this->assertSame('clean', $replacementAssessment['cleanliness']);
        $this->assertSame('checklist_operator', $replacementEvent['source']);
        $this->assertSame(7, (int) $replacementEvent['actor_user_id']);
        $this->assertSame(7, (int) $replacementAssessment['actor_user_id']);
        $this->assertNotNull($this->repository->event((int) $event['id'])['voided_at']);
        $this->assertNotNull($this->repository->assessment((int) $assessment['id'])['voided_at']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->where(['action' => 'superseded', 'actor_user_id' => 8])->countAllResults());
    }

    public function testCompletedHistoricalTripWithStagingButNoHandoffIsRepairCandidateAndPreservesFacts(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'completed'],
            ['id' => 2, 'code' => 'booked'],
        ]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'trip_status_lookup_value_id' => 2,
            'guest_name' => 'Later Guest',
            'starts_at' => '2026-10-06 21:30:00',
            'ends_at' => '2026-10-12 06:00:00',
        ]);
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 101,
            'fleet_vehicle_id' => 10,
            'trip_status_lookup_value_id' => 1,
            'guest_name' => 'Historical Guest',
            'starts_at' => '2026-10-05 08:00:00',
            'ends_at' => '2026-10-05 21:30:00',
            'deleted_at' => null,
        ]);
        $stagingEventId = $this->events->record(10, 101, 'vehicle_staged', 'pickup', '2026-10-05 07:45:00', 'airport_hnl', 'Garage staging', 'checklist_operator', 7);
        $this->assessments->record(10, 101, $stagingEventId, 'pickup', 'clean', 90, '2026-10-05 07:45:00', 'checklist_operator', 7);
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, movement_type VARCHAR(20), scheduled_at DATETIME, completed_at DATETIME NULL)');
        $this->connection->table('trip_movement_checklists')->insertBatch([
            ['id' => 501, 'turo_trip_normalized_id' => 101, 'movement_type' => 'pickup', 'scheduled_at' => '2026-10-05 08:00:00', 'completed_at' => '2026-10-05 08:55:00'],
            ['id' => 502, 'turo_trip_normalized_id' => 101, 'movement_type' => 'return', 'scheduled_at' => '2026-10-05 21:30:00', 'completed_at' => '2026-10-05 21:40:00'],
        ]);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup', 'scheduled_at' => '2026-10-06 21:30:00'];
        $service->recordForChecklist($checklist, [
            'occurred_at' => '2026-10-05 08:50:00',
            'location_class' => 'home',
            'cleanliness' => 'clean',
            'energy_percent' => 80,
            'note' => 'Observed handoff.',
            'confirm_early_handoff' => '1',
        ], 7);
        $event = $this->repository->latestActiveEventForTrip(100);
        $assessment = $this->repository->assessmentForEventOrTrip((int) $event['id'], 100);

        $this->assertSame([101], array_map(static fn (array $trip): int => (int) $trip['id'], $service->wrongTripCandidates($checklist, (int) $event['id'])));
        $this->assertTrue($service->repairWrongTrip($checklist, [
            'event_id' => $event['id'],
            'assessment_id' => $assessment['id'],
            'target_trip_id' => 101,
            'repair_reason' => 'Selected the adjacent historical reservation.',
        ], 8));

        $replacementEvent = $this->connection->table('trip_movement_events')->where(['turo_trip_normalized_id' => 101, 'event_code' => 'actual_handoff', 'voided_at' => null])->get()->getRowArray();
        $replacementAssessment = $this->connection->table('movement_assessments')->where(['trip_movement_event_id' => $replacementEvent['id'], 'voided_at' => null])->get()->getRowArray();
        $this->assertSame('2026-10-05 08:50:00', $replacementEvent['occurred_at']);
        $this->assertSame('home', $replacementEvent['location_class']);
        $this->assertSame('clean', $replacementAssessment['cleanliness']);
        $this->assertSame(80, (int) $replacementAssessment['energy_percent']);
        $this->assertSame('checklist_operator', $replacementEvent['source']);
        $this->assertSame(7, (int) $replacementEvent['actor_user_id']);
        $this->assertSame(7, (int) $replacementAssessment['actor_user_id']);
        $this->assertSame((int) $event['id'], (int) $replacementEvent['supersedes_event_id']);
        $this->assertSame((int) $assessment['id'], (int) $replacementAssessment['supersedes_assessment_id']);
        $this->assertNotNull($this->repository->event((int) $event['id'])['voided_at']);
        $this->assertNotNull($this->repository->assessment((int) $assessment['id'])['voided_at']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->where(['action' => 'superseded', 'actor_user_id' => 8])->countAllResults());
    }

    public function testWrongTripHandoffRepairRecomputesActiveAndNextTripPresentation(): void
    {
        $this->connection->table('lookup_values')->insert(['id' => 1, 'code' => 'booked']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'trip_status_lookup_value_id' => 1,
            'guest_name' => 'Prior Guest',
            'starts_at' => '2026-10-05 08:00:00',
            'ends_at' => '2026-10-05 21:30:00',
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 1, 'guest_name' => 'Current Guest', 'starts_at' => '2026-10-06 21:30:00', 'ends_at' => '2026-10-12 06:00:00', 'deleted_at' => null],
            ['id' => 102, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 1, 'guest_name' => 'Following Guest', 'starts_at' => '2026-10-14 12:30:00', 'ends_at' => '2026-10-19 18:00:00', 'deleted_at' => null],
        ]);
        $facts = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $wrongChecklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 101, 'movement_type' => 'pickup', 'scheduled_at' => '2026-10-06 21:30:00'];
        $facts->recordForChecklist($wrongChecklist, [
            'occurred_at' => '2026-10-05 20:00:00',
            'location_class' => 'home',
            'confirm_early_handoff' => '1',
        ], 7);
        $nextTrips = new NextConfirmedTripService($this->repository);
        $plans = $this->createStub(VehiclePositioningPlanService::class);
        $plans->method('active')->willReturn(null);
        $board = new MovementBoardIntelligenceService(
            $this->repository,
            $nextTrips,
            new ImportFreshnessService(),
            new MovementStateResolver(),
            new VehiclePositioningRecommendationService(),
            $plans,
        );
        $card = ['fleet_vehicle_id' => 10, 'fleet_code' => 'Test Vehicle 10', 'status' => 'available'];
        $asOf = new DateTimeImmutable('2026-10-06 08:00:00');

        $beforeRepair = $board->enrich([$card], $asOf)[0];

        $this->assertSame('on_trip', $beforeRepair['state']['code']);
        $this->assertSame(101, (int) $beforeRepair['state']['basis_facts']['trip_schedule']['id']);
        $this->assertSame('Current Guest', $beforeRepair['current_trip']['guest_name']);
        $this->assertSame(102, (int) $beforeRepair['next_trip']['id']);
        $this->assertSame('Following Guest', $beforeRepair['next_trip']['guest_name']);
        $this->assertNotSame($beforeRepair['state']['basis_facts']['trip_schedule']['id'], $beforeRepair['next_trip']['id']);

        [$event, $assessment] = $this->activeObservation();
        $this->assertTrue($facts->repairWrongTrip($wrongChecklist, [
            'event_id' => $event['id'],
            'assessment_id' => $assessment['id'],
            'target_trip_id' => 100,
            'repair_reason' => 'Handoff belonged to the prior reservation.',
        ], 8));

        $afterRepair = $board->enrich([$card], $asOf)[0];

        $this->assertSame(100, (int) $afterRepair['state']['basis_facts']['trip_schedule']['id']);
        $this->assertSame('return_confirmation_overdue', $afterRepair['state']['code']);
        $this->assertSame('Prior Guest', $afterRepair['current_trip']['guest_name']);
        $this->assertSame(101, (int) $afterRepair['next_trip']['id']);
        $this->assertSame('Current Guest', $afterRepair['next_trip']['guest_name']);
        $this->assertNull($this->repository->latestActiveEventForTrip(101));
    }

    public function testWrongTripRepairRejectsConflictingTarget(): void
    {
        $this->connection->table('lookup_values')->insert(['id' => 1, 'code' => 'completed']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2026-09-03 08:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 1, 'starts_at' => '2026-09-03 09:00:00', 'ends_at' => '2026-09-03 21:00:00', 'deleted_at' => null]);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:02:00', 'location_class' => 'home'], 7);
        [$event, $assessment] = $this->activeObservation();
        $this->events->record(10, 101, 'actual_handoff', 'pickup', '2026-09-03 09:01:00', 'home', null, 'checklist_operator', 7);

        $this->assertSame([], $service->wrongTripCandidates($checklist, (int) $event['id']));
        $conflicts = $service->wrongTripConflicts($checklist, (int) $event['id']);
        $this->assertSame([101], array_map(static fn (array $trip): int => (int) $trip['id'], $conflicts));
        $this->assertSame('an active guest handoff', $conflicts[0]['conflict_label']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has an active guest handoff');
        $service->repairWrongTrip($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'target_trip_id' => 101, 'repair_reason' => 'Wrong reservation.'], 8);
    }

    public function testWrongTripCandidatesExcludeOtherVehiclesAndDistantTrips(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2026-09-03 08:00:00']);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'canceled_zero_payout'],
            ['id' => 2, 'code' => 'completed'],
            ['id' => 3, 'code' => 'booked'],
            ['id' => 4, 'code' => 'invalid'],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-03 09:00:00', 'deleted_at' => null],
            ['id' => 102, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-08 09:00:00', 'deleted_at' => null],
            ['id' => 103, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 1, 'starts_at' => '2026-09-03 09:01:00', 'deleted_at' => null],
            ['id' => 104, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 3, 'starts_at' => '2026-09-03 09:03:00', 'deleted_at' => null],
            ['id' => 105, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-03 09:04:00', 'deleted_at' => '2026-09-03 10:00:00'],
            ['id' => 106, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 4, 'starts_at' => '2026-09-03 09:05:00', 'deleted_at' => null],
            ['id' => 201, 'fleet_vehicle_id' => 20, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-03 09:00:00', 'deleted_at' => null],
        ]);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'pickup'];
        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 09:02:00', 'location_class' => 'home'], 7);
        [$event, $assessment] = $this->activeObservation();

        $this->assertSame([104, 101], array_map(static fn (array $trip): int => (int) $trip['id'], $service->wrongTripCandidates($checklist, (int) $event['id'])));

        $this->expectException(InvalidArgumentException::class);
        $service->repairWrongTrip($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'target_trip_id' => 201, 'repair_reason' => 'Wrong vehicle.'], 8);
    }

    public function testEnergyOnlyCorrectionPreservesReturnLocationAndCleanliness(): void
    {
        [$service, $checklist, $event, $assessment] = $this->recordObservation('return');

        $this->assertTrue($service->correctForChecklist($checklist, [
            'event_id' => $event['id'],
            'assessment_id' => $assessment['id'],
            'location_class' => '',
            'location_detail' => '',
            'cleanliness' => '',
            'energy_percent' => '25',
            'note' => '',
            'correction_reason' => 'Corrected energy reading.',
        ], 8));

        [$activeEvent, $activeAssessment] = $this->activeObservation();
        $this->assertSame('airport_hnl', $activeEvent['location_class']);
        $this->assertSame('International Garage L7', $activeEvent['location_detail']);
        $this->assertSame('dirty', $activeAssessment['cleanliness']);
        $this->assertSame(25, (int) $activeAssessment['energy_percent']);
        $this->assertSame((int) $activeEvent['id'], (int) $activeAssessment['trip_movement_event_id']);
        $this->assertSame('Initial return observation.', $activeEvent['note']);
        $this->assertSame('Initial return observation.', $activeAssessment['note']);
        $this->assertSame('operator_correction', $activeEvent['source']);
        $this->assertSame('operator_correction', $activeAssessment['source']);
        $this->assertSame(8, (int) $activeEvent['actor_user_id']);
        $this->assertSame(8, (int) $activeAssessment['actor_user_id']);
        $this->assertSame('airport_hnl', $this->repository->event((int) $event['id'])['location_class']);
        $this->assertSame('dirty', $this->repository->assessment((int) $assessment['id'])['cleanliness']);
        $this->assertSame(24, (int) $this->repository->assessment((int) $assessment['id'])['energy_percent']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->where(['action' => 'superseded', 'actor_user_id' => 8])->countAllResults());
        $audits = $this->connection->table('operational_fact_audits')->where('actor_user_id', 8)->orderBy('id', 'ASC')->get()->getResultArray();
        $eventAuditBefore = json_decode((string) $audits[0]['old_values'], true, 512, JSON_THROW_ON_ERROR);
        $eventAuditAfter = json_decode((string) $audits[0]['new_values'], true, 512, JSON_THROW_ON_ERROR);
        $assessmentAuditBefore = json_decode((string) $audits[1]['old_values'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('airport_hnl', $eventAuditBefore['location_class']);
        $this->assertSame('Corrected energy reading.', $eventAuditAfter['reason']);
        $this->assertSame((int) $activeEvent['id'], (int) $eventAuditAfter['replacement_id']);
        $this->assertSame('dirty', $assessmentAuditBefore['cleanliness']);
        $this->assertSame(24, (int) $assessmentAuditBefore['energy_percent']);
        $this->assertSame(1, $this->connection->table('trip_movement_events')->where('voided_at', null)->countAllResults());
        $this->assertSame(1, $this->connection->table('movement_assessments')->where('voided_at', null)->countAllResults());

        $facts = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);
        $this->assertSame('Airport HNL', $facts['location_class_label']);
        $this->assertSame('International Garage L7', $facts['location_detail_value']);
        $this->assertSame('Dirty', $facts['cleanliness_label']);
        $this->assertSame('25%', $facts['energy_value']);
    }

    public function testCleanlinessOnlyCorrectionPreservesReturnLocationAndEnergy(): void
    {
        [$service, $checklist, $event, $assessment] = $this->recordObservation('return');

        $service->correctForChecklist($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'cleanliness' => 'clean', 'correction_reason' => 'Rechecked cleanliness.'], 8);

        [$activeEvent, $activeAssessment] = $this->activeObservation();
        $this->assertSame('airport_hnl', $activeEvent['location_class']);
        $this->assertSame('International Garage L7', $activeEvent['location_detail']);
        $this->assertSame('clean', $activeAssessment['cleanliness']);
        $this->assertSame(24, (int) $activeAssessment['energy_percent']);
    }

    public function testLocationOnlyCorrectionPreservesAssessmentFacts(): void
    {
        [$service, $checklist, $event, $assessment] = $this->recordObservation('return');

        $service->correctForChecklist($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'location_class' => 'home', 'location_detail' => 'Fleet yard', 'correction_reason' => 'Corrected return location.'], 8);

        [$activeEvent, $activeAssessment] = $this->activeObservation();
        $this->assertSame('home', $activeEvent['location_class']);
        $this->assertSame('Fleet yard', $activeEvent['location_detail']);
        $this->assertSame('dirty', $activeAssessment['cleanliness']);
        $this->assertSame(24, (int) $activeAssessment['energy_percent']);
        $this->assertSame((int) $activeEvent['id'], (int) $activeAssessment['trip_movement_event_id']);
    }

    public function testNoteOnlyCorrectionPreservesAllStructuredFacts(): void
    {
        [$service, $checklist, $event, $assessment] = $this->recordObservation('return');

        $service->correctForChecklist($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'note' => 'Updated return note.', 'correction_reason' => 'Clarified note.'], 8);

        [$activeEvent, $activeAssessment] = $this->activeObservation();
        $this->assertSame('airport_hnl', $activeEvent['location_class']);
        $this->assertSame('International Garage L7', $activeEvent['location_detail']);
        $this->assertSame('dirty', $activeAssessment['cleanliness']);
        $this->assertSame(24, (int) $activeAssessment['energy_percent']);
        $this->assertSame('Updated return note.', $activeEvent['note']);
        $this->assertSame('Updated return note.', $activeAssessment['note']);
    }

    public function testPickupCorrectionPreservesUnchangedHandoffFacts(): void
    {
        [$service, $checklist, $event, $assessment] = $this->recordObservation('pickup');

        $service->correctForChecklist($checklist, ['event_id' => $event['id'], 'assessment_id' => $assessment['id'], 'energy_percent' => 25, 'correction_reason' => 'Corrected pickup energy.'], 8);

        [$activeEvent, $activeAssessment] = $this->activeObservation();
        $this->assertSame('actual_handoff', $activeEvent['event_code']);
        $this->assertSame('waikiki_hotel', $activeEvent['location_class']);
        $this->assertSame('Hotel lobby', $activeEvent['location_detail']);
        $this->assertSame('dirty', $activeAssessment['cleanliness']);
        $this->assertSame(25, (int) $activeAssessment['energy_percent']);
    }

    public function testLatestPickupFactsUseHandoffSemanticsAndElectricCharge(): void
    {
        $this->connection->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => 10, 'energy_kind' => 'electric', 'created_by' => 7, 'updated_by' => 7]);
        $eventId = $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-03 08:05:00', 'waikiki_hotel', null, 'checklist_operator', 7);
        $this->assessments->record(10, 100, $eventId, 'pickup', 'clean', 82, '2026-09-03 08:05:00', 'checklist_operator', 7);

        $facts = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);

        $this->assertSame('Guest handoff recorded', $facts['event_title']);
        $this->assertSame('Handoff location', $facts['location_label']);
        $this->assertNotSame('Current location', $facts['location_label']);
        $this->assertSame('Clean', $facts['cleanliness_label']);
        $this->assertSame('Charge', $facts['energy_label']);
        $this->assertSame('82%', $facts['energy_value']);
        $this->assertSame('Manual', $facts['source_label']);
        $this->assertSame('operator', $facts['actor_label']);
        $this->assertSame('clean', $facts['form_data']['cleanliness']);
        $this->assertSame(82, (int) $facts['form_data']['energy_percent']);
    }

    public function testTripFactsProjectEventOnlyLifecycleTruthWithOptionalLatestAssessmentEnrichment(): void
    {
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 10, 'deleted_at' => null],
            ['id' => 102, 'fleet_vehicle_id' => 10, 'deleted_at' => null],
            ['id' => 103, 'fleet_vehicle_id' => 10, 'deleted_at' => null],
            ['id' => 104, 'fleet_vehicle_id' => 10, 'deleted_at' => null],
        ]);
        $presenter = new MovementOperationalFactPresentationService($this->repository);

        $handoffId = $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-03 08:05:00', 'home', null, 'checklist_operator', 7);
        $eventOnlyPickup = $presenter->tripFacts(100)['pickup'];
        $this->assertSame($handoffId, (int) $eventOnlyPickup['event_id']);
        $this->assertNull($eventOnlyPickup['assessment_id']);
        $this->assertSame('Guest handoff recorded', $eventOnlyPickup['event_title']);
        $this->assertSame('Sep 3, 2026 8:05 AM', $eventOnlyPickup['occurred_at_label']);
        $this->assertSame('Not captured', $eventOnlyPickup['cleanliness_label']);
        $this->assertSame('Not captured', $eventOnlyPickup['energy_value']);

        $olderAssessmentId = $this->assessments->record(10, 100, $handoffId, 'pickup', 'dirty', 25, '2026-09-03 08:06:00', 'checklist_operator', 7);
        $latestAssessmentId = $this->assessments->record(10, 100, $handoffId, 'pickup', 'clean', 82, '2026-09-03 08:07:00', 'checklist_operator', 8);
        $enrichedRows = $this->repository->activeFactsForTrip(100);
        $this->assertCount(1, $enrichedRows);
        $this->assertSame($latestAssessmentId, (int) $enrichedRows[0]['assessment_id']);
        $this->assertNotSame($olderAssessmentId, (int) $enrichedRows[0]['assessment_id']);
        $enrichedPickup = $presenter->tripFacts(100)['pickup'];
        $this->assertSame('Clean', $enrichedPickup['cleanliness_label']);
        $this->assertSame('82%', $enrichedPickup['energy_value']);
        $this->assertSame('reviewer', $enrichedPickup['actor_label']);

        $recoveredId = $this->events->record(10, 101, 'vehicle_recovered', 'return', '2026-09-03 09:00:00', 'home', null, 'checklist_operator', 7);
        $recovered = $presenter->tripFacts(101)['return'];
        $this->assertSame($recoveredId, (int) $recovered['event_id']);
        $this->assertSame('Vehicle recovery recorded', $recovered['event_title']);
        $this->assertSame('Not captured', $recovered['energy_value']);

        $returnId = $this->events->record(10, 102, 'actual_return', 'return', '2026-09-03 09:05:00', 'home', null, 'checklist_operator', 7);
        $returned = $presenter->tripFacts(102)['return'];
        $this->assertSame($returnId, (int) $returned['event_id']);
        $this->assertSame('Actual return recorded', $returned['event_title']);

        $stagedId = $this->events->record(10, 103, 'guest_return_staged', 'return', '2026-09-03 09:10:00', 'airport_hnl', null, 'guest_report_received', 7, null, ['garage_code' => 'international', 'level' => 7, 'row' => 'F']);
        $staged = $presenter->tripFacts(103)['return'];
        $this->assertSame($stagedId, (int) $staged['event_id']);
        $this->assertSame('Guest Return Staged recorded', $staged['event_title']);
        $this->assertSame('Guest Report Received', $staged['source_label']);

        $voidedId = $this->events->record(10, 104, 'actual_return', 'return', '2026-09-03 09:15:00', 'home', null, 'checklist_operator', 7);
        $this->assertTrue($this->events->void($voidedId, 8, 'Synthetic void test.'));
        $this->assertSame(['pickup' => null, 'return' => null], $presenter->tripFacts(104));

        $this->connection->table('trip_movement_events')->insert([
            'company_id' => 2, 'fleet_vehicle_id' => 20, 'turo_trip_normalized_id' => 100,
            'event_code' => 'actual_return', 'movement_type' => 'return', 'occurred_at' => '2026-09-03 10:00:00',
            'source' => 'checklist_operator', 'actor_user_id' => 7,
        ]);
        $scoped = $presenter->tripFacts(100);
        $this->assertSame($handoffId, (int) $scoped['pickup']['event_id']);
        $this->assertNull($scoped['return']);
    }

    public function testRetroactiveHandoffCreatesEventOnlyTruthAndSupersedesPriorPosition(): void
    {
        $this->prepareActiveTrip(100, 10);
        $this->events->record(10, null, 'vehicle_positioned', null, '2026-09-17 12:00:00', 'home', 'Garage', 'checklist_operator', 7);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);

        $eventId = $service->recordRetroactiveHandoff(1, 100, [
            'occurred_at' => '2026-09-18T17:00',
            'note' => 'Historical pickup confirmed by operator.',
        ], 7);

        $event = $this->repository->event($eventId);
        $this->assertSame('actual_handoff', $event['event_code']);
        $this->assertSame('pickup', $event['movement_type']);
        $this->assertSame('2026-09-18 17:00:00', $event['occurred_at']);
        $this->assertGreaterThan($event['occurred_at'], $event['created_at']);
        $this->assertNull($event['location_class']);
        $this->assertSame('retroactive_trip_operator', $event['source']);
        $this->assertSame(7, (int) $event['actor_user_id']);
        $this->assertSame(0, $this->connection->table('movement_assessments')->where('trip_movement_event_id', $eventId)->countAllResults());

        $facts = (new MovementOperationalFactPresentationService($this->repository))->tripFacts(100)['pickup'];
        $this->assertSame('Guest handoff recorded', $facts['event_title']);
        $this->assertSame('Sep 18, 2026 5:00 PM', $facts['occurred_at_label']);
        $this->assertSame('Unknown', $facts['location_class_label']);
        $this->assertSame('Not captured', $facts['cleanliness_label']);
        $this->assertSame('Not captured', $facts['energy_value']);

        $location = (new CurrentVehicleLocationService($this->repository))->resolve(10, new DateTimeImmutable('2026-09-18 18:00:00'));
        $this->assertSame($eventId, (int) $location['event_id']);
        $this->assertSame(100, (int) $location['trip_id']);
        $this->assertSame('rented', $location['operational_state']);
        $this->assertSame('unknown', $location['location_class']);
    }

    public function testRetroactiveHandoffCreatesLinkedAssessmentOnlyWhenReadinessWasObserved(): void
    {
        $this->prepareActiveTrip(100, 10);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);

        $eventId = $service->recordRetroactiveHandoff(1, 100, [
            'occurred_at' => '2026-09-18T17:00',
            'location_class' => 'waikiki_hotel',
            'location_detail' => 'Front drive',
            'cleanliness' => 'clean',
            'energy_percent' => '82',
        ], 7);

        $assessment = $this->repository->assessmentForEventOrTrip($eventId, 100);
        $this->assertNotNull($assessment);
        $this->assertSame($eventId, (int) $assessment['trip_movement_event_id']);
        $this->assertSame('clean', $assessment['cleanliness']);
        $this->assertSame(82, (int) $assessment['energy_percent']);
        $this->assertSame('retroactive_trip_operator', $assessment['source']);
        $this->assertSame('waikiki_hotel', $this->repository->event($eventId)['location_class']);
    }

    public function testEventOnlyRetroactiveHandoffUsesExistingCorrectionWorkflowWithoutFabricatingAssessment(): void
    {
        $this->prepareActiveTrip(100, 10);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $eventId = $service->recordRetroactiveHandoff(1, 100, ['occurred_at' => '2026-09-18T17:00'], 7);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'];

        $this->assertTrue($service->correctForChecklist($checklist, [
            'event_id' => $eventId,
            'assessment_id' => 0,
            'occurred_at' => '2026-09-18T17:05',
            'note' => 'Corrected operator-confirmed handoff time.',
            'correction_reason' => 'Corrected the historical pickup time.',
        ], 8));

        $replacement = $this->events->activeForTrip(100, ['actual_handoff']);
        $this->assertNotNull($replacement);
        $this->assertNotSame($eventId, (int) $replacement['id']);
        $this->assertSame('2026-09-18 17:05:00', $replacement['occurred_at']);
        $this->assertSame('operator_correction', $replacement['source']);
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testRetroactiveHandoffRejectsDuplicatesReturnConflictsAndWrongCompany(): void
    {
        $this->prepareActiveTrip(100, 10);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $data = ['occurred_at' => '2026-09-18T17:00'];
        $service->recordRetroactiveHandoff(1, 100, $data, 7);

        try {
            $service->recordRetroactiveHandoff(1, 100, $data, 7);
            $this->fail('A duplicate handoff must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already recorded', $exception->getMessage());
        }

        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 90, 'starts_at' => '2026-09-18 10:00:00', 'ends_at' => '2026-09-19 04:00:00'],
            ['id' => 102, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 90, 'starts_at' => '2026-09-18 10:00:00', 'ends_at' => '2026-09-19 04:00:00'],
        ]);
        foreach ([[101, 'actual_return'], [102, 'vehicle_recovered']] as [$tripId, $eventCode]) {
            $this->events->record(10, $tripId, $eventCode, 'return', '2026-09-18 20:00:00', 'home', null, 'checklist_operator', 7);
            try {
                $service->recordRetroactiveHandoff(1, $tripId, $data, 7);
                $this->fail($eventCode . ' must block retroactive handoff.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('return or recovery fact', $exception->getMessage());
            }
        }

        $this->prepareActiveTrip(200, 20);
        try {
            $service->recordRetroactiveHandoff(1, 200, $data, 7);
            $this->fail('A cross-company handoff must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active fleet company', $exception->getMessage());
        }
    }

    public function testRetroactiveHandoffRejectsInvalidTimeLocationAndEnergyWithoutWriting(): void
    {
        $this->prepareActiveTrip(100, 10);
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $invalidPayloads = [
            [['occurred_at' => 'not-a-date'], 'valid Honolulu pickup'],
            [['occurred_at' => '2026-09-18T17:00', 'location_class' => 'invented_place'], 'valid handoff location'],
            [['occurred_at' => '2026-09-18T17:00', 'energy_percent' => '101'], 'between 0 and 100'],
        ];

        foreach ($invalidPayloads as [$payload, $message]) {
            try {
                $service->recordRetroactiveHandoff(1, 100, $payload, 7);
                $this->fail('Invalid handoff input must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testLatestReturnFactsUseReturnLocationAndFuelOrMissingEnergy(): void
    {
        $this->connection->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => 10, 'energy_kind' => 'gasoline', 'created_by' => 7, 'updated_by' => 7]);
        $eventId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-03 09:05:00', 'airport_hnl', null, 'checklist_operator', 7);
        $this->assessments->record(10, 100, $eventId, 'return', 'dirty', null, '2026-09-03 09:05:00', 'checklist_operator', 7);

        $facts = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);

        $this->assertSame('Actual return recorded', $facts['event_title']);
        $this->assertSame('Return location', $facts['location_label']);
        $this->assertSame('Dirty', $facts['cleanliness_label']);
        $this->assertSame('Fuel', $facts['energy_label']);
        $this->assertSame('Not captured', $facts['energy_value']);
    }

    #[DataProvider('locationPresentationProvider')]
    public function testLocationPresentationKeepsCanonicalClassAndOptionalDetail(string $eventCode, string $movementType, string $locationClass, ?string $locationDetail, string $heading, string $classLabel): void
    {
        $eventId = $this->events->record(10, 100, $eventCode, $movementType, '2026-09-03 09:05:00', $locationClass, $locationDetail, 'checklist_operator', 7);
        $this->assessments->record(10, 100, $eventId, $movementType, null, null, '2026-09-03 09:05:00', 'checklist_operator', 7);

        $facts = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);

        $this->assertSame($heading, $facts['location_label']);
        $this->assertSame($classLabel, $facts['location_class_label']);
        $this->assertSame($locationDetail, $facts['location_detail_value']);
    }

    /** @return array<string, array{string, string, string, ?string, string, string}> */
    public static function locationPresentationProvider(): array
    {
        return [
            'airport with detail' => ['actual_return', 'return', 'airport_hnl', 'International Garage L7 RF', 'Return location', 'Airport HNL'],
            'airport without detail' => ['actual_return', 'return', 'airport_hnl', null, 'Return location', 'Airport HNL'],
            'Waikiki handoff with detail' => ['actual_handoff', 'pickup', 'waikiki_hotel', 'Front drive', 'Handoff location', 'Waikiki Hotel'],
            'home' => ['actual_return', 'return', 'home', null, 'Return location', 'Home'],
            'unknown' => ['actual_return', 'return', 'unknown', null, 'Return location', 'Unknown'],
            'other delivery' => ['actual_return', 'return', 'other_delivery', null, 'Return location', 'Other delivery'],
        ];
    }

    public function testChecklistCaptureRollsBackEventWhenAssessmentIsInvalid(): void
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'];

        try {
            $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-01 10:00:00', 'location_class' => 'home', 'energy_percent' => 101], 7);
            $this->fail('Expected invalid energy to reject the capture.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Energy must be between 0 and 100.', $exception->getMessage());
        }

        $this->assertSame(0, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(0, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testTripAndVehicleMustShareOwnership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Trip does not belong to this vehicle and company.');

        $this->events->record(10, 200, 'actual_handoff', 'pickup', '2026-09-01 10:00:00', 'home', null, 'operator', 7);
    }

    public function testScheduledLocationBackfillIsDryRunByDefaultAndIdempotentWhenApplied(): void
    {
        $payload = json_encode(['pickup_location' => 'Fleet yard', 'return_location' => 'Hotel desk'], JSON_THROW_ON_ERROR);
        $this->connection->table('turo_trip_raw')->insert(['id' => 1, 'raw_payload' => $payload]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['turo_trip_raw_id' => 1]);
        $this->connection->table('airports')->insert(['id' => 5, 'code' => 'HNL']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 50, 'turo_trip_normalized_id' => 100, 'airport_id' => 5, 'movement_type' => 'pickup', 'scheduled_at' => '2026-09-01 10:00:00']);
        $service = new ScheduledLocationBackfillService($this->repository);

        $dryRun = $service->run();
        $this->assertSame(2, $dryRun['would_upsert']);
        $this->assertSame(0, $dryRun['upserted']);
        $this->assertSame(0, $this->connection->table('scheduled_movement_locations')->countAllResults());

        $applied = $service->run(true);
        $this->assertSame(2, $applied['upserted']);
        $this->assertSame(2, $this->connection->table('scheduled_movement_locations')->countAllResults());
        $pickup = $this->repository->scheduledLocation(100, 'pickup');
        $this->assertSame('airport_hnl', $pickup['location_class']);
        $this->assertSame(50, (int) $pickup['airport_movement_workflow_id']);
        $this->assertSame(0, $service->run(true)['would_upsert']);
    }

    public function testNextConfirmedTripIncludesOnlyEarliestFutureBookedTripWithDistinctStatusAndPickupFields(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'canceled_zero_payout'],
            ['id' => 2, 'code' => 'completed'],
            ['id' => 3, 'code' => 'booked'],
            ['id' => 4, 'code' => 'completed'],
            ['id' => 5, 'code' => 'in_progress'],
        ]);
        $this->connection->table('turo_import_batches')->insert(['id' => 1, 'import_status_lookup_value_id' => 4, 'source_filename' => 'future-trips.csv', 'completed_at' => '2026-08-31 12:00:00']);
        $this->connection->table('turo_trip_raw')->insert(['id' => 1, 'turo_import_batch_id' => 1, 'raw_payload' => '{}']);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 301, 'fleet_vehicle_id' => 10, 'turo_trip_raw_id' => null, 'trip_status_lookup_value_id' => 1, 'starts_at' => '2026-09-01 10:00:00'],
            ['id' => 302, 'fleet_vehicle_id' => 10, 'turo_trip_raw_id' => null, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-01 11:00:00'],
            ['id' => 304, 'fleet_vehicle_id' => 10, 'turo_trip_raw_id' => null, 'trip_status_lookup_value_id' => 5, 'starts_at' => '2026-09-01 12:00:00'],
            ['id' => 305, 'fleet_vehicle_id' => 10, 'turo_trip_raw_id' => 1, 'trip_status_lookup_value_id' => 3, 'starts_at' => '2026-09-03 02:00:00'],
            ['id' => 303, 'fleet_vehicle_id' => 10, 'turo_trip_raw_id' => 1, 'trip_status_lookup_value_id' => 3, 'starts_at' => '2026-09-03 02:00:00'],
        ]);
        $this->repository->upsertScheduledLocation(303, 10, 'pickup', [
            'location_class' => 'airport_hnl',
            'source_text' => 'HNL Terminal 2',
            'airport_id' => null,
            'airport_movement_workflow_id' => null,
            'classification_source' => 'explicit',
            'classification_status' => 'classified',
        ]);

        $trip = (new NextConfirmedTripService($this->repository))->forVehicle(10, new DateTimeImmutable('2026-09-01 00:00:00'));

        $this->assertSame(303, (int) $trip['id']);
        $this->assertSame('booked', $trip['trip_status_code']);
        $this->assertSame('completed', $trip['import_status_code']);
        $this->assertSame('2026-08-31 12:00:00', $trip['import_completed_at']);
        $this->assertSame('future-trips.csv', $trip['import_source_filename']);
        $this->assertSame('airport_hnl', $trip['pickup_location_class']);
        $this->assertSame('HNL Terminal 2', $trip['pickup_location_source_text']);
        $this->assertArrayNotHasKey('status_code', $trip);
        $this->assertSame('near_term', $trip['planning_horizon']);
    }

    public function testBoardContextReadsLatestEventEventAssessmentAndFullTripSchedule(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 11, 'code' => 'booked'],
            ['id' => 12, 'code' => 'completed'],
        ]);
        $this->connection->table('turo_import_batches')->insert(['id' => 11, 'import_status_lookup_value_id' => 12, 'completed_at' => '2026-09-02 06:00:00']);
        $this->connection->table('turo_trip_raw')->insert(['id' => 11, 'turo_import_batch_id' => 11, 'raw_payload' => '{}']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'turo_trip_raw_id' => 11,
            'trip_status_lookup_value_id' => 11,
            'guest_name' => 'Board Guest',
            'starts_at' => '2026-09-03 08:00:00',
            'ends_at' => '2026-09-05 17:00:00',
        ]);
        foreach (['pickup' => ['airport_hnl', 'HNL Terminal 2'], 'return' => ['home', 'Fleet yard']] as $movementType => [$locationClass, $sourceText]) {
            $this->repository->upsertScheduledLocation(100, 10, $movementType, [
                'location_class' => $locationClass,
                'source_text' => $sourceText,
                'airport_id' => null,
                'airport_movement_workflow_id' => null,
                'classification_source' => 'explicit',
                'classification_status' => 'classified',
            ]);
        }
        $handoffId = $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-03 08:05:00', 'airport_hnl', 'Terminal 2', 'operator', 7);
        $this->assessments->record(10, 100, $handoffId, 'pickup', 'clean', null, '2026-09-03 08:06:00', 'operator', 7);
        $positionedId = $this->events->record(10, 100, 'vehicle_positioned', null, '2026-09-03 09:00:00', 'home', 'Operator note', 'operator', 7);
        $returnId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-05 17:05:00', 'home', 'Fleet yard', 'operator', 7);
        $this->assessments->record(10, 100, $returnId, 'return', 'dirty', 25, '2026-09-05 17:06:00', 'operator', 7);

        $event = $this->repository->latestActiveMovementEvent(10, '2026-09-04 12:00:00');
        $lifecycleEvent = $this->repository->latestActiveLifecycleEvent(10, '2026-09-04 12:00:00');
        $assessment = $this->repository->assessmentForEventOrTrip((int) $lifecycleEvent['id'], 100);
        $schedule = $this->repository->tripSchedule(100);

        $this->assertSame($positionedId, (int) $event['id']);
        $this->assertSame($handoffId, (int) $lifecycleEvent['id']);
        $this->assertSame('actual_handoff', $lifecycleEvent['event_code']);
        $this->assertSame('airport_hnl', $lifecycleEvent['location_class']);
        $this->assertSame('Terminal 2', $lifecycleEvent['location_detail']);
        $this->assertNull($assessment['energy_percent']);
        $this->assertSame('booked', $schedule['trip_status_code']);
        $this->assertSame('completed', $schedule['import_status_code']);
        $this->assertSame('airport_hnl', $schedule['pickup_location_class']);
        $this->assertSame('home', $schedule['return_location_class']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function prepareActiveTrip(int $tripId, int $vehicleId): void
    {
        if ($this->connection->table('lookup_values')->where('id', 90)->countAllResults() === 0) {
            $this->connection->table('lookup_values')->insert(['id' => 90, 'code' => 'in_progress']);
        }
        $this->connection->table('turo_trips_normalized')->where('id', $tripId)->update([
            'fleet_vehicle_id' => $vehicleId,
            'trip_status_lookup_value_id' => 90,
            'starts_at' => '2026-09-17 19:00:00',
            'ends_at' => '2026-09-19 04:00:00',
        ]);
    }

    /** @return array{MovementOperationalFactService, array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    private function recordObservation(string $movementType): array
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => $movementType];
        $isPickup = $movementType === 'pickup';
        $service->recordForChecklist($checklist, [
            'occurred_at' => '2026-09-03 08:05:00',
            'location_class' => $isPickup ? 'waikiki_hotel' : 'airport_hnl',
            'location_detail' => $isPickup ? 'Hotel lobby' : 'International Garage L7',
            'cleanliness' => 'dirty',
            'energy_percent' => 24,
            'note' => 'Initial return observation.',
        ], 7);

        [$event, $assessment] = $this->activeObservation();
        return [$service, $checklist, $event, $assessment];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function activeObservation(): array
    {
        $event = $this->connection->table('trip_movement_events')->where('voided_at', null)->get()->getRowArray();
        $assessment = $this->connection->table('movement_assessments')->where('voided_at', null)->get()->getRowArray();
        $this->assertIsArray($event);
        $this->assertIsArray($assessment);
        return [$event, $assessment];
    }
}
