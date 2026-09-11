<?php

use App\Database\Migrations\CreateIncidentalEarningsPlanAssignments;
use App\Database\Migrations\CreateTripIncidentalReviews;
use App\Repositories\TripIncidentalReviewRepository;
use App\Services\Fleet\IncidentalReviewUrgencyService;
use App\Services\Fleet\TripIncidentalReviewService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Incidentals;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-10-000017_CreateTripIncidentalReviews.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-09-10-000018_CreateIncidentalEarningsPlanAssignments.php';

/** @internal */
final class TripIncidentalReviewTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TripIncidentalReviewRepository $repository;
    private TripIncidentalReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $config = (new Database())->tests;
        $config['DBPrefix'] = 'i3b_';
        $this->connection = Database::connect($config, false);
        foreach (['trip_incidental_reviews', 'incidental_earnings_plan_assignments', 'incidental_review_policies', 'operational_fact_audits', 'vehicle_operational_profiles', 'turo_trips_normalized', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_code VARCHAR(80), display_name VARCHAR(150), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_operational_profiles') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, energy_kind VARCHAR(20))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80) NULL, guest_name VARCHAR(190), booked_at DATETIME NULL, ends_at DATETIME NULL, canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('operational_fact_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NULL, table_name VARCHAR(80), record_id INTEGER, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, actor_user_id INTEGER, created_at DATETIME NULL)');
        (new CreateTripIncidentalReviews(Database::forge($this->connection)))->up();
        (new CreateIncidentalEarningsPlanAssignments(Database::forge($this->connection)))->up();
        $this->connection->table('companies')->insertBatch([['id' => 1], ['id' => 2]]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'fleet_code' => 'Spaceship04', 'display_name' => 'Spaceship04'],
            ['id' => 11, 'company_id' => 1, 'fleet_code' => 'Gas01', 'display_name' => 'Gas01'],
            ['id' => 20, 'company_id' => 2, 'fleet_code' => 'OtherEV', 'display_name' => 'OtherEV'],
        ]);
        $this->connection->table('vehicle_operational_profiles')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 10, 'energy_kind' => 'electric'],
            ['id' => 2, 'fleet_vehicle_id' => 11, 'energy_kind' => 'gasoline'],
            ['id' => 3, 'fleet_vehicle_id' => 20, 'energy_kind' => 'electric'],
        ]);
        $this->insertTrip(100, 10, '2026-09-10 12:00:00');
        $this->insertTrip(101, 10, '2026-09-08 12:00:00');
        $this->insertTrip(102, 11, '2026-09-10 12:00:00');
        $this->insertTrip(200, 20, '2026-09-10 12:00:00');
        foreach ([[1, 'more_earnings', 'More earnings', 4320], [1, 'balanced', 'Balanced', 5760], [1, 'more_peace_of_mind', 'More peace of mind', 7200], [2, 'more_earnings', 'More earnings', 4320]] as $policy) {
            $this->insertPolicy(...$policy);
        }
        $settings = new Incidentals();
        $settings->activationEndedAtUtc = '2026-09-09 00:00:00';
        $settings->reviewDelayMinutes = 1440;
        $this->repository = new TripIncidentalReviewRepository($this->connection);
        $this->service = new TripIncidentalReviewService($this->repository, new IncidentalReviewUrgencyService(), $settings);
    }

    public function testCompletedEligibleTripCreatesExactlyOneReviewWithSeparate24HourTiming(): void
    {
        $asOf = $this->utc('2026-09-10 23:00:00');
        $this->assertTrue($this->service->projectTrip(100, 42, $asOf));
        $this->assertFalse($this->service->projectTrip(100, 42, $asOf));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 100)->get()->getRowArray();
        $this->assertSame('2026-09-11 22:00:00', $review['review_after_at_utc']);
        $this->assertSame(1440, (int) $review['review_delay_minutes_snapshot']);
        $this->assertSame('trip_projection', $review['creation_source']);
        $this->assertNull($review['filing_deadline_at_utc']);
        $this->assertSame(1, $this->connection->table('operational_fact_audits')->where(['record_id' => $review['id'], 'actor_user_id' => 42])->countAllResults());
    }

    public function testProjectionIsIdempotentEligibleAndBoundedByActivation(): void
    {
        $asOf = $this->utc('2026-09-11 23:00:00');
        $this->assertSame(3, $this->service->projectEligible(42, $asOf));
        $this->assertSame(0, $this->service->projectEligible(42, $asOf));
        $ids = array_map('intval', array_column($this->connection->table('trip_incidental_reviews')->orderBy('turo_trip_normalized_id')->get()->getResultArray(), 'turo_trip_normalized_id'));
        $this->assertSame([100, 102, 200], $ids);
        $this->assertNotContains(101, $ids, 'Pre-activation trips must not flood the queue.');
    }

    public function testPlanSpecificWindowsAndImmutableSnapshots(): void
    {
        foreach (['more_earnings' => ['2026-09-13 22:00:00', 4320], 'balanced' => ['2026-09-14 22:00:00', 5760], 'more_peace_of_mind' => ['2026-09-15 22:00:00', 7200]] as $plan => [$deadline, $minutes]) {
            $tripId = 300 + $minutes;
            $this->insertTrip($tripId, 10, '2026-09-10 12:00:00');
            $this->service->projectTrip($tripId, 42, $this->utc('2026-09-10 23:00:00'));
            $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', $tripId)->get()->getRowArray();
            $this->service->confirmPlan(1, (int) $review['id'], ['earnings_plan_code' => $plan, 'selection_reason' => 'Confirmed in Turo.'], 42);
            $review = $this->repository->review(1, (int) $review['id']);
            $this->assertSame($deadline, $review['filing_deadline_at_utc']);
            $this->assertSame($minutes, (int) $review['filing_window_minutes_snapshot']);
        }
        $first = $this->connection->table('trip_incidental_reviews')->where('earnings_plan_code_snapshot', 'more_earnings')->get()->getRowArray();
        $this->connection->table('incidental_review_policies')->where('id', $first['incidental_review_policy_id'])->update(['filing_window_minutes' => 9999]);
        $unchanged = $this->repository->review(1, (int) $first['id']);
        $this->assertSame('2026-09-13 22:00:00', $unchanged['filing_deadline_at_utc']);
        $this->assertSame(4320, (int) $unchanged['filing_window_minutes_snapshot']);
    }

    public function testEffectiveDatedVehicleAssignmentPrecedesFleetDefaultAndSnapshotsAutomatically(): void
    {
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_earnings',
            'effective_from' => '2026-08-01T00:00', 'assignment_reason' => 'Current fleet-wide Turo plan.',
        ], 42);
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'vehicle', 'fleet_vehicle_id' => 10, 'earnings_plan_code' => 'balanced',
            'effective_from' => '2026-08-15T00:00', 'assignment_reason' => 'Vehicle-specific Turo plan.',
        ], 42);
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_peace_of_mind',
            'effective_from' => '2026-08-20T00:00', 'assignment_reason' => 'Newer fleet-wide plan.',
        ], 42);
        $this->insertTrip(150, 10, '2026-09-10 12:00:00', '2026-09-01 09:00:00');

        $this->assertTrue($this->service->projectTrip(150, 42, $this->utc('2026-09-10 23:00:00')));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 150)->get()->getRowArray();
        $this->assertSame('balanced', $review['earnings_plan_code_snapshot']);
        $this->assertSame('2026-09-14 22:00:00', $review['filing_deadline_at_utc']);
        $this->assertStringContainsString('Vehicle override assignment #', (string) $review['plan_source_reference']);

        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'vehicle', 'fleet_vehicle_id' => 10, 'earnings_plan_code' => 'more_peace_of_mind',
            'effective_from' => '2026-09-05T00:00', 'assignment_reason' => 'Later vehicle plan change.',
        ], 42);
        $unchanged = $this->repository->review(1, (int) $review['id']);
        $this->assertSame('balanced', $unchanged['earnings_plan_code_snapshot']);
        $this->assertSame('2026-09-14 22:00:00', $unchanged['filing_deadline_at_utc']);
        $this->assertSame(4, $this->connection->table('incidental_earnings_plan_assignments')->countAllResults());
    }

    public function testFleetDefaultBackfillsOnlyUnresolvedReviewsCoveredByKnownBookedTime(): void
    {
        $this->insertTrip(151, 10, '2026-09-10 12:00:00', '2026-09-02 08:00:00');
        $this->insertTrip(152, 10, '2026-09-10 13:00:00', null);
        $this->insertTrip(153, 10, '2026-09-10 14:00:00', '2026-08-01 08:00:00');
        foreach ([151, 152, 153] as $tripId) {
            $this->service->projectTrip($tripId, 42, $this->utc('2026-09-11 01:00:00'));
        }

        $resolved = $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_earnings',
            'effective_from' => '2026-09-01T00:00', 'assignment_reason' => 'Confirmed fleet default in Turo.',
        ], 42);
        $this->assertSame(1, $resolved);
        $reviews = $this->connection->table('trip_incidental_reviews')->orderBy('turo_trip_normalized_id')->get()->getResultArray();
        $this->assertSame('more_earnings', $reviews[0]['earnings_plan_code_snapshot']);
        $this->assertNull($reviews[1]['earnings_plan_code_snapshot'], 'Unknown booked_at must remain Plan needed.');
        $this->assertNull($reviews[2]['earnings_plan_code_snapshot'], 'Trips booked before the assignment must remain Plan needed.');
        $this->assertSame(1, $this->connection->table('operational_fact_audits')->where('action', 'earnings_plan_auto_resolved')->countAllResults());
    }

    public function testLaterBookedAtImportResolvesAnExistingUnresolvedReviewWithoutRecreatingIt(): void
    {
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_earnings',
            'effective_from' => '2026-09-01T00:00', 'assignment_reason' => 'Confirmed fleet default in Turo.',
        ], 42);
        $this->insertTrip(156, 10, '2026-09-10 12:00:00');
        $this->assertTrue($this->service->projectTrip(156, 42, $this->utc('2026-09-10 23:00:00')));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 156)->get()->getRowArray();
        $this->assertNull($review['earnings_plan_code_snapshot']);

        $this->connection->table('turo_trips_normalized')->where('id', 156)->update(['booked_at' => '2026-09-02 08:00:00']);
        $this->assertFalse($this->service->projectTrip(156, 42, $this->utc('2026-09-10 23:00:00')));
        $resolved = $this->repository->review(1, (int) $review['id']);
        $this->assertSame('more_earnings', $resolved['earnings_plan_code_snapshot']);
        $this->assertSame(1, $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 156)->countAllResults());
    }

    public function testManualTripCorrectionOverridesSnapshotAndRequiresReasonAndAuditActor(): void
    {
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_earnings',
            'effective_from' => '2026-08-01T00:00', 'assignment_reason' => 'Confirmed fleet default.',
        ], 42);
        $this->insertTrip(154, 10, '2026-09-10 12:00:00', '2026-09-01 09:00:00');
        $this->service->projectTrip(154, 42, $this->utc('2026-09-10 23:00:00'));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 154)->get()->getRowArray();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->confirmPlan(1, (int) $review['id'], ['earnings_plan_code' => 'balanced'], 77);
        } finally {
            $this->assertSame('more_earnings', $this->repository->review(1, (int) $review['id'])['earnings_plan_code_snapshot']);
        }
    }

    public function testManualTripCorrectionRecordsNewSnapshotReasonAndActor(): void
    {
        $this->service->recordEarningsPlanAssignment(1, [
            'scope' => 'fleet', 'earnings_plan_code' => 'more_earnings',
            'effective_from' => '2026-08-01T00:00', 'assignment_reason' => 'Confirmed fleet default.',
        ], 42);
        $this->insertTrip(155, 10, '2026-09-10 12:00:00', '2026-09-01 09:00:00');
        $this->service->projectTrip(155, 42, $this->utc('2026-09-10 23:00:00'));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 155)->get()->getRowArray();
        $this->service->confirmPlan(1, (int) $review['id'], [
            'earnings_plan_code' => 'balanced', 'selection_reason' => 'Trip was individually changed in Turo.',
            'source_reference' => 'Turo trip 155',
        ], 77);

        $corrected = $this->repository->review(1, (int) $review['id']);
        $this->assertSame('balanced', $corrected['earnings_plan_code_snapshot']);
        $this->assertSame('2026-09-14 22:00:00', $corrected['filing_deadline_at_utc']);
        $this->assertSame('Trip was individually changed in Turo.', $corrected['plan_selection_reason']);
        $this->assertSame(77, (int) $corrected['plan_confirmed_by_user_id']);
        $this->assertSame(1, $this->connection->table('operational_fact_audits')->where(['action' => 'earnings_plan_corrected', 'actor_user_id' => 77])->countAllResults());
    }

    public function testUnknownPlanReadOnlyQueueTransitionsAndOverdueVisibility(): void
    {
        $this->service->projectTrip(100, 42, $this->utc('2026-09-10 23:00:00'));
        $before = $this->connection->table('trip_incidental_reviews')->get()->getResultArray();
        $waiting = $this->service->index(1, 'all', $this->utc('2026-09-11 21:59:59'))['reviews'][0];
        $due = $this->service->index(1, 'all', $this->utc('2026-09-11 22:00:00'))['reviews'][0];
        $this->assertSame('waiting', $waiting['urgency']['code']);
        $this->assertSame('due', $due['urgency']['code']);
        $this->assertSame('action_required', $due['display_status']);
        $this->assertNull($due['filing_deadline_at_utc'], 'Unknown plan must not invent a deadline.');
        $this->assertSame($before, $this->connection->table('trip_incidental_reviews')->get()->getResultArray(), 'GET read models must not write.');

        $reviewId = (int) $due['id'];
        $this->service->confirmPlan(1, $reviewId, ['earnings_plan_code' => 'more_earnings', 'selection_reason' => 'Confirmed in Turo.'], 42);
        $overdue = $this->service->index(1, 'overdue', $this->utc('2026-09-13 22:00:01'))['reviews'];
        $this->assertCount(1, $overdue);
        $this->assertSame('overdue_unconfirmed', $overdue[0]['urgency']['code']);
    }

    public function testScheduledProjectionPersistsWaitingToActionRequiredWithoutGetWrites(): void
    {
        $this->assertTrue($this->service->projectTrip(100, 42, $this->utc('2026-09-10 23:00:00')));
        $review = $this->connection->table('trip_incidental_reviews')->where('turo_trip_normalized_id', 100)->get()->getRowArray();
        $this->assertSame('waiting_for_review', $review['status']);
        $this->service->projectEligible(null, $this->utc('2026-09-11 23:00:00'));
        $review = $this->repository->review(1, (int) $review['id']);
        $this->assertSame('action_required', $review['status']);
    }

    public function testBothCompletionActionsCloseAndAuditWithinCompany(): void
    {
        $this->service->projectTrip(100, 42, $this->utc('2026-09-11 23:00:00'));
        $this->service->projectTrip(200, 77, $this->utc('2026-09-11 23:00:00'));
        $review = $this->connection->table('trip_incidental_reviews')->where('company_id', 1)->get()->getRowArray();
        $other = $this->connection->table('trip_incidental_reviews')->where('company_id', 2)->get()->getRowArray();
        $this->service->complete(1, (int) $review['id'], 'invoice_sent', ['reference' => 'Turo trip 100'], 42, $this->utc('2026-09-12 00:00:00'));
        $this->service->complete(2, (int) $other['id'], 'no_invoice_needed', ['note' => 'No incidentals populated.'], 77, $this->utc('2026-09-12 00:00:00'));
        $this->assertSame('invoice_sent', $this->repository->review(1, (int) $review['id'])['status']);
        $this->assertSame('no_invoice_needed', $this->repository->review(2, (int) $other['id'])['status']);
        $this->assertNull($this->repository->review(2, (int) $review['id']));
        $this->assertSame(2, $this->connection->table('operational_fact_audits')->whereIn('action', ['invoice_sent', 'no_invoice_needed'])->countAllResults());
    }

    public function testQueueQueriesRemainFixedAndSummaryExcludesWaitingAndComplete(): void
    {
        $this->service->projectTrip(100, 42, $this->utc('2026-09-10 23:00:00'));
        $queryCount = 0;
        $listener = static function () use (&$queryCount): void {
            ++$queryCount;
        };
        Events::on('DBQuery', $listener);
        try {
            $this->service->index(1, 'all', $this->utc('2026-09-11 23:00:00'));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame(5, $queryCount);
        $this->assertSame(0, $this->repository->attentionSummary(1, '2026-09-11 21:00:00')['total']);
        $this->assertSame(1, $this->repository->attentionSummary(1, '2026-09-11 23:00:00')['total']);
    }

    public function testAssignmentMigrationRollbackDropsOnlyItsAdditiveTable(): void
    {
        (new CreateIncidentalEarningsPlanAssignments(Database::forge($this->connection)))->down();
        $this->assertFalse($this->connection->tableExists('incidental_earnings_plan_assignments'));
        $this->assertTrue($this->connection->tableExists('trip_incidental_reviews'));
        $this->assertTrue($this->connection->tableExists('incidental_review_policies'));
    }

    private function insertTrip(int $id, int $vehicleId, string $endsAt, ?string $bookedAt = null): void
    {
        $this->connection->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => $vehicleId, 'turo_trip_id' => 'trip-' . $id, 'turo_reservation_id' => 'reservation-' . $id, 'guest_name' => 'Jacob', 'booked_at' => $bookedAt, 'ends_at' => $endsAt]);
    }

    private function insertPolicy(int $companyId, string $code, string $label, int $minutes): void
    {
        $this->connection->table('incidental_review_policies')->insert(['company_id' => $companyId, 'earnings_plan_code' => $code, 'display_name' => $label, 'rule_version' => 'test-v1', 'effective_from_at_utc' => '2026-01-01 00:00:00', 'filing_window_minutes' => $minutes, 'status' => 'active', 'source_reference' => 'Test host terms', 'source_note' => 'Test source']);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function table(string $name): string
    {
        return $this->connection->getPrefix() . $name;
    }
}
