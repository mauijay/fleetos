<?php

use App\Repositories\TripCommitmentRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\TripCommitmentService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GuestCommitmentProjectionFixture as Fixture;

/** @internal */
final class GuestCommitmentProjectionTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Fixture::sqlite();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    private function projection(): array
    {
        return Fixture::service($this->connection)->forTrip(1, 100, Fixture::clock());
    }

    public function testRootCausePurchasedExtraVisibleInExtrasButMissingFromManualAuthority(): void
    {
        $manual = new TripCommitmentService(new TripCommitmentRepository($this->connection));
        $this->assertSame([], $manual->activeForTrip(1, 100));
        $this->assertCount(1, Fixture::fulfillments($this->connection)->forTrips(1, [100])[100]);
        $projection = $this->projection();
        $this->assertSame(1, $projection['count']);
        $this->assertFalse($projection['can_show_empty']);
        $this->assertSame('purchased_extra', $projection['rows'][0]['source_kind']);
        $this->assertSame('extra_selection:1', $projection['rows'][0]['identity']);
        $this->assertSame('1:100:extra_selection:1', $projection['rows'][0]['qualified_identity']);
    }

    public function testEmptyRequiresTrustedCompleteEmptyAndNoManualRows(): void
    {
        Fixture::snapshot($this->connection, [], '2030-01-02 19:00:00', true, true);
        $empty = $this->projection();
        $this->assertSame([], $empty['rows']);
        $this->assertTrue($empty['can_show_empty']);
        $this->assertCount(1, $empty['purchased_history']);
        $this->connection->table('turo_extra_reservation_snapshots')->update(['snapshot_complete' => 0]);
        $this->assertFalse($this->projection()['can_show_empty']);
    }

    public static function observationCases(): iterable
    {
        yield 'fresh complete' => ['fresh', true];
        yield 'stale complete' => ['stale', false];
        yield 'partial' => ['partial', false];
        yield 'failed' => ['failed', false];
        yield 'conflict' => ['conflict', false];
        yield 'future complete' => ['future', false];
    }

    #[DataProvider('observationCases')]
    public function testObservationTruthAndFreshness(string $case, bool $qualifies): void
    {
        if ($case === 'stale') {
            $this->connection->table('turo_extra_reservation_snapshots')->update(['observed_at' => '2029-12-30 18:00:00']);
        } elseif ($case === 'partial' || $case === 'future') {
            Fixture::snapshot($this->connection, [], $case === 'future' ? '2030-01-04 19:00:00' : '2030-01-02 19:00:00', $case === 'future');
        } elseif ($case === 'failed' || $case === 'conflict') {
            $this->connection->table('turo_import_errors')->insert([
                'turo_import_batch_id' => 1, 'raw_table' => 'turo_extra_reservation_snapshots',
                'error_code' => $case === 'conflict' ? 'extras_observation_conflict' : 'extras_export_failure', 'message' => 'Synthetic refresh issue',
                'raw_payload' => json_encode(['company_id' => 1, 'reservation_id' => '80000100', 'observed_at' => '2030-01-02 19:00:00'], JSON_THROW_ON_ERROR),
            ]);
        }
        $projection = $this->projection();
        $this->assertCount(1, $projection['purchased']);
        $this->assertSame($qualifies, $projection['verification']['qualifies_for_preparation']);
        $this->assertSame(! $qualifies, $projection['purchased'][0]['is_last_known']);
        $this->assertFalse($projection['can_show_empty']);
    }

    public static function configurationCases(): iterable
    {
        yield 'pack' => ['pack', 'preparation', true];
        yield 'install' => ['install', 'pickup', true];
        yield 'feature configure' => ['configure', 'preparation', true];
        yield 'one way logistics' => ['logistics', 'return', true];
        yield 'prepaid information' => ['informational', 'entire_trip', false];
        yield 'unconfigured' => ['none', null, false];
    }

    public function testFutureSelectionChangesCannotOfferActionsAgainstDifferentTrustedWork(): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $this->assertTrue($this->projection()['purchased'][0]['is_actionable']);
        Fixture::snapshot($this->connection, [Fixture::item(['quantity' => '3.000'])], '2030-01-04 19:00:00', true, true);
        $extra = $this->projection()['purchased'][0];
        $this->assertSame('2', $extra['quantity_label']);
        $this->assertTrue($extra['synchronization_required']);
        $this->assertFalse($extra['is_actionable']);
        $this->assertSame([], $extra['permitted_actions']);
        Fixture::snapshot($this->connection, [], '2030-01-05 19:00:00', true, true);
        $removedAhead = $this->projection()['purchased'][0];
        $this->assertTrue($removedAhead['synchronization_required']);
        $this->assertFalse($removedAhead['is_actionable']);
    }

    #[DataProvider('configurationCases')]
    public function testBehaviorUsesConfigurationWithoutLabelGuessing(string $type, ?string $phase, bool $confirmation): void
    {
        $this->connection->table('fleet_extras')->where('id', 301)->update([
            'fulfillment_type' => $type, 'fulfillment_phase' => $phase, 'requires_operator_confirmation' => $confirmation ? 1 : 0,
            'readiness_blocking' => $confirmation ? 1 : 0, 'default_action_label' => $confirmation ? 'Synthetic operator action' : null,
        ]);
        $extra = $this->projection()['purchased'][0];
        $this->assertSame($type, $extra['fulfillment_type']);
        $this->assertSame($confirmation, $extra['synchronization_required']);
        $this->assertSame($confirmation, $extra['is_blocking']);
        $this->assertFalse($extra['is_completed']);
    }

    public function testDistinctSelectionsNullableFractionalQuantityDisabledAndUnmapped(): void
    {
        Fixture::snapshot($this->connection, [Fixture::item(['quantity' => null]), Fixture::item(['reservation_state_extra_id' => '910002', 'quantity' => '1.500']), Fixture::item(['reservation_state_extra_id' => '910003', 'extra_id' => '900002'])], '2030-01-02 19:00:00', true, true);
        $this->connection->table('fleet_extras')->where('id', 301)->update(['active' => 0]);
        $rows = $this->projection()['purchased'];
        $this->assertCount(3, $rows);
        $this->assertCount(3, array_unique(array_column($rows, 'identity')));
        $this->assertTrue($rows[0]['quantity_unknown']);
        $this->assertSame('1.5', $rows[1]['quantity_label']);
        $this->assertSame('disabled', $rows[0]['configuration_state']);
        $this->assertSame('unmapped', $rows[2]['configuration_state']);
        $this->assertFalse($rows[2]['is_blocking']);
        $this->assertNull($rows[2]['fulfillment_type']);
    }

    public function testManualOverlapAmbiguityAndIndependentAcknowledgmentCompletion(): void
    {
        $id = Fixture::manual($this->connection, ['fleet_extra_id' => 301]);
        Fixture::snapshot($this->connection, [Fixture::item(), Fixture::item(['reservation_state_extra_id' => '910002'])], '2030-01-02 19:00:00', true, true);
        $projection = $this->projection();
        $this->assertSame(3, $projection['count']);
        $this->assertStringContainsString('multiple purchased selections', $projection['manual'][0]['overlap_warning']);
        (new TripCommitmentService(new TripCommitmentRepository($this->connection)))->complete(1, 100, $id, 7);
        $this->assertSame(2, $this->projection()['count']);
        $this->assertFalse($this->projection()['purchased'][0]['is_completed']);
        $ack = Fixture::manual($this->connection, ['handling_mode' => 'acknowledgment']);
        (new TripCommitmentService(new TripCommitmentRepository($this->connection)))->acknowledge(1, 100, $ack, 7);
        $this->assertFalse($this->projection()['manual'][0]['is_blocking']);
        (new TripCommitmentService(new TripCommitmentRepository($this->connection)))->cancel(1, 100, $ack, 'Synthetic cancellation', 7);
        $this->assertSame([], $this->projection()['manual']);
    }

    public static function basisCases(): iterable
    {
        yield 'quantity' => ['quantity'];
        yield 'configuration' => ['configuration'];
        yield 'mapping' => ['mapping'];
        yield 'vehicle' => ['vehicle'];
        yield 'price independence' => ['price'];
    }

    #[DataProvider('basisCases')]
    public function testRecomputesBasisWithoutGetRepair(string $change): void
    {
        $selection = (int) $this->projection()['purchased'][0]['selection_id'];
        $fulfillments = Fixture::fulfillments($this->connection);
        $fulfillments->reconcileSelectionIds(1, [$selection]);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        $manual = Fixture::manual($this->connection, ['fleet_extra_id' => 301]);
        $fulfillments->complete(1, 100, $id, 7);
        $this->assertTrue($this->projection()['purchased'][0]['is_completed']);
        $this->assertSame($manual, (int) $this->projection()['manual'][0]['id']);
        $before = (new TripExtraFulfillmentRepository($this->connection))->fulfillmentForSelection(1, $selection);
        if ($change === 'quantity' || $change === 'price') {
            Fixture::snapshot($this->connection, [Fixture::item([$change === 'quantity' ? 'quantity' : 'price' => $change === 'quantity' ? '3.000' : '999.00'])], '2030-01-02 19:00:00', true, true);
        } elseif ($change === 'configuration') {
            $this->connection->table('fleet_extras')->where('id', 301)->update(['fulfillment_type' => 'install']);
        } elseif ($change === 'vehicle') {
            $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['fleet_vehicle_id' => 11]);
        } else {
            $this->connection->table('fleet_extras')->insert(['id' => 302, 'company_id' => 1, 'code' => 'synthetic-other', 'display_name' => 'Synthetic other gear', 'fulfillment_type' => 'pack', 'fulfillment_phase' => 'preparation', 'requires_operator_confirmation' => 1, 'readiness_blocking' => 1, 'default_action_label' => 'Pack other gear', 'created_at' => Fixture::OBSERVED, 'updated_at' => Fixture::OBSERVED]);
            $this->connection->table('fleet_extra_source_mappings')->update(['fleet_extra_id' => 302]);
        }
        $after = $this->projection()['purchased'][0];
        $this->assertSame($change === 'price', $after['is_completed']);
        $this->assertSame($before, (new TripExtraFulfillmentRepository($this->connection))->fulfillmentForSelection(1, $selection));
        $audits = (new TripExtraFulfillmentRepository($this->connection))->audits(1, $id);
        $values = json_decode($audits[count($audits) - 1]['after_values'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $values['operational_context']['version']);
    }

    public function testMissingFulfillmentBlocksReadinessAndGetIsPure(): void
    {
        $authorities = static fn (BaseConnection $db): array => array_map(
            static fn (string $table): array => $db->table($table)->get()->getResultArray(),
            ['turo_trips_normalized', 'fleet_vehicles', 'trip_movement_events', 'operating_expenses', 'turo_extra_selections'],
        );
        $before = $authorities($this->connection);
        $projection = $this->projection();
        $readiness = (new MovementReadinessProjectionService())->projectTripPreparation([
            'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10,
            'extra_fulfillments' => $projection['purchased'], 'extra_verification' => $projection['verification'],
            'active_commitments' => [], 'active_events' => [],
        ]);
        $this->assertSame(1, $readiness['blocking_remaining_count']);
        $this->assertTrue($readiness['requirements'][1]['synchronization_required']);
        $this->assertNull($readiness['requirements'][1]['action']);
        $this->assertSame(0, $this->connection->table('trip_extra_fulfillments')->countAllResults());
        $this->assertSame(0, $this->connection->table('fleet_trip_commitments')->countAllResults());
        $this->assertSame($before, $authorities($this->connection), 'Projection/readiness preserve schedule, vehicle, movement, financial and commercial authorities.');
    }

    public function testRemovalHistoryWithoutFulfillmentAndReappearance(): void
    {
        Fixture::snapshot($this->connection, [], '2030-01-02 18:30:00', true, true);
        $history = $this->projection()['purchased_history'];
        $this->assertCount(1, $history);
        $this->assertSame('historical_unknown', $history[0]['configuration_state']);
        Fixture::snapshot($this->connection, [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $this->assertCount(1, $this->projection()['purchased']);
        $this->assertSame([], $this->projection()['purchased_history']);
        $this->assertTrue($this->projection()['purchased'][0]['synchronization_required']);
    }

    public function testRemovedFulfillmentUsesFrozenConfigurationAndLegacyHistoryStaysUnknown(): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        Fixture::snapshot($this->connection, [], '2030-01-02 19:00:00', true, true);
        $this->connection->table('fleet_extras')->where('id', 301)->update([
            'display_name' => 'Synthetic changed catalog', 'fulfillment_type' => 'configure',
            'fulfillment_phase' => 'return', 'default_action_label' => 'Synthetic changed action', 'active' => 0,
        ]);
        $history = $this->projection()['purchased_history'][0];
        $this->assertTrue($history['is_completed']);
        $this->assertSame('historical_frozen', $history['configuration_state']);
        $this->assertSame('Synthetic Beach Gear', $history['title']);
        $this->assertSame('pack', $history['fulfillment_type']);
        $this->assertSame('preparation', $history['phase']);
        $this->assertCount(2, $this->connection->table('turo_extra_reservation_snapshots')->get()->getResultArray());
        $this->assertSame([], $history['permitted_actions']);
        // Synthetic legacy audits predate prospective operational context.
        foreach ($this->connection->table('trip_extra_fulfillment_audits')->get()->getResultArray() as $audit) {
            $values = json_decode($audit['after_values'], true, 512, JSON_THROW_ON_ERROR);
            unset($values['operational_context']);
            $this->connection->table('trip_extra_fulfillment_audits')->where('id', $audit['id'])->update(['after_values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        }
        $legacy = $this->projection()['purchased_history'][0];
        $this->assertTrue($legacy['is_completed']);
        $this->assertSame('historical_unknown', $legacy['configuration_state']);
        $this->assertSame('Synthetic source gear', $legacy['title']);
        $this->assertNull($legacy['fulfillment_type']);
        $this->assertNull($legacy['phase']);
    }

    public function testMissedReactivationReconciliationCannotReuseOldCompletion(): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        Fixture::snapshot($this->connection, [], '2030-01-02 18:30:00', true, true);
        Fixture::snapshot($this->connection, [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $this->assertFalse($this->projection()['purchased'][0]['is_completed']);
        $this->assertTrue($this->projection()['purchased'][0]['synchronization_required']);
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        $this->assertTrue($this->projection()['purchased'][0]['is_completed']);
    }

    public function testOtherReservationAliasCannotInvalidateConfirmedPurchase(): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['turo_reservation_id' => '80000999']);
        Fixture::snapshot($this->connection, [], '2030-01-02 18:30:00');
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['turo_reservation_id' => '80000100', 'turo_trip_id' => '80000999']);
        Fixture::snapshot($this->connection, [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $extra = $this->projection()['purchased'][0];
        $this->assertTrue($extra['is_completed']);
        $this->assertFalse($extra['synchronization_required']);
        $this->assertSame(['reopen'], $extra['permitted_actions']);
    }

    public function testLateReactivationReconciliationPreservesConfirmationOfCurrentSnapshot(): void
    {
        $work = Fixture::fulfillments($this->connection);
        $work->reconcileForTrip(1, 100);
        $extra = $this->projection()['purchased'][0];
        $work->complete(1, 100, (int) $extra['fulfillment_id'], 7);
        Fixture::snapshot($this->connection, [], '2030-01-02 18:30:00', true, true);
        Fixture::snapshot($this->connection, [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $work->complete(1, 100, (int) $extra['fulfillment_id'], 7);
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->countAllResults();
        $work->reconcileSelectionIds(1, [(int) $extra['selection_id']], [(int) $extra['selection_id']], 7);
        $this->assertTrue($this->projection()['purchased'][0]['is_completed']);
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->countAllResults());
    }

    public static function legacyConfirmationCases(): iterable
    {
        yield 'confirmed before removal' => ['2030-01-02 08:15:00', false];
        yield 'confirmed after reappearance' => ['2030-01-02 09:30:00', true];
    }

    #[DataProvider('legacyConfirmationCases')]
    public function testLegacyConfirmationUsesRecordedTimeWhenSnapshotContextIsUnavailable(string $recordedAt, bool $completed): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        Fixture::snapshot($this->connection, [], '2030-01-02 18:30:00', true, true);
        Fixture::snapshot($this->connection, [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $this->connection->table('trip_extra_fulfillments')->where('id', $id)->update(['completed_at' => $recordedAt]);
        foreach ($this->connection->table('trip_extra_fulfillment_audits')->get()->getResultArray() as $audit) {
            $values = json_decode($audit['after_values'], true, 512, JSON_THROW_ON_ERROR);
            unset($values['operational_context']);
            $this->connection->table('trip_extra_fulfillment_audits')->where('id', $audit['id'])->update(['after_values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        }
        $this->assertSame($completed, $this->projection()['purchased'][0]['is_completed']);
    }

    public function testMovementProjectionPreservesResolvedManualEnergyPolicyContext(): void
    {
        $id = Fixture::manual($this->connection, ['category' => 'energy_override', 'active_override_slot' => 'energy', 'handling_mode' => 'automatic_override', 'energy_comparison' => 'minimum', 'energy_percent' => 90]);
        $rule = ['commitment_id' => $id, 'normal_vehicle_policy' => (new \App\Services\Fleet\TripEnergyRuleResolver())->forProfile(['ready_energy_min_percent' => 70, 'ready_energy_preferred_max_percent' => 80])];
        $rows = Fixture::service($this->connection)->forPhases($this->projection(), ['preparation'], $rule);
        $this->assertSame('Normal vehicle range: 70–80%', $rows[1]['normal_vehicle_policy_summary']);
        $this->assertSame('manual_commitment:' . $id, $rows[1]['identity']);
        $this->assertSame('At least 90% for this trip', $rows[1]['energy_rule_summary']);
    }

    public function testHighCardinalityTripDoesNotTruncatePurchases(): void
    {
        $items = [];
        for ($index = 0; $index < 1025; $index++) {
            $items[] = Fixture::item(['reservation_state_extra_id' => 'synthetic-selection-' . $index]);
        }
        Fixture::snapshot($this->connection, $items, '2030-01-02 19:00:00', true, true);
        $this->assertCount(1025, $this->projection()['purchased']);
        $this->assertSame(1025, $this->projection()['count']);
    }

    public static function oversizedAuthorityCases(): iterable
    {
        yield 'selections' => [true];
        yield 'snapshots' => [false];
    }

    public function testSnapshotPayloadItemsAlsoHaveAnExplicitWholeProjectionBound(): void
    {
        Fixture::snapshot($this->connection, [Fixture::item(), Fixture::item(['reservation_state_extra_id' => '910002'])], '2030-01-02 19:00:00');
        $repository = new \App\Repositories\GuestCommitmentProjectionRepository($this->connection, 2);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('snapshot items. No partial guest commitments were returned');
        $repository->load(1, [100], Fixture::clock());
    }

    #[DataProvider('oversizedAuthorityCases')]
    public function testOversizedAuthorityFailsExplicitlyWithoutReturningPartialObligations(bool $selections): void
    {
        Fixture::snapshot($this->connection, $selections ? [Fixture::item(), Fixture::item(['reservation_state_extra_id' => '910002'])] : [Fixture::item()], '2030-01-02 19:00:00', true, true);
        $repository = new \App\Repositories\GuestCommitmentProjectionRepository($this->connection, 1);
        $before = $this->connection->table('turo_extra_selections')->get()->getResultArray();
        try {
            $repository->load(1, [100], Fixture::clock());
            $this->fail('Oversized authority must reject the entire projection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No partial guest commitments were returned', $exception->getMessage());
            $this->assertStringContainsString($selections ? 'selections' : 'snapshots', $exception->getMessage());
        }
        $this->assertSame($before, $this->connection->table('turo_extra_selections')->get()->getResultArray());
        $this->assertSame(0, $this->connection->transDepth);
        $this->assertSame(0, $this->connection->table('trip_extra_fulfillments')->countAllResults());
    }

    public function testOwnedUnvoidedHandoffClosesOnlyPickupAndPreservesDeficit(): void
    {
        $voided = Fixture::handoff($this->connection, ['voided_at' => '2030-01-02 09:30:00']);
        $this->assertFalse($this->projection()['has_actual_handoff']);
        Fixture::handoff($this->connection, ['turo_trip_normalized_id' => 102]);
        $this->assertFalse($this->projection()['has_actual_handoff']);
        $this->connection->table('trip_movement_events')->where('id', $voided)->update(['voided_at' => null]);
        $extra = $this->projection()['purchased'][0];
        $this->assertTrue($extra['is_suppressed_after_handoff']);
        $this->assertFalse($extra['is_completed']);
        $this->connection->table('fleet_extras')->where('id', 301)->update(['fulfillment_phase' => 'return', 'fulfillment_type' => 'logistics']);
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $this->assertTrue($this->projection()['purchased'][0]['is_actionable']);
    }

    public function testPickupReopeningCannotBypassHandoffAndReconciliationPreservesCompletion(): void
    {
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $id = (int) $this->projection()['purchased'][0]['fulfillment_id'];
        Fixture::fulfillments($this->connection)->complete(1, 100, $id, 7);
        Fixture::handoff($this->connection);
        Fixture::snapshot($this->connection, [Fixture::item(['quantity' => '3.000'])], '2030-01-02 19:00:00', true, true);
        Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
        $row = $this->projection()['purchased'][0];
        $this->assertSame('completed', $row['fulfillment_state']);
        $this->assertFalse($row['is_completed']);
        $this->assertTrue($row['is_suppressed_after_handoff']);
        $this->expectException(InvalidArgumentException::class);
        Fixture::fulfillments($this->connection)->reopen(1, 100, $id, 7);
    }

    public static function batchSizes(): iterable
    {
        yield [1];
        yield [50];
        yield [500];
    }

    #[DataProvider('batchSizes')]
    public function testBoundedSelectBudgetAndNoTruncation(int $size): void
    {
        $ids = [100];
        for ($index = 1; $index < $size; $index++) {
            $id = 1000 + $index;
            $ids[] = $id;
            $this->connection->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => 10, 'turo_trip_id' => '8000' . $id, 'turo_reservation_id' => '8000' . $id, 'starts_at' => '2030-01-03 09:00:00', 'ends_at' => '2030-01-05 09:00:00']);
            Fixture::snapshot($this->connection, [Fixture::item()], Fixture::OBSERVED, true, true, $id);
            Fixture::manual($this->connection, ['turo_trip_normalized_id' => $id]);
        }
        // Warm only fixed schema metadata; data SELECTs are always counted.
        Fixture::service($this->connection)->forTrips(1, $ids, Fixture::clock());
        $selects = $writes = [];
        $listener = static function (Query $query) use (&$selects, &$writes): void {
            $sql = $query->getQuery();
            if (preg_match('/^SELECT\b/i', $sql)) {
                $selects[] = $sql;
            } elseif (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
                $writes[] = $sql;
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $result = Fixture::service($this->connection)->forTrips(1, $ids, Fixture::clock());
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertCount($size, $result);
        $this->assertCount(8, $selects, 'Fixed projection data SELECT budget for ' . $size . ' trips.');
        $this->assertSame([], $writes);
        $this->assertCount(1, $result[$ids[$size - 1]]['purchased']);
        $this->assertSame($result[100], Fixture::service($this->connection)->forTrip(1, 100, Fixture::clock()));
    }

    public function testOwnershipIsValidatedBeforeChildReads(): void
    {
        $selects = [];
        $listener = static function (Query $query) use (&$selects): void {
            if (preg_match('/^SELECT\b/i', $query->getQuery())) {
                $selects[] = $query->getQuery();
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $this->assertSame([], Fixture::service($this->connection)->forTrips(2, [100], Fixture::clock()));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertCount(1, $selects, 'Only owned parent lookup may execute for an unowned trip.');
        $this->expectException(RuntimeException::class);
        Fixture::service($this->connection)->forTrip(2, 100, Fixture::clock());
    }

    public function testOwnedSourceTripIdAliasKeepsPurchasedSelectionVisible(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['turo_reservation_id' => '80000999']);
        $projection = $this->projection();
        $this->assertCount(1, $projection['purchased']);
        $this->assertFalse($projection['can_show_empty']);
    }

    public function testChecklistUsesPhaseRelevantProjectionWithoutDuplicatingPurchasedExtra(): void
    {
        $projection = $this->projection();
        $manualId = Fixture::manual($this->connection, ['fleet_extra_id' => 301]);
        $projection = $this->projection();
        $rows = Fixture::service($this->connection)->forPhases($projection, ['preparation', 'pickup', 'entire_trip']);
        $this->assertCount(2, $rows);
        $html = \Config\Services::renderer()->setData([
            'checklist' => ['turo_trip_normalized_id' => 100, 'company_id' => 1, 'movement_type' => 'pickup'],
            'guestCommitments' => $projection['manual'], 'extraPreparation' => $projection['purchased'],
            'extraVerification' => $projection['verification'], 'readiness' => ['requirements' => []], 'renderFulfillment' => true,
        ])->render('trip_movement_checklists/_guest_commitments');
        $this->assertSame(1, substr_count($html, '<h3>Synthetic Beach Gear'));
        $this->assertStringContainsString('Synthetic manual child seat request', $html);
        $this->assertStringContainsString('/commitments/' . $manualId . '/complete', $html);
        $this->assertStringContainsString('Possible overlap with a purchased Extra', $html);
        $this->assertCount(0, Fixture::service($this->connection)->forPhases($projection, ['return']));
    }

    public static function informationalContextCases(): iterable
    {
        yield 'missing fulfillment' => [false];
        yield 'pickup closed at handoff' => [true];
    }

    #[DataProvider('informationalContextCases')]
    public function testChecklistKeepsRequiredInformationalContextVisibleWhenConfirmationIsUnavailable(bool $handoff): void
    {
        $this->connection->table('fleet_extras')->where('id', 301)->update(['fulfillment_type' => 'informational']);
        if ($handoff) {
            Fixture::fulfillments($this->connection)->reconcileForTrip(1, 100);
            Fixture::handoff($this->connection);
        }
        $projection = $this->projection();
        $this->assertFalse($projection['purchased'][0]['is_actionable']);
        $html = \Config\Services::renderer()->setData([
            'checklist' => ['turo_trip_normalized_id' => 100, 'company_id' => 1, 'movement_type' => 'pickup'],
            'guestCommitments' => [], 'extraPreparation' => $projection['purchased'],
            'extraVerification' => $projection['verification'], 'readiness' => ['requirements' => []], 'renderFulfillment' => true,
        ])->render('trip_movement_checklists/_guest_commitments');
        $this->assertSame(1, substr_count($html, '<h3>Synthetic Beach Gear'));
        $this->assertStringContainsString($handoff ? 'Action currently unavailable' : 'Fulfillment synchronization required', $html);
        $this->assertStringNotContainsString('No operator confirmation required', $html);
    }

    public static function pageCases(): iterable
    {
        yield 'empty' => ['empty', 0, true];
        yield 'purchased only' => ['purchased', 1, false];
        yield 'manual only' => ['manual', 1, false];
        yield 'mixed' => ['mixed', 2, false];
        yield 'unverified' => ['unverified', 0, false];
    }

    #[DataProvider('pageCases')]
    public function testDedicatedPageUsesProjectionCountAndTruthfulEmptyState(string $case, int $count, bool $empty): void
    {
        if (in_array($case, ['empty', 'manual', 'unverified'], true)) {
            Fixture::snapshot($this->connection, [], '2030-01-02 19:00:00', true, true);
        }
        if (in_array($case, ['manual', 'mixed'], true)) {
            Fixture::manual($this->connection);
        }
        if ($case === 'unverified') {
            $this->connection->table('turo_extra_reservation_snapshots')->update(['snapshot_complete' => 0]);
        }
        \Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
            public function setAuthenticator(?string $alias = null): self
            {
                return $this;
            }
            public function loggedIn(): bool
            {
                return false;
            }
            public function user(): \CodeIgniter\Shield\Entities\User
            {
                return new class (['id' => 7, 'username' => 'synthetic-operator']) extends \CodeIgniter\Shield\Entities\User {
                    public function getEmail(): string
                    {
                        return 'synthetic-operator@example.test';
                    }
                };
            }
        });
        try {
            $workspace = (new TripCommitmentService(new TripCommitmentRepository($this->connection)))->workspace(1, 100);
            $html = \CodeIgniter\Config\Services::renderer()->setData([
                'workspace' => $workspace, 'projection' => $this->projection(), 'assets' => ['css' => null, 'js' => null], 'navigation' => [],
                'backLink' => ['label' => 'Synthetic workflow', 'href' => '/synthetic'], 'editing' => null, 'formData' => [], 'success' => null, 'error' => null,
            ])->render('trip_commitments/index');
            $this->assertStringContainsString($count . ' commitment rows', $html);
            $this->assertSame($empty, str_contains($html, 'No active guest commitments'));
            $start = strpos($html, 'id="guest-commitments"');
            $end = strpos($html, '</section>', $start);
            $currentList = substr($html, $start, $end - $start);
            $this->assertSame($count, substr_count($currentList, '<article class="guest-commitment-card'));
            if (in_array($case, ['purchased', 'mixed'], true)) {
                $this->assertStringContainsString('Purchased Extra', $currentList);
            }
            if (in_array($case, ['manual', 'mixed'], true)) {
                $this->assertStringContainsString('Manual ·', $currentList);
            }
        } finally {
            \Config\Services::reset();
        }
    }
}
