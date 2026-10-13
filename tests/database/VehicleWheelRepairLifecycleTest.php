<?php

namespace Tests\Database;

use App\Services\Fleet\VehicleDamageService;
use App\Services\Fleet\VehicleWheelConditionReadService;
use App\Services\Fleet\VehicleWheelRepairPresentation;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairDatabaseFixture;
use Tests\Support\VehicleWheelDamageFixture;
use Tests\Support\VehicleWheelRepairFixture as Repair;

final class VehicleWheelRepairLifecycleTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return [['sqlite'], ['maria']];
    }

    #[DataProvider('engines')]
    public function testBatchPartialRepairPreservesTripsAndExactHistoricalMemberships(string $engine): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        try {
            $r = new Repair($db);
            $lf = $r->create('LF', ['trip_id' => 100, 'attribution_type' => 'discovered_during_trip']);
            $rf = $r->create('RF', ['trip_id' => 102, 'attribution_type' => 'discovered_during_trip']);
            $rr = $r->create('RR');
            $body = VehicleDamageRepairDatabaseFixture::condition($db);
            Repair::requireSuccess((new VehicleDamageService($db))->transitionStatus(1, 10, $rf['condition_id'], 'accepted_unrepaired', 'Synthetic settlement without repair.', 7));
            $ids = [$lf['condition_id'], $rf['condition_id'], $rr['condition_id'], $body];
            $job = $r->job($ids);
            $r->start($job);
            $membersBefore = $db->table('vehicle_damage_incident_items')->get()->getResultArray();
            Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $r->confirmation($job, $lf['condition_id']), 7));
            Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $r->confirmation($job, $rf['condition_id']), 7));
            $outcomes = [];
            foreach ($ids as $id) {
                $outcomes[] = ['membership_id' => $r->member($job, $id)['id'], 'expected_condition_state' => $r->repairs->conditionFingerprint($r->items->item(1, 10, $id)),
                    'result_code' => in_array($id, [$lf['condition_id'], $rf['condition_id']], true) ? 'repaired' : 'partially_repaired', 'note' => 'Synthetic independently assessed result.'];
            }
            Repair::requireSuccess($r->work->complete(1, 10, $job, $r->command($job, ['outcomes' => $outcomes, 'completed_at' => '2026-10-01 11:00:00', 'completion_note' => 'Synthetic partial visit.']), 7));
            $reads = new VehicleWheelConditionReadService($db);
            $projection = $reads->forVehicles(1, [10])[10];
            self::assertSame(['none', 'none', 'one', 'none'], array_column($projection['wheels'], 'state'));
            self::assertSame('open', $r->items->item(1, 10, $rr['condition_id'])['status_code']);
            self::assertSame('open', $r->items->item(1, 10, $body)['status_code']);
            self::assertSame('repaired', $projection['wheels']['LF']['repair_context']['physical_event_code']);
            $strip = \CodeIgniter\Config\Services::renderer()->setData(['projection' => $projection])->render('vehicle_wheel_history/_strip');
            self::assertStringContainsString('No active rash recorded', $strip);
            self::assertStringContainsString('Repaired 2026-10-01 10:00:00', $strip);
            self::assertSame($membersBefore, $db->table('vehicle_damage_incident_items')->get()->getResultArray(), 'Repair must not collapse guest attribution or move occurrence evidence.');
            $before = $f->schema->rows();
            $stale = $r->data('LF', ['discovered_at' => '2026-10-01 09:59:59', 'occurred_at' => '2026-10-01 09:59:59']);
            self::assertFalse($r->wheels->createNew(1, 10, $stale, 7)['success']);
            self::assertSame($before, $f->schema->rows());
            $later = $r->create('LF', ['discovered_at' => '2026-10-01 15:00:00', 'occurred_at' => '2026-10-01 15:00:00']);
            self::assertNotSame($lf['condition_id'], $later['condition_id']);
            self::assertSame($ids, array_map('intval', array_column($r->repairs->members(1, 10, $job), 'vehicle_damage_item_id')));
            $before = $f->schema->rows();
            $denied = $r->work->reopenCondition(1, 10, $lf['condition_id'], $r->reopening($job, $lf['condition_id']), 7);
            self::assertFalse($denied['success']);
            self::assertStringContainsString('active episode', implode(' ', $denied['errors']));
            self::assertSame($before, $f->schema->rows());
            $contexts = VehicleWheelRepairPresentation::contexts($db->table('vehicle_damage_items')->get()->getResultArray(), $reads->forVehicles(1, [10])[10]);
            self::assertTrue($contexts[$lf['condition_id']]['reopen_blocked']);
            self::assertStringEndsWith('?episode=' . $lf['condition_id'], $contexts[$lf['condition_id']]['history_url']);
            self::assertSame('LF Wheel — curb rash', $contexts[$lf['condition_id']]['label']);
            self::assertTrue($contexts[$later['condition_id']]['selectable']);
            $history = $reads->history(1, 10, 'LF', episode: $lf['condition_id']);
            self::assertSame($before, $f->schema->rows(), 'Historical review GET is pure.');
            self::assertNotEmpty($history);
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testWorseningInvalidatesInspectionPreviewAndChronologyThenResidualReopen(string $engine): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        try {
            $r = new Repair($f->schema->db);
            $lf = $r->create('LF');
            $id = $lf['condition_id'];
            $job = $r->job([$id]);
            $r->start($job);
            $stale = $r->confirmation($job, $id);
            Repair::requireSuccess($r->wheels->recordWorsening(1, 10, $r->data('LF', ['discovered_at' => '2026-10-01 11:00:00', 'occurred_at' => '2026-10-01 11:00:00', 'severity_code' => 'moderate', 'note' => 'Synthetic later guest worsening.']), 7));
            $before = $f->schema->rows();
            self::assertFalse($r->work->confirmConditionRepaired(1, 10, $job, $stale, 7)['success']);
            $denied = $r->work->confirmConditionRepaired(1, 10, $job, $r->confirmation($job, $id), 7);
            self::assertFalse($denied['success']);
            self::assertStringContainsString('chronology', implode(' ', $denied['errors']));
            self::assertSame($before, $f->schema->rows());
            self::assertCount(1, $r->repairs->members(1, 10, $job));
            self::assertSame($id, (int) $r->repairs->members(1, 10, $job)[0]['vehicle_damage_item_id']);
            Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $r->confirmation($job, $id, '2026-10-01 12:00:00'), 7));
            Repair::requireSuccess($r->work->reopenCondition(1, 10, $id, $r->reopening($job, $id), 7));
            $entry = $r->wheels->entry(1, 10, 'LF');
            self::assertSame($id, (int) $entry['condition']['id']);
            self::assertSame('one', $entry['state']['state']);
            self::assertSame('partially_repaired', $r->member($job, $id)['result_code']);
            self::assertSame(1, $f->schema->db->table('vehicle_damage_items')->countAllResults());
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testConflictingCurrentSelectionFailsAtomicallyAndHistoricalScopeRemainsReviewable(string $engine): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        try {
            $r = new Repair($db);
            $lf = $r->create('LF');
            $id = $lf['condition_id'];
            $job = $r->job([$id]);
            $row = $db->table('vehicle_damage_items')->where('id', $id)->get()->getRowArray();
            unset($row['id']);
            $db->table('vehicle_damage_items')->insert($row);
            $before = $f->schema->rows();
            self::assertFalse($r->work->createJob(1, 10, VehicleDamageRepairDatabaseFixture::creation($db, [$id]), 7)['success']);
            self::assertFalse($r->work->addCondition(1, 10, $job, $r->command($job, ['condition' => VehicleDamageRepairDatabaseFixture::selection($db, $id), 'reason' => 'Synthetic scope change.']), 7)['success']);
            self::assertSame($before, $f->schema->rows());
            $projection = (new VehicleWheelConditionReadService($db))->forVehicles(1, [10])[10];
            $contexts = VehicleWheelRepairPresentation::contexts($db->table('vehicle_damage_items')->get()->getResultArray(), $projection);
            self::assertFalse($contexts[$id]['selectable']);
            self::assertSame('Review conflicting records', $contexts[$id]['warning']);
            self::assertSame($id, (int) $r->repairs->members(1, 10, $job)[0]['vehicle_damage_item_id']);
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testParentScopingStaleMembershipAndRepairedEpisodeCannotReceiveLaterWorsening(string $engine): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        try {
            $r = new Repair($f->schema->db);
            $lf = $r->create('LF');
            $rf = $r->create('RF');
            $job = $r->job([$lf['condition_id']]);
            $otherJob = $r->job([$rf['condition_id']]);
            $r->start($job);
            $confirm = $r->confirmation($job, $lf['condition_id']);
            $before = $f->schema->rows();
            foreach ([[2, 10, $job, $confirm], [1, 20, $job, $confirm], [1, 10, $otherJob, $confirm], [1, 10, $job, array_replace($confirm, ['membership_id' => $r->member($otherJob, $rf['condition_id'])['id']])]] as $args) {
                self::assertFalse($r->work->confirmConditionRepaired(...[...$args, 7])['success']);
                self::assertSame($before, $f->schema->rows());
            }
            $staleWorsening = $r->data('LF', ['discovered_at' => '2026-10-01 15:00:00', 'occurred_at' => '2026-10-01 15:00:00']);
            Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $confirm, 7));
            $before = $f->schema->rows();
            self::assertFalse($r->wheels->recordWorsening(1, 10, $staleWorsening, 7)['success']);
            self::assertSame($before, $f->schema->rows());
            self::assertSame('none', $r->wheels->entry(1, 10, 'LF')['state']['state']);
        } finally {
            $f->close();
        }
    }

    #[DataProvider('engines')]
    public function testBoundedRepairPresentationReadsAtOneFourAndTenMixedConditions(string $engine): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        try {
            $r = new Repair($db);
            $reads = new VehicleWheelConditionReadService($db);
            $reads->forVehicles(1, [10]); // Warm only fixed schema metadata.
            $counts = [];
            foreach ([1, 4, 10] as $size) {
                while ($db->table('vehicle_damage_items')->countAllResults() < $size) {
                    $index = $db->table('vehicle_damage_items')->countAllResults();
                    $index < 4 ? $r->create(['LF', 'RF', 'RR', 'LR'][$index]) : VehicleDamageRepairDatabaseFixture::condition($db);
                }
                $ids = array_map('intval', array_column($db->table('vehicle_damage_items')->get()->getResultArray(), 'id'));
                $job = $r->job($ids);
                $before = $f->schema->rows();
                $selects = [];
                $listener = static function ($query) use (&$selects): void {
                    if (preg_match('/^SELECT\b/i', ltrim($query->getQuery()))) {
                        $selects[] = $query->getQuery();
                    }
                };
                Events::on('DBQuery', $listener);
                try {
                    $conditions = $db->table('vehicle_damage_items')->where('company_id', 1)->where('fleet_vehicle_id', 10)->get()->getResultArray();
                    $r->repairs->members(1, 10, $job);
                    $r->repairs->fingerprints(1, $conditions);
                    $contexts = VehicleWheelRepairPresentation::contexts($conditions, $reads->forVehicles(1, [10])[10]);
                } finally {
                    Events::removeListener('DBQuery', $listener);
                }
                self::assertCount(6, $selects, 'Three existing batched condition/member/fingerprint reads + three Slice 4 projection reads.');
                self::assertCount(min($size, 4), $contexts);
                self::assertSame($before, $f->schema->rows());
                $counts[$size] = count($selects);
            }
            $directory = dirname(__DIR__, 2) . '/build/slice6';
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory . '/repair-budget-' . $engine . '.json', json_encode(['counts' => $counts, 'wheel_projection' => 3, 'get_writes' => 0], JSON_THROW_ON_ERROR));
        } finally {
            $f->close();
        }
    }
}
