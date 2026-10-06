<?php

use App\Repositories\VehicleDamageRepairDocumentRepository as Documents;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRepository as WorkRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Files\RepairDocumentStorageService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Hard gate: independent connections on guarded disposable MariaDB. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairEstimatesMariaDbTest extends CIUnitTestCase
{
    public static function races(): array
    {
        $cases = ['duplicate', 'revision', 'stale_accept', 'competing_accept', 'expiry_wait', 'withdraw_scope', 'attach_replay', 'archive_race', 'source_order'];
        return array_combine($cases, array_map(fn ($s) => [$s], $cases));
    }

    #[DataProvider('races')]
    public function testIndependentConnectionCommandContention(string $scenario): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture();
        $db = $fixture->db;
        $storedPath = null;
        try {
            $work = new Work($db);
            $repository = new WorkRepository($db);
            $estimates = new Estimates($db);
            $documents = new Documents($db);
            $first = Fixture::condition($db);
            $second = Fixture::condition($db);
            $sourceJob = $work->createJob(1, 10, Fixture::creation($db, [$first]), 7)['id'];
            $job = $work->createJob(1, 10, Fixture::creation($db, [$first, $second]), 7)['id'];
            $command = fn (array $extra = []) => $extra + ['command_key' => Work::commandKey(), 'expected_version' => $repository->job(1, 10, $job)['version']];
            $path = tempnam(sys_get_temp_dir(), 'b22_maria_');
            file_put_contents($path, "%PDF-1.4\n% Synthetic MariaDB source " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
            $sourceData = ['kind_code' => 'estimate', 'upload' => new UploadedFile($path, 'synthetic-maria.pdf', 'application/pdf', filesize($path), UPLOAD_ERR_OK)];
            $sourceCommand = ['command_key' => Work::commandKey(), 'expected_version' => 1, 'document' => $sourceData];
            $source = $work->attachDocument(1, 10, $sourceJob, $sourceCommand, 7);
            $this->assertTrue($source['success'], json_encode($source));
            $sourceDocument = $documents->document(1, 10, $sourceJob, $source['document_id']);
            $storedPath = (new RepairDocumentStorageService($db))->resolve(1, $sourceDocument, $documents->metadata($sourceDocument))['path'];
            $quote = fn (array $extra = []) => $command($extra + ['quote_series_key' => Work::commandKey(), 'recording_mode' => 'current_quote', 'amount' => '123.45', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'vendor_confirmed' => '1', 'date_confirmed' => '1', 'scope_confirmed' => '1', 'vendor_snapshot' => 'Synthetic concurrency vendor', 'quote_date' => '2026-10-06', 'scope_membership_ids' => array_column($repository->members(1, 10, $job), 'id'), 'document' => ['kind_code' => 'estimate', 'source_document_id' => $source['document_id'], 'source_job_id' => $sourceJob, 'source_vehicle_id' => 10]]);
            $created = $work->createEstimate(1, 10, $job, $quote($scenario === 'expiry_wait' ? ['expires_at' => date('Y-m-d H:i:s', time() + 3)] : []), 7);
            $this->assertTrue($created['success'], json_encode($created));
            $estimate = $created['estimate_id'];
            $decision = fn (int $id) => $command(['estimate_id' => $id, 'expected_estimate_state' => $estimates->fingerprint(1, 10, $job, $id), 'confirmed' => '1', 'reason' => 'Synthetic concurrent decision']);
            $payload = $decision($estimate);
            $operation = 'acceptEstimate';
            $replayed = false;
            $succeeds = false;
            $release = null;
            $db->transBegin();
            if ($scenario !== 'source_order') {
                (new VehicleDamageRepository($db))->lockVehicle(1, 10);
            }
            switch ($scenario) {
                case 'duplicate':
                    $operation = 'createEstimate';
                    $payload = $quote();
                    $winner = $work->createEstimate(1, 10, $job, $payload, 7);
                    $this->assertTrue($winner['success'], json_encode($winner));
                    $replayed = $succeeds = true;
                    break;
                case 'revision':
                    $operation = 'createRevision';
                    $old = $estimates->estimate(1, 10, $job, $estimate);
                    $payload = $quote(['quote_series_key' => $old['quote_series_key'], 'previous_estimate_id' => $estimate, 'expected_estimate_state' => $estimates->fingerprint(1, 10, $job, $estimate), 'supersede_previous_confirmed' => '1']);
                    $winner = $work->createRevision(1, 10, $job, $payload, 7);
                    $this->assertTrue($winner['success'], json_encode($winner));
                    $payload['command_key'] = Work::commandKey();
                    break;
                case 'stale_accept':
                    // A metadata change does not increment job version, so this must fail
                    // on the fingerprint read from current locked metadata after the wait.
                    $db->table('files')->where('id', $sourceDocument['file_id'])->update(['original_filename' => 'synthetic-changed-source.pdf']);
                    break;
                case 'competing_accept':
                    $competing = $work->createEstimate(1, 10, $job, $quote(['vendor_snapshot' => 'Synthetic alternative vendor']), 7);
                    $this->assertTrue($competing['success']);
                    $payload = $decision($competing['estimate_id']);
                    $winner = $work->acceptEstimate(1, 10, $job, $decision($estimate), 7);
                    $this->assertTrue($winner['success'], json_encode($winner));
                    break;
                case 'expiry_wait':
                    $expires = $estimates->estimate(1, 10, $job, $estimate)['expires_at'];
                    $this->assertLessThan(strtotime($expires), time());
                    $release = static function () use ($expires): void {
                        while (time() < strtotime($expires)) {
                            usleep(100000);
                        }
                    };
                    break;
                case 'withdraw_scope':
                    $winner = $work->withdrawCondition(1, 10, $job, $command(['membership_id' => $repository->members(1, 10, $job)[0]['id'], 'reason' => 'Synthetic concurrent scope change']), 7);
                    $this->assertTrue($winner['success']);
                    break;
                case 'attach_replay':
                    $operation = 'attachDocument';
                    // A committed upload can replay through its original descriptor without its moved temp file.
                    $job = $sourceJob;
                    $payload = $sourceCommand;
                    unset($payload['document']['upload']);
                    $payload['document']['descriptor'] = $source['source_descriptor'];
                    $replayed = $succeeds = true;
                    break;
                case 'archive_race':
                    $operation = 'archiveDocument';
                    $doc = $created['document_id'];
                    $payload = $command(['document_id' => $doc, 'expected_document_state' => $documents->fingerprint(1, 10, $job, $doc), 'confirmed' => '1', 'reason' => 'Synthetic archive race']);
                    $winner = $work->archiveDocument(1, 10, $job, $payload, 7);
                    $this->assertTrue($winner['success']);
                    $payload['command_key'] = Work::commandKey();
                    break;
                case 'source_order':
                    // Hold only the lower source job. Contender must encounter it before the target job.
                    $repository->job(1, 10, $sourceJob, true);
                    $operation = 'attachDocument';
                    $payload = $command(['document' => ['kind_code' => 'work_order', 'source_document_id' => $source['document_id'], 'source_job_id' => $sourceJob]]);
                    $succeeds = true;
                    break;
            }
            $contender = $fixture->contend($operation, [1, 10, $job, $payload, 7], $release);
            $this->assertTrue($contender['blocked'], json_encode($contender));
            $this->assertSame($succeeds, $contender['result']['success'], json_encode($contender));
            if ($succeeds) {
                $this->assertSame($replayed, $contender['result']['replayed']);
            }
            if ($scenario === 'expiry_wait') {
                $this->assertStringContainsString('expired', implode(' ', $contender['result']['errors']));
            }
            if ($scenario === 'stale_accept') {
                $this->assertStringContainsString('Estimate changed', implode(' ', $contender['result']['errors']));
            }
            if ($scenario === 'source_order') {
                $trace = array_map(static fn (string $sql): string => str_replace('`', '', $sql), $contender['locks']);
                $firstJobLock = array_values(array_filter($trace, static fn (string $sql): bool => str_contains($sql, 'FROM vehicle_damage_repair_jobs jobs')));
                $this->assertStringContainsString('jobs.id = ' . $sourceJob, $firstJobLock[0]);
                $this->assertStringContainsString('jobs.id = ' . $job, $firstJobLock[1]);
            }
            $accepted = array_filter($estimates->estimates(1, 10, $job), fn ($e) => $e['status_code'] === 'accepted');
            $this->assertLessThanOrEqual(1, count($accepted));
            $this->assertSame(count($accepted) === 1, $repository->job(1, 10, $job)['accepted_estimate_id'] !== null);
        } finally {
            $fixture->close();
            if ($storedPath !== null && is_file($storedPath)) {
                unlink($storedPath);
            }
        }
    }

    public function testRealAuditStorageRollbackRetryAndNestedBinaryRejection(): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture();
        $db = $fixture->db;
        $stored = null;
        try {
            $work = new Work($db);
            $job = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7)['id'];
            $bytes = "%PDF-1.4\n% Synthetic rollback " . bin2hex(random_bytes(16)) . "\n%%EOF\n";
            $upload = static function () use ($bytes): UploadedFile {
                $path = tempnam(sys_get_temp_dir(), 'b22_retry_');
                file_put_contents($path, $bytes);
                return new UploadedFile($path, 'synthetic-retry.pdf', 'application/pdf', strlen($bytes), UPLOAD_ERR_OK);
            };
            $data = ['command_key' => Work::commandKey(), 'expected_version' => 1, 'document' => ['kind_code' => 'other', 'upload' => $upload()]];
            $db->transBegin();
            $nested = $work->attachDocument(1, 10, $job, $data, 7);
            $this->assertFalse($nested['success']);
            $this->assertStringContainsString('outer transaction', implode(' ', $nested['errors']));
            $this->assertFileExists($data['document']['upload']->getTempName());
            $db->transRollback();
            $db->query("CREATE TRIGGER synthetic_b22_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic audit failure'");
            (new ReflectionProperty($db, 'DBDebug'))->setValue($db, false);
            $before = glob((new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1/*/*/*') ?: [];
            $failed = $work->attachDocument(1, 10, $job, $data, 7);
            $this->assertFalse($failed['success']);
            $this->assertSame($before, glob((new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1/*/*/*') ?: []);
            $this->assertSame(0, $db->table(Estimates::DOCUMENTS)->countAllResults());
            $db->query('DROP TRIGGER synthetic_b22_audit_failure');
            $data['document']['upload'] = $upload();
            $saved = $work->attachDocument(1, 10, $job, $data, 7);
            $this->assertTrue($saved['success'], json_encode($saved));
            $documents = new Documents($db);
            $doc = $documents->document(1, 10, $job, $saved['document_id']);
            $stored = (new RepairDocumentStorageService($db))->resolve(1, $doc, $documents->metadata($doc))['path'];
            unset($data['document']['upload']);
            $data['document']['descriptor'] = $saved['source_descriptor'];
            $db->transBegin();
            (new VehicleDamageRepository($db))->lockVehicle(1, 10);
            $retry = $fixture->contend('attachDocument', [1, 10, $job, $data, 7]);
            $this->assertTrue($retry['blocked']);
            $this->assertTrue($retry['result']['replayed']);
        } finally {
            $fixture->close();
            if ($stored !== null && is_file($stored)) {
                unlink($stored);
            }
        }
    }
}
