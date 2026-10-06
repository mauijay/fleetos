<?php

namespace Tests\Support;

use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Synthetic operational data on the real migrations. */
final class VehicleDamageRepairDatabaseFixture
{
    public static function prepare(BaseConnection $db): void
    {
        VehicleDamageDatabaseFixture::migrate($db);
        VehicleDamageDatabaseFixture::seed($db);
    }

    public static function condition(BaseConnection $db, int $company = 1, int $vehicle = 10): int
    {
        $result = (new VehicleDamageService($db))->create($company, $vehicle, [
            'zone_code' => 'front', 'damage_type_code' => 'scratch_scuff',
            'description' => 'Synthetic panel scratch', 'severity_code' => 'cosmetic',
            'discovered_at' => '2026-09-25 10:00:00',
        ], 7);
        if (! $result['success']) {
            throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
        }
        return (int) $result['id'];
    }

    public static function selection(BaseConnection $db, int $condition): array
    {
        $row = $db->table('vehicle_damage_items')->where('id', $condition)->get()->getRowArray();
        return ['selected_item_id' => $condition, 'canonical_item_id' => $condition,
            'canonical_confirmed' => 1, 'expected_condition_state' => (new VehicleDamageRepairRepository($db))->conditionFingerprint($row)];
    }

    public static function creation(BaseConnection $db, array $conditions): array
    {
        return ['command_key' => VehicleDamageRepairService::commandKey(), 'intent_code' => 'repair',
            'category_code' => 'body', 'summary' => 'Synthetic panel work',
            'conditions' => array_map(static fn (int $id): array => self::selection($db, $id), $conditions)];
    }
}
