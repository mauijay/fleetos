<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageFinancialReconciliationFixture as Pair;
use Tests\Support\VehicleDamageRepairMariaDbFixture as Maria;

/** @internal Additive migration against populated v0.31.0, varied defaults and prefixed SQLite. */
final class VehicleDamageFinancialReconciliationMigrationTest extends CIUnitTestCase
{
    public static function databases(): array
    {
        return [['sqlite', '', true, ''], ['sqlite', 'synthetic_', false, ''], ['maria', '', true, 'utf8mb4_general_ci'], ['maria', 'b32_', true, 'latin1_swedish_ci'], ['maria', '', false, 'utf8mb4_general_ci'], ['maria', '', true, 'utf8_general_ci']];
    }

    #[DataProvider('databases')]
    public function testEmptyAdditiveSchemaAndRetainedSources(string $engine, string $prefix, bool $upgrade, string $collation): void
    {
        $maria = $engine === 'maria' ? new Maria(false, $prefix, $prefix !== '' ? 29 : ($upgrade ? 34 : 35), $collation) : null;
        $config = (new Database())->tests;
        $config['DBPrefix'] = $prefix;
        $db = $maria === null ? Database::connect($config, false) : $maria->db;
        $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
        $paths = [];
        try {
            if ($maria === null) {
                foreach (glob(dirname(__DIR__, 2) . '/app/Database/Migrations/*.php') as $path) {
                    if ($upgrade && str_contains($path, '000035')) {
                        continue;
                    }
                    $runner->force($path, 'App');
                }
                VehicleDamageDatabaseFixture::seed($db);
            }
            if ($maria !== null && $prefix !== '') {
                foreach (glob(dirname(__DIR__, 2) . '/app/Database/Migrations/*.php') as $path) {
                    if (preg_match('/-(00003[0-5])_/', $path, $number) && (int) $number[1] <= ($upgrade ? 34 : 35)) {
                        $runner->force($path, 'App');
                    }
                }
            }
            $dependencies = new MigrationRunner(new Migrations(), $db);
            $dependencies->setNamespace('CodeIgniter\Shield')->latest();
            foreach (['2021-07-04-041948_CreateSettingsTable.php', '2021-11-14-143905_AddContextColumn.php'] as $file) {
                $dependencies->force(dirname(__DIR__, 2) . '/vendor/codeigniter4/settings/src/Database/Migrations/' . $file, 'CodeIgniter\Settings');
            }
            $before = [];
            if ($upgrade) {
                Pair::pair($db, $paths);
                foreach ($db->listTables() as $table) {
                    if (! str_ends_with($table, 'migrations')) {
                        $before[$table] = $db->table($table)->get()->getResultArray();
                    }
                }
                $count = $db->table('migrations')->countAllResults();
                $this->assertSame(43, $count);
                $runner->force(dirname(__DIR__, 2) . '/app/Database/Migrations/2026-10-07-000035_CreateVehicleDamageFinancialReconciliations.php', 'App');
                $this->assertSame($count + 1, $db->table('migrations')->countAllResults());
            }
            $db->resetDataCache();
            $this->assertSame(44, $db->table('migrations')->countAllResults());
            $repo = new Records($db);
            $this->assertTrue($repo->ready());
            $this->assertSame(0, $db->table(Records::TABLE)->countAllResults());
            $this->assertEqualsCanonicalizing(explode(' ', Records::FIELDS), $db->getFieldNames(Records::TABLE));
            $this->assertCount(5, $db->getForeignKeyData(Records::TABLE));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $db->table($table)->get()->getResultArray(), $table);
            }
            $pair = Pair::pair($db, $paths);
            $created = (new \App\Services\Fleet\VehicleDamageRepairService($db))->reconcileRepairCostToExpense(1, 10, $pair['job'], $pair['payload'], 7);
            $this->assertTrue($created['success'], json_encode($created));
            $row = $repo->rows(1, 10, $pair['job'])[0];
            $debug = new \ReflectionProperty($db, 'DBDebug');
            $oldDebug = $debug->getValue($db);
            $debug->setValue($db, false);
            try {
                $this->assertFalse($db->table(Records::TABLE)->where('id', $row['id'])->update(['reason' => 'Synthetic rewritten fact']));
                $this->assertFalse($db->table(Records::TABLE)->where('id', $row['id'])->delete());
                $this->assertFalse($db->table('operating_expenses')->where('id', $pair['expense'])->delete());
                $this->assertFalse($db->table('operating_expenses')->where('id', $pair['expense'])->update(['fleet_vehicle_id' => null]));
                $repo->invalidate($row, 7, 'Synthetic byte-bound verification.', '2026-10-07 10:00:00');
                $oversized = $row;
                unset($oversized['id']);
                $oversized['damage_lineage_snapshot'] = json_encode(['synthetic' => str_repeat('é', 32769)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertGreaterThan(65536, strlen($oversized['damage_lineage_snapshot']));
                $this->assertFalse($db->table(Records::TABLE)->insert($oversized), 'The 64 KiB bound applies to bytes, including unescaped multibyte JSON.');
                $oversized['financial_source_snapshot'] = $oversized['damage_lineage_snapshot'];
                $oversized['damage_lineage_snapshot'] = $row['damage_lineage_snapshot'];
                $this->assertFalse($db->table(Records::TABLE)->insert($oversized), 'The financial snapshot has the same byte bound.');
                $oversized['financial_source_snapshot'] = $row['financial_source_snapshot'];
                $this->assertTrue($db->table(Records::TABLE)->insert($oversized), 'The same source/context remains valid with its original bounded snapshot.');
            } finally {
                $debug->setValue($db, $oldDebug);
            }
            $migration = new \App\Database\Migrations\CreateVehicleDamageFinancialReconciliations(Database::forge($db));
            try {
                $migration->down();
                $this->fail('Destructive rollback must refuse.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('forward-only', $exception->getMessage());
            }
            if ($db->getPlatform() !== 'SQLite3') {
                $table = $db->escapeIdentifiers($db->prefixTable(Records::TABLE));
                $events = $db->escapeIdentifiers($db->prefixTable('vehicle_damage_repair_job_events'));
                foreach ([
                    ["ALTER TABLE {$table} DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci", "ALTER TABLE {$table} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"],
                    ["ALTER TABLE {$table} MODIFY reason TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL", "ALTER TABLE {$table} MODIFY reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL"],
                    ["ALTER TABLE {$events} MODIFY event_code VARCHAR(40) NOT NULL", "ALTER TABLE {$events} MODIFY event_code VARCHAR(64) NOT NULL"],
                ] as [$damage, $restore]) {
                    $this->assertNotFalse($db->query($damage));
                    $db->resetDataCache();
                    $this->assertFalse($repo->ready(), 'Wrong table/column encoding or insufficient event capacity must fail closed.');
                    $this->assertNotFalse($db->query($restore));
                    $db->resetDataCache();
                    $this->assertTrue($repo->ready());
                }
            }
            $db->query('DROP TRIGGER ' . $db->escapeIdentifiers($db->prefixTable('b32_no_delete')));
            $db->resetDataCache();
            $this->assertTrue($repo->present());
            $this->assertFalse($repo->ready());
        } finally {
            $maria === null ? $db->close() : $maria->close();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
