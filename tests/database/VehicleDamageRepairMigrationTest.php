<?php

use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairMigrationTest extends CIUnitTestCase
{
    /** Keep the accepted B2.1 migration inventory scoped to its own migration. */
    private function throughB21(MigrationRunner $runner): bool
    {
        $applied = array_column($runner->getHistory(''), 'version');
        foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
            if (strcmp(basename($path), '2026-10-06-000031') < 0 && ! in_array(explode('_', basename($path))[0], $applied, true)) {
                $runner->force($path, 'App');
            }
        }
        return true;
    }

    public static function engines(): array
    {
        return [['sqlite', false, ''], ['sqlite', true, ''], ['mariadb', false, ''], ['mariadb', true, ''], ['sqlite', true, 'x_'], ['mariadb', true, 'x_']];
    }

    #[DataProvider('engines')]
    public function testFreshUpgradePreservationRerunAndRollbackRefusal(string $engine, bool $upgrade, string $prefix): void
    {
        $fixture = null;
        if ($engine === 'mariadb') {
            if (! getenv('B21_MARIADB_CONFIG')) {
                $this->markTestSkipped('Requires disposable B21 MariaDB release gate.');
            }
            $fixture = new VehicleDamageRepairMariaDbFixture($upgrade, $prefix, 30);
            $db = $fixture->db;
        } else {
            $db = Database::connect('tests', false);
            if ($prefix !== '') {
                $db->setPrefix($prefix);
            }
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            if ($upgrade) {
                $paths = glob(__DIR__ . '/../../app/Database/Migrations/*.php');
                sort($paths);
                foreach ($paths as $path) {
                    if (strcmp(basename($path), '2026-10-06-000030') < 0) {
                        $runner->force($path, 'App');
                    }
                }
            } else {
                $this->throughB21($runner);
            }
            VehicleDamageDatabaseFixture::seed($db);
        }
        try {
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            $condition = VehicleDamageRepairDatabaseFixture::condition($db);
            $incidentService = new \App\Services\Fleet\VehicleDamageIncidentService($db);
            $createdIncident = $incidentService->create(1, 10, ['discovered_at' => '2026-10-01T09:00', 'attribution_type' => 'unknown', 'areas' => [['panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic', 'effect_code' => 'new_damage', 'note' => 'Synthetic migration incident']]], 7);
            $this->assertTrue($createdIncident['success'], json_encode($createdIncident));
            $preview = $incidentService->historicalOriginalPreview(1, 10, $condition);
            $backfilled = $incidentService->backfillOriginal(1, 10, $condition, ['confirmed' => '1', 'expected_state' => $preview['expected_state'], 'trip_id' => 100, 'attribution_type' => 'unknown', 'reason' => 'Synthetic B1.1 migration preservation'], 7);
            $this->assertTrue($backfilled['success'], json_encode($backfilled));
            $before = [];
            $beforeFields = [];
            foreach ($db->listTables() as $table) {
                if (! str_contains($table, 'repair_job') && ! str_ends_with($table, 'migrations')) {
                    $before[$table] = $db->table($table)->get()->getResultArray();
                    $beforeFields[$table] = $db->getFieldNames($table);
                }
            }
            $this->assertTrue($this->throughB21($runner));
            $db->resetDataCache();
            $preservation = [];
            foreach ($before as $table => $rows) {
                $after = $db->table($table)->get()->getResultArray();
                if ($upgrade && str_ends_with($table, 'vehicle_damage_item_events')) {
                    foreach ($after as &$row) {
                        $this->assertNull($row['repair_job_event_id']);
                        unset($row['repair_job_event_id']);
                    } unset($row);
                }
                $this->assertSame($rows, $after, $table);
                $preservation[$table] = ['before_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'after_sha256' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR))];
            }
            $jobs = ['id', 'company_id', 'fleet_vehicle_id', 'intent_code', 'category_code', 'summary', 'status_code', 'vendor_company_id', 'vendor_snapshot', 'vendor_order_reference', 'scheduled_at', 'started_at', 'completed_at', 'completion_note', 'version', 'creation_command_key', 'creation_command_payload_hash', 'created_by', 'updated_by', 'created_at', 'updated_at'];
            $members = ['id', 'company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_item_id', 'result_code', 'note', 'occurred_at', 'withdrawn_at', 'withdrawn_by', 'withdrawal_reason', 'created_by', 'updated_by', 'created_at', 'updated_at'];
            $events = ['id', 'company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id', 'vehicle_damage_item_id', 'event_code', 'job_version', 'actor_user_id', 'recorded_at', 'occurred_at', 'reason_category_code', 'reason', 'before_json', 'after_json', 'command_key', 'command_payload_hash'];
            foreach (['vehicle_damage_repair_jobs' => $jobs, 'vehicle_damage_repair_job_items' => $members, 'vehicle_damage_repair_job_events' => $events] as $table => $fields) {
                $this->assertEqualsCanonicalizing($fields, array_column($db->getFieldData($table), 'name'));
                $this->assertSame(0, $db->table($table)->countAllResults());
                $this->assertGreaterThanOrEqual(3, count($db->getForeignKeyData($table)));
            }
            $this->assertArrayHasKey('repair_events_command_uq', $db->getIndexData('vehicle_damage_repair_job_events'));
            $this->assertArrayHasKey('repair_events_job_version_uq', $db->getIndexData('vehicle_damage_repair_job_events'));
            $this->assertArrayHasKey('repair_items_job_condition_uq', $db->getIndexData('vehicle_damage_repair_job_items'));
            $this->assertArrayHasKey('damage_event_repair_job_event_idx', $db->getIndexData('vehicle_damage_item_events'));
            $reference = array_filter($db->getForeignKeyData('vehicle_damage_item_events'), static fn ($fk): bool => str_ends_with($fk->foreign_table_name, 'vehicle_damage_repair_job_events'));
            $this->assertCount(1, $reference);
            $history = $runner->getHistory('');
            $this->assertTrue($this->throughB21($runner));
            $this->assertEquals($history, $runner->getHistory(''));
            $inventory = [];
            foreach (['vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'vehicle_damage_item_events'] as $table) {
                $inventory[$table] = ['fields' => $db->getFieldData($table), 'indexes' => $db->getIndexData($table), 'foreign_keys' => $db->getForeignKeyData($table)];
            }
            $artifactDirectory = dirname(__DIR__, 2) . '/build/b21-review';
            if (! is_dir($artifactDirectory)) {
                mkdir($artifactDirectory, 0777, true);
            }
            file_put_contents($artifactDirectory . '/migration-' . $engine . '-' . ($upgrade ? 'upgrade' : 'fresh') . '-' . ($prefix ?: 'default') . '.json', json_encode(['preservation' => $preservation, 'before_fields' => $beforeFields, 'after_inventory' => $inventory, 'new_tables' => array_values(array_diff($db->listTables(), array_keys($before), [$db->prefixTable('migrations')]))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $migration = new \App\Database\Migrations\CreateVehicleDamageRepairWork(Database::forge($db));
            for ($populated = 0; $populated < 2; $populated++) {
                if ($populated === 1) {
                    $result = (new \App\Services\Fleet\VehicleDamageRepairService($db))->createJob(1, 10, VehicleDamageRepairDatabaseFixture::creation($db, [$condition]), 7);
                    $this->assertTrue($result['success'], json_encode($result));
                }
                $downBefore = [];
                foreach ($db->listTables() as $table) {
                    $downBefore[$table] = [$db->getFieldNames($table), $db->table($table)->get()->getResultArray()];
                }
                try {
                    $migration->down();
                    $this->fail('Destructive rollback must be refused.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('B2.1 contains append-only work history', $exception->getMessage());
                }
                $this->assertTrue($db->tableExists('vehicle_damage_repair_jobs'));
                foreach ($downBefore as $table => $state) {
                    $this->assertSame($state, [$db->getFieldNames($table), $db->table($table)->get()->getResultArray()], 'Rollback refusal changed ' . $table);
                }
            }
            if ($engine === 'sqlite') {
                $this->assertSame([], $db->query('PRAGMA foreign_key_check')->getResultArray());
            }
        } finally {
            $fixture?->close();
        }
    }
}
