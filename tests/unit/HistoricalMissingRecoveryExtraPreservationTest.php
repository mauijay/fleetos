<?php

use App\Repositories\HistoricalMissingRecoveryRepairRepository as Repository;
use App\Repositories\LookupRepository;
use App\Services\Fleet\HistoricalMissingRecoveryPreservation as Policy;
use App\Services\Fleet\HistoricalMissingRecoveryProjection as Projection;
use App\Services\Fleet\HistoricalMissingRecoveryRepairService as Repair;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingRecoveryFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture as Snapshot;
use Tests\Support\HistoricalRecoveryExtraFixture as Extra;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
final class HistoricalMissingRecoveryExtraPreservationTest extends TestCase
{
    private BaseConnection $db;
    private array $r;
    private int $selection;

    protected function setUp(): void
    {
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true], false);
        VehicleDamageDatabaseFixture::migrate($this->db);
        $this->r = Fixture::seed($this->db);
        $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['trip_status_lookup_value_id' => (new LookupRepository($this->db))->valueId('trip_status', 'completed')]);
        $this->selection = Extra::seed($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testPurePreviewRetiresOnlyMembershipWithPendingReviewAndExactCommercialWriteFirewall(): void
    {
        $repair = new Repair($this->db);
        $snapshot = Snapshot::snapshot($this->db);
        $queries = new ArrayObject();
        $observer = function (Query $q) use (&$queries): void {
            if ($q->db === $this->db) {
                $queries->append($q->getQuery());
            }
        };
        Events::on('DBQuery', $observer);
        try {
            $p = $repair->preview($this->r, Fixture::ACTOR);
            $previewReads = count($queries);
            $inputs = $this->inputs();
            foreach ($queries as $sql) {
                $this->assertMatchesRegularExpression('/^\s*(SELECT|SHOW|PRAGMA)\b/i', $sql);
            }
            $queries->exchangeArray([]);
            $changes = (new Policy())->compare($this->r, $inputs, $p['before'], $p['after']);
            $this->assertCount(0, $queries, 'Classification performs zero database reads or writes.');
        } finally {
            Events::removeListener('DBQuery', $observer);
        }
        $this->assertGreaterThan(0, $previewReads);
        $this->assertSame($snapshot, Snapshot::snapshot($this->db));
        $retired = array_values(array_filter($changes, static fn ($c) => $c['family'] === 'historical_extra_membership'));
        $this->assertCount(1, $retired);
        $this->assertCount(1, $retired[0]['entries']);
        $entry = $retired[0]['entries'][0];
        $id = $entry['checklist_id'];
        $this->assertSame($this->selection, $entry['selection_id']);
        $this->assertSame('pending', $entry['completion_state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $entry['evidence_fingerprint']);
        $this->assertArrayHasKey($id, $p['before']['current_future']['readiness']);
        $this->assertArrayNotHasKey($id, $p['after']['current_future']['readiness']);
        $a = $this->requirement($p['before']['authority']['readiness'][$id]['requirements']);
        $b = $this->requirement($p['after']['authority']['readiness'][$id]['requirements']);
        $this->assertSame($a, $b);
        $this->assertFalse($b['actionable']);
        $this->assertFalse($b['relevant']);
        $this->assertSame([], $b['extra_fulfillment']['permitted_actions']);
        $this->assertTrue($b['extra_fulfillment']['review_required']);
        $this->assertSame('pending', $b['extra_fulfillment']['fulfillment_state']);
        $this->assertFalse($b['extra_fulfillment']['is_completed']);
        $this->assertSame($p['before']['current_future']['extras'], $p['after']['current_future']['extras']);
        $result = $repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($p['after'], $result['after']);
        $this->assertSame($changes, $result['expected_changes']);
        $after = Snapshot::snapshot($this->db);
        foreach ($snapshot as $table => $rows) {
            $ids = match ($table) {
                'trip_movement_events' => [$result['event_id']],
                'movement_assessments' => [$result['assessment_id']],
                'operational_fact_audits' => [$result['creation_audit_id'], $result['assessment_audit_id'], $result['receipt_audit_id']],
                default => [],
            };
            $this->assertSame($rows, array_values(array_filter($after[$table], static fn ($row) => ! in_array((int) $row['id'], $ids, true))), $table);
        }
        $this->assertSame('already_applied', $repair->apply($this->r, Fixture::ACTOR, $p['confirmation'])['status']);
        $this->assertSame($after, Snapshot::snapshot($this->db));
    }

    #[DataProvider('unsafePredicates')]
    public function testEveryHistoricalExtraPredicateFailsClosed(string $fault): void
    {
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $inputs = $this->inputs();
        $id = (int) array_key_first($inputs['canonical_contexts']);
        $rows = &$p['before']['current_future']['readiness'][$id];
        $index = array_search('extra_fulfillment', array_column($rows, 'source_type'), true);
        $this->assertNotFalse($index);
        $row = &$rows[$index];
        $snapshotIndex = array_search((int) $row['extra_fulfillment']['snapshot_id'], array_map('intval', array_column($inputs['commercial']['snapshots'], 'id')), true);
        $this->assertNotFalse($snapshotIndex);
        match ($fault) {
            'source_type' => $row['source_type'] = 'unknown_extra',
            'company' => $row['company_id']++,
            'vehicle' => $row['vehicle_id']++,
            'trip' => $row['trip_id']++,
            'target' => $row['target_trip_id']++,
            'action' => $row['action'] = ['type' => 'complete'],
            'actionable' => $row['actionable'] = true,
            'relevant' => $row['relevant'] = true,
            'retired_reason' => $row['retired_reason'] = null,
            'selection' => $row['selection_id']++,
            'fulfillment' => $row['fulfillment_id']++,
            'missing_selection' => $row['selection_id'] = null,
            'missing_fulfillment' => $row['fulfillment_id'] = null,
            'commitment' => $row['commitment_id'] = 98001,
            'synchronization' => $row['synchronization_required'] = true,
            'permitted_actions' => $row['extra_fulfillment']['permitted_actions'] = ['complete'],
            'underlying_actionable' => $row['extra_fulfillment']['is_actionable'] = true,
            'underlying_blocker' => $row['extra_fulfillment']['is_blocking'] = true,
            'not_suppressed' => $row['extra_fulfillment']['is_suppressed_after_handoff'] = false,
            'completed' => $row['extra_fulfillment']['is_completed'] = true,
            'completion_time' => $row['extra_fulfillment']['completed_at'] = '2023-06-08 08:00:00',
            'unknown_state' => $row['extra_fulfillment']['fulfillment_state'] = 'unknown',
            'review' => $row['extra_fulfillment']['review_required'] = false,
            'extra_refresh' => $row['extra_fulfillment']['verification']['refresh_required'] = true,
            'wrong_context' => $inputs['canonical_contexts'][$id]['next_trip']['id']++,
            'source_checklist' => $inputs['canonical_contexts'][$id]['id']++,
            'projected_company' => $p['before']['authority']['readiness'][$id]['company_id']++,
            'projected_vehicle' => $p['before']['authority']['readiness'][$id]['vehicle_id']++,
            'source_owner' => $inputs['canonical_contexts'][$id]['turo_trip_normalized_id']++,
            'source_vehicle' => $inputs['canonical_contexts'][$id]['fleet_vehicle_id']++,
            'no_raw_selection' => $inputs['commercial']['selections'] = [],
            'no_snapshot' => $inputs['commercial']['snapshots'] = [],
            'snapshot_owner' => $inputs['commercial']['snapshots'][$snapshotIndex]['company_id']++,
            'current_refresh' => $p['before']['current_future']['extras'][Fixture::TRIP]['verification']['refresh_required'] = true,
            'optional_refresh' => $p['before']['current_future']['extras'][Fixture::TRIP]['verification']['optional_active_refresh'] = true,
            'manual' => $p['before']['current_future']['extras'][Fixture::TRIP]['rows'][] = ['source_kind' => 'manual', 'is_blocking' => false],
            'historical_missing' => $p['after']['authority']['readiness'][$id]['requirements'] = [],
            'duplicate' => $rows[] = $row,
            'open_source' => $inputs['timeline'] = array_values(array_filter($inputs['timeline'], static fn ($e) => $e['event_code'] !== 'vehicle_recovered')),
            default => throw new RuntimeException('Unknown synthetic predicate.'),
        };
        // Keep full before/after requirements equal for row faults: classification
        // must reject the unsafe predicate even when history itself still agrees.
        if (! in_array($fault, ['historical_missing', 'duplicate'], true)) {
            foreach (['before', 'after'] as $side) {
                foreach ($p[$side]['authority']['readiness'][$id]['requirements'] as &$historical) {
                    if ($historical['source_type'] === 'extra_fulfillment') {
                        $historical = $row;
                    }
                }
                unset($historical);
            }
        }
        if (in_array($fault, ['projected_company', 'projected_vehicle'], true)) {
            $p['after']['authority']['readiness'][$id] = $p['before']['authority']['readiness'][$id];
        }
        unset($row, $rows);
        $this->rejectComparison($inputs, $p['before'], $p['after']);
    }

    public static function unsafePredicates(): array
    {
        return array_map(static fn ($s) => [$s], ['source_type', 'company', 'vehicle', 'trip', 'target', 'action', 'actionable', 'relevant', 'retired_reason', 'selection', 'fulfillment', 'missing_selection', 'missing_fulfillment', 'commitment', 'synchronization', 'permitted_actions', 'underlying_actionable', 'underlying_blocker', 'not_suppressed', 'completed', 'completion_time', 'unknown_state', 'review', 'extra_refresh', 'wrong_context', 'source_checklist', 'projected_company', 'projected_vehicle', 'source_owner', 'source_vehicle', 'no_raw_selection', 'no_snapshot', 'snapshot_owner', 'current_refresh', 'optional_refresh', 'manual', 'historical_missing', 'duplicate', 'open_source']);
    }

    #[DataProvider('commercialGates')]
    public function testCurrentAndFutureCommercialDeltasRemainHardGates(string $gate): void
    {
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $future = array_values(array_filter($p['after']['authority']['readiness'], static fn ($q) => $q['trip_id'] === Fixture::FUTURE && $q['movement_type'] === 'pickup'))[0]['checklist_id'];
        $id = (int) array_key_first($p['before']['authority']['readiness']);
        match ($gate) {
            'current_action' => $p['after']['current_future']['extras'][Fixture::TRIP]['purchased'][0]['permitted_actions'] = ['complete'],
            'current_fulfillment' => $p['after']['current_future']['extras'][Fixture::TRIP]['purchased'][0]['fulfillment_state'] = 'completed',
            'future_fulfillment' => $p['after']['current_future']['extras'][Fixture::FUTURE]['purchased'][] = ['selection_id' => 98001],
            'current_verification' => $p['after']['current_future']['extras'][Fixture::TRIP]['verification']['refresh_required'] = true,
            'future_verification' => $p['after']['current_future']['extras'][Fixture::FUTURE]['verification']['refresh_required'] = true,
            'manual_commitment' => $p['after']['current_future']['extras'][Fixture::FUTURE]['manual'][] = ['state' => 'active'],
            'future_blocker_count' => $p['after']['current_future']['readiness'][$future]['blocking_remaining_count']++,
            'historical_review' => $p['after']['authority']['readiness'][$id]['requirements'] = [],
            'future_schedule' => $p['after']['current_future']['next_trip'] = null,
            default => throw new RuntimeException('Unknown synthetic gate.'),
        };
        $this->rejectComparison($this->inputs(), $p['before'], $p['after']);
    }

    public static function commercialGates(): array
    {
        return array_map(static fn ($s) => [$s], ['current_action', 'current_fulfillment', 'future_fulfillment', 'current_verification', 'future_verification', 'manual_commitment', 'future_blocker_count', 'historical_review', 'future_schedule']);
    }

    #[DataProvider('staleChanges')]
    public function testOldConfirmationRejectsCommercialOrLifecycleChangesWithoutWrites(string $change): void
    {
        $repair = new Repair($this->db);
        $p = $repair->preview($this->r, Fixture::ACTOR);
        Extra::change($this->db, $this->selection, $change);
        $snapshot = Snapshot::snapshot($this->db);
        $error = null;
        try {
            $repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        } catch (RuntimeException $e) {
            $error = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $error, 'Stale commercial authority must reject.');
        $this->assertStringContainsString('stale', $error->getMessage());
        $this->assertSame($snapshot, Snapshot::snapshot($this->db));
    }

    public static function staleChanges(): array
    {
        return array_map(static fn ($s) => [$s], ['actionable', 'completed', 'fulfillment_note', 'snapshot', 'selection', 'manual', 'lifecycle']);
    }

    #[DataProvider('activeShapes')]
    public function testActualActiveOrPreRefreshExtraCannotBeHidden(string $shape): void
    {
        Extra::change($this->db, $this->selection, $shape);
        $snapshot = Snapshot::snapshot($this->db);
        $error = null;
        try {
            (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        } catch (RuntimeException $e) {
            $error = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $error, 'Current Extra obligation must reject.');
        $this->assertStringContainsString('readiness membership', $error->getMessage());
        $this->assertSame($snapshot, Snapshot::snapshot($this->db));
    }

    public static function activeShapes(): array
    {
        return [['actionable'], ['lifecycle'], ['manual']];
    }

    private function inputs(): array
    {
        return (new Projection($this->db))->capture($this->r, (new Repository($this->db))->state(Fixture::VEHICLE), new DateTimeImmutable())['inputs'];
    }

    private function requirement(array $rows): array
    {
        return array_values(array_filter($rows, static fn ($q) => $q['source_type'] === 'extra_fulfillment'))[0];
    }

    private function rejectComparison(array $inputs, array $before, array $after): void
    {
        $snapshot = Snapshot::snapshot($this->db);
        $error = null;
        try {
            (new Policy())->compare($this->r, $inputs, $before, $after);
        } catch (RuntimeException $e) {
            $error = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $error, 'Unsafe preservation comparison must reject.');
        $this->assertNotSame('', $error->getMessage());
        $this->assertSame($snapshot, Snapshot::snapshot($this->db));
    }
}
