<?php

use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Real independent connections; no production or SQLite substitute. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairMariaDbConcurrencyTest extends CIUnitTestCase
{
    public function testNestedValidationFailureRollsBackOnlyTheCommandWrites(): void
    {
        if (! getenv('B21_MARIADB_CONFIG')) {
            $this->markTestSkipped('Requires disposable B21 MariaDB release gate.');
        }
        $fixture = new VehicleDamageRepairMariaDbFixture();
        try {
            $db = $fixture->db;
            $work = new VehicleDamageRepairService($db);
            $data = Fixture::creation($db, [Fixture::condition($db)]);
            $valid = $data;
            $data['conditions'][0]['expected_condition_state'] = 'stale';
            $db->transBegin();
            $db->table('fleet_vehicles')->where('id', 10)->update(['license_plate' => 'SYNTHETIC-OUTER']);
            $failed = $work->createJob(1, 10, $data, 7);
            $this->assertFalse($failed['success']);
            $this->assertTrue($db->transStatus());
            foreach (['vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events'] as $table) {
                $this->assertSame(0, $db->table($table)->countAllResults());
            }
            $this->assertSame('SYNTHETIC-OUTER', $db->table('fleet_vehicles')->where('id', 10)->get()->getRowArray()['license_plate']);
            $retried = $work->createJob(1, 10, $valid, 7);
            $this->assertTrue($retried['success'], json_encode($retried));
            $this->assertSame(1, $db->transDepth);
            $this->assertTrue($db->transCommit());
            $this->assertSame(1, $db->table('vehicle_damage_repair_jobs')->countAllResults());
        } finally {
            $fixture->close();
        }
    }

    public function testMissingFinalReferenceForeignKeyFailsClosed(): void
    {
        if (! getenv('B21_MARIADB_CONFIG')) {
            $this->markTestSkipped('Requires disposable B21 MariaDB release gate.');
        }
        $fixture = new VehicleDamageRepairMariaDbFixture();
        try {
            $db = $fixture->db;
            $id = Fixture::condition($db);
            $repository = new VehicleDamageRepairRepository($db);
            $this->assertTrue($repository->ready());
            $db->query('ALTER TABLE vehicle_damage_item_events DROP FOREIGN KEY damage_event_repair_job_event_fk');
            $this->assertFalse($repository->ready());
            $result = (new VehicleDamageRepairService($db))->createJob(1, 10, Fixture::creation($db, [$id]), 7);
            $this->assertFalse($result['success']);
            $this->assertSame(0, $db->table('vehicle_damage_repair_jobs')->countAllResults());
            $this->expectException(RuntimeException::class);
            $repository->hasAnyMembershipForCondition(1, 10, $id);
        } finally {
            $fixture->close();
        }
    }

    public function testDatabaseFailureLeavesCommandReusableOnTheSameConnection(): void
    {
        if (! getenv('B21_MARIADB_CONFIG')) {
            $this->markTestSkipped('Requires disposable B21 MariaDB release gate.');
        }
        $fixture = new VehicleDamageRepairMariaDbFixture();
        try {
            $db = $fixture->db;
            $work = new VehicleDamageRepairService($db);
            $data = Fixture::creation($db, [Fixture::condition($db)]);
            $tables = ['vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'vehicle_damage_item_events', 'audit_logs'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $db->table($table)->countAllResults();
            }
            $db->query("CREATE TRIGGER synthetic_work_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic audit failure'");
            (new ReflectionProperty($db, 'DBDebug'))->setValue($db, false);
            $failed = $work->createJob(1, 10, $data, 7);
            $this->assertFalse($failed['success']);
            foreach ($before as $table => $count) {
                $this->assertSame($count, $db->table($table)->countAllResults(), $table);
            }
            $db->query('DROP TRIGGER synthetic_work_audit_failure');
            $retried = $work->createJob(1, 10, $data, 7);
            $this->assertTrue($retried['success'], json_encode($retried));
            $replay = $work->createJob(1, 10, $data, 7);
            $this->assertTrue($replay['success'], json_encode($replay));
            $this->assertTrue($replay['replayed']);
            $this->assertSame(1, $db->table('vehicle_damage_repair_job_events')->countAllResults());
            $this->assertSame(1, (int) (new VehicleDamageRepairRepository($db))->job(1, 10, (int) $retried['id'])['version']);

            // The command must not clear a failed transaction still owned by its caller.
            $data = Fixture::creation($db, [Fixture::condition($db)]);
            $db->query("CREATE TRIGGER synthetic_work_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic audit failure'");
            $db->transBegin();
            $this->assertFalse($work->createJob(1, 10, $data, 7)['success']);
            $this->assertFalse($db->transStatus());
            $this->assertSame(1, $db->transDepth);
            $db->transRollback();
        } finally {
            $fixture->close();
        }
    }

    public static function races(): array
    {
        return [['duplicate_create'], ['cross_vehicle_command'], ['complete'], ['member'], ['confirm_mutation'], ['reopen_mutation'], ['standalone_replay'], ['link_first'], ['attachment_first']];
    }

    #[DataProvider('races')]
    public function testSerializedWinnerAndContenderRevalidateCommittedState(string $race): void
    {
        if (! getenv('B21_MARIADB_CONFIG')) {
            $this->markTestSkipped('Requires disposable B21 MariaDB release gate.');
        }
        $fixture = new VehicleDamageRepairMariaDbFixture();
        try {
            $db = $fixture->db;
            $work = new VehicleDamageRepairService($db);
            $damage = new VehicleDamageService($db);
            $items = new VehicleDamageRepository($db);
            $repairs = new VehicleDamageRepairRepository($db);
            $id = Fixture::condition($db);
            $other = Fixture::condition($db);
            $creation = Fixture::creation($db, [$id]);
            $job = 0;
            $member = 0;
            $command = static fn (array $extra): array => $extra + ['command_key' => VehicleDamageRepairService::commandKey()];
            $fingerprint = static fn (): string => $repairs->conditionFingerprint($items->item(1, 10, $id));
            if (! in_array($race, ['duplicate_create', 'cross_vehicle_command', 'standalone_replay', 'link_first', 'attachment_first'], true)) {
                $created = $work->createJob(1, 10, $creation, 7);
                $this->assertTrue($created['success'], json_encode($created));
                $job = (int) $created['id'];
                $member = (int) $repairs->members(1, 10, $job)[0]['id'];
                $this->assertTrue($work->start(1, 10, $job, $command(['expected_version' => 1, 'started_at' => '2026-10-01T09:00']), 7)['success']);
            }
            if (in_array($race, ['reopen_mutation', 'standalone_replay'], true)) {
                $this->assertTrue($damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic legacy repair', 7)['success']);
            }
            $crossVehicleCreation = null;
            if ($race === 'cross_vehicle_command') {
                $crossVehicleCreation = Fixture::creation($db, [Fixture::condition($db, 1, 11)]);
                $crossVehicleCreation['command_key'] = $creation['command_key'];
            }
            $db->transBegin();
            $replay = false;
            if (in_array($race, ['duplicate_create', 'cross_vehicle_command', 'attachment_first'], true)) {
                $winner = $work->createJob(1, 10, $creation, 7);
                if ($race === 'duplicate_create') {
                    $operation = 'createJob';
                    $arguments = [1, 10, $creation, 7];
                    $replay = true;
                } elseif ($race === 'cross_vehicle_command') {
                    $operation = 'createJob';
                    $arguments = [1, 11, $crossVehicleCreation, 7];
                } else {
                    $operation = 'link';
                    $arguments = [1, 10, $id, $other, ['confirmed' => '1', 'reason' => 'Synthetic reconciliation', 'source_state' => VehicleDamageIncidentService::fingerprint($items->item(1, 10, $id)), 'target_state' => VehicleDamageIncidentService::fingerprint($items->item(1, 10, $other))], 7];
                }
            } elseif ($race === 'link_first') {
                $winner = (new VehicleDamageIncidentService($db))->linkHistorical(1, 10, $id, $other, ['confirmed' => '1', 'reason' => 'Synthetic reconciliation', 'source_state' => VehicleDamageIncidentService::fingerprint($items->item(1, 10, $id)), 'target_state' => VehicleDamageIncidentService::fingerprint($items->item(1, 10, $other))], 7);
                $operation = 'createJob';
                $arguments = [1, 10, $creation, 7];
            } elseif ($race === 'complete') {
                $payload = $command(['expected_version' => 2, 'completion_time_unknown' => 1, 'completion_note' => 'Synthetic completion', 'outcomes' => [['membership_id' => $member, 'expected_condition_state' => $fingerprint(), 'result_code' => 'repair_reported', 'note' => 'Synthetic report']]]);
                $winner = $work->complete(1, 10, $job, $payload, 7);
                $payload['command_key'] = VehicleDamageRepairService::commandKey();
                $operation = 'complete';
                $arguments = [1, 10, $job, $payload, 7];
            } elseif ($race === 'member') {
                $payload = $command(['expected_version' => 2, 'membership_id' => $member, 'expected_condition_state' => $fingerprint(), 'result_code' => 'unchanged', 'note' => 'Synthetic assessment']);
                $winner = $work->recordMembershipResult(1, 10, $job, $payload, 7);
                $payload['command_key'] = VehicleDamageRepairService::commandKey();
                $payload['result_code'] = 'failed';
                $operation = 'recordMembershipResult';
                $arguments = [1, 10, $job, $payload, 7];
            } elseif ($race === 'confirm_mutation') {
                $payload = $command(['expected_version' => 2, 'membership_id' => $member, 'expected_condition_state' => $fingerprint(), 'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic inspection']);
                $winner = $damage->worsen(1, 10, $id, ['severity_code' => 'moderate', 'note' => 'Synthetic concurrent worsening', 'occurred_at' => '2026-10-01 10:00:00'], 7);
                $operation = 'confirmConditionRepaired';
                $arguments = [1, 10, $job, $payload, 7];
            } else {
                $payload = $command(['expected_condition_state' => $fingerprint(), 'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic repair failure']);
                $winner = $damage->reopenRepairedCondition(1, 10, $id, $payload, 7);
                $operation = 'reopenCondition';
                $arguments = [1, 10, $id, $payload, 7];
                $replay = $race === 'standalone_replay';
                if (! $replay) {
                    $arguments[3]['command_key'] = VehicleDamageRepairService::commandKey();
                }
            }
            $this->assertTrue($winner['success'], json_encode($winner));
            $counts = [];
            foreach (['vehicle_damage_repair_job_events', 'vehicle_damage_item_events', 'audit_logs'] as $table) {
                $counts[$table] = $db->table($table)->countAllResults();
            }
            $contender = $fixture->contend($operation, $arguments);
            $this->assertTrue($contender['blocked'], 'The independent connection must encounter a real InnoDB lock timeout.');
            $this->assertSame($replay, $contender['result']['success'], json_encode($contender));
            if ($replay) {
                $this->assertTrue($contender['result']['replayed']);
            }
            foreach ($counts as $table => $count) {
                $this->assertSame($count, $db->table($table)->countAllResults(), $table);
            }
            $duplicates = $db->query('SELECT vehicle_damage_repair_job_id, job_version, COUNT(*) AS n FROM vehicle_damage_repair_job_events GROUP BY vehicle_damage_repair_job_id, job_version HAVING COUNT(*) > 1')->getResultArray();
            $this->assertSame([], $duplicates);
            $this->assertSame($race === 'link_first' ? $other : null, ($items->item(1, 10, $id)['current_condition_item_id'] === null ? null : (int) $items->item(1, 10, $id)['current_condition_item_id']));
            if ($race === 'link_first') {
                $this->assertFalse($repairs->hasAnyMembershipForCondition(1, 10, $id));
            }
            if ($race === 'attachment_first') {
                $this->assertTrue($repairs->hasAnyMembershipForCondition(1, 10, $id));
            }
        } finally {
            $fixture->close();
        }
    }
}
