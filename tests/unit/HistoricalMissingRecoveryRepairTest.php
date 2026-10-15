<?php

use App\Repositories\HistoricalMissingRecoveryRepairRepository as Repository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingRecoveryRepairService as Repair;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingRecoveryFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
final class HistoricalMissingRecoveryRepairTest extends TestCase
{
    private BaseConnection $db;
    private array $r;
    private Repair $repair;

    protected function setUp(): void
    {
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true], false);
        VehicleDamageDatabaseFixture::migrate($this->db);
        $this->r = Fixture::seed($this->db);
        $this->repair = new Repair($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testPurePreviewExpectedCustodyAndExactAuditedWriteSetWithReplay(): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $queries = [];
        $observer = function (Query $q) use (&$queries): void {
            if ($q->db === $this->db) {
                $queries[] = $q->getQuery();
            }
        };
        Events::on('DBQuery', $observer);
        try {
            $p = $this->repair->preview($this->r, Fixture::ACTOR);
        } finally {
            Events::removeListener('DBQuery', $observer);
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(SELECT|SHOW|PRAGMA)\b/i', $sql);
        }
        $this->assertSame('guest', $p['before']['authority']['custody']['custody']);
        $this->assertSame('operator', $p['after']['authority']['custody']['custody']);
        $this->assertTrue($p['before']['return_confirmation']['overdue']);
        $this->assertFalse($p['after']['return_confirmation']['overdue']);
        $this->assertStringContainsString('until a later position is separately recorded', $p['position_effect']);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($p['after'], $result['after']);
        $facts = new OperationalFactsRepository($this->db);
        $event = $facts->event($result['event_id']);
        $assessment = $facts->assessment($result['assessment_id']);
        $this->assertSame('vehicle_recovered', $event['event_code']);
        $this->assertSame($this->r['recovery']['occurred_at'], $event['occurred_at']);
        $this->assertSame($event['occurred_at'], $assessment['captured_at']);
        $this->assertGreaterThan($event['occurred_at'], $event['created_at']);
        $this->assertSame('clean', $assessment['cleanliness']);
        $this->assertSame(67, (int) $assessment['energy_percent']);
        $this->assertSame($this->r['assessment']['note'], $assessment['note']);
        $after = HistoricalMovementRepairFixture::snapshot($this->db);
        foreach ($before as $table => $rows) {
            $expected = $after[$table];
            $ids = match ($table) {
                'trip_movement_events' => [$result['event_id']],
                'movement_assessments' => [$result['assessment_id']],
                'operational_fact_audits' => [$result['creation_audit_id'], $result['assessment_audit_id'], $result['receipt_audit_id']],
                default => [],
            };
            $this->assertCount(count($rows) + count($ids), $expected, $table);
            $this->assertSame($rows, array_values(array_filter($expected, static fn ($row) => ! in_array((int) $row['id'], $ids, true))), $table);
        }
        $replay = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame('already_applied', $replay['status']);
        $this->assertSame($result['event_id'], $replay['event_id']);
        $this->assertSame($result['assessment_id'], $replay['assessment_id']);
        $this->assertSame($after, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public function testCanonicalReadinessIncreaseIsExpectedWithoutHardCodedCounts(): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $future = array_values(array_filter($p['before']['authority']['readiness'], static fn ($row) => $row['trip_id'] === Fixture::FUTURE && $row['movement_type'] === 'pickup'))[0];
        $after = $p['after']['authority']['readiness'][$future['checklist_id']];
        $this->assertGreaterThan($future['blocking_remaining_count'], $after['blocking_remaining_count']);
        $this->assertContains('cleaning', array_column($p['expected_changes'], 'family'));
        $this->assertContains('energy', array_column($p['expected_changes'], 'family'));
        $this->assertSame($p['before']['current_future']['extras'], $p['after']['current_future']['extras']);
        $this->assertSame($p['before']['current_future']['next_trip'], $p['after']['current_future']['next_trip']);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($p['expected_changes'], $result['expected_changes']);
    }

    public function testExistingLaterPositionsRemainTheCurrentPositionAuthority(): void
    {
        $position = Fixture::laterPositions($this->db);
        $before = (new OperationalFactsRepository($this->db))->event($position);
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame($position, (int) $p['after']['authority']['position']['event_id']);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($before, (new OperationalFactsRepository($this->db))->event($position));
        $this->assertSame($position, (int) $result['after']['authority']['position']['event_id']);
        $this->assertSame(2, $this->db->table('trip_movement_events')->where('fleet_vehicle_id', Fixture::VEHICLE)->where('event_code', 'vehicle_positioned')->countAllResults());
    }

    #[DataProvider('observations')]
    public function testExplicitCanonicalObservationsAndUnknownsPersist(?string $cleanliness, ?int $energy): void
    {
        $this->r['assessment'] = ['cleanliness' => $cleanliness, 'energy_percent' => $energy];
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $a = (new OperationalFactsRepository($this->db))->assessment($result['assessment_id']);
        $this->assertSame($cleanliness, $a['cleanliness']);
        $this->assertSame($energy, $a['energy_percent'] === null ? null : (int) $a['energy_percent']);
        $this->assertNull($a['note']);
    }

    public static function observations(): array
    {
        return [['clean', 0], ['dirty', 100], [null, null], ['clean', null], [null, 43]];
    }


    #[DataProvider('observationEdges')]
    public function testCanonicalObservationBoundariesMatchPersistedProjection(string $shape): void
    {
        $facts = new OperationalFactsRepository($this->db);
        $events = new MovementEventService($facts);
        $assessments = new MovementAssessmentService($facts);
        if ($shape === 'no_pickup_assessment') {
            $this->db->table('movement_assessments')->where('id', $this->r['handoff_assessment_id'])->delete();
            $this->r['handoff_assessment_id'] = null;
        } elseif ($shape === 'later_readiness' || $shape === 'mixed_readiness') {
            $id = $events->record(Fixture::VEHICLE, null, 'vehicle_readiness_observed', null, '2023-06-10 09:00:00', null, null, 'synthetic_operator', Fixture::ACTOR);
            $assessments->record(Fixture::VEHICLE, null, $id, 'current', 'dirty', $shape === 'mixed_readiness' ? null : 91, '2023-06-10 09:00:00', 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'same_day' || $shape === 'same_day_unknown_energy') {
            $this->db->table('turo_trips_normalized')->where('id', Fixture::FUTURE)->update(['starts_at' => '2023-06-09 18:00:00', 'ends_at' => '2023-06-09 20:00:00']);
            if ($shape === 'same_day_unknown_energy') {
                $this->db->table('movement_assessments')->where('movement_type', 'current')->where('fleet_vehicle_id', Fixture::VEHICLE)->update(['energy_percent' => null]);
                $this->r['assessment']['energy_percent'] = null;
            }
        } elseif ($shape === 'hnl') {
            $this->r['recovery'] = ['occurred_at' => $this->r['recovery']['occurred_at'], 'location_class' => 'airport_hnl', 'airport_garage_code' => 'international', 'airport_parking_level' => 3, 'airport_parking_row' => 'G'];
        } elseif ($shape === 'guest_report') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, 'guest_return_staged', 'return', '2023-06-09 14:50:00', 'airport_hnl', null, 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'prior_operation') {
            $this->db->table('trip_movement_events')->where('turo_trip_normalized_id', 9300)->update(['source' => Repair::OPERATION]);
        }
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($p['after'], $result['after']);
        if ($shape === 'later_readiness' || $shape === 'mixed_readiness') {
            $current = $result['after']['authority']['current_readiness'];
            $this->assertSame('dirty', $current['cleanliness']);
            $this->assertSame($shape === 'mixed_readiness' ? null : 91, $current['energy_percent'] === null ? null : (int) $current['energy_percent']);
        }
        $this->assertSame('already_applied', $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation'])['status']);
    }

    public static function observationEdges(): array
    {
        return array_map(static fn ($x) => [$x], ['no_pickup_assessment', 'later_readiness', 'mixed_readiness', 'same_day', 'same_day_unknown_energy', 'hnl', 'guest_report', 'prior_operation']);
    }

    public function testNormalizationPreservesOtherOperationsArtifactIdentity(): void
    {
        $p = new App\Services\Fleet\HistoricalMissingRecoveryProjection($this->db);
        $old = ['id' => 10001, 'event_code' => 'vehicle_recovered', 'source' => Repair::OPERATION, 'created_at' => '2023-06-01 10:00:00', 'updated_at' => '2023-06-01 10:00:00'];
        $this->assertSame($old, $p->normalize($old, 10002, 10003));
        $old = ['id' => 10001, 'trip_movement_event_id' => 10000, 'source' => Repair::OPERATION, 'created_at' => '2023-06-01 10:00:00', 'updated_at' => '2023-06-01 10:00:00'];
        $this->assertSame($old, $p->normalize($old, 10002, 10003));
    }

    #[DataProvider('historicalStatuses')]
    public function testImportedStatusAndInactivePriorPreparationUseCanonicalClosure(string $status): void
    {
        $lookup = (new App\Repositories\LookupRepository($this->db))->valueId('trip_status', $status);
        $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['trip_status_lookup_value_id' => $lookup]);
        $id = (int) $this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 9300)->where('movement_type', 'return')->get()->getRowArray()['id'];
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertArrayHasKey($id, $p['before']['current_future']['readiness']);
        if ($status === 'booked') {
            $this->assertArrayHasKey($id, $p['after']['current_future']['readiness']);
        } else {
            $this->assertArrayNotHasKey($id, $p['after']['current_future']['readiness']);
        }
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($p['after'], $result['after']);
    }

    public static function historicalStatuses(): array
    {
        return [['booked'], ['in_progress'], ['completed']];
    }

    #[DataProvider('historicalMembershipFaults')]
    public function testHistoricalMembershipCannotHideActionableOrCommercialWork(string $fault): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $capture = (new App\Services\Fleet\HistoricalMissingRecoveryProjection($this->db))->capture($this->r, (new Repository($this->db))->state(Fixture::VEHICLE), new DateTimeImmutable('now'));
        $inputs = $capture['inputs'];
        $id = (int) $this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 9300)->where('movement_type', 'return')->get()->getRowArray()['id'];
        $before = $p['before'];
        $rows = &$before['current_future']['readiness'][$id];
        $physical = array_search('vehicle_clean', array_column($rows, 'code'), true);
        $this->assertNotFalse($physical);
        if ($fault === 'pending_action' || $fault === 'pending_without_link') {
            $rows[$physical]['status'] = 'unsatisfied';
            $rows[$physical]['actionable'] = true;
            $rows[$physical]['relevant'] = true;
            $rows[$physical]['retired_reason'] = null;
            $rows[$physical]['action'] = $fault === 'pending_action' ? ['type' => 'record_fact'] : null;
        } elseif ($fault === 'selection' || $fault === 'commitment') {
            $rows[$physical][$fault === 'selection' ? 'selection_id' : 'commitment_id'] = 56001;
        } elseif ($fault === 'wrong_target') {
            $rows[$physical]['target_trip_id'] = Fixture::FUTURE;
        } elseif ($fault === 'unknown_family') {
            $rows[$physical]['code'] = 'photos_attached';
        } elseif ($fault === 'stale_verification') {
            $index = array_search('extras_verification', array_column($rows, 'source_type'), true);
            $this->assertNotFalse($index);
            $rows[$index]['verification']['refresh_required'] = true;
        } elseif ($fault === 'open_source') {
            foreach ($inputs['commercial']['trips'] as &$trip) {
                if ((int) $trip['id'] === 9300) {
                    $trip['trip_status_code'] = 'booked';
                }
            }
            unset($trip);
        } else {
            $inputs['canonical_contexts'][$id]['next_trip']['id'] = Fixture::FUTURE;
        }
        unset($rows);
        $snapshot = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            (new App\Services\Fleet\HistoricalMissingRecoveryPreservation())->compare($this->r, $inputs, $before, $p['after']);
            $this->fail('Historical membership hid unrelated or actionable work');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('readiness membership', $e->getMessage());
        }
        $this->assertSame($snapshot, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public static function historicalMembershipFaults(): array
    {
        return array_map(static fn ($fault) => [$fault], ['pending_action', 'pending_without_link', 'selection', 'commitment', 'wrong_target', 'unknown_family', 'stale_verification', 'open_source', 'wrong_context']);
    }

    #[DataProvider('invalidRequests')]
    public function testMalformedOrUnownedRequestRejectsWithoutWrites(string $field, mixed $value): void
    {
        $r = $this->r;
        $keys = explode('.', $field);
        if (count($keys) === 2) {
            $r[$keys[0]][$keys[1]] = $value;
        } else {
            $r[$field] = $value;
        }
        $this->reject($r);
    }

    public static function invalidRequests(): iterable
    {
        foreach (['company_id' => 999, 'vehicle_id' => 10, 'trip_id' => 3001, 'pickup_checklist_id' => 999, 'return_checklist_id' => 999, 'handoff_event_id' => 999, 'handoff_assessment_id' => null,
            'operation_uuid' => 'invalid', 'reason' => '', 'attestation' => '', 'actor' => 1, 'positions' => [],
            'assessment.cleanliness' => 'very_clean', 'assessment.energy_percent' => -1, 'assessment.odometer' => 123,
            'recovery.occurred_at' => '2023-06-31 15:22:00', 'recovery.location_class' => 'unknown', 'recovery.cleanliness' => 'clean', 'recovery.airport_garage_code' => 'international'] as $key => $value) {
            yield $key => [$key, $value];
        }
        yield 'charge string' => ['assessment.energy_percent', '67'];
        yield 'charge excessive' => ['assessment.energy_percent', 101];
        yield 'charge float' => ['assessment.energy_percent', 4.5];
        yield 'noncanonical phrase' => ['assessment.cleanliness', 'very clean'];
        yield 'future occurrence' => ['recovery.occurred_at', '2098-07-04 09:00:00'];
        yield 'before handoff' => ['recovery.occurred_at', '2023-06-07 10:00:00'];
        yield 'same second handoff' => ['recovery.occurred_at', '2023-06-07 10:18:00'];
    }

    #[DataProvider('unsafeStates')]
    public function testAmbiguousExistingOrContradictoryAuthorityRejects(string $shape): void
    {
        $facts = new OperationalFactsRepository($this->db);
        $events = new MovementEventService($facts);
        if ($shape === 'missing') {
            $this->db->table('trip_movement_events')->where('id', $this->r['handoff_event_id'])->delete();
        } elseif ($shape === 'voided') {
            $this->db->table('trip_movement_events')->where('id', $this->r['handoff_event_id'])->update(['voided_at' => '2023-06-08 09:00:00']);
        } elseif ($shape === 'superseding') {
            $this->db->table('trip_movement_events')->where('id', $this->r['handoff_event_id'])->update(['supersedes_event_id' => $this->r['handoff_event_id'] - 1]);
        } elseif ($shape === 'multiple') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, 'actual_handoff', 'pickup', '2023-06-07 10:19:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'recovered' || $shape === 'actual_return') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, $shape === 'recovered' ? 'vehicle_recovered' : 'actual_return', 'return', '2023-06-09 15:30:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'return_assessment') {
            (new MovementAssessmentService($facts))->record(Fixture::VEHICLE, Fixture::TRIP, null, 'return', null, null, '2023-06-09 15:22:00', 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'assessment_scope') {
            $this->db->table('movement_assessments')->where('id', $this->r['handoff_assessment_id'])->update(['turo_trip_normalized_id' => 9300]);
        } elseif ($shape === 'same_second_position') {
            $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, $this->r['recovery']['occurred_at'], 'home', 'Synthetic ambiguous ordering', 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'other_guest') {
            $events->record(Fixture::VEHICLE, 9300, 'actual_handoff', 'pickup', '2023-06-08 14:00:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        } elseif ($shape === 'late_report') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, 'guest_return_staged', 'return', '2023-06-09 15:25:00', 'airport_hnl', null, 'synthetic_operator', Fixture::ACTOR);
        } else {
            throw new RuntimeException('Unknown synthetic unsafe shape');
        }
        $this->reject($this->r);
    }

    public static function unsafeStates(): array
    {
        return array_map(static fn ($x) => [$x], ['missing', 'voided', 'superseding', 'multiple', 'recovered', 'actual_return', 'return_assessment', 'assessment_scope', 'same_second_position', 'other_guest', 'late_report']);
    }

    #[DataProvider('staleStates')]
    public function testStaleFingerprintBindsEveryPreservationInput(string $shape): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $facts = new OperationalFactsRepository($this->db);
        $events = new MovementEventService($facts);
        match ($shape) {
            'handoff' => $this->db->table('trip_movement_events')->where('id', $this->r['handoff_event_id'])->update(['location_detail' => 'Synthetic changed lodge']),
            'schedule' => $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['ends_at' => '2023-06-09 16:15:00']),
            'assessment' => $this->db->table('movement_assessments')->where('id', $this->r['handoff_assessment_id'])->update(['energy_percent' => 73]),
            'position' => Fixture::laterPositions($this->db),
            'current_readiness' => $this->db->table('movement_assessments')->where('fleet_vehicle_id', Fixture::VEHICLE)->where('movement_type', 'current')->update(['energy_percent' => 79]),
            'custody' => $events->record(Fixture::VEHICLE, Fixture::TRIP, 'guest_return_staged', 'return', '2023-06-09 14:20:00', 'airport_hnl', null, 'synthetic_operator', Fixture::ACTOR),
            'unrelated_vehicle' => $this->db->table('fleet_vehicles')->where('id', 10)->update(['display_name' => 'Synthetic independent business change']),
            'extras' => $this->db->table('turo_extra_reservation_snapshots')->where('company_id', Fixture::COMPANY)->update(['observed_at' => '2023-06-02 10:00:00']),
            default => throw new RuntimeException('Unknown synthetic stale shape'),
        };
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Stale preview accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public static function staleStates(): array
    {
        return array_map(static fn ($x) => [$x], ['handoff', 'schedule', 'assessment', 'position', 'current_readiness', 'custody', 'unrelated_vehicle', 'extras']);
    }

    public function testSuperadminOnlyIncludingReplayAndChangedActor(): void
    {
        $this->reject($this->r, Fixture::ADMIN);
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->db->table('users')->where('id', Fixture::ACTOR)->update(['active' => 0]);
        $this->reject($this->r);
    }

    public function testUuidBindsActorTripAndOriginalConfirmation(): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->reject($this->r, 7); // A different active synthetic superadmin.
        $other = $this->r;
        $other['trip_id'] = 9300;
        $this->reject($other);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($this->r, Fixture::ACTOR, str_repeat('0', 64));
            $this->fail('Different confirmation accepted on replay');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different payload, actor or preview', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    #[DataProvider('changedCommands')]
    public function testUuidCannotBeReusedWithDifferentSemanticPayload(string $field, mixed $value): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $r = $this->r;
        [$key, $part] = explode('.', $field);
        $r[$key][$part] = $value;
        $this->reject($r);
    }

    public static function changedCommands(): array
    {
        return [['recovery.occurred_at', '2023-06-09 15:23:00'], ['recovery.location_detail', 'Synthetic other depot'], ['assessment.cleanliness', 'dirty'], ['assessment.energy_percent', 69], ['assessment.note', 'Synthetic revised observation']];
    }

    #[DataProvider('unrelatedFamilies')]
    public function testUnrelatedHypotheticalObligationOrReadinessChangeRejects(string $family): void
    {
        $projection = new class ($this->db, $family) extends \App\Services\Fleet\HistoricalMissingRecoveryProjection {
            public function __construct(BaseConnection $db, private readonly string $family)
            {
                parent::__construct($db);
            }
            public function views(array $r, array $inputs, DateTimeImmutable $now): array
            {
                $views = parent::views($r, $inputs, $now);
                if (array_filter($inputs['timeline'], static fn ($e) => $e['source'] === Repair::OPERATION) === []) {
                    return $views;
                }
                if ($this->family === 'extras') {
                    $views['current_future']['extras'][Fixture::FUTURE]['manual'][] = ['synthetic_unrelated_obligation' => true];
                } elseif ($this->family === 'current_future') {
                    foreach ($views['current_future']['readiness'] as &$row) {
                        if (($row['trip_id'] ?? null) === Fixture::FUTURE && ($row['movement_type'] ?? null) === 'pickup') {
                            $row['requirements'][0]['status'] = 'satisfied';
                        }
                    }
                    unset($row);
                } else {
                    foreach ($views['authority']['readiness'] as &$row) {
                        if ($row['trip_id'] === Fixture::FUTURE && $row['movement_type'] === 'pickup') {
                            $row['requirements'][0]['status'] = 'satisfied';
                        }
                    }
                    unset($row);
                }
                return $views;
            }
        };
        $this->repair = new Repair($this->db, projection: $projection);
        $this->reject($this->r);
    }

    public static function unrelatedFamilies(): array
    {
        return [['extras'], ['photos'], ['current_future']];
    }

    #[DataProvider('faults')]
    public function testUnexpectedPersistedSideEffectRollsBackAllNewArtifacts(string $fault): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $repo = new class ($this->db, $fault) extends Repository {
            public function __construct(BaseConnection $db, private readonly string $fault)
            {
                parent::__construct($db);
            }
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === self::RECEIPT_ACTION) {
                    if ($this->fault === 'throw') {
                        throw new RuntimeException('Synthetic receipt failure');
                    }
                    $this->db->table('fleet_vehicles')->where('id', 10)->update(['display_name' => 'Synthetic forbidden independent side effect']);
                }
            }
        };
        try {
            (new Repair($this->db, $repo))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Unexpected persisted side effect accepted');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public static function faults(): array
    {
        return [['throw'], ['unrelated_write']];
    }

    #[DataProvider('corruptions')]
    public function testChangedReceiptOrCreatedAuthorityCannotReplay(string $artifact): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        match ($artifact) {
            'assessment' => $this->db->table('movement_assessments')->where('id', $result['assessment_id'])->update(['cleanliness' => null]),
            'event' => $this->db->table('trip_movement_events')->where('id', $result['event_id'])->update(['voided_at' => '2023-06-11 09:00:00']),
            'handoff' => $this->db->table('trip_movement_events')->where('id', $this->r['handoff_event_id'])->update(['location_detail' => 'Synthetic altered handoff']),
            'audit' => $this->db->table('operational_fact_audits')->where('id', $result['creation_audit_id'])->update(['new_values' => '{}']),
            'receipt' => $this->corruptReceipt($result['receipt_audit_id']),
            default => throw new RuntimeException('Unknown synthetic corruption'),
        };
        $this->reject($this->r);
    }

    public static function corruptions(): array
    {
        return [['assessment'], ['event'], ['handoff'], ['audit'], ['receipt']];
    }

    private function corruptReceipt(int $id): bool
    {
        $row = $this->db->table('operational_fact_audits')->where('id', $id)->get()->getRowArray();
        $receipt = json_decode($row['new_values'], true, 512, JSON_THROW_ON_ERROR);
        $receipt['result']['preservation'] = false;
        return $this->db->table('operational_fact_audits')->where('id', $id)->update(['new_values' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
    }

    private function reject(array $r, int $actor = Fixture::ACTOR): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->preview($r, $actor);
            $this->fail('Unsafe recovery accepted');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }
}
