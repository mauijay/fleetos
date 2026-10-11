<?php

use App\Repositories\LookupRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\MovementScheduleAuthorityFixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** Invented four-trip chronology; no production facts or executable correction command. @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class HistoricalMovementCorrectionDesignTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private OperationalFactsRepository $repository;
    private MovementEventService $events;
    private MovementOperationalFactService $facts;
    private int $stageId;
    private int $assessmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->setPrefix('');
        VehicleDamageDatabaseFixture::migrate($this->connection);
        MovementScheduleAuthorityFixture::seed($this->connection);
        $this->repository = new OperationalFactsRepository($this->connection);
        $this->events = new MovementEventService($this->repository);
        $assessments = new MovementAssessmentService($this->repository);
        $this->facts = new MovementOperationalFactService($this->connection, $this->events, $assessments);
        $this->connection->table('movement_assessments')->where('fleet_vehicle_id', 10)->delete();
        $this->connection->table('trip_movement_events')->where('fleet_vehicle_id', 10)->delete();
        $lookups = new LookupRepository($this->connection);
        foreach ([100 => ['2026-09-28 08:00:00', '2026-10-02 18:00:00'], 103 => ['2026-10-09 09:00:00', '2026-10-10 08:00:00']] as $id => [$start, $end]) {
            $this->connection->table('turo_trips_normalized')->where('id', $id)->update([
                'starts_at' => $start, 'ends_at' => $end,
                'trip_status_lookup_value_id' => $lookups->valueId('trip_status', 'completed'),
            ]);
        }
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 104, 'fleet_vehicle_id' => 10, 'turo_trip_id' => 'SYNTHETIC-LATER-104',
            'turo_reservation_id' => 'SYNTHETIC-LATER-104', 'guest_name' => 'Synthetic Later Guest',
            'starts_at' => '2026-10-11 08:00:00', 'ends_at' => '2026-10-12 08:00:00',
            'trip_status_lookup_value_id' => $lookups->valueId('trip_status', 'booked'),
        ]);
        $this->connection->table('trip_movement_checklists')->insert([
            'id' => 104, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 104,
            'movement_type' => 'pickup', 'scheduled_at' => '2026-10-11 08:00:00', 'readiness_status' => 'not_started',
        ]);
        $this->events->record(10, 100, 'actual_handoff', 'pickup', '2026-09-28 08:00:00', 'home', null, 'operator', 7);
        $this->events->record(10, 100, 'vehicle_recovered', 'return', '2026-10-02 21:22:00', 'airport_hnl', null, 'operator', 7);
        $this->stageId = $this->events->record(10, 102, 'vehicle_staged', 'pickup', '2026-10-07 09:31:00', 'airport_hnl', null, 'operator', 7);
        $this->assessmentId = $assessments->record(10, 102, $this->stageId, 'pickup', 'clean', 80, '2026-10-07 09:31:00', 'operator', 7);
        $this->events->record(10, 103, 'actual_handoff', 'pickup', '2026-10-09 09:00:00', 'home', null, 'operator', 7);
        $this->events->record(10, 103, 'vehicle_recovered', 'return', '2026-10-10 08:00:00', 'home', null, 'operator', 7);
        $laterStage = $this->events->record(10, 104, 'vehicle_staged', 'pickup', '2026-10-10 09:30:00', 'airport_hnl', null, 'operator', 7);
        $assessments->record(10, 104, $laterStage, 'pickup', 'clean', 90, '2026-10-10 09:30:00', 'operator', 7);
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->connection->close();
        parent::tearDown();
    }

    public function testAuditedVoidPreservesLaterTripsAndCurrentAuthority(): void
    {
        $before = $this->laterState();
        $this->assertTrue($this->facts->voidInvalidFact($this->checklist(), [
            'event_id' => $this->stageId, 'assessment_id' => $this->assessmentId,
            'void_reason' => 'Synthetic invalid occurrence; earlier staging time cannot be established.',
        ], 7));
        $this->assertNotNull($this->repository->event($this->stageId)['voided_at']);
        $this->assertNotNull($this->repository->assessment($this->assessmentId)['voided_at']);
        $this->assertSame('2026-10-07 09:31:00', $this->repository->event($this->stageId)['occurred_at']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->countAllResults());
        $this->assertSame($before, $this->laterState());
    }

    public function testAuditedCorrectionWithInventedEvidencePreservesLaterAuthorityAndHistory(): void
    {
        $before = $this->laterState();
        $this->assertTrue($this->facts->correctForChecklist($this->checklist(), [
            'event_id' => $this->stageId, 'assessment_id' => $this->assessmentId,
            'occurred_at' => '2026-10-07 07:45:00',
            'correction_reason' => 'Synthetic only: independently verified staging AND assessment capture time.',
        ], 7));
        $replacement = $this->events->activeForTrip(102, ['vehicle_staged']);
        $this->assertSame($this->stageId, (int) $replacement['supersedes_event_id']);
        $this->assertSame('2026-10-07 07:45:00', $replacement['occurred_at']);
        $this->assertNotNull($this->repository->event($this->stageId)['voided_at']);
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->countAllResults());
        $this->assertSame($before, $this->laterState());
    }

    public function testExistingHistoricalHandoffCommandRejectsCompletedTripWithoutWrites(): void
    {
        $before = MovementScheduleAuthorityFixture::fingerprints($this->connection);
        try {
            $this->facts->recordRetroactiveHandoff(1, 102, [
                'occurred_at' => '2026-10-07 08:25:00', 'location_class' => 'other_delivery',
                'location_detail' => 'Synthetic hotel', 'note' => 'Synthetic historical evidence',
            ], 7);
            $this->fail('Completed historical trip needs a separately reviewed reconciliation mechanism.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active trip', $exception->getMessage());
        }
        $this->assertSame($before, MovementScheduleAuthorityFixture::fingerprints($this->connection));
    }

    public function testClosedHistoricalProjectionPreservesLaterState(): void
    {
        $before = $this->laterState();
        // Projection simulation only: this closing observation is invented, NOT inferred from completed status.
        $this->events->record(10, 102, 'vehicle_recovered', 'return', '2026-10-08 23:00:00', 'home', null, 'operator', 7, 'Synthetic independently evidenced closing fact');
        $this->simulateHandoff();
        $this->assertSame($before, $this->laterState());
    }

    public function testUnclosedHistoricalHandoffWouldStealCurrentCustodyAndMustNotBeRecommended(): void
    {
        $before = $this->laterState();
        $this->simulateHandoff();
        $after = $this->laterState();
        $this->assertSame('operator', $before['custody']['custody']);
        $this->assertSame('guest', $after['custody']['custody']);
        $this->assertSame(102, $after['custody']['active_trip_id']);
        $this->assertSame($before['later_rows'], $after['later_rows']);
        $this->assertSame($before['latest_location'], $after['latest_location']);
        $this->assertNotSame($before['position'], $after['position']);
    }

    private function simulateHandoff(): void
    {
        $this->facts->voidInvalidFact($this->checklist(), [
            'event_id' => $this->stageId, 'assessment_id' => $this->assessmentId,
            'void_reason' => 'Synthetic invalid staging occurrence; exact earlier time unknown.',
        ], 7);
        // Low-level fixture insertion examines projection impact, never bypasses a production command.
        $this->events->record(10, 102, 'actual_handoff', 'pickup', '2026-10-07 08:25:00', 'other_delivery', 'Synthetic hotel', 'retroactive_trip_operator', 7, 'Synthetic projection candidate only');
    }

    /** @return array<string, mixed> */
    private function checklist(): array
    {
        return ['exists' => true, 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 102, 'movement_type' => 'pickup'];
    }

    /** @return array<string, mixed> */
    private function laterState(): array
    {
        $asOf = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('Pacific/Honolulu'));
        $rows = [];
        foreach (['turo_trips_normalized', 'trip_movement_checklists', 'trip_movement_events', 'movement_assessments'] as $table) {
            $key = $table === 'turo_trips_normalized' ? 'id' : 'turo_trip_normalized_id';
            $rows[$table] = $this->connection->table($table)->whereIn($key, [103, 104])->orderBy('id')->get()->getResultArray();
        }

        return [
            'later_rows' => $rows,
            'custody' => (new CurrentVehicleCustodyService($this->repository))->resolve(10, $asOf),
            'position' => (new CurrentVehicleLocationService($this->repository))->resolve(10, $asOf),
            'latest_location' => $this->repository->latestLocationEvent(10, $asOf->format('Y-m-d H:i:s')),
            'readiness' => Services::movementReadinessReadService()->forCompany(1, [104], $asOf),
        ];
    }
}
