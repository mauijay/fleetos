<?php

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture as Maria;

/** @internal Regression: cost table inherits latin1 database default. Synthetic local databases only. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairCostsCharsetMigrationTest extends CIUnitTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/Database/Migrations/2026-10-07-000033_EnsureVehicleDamageRepairCostCharset.php';

    public static function defaults(): array
    {
        return [['latin1_swedish_ci', ''], ['utf8mb4_general_ci', ''], ['latin1_swedish_ci', 'x_'], ['utf8mb4_general_ci', 'x_']];
    }

    #[DataProvider('defaults')]
    public function testCostTableInheritsLatin1DatabaseDefaultThenOnly000033HardensIt(string $collation, string $prefix): void
    {
        $fixture = new Maria(false, $prefix, $prefix === '' ? 32 : 31, $collation);
        $db = $fixture->db;
        try {
            if ($prefix !== '') {
                $this->assertTrue($this->runner($db)->force(__DIR__ . '/../../app/Database/Migrations/2026-10-06-000032_CreateVehicleDamageRepairCosts.php', 'App'));
            }
            $this->assertSame($collation, $db->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->getRowArray()['DEFAULT_COLLATION_NAME']);
            $before = $this->inventory($db);
            $rows = $this->rows($db);
            $this->assertSame($collation, $before['table']['TABLE_COLLATION']);
            $this->assertSame($collation === 'utf8mb4_general_ci', (new Costs($db))->ready());
            $ledger = self::acceptedAppMigrationCount() - 1;
            $this->assertSame($ledger, $db->table('migrations')->countAllResults());
            $sql = $this->apply($db);
            $after = $this->inventory($db);
            $this->assertSame($ledger + 1, $db->table('migrations')->countAllResults());
            $history = $db->table('migrations')->orderBy('id', 'DESC')->get(1)->getRowArray();
            $this->assertSame('2026-10-07-000033', $history['version']);
            $this->assertSame('App\\Database\\Migrations\\EnsureVehicleDamageRepairCostCharset', $history['class']);
            $this->assertSame($rows, $this->rows($db));
            $this->assertSame($this->normalized($before), $this->normalized($after));
            $this->assertCorrect($db, $after);
            if ($collation === 'utf8mb4_general_ci') {
                $this->assertSame([], $sql, 'Correct table must execute zero ALTER statements.');
                $this->assertSame($before, $after, 'Actual DDL/inventories must be identical, including AUTO_INCREMENT.');
            } else {
                $this->assertCount(1, $sql);
                $this->assertStringContainsString($db->prefixTable(Costs::TABLE), $sql[0]);
                $this->assertStringNotContainsString('CONVERT TO', $sql[0], 'Explicit TEXT avoids implicit MEDIUMTEXT widening.');
            }
        } finally {
            $fixture->close();
        }
    }

    public function testFreshCompleteLatin1ChainHasNoBusinessRows(): void
    {
        $fixture = new Maria(false, '', 33, 'latin1_swedish_ci', false);
        try {
            $db = $fixture->db;
            $this->assertSame(self::acceptedAppMigrationCount(), $db->table('migrations')->countAllResults());
            $this->assertCorrect($db, $this->inventory($db));
            foreach (['fleet_vehicles', 'vehicle_damage_items', 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_documents', 'vehicle_damage_repair_estimates', Costs::TABLE, 'damage_claims', 'vehicle_maintenance', 'operating_expenses'] as $table) {
                if ($db->tableExists($table)) {
                    $this->assertSame(0, $db->table($table)->countAllResults(), $table);
                }
            }
        } finally {
            $fixture->close();
        }
    }

    public function testBothV0300VariantsConvergeToTheSameAcceptedCostSchema(): void
    {
        $accepted = null;
        foreach (['utf8mb4_general_ci', 'latin1_swedish_ci'] as $collation) {
            $fixture = new Maria(false, '', 32, $collation);
            try {
                $this->apply($fixture->db);
                $schema = $this->inventory($fixture->db);
                if ($accepted === null) {
                    $accepted = $schema;
                } else {
                    $this->assertSame($accepted, $schema);
                }
            } finally {
                $fixture->close();
            }
        }
    }

    public function testNonstrictConnectionCannotTruncateOversizedUtf8TextAndDoesNotRecordFailedDdl(): void
    {
        $fixture = new Maria(false, '', 32, 'latin1_swedish_ci');
        $db = $fixture->db;
        try {
            $created = (new Work($db))->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
            $this->assertTrue($created['success'], json_encode($created));
            $job = $created['id'];
            $this->assertTrue($db->table('vehicle_damage_repair_documents')->insert(['company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'external_reference' => 'Synthetic capacity source', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-07 12:00:00', 'updated_at' => '2026-10-07 12:00:00']));
            $this->assertTrue($db->table(Costs::TABLE)->insert(['company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'amount' => '1.00', 'currency' => 'USD', 'occurred_on' => '2026-10-07', 'vendor_snapshot' => 'Synthetic capacity vendor', 'repair_document_id' => (int) $db->insertID(), 'note' => str_repeat('é', 40000), 'created_by' => 7, 'created_at' => '2026-10-07 12:00:00']));
            $db->query("SET SESSION sql_mode=''");
            $before = $this->inventory($db);
            $rows = $this->rows($db);
            $ledger = $db->table('migrations')->countAllResults();
            try {
                $this->runner($db)->force(self::MIGRATION, 'App');
                $this->fail('Preserving TEXT requires refusing a conversion that cannot preserve its value.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Keep writes suspended', $e->getMessage());
                $this->assertStringContainsString('never blindly rerun', $e->getMessage());
            }
            $this->assertSame('', $db->query('SELECT @@SESSION.sql_mode AS mode')->getRowArray()['mode']);
            $this->assertSame($before, $this->inventory($db));
            $this->assertSame($rows, $this->rows($db));
            $this->assertSame($ledger, $db->table('migrations')->countAllResults());
            $this->assertFalse((new Costs($db))->ready());
        } finally {
            $fixture->close();
        }
    }

    public static function populated(): array
    {
        return [
            ['latin1_swedish_ci', false, "Synthetic O'Brien café", "ASCII and apostrophe: O'Brien"],
            ['latin1_swedish_ci', true, 'Synthetic Kāneʻohe', "Hawaiʻi — 日本語 — café — 🌺 O'Brien"],
            ['utf8mb4_general_ci', false, 'Synthetic Kāneʻohe', "Hawaiʻi — 日本語 — café — 🌺 O'Brien"],
            ['utf8mb4_unicode_ci', false, 'Synthetic Kāneʻohe', "Hawaiʻi — 日本語 — café — 🌺 O'Brien"],
        ];
    }

    #[DataProvider('populated')]
    public function testPopulatedConversionPreservesSemanticHashesAndEnforcement(string $collation, bool $mixed, string $vendor, string $note): void
    {
        $fixture = new Maria(false, '', 32, $collation === 'utf8mb4_unicode_ci' ? 'utf8mb4_general_ci' : $collation);
        $db = $fixture->db;
        try {
            if ($mixed || $collation === 'utf8mb4_unicode_ci') {
                $text = ['kind_code' => 'VARCHAR(20) NOT NULL', 'currency' => 'CHAR(3) NOT NULL', 'vendor_snapshot' => 'VARCHAR(190) NOT NULL', 'vendor_reference' => 'VARCHAR(120) NULL DEFAULT NULL', 'status_code' => "VARCHAR(12) NOT NULL DEFAULT 'recorded'", 'note' => 'TEXT NULL DEFAULT NULL', 'void_reason' => 'TEXT NULL DEFAULT NULL'];
                $clauses = $mixed ? [] : ['DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'];
                foreach ($text as $field => $definition) {
                    [$type, $rest] = explode(' ', $definition, 2);
                    $clauses[] = 'MODIFY ' . $field . ' ' . $type . ' CHARACTER SET utf8mb4 COLLATE ' . ($mixed ? 'utf8mb4_general_ci' : $collation) . ' ' . $rest;
                }
                $db->query('ALTER TABLE ' . Costs::TABLE . ' ' . implode(', ', $clauses));
            }
            // Unicode cannot exist in a genuine latin1 column. The mixed-default
            // fixture stores it in UTF-8 columns while reproducing the wrong default.
            $created = (new Work($db))->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
            $this->assertTrue($created['success'], json_encode($created));
            $job = $created['id'];
            $this->assertTrue($db->table('vehicle_damage_repair_documents')->insert(['company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'external_reference' => 'Synthetic charset source', 'created_by' => 7, 'updated_by' => 7, 'created_at' => '2026-10-07 12:00:00', 'updated_at' => '2026-10-07 12:00:00']));
            $document = (int) $db->insertID();
            $values = ['id' => 101, 'company_id' => 1, 'vehicle_damage_repair_job_id' => $job, 'kind_code' => 'invoice', 'amount' => '1234.56', 'currency' => 'USD', 'occurred_on' => '2026-10-07', 'vendor_company_id' => 2, 'vendor_snapshot' => $vendor, 'vendor_reference' => "Synthetic O'Brien", 'repair_document_id' => $document, 'note' => $note, 'created_by' => 7, 'created_at' => '2026-10-07 12:00:00'];
            $this->assertTrue($db->table(Costs::TABLE)->insert($values));
            $this->assertTrue($db->table(Costs::TABLE)->insert(array_replace($values, ['id' => 102, 'kind_code' => 'invoice_credit', 'amount' => '12.34', 'related_cost_entry_id' => 101, 'status_code' => 'voided', 'voided_at' => '2026-10-07 13:00:00', 'voided_by' => 7, 'void_reason' => $note])));
            $this->assertTrue($db->table(Costs::TABLE)->insert(array_replace($values, ['id' => 103, 'kind_code' => 'invoice_credit', 'amount' => '10.00', 'related_cost_entry_id' => 101, 'replacement_of_cost_entry_id' => 102])));
            $db->query('ALTER TABLE ' . Costs::TABLE . ' AUTO_INCREMENT=500');
            $before = $this->inventory($db);
            $rows = $this->rows($db);
            $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            $this->assertSame(3, count($rows[Costs::TABLE]));
            $this->assertSame($note, $rows[Costs::TABLE][0]['note']);
            $this->assertSame($vendor, $rows[Costs::TABLE][0]['vendor_snapshot']);
            $this->assertSame('1234.56', $rows[Costs::TABLE][0]['amount']);
            $this->assertSame($collation === 'utf8mb4_general_ci', (new Costs($db))->ready());
            $sql = $this->apply($db);
            $after = $this->inventory($db);
            $this->assertSame($rows, $this->rows($db));
            $this->assertSame($hash, hash('sha256', json_encode($this->rows($db), JSON_THROW_ON_ERROR)));
            $this->assertSame($this->normalized($before), $this->normalized($after));
            $this->assertSame($before['table']['AUTO_INCREMENT'], $after['table']['AUTO_INCREMENT']);
            $this->assertCorrect($db, $after);
            $this->assertCount($collation === 'utf8mb4_general_ci' ? 0 : 1, $sql);
            $this->assertTrue($db->table(Costs::TABLE)->insert(array_replace($values, ['id' => null, 'vendor_reference' => 'Synthetic next id'])));
            $this->assertSame(500, (int) $db->insertID());
            $this->enforcement($db, $values, $job);
        } finally {
            $fixture->close();
        }
    }

    public static function malformed(): array
    {
        return [
            ['DROP TABLE vehicle_damage_repair_cost_entries'],
            ['ALTER TABLE vehicle_damage_repair_cost_entries MODIFY vendor_reference VARCHAR(121) NULL'],
            ['ALTER TABLE vehicle_damage_repair_cost_entries MODIFY note MEDIUMTEXT NULL'],
            ["ALTER TABLE vehicle_damage_repair_cost_entries ALTER status_code SET DEFAULT 'voided'"],
            ['ALTER TABLE vehicle_damage_repair_cost_entries MODIFY vendor_reference VARCHAR(120) NOT NULL'],
            ['ALTER TABLE vehicle_damage_repair_cost_entries ADD unexpected INT NULL'],
            ['ALTER TABLE vehicle_damage_repair_cost_entries DROP CONSTRAINT b23_cost_vendor_ck'],
            ['DROP INDEX b23_cost_history_idx ON vehicle_damage_repair_cost_entries'],
            ['DROP TRIGGER b23_cost_no_delete'],
            ['ALTER TABLE vehicle_damage_repair_cost_entries MODIFY vendor_reference VARCHAR(120) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMissingOrMalformedSchemaFailsWithoutAlterOrLedgerEntry(string $damage): void
    {
        $fixture = new Maria(false, '', 32);
        $db = $fixture->db;
        try {
            $db->query($damage);
            $db->resetDataCache();
            $before = $this->inventory($db);
            $alters = [];
            $listener = static function ($query) use (&$alters): void {
                if (preg_match('/^ALTER\s+TABLE\b/i', trim($query->getQuery()))) {
                    $alters[] = $query->getQuery();
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $this->runner($db)->force(self::MIGRATION, 'App');
                $this->fail('Malformed baseline must fail closed.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('inspect', strtolower($e->getMessage()));
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            $this->assertSame([], $alters);
            $this->assertSame($before, $this->inventory($db));
            $this->assertSame(self::acceptedAppMigrationCount() - 1, $db->table('migrations')->countAllResults());
        } finally {
            $fixture->close();
        }
    }

    public function testSQLiteNoCharsetDdlAndForwardOnlyRollback(): void
    {
        $db = Database::connect('tests', false);
        $db->setPrefix('');
        try {
            $runner = $this->runner($db);
            foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
                if (strcmp(basename($path), '2026-10-07-000033') < 0) {
                    $this->assertTrue($runner->force($path, 'App'));
                }
            }
            VehicleDamageDatabaseFixture::seed($db);
            $before = $db->query('SELECT type, name, sql FROM sqlite_master ORDER BY type,name')->getResultArray();
            $this->assertSame([], $this->apply($db));
            $this->assertSame($before, $db->query('SELECT type, name, sql FROM sqlite_master ORDER BY type,name')->getResultArray());
            $this->assertTrue((new Costs($db))->ready());
            $this->assertSame(self::acceptedAppMigrationCount(), $db->table('migrations')->countAllResults());
            $migration = new \App\Database\Migrations\EnsureVehicleDamageRepairCostCharset(Database::forge($db));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('forward-only');
            $migration->down();
        } finally {
            $db->close();
        }
    }

    private static function acceptedAppMigrationCount(): int
    {
        return count(array_filter(glob(__DIR__ . '/../../app/Database/Migrations/*.php'), fn (string $path): bool => strcmp(basename($path), '2026-10-07-000034') < 0));
    }

    private function runner(BaseConnection $db): MigrationRunner
    {
        return (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
    }

    /** Observe actual database statements, so a rebuilding semantic no-op cannot pass. */
    private function apply(BaseConnection $db): array
    {
        $alters = [];
        $listener = static function ($query) use (&$alters): void {
            if (preg_match('/^ALTER\s+TABLE\b/i', trim($query->getQuery()))) {
                $alters[] = $query->getQuery();
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $this->assertTrue($this->runner($db)->force(self::MIGRATION, 'App'));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        return $alters;
    }

    private function rows(BaseConnection $db): array
    {
        $rows = [];
        foreach ($db->listTables() as $table) {
            if (! str_ends_with($table, 'migrations')) {
                $values = $db->table($table)->get()->getResultArray();
                usort($values, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
                $rows[$table] = $values;
            }
        }
        ksort($rows);
        return $rows;
    }

    /** Independent SHOW CREATE plus engine inventories, including job coherence. */
    private function inventory(BaseConnection $db): array
    {
        $name = $db->prefixTable(Costs::TABLE);
        $tables = $db->query('SELECT ENGINE, AUTO_INCREMENT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name])->getRowArray();
        if ($tables === null) {
            return [];
        }
        return [
            'table' => $tables,
            'ddl' => array_values($db->query('SHOW CREATE TABLE ' . $db->escapeIdentifiers($name))->getRowArray())[1],
            'columns' => $db->query('SHOW FULL COLUMNS FROM ' . $db->escapeIdentifiers($name))->getResultArray(),
            'indexes' => array_map(static function (array $index): array {
                unset($index['Cardinality']);
                return $index;
            }, $db->query('SHOW INDEX FROM ' . $db->escapeIdentifiers($name))->getResultArray()),
            'fk' => array_map(static fn ($key): array => (array) $key, $db->getForeignKeyData(Costs::TABLE)),
            'checks' => $db->query('SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?) ORDER BY TABLE_NAME,CONSTRAINT_NAME', [$name, $db->prefixTable('vehicle_damage_repair_jobs')])->getResultArray(),
            'triggers' => $db->query('SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_STATEMENT, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$name])->getResultArray(),
        ];
    }

    private function normalized(array $inventory): array
    {
        unset($inventory['table']['TABLE_COLLATION']);
        // Strip only encoding attributes, retaining every other part of the DDL.
        $inventory['ddl'] = preg_replace('/(?: CHARACTER SET | DEFAULT CHARSET=| COLLATE[= ])(?:latin1|utf8mb4)(?:_[a-z0-9_]+)?/i', '', $inventory['ddl']);
        foreach ($inventory['columns'] as &$column) {
            unset($column['Collation']);
        }
        unset($column);
        return $inventory;
    }

    private function assertCorrect(BaseConnection $db, array $inventory): void
    {
        $this->assertSame('utf8mb4_general_ci', $inventory['table']['TABLE_COLLATION']);
        $this->assertSame(explode(' ', Costs::FIELDS), array_column($inventory['columns'], 'Field'));
        $text = array_filter($inventory['columns'], static fn (array $column): bool => $column['Collation'] !== null);
        $this->assertCount(7, $text);
        foreach ($text as $column) {
            $this->assertSame('utf8mb4_general_ci', $column['Collation']);
        }
        $this->assertTrue((new Costs($db))->ready());
        $this->assertCount(6, $inventory['fk']);
        $this->assertCount(8, $inventory['checks']);
        $this->assertCount(2, $inventory['triggers']);
    }

    private function enforcement(BaseConnection $db, array $values, int $job): void
    {
        unset($values['id']);
        $debug = new ReflectionProperty($db, 'DBDebug');
        $previous = $debug->getValue($db);
        $debug->setValue($db, false);
        try {
            $invalid = ['b23_cost_kind_ck' => ['kind_code' => 'unsupported'], 'b23_cost_status_ck' => ['status_code' => 'paid'], 'b23_cost_currency_ck' => ['currency' => 'EUR'], 'b23_cost_amount_ck' => ['amount' => '-1.00'], 'b23_cost_vendor_ck' => ['vendor_snapshot' => ' '], 'b23_cost_void_ck' => ['voided_by' => 7], 'b23_cost_relation_ck' => ['kind_code' => 'invoice_credit']];
            foreach ($invalid as $constraint => $change) {
                $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, $change)));
                $this->assertStringContainsString($constraint, $db->error()['message']);
            }
            foreach ([['company_id' => 2], ['repair_document_id' => 999999], ['vendor_company_id' => 999999], ['related_cost_entry_id' => 999999], ['replacement_of_cost_entry_id' => 999999]] as $change) {
                $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, $change)));
            }
            $this->assertFalse($db->table(Costs::TABLE)->insert(array_replace($values, ['kind_code' => 'invoice_credit', 'related_cost_entry_id' => 101, 'replacement_of_cost_entry_id' => 102])));
            $this->assertStringContainsString('b23_cost_replace_uq', $db->error()['message']);
            $this->assertFalse($db->table(Costs::TABLE)->where('id', 101)->update(['amount' => '9.99']));
            $this->assertFalse($db->table(Costs::TABLE)->where('id', 101)->delete());
            $this->assertFalse($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['cost_finalized_by' => 7]));
            $this->assertStringContainsString('b23_job_finalization_ck', $db->error()['message']);
        } finally {
            $debug->setValue($db, $previous);
        }
    }
}
