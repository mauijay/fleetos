<?php

use App\Repositories\HistoricalMissingHandoffRepairRepository as Repository;
use App\Repositories\LookupRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingHandoffRepairService as Repair;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMissingHandoffFixture as Fixture;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
final class HistoricalMissingHandoffRepairTest extends TestCase
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

    public function testPreviewIsPureAndOneEventPreservesEveryOtherRowAndProjection(): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $preview = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $this->assertSame(Repair::OPERATION, $preview['operation']);
        $this->assertFalse($preview['assessment_creation']);
        $this->assertSame($this->r['recovery_event_id'], (int) $preview['boundaries']['recovery']['id']);
        $this->assertCount(1, $preview['boundaries']['guest_return_staged']);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($result['before'], $result['after']);
        $this->assertFalse($result['assessment_created']);
        $event = (new OperationalFactsRepository($this->db))->event($result['event_id']);
        $this->assertSame('actual_handoff', $event['event_code']);
        $this->assertSame('pickup', $event['movement_type']);
        $this->assertSame('international', $event['airport_garage_code']);
        $this->assertSame(2, (int) $event['airport_parking_level']);
        $this->assertSame('F', $event['airport_parking_row']);
        $this->assertSame('Synthetic charging bay', $event['note']);
        $this->assertSame($this->r['handoff']['occurred_at'], $event['occurred_at']);
        $this->assertGreaterThan($event['occurred_at'], $event['created_at']);
        $after = HistoricalMovementRepairFixture::snapshot($this->db);
        $this->assertCount(count($before['trip_movement_events']) + 1, $after['trip_movement_events']);
        $this->assertCount(count($before['operational_fact_audits']) + 2, $after['operational_fact_audits']);
        foreach ($before as $table => $rows) {
            if ($table === 'trip_movement_events') {
                $rowsAfter = array_values(array_filter($after[$table], static fn ($e) => (int) $e['id'] !== $result['event_id']));
                $this->assertSame($rows, $rowsAfter);
            } elseif ($table !== 'operational_fact_audits') {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $receipt = (new Repository($this->db))->commandReceipt($this->r['operation_uuid']);
        $this->assertSame($result['receipt_audit_id'], (int) $receipt['audit']['id']);
        $this->assertSame($this->r['attestation'], $receipt['receipt']['attestation']);
        $replay = $this->repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
        $this->assertSame('already_applied', $replay['status']);
        foreach (['event_id', 'creation_audit_id', 'receipt_audit_id'] as $key) {
            $this->assertSame($result[$key], $replay[$key]);
        }
        $this->assertSame('already_applied', $this->repair->preview($this->r, Fixture::ACTOR)['status']);
        $this->assertSame($after, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public function testPreviewIssuesOnlyReadQueries(): void
    {
        $queries = [];
        $observer = function (Query $q) use (&$queries): void {
            if ($q->db === $this->db) {
                $queries[] = $q->getQuery();
            }
        };
        Events::on('DBQuery', $observer);
        try {
            $this->repair->preview($this->r, Fixture::ACTOR);
        } finally {
            Events::removeListener('DBQuery', $observer);
        }
        $this->assertNotEmpty($queries);
        file_put_contents(dirname(__DIR__, 2) . '/build/v0352-tooling/preview-query-count.txt', (string) count($queries));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(SELECT|SHOW|PRAGMA)\b/i', $sql);
        }
    }

    #[DataProvider('shapes')]
    public function testDirectRecoveryAndImportedStatuses(string $shape): void
    {
        if ($shape === 'direct') {
            $this->db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'guest_return_staged'])->delete();
        }
        if ($shape === 'completed') {
            $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['trip_status_lookup_value_id' => (new LookupRepository($this->db))->valueId('trip_status', 'completed')]);
        }
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame('applied', $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation'])['status']);
    }

    public static function shapes(): array
    {
        return [['direct'], ['completed'], ['in_progress']];
    }

    #[DataProvider('badInputs')]
    public function testMalformedOrUnownedInputFailsWithoutWrites(string $field, mixed $value): void
    {
        $r = $this->r;
        $parts = explode('.', $field);
        if (count($parts) === 2) {
            $r[$parts[0]][$parts[1]] = $value;
        } else {
            $r[$field] = $value;
        }
        $this->reject($r);
    }

    public static function badInputs(): iterable
    {
        yield ['company_id', 999];
        yield ['vehicle_id', 10];
        yield ['trip_id', 3001];
        yield ['pickup_checklist_id', 999];
        yield ['recovery_event_id', 999];
        yield ['recovery_assessment_id', null];
        yield ['reason', ''];
        yield ['attestation', ''];
        yield ['operation_uuid', 'not-a-uuid'];
        yield ['actor', 7];
        yield ['energy_percent', 80];
        yield ['handoff.energy_percent', 80];
        yield ['handoff.occurred_at', '2024-02-30 11:27:00'];
        yield ['handoff.occurred_at', '2024-03-17 14:00:00'];
        yield ['handoff.occurred_at', '2024-03-12 09:00:00'];
        yield ['handoff.location_class', 'unknown'];
        yield ['handoff.airport_parking_level', 'malformed'];
        yield ['handoff.airport_parking_row', 'JUNK'];
        yield ['handoff.airport_garage_code', 'unknown-garage'];
        yield ['handoff.location_detail', 'Conflicting detail'];
    }

    #[DataProvider('unsafeStates')]
    public function testUnsafeChronologyOrBoundaryRejects(string $state): void
    {
        $events = new MovementEventService(new OperationalFactsRepository($this->db));
        if ($state === 'duplicate') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, 'actual_handoff', 'pickup', $this->r['handoff']['occurred_at'], 'home', null, 'synthetic_operator', Fixture::ACTOR);
        }
        if ($state === 'multiple') {
            $events->record(Fixture::VEHICLE, Fixture::TRIP, 'vehicle_recovered', 'return', '2024-03-17 15:00:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        }
        if ($state === 'voided') {
            $this->db->table('trip_movement_events')->where('id', $this->r['recovery_event_id'])->update(['voided_at' => '2024-03-18 09:00:00']);
        }
        if ($state === 'superseded') {
            $this->db->table('trip_movement_events')->where('id', $this->r['recovery_event_id'])->update(['supersedes_event_id' => $this->r['recovery_event_id'] - 1]);
        }
        if ($state === 'pickup_assessment') {
            $this->db->table('movement_assessments')->where('id', $this->r['recovery_assessment_id'])->update(['movement_type' => 'pickup']);
        }
        if ($state === 'overlap') {
            $events->record(Fixture::VEHICLE, 8300, 'actual_handoff', 'pickup', '2024-03-13 09:00:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        }
        if ($state === 'tie') {
            $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, $this->r['handoff']['occurred_at'], 'home', null, 'synthetic_operator', Fixture::ACTOR);
        }
        if ($state === 'interval_position') {
            $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, '2024-03-15 09:00:00', 'home', null, 'synthetic_operator', Fixture::ACTOR);
        }
        $this->reject($this->r);
    }

    public static function unsafeStates(): array
    {
        return array_map(static fn ($x) => [$x], ['duplicate','multiple','voided','superseded','pickup_assessment','overlap','tie','interval_position']);
    }

    #[DataProvider('staleFields')]
    public function testStalePreviewRejectsEveryPreservationBoundary(string $field): void
    {
        $preview = $this->repair->preview($this->r, Fixture::ACTOR);
        match ($field) {
            'position' => $this->db->table('trip_movement_events')->where(['fleet_vehicle_id' => Fixture::VEHICLE,'event_code' => 'vehicle_positioned'])->update(['location_detail' => 'Synthetic changed depot']),
            'recovery' => $this->db->table('trip_movement_events')->where('id', $this->r['recovery_event_id'])->update(['note' => 'Synthetic changed recovery']),
            'assessment' => $this->db->table('movement_assessments')->where('id', $this->r['recovery_assessment_id'])->update(['energy_percent' => 62]),
            'schedule' => $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->update(['starts_at' => '2024-03-14 11:10:00']),
            'future' => $this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 8302)->update(['completion_note' => 'Synthetic changed future workspace']),
            'audit' => (new Repository($this->db))->audit(Fixture::COMPANY, 'trip_movement_events', $this->r['recovery_event_id'], 'synthetic_review', Fixture::ACTOR, [], ['note' => 'Synthetic new review']),
            default => throw new RuntimeException('Unknown synthetic authority change.'),
        };
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($this->r, Fixture::ACTOR, $preview['confirmation']);
            $this->fail('Stale apply accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public static function staleFields(): array
    {
        return array_map(static fn ($x) => [$x], ['position','recovery','assessment','schedule','future','audit']);
    }

    public function testAuthorizationAndOrdinaryGuardRemainStrict(): void
    {
        $this->reject($this->r, Fixture::ADMIN);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            (new MovementOperationalFactService($this->db, new MovementEventService(new OperationalFactsRepository($this->db))))->recordRetroactiveHandoff(Fixture::COMPANY, Fixture::TRIP, ['occurred_at' => '2024-03-14T11:27','location_class' => 'home'], Fixture::ACTOR);
            $this->fail('Ordinary writer weakened');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('later return or recovery', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $this->db->table('users')->where('id', Fixture::ACTOR)->update(['active' => 0]);
        $this->reject($this->r);
    }

    public function testReceiptSideEffectRollsBackEventBothAuditsAndAssessmentChange(): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $repo = new class ($this->db) extends Repository {
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === self::RECEIPT_ACTION) {
                    $this->db->table('movement_assessments')->where('id', $new['preserved_recovery_assessment_id'])->update(['energy_percent' => 1]);
                }
            }
        };
        try {
            (new Repair($this->db, $repo))->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Preservation failure accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('preservation', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    #[DataProvider('uuidChanges')]
    public function testUuidCannotBeReusedWithChangedSemanticPayloadOrActor(string $field): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $r = $this->r;
        $actor = Fixture::ACTOR;
        if ($field === 'actor') {
            $actor = 7;
        } elseif ($field === 'time') {
            $r['handoff']['occurred_at'] = '2024-03-14 11:28:00';
        } elseif ($field === 'location') {
            $r['handoff']['location_note'] = 'Different synthetic note';
        } elseif ($field === 'trip') {
            $r['trip_id'] = 8302;
        } else {
            $r['reason'] = 'Different synthetic reason';
        }
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($r, $actor, $p['confirmation']);
            $this->fail('UUID mismatch accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UUID', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }
    public static function uuidChanges(): array
    {
        return [['actor'],['time'],['location'],['trip'],['reason']];
    }

    public function testDelayedPreviewKeepsDeterministicFingerprint(): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        usleep(1100000);
        $later = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->assertSame($p, $later);
        $this->assertSame('applied', $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation'])['status']);
    }

    public function testNoAssessmentRemainsMissing(): void
    {
        $this->db->table('movement_assessments')->where('id', $this->r['recovery_assessment_id'])->delete();
        $this->r['recovery_assessment_id'] = null;
        $before = $this->db->table('movement_assessments')->get()->getResultArray();
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($before, $this->db->table('movement_assessments')->get()->getResultArray());
    }

    #[DataProvider('receiptDamage')]
    public function testLostAcknowledgementRecoversIdsAndDamagedReceiptFailsClosed(string $damage): void
    {
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']); // Deliberately discard the acknowledgement.
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame('already_applied', $result['status']);
        if ($damage === 'event') {
            $this->db->table('trip_movement_events')->where('id', $result['event_id'])->update(['voided_at' => date('Y-m-d H:i:s')]);
        } elseif ($damage === 'audit') {
            $this->db->table('operational_fact_audits')->where('id', $result['creation_audit_id'])->update(['actor_user_id' => Fixture::ADMIN]);
        } elseif ($damage === 'result') {
            $row = (new Repository($this->db))->commandReceipt($this->r['operation_uuid']);
            $receipt = $row['receipt'];
            $receipt['result']['event_id'] = $this->r['recovery_event_id'];
            $this->db->table('operational_fact_audits')->where('id', $result['receipt_audit_id'])->update(['new_values' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        } else {
            $this->db->table('operational_fact_audits')->where('id', $result['receipt_audit_id'])->update(['record_id' => 8302]);
        }
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
            $this->fail('Changed receipt accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public static function receiptDamage(): array
    {
        return [['event'], ['audit'], ['scope'], ['result']];
    }

    public function testLaterClosedGuestIntervalIsPreserved(): void
    {
        $events = new MovementEventService(new OperationalFactsRepository($this->db));
        $laterTrip = $this->db->table('turo_trips_normalized')->where('id', Fixture::TRIP)->get()->getRowArray();
        $laterTrip = array_replace($laterTrip, ['id' => 8303, 'starts_at' => '2024-03-20 09:00:00', 'ends_at' => '2024-03-21 09:00:00', 'turo_trip_id' => 'SYNTHETIC-LATER-CLOSED', 'turo_reservation_id' => 'SYNTHETIC-LATER-RES', 'trip_status_lookup_value_id' => (new LookupRepository($this->db))->valueId('trip_status', 'completed')]);
        $this->db->table('turo_trips_normalized')->insert($laterTrip);
        $events->record(Fixture::VEHICLE, 8303, 'actual_handoff', 'pickup', '2024-03-20 09:00:00', 'home', 'Synthetic later depot', 'synthetic_operator', Fixture::ACTOR);
        $events->record(Fixture::VEHICLE, 8303, 'vehicle_recovered', 'return', '2024-03-21 09:00:00', 'home', 'Synthetic later depot', 'synthetic_operator', Fixture::ACTOR);
        $p = $this->repair->preview($this->r, Fixture::ACTOR);
        $result = $this->repair->apply($this->r, Fixture::ACTOR, $p['confirmation']);
        $this->assertSame($result['before'], $result['after']);
        $this->assertCount(3, $p['boundaries']['later_events']);
    }

    public function testContradictoryReturnReportAndOwnershipFailClosed(): void
    {
        $this->db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'guest_return_staged'])->update(['occurred_at' => '2024-03-13 09:00:00']);
        $this->reject($this->r);
        $this->db->table('trip_movement_events')->where(['turo_trip_normalized_id' => Fixture::TRIP, 'event_code' => 'guest_return_staged'])->update(['occurred_at' => '2024-03-17 13:15:00']);
        $this->db->table('movement_assessments')->where('id', $this->r['recovery_assessment_id'])->update(['company_id' => 1]);
        $this->reject($this->r);
    }

    public function testNestedTransactionAndUnconfirmedApplyReject(): void
    {
        $this->db->transBegin();
        try {
            $this->repair->preview($this->r, Fixture::ACTOR);
            $this->fail('Nested transaction accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('root transaction', $e->getMessage());
        } finally {
            $this->db->transRollback();
        }
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->apply($this->r, Fixture::ACTOR, '');
            $this->fail('Unconfirmed apply accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preview hash', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public function testHistoricalHandoffWouldRetireAnotherWorkspaceReadinessAndIsRejected(): void
    {
        $facts = new OperationalFactsRepository($this->db);
        (new App\Services\Fleet\TripMovementChecklistService(new App\Repositories\MovementChecklistRepository($this->db)))->ensureForMovement($facts->tripSchedule(8300), 'return');
        $this->reject($this->r);
    }

    private function reject(array $r, int $actor = Fixture::ACTOR): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->repair->preview($r, $actor);
            $this->fail('Unsafe preview accepted');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }
}
