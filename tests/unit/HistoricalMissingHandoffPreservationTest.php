<?php

use App\Repositories\HistoricalMissingHandoffRepairRepository as Repository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingHandoffPreservation as Policy;
use App\Services\Fleet\HistoricalMissingHandoffProjection as Projection;
use App\Services\Fleet\HistoricalMissingHandoffRepairService as Repair;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingHandoffFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture as Snapshot;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
final class HistoricalMissingHandoffPreservationTest extends TestCase
{
    private BaseConnection $db;
    private array $r;
    private array $historicalIds;

    protected function setUp(): void
    {
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true], false);
        VehicleDamageDatabaseFixture::migrate($this->db);
        $this->r = Fixture::seed($this->db);
        $this->historicalIds = Fixture::historicalAffordances($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testDuplicateSupersededHistoricalWorkspacesRetireOnlyFourDerivedAffordances(): void
    {
        $before = Snapshot::snapshot($this->db);
        $repair = new Repair($this->db);
        $p = $repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame($before, Snapshot::snapshot($this->db));
        $this->assertCount(4, $p['historical_affordances_retired']);
        $this->assertSame(['cleaning', 'energy', 'cleaning', 'energy'], array_column($p['historical_affordances_retired'], 'family'));
        $this->assertSame($this->historicalIds, array_values(array_unique(array_column($p['historical_affordances_retired'], 'checklist_id'))));
        $this->assertSame($p['current_future_before'], $p['current_future_after']);
        $this->assertCount(1, $p['current_future_before']['cleaning']);
        $this->assertCount(1, $p['current_future_before']['energy']);
        $this->assertFalse($p['current_future_before']['extras'][Fixture::TRIP]['verification']['refresh_required']);
        $future = array_values(array_filter($p['current_future_before']['readiness'], static fn ($row) => ($row['trip_id'] ?? 0) === 8302 && ($row['movement_type'] ?? '') === 'pickup'));
        $this->assertNotEmpty($future);
        $this->assertGreaterThan(0, $future[0]['blocking_remaining_count']);
        $result = $repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($p['historical_affordances_retired'], $result['historical_affordances_retired']);
        $this->assertSame($result['current_future_fingerprints']['before'], $result['current_future_fingerprints']['after']);
        $after = Snapshot::snapshot($this->db);
        foreach ($before as $table => $rows) {
            if ($table === 'trip_movement_events') {
                $this->assertCount(count($rows) + 1, $after[$table]);
                $this->assertSame($rows, array_values(array_filter($after[$table], static fn ($e) => (int) $e['id'] !== $result['event_id'])));
            } elseif ($table === 'operational_fact_audits') {
                $this->assertCount(count($rows) + 2, $after[$table]);
                $this->assertSame($rows, array_values(array_filter($after[$table], static fn ($a) => ! in_array((int) $a['id'], [$result['creation_audit_id'], $result['receipt_audit_id']], true))));
            } else {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $receipt = (new Repository($this->db))->commandReceipt($this->r['operation_uuid'])['receipt'];
        $this->assertSame($p['historical_affordances_retired'], $receipt['historical_affordances_retired']);
        $this->assertArrayNotHasKey('current_future_before', $receipt['result']);
        $this->assertArrayNotHasKey('current_future_after', $receipt['result']);
        $this->assertSame($receipt['current_future_authority_fingerprint'], $receipt['result']['current_future_fingerprints']['before']);
        $this->assertSame(hash('sha256', json_encode($resultWithoutId = array_diff_key($result, ['receipt_audit_id' => true]), JSON_THROW_ON_ERROR)), $receipt['result_hash']);
        $this->assertSame($resultWithoutId, $receipt['result']);
        $replay = $repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame('already_applied', $replay['status']);
        $this->assertSame($result['event_id'], $replay['event_id']);
        $this->assertSame($after, Snapshot::snapshot($this->db));
    }

    public function testPreRefreshShapeRejectsCurrentTargetExtrasEvenWithClosedRecovery(): void
    {
        $this->db->table('turo_extra_reservation_snapshots')->where('company_id', Fixture::COMPANY)->delete();
        $state = (new Repository($this->db))->state(Fixture::VEHICLE);
        $projection = new Projection($this->db);
        $now = new DateTimeImmutable();
        $before = $projection->capture($this->r, $state, $now);
        $current = $projection->currentFuture($this->r, $before['inputs'], $now);
        $this->assertTrue($current['extras'][Fixture::TRIP]['verification']['refresh_required']);
        $this->rejectPreview('current/future');
    }

    #[DataProvider('unsafeDeltas')]
    public function testNoOtherHistoricalOrCurrentAuthorityDifferenceIsExempt(string $kind): void
    {
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $capture = (new Projection($this->db))->capture($this->r, (new Repository($this->db))->state(Fixture::VEHICLE), new DateTimeImmutable());
        $inputs = $capture['inputs'];
        $before = $p['before'];
        $after = $p['projected_after'];
        $currentBefore = $p['current_future_before'];
        $currentAfter = $p['current_future_after'];
        $id = $this->historicalIds[0];
        $index = array_search('vehicle_clean', array_column($before['readiness'][$id]['requirements'], 'code'), true);
        match ($kind) {
            'current_cleaning' => $currentAfter['cleaning'] = [],
            'current_energy' => $currentAfter['energy'] = [],
            'future_blocker' => $currentAfter['readiness'][array_key_first($currentAfter['readiness'])]['ready'] = true,
            'future_staging' => $currentAfter['readiness']['synthetic_staging'] = ['action' => 'stage'],
            'persisted_checklist' => $after['readiness'][$id]['workflow_history']['stored_readiness_status'] = 'ready',
            'historical_creation' => $after['readiness'][$id]['requirements'][] = $before['readiness'][$id]['requirements'][$index],
            'retired_to_available' => [$before['readiness'][$id]['requirements'][$index], $after['readiness'][$id]['requirements'][$index]] = [$after['readiness'][$id]['requirements'][$index], $before['readiness'][$id]['requirements'][$index]],
            'remaining_payload' => $after['readiness'][$id]['requirements'][$index]['label'] = 'Changed historical payload',
            'persisted_action' => $before['readiness'][$id]['requirements'][$index]['source_type'] = 'trip_commitment',
            'active_target' => $inputs['timeline'] = array_values(array_filter($inputs['timeline'], fn ($e) => (int) $e['id'] !== $this->r['recovery_event_id'])),
            'active_owner' => $inputs['timeline'] = array_values(array_filter($inputs['timeline'], static fn ($e) => (int) $e['turo_trip_normalized_id'] !== 8300)),
            'mixed_current_checklist' => $after['readiness'][$this->r['return_checklist_id']] = ['requirements' => [['actionable' => false]]],
            default => throw new RuntimeException('Unknown synthetic delta.'),
        };
        $this->expectException(RuntimeException::class);
        (new Policy())->compare($this->r, $inputs, $before, $after, $currentBefore, $currentAfter);
    }

    public static function unsafeDeltas(): array
    {
        return array_map(static fn ($x) => [$x], ['current_cleaning', 'current_energy', 'future_blocker', 'future_staging', 'persisted_checklist', 'historical_creation', 'retired_to_available', 'remaining_payload', 'persisted_action', 'active_target', 'active_owner', 'mixed_current_checklist']);
    }

    #[DataProvider('stillCurrentFamilies')]
    public function testHistoricalOwnerDoesNotExemptCurrentTargetFamilyOrCurrentSiblingChecklist(string $family): void
    {
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $inputs = (new Projection($this->db))->capture($this->r, (new Repository($this->db))->state(Fixture::VEHICLE), new DateTimeImmutable())['inputs'];
        $current = $p['current_future_before'];
        if ($family === 'sibling') {
            // Keep one duplicate historical; explicitly retain its sibling in
            // canonical current execution. Trip-wide exemption must not win.
            $id = $this->historicalIds[1];
            $current['readiness'][$id] = $p['before']['readiness'][$id];
        } elseif ($family === 'extras') {
            $current['extras'][Fixture::TRIP]['verification']['refresh_required'] = true;
        } else {
            $current[$family][0]['target_trip_id'] = Fixture::TRIP;
        }
        $this->expectException(RuntimeException::class);
        (new Policy())->compare($this->r, $inputs, $p['before'], $p['projected_after'], $current, $current);
    }

    public static function stillCurrentFamilies(): array
    {
        return [['extras'], ['cleaning'], ['energy'], ['sibling']];
    }

    public function testHistoricalLabelBecomingCurrentMakesOldApplyStaleAndWritesNothing(): void
    {
        $repair = new Repair($this->db);
        $p = $repair->preview($this->r, Fixture::ACTOR);
        $this->db->table('turo_trips_normalized')->where('id', 8300)->update(['trip_status_lookup_value_id' => (new App\Repositories\LookupRepository($this->db))->valueId('trip_status', 'booked')]);
        $before = Snapshot::snapshot($this->db);
        try {
            $repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Reclassified historical action accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    #[DataProvider('canonicalChanges')]
    public function testActualCanonicalWorkChangeInvalidatesPreview(string $family): void
    {
        $repair = new Repair($this->db);
        $preview = $repair->preview($this->r, Fixture::ACTOR);
        if ($family === 'future') {
            $ids = array_column($this->db->table('trip_movement_checklists')->where(['turo_trip_normalized_id' => 8302, 'movement_type' => 'pickup'])->get()->getResultArray(), 'id');
            $this->db->table('trip_movement_checklist_items')->whereIn('trip_movement_checklist_id', $ids)->whereIn('item_code', ['exterior_photos_completed', 'interior_photos_completed'])->update(['completion_state' => 'complete', 'completed_at' => date('Y-m-d H:i:s')]);
        } else {
            $facts = new OperationalFactsRepository($this->db);
            $at = (new DateTimeImmutable())->modify('-1 minute')->format('Y-m-d H:i:s');
            $id = (new App\Services\Fleet\MovementEventService($facts))->record(Fixture::VEHICLE, null, 'vehicle_readiness_observed', null, $at, null, null, 'synthetic_operator', Fixture::ACTOR);
            (new App\Services\Fleet\MovementAssessmentService($facts))->record(Fixture::VEHICLE, null, $id, 'current', $family === 'cleaning' ? 'clean' : null, $family === 'energy' ? 88 : null, $at, 'synthetic_operator', Fixture::ACTOR);
        }
        $fresh = $repair->preview($this->r, Fixture::ACTOR);
        $key = $family === 'future' ? 'readiness' : $family;
        $this->assertNotSame($preview['current_future_before'][$key], $fresh['current_future_before'][$key]);
        $before = Snapshot::snapshot($this->db);
        try {
            $repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
            $this->fail('Current canonical authority change accepted with old hash');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    public static function canonicalChanges(): array
    {
        return [['cleaning'], ['energy'], ['future']];
    }

    public function testCanonicalFuturePreparationIsComparedBeforeAnyChecklistExists(): void
    {
        $futureIds = array_column($this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 8302)->get()->getResultArray(), 'id');
        $this->db->table('trip_movement_checklist_items')->whereIn('trip_movement_checklist_id', $futureIds)->delete();
        $this->db->table('trip_movement_checklists')->whereIn('id', $futureIds)->delete();
        $this->db->table('turo_trips_normalized')->where('id', 8302)->update(['starts_at' => (new DateTimeImmutable())->modify('+6 hours')->format('Y-m-d H:i:s'), 'ends_at' => (new DateTimeImmutable())->modify('+12 hours')->format('Y-m-d H:i:s')]);
        $before = Snapshot::snapshot($this->db);
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $future = $p['current_future_before']['preparation'][8302];
        $this->assertFalse($future['ready']);
        $this->assertSame(1, $future['blocking_remaining_count']);
        $this->assertSame('extras_verification', $future['requirements'][0]['source_type']);
        $this->assertSame($p['current_future_before'], $p['current_future_after']);
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    public function testCanonicallyRecoveredSameDayTripStillPreservesItsCommandCenterPickup(): void
    {
        // A fixed synthetic as-of avoids midnight/date assumptions. Physical
        // closure does not permit removal of a current scheduled pickup task.
        $now = new DateTimeImmutable('2024-03-17 15:00:00');
        $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['starts_at' => '2024-03-17 11:00:00']);
        $this->db->table('trip_movement_events')->where('fleet_vehicle_id', Fixture::VEHICLE)->update(['created_at' => '2024-03-17 14:30:00']);
        $this->db->table('turo_extra_reservation_snapshots')->where('company_id', Fixture::COMPANY)->update(['observed_at' => '2024-03-18 00:59:00']);
        $r = $this->r;
        $r['handoff']['occurred_at'] = '2024-03-17 11:27:00';
        $projection = new Projection($this->db);
        $before = $projection->capture($r, (new Repository($this->db))->state(Fixture::VEHICLE), $now);
        $event = $r['handoff'] + ['id' => -1, 'turo_trip_normalized_id' => Fixture::TRIP, 'fleet_vehicle_id' => Fixture::VEHICLE, 'company_id' => Fixture::COMPANY, 'event_code' => 'actual_handoff'];
        $hyp = $projection->withHandoff($r, $before['inputs'], $event);
        $currentBefore = $projection->currentFuture($r, $before['inputs'], $now);
        $currentAfter = $projection->currentFuture($r, $hyp, $now);
        $this->assertTrue((new Policy())->closedTrip(Fixture::TRIP, $before['inputs']));
        $this->assertFalse($currentBefore['extras'][Fixture::TRIP]['verification']['refresh_required']);
        $pickup = ['trip_id' => Fixture::TRIP, 'movement_type' => 'pickup', 'scheduled_at' => '2024-03-17 11:00:00'];
        $this->assertContains($pickup, $currentBefore['execution']);
        $this->assertNotContains($pickup, $currentAfter['execution']);
        $this->expectException(RuntimeException::class);
        (new Policy())->compare($r, $before['inputs'], $before['projected'], $projection->historicalView($r, $hyp, $before['inputs']['commercial'], $now), $currentBefore, $currentAfter);
    }

    public function testClassificationReceiptMetadataCannotBeChangedOnReplay(): void
    {
        $repair = new Repair($this->db);
        $preview = $repair->preview($this->r, Fixture::ACTOR);
        $result = $repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $row = (new Repository($this->db))->commandReceipt($this->r['operation_uuid']);
        $receipt = $row['receipt'];
        $receipt['historical_affordances_retired'][0]['family'] = 'changed';
        $this->db->table('operational_fact_audits')->where('id', $result['receipt_audit_id'])->update(['new_values' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        $before = Snapshot::snapshot($this->db);
        try {
            $repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
            $this->fail('Changed classification receipt accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('receipt result changed', $e->getMessage());
        }
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    public function testOriginalExactEqualityReceiptStillReplaysWithoutNewHashFields(): void
    {
        $this->db->table('trip_movement_checklist_items')->whereIn('trip_movement_checklist_id', $this->historicalIds)->delete();
        $this->db->table('trip_movement_checklists')->whereIn('id', $this->historicalIds)->delete();
        $repair = new Repair($this->db);
        $preview = $repair->preview($this->r, Fixture::ACTOR);
        $result = $repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $this->assertSame($result['before'], $result['after']);
        $row = (new Repository($this->db))->commandReceipt($this->r['operation_uuid']);
        $receipt = $row['receipt'];
        unset($receipt['result_hash']);
        $this->db->table('operational_fact_audits')->where('id', $result['receipt_audit_id'])->update(['new_values' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        $before = Snapshot::snapshot($this->db);
        $this->assertSame('already_applied', $repair->apply($this->r, Fixture::ACTOR, $preview['confirmation'])['status']);
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    public function testPersistedTaskSideEffectDuringApplyRollsBackEverything(): void
    {
        $p = (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
        $before = Snapshot::snapshot($this->db);
        $repo = new class ($this->db) extends Repository {
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === 'historical_missing_handoff_created') {
                    $this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 8300)->update(['readiness_status' => 'ready']);
                }
            }
        };
        try {
            (new Repair($this->db, $repo))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Persisted checklist mutation accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('preservation', $e->getMessage());
        }
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }

    public function testPurePreviewReadsAreBoundedAsHistoricalWorkspacesIncrease(): void
    {
        $measure = function (): int {
            $queries = [];
            $observer = function (Query $q) use (&$queries): void {
                if ($q->db === $this->db) {
                    $queries[] = $q->getQuery();
                }
            };
            Events::on('DBQuery', $observer);
            try {
                (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
            } finally {
                Events::removeListener('DBQuery', $observer);
            }
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^\s*(SELECT|SHOW|PRAGMA)\b/i', $query);
            }
            return count($queries);
        };
        $small = $measure();
        $row = $this->db->table('trip_movement_checklists')->where('id', $this->historicalIds[0])->get()->getRowArray();
        unset($row['id']);
        for ($i = 1; $i <= 6; $i++) {
            $row['scheduled_at'] = '2024-03-12 07:0' . $i . ':00';
            $this->db->table('trip_movement_checklists')->insert($row);
        }
        $large = $measure();
        // Existing persisted item capture has one bounded read per workspace;
        // the new bulk classification and canonical comparator add no per-row reads.
        $this->assertSame(6, $large - $small);
        $this->assertLessThan(180, $large);
        file_put_contents(dirname(__DIR__, 2) . '/build/v0352-tooling/preservation-query-count.json', json_encode([$small, $large], JSON_THROW_ON_ERROR));
    }

    private function rejectPreview(string $message): void
    {
        $before = Snapshot::snapshot($this->db);
        try {
            (new Repair($this->db))->preview($this->r, Fixture::ACTOR);
            $this->fail('Unsafe synthetic preview accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($before, Snapshot::snapshot($this->db));
    }
}
