<?php

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageRepairRecoveryService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairRecoveryTestCase as RecoveryFixture;

/** @internal Real COMMIT precedes the fault; independent connection verifies retained evidence/history. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairRecoveriesLostAckTest extends CIUnitTestCase
{
    public static function commands(): array
    {
        return [['receipt'], ['reversal'], ['replacement'], ['void'], ['finalize'], ['invalidate']];
    }

    #[DataProvider('commands')]
    public function testLostAckRetainsBinaryAndSameKeyReplaysWithoutAnotherWrite(string $case): void
    {
        $path = tempnam(dirname(__DIR__, 2) . '/build/', 'b31_synthetic_sqlite_');
        $paths = [$path];
        $db = $independent = $listener = null;
        try {
            $config = (new Database())->tests;
            $config['database'] = $path;
            $db = Database::connect($config, false);
            (new MigrationRunner(new Migrations(), $db))->setNamespace('App')->latest();
            VehicleDamageDatabaseFixture::seed($db);
            $work = new Work($db);
            $repo = new VehicleDamageRepairRepository($db);
            $helper = new VehicleDamageRepairRecoveryService($db);
            $job = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7)['id'];
            $upload = function () use (&$paths): UploadedFile {
                $tmp = tempnam(sys_get_temp_dir(), 'b31_synthetic_ack_');
                $paths[] = $tmp;
                file_put_contents($tmp, "%PDF-1.4\n% Synthetic recovery acknowledgement " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
                return new UploadedFile($tmp, 'synthetic-recovery-ack.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK);
            };
            $facts = RecoveryFixture::facts();
            $method = 'recordRecovery';
            if ($case !== 'receipt') {
                $first = $work->recordRecovery(1, 10, $job, $facts + ['expected_version' => 1, 'command_key' => Work::commandKey(), 'document' => ['kind_code' => 'recovery_payment', 'upload' => $upload()]], 7);
                $this->assertTrue($first['success'], json_encode($first));
                if ($case === 'reversal') {
                    $facts['kind_code'] = 'recovery_reversal';
                    $facts['related_recovery_entry_id'] = $first['recovery_entry_id'];
                    $facts['amount'] = '20.00';
                    $facts['source_reference'] = 'SYNTHETIC-REVERSAL';
                } elseif ($case === 'replacement') {
                    $method = 'replaceRecovery';
                    $facts['recovery_entry_id'] = $first['recovery_entry_id'];
                    $facts['expected_entry_state'] = $helper->entryFingerprint(1, 10, $job, $first['recovery_entry_id']);
                    $facts['amount'] = '90.00';
                    $facts['reason'] = 'Synthetic monetary correction';
                } elseif ($case === 'void') {
                    $method = 'voidRecovery';
                    $facts = ['confirmed' => '1', 'recovery_entry_id' => $first['recovery_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $job, $first['recovery_entry_id']), 'reason' => 'Synthetic erroneous receipt'];
                } else {
                    $facts = ['confirmed' => '1', 'completeness_confirmed' => '1', 'expected_ledger_state' => $helper->ledgerFingerprint(1, 10, $job), 'note' => 'Synthetic completeness'];
                    $method = 'finalizeRecovery';
                    if ($case === 'invalidate') {
                        $finalized = $work->finalizeRecovery(1, 10, $job, $facts + ['expected_version' => $repo->job(1, 10, $job)['version'], 'command_key' => Work::commandKey()], 7);
                        $this->assertTrue($finalized['success'], json_encode($finalized));
                        $method = 'invalidateRecoveryFinalization';
                        $facts = ['confirmed' => '1', 'expected_finalization_state' => $helper->finalizationFingerprint(1, 10, $job), 'reason' => 'Synthetic completeness review'];
                    }
                }
            }
            $binaryFlow = in_array($case, ['receipt', 'reversal', 'replacement'], true);
            $selected = $binaryFlow ? $upload() : null;
            $descriptor = $selected === null ? null : $helper->sources->documents->storage->descriptor($selected);
            $data = $facts + ['expected_version' => $repo->job(1, 10, $job)['version'], 'command_key' => Work::commandKey()];
            if ($selected !== null) {
                $data['document'] = ['kind_code' => \Config\VehicleDamageRepairRecoveries::DOCUMENT_KINDS[$facts['kind_code']], 'upload' => $selected, 'descriptor' => $descriptor];
            }
            $tables = ['files', 'vehicle_damage_repair_documents', Recoveries::TABLE, 'vehicle_damage_repair_job_events', 'audit_logs', 'vehicle_damage_repair_jobs'];
            $beforeCommand = [];
            foreach ($tables as $table) {
                $beforeCommand[$table] = $db->table($table)->countAllResults();
            }
            $injected = false;
            $listener = static function (Query $query) use (&$injected): void {
                if (! $injected && trim(strtoupper($query->getQuery())) === 'COMMIT') {
                    $injected = true;
                    throw new RuntimeException('Synthetic acknowledgement lost AFTER COMMIT');
                }
            };
            Events::on('DBQuery', $listener);
            $uncertain = $work->{$method}(1, 10, $job, $data, 7);
            $this->assertTrue($injected);
            $this->assertFalse($uncertain['success']);
            $this->assertTrue($uncertain['uncertain']);
            $this->assertSame($data['command_key'], $uncertain['retry_payload']['command_key']);
            if ($binaryFlow) {
                $this->assertArrayNotHasKey('upload', $uncertain['retry_payload']['document']);
            } else {
                $this->assertArrayNotHasKey('document', $uncertain['retry_payload']);
            }
            $independent = Database::connect($config, false);
            $entry = $independent->table(Recoveries::TABLE)->orderBy('id', 'DESC')->get(1)->getRowArray();
            $this->assertNotNull($entry);
            $this->assertSame($facts['amount'] ?? '100.00', $entry['amount']);
            $this->assertSame($case === 'void' ? 'voided' : 'recorded', $entry['status_code']);
            $doc = $helper->verifyDocument(1, 10, $job, (int) $entry['repair_document_id'], $entry['kind_code']);
            $stored = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc))['path'];
            $this->assertFileExists($stored);
            if ($selected !== null) {
                $this->assertFileDoesNotExist($selected->getTempName());
            }
            $committedJob = $independent->table('vehicle_damage_repair_jobs')->where('id', $job)->get()->getRowArray();
            $this->assertSame((int) $data['expected_version'] + 1, (int) $committedJob['version']);
            $this->assertSame($case === 'finalize', $committedJob['recovery_finalized_at'] !== null);
            $event = $independent->table('vehicle_damage_repair_job_events')->where('command_key', $data['command_key'])->get()->getRowArray();
            $this->assertSame(match ($case) {
                'receipt' => 'repair_recovery_recorded', 'reversal' => 'repair_recovery_reversed', 'replacement' => 'repair_recovery_replaced',
                'void' => 'repair_recovery_voided', 'finalize' => 'repair_recovery_finalized', 'invalidate' => 'repair_recovery_finalization_invalidated',
                default => throw new InvalidArgumentException('Unknown synthetic recovery command.'),
            }, $event['event_code']);
            $auditDelta = match ($case) {
                'replacement' => 4, 'receipt', 'reversal' => 3, 'void' => 2, default => 1
            };
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $independent->table($table)->orderBy('id')->get()->getResultArray();
                $expectedDelta = match ($table) {
                    'files', 'vehicle_damage_repair_documents', Recoveries::TABLE => $binaryFlow ? 1 : 0,
                    'vehicle_damage_repair_job_events' => 1,
                    'audit_logs' => $auditDelta,
                    default => 0,
                };
                $this->assertCount($beforeCommand[$table] + $expectedDelta, $before[$table], $table);
            }
            $replay = $work->{$method}(1, 10, $job, $uncertain['retry_payload'], 7);
            $this->assertTrue($replay['success'], json_encode($replay));
            $this->assertTrue($replay['replayed']);
            $this->assertSame((int) $committedJob['version'], $replay['version']);
            if ($binaryFlow || $case === 'void') {
                $this->assertSame((int) $entry['id'], $replay['recovery_entry_id']);
            }
            if ($binaryFlow) {
                $this->assertSame($descriptor, $replay['source_descriptor']);
            }
            $this->assertSame($helper::semantic($data), $helper::semantic($uncertain['retry_payload']));
            foreach ($tables as $table) {
                $this->assertSame($before[$table], $independent->table($table)->orderBy('id')->get()->getResultArray(), $table);
            }
        } finally {
            if ($listener !== null) {
                Events::removeListener('DBQuery', $listener);
            }
            if ($db !== null) {
                $helper = new VehicleDamageRepairRecoveryService($db);
                foreach ($db->table('vehicle_damage_repair_documents')->get()->getResultArray() as $doc) {
                    $resolved = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc));
                    if ($resolved !== null) {
                        $paths[] = $resolved['path'];
                    }
                }
            }
            $independent?->close();
            $db?->close();
            foreach (array_unique($paths) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
