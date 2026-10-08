<?php

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Generated schema and real enforcement on both supported engines. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairCostsMigrationTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return [['sqlite', false, ''], ['sqlite', true, ''], ['sqlite', true, 'x_'], ['mariadb', false, ''], ['mariadb', true, ''], ['mariadb', true, 'x_']];
    }

    #[DataProvider('engines')]
    public function testGeneratedConstraintsAndV0290Preservation(string $engine, bool $upgrade, string $prefix): void
    {
        $fixture = null;
        if ($engine === 'mariadb') {
            $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'), 'Disposable MariaDB is a required B2.3 gate.');
            $fixture = new VehicleDamageRepairMariaDbFixture(false, $prefix, $upgrade ? 31 : 32);
            $db = $fixture->db;
        } else {
            $db = Database::connect('tests', false);
            $db->setPrefix($prefix);
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
                if (! $upgrade || strcmp(basename($path), '2026-10-06-000032') < 0) {
                    $runner->force($path, 'App');
                }
            }
            VehicleDamageDatabaseFixture::seed($db);
        }
        try {
            $work = new Work($db);
            $created = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
            $this->assertTrue($created['success'], json_encode($created));
            $job = $created['id'];
            $quote = $work->createEstimate(1, 10, $job, ['expected_version' => 1, 'command_key' => Work::commandKey(), 'quote_series_key' => Work::commandKey(), 'recording_mode' => 'historical_incomplete', 'amount' => '50.00', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'historical_recording_reason' => 'Synthetic preserved source', 'scope_membership_ids' => array_column((new \App\Repositories\VehicleDamageRepairRepository($db))->members(1, 10, $job), 'id')], 7);
            $this->assertTrue($quote['success'], json_encode($quote));
            $before = [];
            foreach ($db->listTables() as $table) {
                if (! str_ends_with($table, 'migrations')) {
                    $before[$table] = $db->table($table)->get()->getResultArray();
                }
            }
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            $this->assertTrue($runner->latest());
            $db->resetDataCache();
            $repo = new Costs($db);
            $this->assertTrue($repo->ready());
            $this->assertEqualsCanonicalizing(explode(' ', Costs::FIELDS), $db->getFieldNames(Costs::TABLE));
            $this->assertSame(0, $db->table(Costs::TABLE)->countAllResults());
            foreach ($before as $table => $rows) {
                $after = $db->table($table)->get()->getResultArray();
                if (str_ends_with($table, 'vehicle_damage_repair_jobs')) {
                    foreach ($after as &$row) {
                        foreach (['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note', 'recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'] as $field) {
                            $this->assertNull($row[$field]);
                            if (! array_key_exists($field, $rows[0])) {
                                unset($row[$field]);
                            }
                        }
                    }
                    unset($row);
                }
                $this->assertSame($rows, $after, $table);
            }
            $name = $db->escapeIdentifiers($db->prefixTable(Costs::TABLE));
            $generated = $engine === 'sqlite'
                ? $db->query('SELECT sql FROM sqlite_master WHERE name=?', [$db->prefixTable(Costs::TABLE)])->getRowArray()['sql']
                : array_values($db->query('SHOW CREATE TABLE ' . $name)->getRowArray())[1];
            foreach (Costs::CHECKS as $constraint) {
                $this->assertStringContainsString($constraint, $generated);
            }
            $db->table('vehicle_damage_repair_documents')->insert(['company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'external_reference' => 'Synthetic DDL source only', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-06 12:00:00', 'updated_at' => '2026-10-06 12:00:00']);
            $doc = (int) $db->insertID();
            $values = ['company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'amount' => '100.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic DDL Vendor', 'repair_document_id' => $doc, 'created_by' => 7, 'created_at' => '2026-10-06 12:00:00'];
            $debug = new ReflectionProperty($db, 'DBDebug');
            $setting = $debug->getValue($db);
            $debug->setValue($db, false);
            try {
                foreach ([['kind_code' => 'unsupported'], ['kind_code' => 'INVOICE'], ['status_code' => 'paid'], ['status_code' => 'RECORDED'], ['currency' => 'EUR'], ['currency' => 'usd'], ['amount' => '-1.00'], ['amount' => '10000000000.00'], ['vendor_snapshot' => ' '], ['status_code' => 'voided'], ['kind_code' => 'invoice_credit'], ['kind_code' => 'payment', 'amount' => '0.00'], ['company_id' => 2], ['repair_document_id' => 999999], ['related_cost_entry_id' => 999999]] as $change) {
                    $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, $change)), json_encode($change));
                }
                $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['cost_finalized_at' => '2026-10-06 12:00:00']));
                $this->assertTrue($db->table(Costs::TABLE)->insert($values));
                $id = (int) $db->insertID();
                // Isolate every named check; FK/type failures cannot substitute for its enforcement.
                $invalidChecks = [
                    'b23_cost_kind_ck' => ['kind_code' => 'unsupported'],
                    'b23_cost_status_ck' => ['status_code' => 'paid'],
                    'b23_cost_currency_ck' => ['currency' => 'EUR'],
                    'b23_cost_amount_ck' => ['amount' => '-1.00'],
                    'b23_cost_vendor_ck' => ['vendor_snapshot' => ' '],
                    'b23_cost_void_ck' => ['voided_by' => 7],
                    'b23_cost_relation_ck' => ['kind_code' => 'invoice_credit'],
                ];
                foreach ($invalidChecks as $constraint => $change) {
                    $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, $change)), $constraint);
                    $this->assertStringContainsString($constraint, $db->error()['message']);
                    $this->assertTrue($db->table(Costs::TABLE)->insert($values), 'Valid counterpart: ' . $constraint);
                }
                foreach ([
                    ['kind_code' => 'invoice', 'amount' => '0.00'],
                    ['kind_code' => 'payment'],
                    ['kind_code' => 'payment', 'related_cost_entry_id' => $id],
                    ['kind_code' => 'invoice_credit', 'related_cost_entry_id' => $id],
                    ['kind_code' => 'payment_refund', 'related_cost_entry_id' => $id],
                    ['status_code' => 'voided', 'voided_at' => '2026-10-06 12:00:00', 'voided_by' => 7, 'void_reason' => 'Synthetic coherent disposition'],
                    ['amount' => '9999999999.99', 'vendor_snapshot' => str_repeat('V', 190)],
                ] as $valid) {
                    $this->assertTrue($db->table(Costs::TABLE)->insert(array_replace($values, $valid)), json_encode($valid));
                }
                foreach ([
                    ['kind_code' => 'invoice', 'related_cost_entry_id' => $id],
                    ['kind_code' => 'payment_refund'],
                    ['kind_code' => 'invoice_credit', 'related_cost_entry_id' => $id, 'amount' => '0.00'],
                    ['kind_code' => 'payment_refund', 'related_cost_entry_id' => $id, 'amount' => '0.00'],
                    ['voided_at' => '2026-10-06 12:00:00'],
                    ['void_reason' => 'Unexpected recorded metadata'],
                    ['status_code' => 'voided', 'voided_at' => '2026-10-06 12:00:00', 'voided_by' => 7, 'void_reason' => ' '],
                ] as $invalid) {
                    $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, $invalid)), json_encode($invalid));
                }
                if ($engine === 'sqlite') {
                    foreach (['01.00', '1.0', '1.000', '.00', '1e2', '1..00', ' 1.00'] as $amount) {
                        $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, ['amount' => $amount])), $amount);
                    }
                    $type = $db->query('SELECT typeof(amount) AS type FROM ' . $name . ' WHERE id=?', [$id])->getRowArray()['type'];
                    $this->assertSame('text', $type);
                } else {
                    $type = $db->query('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'amount\'', [$db->prefixTable(Costs::TABLE)])->getRowArray()['COLUMN_TYPE'];
                    $this->assertSame('decimal(12,2)', $type);
                }
                $final = ['cost_finalized_at' => '2026-10-06 12:00:00', 'cost_finalized_by' => 7, 'cost_finalization_note' => 'Synthetic coherent finalization'];
                foreach (array_keys($final) as $field) {
                    $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update([$field => $final[$field]]), $field);
                    $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(array_replace($final, [$field => null])), $field);
                }
                $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(array_replace($final, ['cost_finalization_note' => ' '])));
                $this->assertTrue($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update($final));
                $this->assertTrue($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(array_fill_keys(array_keys($final), null)));
                $newJob = $db->table('vehicle_damage_repair_jobs')->where('id', $job)->get()->getRowArray();
                unset($newJob['id']);
                $newJob['creation_command_key'] = Work::commandKey();
                $this->assertFalse($db->table('vehicle_damage_repair_jobs')->insert(array_replace($newJob, ['cost_finalized_by' => 7])), 'INSERT finalization coherence');
                $this->assertTrue($db->table('vehicle_damage_repair_jobs')->insert(array_replace($newJob, $final)), 'INSERT populated finalization');
                $this->assertFalse($db->table(Costs::TABLE)->where('id', $id)->update(['amount' => '99.00']));
                $this->assertFalse($db->table(Costs::TABLE)->where('id', $id)->update(['status_code' => 'voided', 'voided_at' => '2026-10-06 12:00:00', 'voided_by' => 7, 'void_reason' => 'Synthetic correction', 'vendor_snapshot' => 'SYNTHETIC DDL VENDOR']));
                $this->assertFalse($db->table(Costs::TABLE)->where('id', $id)->delete());
                $this->assertTrue($db->table(Costs::TABLE)->where('id', $id)->update(['status_code' => 'voided', 'voided_at' => '2026-10-06 12:00:00', 'voided_by' => 7, 'void_reason' => 'Synthetic correction']));
                $this->assertFalse($db->table(Costs::TABLE)->where('id', $id)->update(['void_reason' => 'Rewritten history']));
            } finally {
                $debug->setValue($db, $setting);
            }
            $migration = new \App\Database\Migrations\CreateVehicleDamageRepairCosts(Database::forge($db));
            try {
                $migration->down();
                $this->fail('Monetary history rollback must refuse.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('retains monetary facts', $e->getMessage());
            }
            $trigger = $db->escapeIdentifiers($db->prefixTable('b23_cost_no_delete'));
            $db->query('DROP TRIGGER ' . $trigger);
            $this->assertFalse($repo->ready(), 'A partial cost authority must fail closed.');
            $this->expectException(RuntimeException::class);
            $repo->archiveGuard(1, $job, $doc);
        } finally {
            $fixture?->close();
        }
    }

    public function testEveryPartialFinalizationFootprintDisablesArchiving(): void
    {
        $db = Database::connect('tests', false);
        $db->setPrefix('');
        foreach (['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'] as $field) {
            $db->query('CREATE TABLE vehicle_damage_repair_jobs (id INTEGER PRIMARY KEY, ' . $field . ' TEXT NULL)');
            $repo = new Costs($db);
            $this->assertTrue($repo->present(), $field);
            $this->assertFalse($repo->ready());
            try {
                $repo->archiveGuard(1, 1, 1);
                $this->fail('Partial finalization authority must fail closed.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('schema is incomplete', $e->getMessage());
            }
            $db->query('DROP TABLE vehicle_damage_repair_jobs');
            $db->resetDataCache();
        }
        $db->close();
    }
}
