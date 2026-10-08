<?php

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use Config\VehicleDamageRepairRecoveries as Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Exact v0.30.2 App manifest plus real Shield/Settings migrations: ledger 42 -> 43. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairRecoveriesMigrationTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return [
            ['sqlite', false, '', 'utf8mb4_general_ci'], ['sqlite', true, '', 'utf8mb4_general_ci'], ['sqlite', true, 'b31_', 'utf8mb4_general_ci'],
            ['mariadb', false, '', 'utf8mb4_general_ci'], ['mariadb', true, '', 'latin1_swedish_ci'], ['mariadb', true, 'b31_', 'utf8mb4_general_ci'],
            ['mariadb', false, '', 'latin1_swedish_ci'],
        ];
    }

    #[DataProvider('engines')]
    public function testDeterministicSchemaAndExactUpgradePreserveExistingRows(string $engine, bool $upgrade, string $prefix, string $collation): void
    {
        $fixture = null;
        if ($engine === 'mariadb') {
            $fixture = new VehicleDamageRepairMariaDbFixture(false, $prefix, $prefix !== '' ? 31 : ($upgrade ? 33 : 34), $collation);
            $db = $fixture->db;
            if ($prefix !== '') {
                $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
                $runner->force(__DIR__ . '/../../app/Database/Migrations/2026-10-06-000032_CreateVehicleDamageRepairCosts.php', 'App');
                $runner->force(__DIR__ . '/../../app/Database/Migrations/2026-10-07-000033_EnsureVehicleDamageRepairCostCharset.php', 'App');
            }
        } else {
            $configuration = (new Database())->tests;
            $configuration['DBPrefix'] = $prefix;
            $db = Database::connect($configuration, false);
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
                if (! $upgrade || strcmp(basename($path), '2026-10-07-000034') < 0) {
                    $runner->force($path, 'App');
                }
            }
            VehicleDamageDatabaseFixture::seed($db);
        }
        try {
            $dependencies = new MigrationRunner(new Migrations(), $db);
            $dependencies->setNamespace('CodeIgniter\Shield')->latest();
            // Accepted v0.30.2 has these two Settings rows, not the vendor's later SQL Server-only migration.
            foreach (['2021-07-04-041948_CreateSettingsTable.php', '2021-11-14-143905_AddContextColumn.php'] as $file) {
                $dependencies->force(__DIR__ . '/../../vendor/codeigniter4/settings/src/Database/Migrations/' . $file, 'CodeIgniter\Settings');
            }
            $work = new Work($db);
            $created = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
            $this->assertTrue($created['success'], json_encode($created));
            $before = [];
            foreach ($db->listTables() as $table) {
                if (! str_ends_with($table, 'migrations')) {
                    $before[$table] = $db->table($table)->get()->getResultArray();
                }
            }
            if ($upgrade) {
                $this->assertSame(42, $db->table('migrations')->countAllResults());
            }
            $this->assertTrue((new MigrationRunner(new Migrations(), $db))->setNamespace('App')->latest());
            $this->assertSame(43, $db->table('migrations')->countAllResults());
            $db->resetDataCache();
            $repo = new Recoveries($db);
            $this->assertTrue($repo->ready());
            $this->assertEqualsCanonicalizing(explode(' ', Recoveries::FIELDS), $db->getFieldNames(Recoveries::TABLE));
            $this->assertNotContains('updated_at', $db->getFieldNames(Recoveries::TABLE));
            $this->assertSame(0, $db->table(Recoveries::TABLE)->countAllResults());
            foreach ($before as $table => $rows) {
                $after = $db->table($table)->get()->getResultArray();
                if ($upgrade && str_ends_with($table, 'vehicle_damage_repair_jobs')) {
                    foreach ($after as &$row) {
                        foreach (['recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'] as $field) {
                            $this->assertNull($row[$field]);
                            unset($row[$field]);
                        }
                    }
                    unset($row);
                }
                $this->assertSame($rows, $after, $table);
            }
            $table = $db->escapeIdentifiers($db->prefixTable(Recoveries::TABLE));
            $ddl = $engine === 'sqlite' ? $db->query('SELECT sql FROM sqlite_master WHERE name=?', [$db->prefixTable(Recoveries::TABLE)])->getRowArray()['sql'] : array_values($db->query('SHOW CREATE TABLE ' . $table)->getRowArray())[1];
            foreach (Recoveries::CHECKS as $check) {
                $this->assertStringContainsString($check, $ddl);
            }
            if ($engine === 'mariadb') {
                $this->assertStringContainsString('COLLATE=utf8mb4_general_ci', $ddl);
                $columns = $db->query('SELECT COLUMN_NAME,COLUMN_TYPE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->prefixTable(Recoveries::TABLE)])->getResultArray();
                $columns = array_column($columns, null, 'COLUMN_NAME');
                $this->assertSame('decimal(12,2)', $columns['amount']['COLUMN_TYPE']);
                foreach ($columns as $name => $column) {
                    if ($column['COLLATION_NAME'] !== null) {
                        $this->assertSame($name === 'source_snapshot' ? 'utf8mb4_bin' : Policy::COLLATION, $column['COLLATION_NAME']);
                    }
                }
            }
            $db->table('vehicle_damage_repair_documents')->insert(['company_id' => 1, 'vehicle_damage_repair_job_id' => $created['id'], 'kind_code' => 'recovery_payment', 'external_reference' => 'Synthetic constraint source only', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-07 09:00:00', 'updated_at' => '2026-10-07 09:00:00']);
            $values = ['company_id' => 1, 'vehicle_damage_repair_job_id' => $created['id'], 'kind_code' => 'recovery', 'authority_code' => 'external_receipt', 'source_type' => 'guest_direct', 'amount' => '12.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06',
                'payer_snapshot' => 'Synthetic DDL Payer', 'source_namespace' => 'bank_transfer:synthetic', 'source_reference' => 'SYNTHETIC-DDL', 'source_identity_key' => str_repeat('a', 64), 'source_root_key' => str_repeat('a', 64), 'repair_document_id' => (int) $db->insertID(), 'source_snapshot' => '{}', 'created_by' => 7, 'created_at' => '2026-10-07 09:00:00'];
            $debug = new ReflectionProperty($db, 'DBDebug');
            $setting = $debug->getValue($db);
            $debug->setValue($db, false);
            try {
                foreach ([['kind_code' => 'RECOVERY'], ['authority_code' => 'EXTERNAL_RECEIPT'], ['source_type' => 'GUEST_DIRECT'], ['source_type' => 'turo_reimbursement'], ['amount' => '0.00'], ['amount' => '-1.00'], ['amount' => '10000000000.00'], ['currency' => 'usd'], ['currency' => null], ['occurred_on' => null], ['status_code' => 'RECORDED'], ['source_root_key' => null], ['source_root_key' => str_repeat('b', 64)], ['replacement_of_recovery_entry_id' => 999], ['kind_code' => 'recovery_reversal'], ['related_recovery_entry_id' => 999], ['company_id' => 2], ['repair_document_id' => 999], ['damage_claim_id' => 999], ['payer_snapshot' => ' '], ['source_snapshot' => 'broken JSON'], ['status_code' => 'voided']] as $change) {
                    $this->assertFalse($db->table(Recoveries::TABLE)->insert(array_replace($values, $change)), json_encode($change));
                }
                $this->assertTrue($db->table(Recoveries::TABLE)->insert($values));
                $id = (int) $db->insertID();
                $this->assertFalse($db->table(Recoveries::TABLE)->insert($values));
                $this->assertFalse($db->table(Recoveries::TABLE)->where('id', $id)->update(['amount' => '11.00']));
                $this->assertFalse($db->table(Recoveries::TABLE)->where('id', $id)->update(['source_reference' => 'SYNTHETIC-CHANGED']));
                $this->assertFalse($db->table(Recoveries::TABLE)->where('id', $id)->delete());
                $this->assertFalse($db->table('vehicle_damage_repair_documents')->where('id', $values['repair_document_id'])->delete());
                $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $created['id'])->update(['recovery_finalized_at' => '2026-10-07 09:00:00']));
                $this->assertTrue($db->table(Recoveries::TABLE)->where('id', $id)->update(['status_code' => 'voided', 'voided_by' => 7, 'voided_at' => '2026-10-07 10:00:00', 'void_reason' => 'Synthetic correction']));
                $this->assertFalse($db->table(Recoveries::TABLE)->where('id', $id)->update(['void_reason' => 'Synthetic silent edit']));
            } finally {
                $debug->setValue($db, $setting);
            }
            require_once __DIR__ . '/../../app/Database/Migrations/2026-10-07-000034_CreateVehicleDamageRepairRecoveries.php';
            $this->expectException(RuntimeException::class);
            (new \App\Database\Migrations\CreateVehicleDamageRepairRecoveries())->down();
        } finally {
            $fixture?->close();
            if ($fixture === null) {
                $db->close();
            }
        }
    }

    public function testMariaDbMalformedTypeCollationAndMissingTriggerAreNotReady(): void
    {
        $fixture = new VehicleDamageRepairMariaDbFixture(false, '', 34);
        $db = $fixture->db;
        try {
            $repo = new Recoveries($db);
            $this->assertTrue($repo->ready());
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY amount DECIMAL(13,2) NULL');
            $db->resetDataCache();
            $this->assertFalse($repo->ready());
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY amount DECIMAL(12,2) NULL');
            $db->resetDataCache();
            $this->assertTrue($repo->ready());
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY payer_snapshot VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            $db->resetDataCache();
            $this->assertFalse($repo->ready());
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY payer_snapshot VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
            $db->resetDataCache();
            $this->assertTrue($repo->ready());
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY payer_snapshot VARCHAR(190) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL');
            $db->resetDataCache();
            $this->assertFalse($repo->ready(), 'Correct-looking field types cannot conceal the wrong text encoding.');
            $db->query('ALTER TABLE vehicle_damage_repair_recovery_entries MODIFY payer_snapshot VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
            $db->resetDataCache();
            $this->assertTrue($repo->ready());
            $db->query('DROP TRIGGER b31_recovery_no_delete');
            $this->assertFalse($repo->ready());
        } finally {
            $fixture->close();
        }
    }
}
