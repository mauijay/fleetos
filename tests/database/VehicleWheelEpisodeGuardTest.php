<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService as Incidents;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use App\Services\Fleet\VehicleDamageService as Damage;
use App\Services\Fleet\VehicleWheelEpisodeGuard as Guard;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageOccurrenceFixture as Fixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as RepairFixture;

final class VehicleWheelEpisodeGuardTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'maria' => ['maria']];
    }

    public static function data(array $changes = []): array
    {
        return array_replace(['zone_code' => 'wheel_tire', 'panel_code' => 'front_left_wheel_tire', 'damage_type_code' => 'wheel_curb_rash',
            'severity_code' => 'cosmetic', 'description' => 'Synthetic wheel condition', 'discovered_at' => '2026-10-01 10:00:00'], $changes);
    }

    private function success(array $result): int
    {
        $this->assertTrue($result['success'], json_encode($result));
        return (int) $result['id'];
    }

    private function rejected(Fixture $fixture, callable $command, string $message = ''): void
    {
        $before = $fixture->rows();
        $result = $command();
        $this->assertFalse($result['success'], json_encode($result));
        if ($message !== '') {
            $this->assertStringContainsString($message, implode(' ', $result['errors']));
        }
        $this->assertSame($before, $fixture->rows(), 'Rejection must roll back every business row and audit.');
    }

    private function assertSlot(array $state, string $expected): void
    {
        $this->assertSame($expected, $state['state']);
    }

    #[DataProvider('engines')]
    public function testResolverScopeHistoryUnknownFamilyAndReadPurity(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $guard = new Guard($f->db);
            $panel = self::data()['panel_code'];
            $this->assertSlot($guard->resolve(1, 10, $panel), 'none');
            foreach ([['panel_code' => null], ['damage_type_code' => 'crack_broken'], ['panel_code' => 'front_right_wheel_tire']] as $other) {
                $this->success($damage->create(1, 10, self::data($other), 7));
            }
            $this->success($damage->create(1, 11, self::data(), 7));
            $this->success($damage->create(2, 20, self::data(), 7));
            $this->assertSlot($guard->resolve(1, 10, $panel), 'none');
            $id = $this->success($damage->create(1, 10, self::data(), 7));
            $state = $guard->resolve(1, 10, $panel);
            $this->assertSame('one', $state['state']);
            $this->assertSame($id, $state['candidates'][0]['id']);
            $this->assertGreaterThan(0, $state['candidates'][0]['latest_condition_event_id']);
            $before = $f->rows();
            $this->assertSame($state, $guard->resolve(1, 10, $panel));
            $this->assertSame($before, $f->rows());
            $this->success($damage->transitionStatus(1, 10, $id, 'accepted_unrepaired', 'Synthetic accepted condition', 7));
            $this->assertSame('accepted_unrepaired', $guard->resolve(1, 10, $panel)['candidates'][0]['status_code']);
            $this->success($damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic repair', 7));
            $empty = $guard->resolve(1, 10, $panel);
            $this->assertSlot($empty, 'none');
            $this->assertNotSame($state['fingerprint'], $empty['fingerprint']);
            $later = $this->success($damage->create(1, 10, self::data(['expected_wheel_state' => $empty['fingerprint']]), 7));
            $this->success($damage->transitionStatus(1, 10, $later, 'resolved_other', 'Synthetic resolution', 7));
            $this->assertSlot($guard->resolve(1, 10, $panel), 'none');
            $this->rejected($f, fn () => $damage->create(1, 11, self::data(['expected_wheel_state' => $empty['fingerprint']]), 7));
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testGenericAndMultiAreaBoundariesEventStalenessAndExactIdentity(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $guard = new Guard($f->db);
            $id = $this->success($damage->create(1, 10, self::data(), 7));
            $state = $guard->resolve(1, 10, self::data()['panel_code']);
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(), 7), 'active');
            $this->rejected($f, fn () => (new Incidents($f->db))->create(1, 10, Fixture::incident(['areas' => [Fixture::area(['panel_code' => 'front_right_wheel_tire']), Fixture::area()]]), 7));
            $this->rejected($f, fn () => (new Incidents($f->db))->create(1, 10, Fixture::incident(['areas' => [Fixture::area(['panel_code' => 'front_right_wheel_tire']), Fixture::area(['panel_code' => 'front_right_wheel_tire'])]]), 7), 'state changed');
            $item = (new VehicleDamageRepository($f->db))->item(1, 10, $id);
            $token = $guard->conditionState($item);
            $this->success($damage->observe(1, 10, $id, 'Synthetic observation', 7));
            // Freeze all timestamp/item fields: an additional event alone must invalidate intent.
            $f->db->table('vehicle_damage_items')->where('id', $id)->update(['updated_at' => $item['updated_at']]);
            $this->rejected($f, fn () => $damage->worsen(1, 10, $id, ['note' => 'Synthetic worsening', 'expected_condition_state' => $token], 7), 'event state changed');
            $this->rejected($f, fn () => $damage->worsen(1, 10, $id, ['note' => 'Synthetic worsening', 'panel_code' => 'rear_left_wheel_tire'], 7), 'Identity mismatch');
            $this->rejected($f, fn () => $damage->observe(1, 10, $id, 'Synthetic observation', 7, [], ['expected_wheel_state' => $state['fingerprint']]), 'state changed');
            $this->success($damage->worsen(1, 10, $id, ['note' => 'Synthetic worsening', 'severity_code' => 'moderate'], 7));
            $this->rejected($f, fn () => $damage->worsen(1, 10, $id, ['note' => 'Synthetic invalid reduction', 'severity_code' => 'cosmetic'], 7), 'lower');
            $this->rejected($f, fn () => (new Incidents($f->db))->create(1, 10, Fixture::incident(['areas' => [Fixture::area(['effect_code' => 'observed_existing', 'vehicle_damage_item_id' => $id, 'damage_type_code' => 'scratch_scuff', 'severity_code' => 'moderate'])]]), 7), 'Identity mismatch');
            $this->success($damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic repair', 7));
            $this->rejected($f, fn () => $damage->observe(1, 10, $id, 'Synthetic historical observation', 7), 'active wheel episode');
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(['expected_wheel_state' => $state['fingerprint']]), 7), 'state changed');
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testClassificationCorrectionAtomicSlotsAndWorkHistory(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $guard = new Guard($f->db);
            $items = new VehicleDamageRepository($f->db);
            $id = $this->success($damage->create(1, 10, self::data(['panel_code' => null]), 7));
            $occupied = $this->success($damage->create(1, 10, self::data(), 7));
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $id, self::data(['note' => 'Synthetic classification without preview']), 7), 'expected condition state');
            $historical = $this->success($damage->create(1, 10, self::data(['panel_code' => null]), 7));
            $this->success($damage->transitionStatus(1, 10, $historical, 'repaired', 'Synthetic historical unknown repair', 7));
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $historical, self::data(['note' => 'Synthetic historical classification',
                'expected_condition_state' => $guard->conditionState($items->item(1, 10, $historical))]), 7), 'Target wheel');
            $correction = self::data(['note' => 'Synthetic explicit classification', 'expected_condition_state' => $guard->conditionState($items->item(1, 10, $id))]);
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $id, $correction, 7), 'Target wheel');
            $this->success($damage->correctDetails(1, 10, $occupied, self::data(['panel_code' => 'front_right_wheel_tire', 'note' => 'Synthetic corrected wheel']), 7));
            $this->success($damage->correctDetails(1, 10, $id, $correction, 7));
            $this->assertSame($id, $guard->resolve(1, 10, self::data()['panel_code'])['candidates'][0]['id']);
            $unknown = $this->success($damage->create(1, 10, self::data(['panel_code' => null]), 7));
            $creation = RepairFixture::creation($f->db, [$id, $unknown]);
            $this->success((new Work($f->db))->createJob(1, 10, $creation, 7));
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $id, self::data(['panel_code' => 'rear_left_wheel_tire', 'note' => 'Synthetic attempted work retarget']), 7), 'work history');
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $id, self::data(['damage_type_code' => 'scratch_scuff', 'note' => 'Synthetic attempted type retarget']), 7), 'work history');
            $this->rejected($f, fn () => $damage->correctDetails(1, 10, $unknown, self::data(['panel_code' => null, 'damage_type_code' => 'scratch_scuff', 'note' => 'Synthetic unknown work retarget']), 7), 'work history');
            $this->success($damage->correctDetails(1, 10, $id, self::data(['description' => 'Synthetic clarified description', 'note' => 'Synthetic text correction']), 7));
            $event = (new VehicleDamageRepairRepository($f->db))->latestConditionEvent(1, $id);
            $this->assertSame('detail_corrected', $event['event_code']);
        } finally {
            $f->close();
        }
    }

    public static function aliasCases(): array
    {
        $cases = [];
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['valid', 'broken', 'chain', 'mismatch', 'foreign', 'repaired', 'duplicate'] as $case) {
                $cases[$engine . '-' . $case] = [$engine, $case];
            }
        }
        return $cases;
    }

    #[DataProvider('aliasCases')]
    public function testLegacyAliasAndDuplicateRelationships(string $engine, string $case): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $id = $this->success($damage->create(1, 10, self::data(), 7));
            $items = new VehicleDamageRepository($f->db);
            $raw = $items->item(1, 10, $id);
            $values = array_intersect_key($raw, array_flip(['company_id', 'fleet_vehicle_id', 'zone_code', 'panel_code', 'damage_type_code', 'description', 'severity_code', 'status_code', 'discovered_at', 'created_by', 'created_at', 'updated_by', 'updated_at']));
            if ($case === 'repaired') {
                $this->success($damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic repaired alias target', 7));
            }
            $values['current_condition_item_id'] = $case === 'duplicate' ? null : $id;
            if ($case === 'mismatch') {
                $values['panel_code'] = 'front_right_wheel_tire';
            }
            if ($case === 'foreign') {
                $values['company_id'] = 2;
                $values['fleet_vehicle_id'] = 20;
            }
            $alias = $items->insertItem($values);
            if ($case === 'broken') {
                $f->db->query($engine === 'sqlite' ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS=0');
                $f->db->table('vehicle_damage_items')->where('id', $alias)->update(['current_condition_item_id' => 999999]);
                $f->db->query($engine === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS=1');
            } elseif ($case === 'chain') {
                $f->db->table('vehicle_damage_items')->where('id', $id)->update(['current_condition_item_id' => $alias]);
            }
            $guard = new Guard($f->db);
            $before = $f->rows();
            $state = $guard->resolve(1, 10, self::data()['panel_code']);
            $this->assertSame($before, $f->rows());
            $this->assertSame($case === 'duplicate' ? 'conflict' : ($case === 'repaired' || $case === 'chain' ? 'none' : 'one'), $state['state']);
            $this->assertSame(in_array($case, ['valid', 'repaired', 'duplicate'], true) ? 'valid' : 'invalid', $state['alias_integrity']);
            if ($case === 'valid') {
                $this->success($damage->observe(1, 10, $alias, 'Synthetic one-hop observation', 7));
                $this->rejected($f, fn () => $damage->correctDetails(1, 10, $id, self::data(['panel_code' => 'front_right_wheel_tire', 'note' => 'Synthetic forbidden alias retarget']), 7), 'historical aliases');
            } else {
                $this->rejected($f, fn () => $damage->observe(1, 10, $id, 'Synthetic rejected observation', 7));
                if ($case === 'duplicate') {
                    $this->rejected($f, fn () => $damage->attachEvidence(1, 10, $id, ['external_reference' => 'Synthetic duplicate evidence'], 7), 'multiple active');
                    $this->rejected($f, fn () => $damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic conflict repair', 7), 'multiple active');
                }
            }
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testReviewedDuplicateLinkAndRepairBoundaryRejection(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $items = new VehicleDamageRepository($f->db);
            $guard = new Guard($f->db);
            $target = $this->success($damage->create(1, 10, self::data(), 7));
            $source = $this->success($damage->create(1, 10, self::data(['damage_type_code' => 'scratch_scuff']), 7));
            // Deliberate legacy duplicate; the command API must never manufacture one.
            $f->db->table('vehicle_damage_items')->where('id', $source)->update(['damage_type_code' => 'wheel_curb_rash']);
            $a = $items->item(1, 10, $source);
            $b = $items->item(1, 10, $target);
            $data = ['confirmed' => '1', 'reason' => 'Synthetic reviewed same episode', 'source_state' => Incidents::fingerprint($a), 'target_state' => Incidents::fingerprint($b),
                'source_condition_state' => $guard->conditionState($a), 'target_condition_state' => $guard->conditionState($b),
                'expected_wheel_state' => $guard->resolve(1, 10, self::data()['panel_code'])['fingerprint']];
            $incident = new Incidents($f->db);
            $this->rejected($f, fn () => $incident->linkHistorical(1, 10, $source, $target, $data, 7), 'same-episode');
            $id = $this->success($incident->linkHistorical(1, 10, $source, $target, $data + ['same_episode_confirmed' => '1'], 7));
            $this->assertSame('one', $guard->resolve(1, 10, self::data()['panel_code'])['state']);
            foreach ((new VehicleDamageIncidentRepository($f->db))->memberships(1, 10, $id) as $member) {
                $this->assertNull($member['condition_event_id']);
                $this->assertNull($member['basis_snapshot']);
            }
            $this->success($damage->transitionStatus(1, 10, $target, 'repaired', 'Synthetic repair boundary', 7));
            $later = $this->success($damage->create(1, 10, self::data(), 7));
            $a = $items->item(1, 10, $target);
            $b = $items->item(1, 10, $later);
            $this->rejected($f, fn () => $incident->linkHistorical(1, 10, $target, $later, ['confirmed' => '1', 'same_episode_confirmed' => '1', 'reason' => 'Synthetic forbidden merge', 'source_state' => Incidents::fingerprint($a), 'target_state' => Incidents::fingerprint($b)], 7));
            $reopen = ['command_key' => Work::commandKey(), 'expected_condition_state' => $guard->conditionState($a), 'confirmed' => 1,
                'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic failed repair'];
            $this->rejected($f, fn () => $damage->reopenRepairedCondition(1, 10, $target, $reopen, 7), 'active episode');
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testContextRepairReopeningCannotDisplaceNewEpisode(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $work = new Work($f->db);
            $repairs = new VehicleDamageRepairRepository($f->db);
            $items = new VehicleDamageRepository($f->db);
            $id = $this->success($damage->create(1, 10, self::data(), 7));
            $job = $this->success($work->createJob(1, 10, RepairFixture::creation($f->db, [$id]), 7));
            $command = fn (array $extra = []) => $extra + ['command_key' => Work::commandKey(), 'expected_version' => $repairs->job(1, 10, $job)['version']];
            $this->success($work->start(1, 10, $job, $command(['started_at' => '2026-10-01T11:00']), 7));
            $member = $repairs->members(1, 10, $job)[0];
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(), 7), 'active');
            $this->success($work->confirmConditionRepaired(1, 10, $job, $command(['membership_id' => $member['id'], 'expected_condition_state' => $repairs->conditionFingerprint($items->item(1, 10, $id)),
                'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspected repair']), 7));
            $later = $this->success($damage->create(1, 10, self::data(), 7));
            $reopen = $command(['job_id' => $job, 'membership_id' => $member['id'], 'expected_condition_state' => $repairs->conditionFingerprint($items->item(1, 10, $id)),
                'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic context repair failure']);
            $this->rejected($f, fn () => $work->reopenCondition(1, 10, $id, $reopen, 7), 'active episode');
            $this->success($damage->transitionStatus(1, 10, $later, 'repaired', 'Synthetic later episode repaired', 7));
            $this->success($work->reopenCondition(1, 10, $id, $reopen, 7));
            $this->assertSame($id, (new Guard($f->db))->resolve(1, 10, self::data()['panel_code'])['candidates'][0]['id']);
        } finally {
            $f->close();
        }
    }

    public static function linkFailures(): array
    {
        $cases = [];
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['different_wheel', 'different_type', 'unknown', 'source_work', 'target_work', 'repaired_then_reopened', 'stale_event'] as $case) {
                $cases[$engine . '-' . $case] = [$engine, $case];
            }
        }
        return $cases;
    }

    #[DataProvider('linkFailures')]
    public function testHistoricalLinkRejectsWrongIdentityWorkAndRepairedEpisodes(string $engine, string $case): void
    {
        $f = new Fixture($engine);
        try {
            $f->migrate();
            $damage = new Damage($f->db);
            $items = new VehicleDamageRepository($f->db);
            $guard = new Guard($f->db);
            $target = $this->success($damage->create(1, 10, self::data(), 7));
            $source = $this->success($damage->create(1, 10, self::data(['panel_code' => 'front_right_wheel_tire']), 7));
            if ($case === 'repaired_then_reopened') {
                $this->success($damage->transitionStatus(1, 10, $source, 'repaired', 'Synthetic prior repair', 7));
                $this->success($damage->reopenRepairedCondition(1, 10, $source, ['command_key' => Work::commandKey(), 'expected_condition_state' => $guard->conditionState($items->item(1, 10, $source)), 'confirmed' => 1,
                    'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic reopened prior episode'], 7));
            }
            // Establish genuine prior work before introducing the deliberate legacy conflict.
            // Current repair targeting must reject a conflict rather than manufacture this history.
            if (in_array($case, ['source_work', 'target_work'], true)) {
                $this->success((new Work($f->db))->createJob(1, 10, RepairFixture::creation($f->db, [$case === 'source_work' ? $source : $target]), 7));
            }
            if ($case !== 'different_wheel') {
                $f->db->table('vehicle_damage_items')->where('id', $source)->update(['panel_code' => $case === 'unknown' ? null : self::data()['panel_code'],
                    'damage_type_code' => $case === 'different_type' ? 'scratch_scuff' : 'wheel_curb_rash']);
            }
            $a = $items->item(1, 10, $source);
            $b = $items->item(1, 10, $target);
            $data = ['confirmed' => '1', 'same_episode_confirmed' => '1', 'reason' => 'Synthetic forbidden historical link', 'source_state' => Incidents::fingerprint($a), 'target_state' => Incidents::fingerprint($b),
                'source_condition_state' => $guard->conditionState($a), 'target_condition_state' => $guard->conditionState($b), 'expected_wheel_state' => $guard->resolve(1, 10, self::data()['panel_code'])['fingerprint']];
            if ($case === 'stale_event') {
                $f->db->table('vehicle_damage_item_events')->insert(['company_id' => 1, 'vehicle_damage_item_id' => $source, 'event_code' => 'observed_existing', 'occurred_at' => date('Y-m-d H:i:s'), 'actor_user_id' => 7, 'created_at' => date('Y-m-d H:i:s')]);
            }
            $this->rejected($f, fn () => (new Incidents($f->db))->linkHistorical(1, 10, $source, $target, $data, 7));
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testSchemaReadinessAndDedicatedInputRejectBeforeWriting(string $engine): void
    {
        $f = new Fixture($engine);
        try {
            $damage = new Damage($f->db);
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(), 7));
            $f->migrate();
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(['wheel_code' => 'LR']), 7), 'Identity mismatch');
            $f->db->table('migrations')->where('version', '2026-10-11-000036')->delete();
            $this->rejected($f, fn () => $damage->create(1, 10, self::data(), 7));
            $this->success($damage->create(1, 10, self::data(['damage_type_code' => 'crack_broken']), 7));
        } finally {
            $f->close();
        }
    }
}
