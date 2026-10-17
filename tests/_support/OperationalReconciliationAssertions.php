<?php

namespace Tests\Support;

use App\Services\Fleet\FleetOperationalReconciliationService;
use App\Services\Fleet\ReconciliationIssue;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperationalReconciliationFixture as FixtureAlias;

trait OperationalReconciliationAssertions
{
    protected BaseConnection $connection;

    public static function cases(): iterable
    {
        foreach (['healthy', 'old_custody', 'no_handoff', 'missing_recovery', 'stale_import', 'obsolete_checklist', 'persisted_ready', 'preparation', 'guest_deferral', 'extra_blocker', 'historical_extra', 'position_stale', 'recovery_without_handoff', 'overlapping', 'ambiguous', 'import_conflict', 'workflow_disagreement', 'unknown_position', 'manual_blocker', 'unmapped_extra', 'refresh', 'historical_readiness', 'far_future', 'guest_report'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testSyntheticAuthorityComparisons(string $case): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        if ($case === 'healthy' || $case === 'persisted_ready') {
            FixtureAlias::event($db, 'vehicle_staged', '2030-01-02 07:30:00');
            FixtureAlias::event($db, 'actual_handoff');
            $row = FixtureAlias::read($db);
            $this->assertSame('guest', $row['custody']['custody']);
            $this->assertSame(FixtureAlias::TRIP, (int) $row['movement_current_trip']['id']);
            $this->assertSame([], array_values(array_filter($row['issues'], static fn (array $i): bool => str_starts_with($i['code'], 'movement.'))));
            $this->assertNotContains('readiness.current_blocker', array_column($row['issues'], 'code'));
            $this->assertSame('not_started', $row['checklists'][0]['persisted_workflow_state']);
            return;
        }
        if ($case === 'old_custody' || $case === 'overlapping') {
            FixtureAlias::trip($db, 10001, FixtureAlias::VEHICLE, 'completed', '2029-12-28 08:00:00', '2029-12-30 08:00:00');
            FixtureAlias::event($db, 'actual_handoff', '2029-12-28 08:00:00', 10001);
            if ($case === 'overlapping') {
                FixtureAlias::event($db, 'actual_handoff');
            }
            $row = FixtureAlias::read($db);
            $issue = $this->issue($row, $case === 'old_custody' ? 'movement.old_guest_custody' : 'movement.overlapping_guest_intervals');
            $this->assertSame('P0', $issue['severity']);
            $this->assertSame('CURRENT', $issue['scope']);
            $this->assertCount(1, array_filter($row['issues'], static fn (array $i): bool => $i['severity'] === 'P0'));
            return;
        }
        if ($case === 'no_handoff') {
            FixtureAlias::event($db, 'vehicle_staged');
            $row = FixtureAlias::read($db);
            $this->assertSame('operator', $row['custody']['custody']);
            $this->assertSame('P0', $this->issue($row, 'movement.import_current_missing_handoff')['severity']);
            return;
        }
        if (in_array($case, ['missing_recovery', 'stale_import', 'recovery_without_handoff'], true)) {
            $db->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2029-12-28 08:00:00', 'ends_at' => '2029-12-30 08:00:00']);
            if ($case !== 'recovery_without_handoff') {
                FixtureAlias::event($db, 'actual_handoff', '2029-12-28 08:00:00');
            }
            if ($case !== 'missing_recovery') {
                FixtureAlias::event($db, 'vehicle_recovered', '2029-12-30 09:00:00');
            } else {
                FixtureAlias::status($db, 'completed');
            }
            $row = FixtureAlias::read($db);
            $code = match ($case) {
                'missing_recovery' => 'movement.overdue_return_missing_recovery', 'stale_import' => 'import.status_stale', default => 'movement.recovery_without_handoff'
            };
            $this->assertSame($case === 'missing_recovery' ? 'P0' : 'P2', $this->issue($row, $code)['severity']);
            if ($case === 'stale_import') {
                $this->assertSame('operator', $row['custody']['custody']);
                $this->assertNotContains('movement.import_current_missing_handoff', array_column($row['issues'], 'code'));
            }
            return;
        }
        if ($case === 'obsolete_checklist') {
            FixtureAlias::checklist($db, 20001, '2030-01-02 07:00:00');
            FixtureAlias::event($db, 'actual_handoff');
            $row = FixtureAlias::read($db);
            $this->assertTrue($row['checklists'][0]['is_current']);
            $this->assertFalse($row['checklists'][1]['is_current']);
            $this->assertSame('schedule_revised', $row['checklists'][1]['obsolete_reason']);
            $this->assertSame('HISTORICAL', $this->issue($row, 'checklist.obsolete_schedule_generation')['scope']);
            $this->assertNotContains('checklist.movement_disagreement', array_column($row['issues'], 'code'));
            return;
        }
        if (in_array($case, ['preparation', 'guest_deferral', 'far_future'], true)) {
            $start = $case === 'far_future' ? '2030-01-10 08:00:00' : '2030-01-03 08:00:00';
            FixtureAlias::trip($db, 10001, FixtureAlias::VEHICLE, 'booked', $start, '2030-01-12 08:00:00');
            FixtureAlias::checklist($db, 20001, $start, 'pickup', 10001);
            FixtureAlias::event($db, 'actual_handoff');
            if ($case !== 'guest_deferral') {
                FixtureAlias::event($db, 'vehicle_recovered', '2030-01-02 09:00:00');
            }
            $row = FixtureAlias::read($db);
            $nextItems = array_values(array_filter($row['readiness']['items'], static fn (array $item): bool => $item['trip_id'] === 10001));
            $this->assertContains('cleaning', array_column($nextItems, 'family'));
            $this->assertContains('energy_fuel', array_column($nextItems, 'family'));
            if ($case === 'guest_deferral') {
                $this->assertSame('AWAIT_RECOVERY', $row['next_trip_urgency']['state']);
                $physical = array_filter($nextItems, static fn (array $i): bool => in_array($i['family'], ['cleaning', 'energy_fuel'], true));
                foreach ($physical as $item) {
                    $this->assertFalse($item['actionable']);
                    $this->assertSame('ADVISORY', $item['classification']);
                }
                $this->assertNotContains('readiness.current_blocker', array_column($row['issues'], 'code'));
            } else {
                $issue = $this->issue($row, 'readiness.current_blocker', 10001);
                $this->assertSame($case === 'far_future' ? 'P3' : 'P1', $issue['severity']);
                $this->assertSame($case === 'far_future' ? 'SCHEDULED' : 'ACTION_REQUIRED', $row['next_trip_urgency']['state']);
            }
            return;
        }
        if (in_array($case, ['extra_blocker', 'historical_extra', 'manual_blocker', 'unmapped_extra', 'refresh'], true)) {
            FixtureAlias::status($db, 'booked');
            $db->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2030-01-03 08:00:00', 'ends_at' => '2030-01-05 08:00:00']);
            $db->table('trip_movement_checklists')->where('id', 20000)->update(['scheduled_at' => '2030-01-03 08:00:00']);
            FixtureAlias::event($db, 'vehicle_staged');
            if ($case !== 'refresh') {
                GuestCommitmentProjectionFixture::snapshot($db, [GuestCommitmentProjectionFixture::item()], GuestCommitmentProjectionFixture::OBSERVED, true, true, FixtureAlias::TRIP);
            }
            if ($case === 'historical_extra') {
                FixtureAlias::status($db, 'completed');
                $db->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2029-12-28 08:00:00', 'ends_at' => '2029-12-30 08:00:00']);
                FixtureAlias::event($db, 'vehicle_recovered', '2029-12-30 08:00:00');
            }
            if ($case === 'manual_blocker') {
                GuestCommitmentProjectionFixture::manual($db, ['turo_trip_normalized_id' => FixtureAlias::TRIP]);
            }
            if ($case === 'unmapped_extra') {
                $db->table('fleet_extra_source_mappings')->emptyTable();
            }
            $row = FixtureAlias::read($db);
            $code = match ($case) {
                'manual_blocker' => 'extras.manual_commitment_incomplete', 'unmapped_extra' => 'extras.unmapped_purchase', 'refresh' => 'extras.refresh_required', default => 'extras.missing_fulfillment'
            };
            $issue = $this->issue($row, $code);
            $this->assertSame($case === 'historical_extra' ? 'HISTORICAL' : 'FUTURE', $issue['scope']);
            $this->assertSame($case === 'historical_extra' ? 'P2' : ($case === 'unmapped_extra' ? 'P3' : 'P1'), $issue['severity']);
            if ($case === 'extra_blocker') {
                $db->table('trip_movement_checklists')->where('id', 20000)->delete();
                $withoutChecklist = FixtureAlias::read($db);
                $this->assertSame('P1', $this->issue($withoutChecklist, $code)['severity']);
                $this->assertSame('COMMITMENTS_ONLY', $withoutChecklist['readiness']['projections'][0]['coverage']);
                $this->assertNull($withoutChecklist['readiness']['projections'][0]['ready']);
                $this->assertSame([], $withoutChecklist['checklists']);
            }
            return;
        }
        if ($case === 'historical_readiness') {
            FixtureAlias::status($db, 'completed');
            $db->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2029-12-28 08:00:00', 'ends_at' => '2029-12-30 08:00:00']);
            $db->table('trip_movement_checklists')->where('id', 20000)->update(['scheduled_at' => '2029-12-28 08:00:00']);
            FixtureAlias::event($db, 'vehicle_staged', '2029-12-28 07:00:00');
            FixtureAlias::event($db, 'vehicle_recovered', '2029-12-30 08:00:00');
            $row = FixtureAlias::read($db);
            $this->assertSame('P2', $this->issue($row, 'readiness.historical_derived_only')['severity']);
            $this->assertSame([], $row['actionable_work']);
            return;
        }
        if ($case === 'ambiguous') {
            FixtureAlias::event($db, 'actual_handoff');
            FixtureAlias::event($db, 'vehicle_recovered');
            $this->assertSame('P0', $this->issue(FixtureAlias::read($db), 'movement.ambiguous_authority')['severity']);
            return;
        }
        if ($case === 'import_conflict') {
            FixtureAlias::event($db, 'actual_handoff');
            FixtureAlias::status($db, 'booked');
            $this->assertSame('P2', $this->issue(FixtureAlias::read($db), 'import.status_movement_conflict')['severity']);
            return;
        }
        if ($case === 'workflow_disagreement') {
            FixtureAlias::event($db, 'vehicle_staged');
            $db->table('trip_movement_checklists')->where('id', 20000)->update(['completed_at' => '2030-01-02 08:00:00']);
            $db->table('trip_movement_checklist_items')->insert(['trip_movement_checklist_id' => 20000, 'item_code' => 'guest_handoff_completed', 'label' => 'Synthetic legacy handoff confirmation', 'completion_state' => 'complete']);
            $this->assertSame('P2', $this->issue(FixtureAlias::read($db), 'checklist.movement_disagreement')['severity']);
            return;
        }
        if ($case === 'guest_report') {
            FixtureAlias::event($db, 'actual_handoff');
            FixtureAlias::event($db, 'guest_return_staged', '2030-01-02 09:00:00');
            $row = FixtureAlias::read($db);
            $this->assertSame('unverified_guest_report', $row['position']['position_semantics']);
            $this->assertSame('P0', $this->issue($row, 'position.unknown')['severity']);
            return;
        }
        if ($case === 'unknown_position') {
            FixtureAlias::event($db, 'actual_handoff', location: null);
            $this->assertSame('P0', $this->issue(FixtureAlias::read($db), 'position.unknown')['severity']);
            return;
        }
        FixtureAlias::event($db, 'actual_handoff');
        FixtureAlias::event($db, 'vehicle_recovered', '2030-01-02 09:00:00');
        FixtureAlias::event($db, 'vehicle_positioned', '2030-01-02 09:30:00', location: null);
        $row = FixtureAlias::read($db);
        $this->assertSame('P0', $this->issue($row, 'position.stale_basis')['severity']);
    }

    public function testReadPurityDeterminismIsolationAndImmutableSnapshot(): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        FixtureAlias::event($db, 'actual_handoff');
        $before = FixtureAlias::fingerprint($db);
        $service = new FleetOperationalReconciliationService($db);
        $queries = [];
        $listener = static function (Query $query) use (&$queries): void {
            $queries[] = $query->getQuery();
        };
        Events::on('DBQuery', $listener);
        try {
            $first = $service->forVehicles(1, [FixtureAlias::VEHICLE, 20], FixtureAlias::clock());
            $second = $service->forVehicles(1, [FixtureAlias::VEHICLE], FixtureAlias::clock());
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame($before, FixtureAlias::fingerprint($db));
        $this->assertSame($first->vehicles, $second->vehicles);
        $this->assertSame([FixtureAlias::VEHICLE], array_keys($first->vehicles));
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql))));
        $json = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SYNTHETIC-RESERVATION-200', $json);
        $this->assertStringNotContainsString('complete_extra', $json);
        $this->assertTrue((new \ReflectionClass($first))->isReadOnly());
        $copy = $first->vehicles;
        $copy[FixtureAlias::VEHICLE] = [];
        $this->assertNotSame($first->vehicles, $copy);
    }

    public function testCompanyAndParentOwnershipRejectForgedChildren(): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        FixtureAlias::event($db, 'vehicle_staged');
        FixtureAlias::event($db, 'actual_handoff', trip: 200, changes: ['company_id' => 2]);
        $foreignBasis = FixtureAlias::event($db, 'vehicle_recovered', '2030-01-02 09:00:00', trip: 200);
        $db->table('movement_assessments')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => FixtureAlias::VEHICLE, 'turo_trip_normalized_id' => FixtureAlias::TRIP,
            'trip_movement_event_id' => $foreignBasis, 'movement_type' => 'pickup', 'cleanliness' => 'clean', 'energy_percent' => 80,
            'captured_at' => '2030-01-02 09:00:00', 'source' => 'operator', 'actor_user_id' => 7,
        ]);
        FixtureAlias::event($db, 'actual_handoff', vehicle: 20, changes: ['company_id' => 2]);
        FixtureAlias::checklist($db, 20002, trip: 200);
        GuestCommitmentProjectionFixture::manual($db, ['company_id' => 2, 'turo_trip_normalized_id' => FixtureAlias::TRIP]);
        $row = FixtureAlias::read($db);
        $this->assertSame('operator', $row['custody']['custody']);
        $this->assertCount(1, $row['checklists']);
        foreach ($row['readiness']['items'] as $item) {
            $this->assertNull($item['source_basis']['authority_source_ids']['last_energy_event_id']);
            $this->assertNull($item['source_basis']['assessment_id']);
        }
        $this->assertNotContains('extras.manual_commitment_incomplete', array_column($row['issues'], 'code'));
        $service = new FleetOperationalReconciliationService($db);
        $this->assertSame([], $service->forVehicles(1, [20], FixtureAlias::clock())->vehicles);
        $this->assertSame([], $service->forVehicles(1, [], FixtureAlias::clock())->vehicles);
        $this->expectException(\InvalidArgumentException::class);
        $service->forCompany(0);
    }

    public function testConstantSelectBudgetForOneTenAndOneHundredVehicles(): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        for ($index = 1; $index < 100; $index++) {
            FixtureAlias::vehicle($db, 1000 + $index);
            FixtureAlias::trip($db, 10000 + $index, 1000 + $index);
            FixtureAlias::checklist($db, 20000 + $index, trip: 10000 + $index, vehicle: 1000 + $index);
        }
        GuestCommitmentProjectionFixture::snapshot($db, [GuestCommitmentProjectionFixture::item()], GuestCommitmentProjectionFixture::OBSERVED, true, true, FixtureAlias::TRIP);
        GuestCommitmentProjectionFixture::manual($db, ['turo_trip_normalized_id' => FixtureAlias::TRIP]);
        $counts = [];
        foreach ([1, 10, 100] as $size) {
            $count = 0;
            $listener = static function (Query $query) use (&$count): void {
                if (preg_match('/^SELECT\b/i', $query->getQuery())) {
                    $count++;
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $projection = (new FleetOperationalReconciliationService($db))->forVehicles(1, range(1000, 999 + $size), FixtureAlias::clock());
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            $this->assertCount($size, $projection->vehicles);
            $this->assertLessThanOrEqual(32, $count, 'Reconciliation SELECT budget at ' . $size . ' vehicles.');
            $counts[] = $count;
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertSame($counts[1], $counts[2]);
    }

    public function testFingerprintsUseAuthorityInputsAndRemainStableAcrossDisplayChanges(): void
    {
        FixtureAlias::event($this->connection, 'vehicle_staged');
        $first = FixtureAlias::read($this->connection);
        $this->connection->table('fleet_vehicles')->where('id', FixtureAlias::VEHICLE)->update(['display_name' => 'Synthetic renamed vehicle']);
        $second = FixtureAlias::read($this->connection);
        $this->assertSame($first['issue_fingerprint'], $second['issue_fingerprint']);
        FixtureAlias::event($this->connection, 'actual_handoff');
        $third = FixtureAlias::read($this->connection);
        $this->assertNotSame($first['issue_fingerprint'], $third['issue_fingerprint']);
        $this->assertSame(ReconciliationIssue::fingerprint(['a' => 1, 'b' => 2]), ReconciliationIssue::fingerprint(['b' => 2, 'a' => 1]));
    }

    public function testCompanyRosterExcludesOtherCompaniesAndInactiveDates(): void
    {
        FixtureAlias::vehicle($this->connection, 1100);
        FixtureAlias::vehicle($this->connection, 1101);
        $this->connection->table('fleet_vehicles')->where('id', 1100)->update(['out_of_service_date' => '2030-01-01']);
        $this->connection->table('fleet_vehicles')->where('id', 1101)->update(['in_service_date' => '2030-01-03']);
        $rows = (new FleetOperationalReconciliationService($this->connection))->forCompany(1, FixtureAlias::clock())->vehicles;
        $this->assertArrayHasKey(FixtureAlias::VEHICLE, $rows);
        foreach ([20, 1100, 1101] as $id) {
            $this->assertArrayNotHasKey($id, $rows);
        }
        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row['vehicle']['company_id']);
        }
    }

    public function testVoidedFutureAndCanceledMovementCannotCreateCustody(): void
    {
        FixtureAlias::event($this->connection, 'vehicle_staged');
        FixtureAlias::event($this->connection, 'actual_handoff', '2030-01-02 09:00:00', changes: ['voided_at' => '2030-01-02 09:30:00']);
        FixtureAlias::event($this->connection, 'actual_handoff', '2030-01-04 09:00:00');
        FixtureAlias::trip($this->connection, 10001, FixtureAlias::VEHICLE, 'canceled_zero_payout');
        FixtureAlias::event($this->connection, 'actual_handoff', '2030-01-02 09:00:00', 10001);
        $row = FixtureAlias::read($this->connection);
        $this->assertSame('operator', $row['custody']['custody']);
        $this->assertSame('P0', $this->issue($row, 'movement.import_current_missing_handoff')['severity']);
    }

    public function testScheduleChangeChangesFingerprintAndReadinessCoverageWithoutCreatingChecklist(): void
    {
        FixtureAlias::checklist($this->connection);
        FixtureAlias::event($this->connection, 'actual_handoff');
        $first = FixtureAlias::read($this->connection);
        $this->connection->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2030-01-02 07:45:00']);
        $second = FixtureAlias::read($this->connection);
        $this->assertNotSame($first['issue_fingerprint'], $second['issue_fingerprint']);
        $this->assertFalse($second['checklists'][0]['is_current']);
        $this->assertSame([], array_values(array_filter($second['readiness']['projections'], static fn (array $projection): bool => $projection['coverage'] === 'FULL')));
        $this->assertCount(1, $second['checklists']);
        $this->assertSame('P3', $this->issue($second, 'readiness.source_refresh_needed')['severity']);
    }

    public function testTripEnergyOverrideUsesExistingPolicyAuthority(): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        GuestCommitmentProjectionFixture::manual($db, ['turo_trip_normalized_id' => FixtureAlias::TRIP, 'category' => 'energy_override', 'energy_comparison' => 'minimum', 'energy_percent' => 85, 'active_override_slot' => 'energy']);
        $resolver = new \App\Services\Fleet\TripEnergyRuleResolver(new \App\Repositories\TripCommitmentRepository($db));
        $expected = $resolver->forTrip(1, FixtureAlias::TRIP);
        $manual = $db->table('fleet_trip_commitments')->where('turo_trip_normalized_id', FixtureAlias::TRIP)->get()->getRowArray();
        $this->assertSame($expected, $resolver->forOverride($resolver->forProfile(null), $manual));
        $this->assertSame(85, $expected['minimum_percent']);
    }

    public function testVehicleHealthRemainsPartOfCanonicalReadiness(): void
    {
        $db = $this->connection;
        FixtureAlias::checklist($db);
        FixtureAlias::event($db, 'vehicle_staged');
        $db->table('vehicle_health_policies')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => FixtureAlias::VEHICLE, 'reminder_code' => 'tire_pressure_check', 'rule_type' => 'calendar_days',
            'interval_value' => 30, 'recommended_psi' => 42, 'acceptable_min_psi' => 40, 'acceptable_max_psi' => 44, 'safety_min_psi' => 35,
            'is_enabled' => 1, 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2030-01-02 08:00:00', 'updated_at' => '2030-01-02 08:00:00',
        ]);
        $db->table('vehicle_health_observations')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => FixtureAlias::VEHICLE, 'observation_code' => 'tire_pressure', 'source' => 'operator',
            'observed_at' => '2030-01-02 09:00:00', 'received_at' => '2030-01-02 09:00:00', 'created_at' => '2030-01-02 09:00:00', 'actor_user_id' => 7,
        ]);
        $id = (int) $db->insertID();
        $db->table('vehicle_tire_pressure_observations')->insert(['vehicle_health_observation_id' => $id, 'lf_psi' => 30, 'rf_psi' => 42, 'lr_psi' => 42, 'rr_psi' => 42, 'recommended_psi' => 42]);
        $row = FixtureAlias::read($db);
        $health = array_values(array_filter($row['readiness']['items'], static fn (array $item): bool => $item['code'] === 'vehicle_health_tire_pressure'));
        $this->assertCount(1, $health);
        $this->assertTrue($health[0]['blocking']);
        $this->assertSame('CURRENT_GENUINE', $health[0]['classification']);
        $this->assertSame('P1', $this->issue($row, 'readiness.current_blocker')['severity']);
    }

    public function testRecoveredHistoricalOverlapIsP2RatherThanCurrentCustodyFailure(): void
    {
        $db = $this->connection;
        FixtureAlias::trip($db, 10001, FixtureAlias::VEHICLE, 'completed', '2029-12-28 08:00:00', '2029-12-30 08:00:00');
        FixtureAlias::trip($db, 10002, FixtureAlias::VEHICLE, 'completed', '2029-12-29 08:00:00', '2029-12-31 08:00:00');
        FixtureAlias::event($db, 'actual_handoff', '2029-12-28 08:00:00', 10001);
        FixtureAlias::event($db, 'actual_handoff', '2029-12-29 08:00:00', 10002);
        FixtureAlias::event($db, 'vehicle_recovered', '2029-12-30 08:00:00', 10001);
        FixtureAlias::event($db, 'vehicle_recovered', '2029-12-31 08:00:00', 10002);
        FixtureAlias::event($db, 'actual_handoff');
        $row = FixtureAlias::read($db);
        $issue = $this->issue($row, 'movement.overlapping_guest_intervals');
        $this->assertSame('P2', $issue['severity']);
        $this->assertSame('HISTORICAL', $issue['scope']);
        $this->assertFalse($row['custody']['integrity_conflict']);
    }

    public function testFutureReturnWorkflowDoesNotBecomeImmediatePreparationP1(): void
    {
        FixtureAlias::checklist($this->connection);
        FixtureAlias::checklist($this->connection, 20001, '2030-01-03 08:00:00', 'return');
        FixtureAlias::event($this->connection, 'actual_handoff');
        $row = FixtureAlias::read($this->connection);
        $this->assertNotContains('readiness.current_blocker', array_column($row['issues'], 'code'));
        $this->assertSame('guest', $row['custody']['custody']);
    }

    public function testReadyNotStartedPickupHasNoPreparationP1OrFalseUrgency(): void
    {
        $db = $this->connection;
        FixtureAlias::status($db, 'completed');
        $db->table('turo_trips_normalized')->where('id', FixtureAlias::TRIP)->update(['starts_at' => '2030-01-01 08:00:00', 'ends_at' => '2030-01-02 08:00:00']);
        FixtureAlias::trip($db, 10001, FixtureAlias::VEHICLE, 'booked', '2030-01-03 08:00:00', '2030-01-05 08:00:00');
        FixtureAlias::checklist($db, 20000, '2030-01-03 08:00:00', trip: 10001);
        $event = FixtureAlias::event($db, 'vehicle_staged', '2030-01-02 09:00:00', 10001);
        $db->table('movement_assessments')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => FixtureAlias::VEHICLE, 'turo_trip_normalized_id' => 10001,
            'trip_movement_event_id' => $event, 'movement_type' => 'pickup', 'cleanliness' => 'clean', 'energy_percent' => 80,
            'captured_at' => '2030-01-02 09:00:00', 'source' => 'operator', 'actor_user_id' => 7,
        ]);
        $db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => FixtureAlias::VEHICLE, 'energy_kind' => 'electric', 'ready_energy_min_percent' => 70, 'created_by' => 7, 'updated_by' => 7]);
        foreach (['exterior_photos_completed', 'interior_photos_completed', 'key_card_confirmed'] as $code) {
            $db->table('trip_movement_checklist_items')->insert(['trip_movement_checklist_id' => 20000, 'item_code' => $code, 'label' => 'Synthetic complete requirement', 'completion_state' => 'complete']);
        }
        GuestCommitmentProjectionFixture::snapshot($db, [], GuestCommitmentProjectionFixture::OBSERVED, true, true, 10001);
        $row = FixtureAlias::read($db);
        $this->assertTrue($row['readiness']['projections'][0]['ready']);
        $this->assertSame('not_started', $row['checklists'][0]['persisted_workflow_state']);
        $this->assertSame('SCHEDULED', $row['next_trip_urgency']['state']);
        $this->assertNotContains('readiness.current_blocker', array_column($row['issues'], 'code'));
        $this->assertNotContains('movement.import_current_missing_handoff', array_column($row['issues'], 'code'));
        $db->table('trip_movement_checklists')->where('id', 20000)->update(['completed_at' => '2030-01-02 09:30:00']);
        $completed = FixtureAlias::read($db);
        $this->assertNotContains('checklist.movement_disagreement', array_column($completed['issues'], 'code'));
    }

    private function issue(array $row, string $code, ?int $tripId = null): array
    {
        $found = array_values(array_filter($row['issues'], static fn (array $issue): bool => $issue['code'] === $code && ($tripId === null || in_array($tripId, $issue['trip_ids'], true))));
        $this->assertNotEmpty($found, $code . ' missing from ' . json_encode($row['issues']));
        $issue = $found[0];
        $this->assertNotEmpty($issue['summary']);
        $this->assertNotEmpty($issue['authority_basis']);
        $this->assertNotEmpty($issue['review_category']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issue['fingerprint']);
        return $issue;
    }
}
