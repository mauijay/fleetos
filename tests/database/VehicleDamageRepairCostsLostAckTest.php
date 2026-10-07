<?php

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;

/** @internal Throw only after actual COMMIT, then read through an independent connection. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairCostsLostAckTest extends CIUnitTestCase
{
    public function testLostAcknowledgementPreservesBinaryAndSameKeyRecoversCommittedReceipt(): void
    {
        $path = tempnam(dirname(__DIR__, 2) . '/build/', 'b23_synthetic_sqlite_');
        $temporary = tempnam(sys_get_temp_dir(), 'b23_synthetic_pdf_');
        $stored = null;
        $db = null;
        $independent = null;
        $listener = null;
        try {
            $config = (new Database())->tests;
            $config['database'] = $path;
            $db = Database::connect($config, false);
            (new MigrationRunner(new Migrations(), $db))->setNamespace('App')->latest();
            VehicleDamageDatabaseFixture::seed($db);
            $work = new Work($db);
            $created = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7);
            $this->assertTrue($created['success']);
            $job = $created['id'];
            file_put_contents($temporary, "%PDF-1.4\n% Synthetic lost acknowledgement " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
            $upload = new UploadedFile($temporary, 'synthetic-lost-ack.pdf', 'application/pdf', filesize($temporary), UPLOAD_ERR_OK);
            $helper = new VehicleDamageRepairCostService($db);
            $descriptor = $helper->sources->documents->storage->descriptor($upload);
            $data = ['expected_version' => 1, 'command_key' => Work::commandKey(), 'kind_code' => 'invoice', 'amount' => '123.45', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Lost Ack Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'document' => ['kind_code' => 'invoice', 'upload' => $upload, 'descriptor' => $descriptor]];
            $injected = false;
            $listener = static function (Query $query) use (&$injected): void {
                if (! $injected && trim(strtoupper($query->getQuery())) === 'COMMIT') {
                    $injected = true;
                    throw new RuntimeException('Synthetic acknowledgement lost AFTER COMMIT');
                }
            };
            Events::on('DBQuery', $listener);
            $uncertain = $work->recordCostEntry(1, 10, $job, $data, 7);
            $this->assertTrue($injected);
            $this->assertFalse($uncertain['success']);
            $this->assertTrue($uncertain['uncertain']);
            $this->assertStringContainsString('same command key', $uncertain['errors']['work']);
            $independent = Database::connect($config, false);
            $entry = $independent->table(Costs::TABLE)->get()->getRowArray();
            $this->assertNotNull($entry, 'Actual committed entry is visible to another connection.');
            $this->assertSame('123.45', $entry['amount']);
            $source = $helper->sources->documents->documents->document(1, 10, $job, (int) $entry['repair_document_id']);
            $stored = $helper->sources->documents->storage->resolve(1, $source, $helper->sources->documents->documents->metadata($source))['path'];
            $this->assertFileExists($stored);
            $this->assertFileDoesNotExist($temporary);
            $tables = ['files', 'vehicle_damage_repair_documents', Costs::TABLE, 'vehicle_damage_repair_job_events', 'audit_logs', 'vehicle_damage_repair_jobs'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $independent->table($table)->orderBy('id')->get()->getResultArray();
            }
            $this->assertSame($data['command_key'], $uncertain['retry_payload']['command_key']);
            $this->assertArrayNotHasKey('upload', $uncertain['retry_payload']['document']);
            $this->assertSame(VehicleDamageRepairCostService::semantic($data), VehicleDamageRepairCostService::semantic($uncertain['retry_payload']));
            $replay = $work->recordCostEntry(1, 10, $job, $uncertain['retry_payload'], 7);
            $this->assertTrue($replay['success'], json_encode($replay));
            $this->assertTrue($replay['replayed']);
            $this->assertSame((int) $entry['id'], $replay['cost_entry_id']);
            $this->assertSame($descriptor, $replay['source_descriptor']);
            foreach ($tables as $table) {
                $this->assertSame($before[$table], $independent->table($table)->orderBy('id')->get()->getResultArray(), $table);
            }
            $this->assertFileExists($stored);
        } finally {
            if ($listener !== null) {
                Events::removeListener('DBQuery', $listener);
            }
            $independent?->close();
            $db?->close();
            foreach ([$path, $temporary, $stored] as $file) {
                if ($file !== null && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
