<?php

use App\Repositories\HistoricalMovementRepairRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\HistoricalPairedMovementRepairService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\VehiclePositioningPlanService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HistoricalMovementRepairFixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
final class HistoricalPairedMovementRepairTest extends TestCase
{
    private BaseConnection $db;
    private array $request;
    private HistoricalPairedMovementRepairService $service;

    protected function setUp(): void
    {
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true], false);
        VehicleDamageDatabaseFixture::migrate($this->db);
        $this->request = HistoricalMovementRepairFixture::seed($this->db);
        $this->service = new HistoricalPairedMovementRepairService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testClosedPairVoidsStagingAndPreservesLaterAuthorityGapReadinessAndFirewalls(): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $preview = $this->service->preview($this->request, 7);
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $this->assertSame([3002], $preview['historical_movement_gaps']);
        $result = $this->service->apply($this->request, 7, $preview['confirmation']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($result['before'], $result['after']);
        $facts = new OperationalFactsRepository($this->db);
        $current = (new CurrentVehicleCustodyService($facts))->resolve(10);
        $this->assertSame(3003, $current['active_trip_id']);
        $this->assertFalse($current['integrity_conflict']);
        $this->assertSame('rented', (new CurrentVehicleLocationService($facts))->resolve(10)['operational_state']);
        $closed = (new CurrentVehicleCustodyService($facts))->resolve(10, new DateTimeImmutable($this->request['recovery']['occurred_at']));
        $this->assertSame('operator', $closed['custody']);
        $this->assertSame(3001, $closed['basis_trip_id']);
        $this->assertNotNull($facts->event($this->request['invalid_fact']['event_id'])['voided_at']);
        $unknown = $facts->assessment($result['created']['assessment']['id']);
        $this->assertNull($unknown['cleanliness']);
        $this->assertNull($unknown['energy_percent']);
        $after = HistoricalMovementRepairFixture::snapshot($this->db);
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['trip_movement_events', 'movement_assessments', 'operational_fact_audits'], true)) {
                $this->assertSame($rows, $after[$table], $table . ' firewall');
            }
        }
        $this->assertCount(6, $after['operational_fact_audits']);
        $receipt = (new HistoricalMovementRepairRepository($this->db))->receipt(1, 3001);
        $this->assertSame([3002], $receipt['historical_movement_gaps']);
        $this->assertSame('already_applied', $this->service->apply($this->request, 7, $preview['confirmation'])['status']);
        $this->assertSame($after, HistoricalMovementRepairFixture::snapshot($this->db));
        $this->assertSame('already_applied', $this->service->preview($this->request, 7)['status']);
        $this->assertSame($after, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public function testNoLaterAuthorityIsPermanentlyRejected(): void
    {
        $this->db->table('trip_movement_events')->where('turo_trip_normalized_id', 3003)->delete();
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testPreviewExecutesOnlyReadQueries(): void
    {
        $queries = [];
        $observer = function (Query $query) use (&$queries): void {
            if ($query->db === $this->db) {
                $queries[] = $query->getQuery();
            }
        };
        Events::on('DBQuery', $observer);
        try {
            $this->service->preview($this->request, 7);
        } finally {
            Events::removeListener('DBQuery', $observer);
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(SELECT|PRAGMA|SHOW)\b/i', $sql);
        }
    }

    public function testExistingRootTransactionCannotDowngradeRepairLocks(): void
    {
        $this->db->transBegin();
        $this->assertRejectedWithoutWrites($this->request);
        $this->db->transRollback();
    }

    public function testVoidedPriorRepairCannotBeReplayedOrRecreated(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $result = $this->service->apply($this->request, 7, $preview['confirmation']);
        (new MovementEventService(new OperationalFactsRepository($this->db)))->void($result['created']['handoff']['id'], 7, 'Synthetic subsequent review');
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testCurrentPermissionMatrixOverrideIsHonored(): void
    {
        $this->db->query('CREATE TABLE settings (id INTEGER PRIMARY KEY, class TEXT, "key" TEXT, context TEXT, value TEXT, type TEXT DEFAULT \'array\')');
        $this->db->table('settings')->insert(['id' => 1, 'class' => 'Config\\AuthGroups', 'key' => 'matrix', 'context' => null, 'value' => serialize(['superadmin' => []])]);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testPairDoesNotRequireInventedReplacementStaging(): void
    {
        $this->db->table('movement_assessments')->where('id', $this->request['invalid_fact']['assessment_id'])->delete();
        $this->db->table('trip_movement_events')->where('id', $this->request['invalid_fact']['event_id'])->delete();
        $r = $this->request;
        $r['invalid_fact'] = null;
        $preview = $this->service->preview($r, 7);
        $this->assertSame('applied', $this->service->apply($r, 7, $preview['confirmation'])['status']);
        $this->assertSame(0, $this->db->table('trip_movement_events')->where(['turo_trip_normalized_id' => 3001, 'event_code' => 'vehicle_staged'])->countAllResults());
    }

    public function testAcceptedVoidCannotInvalidateUnrelatedPositioningPlan(): void
    {
        $facts = new OperationalFactsRepository($this->db);
        (new VehiclePositioningPlanService($facts))->create(10, 'hold_home_flexible', 'home', 'synthetic_review', null, 'not_applicable', $facts->event($this->request['expected_authority']['custody_event_id']), $facts->tripSchedule(3003), null, 7);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testLinkedAssessmentOnAnotherTripCannotBeVoided(): void
    {
        $this->db->table('movement_assessments')->where('id', $this->request['invalid_fact']['assessment_id'])->update(['turo_trip_normalized_id' => 3002]);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testLinkedAssessmentInAnotherCompanyCannotBeVoided(): void
    {
        $this->db->table('movement_assessments')->where('id', $this->request['invalid_fact']['assessment_id'])->update(['company_id' => 2, 'fleet_vehicle_id' => 20, 'turo_trip_normalized_id' => 200]);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testReceiptSideEffectCannotEscapeFinalPreservationGate(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $repository = new class ($this->db) extends HistoricalMovementRepairRepository {
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === 'historical_pair_applied') {
                    $this->db->table('trip_movement_checklists')->where('turo_trip_normalized_id', 3003)->update(['completion_note' => 'Synthetic late side effect']);
                }
            }
        };
        try {
            (new HistoricalPairedMovementRepairService($this->db, $repository))->apply($this->request, 7, $preview['confirmation']);
            $this->fail('Receipt side effect accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Final repair/receipt preservation failed', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsUnsafeInputWithoutWrites(string $field, mixed $value): void
    {
        $r = $this->request;
        $parts = explode('.', $field);
        if (count($parts) === 2) {
            $r[$parts[0]][$parts[1]] = $value;
        } else {
            $r[$field] = $value;
        }
        $this->assertRejectedWithoutWrites($r);
    }

    public static function invalidRequests(): iterable
    {
        yield 'open' => ['recovery', null];
        yield 'equal' => ['recovery.occurred_at', '2025-10-07 08:25:00'];
        yield 'reversed' => ['recovery.occurred_at', '2025-10-06 08:25:00'];
        yield 'invalid date' => ['handoff.occurred_at', '2025-02-30 08:25:00'];
        yield 'future' => ['recovery.occurred_at', '2099-10-08 21:06:00'];
        yield 'unrelated company' => ['company_id', 2];
        yield 'unrelated vehicle' => ['vehicle_id', 11];
        yield 'unrelated trip' => ['trip_id', 101];
        yield 'wrong checklist' => ['pickup_checklist_id', 999999];
        yield 'no reason' => ['reason', ''];
        yield 'no context' => ['context', ''];
        yield 'measurement' => ['energy_percent', 80];
        yield 'unknown location' => ['handoff.location_class', 'unknown'];
        yield 'wrong assessment' => ['invalid_fact.assessment_id', 999999];
        yield 'later staging void' => ['invalid_fact.event_id', 999999];
        yield 'stale basis' => ['expected_authority.custody_event_id', 999999];
    }

    public function testAdminAndInactiveActorCannotRepair(): void
    {
        $this->assertRejectedWithoutWrites($this->request, 8);
        $this->db->table('users')->where('id', 7)->update(['active' => 0]);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testObservedOverlapRejectsIncludingBoundary(): void
    {
        $events = new MovementEventService(new OperationalFactsRepository($this->db));
        $events->record(10, 3002, 'actual_handoff', 'pickup', $this->request['handoff']['occurred_at'], 'other_delivery', 'Other Lodge', 'operator', 7);
        $events->record(10, 3002, 'vehicle_recovered', 'return', '2025-10-09 09:00:00', 'home', 'Synthetic home', 'operator', 7);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testUnassignedPositionMovementInsideIntervalRejects(): void
    {
        (new MovementEventService(new OperationalFactsRepository($this->db)))->record(10, null, 'vehicle_positioned', null, '2025-10-08 09:00:00', 'home', 'Synthetic competing position', 'operator', 7);
        $this->assertRejectedWithoutWrites($this->request);
    }

    public function testCrossingWallClockSecondDoesNotHideNewFactsFromPreservationGate(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $repository = new class ($this->db) extends HistoricalMovementRepairRepository {
            private bool $delayed = false;
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if (! $this->delayed) {
                    $this->delayed = true;
                    usleep(1100000);
                }
            }
        };
        $result = (new HistoricalPairedMovementRepairService($this->db, $repository))->apply($this->request, 7, $preview['confirmation']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($result['before'], $result['after']);
    }

    public function testStalePreviewAndConflictingReplayReject(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $this->db->table('trip_movement_checklists')->where('id', $this->request['pickup_checklist_id'])->update(['completion_note' => 'Changed since preview']);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->service->apply($this->request, 7, $preview['confirmation']);
            $this->fail('Stale preview accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $preview = $this->service->preview($this->request, 7);
        $this->service->apply($this->request, 7, $preview['confirmation']);
        $conflict = $this->request;
        $conflict['recovery']['location_detail'] = 'Different Lodge';
        $this->assertRejectedWithoutWrites($conflict);
    }

    public function testAuditFailureRollsBackVoidBothEventsAssessmentAndReceipt(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $repository = new class ($this->db) extends HistoricalMovementRepairRepository {
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($action === 'historical_pair_applied') {
                    throw new RuntimeException('Synthetic receipt failure.');
                }
            }
        };
        try {
            (new HistoricalPairedMovementRepairService($this->db, $repository))->apply($this->request, 7, $preview['confirmation']);
            $this->fail('Failure accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic receipt failure.', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testPreservationFailureRollsBackUnexpectedLaterChange(): void
    {
        $preview = $this->service->preview($this->request, 7);
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        $repository = new class ($this->db) extends HistoricalMovementRepairRepository {
            public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
            {
                parent::audit($company, $table, $record, $action, $actor, $old, $new);
                if ($table === 'movement_assessments') {
                    $this->db->table('trip_movement_events')->where('turo_trip_normalized_id', 3003)->update(['location_detail' => 'Unexpected change']);
                }
            }
        };
        try {
            (new HistoricalPairedMovementRepairService($this->db, $repository))->apply($this->request, 7, $preview['confirmation']);
            $this->fail('Preservation failure accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    public function testOrdinaryCompletedTripHandoffRemainsRejected(): void
    {
        $facts = new OperationalFactsRepository($this->db);
        $ordinary = new MovementOperationalFactService($this->db, new MovementEventService($facts));
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $ordinary->recordRetroactiveHandoff(1, 3001, $this->request['handoff'], 7);
            $this->fail('Ordinary completed-trip handoff accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }

    private function assertRejectedWithoutWrites(array $request, int $actor = 7): void
    {
        $before = HistoricalMovementRepairFixture::snapshot($this->db);
        try {
            $this->service->preview($request, $actor);
            $this->fail('Unsafe repair accepted.');
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($before, HistoricalMovementRepairFixture::snapshot($this->db));
    }
}
