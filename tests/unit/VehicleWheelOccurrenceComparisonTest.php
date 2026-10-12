<?php

namespace Tests\Unit;

use App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot as Basis;
use App\Services\Fleet\VehicleWheelOccurrenceComparison as Comparison;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class VehicleWheelOccurrenceComparisonTest extends CIUnitTestCase
{
    public static function snapshot(): array
    {
        return ['schema_version' => 1,'snapshot_recorded_at' => '2026-10-01 10:00:00','panel_code' => 'front_left_wheel_tire','damage_type_code' => 'wheel_curb_rash',
            'prior_condition' => ['canonical_condition_item_id' => 1,'latest_condition_event_id' => 2,'status_code' => 'open','severity_code' => 'cosmetic','description' => 'Frozen original description','discovered_at' => '2026-09-01 10:00:00'],
            'prior_occurrence' => ['incident_item_id' => 4,'condition_event_id' => 2,'effect_code' => 'observed_existing','note' => 'Frozen prior observation at 8 o\'clock.','occurred_at' => null,'discovered_at' => '2026-09-20 10:00:00','recorded_at' => '2026-09-20 10:00:00'],
            'comparison' => ['trip_id' => 100,'boundary_kind' => 'actual_handoff','boundary_at' => '2026-09-25 08:00:00','baseline' => ['kind' => 'observed_existing','canonical_condition_item_id' => 1,'condition_event_id' => 2,'incident_item_id' => 4,'observed_at' => '2026-09-20 10:00:00','recorded_at' => '2026-09-20 10:00:00','evidence_ids' => [5,6]]]];
    }

    public static function cases(): iterable
    {
        foreach (['actual','scheduled','recorded','none','late_recorded','wrong_trip','deleted_trip','wrong_identity','different_baseline','null','future','invalid','observed','attribution'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testFrozenComparisonClaims(string $case): void
    {
        $snapshot = self::snapshot();
        $member = ['panel_code' => $snapshot['panel_code'],'damage_type_code' => 'wheel_curb_rash','trip_id' => 100,'owned_trip_id' => 100,'effect_code' => 'worsened','note' => 'New delta at 2 o\'clock.','attribution_type' => 'discovered_during_trip'];
        $expected = 'Observed before this guest';
        switch ($case) {
            case 'scheduled':$snapshot['comparison']['boundary_kind'] = 'scheduled_start';
                $expected = 'Recorded before scheduled pickup';
                break;
            case 'recorded':$snapshot['comparison']['baseline']['kind'] = 'recorded_condition';
                $snapshot['comparison']['baseline']['observed_at'] = null;
                $expected = 'Last recorded condition';
                break;
            case 'none':$snapshot['comparison']['baseline'] = null;
                $expected = 'No documented pre-trip baseline';
                break;
            case 'late_recorded':$snapshot['comparison']['baseline']['recorded_at'] = '2026-09-26 10:00:00';
                $expected = 'Last recorded condition';
                break;
            case 'wrong_trip':$member['trip_id'] = 102;
                $member['owned_trip_id'] = 102;
                $expected = 'Last recorded condition';
                break;
            case 'deleted_trip':$member['owned_trip_id'] = null;
                $expected = 'Last recorded condition';
                break;
            case 'wrong_identity':$member['panel_code'] = 'front_right_wheel_tire';
                $expected = 'Last recorded condition';
                break;
            case 'different_baseline':$snapshot['comparison']['baseline']['canonical_condition_item_id'] = 99;
                break;
            case 'observed':$member['effect_code'] = 'observed_existing';
                break;
            case 'attribution':$member['attribution_type'] = 'operator_attributed_cause';
                break;
        }
        $member['basis_snapshot'] = match($case) {
            'null' => null,'future' => json_encode(array_replace($snapshot, ['schema_version' => 2])),'invalid' => '{bad',default => Basis::encode($snapshot)
        };
        if (in_array($case, ['null','future','invalid'], true)) {
            $expected = 'Comparison basis not recorded';
        }
        $before = $member;
        $view = Comparison::forOccurrence($member);
        self::assertSame($before, $member, 'Rendering cannot rewrite a frozen snapshot.');
        self::assertSame($expected, $view['heading']);
        self::assertSame($member['note'], $view['after']);
        self::assertSame(\Config\VehicleDamage::ATTRIBUTIONS[$member['attribution_type']], $view['attribution']);
        if (in_array($case, ['different_baseline','none'], true)) {
            self::assertNull($view['before']);
        } elseif ($view['snapshot'] !== null) {
            self::assertSame($snapshot['prior_occurrence']['note'], $view['before']);
        }
        if ($case === 'observed') {
            self::assertSame('Observed existing — no physical change recorded', $view['after_heading']);
        } else {
            self::assertSame('Worsening recorded', $view['after_heading']);
        }
        self::assertStringNotContainsString('during this trip', $view['after_heading']);
    }
}
