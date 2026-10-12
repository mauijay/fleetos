<?php

namespace Tests\Support;

use App\Services\Fleet\VehicleDamageIncidentService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Config\Migrations;

/** Only in-memory SQLite or the established explicitly guarded disposable MariaDB. */
final class VehicleDamageOccurrenceFixture
{
    public const MIGRATION = '2026-10-11-000036_AddVehicleDamageOccurrenceProvenance.php';
    public BaseConnection $db;
    private ?VehicleDamageRepairMariaDbFixture $maria;

    public function __construct(string $engine = 'sqlite', string $prefix = '', string $collation = 'utf8mb4_general_ci', bool $seed = true)
    {
        $this->maria = $engine === 'maria' ? new VehicleDamageRepairMariaDbFixture(false, $prefix, 35, $collation, $seed) : null;
        if ($this->maria !== null) {
            $this->db = $this->maria->db;
        } else {
            $configuration = (new Database())->tests;
            $configuration['DBPrefix'] = $prefix;
            $this->db = Database::connect($configuration, false);
            $runner = (new MigrationRunner(new Migrations(), $this->db))->setNamespace('App');
            foreach (glob(dirname(__DIR__, 2) . '/app/Database/Migrations/*.php') as $path) {
                if (preg_match('/-(\d+)_/', basename($path), $number) && (int) $number[1] <= 35) {
                    $runner->force($path, 'App');
                }
            }
            if ($seed) {
                VehicleDamageDatabaseFixture::seed($this->db);
            }
        }
        $dependencies = new MigrationRunner(new Migrations(), $this->db);
        $dependencies->setNamespace('CodeIgniter\Shield')->latest();
        foreach (['2021-07-04-041948_CreateSettingsTable.php', '2021-11-14-143905_AddContextColumn.php'] as $file) {
            $dependencies->force(dirname(__DIR__, 2) . '/vendor/codeigniter4/settings/src/Database/Migrations/' . $file, 'CodeIgniter\Settings');
        }
    }

    public function migrate(): void
    {
        (new MigrationRunner(new Migrations(), $this->db))->setNamespace('App')->force(dirname(__DIR__, 2) . '/app/Database/Migrations/' . self::MIGRATION, 'App');
    }

    public static function incident(array $changes = []): array
    {
        return array_replace(['discovered_at' => '2026-10-01 10:00:00', 'occurred_at' => null, 'attribution_type' => 'unknown', 'areas' => [self::area()]], $changes);
    }

    public static function area(array $changes = []): array
    {
        return array_replace(['panel_code' => 'front_left_wheel_tire', 'damage_type_code' => 'wheel_curb_rash', 'severity_code' => 'cosmetic', 'effect_code' => 'new_damage', 'note' => "Synthetic scuff at 2 o'clock."], $changes);
    }

    public function create(array $changes = []): int
    {
        $result = (new VehicleDamageIncidentService($this->db))->create(1, 10, self::incident($changes), 7);
        if (! $result['success']) {
            throw new \RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
        }
        return (int) $result['id'];
    }

    /** Stable values of all business rows, excluding only framework migration history. */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->db->listTables() as $table) {
            if ($table !== $this->db->prefixTable('migrations')) {
                $rows[$table] = $this->db->table($table)->get()->getResultArray();
            }
        }
        ksort($rows);
        return $rows;
    }

    public function close(): void
    {
        $this->maria === null ? $this->db->close() : $this->maria->close();
    }
}
