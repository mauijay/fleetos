<?php

use App\Repositories\AuditLogRepository;
use App\Services\Fleet\VehicleDamageRepairService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairOutcomeTest extends VehicleDamageRepairTestCase
{
    public static function persistenceFailures(): array
    {
        return [['vehicle_damage_repair_job_items'], ['vehicle_damage_repair_job_events'], ['vehicle_damage_item_events'], ['vehicle_damage_items']];
    }

    #[DataProvider('persistenceFailures')]
    public function testPersistenceFailureRollsBackEveryObjectAndLeavesTheKeyReusable(string $table): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $data = $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspected repair']);
        $before = [];
        foreach (array_keys($this->counts()) as $name) {
            $before[$name] = $this->connection->table($name)->get()->getResultArray();
        }
        $before['vehicle_damage_items'] = $this->connection->table('vehicle_damage_items')->get()->getResultArray();
        $operation = str_ends_with($table, 'events') ? 'INSERT' : 'UPDATE';
        $this->connection->query('CREATE TRIGGER synthetic_work_write_failure BEFORE ' . $operation . ' ON ' . $this->connection->prefixTable($table) . " BEGIN SELECT RAISE(FAIL, 'Synthetic write failure'); END");
        (new ReflectionProperty($this->connection, 'DBDebug'))->setValue($this->connection, false);
        $this->failure($this->work->confirmConditionRepaired(1, 10, $job, $data, 7));
        foreach ($before as $name => $rows) {
            $this->assertSame($rows, $this->connection->table($name)->get()->getResultArray(), $name);
        }
        $this->connection->query('DROP TRIGGER synthetic_work_write_failure');
        $this->success($this->work->confirmConditionRepaired(1, 10, $job, $data, 7));
        $this->assertSame(3, (int) $this->repairs->job(1, 10, $job)['version']);
        $this->assertSame('repaired', $this->conditions->item(1, 10, $id)['status_code']);
    }

    public function testStandaloneReceiptsAreBoundedScopedAndRejectMalformedMatchingData(): void
    {
        $a = $this->condition();
        $b = $this->condition();
        $key = VehicleDamageRepairService::commandKey();
        foreach ([$a, $b] as $id) {
            $this->success($this->damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic legacy repair', 7));
            $data = ['command_key' => $key, 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic legacy failure'];
            $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $data, 7));
            $before = $this->counts();
            $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $data, 7));
            $this->assertSame($before, $this->counts());
            $this->assertCount(1, $this->repairs->standaloneReceipts(1, 10, $id, $key));
        }
        $this->assertSame([], $this->repairs->standaloneReceipts(2, 20, $a, $key));
        $this->assertSame([], $this->repairs->standaloneReceipts(1, 11, $a, $key));
        $id = $this->condition();
        $this->success($this->damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic legacy repair', 7));
        $audit = $this->connection->table('audit_logs')->where('table_name', 'vehicle_damage_items')->where('record_id', $id)->orderBy('id', 'DESC')->get()->getRowArray();
        // Old malformed JSON and operator prose must not be mistaken for a reserved receipt.
        $this->connection->table('audit_logs')->where('id', $audit['id'])->update(['new_values' => 'malformed synthetic JSON']);
        $data = ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic b21_command operator prose'];
        $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $data, 7));
        $receipt = $this->connection->table('audit_logs')->where('table_name', 'vehicle_damage_items')->where('record_id', $id)->orderBy('id', 'DESC')->get()->getRowArray();
        $stored = json_decode($receipt['new_values'], true, 512, JSON_THROW_ON_ERROR);
        $stored['b21_command']['command_payload_hash'] = ['invalid'];
        $this->connection->table('audit_logs')->where('id', $receipt['id'])->update(['new_values' => json_encode($stored, JSON_THROW_ON_ERROR)]);
        $before = $this->counts();
        $this->failure($this->damage->reopenRepairedCondition(1, 10, $id, $data, 7), 'receipt is invalid');
        $this->assertSame($before, $this->counts());
    }

    public static function outcomes(): array
    {
        return [['mitigation', 'mitigated'], ['repair', 'unchanged'], ['repair', 'partially_repaired'], ['repair', 'repair_reported'], ['repair', 'unassessed']];
    }

    #[DataProvider('outcomes')]
    public function testCompletionNeverConfirmsPhysicalRepair(string $intent, string $result): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id], ['intent_code' => $intent]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $physical = $this->conditions->item(1, 10, $id);
        $before = $this->counts();
        $data = ['completion_time_unknown' => 1, 'completion_note' => 'Synthetic work completed', 'outcomes' => [['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'result_code' => $result, 'note' => 'Synthetic assessed outcome']]];
        $this->success($this->work->complete(1, 10, $job, $this->command($job, $data), 7));
        $this->assertSame('completed', $this->repairs->job(1, 10, $job)['status_code']);
        $this->assertSame($physical, $this->conditions->item(1, 10, $id));
        $this->assertCount(1, $this->conditions->currentForVehicle(1, 10));
        $this->assertSame($before['vehicle_damage_item_events'], $this->counts()['vehicle_damage_item_events']);
        $this->assertSame($before['audit_logs'] + 2, $this->counts()['audit_logs']);
    }

    public function testConfirmationAndContextReopenAreAtomicAndCorrelatedWithoutReopeningJob(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $result = $this->work->confirmConditionRepaired(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection passed']), 7);
        $this->success($result);
        $this->assertCount(0, $this->conditions->currentForVehicle(1, 10));
        $repairEvent = $this->repairs->latestConditionEvent(1, $id);
        $this->assertSame('repaired', $repairEvent['event_code']);
        $this->assertSame($result['event_id'], (int) $repairEvent['repair_job_event_id']);
        $this->failure($this->damage->transitionStatus(1, 10, $id, 'open', 'Generic reopening', 7));
        $base = ['expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => '2026-10-03T10:00', 'reason_category_code' => 'residual_damage', 'note' => 'Synthetic residual damage'];
        $this->failure($this->damage->reopenRepairedCondition(1, 10, $id, $base + ['command_key' => VehicleDamageRepairService::commandKey()], 7), 'exact B2');
        $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $this->command($job, $base + ['job_id' => $job, 'membership_id' => $member['id']]), 7));
        $this->assertCount(1, $this->conditions->currentForVehicle(1, 10));
        $this->assertSame('in_progress', $this->repairs->job(1, 10, $job)['status_code']);
        $this->assertSame('partially_repaired', $this->repairs->members(1, 10, $job)[0]['result_code']);
        $this->assertSame($repairEvent, $this->repairs->latestConditionEvent(1, $id, 'repaired'));
        $this->assertNull($this->conditions->item(1, 10, $id)['resolved_at']);
        $this->assertNotNull($this->repairs->latestConditionEvent(1, $id)['repair_job_event_id']);
        $new = $this->condition();
        $this->assertNotSame($id, $new);
        $this->assertCount(2, $this->conditions->currentForVehicle(1, 10));
    }

    public function testLegacyReopenUsesAuditReceiptAndPreservesGenericTerminalRules(): void
    {
        $id = $this->condition();
        $this->success($this->damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic legacy repair', 7));
        $data = ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic failed repair'];
        $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $data, 7));
        $before = $this->counts();
        $retry = $this->damage->reopenRepairedCondition(1, 10, $id, $data, 7);
        $this->success($retry);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, $before['vehicle_damage_repair_jobs']);
        $this->assertNull($this->repairs->latestConditionEvent(1, $id)['repair_job_event_id']);
        $this->failure($this->damage->reopenRepairedCondition(1, 10, $id, array_replace($data, ['note' => 'Different payload']), 7), 'different payload');
        $other = $this->condition();
        $this->success($this->damage->transitionStatus(1, 10, $other, 'resolved_other', 'Synthetic correction', 7));
        $this->failure($this->damage->reopenRepairedCondition(1, 10, $other, array_replace($data, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_condition_state' => $this->state($other)]), 7));
    }

    public function testWithdrawalAfterRepairDoesNotTrapPhysicalConditionReopening(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id, $this->condition()]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $this->success($this->work->confirmConditionRepaired(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection']), 7));
        $this->success($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'reason' => 'Synthetic future scope withdrawal']), 7));
        $withdrawn = $this->repairs->members(1, 10, $job)[0];
        $this->success($this->damage->reopenRepairedCondition(1, 10, $id, $this->command($job, ['job_id' => $job, 'membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'observed_at' => '2026-10-03T10:00', 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic failed withdrawn repair']), 7));
        $after = $this->repairs->members(1, 10, $job)[0];
        $this->assertSame('failed', $after['result_code']);
        foreach (['withdrawn_at', 'withdrawn_by', 'withdrawal_reason'] as $field) {
            $this->assertSame($withdrawn[$field], $after[$field]);
        }
        $this->assertSame('open', $this->conditions->item(1, 10, $id)['status_code']);
        $this->assertSame('in_progress', $this->repairs->job(1, 10, $job)['status_code']);
    }

    public function testForgedRepairAndIncompleteCompletionRollBackAllMemberChanges(): void
    {
        $ids = [$this->condition(), $this->condition()];
        $job = $this->createWork($ids);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $members = $this->repairs->members(1, 10, $job);
        $before = $this->counts();
        $outcome = ['membership_id' => $members[0]['id'], 'expected_condition_state' => $this->state($ids[0]), 'result_code' => 'repaired', 'note' => 'Forged repaired result'];
        $this->failure($this->work->recordMembershipResult(1, 10, $job, $this->command($job, $outcome), 7), 'inspection');
        $this->failure($this->work->complete(1, 10, $job, $this->command($job, ['outcomes' => [$outcome]]), 7), 'every active');
        $outcome['result_code'] = 'unchanged';
        $bad = $outcome;
        $bad['membership_id'] = $members[1]['id'];
        $bad['expected_condition_state'] = 'stale';
        $this->failure($this->work->complete(1, 10, $job, $this->command($job, ['outcomes' => [$outcome, $bad]]), 7), 'changed');
        $this->assertSame($members, $this->repairs->members(1, 10, $job));
        $this->assertSame($before, $this->counts());
    }

    public function testAuditFailureRollsBackPhysicalConfirmationAndWorkHistory(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $audit = $this->getMockBuilder(AuditLogRepository::class)->setConstructorArgs([$this->connection])->onlyMethods(['record'])->getMock();
        $audit->expects($this->once())->method('record')->willThrowException(new RuntimeException('Synthetic audit failure'));
        $service = new VehicleDamageRepairService($this->connection, audits: $audit);
        $before = $this->counts();
        $this->failure($service->confirmConditionRepaired(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection']), 7));
        $this->assertSame($before, $this->counts());
        $this->assertSame('open', $this->conditions->item(1, 10, $id)['status_code']);
        $this->assertSame('unassessed', $this->repairs->members(1, 10, $job)[0]['result_code']);
    }

    public function testNonThrowingDatabaseAuditFailureStillRollsBackImmediateTransaction(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $this->success($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01T09:00']), 7));
        $member = $this->repairs->members(1, 10, $job)[0];
        $before = $this->counts();
        $this->connection->query('CREATE TRIGGER synthetic_work_audit_failure BEFORE INSERT ON ' . $this->connection->prefixTable('audit_logs') . " WHEN NEW.table_name = 'vehicle_damage_repair_jobs' BEGIN SELECT RAISE(FAIL, 'Synthetic audit failure'); END");
        (new ReflectionProperty($this->connection, 'DBDebug'))->setValue($this->connection, false);
        $result = $this->work->confirmConditionRepaired(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'expected_condition_state' => $this->state($id), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection']), 7);
        $this->failure($result, 'Audit history could not be recorded');
        $this->assertSame($before, $this->counts());
        $this->assertSame('open', $this->conditions->item(1, 10, $id)['status_code']);
    }
}
