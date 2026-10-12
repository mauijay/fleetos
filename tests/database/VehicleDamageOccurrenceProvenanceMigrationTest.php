<?php

namespace Tests\Database;

use App\Database\Migrations\AddVehicleDamageOccurrenceProvenance;
use App\Database\VehicleDamageOccurrenceProvenanceSchema as Schema;
use App\Repositories\VehicleDamageOccurrenceRepository;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageOccurrenceFixture as Fixture;

/** @internal Real 44 -> 45 ledger, native additive DDL, no business backfill. */
final class VehicleDamageOccurrenceProvenanceMigrationTest extends CIUnitTestCase
{
    public static function databases(): array
    {
        return [
            ['sqlite', '', true, 'utf8mb4_general_ci'], ['sqlite', 's1_', true, 'utf8mb4_general_ci'], ['sqlite', '', false, 'utf8mb4_general_ci'],
            ['maria', '', true, 'utf8mb4_general_ci'], ['maria', '', true, 'latin1_swedish_ci'], ['maria', 's1_', true, 'latin1_swedish_ci'],
            ['maria', '', false, 'utf8mb4_general_ci'], ['maria', 's1_', false, 'latin1_swedish_ci'],
        ];
    }

    #[DataProvider('databases')]
    public function testExactAdditiveUpgradeAndFreshChain(string $engine, string $prefix, bool $populated, string $collation): void
    {
        $fixture = new Fixture($engine, $prefix, $collation, $populated);
        $db = $fixture->db;
        try {
            if ($populated) {
                $fixture->create(['areas' => [Fixture::area(['file_id' => 800]), Fixture::area(['panel_code' => 'rear_right_wheel_tire'])]]);
            }
            $before = $fixture->rows();
            $fingerprint = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
            $indexes = [];
            $foreignKeys = [];
            foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_incident_items', 'vehicle_damage_item_evidence'] as $table) {
                $indexes[$table] = $db->getIndexData($table);
                $foreignKeys[$table] = $db->getForeignKeyData($table);
            }
            $this->assertSame(44, $db->table('migrations')->countAllResults());
            $this->assertTrue(Schema::matchesStage($db, 0));
            $observedDdl = [];
            $businessWrites = [];
            $migrationTable = $db->escapeIdentifiers($db->prefixTable('migrations'));
            $listener = static function (Query $query) use (&$observedDdl, &$businessWrites, $migrationTable): void {
                $sql = $query->getOriginalQuery();
                if (preg_match('/^(ALTER TABLE|CREATE INDEX) /', $sql)) {
                    $observedDdl[] = $sql;
                }
                if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/', $sql) && ! str_contains($sql, $migrationTable)) {
                    $businessWrites[] = $sql;
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $fixture->migrate();
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            $this->assertSame(Schema::statements($db), $observedDdl, 'Observed DDL matches the exact offline plan in order.');
            $this->assertSame([], $businessWrites);
            $this->assertSame(45, $db->table('migrations')->countAllResults());
            $history = $db->table('migrations')->orderBy('id', 'DESC')->get(1)->getRowArray();
            $this->assertSame('2026-10-11-000036', $history['version']);
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            $this->assertTrue($runner->latest());
            $this->assertSame(45, $db->table('migrations')->countAllResults(), 'No pending App migration.');
            $this->assertTrue(Schema::ready($db));
            $after = $fixture->rows();
            foreach ($after as $table => &$rows) {
                foreach ($rows as &$row) {
                    $new = str_ends_with($table, 'vehicle_damage_incident_items') ? ['condition_event_id', 'basis_snapshot'] : (str_ends_with($table, 'vehicle_damage_item_evidence') ? ['vehicle_damage_incident_item_id'] : []);
                    foreach ($new as $column) {
                        $this->assertNull($row[$column]);
                        unset($row[$column]);
                    }
                }
                unset($row);
            }
            unset($rows);
            $this->assertSame($before, $after);
            $this->assertSame($fingerprint, hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)));
            foreach ($indexes as $table => $existing) {
                foreach ($existing as $name => $index) {
                    $this->assertEquals($index, $db->getIndexData($table)[$name]);
                }
                foreach ($foreignKeys[$table] as $name => $key) {
                    $this->assertEquals($key, $db->getForeignKeyData($table)[$name]);
                }
            }
            foreach (Schema::INDEXES as $name => [$table, $fields]) {
                $index = $db->getIndexData($table)[$name];
                $this->assertSame($fields, $index->fields);
                $this->assertSame('INDEX', $index->type);
            }
            if ($engine === 'maria') {
                $column = $db->query('SELECT COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'basis_snapshot\'', [$db->prefixTable('vehicle_damage_incident_items')])->getRowArray();
                $this->assertSame(['COLUMN_TYPE' => 'longtext', 'CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_bin'], $column);
                foreach (Schema::REFERENCES as [$table, $column, $parent]) {
                    $types = $db->query('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME=? AND COLUMN_NAME=?) OR (TABLE_NAME=? AND COLUMN_NAME=\'id\'))', [$db->prefixTable($table), $column, $db->prefixTable($parent)])->getResultArray();
                    $this->assertCount(2, $types);
                    $this->assertSame($types[0], $types[1]);
                }
            }
            $migration = new AddVehicleDamageOccurrenceProvenance(Database::forge($db));
            $schema = $this->schema($db);
            try {
                $migration->down();
                $this->fail('Down must refuse.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('forward-only', $error->getMessage());
            }
            $this->assertSame($schema, $this->schema($db));
        } finally {
            $fixture->close();
        }
    }

    public static function engines(): array
    {
        return [['sqlite'], ['maria']];
    }

    #[DataProvider('engines')]
    public function testDatabaseRejectsInvalidJsonOversizeAndForeignKeyChanges(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->migrate();
            $incident = $fixture->create(['areas' => [Fixture::area(['file_id' => 800])]]);
            $db = $fixture->db;
            $member = $db->table('vehicle_damage_incident_items')->where('vehicle_damage_incident_id', $incident)->get()->getRowArray();
            $evidence = $db->table('vehicle_damage_item_evidence')->get()->getRowArray();
            $debug = new \ReflectionProperty($db, 'DBDebug');
            $debug->setValue($db, false);
            $json = json_encode(['text' => '輪毂 — café 🌺'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach ([$json, null, '"' . str_repeat('x', 32766) . '"'] as $value) {
                $this->assertTrue($db->table('vehicle_damage_incident_items')->where('id', $member['id'])->update(['basis_snapshot' => $value]));
            }
            foreach (['not-json', '"' . str_repeat('x', 32767) . '"', '{'] as $value) {
                $this->assertFalse($db->table('vehicle_damage_incident_items')->where('id', $member['id'])->update(['basis_snapshot' => $value]));
            }
            $this->assertFalse($db->table('vehicle_damage_incident_items')->where('id', $member['id'])->update(['condition_event_id' => 999999]));
            $this->assertFalse($db->table('vehicle_damage_item_evidence')->where('id', $evidence['id'])->update(['vehicle_damage_incident_item_id' => 999999]));
            $this->assertFalse($db->table('vehicle_damage_item_events')->where('id', $member['condition_event_id'])->delete());
            $this->assertFalse($db->table('vehicle_damage_item_events')->where('id', $member['condition_event_id'])->update(['id' => 999999]));
            $this->assertFalse($db->table('vehicle_damage_incident_items')->where('id', $member['id'])->delete());
            $this->assertFalse($db->table('vehicle_damage_incident_items')->where('id', $member['id'])->update(['id' => 999999]));
        } finally {
            $fixture->close();
        }
    }

    public static function interruptions(): array
    {
        $cases = [];
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (range(0, 8) as $count) {
                $cases[] = [$engine, $count];
            }
        }
        return $cases;
    }

    #[DataProvider('interruptions')]
    public function testEveryInterruptedDdlBoundaryRefusesBlindRerun(string $engine, int $completed): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->create(['areas' => [Fixture::area(['external_reference' => 'Synthetic legacy evidence'])]]);
            $db = $fixture->db;
            $before = $fixture->rows();
            foreach (array_slice(Schema::statements($db), 0, $completed) as $sql) {
                $this->assertNotFalse($db->query($sql));
            }
            $this->assertTrue(Schema::matchesStage($db, $completed));
            $this->assertSame($completed === 8, Schema::ready($db));
            if ($completed > 0) {
                $schema = $this->schema($db);
                try {
                    (new AddVehicleDamageOccurrenceProvenance(Database::forge($db)))->up();
                    $this->fail('An unacknowledged migration must refuse rerun.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('unexpected or partial', $error->getMessage());
                }
                $this->assertSame($schema, $this->schema($db));
            }
            // Even all eight DDL steps do not enable writers without the framework ledger acknowledgement.
            try {
                (new VehicleDamageOccurrenceRepository($db))->requireReady();
                $this->fail('Provenance writer must fail closed.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('incomplete', $error->getMessage());
            }
            $legacyMember = $db->table('vehicle_damage_incident_items')->get()->getRowArray();
            $failed = (new \App\Services\Fleet\VehicleDamageService($db))->attachEvidence(1, 10, (int) $legacyMember['vehicle_damage_item_id'], ['external_reference' => 'Synthetic rejected partial-schema occurrence evidence'], 7, (int) $legacyMember['id']);
            $this->assertFalse($failed['success']);
            $this->assertStringContainsString('incomplete', implode(' ', $failed['errors']));
            $after = $fixture->rows();
            foreach ($after as $table => &$rows) {
                foreach ($rows as &$row) {
                    foreach (['condition_event_id', 'basis_snapshot', 'vehicle_damage_incident_item_id'] as $column) {
                        if (array_key_exists($column, $row) && ! array_key_exists($column, $before[$table][0] ?? [])) {
                            $this->assertNull($row[$column]);
                            unset($row[$column]);
                        }
                    }
                }
                unset($row);
            }
            unset($rows);
            $this->assertSame($before, $after);
            $this->assertSame(44, $db->table('migrations')->countAllResults());
        } finally {
            $fixture->close();
        }
    }

    private function schema(\CodeIgniter\Database\BaseConnection $db): array
    {
        $result = [];
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_incident_items', 'vehicle_damage_item_evidence'] as $table) {
            $name = $db->prefixTable($table);
            $result[$table] = $db->getPlatform() === 'SQLite3' ? $db->query('SELECT sql FROM sqlite_master WHERE tbl_name=? ORDER BY type,name', [$name])->getResultArray() : $db->query('SHOW CREATE TABLE ' . $db->escapeIdentifiers($name))->getRowArray();
        }
        return $result;
    }
}
