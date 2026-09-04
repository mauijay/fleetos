<?php

use App\Database\Migrations\CreateMovementOperationalFacts;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactPresentationService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\ScheduledLocationBackfillService;
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
        foreach (['operational_fact_audits', 'vehicle_operational_capabilities', 'vehicle_operational_profiles', 'movement_assessments', 'trip_movement_events', 'scheduled_movement_locations', 'airport_movement_workflows', 'airports', 'turo_trips_normalized', 'turo_trip_raw', 'turo_import_batches', 'lookup_values', 'fleet_vehicles', 'users'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('users') . ' (id INTEGER PRIMARY KEY, username VARCHAR(30) NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY, import_status_lookup_value_id INTEGER NULL, source_filename VARCHAR(190) NULL, completed_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trip_raw') . ' (id INTEGER PRIMARY KEY, turo_import_batch_id INTEGER NULL, raw_payload TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('airports') . ' (id INTEGER PRIMARY KEY, code VARCHAR(20))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, airport_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, turo_trip_raw_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, guest_name VARCHAR(190) NULL, starts_at DATETIME NULL, ends_at DATETIME NULL, deleted_at DATETIME NULL)');
        (new CreateMovementOperationalFacts(Database::forge($this->connection)))->up();
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

    public function testCurrentLocationUsesLatestNonVoidedEventAtOrBeforeAsOf(): void
    {
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-01 10:00:00', 'home', 'Yard', 'operator', 7);
        $this->events->record(10, 100, 'vehicle_positioned', 'pickup', '2026-09-01 15:00:00', 'airport_hnl', 'Terminal 2', 'operator', 7);

        $location = (new CurrentVehicleLocationService($this->repository))->resolve(10, new DateTimeImmutable('2026-09-01 12:00:00'));

        $this->assertSame('home', $location['location_class']);
        $this->assertSame('Yard', $location['location_detail']);
        $this->assertSame(7200, $location['age_seconds']);
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
        $this->assertSame('airport_hnl', $activeEvent['location_class']);
        $this->assertSame('International Garage L7', $activeEvent['location_detail']);
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

    public function testLatestReturnFactsUseCurrentLocationAndFuelOrMissingEnergy(): void
    {
        $this->connection->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => 10, 'energy_kind' => 'gasoline', 'created_by' => 7, 'updated_by' => 7]);
        $eventId = $this->events->record(10, 100, 'actual_return', 'return', '2026-09-03 09:05:00', 'airport_hnl', null, 'checklist_operator', 7);
        $this->assessments->record(10, 100, $eventId, 'return', 'dirty', null, '2026-09-03 09:05:00', 'checklist_operator', 7);

        $facts = (new MovementOperationalFactPresentationService($this->repository))->latestForTrip(100);

        $this->assertSame('Actual return recorded', $facts['event_title']);
        $this->assertSame('Current location', $facts['location_label']);
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
            'airport with detail' => ['actual_return', 'return', 'airport_hnl', 'International Garage L7 RF', 'Current location', 'Airport HNL'],
            'airport without detail' => ['actual_return', 'return', 'airport_hnl', null, 'Current location', 'Airport HNL'],
            'Waikiki handoff with detail' => ['actual_handoff', 'pickup', 'waikiki_hotel', 'Front drive', 'Handoff location', 'Waikiki Hotel'],
            'home' => ['actual_return', 'return', 'home', null, 'Current location', 'Home'],
            'unknown' => ['actual_return', 'return', 'unknown', null, 'Current location', 'Unknown'],
            'other delivery' => ['actual_return', 'return', 'other_delivery', null, 'Current location', 'Other delivery'],
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

    /** @return array{MovementOperationalFactService, array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    private function recordObservation(string $movementType): array
    {
        $service = new MovementOperationalFactService($this->connection, $this->events, $this->assessments);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => $movementType];
        $service->recordForChecklist($checklist, [
            'occurred_at' => '2026-09-03 08:05:00',
            'location_class' => 'airport_hnl',
            'location_detail' => 'International Garage L7',
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
