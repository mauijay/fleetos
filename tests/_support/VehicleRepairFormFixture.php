<?php

namespace Tests\Support;

use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Synthetic repair selection controls on the real schema, including 000036. */
final class VehicleRepairFormFixture
{
    public const CASES = ['empty', 'body', 'wheel', 'mixed', 'historical', 'conflict', 'unknown', 'generic'];

    public static function seed(BaseConnection $db, string $case): void
    {
        if (! in_array($case, self::CASES, true)) {
            throw new InvalidArgumentException('Unknown synthetic repair form case.');
        }
        $damage = new VehicleDamageService($db);
        $generic = static function (array $changes = []) use ($damage): int {
            $result = $damage->create(1, 10, array_replace([
                'zone_code' => 'front', 'damage_type_code' => 'scratch_scuff',
                'description' => 'Synthetic current body condition', 'severity_code' => 'cosmetic',
                'discovered_at' => '2026-09-25 10:00:00',
            ], $changes), 7);
            VehicleWheelRepairFixture::requireSuccess($result);
            return (int) $result['id'];
        };
        if (in_array($case, ['body', 'mixed', 'conflict'], true)) {
            $generic();
        }
        if (in_array($case, ['wheel', 'mixed'], true)) {
            $wheel = new VehicleWheelRepairFixture($db);
            foreach (['LF', 'RF', 'RR', 'LR'] as $code) {
                $wheel->create($code);
            }
        }
        if (in_array($case, ['historical', 'mixed'], true)) {
            foreach (['repaired', 'resolved_other'] as $status) {
                $id = $generic(['description' => 'Synthetic historical ' . $status . ' condition']);
                VehicleWheelRepairFixture::requireSuccess($damage->transitionStatus(1, 10, $id, $status, 'Synthetic historical resolution.', 7));
            }
        }
        if ($case === 'conflict') {
            $receipt = (new VehicleWheelRepairFixture($db))->create('LF');
            $row = $db->table('vehicle_damage_items')->where('id', $receipt['condition_id'])->get()->getRowArray();
            unset($row['id']);
            $db->table('vehicle_damage_items')->insert($row);
        }
        if ($case === 'unknown') {
            $generic(['zone_code' => 'wheel_tire', 'damage_type_code' => 'wheel_curb_rash', 'description' => 'Synthetic wheel position unknown']);
        }
        if ($case === 'generic') {
            $generic(['zone_code' => 'roof_glass', 'damage_type_code' => 'glass', 'description' => 'Synthetic current glass condition']);
            $generic(['zone_code' => 'wheel_tire', 'panel_code' => 'front_left_wheel_tire', 'damage_type_code' => 'tire', 'description' => 'Synthetic current tire condition']);
            $generic(['zone_code' => 'wheel_tire', 'panel_code' => 'rear_right_wheel_tire', 'description' => 'Synthetic non-curb wheel scratch']);
        }
    }

    public static function rows(BaseConnection $db): array
    {
        $rows = [];
        foreach ($db->listTables() as $table) {
            $rows[$table] = $db->table($table)->get()->getResultArray();
        }
        ksort($rows);
        return $rows;
    }
}
