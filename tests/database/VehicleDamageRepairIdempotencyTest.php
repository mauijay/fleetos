<?php

use App\Services\Fleet\VehicleDamageRepairService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairIdempotencyTest extends VehicleDamageRepairTestCase
{
    public function testPersistentCreationReplayNormalizesCaseWhitespaceIdsOrderingAndCsrf(): void
    {
        $data = Fixture::creation($this->connection, [$this->condition(), $this->condition()]);
        $result = $this->work->createJob(1, 10, $data, 7);
        $this->success($result);
        $before = $this->counts();
        $data['command_key'] = strtoupper($data['command_key']);
        $data['summary'] = '  ' . $data['summary'] . '  ';
        $data['conditions'] = array_reverse($data['conditions']);
        foreach ($data['conditions'] as &$selection) {
            $selection['selected_item_id'] = (string) $selection['selected_item_id'];
        }
        unset($selection);
        $data['csrf_test_name'] = 'synthetic-token';
        $retry = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, $data, 7);
        $this->success($retry);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($result['id'], $retry['id']);
        $this->assertSame($before, $this->counts());
        $data['summary'] = 'Different work';
        $this->failure($this->work->createJob(1, 10, $data, 7), 'different payload');
        $this->assertSame($before, $this->counts());
    }

    public function testFailureLeavesKeyReusableAndLifecycleReplayPrecedesStaleVersionCheck(): void
    {
        $data = Fixture::creation($this->connection, [$this->condition()]);
        $valid = $data;
        $data['summary'] = '';
        $this->failure($this->work->createJob(1, 10, $data, 7));
        $result = $this->work->createJob(1, 10, $valid, 7);
        $this->success($result);
        $job = (int) $result['id'];
        $command = $this->command($job, ['started_at' => '2026-10-01T09:00']);
        $this->success($this->work->start(1, 10, $job, $command, 7));
        $before = $this->counts();
        $retry = $this->work->start(1, 10, $job, $command, 7);
        $this->success($retry);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($before, $this->counts());
        $this->assertSame(2, (int) $this->repairs->job(1, 10, $job)['version']);
    }
}
