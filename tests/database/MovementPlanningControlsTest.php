<?php

use App\Database\Migrations\CreateMovementOperationalFacts;
use App\Database\Migrations\CreateMovementPlanningControls;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementLocationAliasService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\VehiclePositioningPlanService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-31-000015_CreateMovementOperationalFacts.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-09-03-000016_CreateMovementPlanningControls.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class MovementPlanningControlsTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private OperationalFactsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['vehicle_positioning_plans', 'movement_location_aliases', 'operational_fact_audits', 'vehicle_operational_capabilities', 'vehicle_operational_profiles', 'movement_assessments', 'trip_movement_events', 'scheduled_movement_locations', 'trip_movement_checklists', 'turo_trips_normalized', 'fleet_vehicles', 'movement_slice_sentinel'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, starts_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY, scheduled_at DATETIME NULL, completion_note TEXT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('movement_slice_sentinel') . ' (id INTEGER PRIMARY KEY, marker VARCHAR(20))');
        $this->connection->table('movement_slice_sentinel')->insert(['id' => 1, 'marker' => 'preserve']);
        (new CreateMovementOperationalFacts(Database::forge($this->connection)))->up();
        (new CreateMovementPlanningControls(Database::forge($this->connection)))->up();
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'deleted_at' => null],
            ['id' => 20, 'company_id' => 2, 'deleted_at' => null],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 100, 'fleet_vehicle_id' => 10, 'starts_at' => '2026-09-10 10:00:00', 'deleted_at' => null],
            ['id' => 200, 'fleet_vehicle_id' => 20, 'starts_at' => '2026-09-10 10:00:00', 'deleted_at' => null],
        ]);
        $this->repository = new OperationalFactsRepository($this->connection);
    }

    public function testMigrationIsAdditiveAndDownDropsOnlySliceTwoTables(): void
    {
        $migration = new CreateMovementPlanningControls(Database::forge($this->connection));
        $this->assertTrue($this->connection->tableExists('movement_location_aliases'));
        $this->assertTrue($this->connection->tableExists('vehicle_positioning_plans'));
        $this->assertContains('airport_garage_code', $this->connection->getFieldNames('trip_movement_events'));
        $this->assertContains('airport_parking_level', $this->connection->getFieldNames('trip_movement_events'));
        $this->assertContains('airport_parking_row', $this->connection->getFieldNames('trip_movement_events'));
        $this->assertContains('projection_source', $this->connection->getFieldNames('trip_movement_checklists'));
        $this->assertContains('projected_at', $this->connection->getFieldNames('trip_movement_checklists'));

        $migration->down();

        $this->assertFalse($this->connection->tableExists('movement_location_aliases'));
        $this->assertFalse($this->connection->tableExists('vehicle_positioning_plans'));
        $this->assertTrue($this->connection->tableExists('scheduled_movement_locations'));
        $this->assertNotContains('projection_source', $this->connection->getFieldNames('trip_movement_checklists'));
        $this->assertNotContains('airport_garage_code', $this->connection->getFieldNames('trip_movement_events'));
        $this->assertSame('preserve', $this->connection->table('movement_slice_sentinel')->where('id', 1)->get()->getRowArray()['marker']);
    }

    public function testStructuredHnlParkingPersistsOnMovementEvent(): void
    {
        $eventId = (new MovementEventService($this->repository))->record(
            10,
            100,
            'actual_return',
            'return',
            '2026-09-03 10:00:00',
            'airport_hnl',
            'Operator note remains separate.',
            'checklist_operator',
            7,
            'Recovered by operator.',
            ['garage_code' => 'international', 'level' => 7, 'row' => 'F'],
        );

        $event = $this->repository->event($eventId);
        $this->assertSame('international', $event['airport_garage_code']);
        $this->assertSame(7, (int) $event['airport_parking_level']);
        $this->assertSame('F', $event['airport_parking_row']);
        $this->assertSame('International Garage L7 RF', $event['location_detail']);
        $this->assertSame('Recovered by operator.', $event['note']);
        $this->assertSame(7, (int) $event['actor_user_id']);
    }

    public function testStructuredHnlParkingReplacesContradictoryLocationDetail(): void
    {
        $eventId = (new MovementEventService($this->repository))->record(
            10,
            100,
            'actual_return',
            'return',
            '2026-09-03 10:00:00',
            'airport_hnl',
            'International Garage L7 RF',
            'checklist_operator',
            7,
            'Parked near the elevator.',
            ['garage_code' => 'terminal_2', 'level' => 4, 'row' => 'M'],
        );

        $event = $this->repository->event($eventId);
        $this->assertSame('terminal_2', $event['airport_garage_code']);
        $this->assertSame(4, (int) $event['airport_parking_level']);
        $this->assertSame('M', $event['airport_parking_row']);
        $this->assertSame('Terminal 2 Garage L4 RM', $event['location_detail']);
        $this->assertSame('Parked near the elevator.', $event['note']);

        $replacementId = (new MovementEventService($this->repository))->correct($eventId, [
            'location_class' => 'airport_hnl',
            'location_detail' => 'Terminal 2 Garage L4 RM',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
        ], 8, 'Corrected the garage.');

        $original = $this->repository->event($eventId);
        $replacement = $this->repository->event($replacementId);
        $this->assertSame('Terminal 2 Garage L4 RM', $original['location_detail']);
        $this->assertNotNull($original['voided_at']);
        $this->assertSame($eventId, (int) $replacement['supersedes_event_id']);
        $this->assertSame('International Garage L7 RF', $replacement['location_detail']);
        $this->assertSame('international', $replacement['airport_garage_code']);
        $this->assertSame('Parked near the elevator.', $replacement['note']);
    }

    public function testExactCompanyAliasReclassifiesMatchingRowsAndPreservesSourceText(): void
    {
        foreach ([[100, 10], [200, 20]] as [$tripId, $vehicleId]) {
            $this->repository->upsertScheduledLocation($tripId, $vehicleId, 'pickup', [
                'location_class' => 'unknown',
                'source_text' => '  HNL   Delivery  ',
                'airport_id' => null,
                'airport_movement_workflow_id' => null,
                'classification_source' => 'unclassified',
                'classification_status' => 'pending',
            ]);
        }
        $service = new MovementLocationAliasService($this->repository);

        $aliasId = $service->save(1, 'HNL Delivery', 'airport_hnl', 'Operator confirmed exact location.', 7);

        $companyOne = $this->repository->scheduledLocation(100, 'pickup');
        $companyTwo = $this->repository->scheduledLocation(200, 'pickup');
        $this->assertSame('airport_hnl', $companyOne['location_class']);
        $this->assertSame('company_alias', $companyOne['classification_source']);
        $this->assertSame('  HNL   Delivery  ', $companyOne['source_text']);
        $this->assertSame('unknown', $companyTwo['location_class']);
        $this->assertSame($aliasId, (int) $this->connection->table('operational_fact_audits')->where('table_name', 'movement_location_aliases')->get()->getRowArray()['record_id']);
        $this->assertNull($service->match(1, 'HNL Delivery nearby'));
        $this->assertSame('airport_hnl', $service->match(1, " hnl\t delivery ")['location_class']);
    }

    public function testPositioningPlansSupersedeAndStaleBasisIsReadOnly(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);
        $trip = ['id' => 100, 'starts_at' => '2026-09-10 10:00:00', 'trip_status_code' => 'booked', 'pickup_location_class' => 'airport_hnl'];
        $first = $service->create(10, 'leave_at_airport', 'airport_hnl', 'operator_choice', 'Keep at HNL', 'confirmed', ['id' => 5], $trip, null, 7);
        $second = $service->create(10, 'retrieve_home', 'home', 'operator_choice', null, 'confirmed', ['id' => 5], $trip, '2026-09-20 00:00:00', 8);

        $this->assertNotSame($first, $second);
        $this->assertNotNull($this->connection->table('vehicle_positioning_plans')->where('id', $first)->get()->getRowArray()['invalidated_at']);
        $active = $service->active(10, ['id' => 5], $trip, new DateTimeImmutable('2026-09-04 00:00:00'));
        $this->assertSame($second, (int) $active['id']);
        $this->assertFalse($active['is_basis_stale']);
        $auditCount = $this->connection->table('operational_fact_audits')->countAllResults();

        $stale = $service->active(10, ['id' => 6], $trip, new DateTimeImmutable('2026-09-04 00:00:00'));

        $this->assertTrue($stale['is_basis_stale']);
        $this->assertSame($auditCount, $this->connection->table('operational_fact_audits')->countAllResults());
        $this->assertNull($service->active(10, ['id' => 5], $trip, new DateTimeImmutable('2026-09-21 00:00:00')));
    }

    public function testPositioningPlanRequiresReasonAndMatchingTarget(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positioning reason');
        $service->create(10, 'retrieve_home', 'home', ' ', null, 'confirmed', null, null, null, 7);
    }

    public function testPositioningPlanRejectsMismatchedActionTarget(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target location');
        $service->create(10, 'retrieve_home', 'airport_hnl', 'operator_choice', null, 'confirmed', null, null, null, 7);
    }

    public function testPositioningPlanNormalizesIrrelevantTransportation(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);

        $planId = $service->create(10, 'leave_at_airport', 'airport_hnl', 'trip_schedule', null, 'confirmed', null, null, null, 7);
        $plan = $this->connection->table('vehicle_positioning_plans')->where('id', $planId)->get()->getRowArray();

        $this->assertSame('not_applicable', $plan['transportation_state']);
    }

    public function testBasisChangesAreReadOnlyStaleSignalsAndExpiryRemovesActivePlan(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);
        $event = ['id' => 5];
        $trip = ['id' => 100, 'starts_at' => '2026-09-10 10:00:00', 'trip_status_code' => 'booked', 'pickup_location_class' => 'airport_hnl'];
        $planId = $service->create(10, 'leave_at_airport', 'airport_hnl', 'trip_schedule', null, 'not_applicable', $event, $trip, '2026-09-20 00:00:00', 7);
        $auditCount = $this->connection->table('operational_fact_audits')->countAllResults();

        $changedTrip = array_merge($trip, ['id' => 101]);
        $canceledTrip = null;
        $changedTime = array_merge($trip, ['starts_at' => '2026-09-10 12:00:00']);
        $changedLocation = array_merge($trip, ['pickup_location_class' => 'home']);

        $this->assertTrue($service->active(10, $event, $changedTrip, new DateTimeImmutable('2026-09-04'))['is_basis_stale']);
        $this->assertTrue($service->active(10, $event, $canceledTrip, new DateTimeImmutable('2026-09-04'))['is_basis_stale']);
        $this->assertTrue($service->active(10, $event, $changedTime, new DateTimeImmutable('2026-09-04'))['is_basis_stale']);
        $this->assertTrue($service->active(10, $event, $changedLocation, new DateTimeImmutable('2026-09-04'))['is_basis_stale']);
        $this->assertTrue($service->active(10, ['id' => 6], $trip, new DateTimeImmutable('2026-09-04'))['is_basis_stale']);
        $this->assertSame($auditCount, $this->connection->table('operational_fact_audits')->countAllResults());
        $this->assertNull($this->connection->table('vehicle_positioning_plans')->where('id', $planId)->get()->getRowArray()['invalidated_at']);
        $this->assertNull($service->active(10, $event, $trip, new DateTimeImmutable('2026-09-21')));
    }

    public function testActualMovementRecordAndCorrectionInvalidatePlansInsideWorkflow(): void
    {
        $plans = new VehiclePositioningPlanService($this->repository);
        $events = new MovementEventService($this->repository);
        $assessments = new MovementAssessmentService($this->repository);
        $service = new MovementOperationalFactService($this->connection, $events, $assessments, $plans);
        $checklist = ['exists' => true, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'movement_type' => 'return'];
        $firstPlanId = $plans->create(10, 'retrieve_home', 'home', 'operator_choice', null, 'confirmed', null, null, null, 7);

        $service->recordForChecklist($checklist, ['occurred_at' => '2026-09-03 10:00:00', 'location_class' => 'home'], 7);

        $firstPlan = $this->connection->table('vehicle_positioning_plans')->where('id', $firstPlanId)->get()->getRowArray();
        $this->assertSame('new_actual_movement_event', $firstPlan['invalidation_reason']);
        $this->assertSame(7, (int) $firstPlan['invalidated_by_user_id']);
        $event = $this->connection->table('trip_movement_events')->where('voided_at', null)->get()->getRowArray();
        $assessment = $this->connection->table('movement_assessments')->where('voided_at', null)->get()->getRowArray();
        $secondPlanId = $plans->create(10, 'hold_home_flexible', 'home', 'operator_choice', null, 'confirmed', $event, null, null, 8);

        $service->correctForChecklist($checklist, [
            'event_id' => $event['id'],
            'assessment_id' => $assessment['id'],
            'occurred_at' => '2026-09-03 10:05:00',
            'correction_reason' => 'Corrected event time.',
        ], 8);

        $secondPlan = $this->connection->table('vehicle_positioning_plans')->where('id', $secondPlanId)->get()->getRowArray();
        $this->assertSame('corrected_actual_movement_event', $secondPlan['invalidation_reason']);
        $this->assertSame(8, (int) $secondPlan['invalidated_by_user_id']);
        $this->assertSame(2, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame(2, $this->connection->table('movement_assessments')->countAllResults());
    }

    public function testActorlessInvalidationRetainsTruthWithoutFalseAudit(): void
    {
        $service = new VehiclePositioningPlanService($this->repository);
        $planId = $service->create(10, 'hold_home_flexible', 'home', 'operator_choice', null, 'unknown', null, null, null, 7);
        $auditCount = $this->connection->table('operational_fact_audits')->countAllResults();

        $this->assertSame(1, $service->invalidateForWrite(10, 'material_trip_reconciliation', null));

        $plan = $this->connection->table('vehicle_positioning_plans')->where('id', $planId)->get()->getRowArray();
        $this->assertSame('material_trip_reconciliation', $plan['invalidation_reason']);
        $this->assertNotNull($plan['invalidated_at']);
        $this->assertNull($plan['invalidated_by_user_id']);
        $this->assertSame($auditCount, $this->connection->table('operational_fact_audits')->countAllResults());
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
