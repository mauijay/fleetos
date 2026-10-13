<?php

namespace Tests\Database;

use App\Services\Fleet\VehicleWheelEpisodeGuard;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairMariaDbFixture;
use Tests\Support\VehicleWheelRepairFixture as Repair;

/** Mandatory independent connections, observed live InnoDB wait, no skipped races. */
final class VehicleWheelRepairMariaDbConcurrencyTest extends CIUnitTestCase
{
    public static function races(): array
    {
        return [['confirm_vs_create'], ['confirm_vs_worsen'], ['worsen_vs_confirm'], ['new_vs_reopen'], ['reopen_vs_new'], ['two_confirmations']];
    }

    #[DataProvider('races')]
    public function testExactEpisodeCommandsSerializeWithoutConflictingRoots(string $race): void
    {
        $f = new VehicleDamageRepairMariaDbFixture(through: 36);
        $db = $f->db;
        try {
            $r = new Repair($db);
            $lf = $r->create('LF');
            $rf = $r->create('RF');
            $id = $lf['condition_id'];
            $job = $r->job([$id, $rf['condition_id']]);
            $r->start($job);
            $confirm = $r->confirmation($job, $id);
            if (in_array($race, ['new_vs_reopen', 'reopen_vs_new'], true)) {
                Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $confirm, 7));
            }
            $new = $r->data('LF', ['discovered_at' => '2026-10-01 15:00:00', 'occurred_at' => '2026-10-01 15:00:00']);
            $reopen = $r->reopening($job, $id);
            $second = $r->confirmation($job, $rf['condition_id']);
            $operation = match ($race) {
                'confirm_vs_worsen' => 'wheelOccurrenceWorsen', 'worsen_vs_confirm', 'two_confirmations' => 'confirmConditionRepaired',
                'new_vs_reopen' => 'reopenCondition', default => 'wheelOccurrenceCreate',
            };
            $arguments = match ($operation) {
                'confirmConditionRepaired' => [1, 10, $job, $race === 'two_confirmations' ? $second : $confirm, 7],
                'reopenCondition' => [1, 10, $id, $reopen, 7], default => [1, 10, $new, 7],
            };
            $db->transBegin();
            $r->items->lockVehicle(1, 10);
            $result = $f->contendUntilCommit($operation, $arguments, function (\Closure $wait) use ($race, $db, $r, $job, $id, $confirm, $new, $reopen): array {
                $wait();
                $winner = match ($race) {
                    'worsen_vs_confirm' => $r->wheels->recordWorsening(1, 10, $new, 7),
                    'new_vs_reopen' => $r->wheels->createNew(1, 10, $new, 7),
                    'reopen_vs_new' => $r->work->reopenCondition(1, 10, $id, $reopen, 7),
                    default => $r->work->confirmConditionRepaired(1, 10, $job, $confirm, 7),
                };
                $winner['success'] ? $db->transCommit() : $db->transRollback();
                return $winner;
            });
            self::assertTrue($result['blocked']);
            self::assertNotEmpty($result['locks']);
            self::assertTrue($result['winner']['success']);
            self::assertFalse($result['result']['success'], json_encode($result));
            $guard = new VehicleWheelEpisodeGuard($db);
            $state = $guard->resolve(1, 10, 'front_left_wheel_tire');
            self::assertSame(in_array($race, ['confirm_vs_create', 'confirm_vs_worsen', 'two_confirmations'], true) ? 'none' : 'one', $state['state']);
            self::assertSame('valid', $state['alias_integrity']);
            self::assertCount(2, $r->repairs->members(1, 10, $job));
            if ($race === 'two_confirmations') {
                // Shared job version makes the second preview stale, even on a different wheel.
                self::assertSame('open', $r->items->item(1, 10, $rf['condition_id'])['status_code']);
                Repair::requireSuccess($r->work->confirmConditionRepaired(1, 10, $job, $r->confirmation($job, $rf['condition_id']), 7));
                self::assertSame('none', $guard->resolve(1, 10, 'front_right_wheel_tire')['state']);
            }
            if ($race === 'confirm_vs_create') {
                $later = $r->create('LF', ['discovered_at' => '2026-10-01 15:00:00', 'occurred_at' => '2026-10-01 15:00:00']);
                self::assertNotSame($id, $later['condition_id']);
            }
            self::assertSame(2 + (int) ($race === 'new_vs_reopen' || $race === 'confirm_vs_create'), $db->table('vehicle_damage_items')->countAllResults());
        } finally {
            $f->close();
        }
    }
}
