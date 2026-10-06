<?php

use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Services\Fleet\VehicleDamageRepairService;
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

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairEstimatesMigrationTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return [['sqlite', false, ''], ['sqlite', true, ''], ['sqlite', true, 'x_'], ['mariadb', false, ''], ['mariadb', true, ''], ['mariadb', true, 'x_']];
    }

    #[DataProvider('engines')]
    public function testExactSchemaAndAcceptedBaselinePreservation(string $engine, bool $upgrade, string $prefix): void
    {
        $fixture = null;
        if ($engine === 'mariadb') {
            $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'), 'Real disposable MariaDB is a required B2.2 gate.');
            $fixture = new VehicleDamageRepairMariaDbFixture(false, $prefix, $upgrade ? 30 : 31);
            $db = $fixture->db;
        } else {
            $db = Database::connect('tests', false);
            $db->setPrefix($prefix);
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
                if (! $upgrade || strcmp(basename($path), '2026-10-06-000031') < 0) {
                    $runner->force($path, 'App');
                }
            }
            VehicleDamageDatabaseFixture::seed($db);
        }
        try {
            $before = [];
            $inventory = [];
            if ($upgrade) {
                $this->assertFalse((new Estimates($db))->ready());
                $this->assertSame(['work' => true, 'estimates_documents' => false], (new VehicleDamageRepairService($db))->readiness());
                $work = new VehicleDamageRepairService($db);
                $created = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
                $this->assertTrue($created['success'], json_encode($created));
                foreach ($db->listTables() as $table) {
                    if (str_ends_with($table, 'migrations')) {
                        continue;
                    }
                    $before[$table] = $db->table($table)->get()->getResultArray();
                    $inventory[$table] = [$db->getIndexData($table), $db->getForeignKeyData($table)];
                }
            }
            $runner = (new MigrationRunner(new Migrations(), $db))->setNamespace('App');
            $this->assertTrue($runner->latest());
            $db->resetDataCache();
            $repo = new Estimates($db);
            $this->assertTrue($repo->ready());
            foreach (Estimates::FIELDS as $table => $fields) {
                $this->assertEqualsCanonicalizing(explode(' ', $fields), $db->getFieldNames($table));
                $this->assertSame(0, $db->table($table)->countAllResults());
            }
            foreach ($before as $table => $rows) {
                $after = $db->table($table)->get()->getResultArray();
                if (str_ends_with($table, 'vehicle_damage_repair_jobs')) {
                    foreach ($after as &$row) {
                        $this->assertNull($row['accepted_estimate_id']);
                        unset($row['accepted_estimate_id']);
                    } unset($row);
                }
                $this->assertSame($rows, $after, $table);
                foreach ($inventory[$table][0] as $name => $index) {
                    $this->assertEquals($index, $db->getIndexData($table)[$name]);
                }
                foreach ($inventory[$table][1] as $foreign) {
                    $this->assertTrue(array_any($db->getForeignKeyData($table), fn ($fk) => $fk == $foreign), $table);
                }
            }
            $history = $runner->getHistory('');
            $runner->latest();
            $this->assertEquals($history, $runner->getHistory(''));
            $failedForge = $this->getMockBuilder(Database::forge($db)::class)->setConstructorArgs([$db])->onlyMethods(['processIndexes'])->getMock();
            $failedForge->expects($this->once())->method('processIndexes')->with('vehicle_damage_repair_job_items')->willReturn(false);
            $debugProperty = new ReflectionProperty($db, 'DBDebug');
            $debug = $debugProperty->getValue($db);
            $debugProperty->setValue($db, false);
            try {
                (new \App\Database\Migrations\CreateVehicleDamageRepairEstimates($failedForge))->up();
                $this->fail('A false DDL result must stop migration completion.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Inspect the partial schema', $exception->getMessage());
            } finally {
                $this->assertFalse($db->DBDebug, 'Migration restores the caller connection error setting.');
                $debugProperty->setValue($db, $debug);
            }
            $this->assertEquals($history, $runner->getHistory(''));
            $migration = new \App\Database\Migrations\CreateVehicleDamageRepairEstimates(Database::forge($db));
            foreach ([false, true] as $populated) {
                if ($populated) {
                    if (! $upgrade) {
                        $created = (new VehicleDamageRepairService($db))->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
                    }
                    $job = (int) $created['id'];
                    $result = (new VehicleDamageRepairService($db))->createEstimate(1, 10, $job, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 1, 'quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => 'historical_incomplete', 'amount' => '0', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'historical_recording_reason' => 'Synthetic baseline quote', 'scope_membership_ids' => array_column((new \App\Repositories\VehicleDamageRepairRepository($db))->members(1, 10, $job), 'id')], 7);
                    $this->assertTrue($result['success'], json_encode($result));
                    $this->assertSame('0.00', $repo->estimate(1, 10, $job, $result['estimate_id'])['amount']);
                }
                try {
                    $migration->down();
                    $this->fail('Append-only rollback must refuse.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
                $this->assertTrue($repo->ready());
            }
            $this->assertAcceptedOwnership($db, $job, (int) $result['estimate_id']);
            if ($engine === 'sqlite') {
                $index = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND name LIKE '%b22_doc_scope_idx'")->getRowArray();
                $this->assertNotNull($index);
                $db->query('DROP INDEX ' . $db->escapeIdentifiers($index['name']));
                $this->assertFalse($repo->ready());
                $db->query('CREATE INDEX ' . $db->escapeIdentifiers($index['name']) . ' ON ' . $db->escapeIdentifiers($db->prefixTable(Estimates::DOCUMENTS)) . ' (id)');
                $this->assertFalse($repo->ready());
                $db->query('DROP INDEX ' . $db->escapeIdentifiers($index['name']));
                $db->query($index['sql']);
                $this->assertTrue($repo->ready());
                $db->query('DROP TRIGGER ' . $db->prefixTable('b22_job_accepted_update'));
                $this->assertFalse($repo->ready());
                $db->query('CREATE TRIGGER ' . $db->escapeIdentifiers($db->prefixTable('b22_job_accepted_update')) . ' BEFORE UPDATE ON ' . $db->escapeIdentifiers($db->prefixTable('vehicle_damage_repair_jobs')) . ' BEGIN SELECT 1; END');
                $this->assertFalse($repo->ready());
                $this->assertTrue((new \App\Repositories\VehicleDamageRepairRepository($db))->ready());
                $this->assertSame(['work' => true, 'estimates_documents' => false], (new VehicleDamageRepairService($db))->readiness());
            } else {
                $db->query('ALTER TABLE ' . $db->escapeIdentifiers($db->prefixTable(Estimates::DOCUMENTS)) . ' DROP FOREIGN KEY b22_doc_file_id_fk');
                $db->resetDataCache();
                $this->assertFalse($repo->ready());
                $this->assertTrue((new \App\Repositories\VehicleDamageRepairRepository($db))->ready());
                $db->query('ALTER TABLE ' . $db->escapeIdentifiers($db->prefixTable(Estimates::DOCUMENTS)) . ' ADD CONSTRAINT b22_doc_file_id_fk FOREIGN KEY (file_id) REFERENCES ' . $db->escapeIdentifiers($db->prefixTable('files')) . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
                $db->resetDataCache();
                $this->assertTrue($repo->ready());
            }
        } finally {
            if ($fixture !== null) {
                $fixture->close();
            }
        }
    }

    private function assertAcceptedOwnership(\CodeIgniter\Database\BaseConnection $db, int $job, int $ownEstimate): void
    {
        $work = new VehicleDamageRepairService($db);
        $repository = new \App\Repositories\VehicleDamageRepairRepository($db);
        $quote = function (int $company, int $vehicle, int $jobId, string $amount) use ($db, $work, $repository): int {
            $result = $work->createEstimate($company, $vehicle, $jobId, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => $repository->job($company, $vehicle, $jobId)['version'], 'quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => 'historical_incomplete', 'amount' => $amount, 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'historical_recording_reason' => 'Synthetic ownership source', 'scope_membership_ids' => array_column($repository->members($company, $vehicle, $jobId), 'id')], 7);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertSame($amount, (new Estimates($db))->estimate($company, $vehicle, $jobId, $result['estimate_id'])['amount']);
            return (int) $result['estimate_id'];
        };
        $quote(1, 10, $job, '9999999999.99');
        $otherJob = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
        $this->assertTrue($otherJob['success']);
        $otherEstimate = $quote(1, 10, (int) $otherJob['id'], '1.00');
        $foreignJob = $work->createJob(2, 20, Fixture::creation($db, [Fixture::condition($db, 2, 20)]), 7);
        $this->assertTrue($foreignJob['success']);
        $foreignEstimate = $quote(2, 20, (int) $foreignJob['id'], '1.00');
        // These direct fixture writes test DB ownership, independently of service disposition rules.
        $this->assertTrue($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['accepted_estimate_id' => $ownEstimate]));
        $this->assertSame($ownEstimate, (int) $repository->job(1, 10, $job)['accepted_estimate_id']);
        $this->assertTrue($db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['accepted_estimate_id' => null]));
        $prototype = $repository->job(1, 10, $job);
        foreach ([$otherEstimate, $foreignEstimate, 999999999] as $offset => $invalid) {
            foreach (['update', 'insert'] as $operation) {
                try {
                    $data = array_replace($prototype, ['id' => 900001 + $offset, 'creation_command_key' => VehicleDamageRepairService::commandKey(), 'accepted_estimate_id' => $invalid]);
                    $result = $operation === 'update'
                        ? $db->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['accepted_estimate_id' => $invalid])
                        : $db->table('vehicle_damage_repair_jobs')->insert($data);
                    $this->assertFalse($result, 'Foreign accepted authority must fail.');
                    $this->assertMatchesRegularExpression('/foreign key|Accepted estimate must/i', $db->error()['message']);
                } catch (\CodeIgniter\Database\Exceptions\DatabaseException $exception) {
                    $this->assertMatchesRegularExpression('/foreign key|Accepted estimate must/i', $exception->getMessage());
                } finally {
                    $db->resetTransStatus();
                }
                $this->assertNull($repository->job(1, 10, $job)['accepted_estimate_id']);
                $this->assertNull($repository->job(1, 10, 900001 + $offset));
            }
        }
        $this->assertTrue($db->table('vehicle_damage_repair_jobs')->insert(array_replace($prototype, ['id' => 900010, 'creation_command_key' => VehicleDamageRepairService::commandKey(), 'accepted_estimate_id' => null])));
        $this->assertNull($repository->job(1, 10, 900010)['accepted_estimate_id']);
    }
}
