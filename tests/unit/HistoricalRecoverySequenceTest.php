<?php

use App\Repositories\HistoricalMissingRecoveryRepairRepository;
use App\Repositories\LookupRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\GuestCommitmentProjectionService;
use App\Services\Fleet\HistoricalMissingRecoveryProjection;
use App\Services\Fleet\HistoricalMissingRecoveryRepairService;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GuestCommitmentProjectionFixture;
use Tests\Support\HistoricalMissingRecoveryFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture as Snapshot;
use Tests\Support\HistoricalRecoveryExtraFixture as Extra;
use Tests\Support\VehicleDamageDatabaseFixture;

/** Existing workflows, separate root commits, with all external observers suspended. @internal */
final class HistoricalRecoverySequenceTest extends TestCase
{
    private const MIDDLE = 9303;
    private const FINAL = 9304;
    private BaseConnection $db;
    private array $request;
    private OperationalFactsRepository $facts;
    private MovementOperationalFactService $writer;
    private array $checklists = [];
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true], false);
        VehicleDamageDatabaseFixture::migrate($this->db);
        $this->request = Fixture::seed($this->db);
        $this->now = new DateTimeImmutable();
        $lookups = new LookupRepository($this->db);
        $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['trip_status_lookup_value_id' => $lookups->valueId('trip_status', 'completed')]);
        $this->request['assessment']['cleanliness'] = null;
        Extra::seed($this->db);
        foreach ([[self::MIDDLE, '2023-06-10 12:00:00', '2023-06-11 15:00:00', 'completed'], [self::FINAL, '2023-06-12 14:00:00', '2023-06-15 14:00:00', 'in_progress']] as [$id, $start, $end, $status]) {
            $this->db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => Fixture::VEHICLE, 'turo_trip_id' => 'SYNTHETIC-SEQUENCE-' . $id,
                'turo_reservation_id' => 'SYNTHETIC-SEQUENCE-RES-' . $id, 'starts_at' => $start, 'ends_at' => $end, 'trip_status_lookup_value_id' => $lookups->valueId('trip_status', $status)]);
        }
        $this->facts = new OperationalFactsRepository($this->db);
        $events = new MovementEventService($this->facts);
        $this->writer = new MovementOperationalFactService($this->db, $events, new MovementAssessmentService($this->facts));
        $repository = new MovementChecklistRepository($this->db);
        foreach ([self::MIDDLE, self::FINAL] as $trip) {
            foreach (['pickup', 'return'] as $type) {
                $row = (new TripMovementChecklistService($repository))->ensureForMovement($this->facts->tripSchedule($trip), $type);
                $this->checklists[$trip][$type] = $repository->checklistForCompany(Fixture::COMPANY, (int) $row['id']) + ['exists' => true];
            }
        }
        // A real future purchased obligation and manual commitment must survive
        // all seven facts, including physical-work deferral during final custody.
        Extra::seed($this->db, Fixture::FUTURE);
        GuestCommitmentProjectionFixture::manual($this->db, ['company_id' => Fixture::COMPANY, 'turo_trip_normalized_id' => Fixture::FUTURE,
            'created_by_user_id' => Fixture::ACTOR, 'updated_by_user_id' => Fixture::ACTOR]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[DataProvider('stopPoints')]
    public function testSevenFactsAndFailureAfterEachCommitRetainExactIntermediateAuthority(int $stop): void
    {
        $snapshot = Snapshot::snapshot($this->db);
        $future = $this->commercialFuture();
        $baselineEvents = $this->vehicleEvents();
        $committed = [];
        // The harness has no external readers or background writers. Production
        // must independently prove that fence before using these workflows.
        $expected = [1 => ['operator', null], ['operator', self::MIDDLE], ['guest', self::MIDDLE], ['guest', self::MIDDLE], ['operator', null], ['operator', self::FINAL], ['guest', self::FINAL]];
        for ($step = 1; $step <= 7; $step++) {
            // The window stays externally suspended for every intermediate commit.
            $this->assertSame([...$baselineEvents, ...$committed], $this->vehicleEvents(), 'No unexpected movement since last gate.');
            $this->assertSame(0, $this->db->transDepth, 'Each deployed-style workflow owns its transaction.');
            $this->assertPreserved($snapshot);
            $beforeStep = Snapshot::snapshot($this->db);
            $this->applyStep($step);
            $events = $this->vehicleEvents();
            $this->assertCount(count($baselineEvents) + $step, $events);
            $last = $events[array_key_last($events)];
            $this->assertSame($this->spec()[$step][0], $last['event_code']);
            $this->assertSame($this->spec()[$step][1], (int) $last['turo_trip_normalized_id']);
            $this->assertSame($this->spec()[$step][2], $last['occurred_at']);
            $committed[] = $last;
            $this->assertSame([...$baselineEvents, ...$committed], $events);
            $this->assertSame(0, $this->db->transDepth, 'No outer transaction or hidden partial workflow.');
            $custody = (new CurrentVehicleCustodyService($this->facts))->resolve(Fixture::VEHICLE);
            $this->assertSame($expected[$step][0], $custody['custody']);
            $this->assertSame($expected[$step][1], $custody['active_trip_id']);
            $this->assertSame((int) $last['id'], $custody['basis_event_id']);
            $this->assertFalse($custody['integrity_conflict']);
            $this->assertSame([], $custody['conflicting_guest_states']);
            $position = (new CurrentVehicleLocationService($this->facts))->resolve(Fixture::VEHICLE);
            $this->assertSame((int) $last['id'], (int) $position['event_id']);
            if ($step === 4) {
                $this->assertSame('unverified_guest_report', $position['position_semantics']);
                $this->assertSame('unknown', $position['location_class']);
                $this->assertSame('G', $position['reported_airport_parking_row']);
            } else {
                $this->assertSame($last['location_class'], $position['location_class']);
            }
            $this->assertSame(count($snapshot['trip_movement_events']) + $step, $this->db->table('trip_movement_events')->countAllResults());
            $this->assertSame(count($snapshot['movement_assessments']) + [1 => 1, 2, 2, 2, 3, 4, 4][$step], $this->db->table('movement_assessments')->countAllResults());
            $this->assertClosedIntervals($step);
            $this->assertPreserved($snapshot);
            $this->assertSame($future, $this->commercialFuture());
            if ($step === 2 || $step === 3) {
                $this->assertSame('airport_hnl', $last['location_class']);
                $this->assertSame('Synthetic HNL International Garage; pickup bay unknown', $last['location_detail']);
                foreach (['airport_garage_code', 'airport_parking_level', 'airport_parking_row'] as $key) {
                    $this->assertNull($last[$key], 'Do not borrow a later return bay.');
                }
            }
            if ($step === 4) {
                $this->assertStringContainsString('guest reported 83%', $last['note']);
                $this->assertSame('guest_reported_parked_time', $last['source']);
                $this->assertSame($beforeStep['movement_assessments'], Snapshot::snapshot($this->db)['movement_assessments']);
            }
            if ($step === 5) {
                $assessment = $this->db->table('movement_assessments')->where('trip_movement_event_id', $last['id'])->get()->getRowArray();
                $this->assertNull($assessment['energy_percent']);
                $this->assertNull($assessment['cleanliness']);
                $this->assertStringContainsString('Energy unknown:', $assessment['note']);
                $this->assertSame('international', $last['airport_garage_code']);
                $this->assertSame(5, (int) $last['airport_parking_level']);
                $this->assertSame('G', $last['airport_parking_row']);
            }
            if ($stop === $step) {
                // Simulate the process dying AFTER a successful commit. Read-only
                // diagnosis identifies that exact committed prefix; nothing retries
                // or rolls it back, and no observer is re-enabled.
                $checkpoint = Snapshot::snapshot($this->db);
                try {
                    throw new RuntimeException('Synthetic interruption after committed step ' . $step);
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString((string) $step, $e->getMessage());
                    $this->assertSame($checkpoint, Snapshot::snapshot($this->db));
                    $this->assertSame($custody, (new CurrentVehicleCustodyService($this->facts))->resolve(Fixture::VEHICLE));
                    $this->assertSame($events, $this->vehicleEvents());
                    return;
                }
            }
        }
        $position = (new CurrentVehicleLocationService($this->facts))->resolve(Fixture::VEHICLE);
        $this->assertSame('international', $position['airport_garage_code']);
        $this->assertSame(4, (int) $position['airport_parking_level']);
        $this->assertSame('H', $position['airport_parking_row']);
        $this->assertSame((int) $committed[6]['id'], (int) $position['event_id']);
        $stage = $committed[5];
        $assessment = $this->db->table('movement_assessments')->where('trip_movement_event_id', $stage['id'])->get()->getRowArray();
        $this->assertSame('clean', $assessment['cleanliness']);
        $this->assertSame(79, (int) $assessment['energy_percent']);
        $projection = new HistoricalMissingRecoveryProjection($this->db);
        $capture = $projection->capture($this->request, (new HistoricalMissingRecoveryRepairRepository($this->db))->state(Fixture::VEHICLE), new DateTimeImmutable());
        $final = $projection->normalize($capture['views']);
        $physical = [];
        foreach ($final['authority']['readiness'] as $row) {
            foreach ($row['requirements'] as $q) {
                if ($row['trip_id'] === Fixture::FUTURE && in_array($q['code'], ['vehicle_clean', 'energy_known', 'energy_ready'], true) && $q['status'] === 'unsatisfied') {
                    $physical[] = $q;
                    $this->assertFalse($q['actionable']);
                    $this->assertNull($q['action']);
                    $this->assertSame(self::FINAL, (int) $q['custody_trip_id']);
                }
            }
        }
        $this->assertNotEmpty($physical);
        $this->assertNotEmpty($future['purchased']);
        $this->assertSame(['complete'], $future['purchased'][0]['permitted_actions']);
        $this->assertNotEmpty($future['manual']);
        $this->assertTrue($future['purchased'][0]['is_blocking']);
        $this->assertSame(0, $this->db->table('trip_movement_events')->where('fleet_vehicle_id', Fixture::VEHICLE)->where('event_code', 'vehicle_positioned')->countAllResults(), 'No invented repositioning.');
        $this->assertSame(3, $this->db->table('operational_fact_audits')->countAllResults() - count($snapshot['operational_fact_audits']), 'Recovery repair creates its three existing audits; ordinary workflows retain their existing semantics.');
        // Only this completely verified path is eligible to reopen the window.
    }

    public static function stopPoints(): array
    {
        return array_map(static fn ($step) => [$step], range(0, 7));
    }

    private function applyStep(int $step): void
    {
        $b = $this->checklists[self::MIDDLE];
        $c = $this->checklists[self::FINAL];
        if ($step === 1) {
            $repair = new HistoricalMissingRecoveryRepairService($this->db);
            $p = $repair->preview($this->request, Fixture::ACTOR);
            $this->assertSame('guest', $p['before']['authority']['custody']['custody']);
            $this->assertSame('applied', $repair->apply($this->request, Fixture::ACTOR, $p['confirmation'])['status']);
        } elseif ($step === 2) {
            $this->assertTrue($this->writer->stageForChecklist($b['pickup'], ['occurred_at' => $this->spec()[$step][2], 'location_class' => 'airport_hnl', 'location_detail' => 'Synthetic HNL International Garage; pickup bay unknown', 'cleanliness' => 'clean', 'energy_percent' => 76], Fixture::ACTOR));
        } elseif ($step === 3 || $step === 7) {
            $this->assertTrue($this->writer->confirmGuestPickup(($step === 3 ? $b : $c)['pickup'], ['occurred_at' => $this->spec()[$step][2]], Fixture::ACTOR));
        } elseif ($step === 4) {
            $this->writer->stageGuestReturn($b['return'], ['reported_parked_at' => $this->spec()[$step][2], 'airport_garage_code' => 'international', 'airport_parking_level' => 5, 'airport_parking_row' => 'G', 'guest_report_note' => 'Synthetic guest reported 83%; not an operator measurement.'], Fixture::ACTOR);
        } elseif ($step === 5) {
            $this->writer->recoverVehicle($b['return'], ['occurred_at' => $this->spec()[$step][2], 'confirm_recovery_location' => '1', 'location_class' => 'airport_hnl', 'airport_garage_code' => 'international', 'airport_parking_level' => 5, 'airport_parking_row' => 'G', 'energy_unknown' => '1', 'energy_unknown_reason' => 'Synthetic historical recovery charge was not observed.'], Fixture::ACTOR);
        } else {
            $this->assertTrue($this->writer->stageForChecklist($c['pickup'], ['occurred_at' => $this->spec()[$step][2], 'location_class' => 'airport_hnl', 'airport_garage_code' => 'international', 'airport_parking_level' => 4, 'airport_parking_row' => 'H', 'cleanliness' => 'clean', 'energy_percent' => 79], Fixture::ACTOR));
        }
    }

    private function spec(): array
    {
        return [1 => ['vehicle_recovered', Fixture::TRIP, $this->request['recovery']['occurred_at']],
            ['vehicle_staged', self::MIDDLE, '2023-06-10 10:20:00'], ['actual_handoff', self::MIDDLE, '2023-06-10 13:15:00'],
            ['guest_return_staged', self::MIDDLE, '2023-06-11 14:30:00'], ['vehicle_recovered', self::MIDDLE, '2023-06-12 07:40:00'],
            ['vehicle_staged', self::FINAL, '2023-06-12 08:35:00'], ['actual_handoff', self::FINAL, '2023-06-12 13:55:00']];
    }

    private function vehicleEvents(): array
    {
        return $this->db->table('trip_movement_events')->where('fleet_vehicle_id', Fixture::VEHICLE)->orderBy('id')->get()->getResultArray();
    }

    private function commercialFuture(): array
    {
        $projection = new GuestCommitmentProjectionService(new App\Repositories\GuestCommitmentProjectionRepository($this->db));
        $row = $projection->forTrip(Fixture::COMPANY, Fixture::FUTURE, $this->now);
        return $row;
    }

    private function assertPreserved(array $snapshot): void
    {
        $after = Snapshot::snapshot($this->db);
        foreach ($snapshot as $table => $rows) {
            if (! in_array($table, ['trip_movement_events', 'movement_assessments', 'operational_fact_audits'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        foreach (['trip_movement_events', 'movement_assessments', 'operational_fact_audits'] as $table) {
            $this->assertSame($snapshot[$table], array_slice($after[$table], 0, count($snapshot[$table])), 'Existing ' . $table . ' remains immutable.');
        }
    }

    private function assertClosedIntervals(int $step): void
    {
        $timeline = $this->facts->activeCustodyTimelineEvents([Fixture::VEHICLE], '2099-01-01 00:00:00');
        usort($timeline, static fn ($a, $b) => [$a['occurred_at'], $a['id']] <=> [$b['occurred_at'], $b['id']]);
        $holder = null;
        foreach ($timeline as $event) {
            if ($event['event_code'] === 'actual_handoff') {
                $this->assertNull($holder, 'No second handoff before the prior recovery.');
                $holder = (int) $event['turo_trip_normalized_id'];
            } elseif (in_array($event['event_code'], ['vehicle_recovered', 'actual_return', 'guest_return_staged'], true)) {
                $this->assertSame((int) $event['turo_trip_normalized_id'], $holder);
                if ($event['event_code'] !== 'guest_return_staged') {
                    $holder = null;
                }
            } elseif ($event['event_code'] === 'vehicle_staged') {
                $this->assertNull($holder, 'No staging under unclosed guest possession.');
            }
        }
        foreach ([Fixture::TRIP => 1, self::MIDDLE => 5] as $trip => $closedAtStep) {
            if ($step >= $closedAtStep) {
                $handoff = array_values(array_filter($timeline, static fn ($e) => (int) $e['turo_trip_normalized_id'] === $trip && $e['event_code'] === 'actual_handoff'));
                $closing = array_values(array_filter($timeline, static fn ($e) => (int) $e['turo_trip_normalized_id'] === $trip && $e['event_code'] === 'vehicle_recovered'));
                $this->assertCount(1, $handoff);
                $this->assertCount(1, $closing);
                $this->assertLessThan($closing[0]['occurred_at'], $handoff[0]['occurred_at']);
            }
        }
    }
}
