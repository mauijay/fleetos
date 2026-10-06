<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairServiceTest extends VehicleDamageRepairTestCase
{
    public function testEveryLifecycleCommandRevalidatesTheExactStateMatrix(): void
    {
        $legal = ['planned' => ['schedule', 'start', 'defer', 'cancel'], 'scheduled' => ['schedule', 'start', 'defer', 'cancel'], 'in_progress' => ['defer', 'cancel', 'complete'], 'deferred' => ['schedule', 'start', 'cancel', 'complete', 'resume_planned', 'resume_scheduled', 'resume_in_progress'], 'completed' => ['reopenJob'], 'cancelled' => ['reopenJob']];
        $targets = ['schedule' => 'scheduled', 'start' => 'in_progress', 'defer' => 'deferred', 'cancel' => 'cancelled', 'complete' => 'completed', 'reopenJob' => 'planned', 'resume_planned' => 'planned', 'resume_scheduled' => 'scheduled', 'resume_in_progress' => 'in_progress'];
        foreach ($legal as $status => $allowed) {
            foreach ($targets as $command => $target) {
                $id = $this->condition();
                $job = $this->createWork([$id]);
                $started = in_array($status, ['in_progress', 'deferred', 'completed', 'cancelled'], true) ? '2026-10-01 09:00:00' : null;
                $this->connection->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['status_code' => $status, 'started_at' => $started, 'scheduled_at' => $status === 'scheduled' ? '2026-09-30 09:00:00' : null]);
                $member = $this->repairs->members(1, 10, $job)[0];
                $data = $this->command($job, ['scheduled_at' => '2026-10-02T10:00', 'started_at' => '2026-10-01T09:00', 'reason' => 'Synthetic lifecycle matrix', 'same_work_order_confirmed' => 1, 'reason_category_code' => 'continuing_order', 'completion_time_unknown' => 1, 'completion_note' => 'Synthetic completion', 'outcomes' => [['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'result_code' => 'unchanged', 'note' => 'Synthetic outcome']]]);
                $method = match ($command) {
                    'resume_planned', 'resume_scheduled', 'resume_in_progress' => 'resume', default => $command
                };
                if ($method === 'resume') {
                    $data['target_status'] = $target;
                }
                $before = $this->counts();
                $physical = $this->conditions->item(1, 10, $id);
                $result = $this->work->{$method}(1, 10, $job, $data, 7);
                $this->assertSame(in_array($command, $allowed, true), $result['success'], $status . ' / ' . $command . ': ' . json_encode($result));
                if ($result['success']) {
                    $this->assertSame($target, $this->repairs->job(1, 10, $job)['status_code']);
                    $this->assertSame(2, (int) $this->repairs->job(1, 10, $job)['version']);
                    $this->assertSame(2, (int) $this->repairs->events(1, 10, $job)[1]['job_version']);
                } else {
                    $this->assertSame($before, $this->counts());
                    $this->assertSame($status, $this->repairs->job(1, 10, $job)['status_code']);
                }
                $this->assertSame($physical, $this->conditions->item(1, 10, $id));
            }
        }
        $job = $this->createWork([$this->condition()]);
        $this->success($this->work->defer(1, 10, $job, $this->command($job, ['reason' => 'Synthetic not-started deferral']), 7));
        $this->failure($this->work->complete(1, 10, $job, $this->command($job), 7), 'actually started');
    }

    public function testMultiConditionCreationHasOneVersionAndOnlyChangedObjectAudits(): void
    {
        $ids = [$this->condition(), $this->condition()];
        $before = $this->counts();
        $job = $this->createWork($ids);
        $this->assertSame('planned', $this->repairs->job(1, 10, $job)['status_code']);
        $this->assertSame(1, (int) $this->repairs->job(1, 10, $job)['version']);
        $this->assertCount(2, $this->repairs->members(1, 10, $job));
        $this->assertSame(['job_created'], array_column($this->repairs->events(1, 10, $job), 'event_code'));
        $this->assertSame($before['vehicle_damage_item_events'], $this->counts()['vehicle_damage_item_events']);
        $this->assertSame($before['audit_logs'] + 3, $this->counts()['audit_logs']);
    }

    public function testHistoricalMitigationKeepsUnknownVendorAndTimeNullAndDoesNotChangePhysicalState(): void
    {
        $id = $this->condition();
        $before = $this->conditions->item(1, 10, $id);
        $job = $this->createWork([$id], ['intent_code' => 'mitigation', 'recording_mode' => 'historical_mitigation', 'performed_confirmed' => 1, 'recording_reason' => 'Synthetic historical entry', 'completion_note' => 'Temporary protection applied']);
        $row = $this->repairs->job(1, 10, $job);
        $this->assertSame('completed', $row['status_code']);
        foreach (['vendor_company_id', 'vendor_snapshot', 'scheduled_at', 'started_at', 'completed_at'] as $field) {
            $this->assertNull($row[$field]);
        }
        $this->assertSame('mitigated', $this->repairs->members(1, 10, $job)[0]['result_code']);
        $this->assertSame($before, $this->conditions->item(1, 10, $id));
        $this->assertSame('historical_mitigation_recorded', $this->repairs->events(1, 10, $job)[0]['event_code']);
        $this->failure($this->work->createJob(1, 10, array_replace(Fixture::creation($this->connection, [$id]), ['recording_mode' => 'historical_mitigation']), 7));
    }

    public function testLifecycleAndReopenPreservePriorCycleInHistory(): void
    {
        $job = $this->createWork([$this->condition()]);
        $this->success($this->work->schedule(1, 10, $job, $this->command($job, ['scheduled_at' => '2026-10-01T10:00']), 7));
        $this->success($this->work->schedule(1, 10, $job, $this->command($job, ['scheduled_at' => '2026-10-02T10:00']), 7));
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $this->success($this->work->defer(1, 10, $job, $this->command($job, ['reason' => 'Synthetic delay']), 7));
        $this->success($this->work->resume(1, 10, $job, $this->command($job, ['target_status' => 'in_progress', 'reason' => 'Continuing effort']), 7));
        $this->assertSame('2026-10-01 09:00:00', $this->repairs->job(1, 10, $job)['started_at']);
        $this->success($this->work->cancel(1, 10, $job, $this->command($job, ['reason' => 'Wrong cancellation']), 7));
        $this->failure($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-02T09:00']), 7));
        $this->success($this->work->reopenJob(1, 10, $job, $this->command($job, ['same_work_order_confirmed' => 1, 'reason_category_code' => 'incorrect_cancellation', 'reason' => 'Correcting cancellation']), 7));
        $row = $this->repairs->job(1, 10, $job);
        $this->assertSame('planned', $row['status_code']);
        $this->assertNull($row['started_at']);
        $this->assertNull($row['scheduled_at']);
        $this->assertSame(range(1, 8), array_map('intval', array_column($this->repairs->events(1, 10, $job), 'job_version')));
        $prior = json_decode($this->repairs->events(1, 10, $job)[7]['before_json'], true);
        $this->assertSame('2026-10-01 09:00:00', $prior['job']['started_at']);
        $this->failure($this->work->correctJobDetails(1, 10, $job, $this->command($job, ['intent_code' => 'mitigation', 'category_code' => 'body', 'summary' => 'Synthetic rewritten intent', 'reason' => 'Rewrite after cycle reset']), 7), 'cannot be rewritten');
    }

    public function testWithdrawalReactivationAndLastMemberGuard(): void
    {
        $a = $this->condition();
        $b = $this->condition();
        $job = $this->createWork([$a, $b]);
        $members = $this->repairs->members(1, 10, $job);
        $this->success($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $members[0]['id'], 'reason' => 'Scope correction']), 7));
        $this->failure($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $members[1]['id'], 'reason' => 'Remove all']), 7));
        $this->success($this->work->addCondition(1, 10, $job, $this->command($job, ['condition' => Fixture::selection($this->connection, $a), 'reason' => 'Restore scope']), 7));
        $this->assertCount(2, $this->repairs->members(1, 10, $job));
        $this->assertNull($this->repairs->members(1, 10, $job)[0]['withdrawn_at']);
    }

    public function testStaleVersionFingerprintAndNoOpLeaveNoPartialHistory(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $payload = $this->command($job, ['scheduled_at' => '2026-10-01T10:00']);
        $this->success($this->work->schedule(1, 10, $job, $payload, 7));
        $before = $this->counts();
        $payload['command_key'] = \App\Services\Fleet\VehicleDamageRepairService::commandKey();
        $this->failure($this->work->schedule(1, 10, $job, $payload, 7), 'changed');
        $this->failure($this->work->schedule(1, 10, $job, $this->command($job, ['scheduled_at' => '2026-10-01T10:00']), 7), 'No change');
        $data = Fixture::creation($this->connection, [$id]);
        $this->success($this->damage->worsen(1, 10, $id, ['severity_code' => 'moderate', 'note' => 'Synthetic worsening', 'occurred_at' => '2026-10-01 09:00:00'], 7));
        $afterWorsening = $this->counts();
        $this->failure($this->work->createJob(1, 10, $data, 7), 'changed');
        $this->assertSame($afterWorsening, $this->counts());
        $this->assertSame($before['vehicle_damage_repair_job_events'], $this->counts()['vehicle_damage_repair_job_events']);
    }

    public function testDetailsCorrectionRequiresReasonAndCannotRewritePerformedIntent(): void
    {
        $job = $this->createWork([$this->condition()]);
        $data = ['intent_code' => 'repair', 'category_code' => 'body', 'summary' => 'Corrected synthetic summary'];
        $this->failure($this->work->correctJobDetails(1, 10, $job, $this->command($job, $data), 7));
        $this->success($this->work->correctJobDetails(1, 10, $job, $this->command($job, $data + ['reason' => 'Correct spelling']), 7));
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $data['intent_code'] = 'mitigation';
        $this->failure($this->work->correctJobDetails(1, 10, $job, $this->command($job, $data + ['reason' => 'Rewrite work']), 7), 'cannot be rewritten');
    }
}
