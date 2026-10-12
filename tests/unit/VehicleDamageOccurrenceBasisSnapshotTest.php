<?php

namespace Tests\Unit;

use App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot as Snapshot;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal Exact versioned JSON contract, including UTF-8 byte limits. */
final class VehicleDamageOccurrenceBasisSnapshotTest extends CIUnitTestCase
{
    public static function empty(): array
    {
        return ['schema_version' => 1, 'snapshot_recorded_at' => '2026-10-11 12:00:00', 'panel_code' => 'front_left_wheel_tire', 'damage_type_code' => 'wheel_curb_rash', 'prior_condition' => null, 'prior_occurrence' => null, 'comparison' => ['trip_id' => null, 'boundary_kind' => 'unknown', 'boundary_at' => null, 'baseline' => null]];
    }

    public function testNullFutureMalformedAndAdditionalKeysReadWithoutReconstruction(): void
    {
        $this->assertSame('not_recorded', Snapshot::read(null)['state']);
        $this->assertSame('unsupported', Snapshot::read('{"schema_version":2}')['state']);
        $this->assertSame('invalid', Snapshot::read('{')['state']);
        $this->assertSame('invalid', Snapshot::read('[]')['state']);
        $snapshot = self::empty() + ['future_context' => ['text' => '輪毂 — café 🌺']];
        $json = Snapshot::encode($snapshot);
        $this->assertSame($snapshot, Snapshot::read($json)['snapshot']);
        $this->assertStringContainsString('輪毂', $json);
        $this->assertSame(Snapshot::UNAVAILABLE, Snapshot::read(null)['message']);
    }

    public function testExactEncodedByteBoundaryNeverTruncates(): void
    {
        $snapshot = self::empty() + ['extension' => ''];
        $overhead = strlen(Snapshot::encode($snapshot));
        $snapshot['extension'] = str_repeat('x', Snapshot::MAX_BYTES - $overhead);
        $this->assertSame(32768, strlen(Snapshot::encode($snapshot)));
        $snapshot['extension'] .= 'x';
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::encode($snapshot);
    }

    public static function invalid(): array
    {
        $empty = self::empty();
        $prior = ['canonical_condition_item_id' => 1, 'latest_condition_event_id' => 2, 'status_code' => 'open', 'severity_code' => 'cosmetic', 'description' => 'Synthetic recorded damage', 'discovered_at' => '2026-10-01 12:00:00'];
        $occurrence = ['incident_item_id' => 1, 'condition_event_id' => 2, 'effect_code' => 'new_damage', 'note' => null, 'occurred_at' => null, 'discovered_at' => '2026-10-01 12:00:00', 'recorded_at' => '2026-10-01 12:01:00'];
        $baseline = ['kind' => 'observed_existing', 'canonical_condition_item_id' => 1, 'condition_event_id' => 2, 'incident_item_id' => 3, 'observed_at' => '2026-10-01 12:00:00', 'recorded_at' => '2026-10-01 12:01:00', 'evidence_ids' => []];
        $cases = [];
        foreach ([['schema_version', 2], ['panel_code', 'LF'], ['damage_type_code', 'curb_rash'], ['snapshot_recorded_at', '2026-02-30 12:00:00'], ['snapshot_recorded_at', '2026-10-11T12:00:00Z'], ['comparison', []]] as [$key, $value]) {
            $cases[] = [array_replace($empty, [$key => $value])];
        }
        foreach ([['canonical_condition_item_id', 0], ['latest_condition_event_id', -1], ['canonical_condition_item_id', '1'], ['status_code', 'clean'], ['severity_code', 'light'], ['description', str_repeat('界', 2001)]] as [$key, $value]) {
            $cases[] = [array_replace($empty, ['prior_condition' => array_replace($prior, [$key => $value])])];
        }
        foreach ([['effect_code', 'historical_linked'], ['note', str_repeat('x', 2001)], ['occurred_at', '2026-10-02 12:00:00'], ['recorded_at', '2026-10-12 12:00:00']] as [$key, $value]) {
            $cases[] = [array_replace($empty, ['prior_occurrence' => array_replace($occurrence, [$key => $value])])];
        }
        foreach ([['kind', 'clean'], ['evidence_ids', range(1, 25)], ['evidence_ids', [1, 1]], ['evidence_ids', [-1]], ['evidence_ids', ['1']], ['observed_at', null], ['recorded_at', '2026-10-12 12:00:00']] as [$key, $value]) {
            $comparison = $empty['comparison'];
            $comparison['baseline'] = array_replace($baseline, [$key => $value]);
            $cases[] = [array_replace($empty, ['comparison' => $comparison])];
        }
        $missing = $empty;
        unset($missing['prior_condition']);
        $cases[] = [$missing];
        return $cases;
    }

    #[DataProvider('invalid')]
    public function testMalformedWriteContractIsRejected(array $snapshot): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::encode($snapshot);
    }

    public function testCharacterLimitsAreDifferentFromEncodedByteLimits(): void
    {
        $snapshot = self::empty();
        $snapshot['prior_condition'] = ['canonical_condition_item_id' => 1, 'latest_condition_event_id' => 2, 'status_code' => 'open', 'severity_code' => 'cosmetic', 'description' => str_repeat('🌺', 2000), 'discovered_at' => '2026-10-01 12:00:00'];
        $this->assertSame($snapshot, Snapshot::read(Snapshot::encode($snapshot))['snapshot']);
    }
}
