<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairBoundaryTest extends VehicleDamageRepairTestCase
{
    public function testEntireWorkAndPhysicalOutcomeCycleLeavesEveryOtherDomainUnchanged(): void
    {
        $id = $this->condition();
        $before = [];
        foreach ($this->connection->listTables() as $table) {
            if (! preg_match('/(?:vehicle_damage_repair_|vehicle_damage_items$|vehicle_damage_item_events$|audit_logs$)/', $table)) {
                $before[$table] = $this->connection->table($table)->get()->getResultArray();
            }
        }
        $job = $this->createWork([$id]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $this->success($this->work->recordMembershipResult(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'result_code' => 'repair_reported', 'note' => 'Synthetic vendor report']), 7));
        $this->success($this->work->confirmConditionRepaired(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection']), 7));
        $this->success($this->work->complete(1, 10, $job, $this->command($job, ['completed_at' => '2026-10-02T11:00', 'completion_note' => 'Synthetic completion', 'outcomes' => [['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'result_code' => 'repaired']]]), 7));
        $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $this->command($job, ['job_id' => $job, 'membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => '2026-10-03T10:00', 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic repair failure']), 7));
        $this->success($this->work->reopenJob(1, 10, $job, $this->command($job, ['same_work_order_confirmed' => 1, 'reason_category_code' => 'continuing_order', 'reason' => 'Synthetic continuing order']), 7));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->connection->table($table)->get()->getResultArray(), $table);
        }
        $this->assertSame('completed', json_decode($this->repairs->events(1, 10, $job)[5]['after_json'], true)['job']['status_code']);
    }
}
