<?php

use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageMigrationTest extends CIUnitTestCase
{
    public function testFullFreshChainRerunAndRefusedRollbackPreserveMigrationState(): void
    {
        $db = Database::connect('tests', false);
        $runner = new MigrationRunner(new Migrations(), $db);
        $runner->setNamespace('App');
        $this->assertTrue($runner->latest());
        $history = $runner->getHistory('tests');
        $this->assertCount(count(glob(__DIR__ . '/../../app/Database/Migrations/*.php')), $history);
        $this->assertTrue($db->tableExists('vehicle_damage_incidents'));
        $this->assertTrue($db->tableExists('vehicle_damage_incident_items'));
        $this->assertTrue($db->fieldExists('panel_code', 'vehicle_damage_items'));
        $this->assertTrue($db->fieldExists('current_condition_item_id', 'vehicle_damage_items'));
        $this->assertCount(5, $db->getForeignKeyData('vehicle_damage_incidents'));
        $this->assertCount(3, $db->getForeignKeyData('vehicle_damage_incident_items'));
        $this->assertArrayHasKey('damage_canonical_idx', $db->getIndexData('vehicle_damage_items'));
        foreach (['damage_claims', 'charging_sessions', 'airport_deliveries'] as $table) {
            $this->assertNotEmpty(array_filter($db->getForeignKeyData($table), static fn ($key): bool => $key->foreign_table_name === $db->prefixTable('turo_trips_normalized')));
        }
        $this->assertTrue($runner->latest());
        $this->assertEquals($history, $runner->getHistory('tests'));
        try {
            $runner->regress(0);
            $this->fail('B1 rollback must refuse to destroy incident history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Restore an approved backup', $exception->getMessage());
        }
        $runner->setNamespace('App');
        $this->assertEquals($history, $runner->getHistory('tests'));
        $this->assertTrue($runner->latest());
        $this->assertTrue($db->tableExists('vehicle_damage_incidents'));
        $this->assertSame([], $db->query('PRAGMA foreign_key_check')->getResultArray());
        foreach ($db->listTables() as $table) {
            foreach ($db->getForeignKeyData($table) as $key) {
                $this->assertTrue($db->tableExists($key->foreign_table_name), $table . ' references missing table ' . $key->foreign_table_name);
            }
        }
        $seeder = new \App\Database\Seeds\LookupSeeder(new Database(), $db);
        $seeder->run();
        $this->assertGreaterThan(0, $db->table('lookup_values')->countAllResults());
    }
}
