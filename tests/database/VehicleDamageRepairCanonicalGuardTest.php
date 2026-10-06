<?php

use App\Services\Fleet\VehicleDamageIncidentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairCanonicalGuardTest extends VehicleDamageRepairTestCase
{
    public static function states(): array
    {
        return [['active'], ['withdrawn'], ['cancelled'], ['completed']];
    }

    #[DataProvider('states')]
    public function testAnySourceMembershipPermanentlyBlocksHistoricalAliasing(string $state): void
    {
        $source = $this->condition();
        $target = $this->condition();
        $job = $this->createWork([$source, $target], $state === 'completed' ? ['intent_code' => 'mitigation', 'recording_mode' => 'historical_mitigation', 'performed_confirmed' => 1, 'recording_reason' => 'Synthetic backfill', 'completion_note' => 'Synthetic protection'] : []);
        if ($state === 'withdrawn') {
            $this->success($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $this->repairs->members(1, 10, $job)[0]['id'], 'reason' => 'Synthetic withdrawal']), 7));
        }
        if ($state === 'cancelled') {
            $this->success($this->work->cancel(1, 10, $job, $this->command($job, ['reason' => 'Synthetic cancellation']), 7));
        }
        $before = $this->counts();
        $result = (new VehicleDamageIncidentService($this->connection))->linkHistorical(1, 10, $source, $target, $this->link($source, $target), 7);
        $this->failure($result, 'work history');
        $this->assertSame($before, $this->counts());
        $this->assertNull($this->conditions->item(1, 10, $source)['current_condition_item_id']);
    }

    public function testUntouchedSourceMayLinkToTargetWithWorkAndAliasNeedsExplicitPreview(): void
    {
        $source = $this->condition();
        $target = $this->condition();
        $job = $this->createWork([$target]);
        $this->success((new VehicleDamageIncidentService($this->connection))->linkHistorical(1, 10, $source, $target, $this->link($source, $target), 7));
        $preview = $this->work->conditionPreview(1, 10, $source);
        $this->assertSame($target, (int) $preview['canonical']['id']);
        $selection = ['selected_item_id' => $source, 'canonical_item_id' => $target, 'expected_condition_state' => $preview['expected_condition_state']];
        $data = \Tests\Support\VehicleDamageRepairDatabaseFixture::creation($this->connection, [$target]);
        $data['conditions'] = [$selection];
        $this->failure($this->work->createJob(1, 10, $data, 7), 'confirmation');
        $data['conditions'][0]['canonical_confirmed'] = 1;
        $result = $this->work->createJob(1, 10, $data, 7);
        $this->success($result);
        $this->assertSame($target, (int) $this->repairs->members(1, 10, (int) $result['id'])[0]['vehicle_damage_item_id']);
        $this->assertSame($target, (int) $this->repairs->members(1, 10, $job)[0]['vehicle_damage_item_id']);
    }

    private function link(int $source, int $target): array
    {
        return ['confirmed' => '1', 'reason' => 'Synthetic same physical condition', 'source_state' => VehicleDamageIncidentService::fingerprint($this->conditions->item(1, 10, $source)), 'target_state' => VehicleDamageIncidentService::fingerprint($this->conditions->item(1, 10, $target))];
    }
}
