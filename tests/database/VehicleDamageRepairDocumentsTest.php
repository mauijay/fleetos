<?php

use App\Repositories\VehicleDamageRepairDocumentRepository as Documents;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Services\Files\RepairDocumentStorageService;
use App\Services\Fleet\VehicleDamageRepairService;
use CodeIgniter\HTTP\Files\UploadedFile;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairDocumentsTest extends VehicleDamageRepairTestCase
{
    private array $temporary = [];
    private array $stored = [];

    protected function tearDown(): void
    {
        foreach ([...$this->temporary, ...$this->stored] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function upload(string $mime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'b22_doc_');
        if ($mime === 'application/pdf') {
            file_put_contents($path, "%PDF-1.4\n% Synthetic document " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
        } elseif ($mime === 'text/plain') {
            file_put_contents($path, 'Synthetic unsupported content');
        } else {
            $image = imagecreatetruecolor(2, 2);
            match ($mime) {
                'image/png' => imagepng($image, $path), 'image/jpeg' => imagejpeg($image, $path), 'image/webp' => imagewebp($image, $path), default => throw new InvalidArgumentException('Unsupported synthetic MIME')
            };
        }
        $this->temporary[] = $path;
        return new UploadedFile($path, 'synthetic-source.' . ($mime === 'application/pdf' ? 'pdf' : 'image'), $mime, filesize($path), UPLOAD_ERR_OK);
    }

    private function attach(int $job, array $data): array
    {
        $result = $this->work->attachDocument(1, 10, $job, $this->command($job, ['document' => $data]), 7);
        if ($result['success']) {
            $repo = new Documents($this->connection);
            $doc = $repo->document(1, 10, $job, $result['document_id']);
            $binary = (new RepairDocumentStorageService($this->connection))->resolve(1, $doc, $repo->metadata($doc));
            if ($binary !== null) {
                $this->stored[] = $binary['path'];
            }
        }
        return $result;
    }

    public function testVerifiedPdfImagesAndOwnedDownloadIdentity(): void
    {
        $job = $this->createWork([$this->condition()]);
        $repo = new Documents($this->connection);
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp'] as $mime) {
            $result = $this->attach($job, ['kind_code' => $mime === 'application/pdf' ? 'estimate' : 'before_photo', 'upload' => $this->upload($mime)]);
            $this->success($result);
            $doc = $repo->document(1, 10, $job, $result['document_id']);
            $this->assertSame($mime, $doc['content_mime_type']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $doc['content_checksum']);
            $this->assertNull($repo->document(2, 10, $job, (int) $doc['id']));
            $this->assertNull($repo->document(1, 11, $job, (int) $doc['id']));
            $this->assertNull($repo->document(1, 10, 999, (int) $doc['id']));
            $metadata = $repo->metadata($doc);
            $storage = new RepairDocumentStorageService($this->connection);
            $this->assertNotNull($storage->resolve(1, $doc, $metadata));
            foreach ([['deleted_at' => '2026-10-07 00:00:00'], ['path' => 'repair-documents/company-2/a.pdf'], ['path' => '../synthetic.pdf'], ['checksum' => str_repeat('0', 64)], ['size_bytes' => 1], ['mime_type' => 'text/html']] as $change) {
                $this->assertNull($storage->resolve(1, $doc, array_replace($metadata, $change)));
            }
        }
    }

    public function testSharedStoragePreservesExistingExpenseReceiptAndDamageEvidenceCallers(): void
    {
        $files = new \App\Repositories\FileRepository($this->connection);
        $storage = new \App\Services\Files\PrivateEvidenceStorageService($files);
        $expenses = new \App\Services\Fleet\OperatingExpenseService(
            new \App\Repositories\OperatingExpenseRepository($this->connection),
            new \App\Repositories\AuditLogRepository($this->connection),
            new \App\Repositories\LookupRepository($this->connection),
            $storage,
        );
        $upload = $this->upload();
        $bytes = file_get_contents($upload->getTempName());
        $receipt = $expenses->uploadReceipt(1, $upload, ['document_date' => '2026-10-06'], 7);
        $this->success($receipt);
        $resolved = $expenses->receiptFile(1, $receipt['receipt_id']);
        $this->stored[] = $resolved['path'];
        $this->assertSame($bytes, file_get_contents($resolved['path']));
        $duplicate = $this->upload();
        file_put_contents($duplicate->getTempName(), $bytes);
        $reused = $expenses->uploadReceipt(1, $duplicate, ['document_date' => '2026-10-06'], 7);
        $this->success($reused);
        $this->assertTrue($reused['duplicate_evidence']);
        $this->assertSame($receipt['receipt_id'], $reused['receipt_id']);

        // Older shared metadata may have neither checksum nor size populated.
        $row = $this->connection->table('operating_expense_receipts')->where('id', $receipt['receipt_id'])->get()->getRowArray();
        $this->connection->table('files')->where('id', $row['file_id'])->update(['checksum' => null, 'size_bytes' => null]);
        $this->assertSame($bytes, file_get_contents($expenses->receiptFile(1, $receipt['receipt_id'])['path']));
        $this->connection->table('vehicle_files')->insert(['fleet_vehicle_id' => 10, 'file_id' => $row['file_id'], 'file_type_lookup_value_id' => (new \App\Repositories\LookupRepository($this->connection))->valueId('file_type', 'claim')]);
        $condition = $this->condition();
        $linked = $this->damage->attachEvidence(1, 10, $condition, ['file_id' => $row['file_id'], 'label' => 'Synthetic retained private evidence'], 7);
        $this->success($linked);
        $evidence = $this->conditions->evidence(1, $condition);
        $this->assertSame((int) $row['file_id'], (int) $evidence[0]['file_id']);
        $this->assertSame($bytes, file_get_contents($expenses->receiptFile(1, $receipt['receipt_id'])['path']));
    }

    public function testExternalReferencesKindsXorAndContextFailClosed(): void
    {
        $job = $this->createWork([$this->condition()]);
        foreach (['https://example.invalid/synthetic-source', 'Synthetic source reference'] as $reference) {
            $this->success($this->attach($job, ['kind_code' => 'other', 'external_reference' => $reference]));
        }
        foreach (['javascript:alert(1)', 'file:///synthetic', 'data:text/html,hi', 'C:\\synthetic\\file', '/synthetic/file', '../file', "source\nreference", 'http://example.invalid'] as $reference) {
            $this->failure($this->attach($job, ['kind_code' => 'other', 'external_reference' => $reference]));
        }
        foreach ([['kind_code' => 'invoice', 'external_reference' => 'Synthetic'], ['kind_code' => 'before_photo', 'external_reference' => 'Synthetic'], ['kind_code' => 'other', 'file_id' => 1], ['kind_code' => 'other', 'image_id' => 1], ['kind_code' => 'other'], ['kind_code' => 'other', 'membership_id' => 999, 'external_reference' => 'Synthetic'], ['kind_code' => 'other', 'estimate_id' => 999, 'external_reference' => 'Synthetic']] as $invalid) {
            $this->failure($this->attach($job, $invalid));
        }
        $this->failure($this->attach($job, ['kind_code' => 'other', 'upload' => $this->upload(), 'external_reference' => 'Synthetic']));
        $this->failure($this->attach($job, ['kind_code' => 'other', 'upload' => $this->upload('text/plain')]));
        $huge = $this->upload();
        $stream = fopen($huge->getTempName(), 'ab');
        ftruncate($stream, 10485761);
        fclose($stream);
        $this->failure($this->attach($job, ['kind_code' => 'other', 'upload' => $huge]));
    }

    public function testValidatedDedupeKeepsSeparateBusinessDocumentsAndRejectsCorruption(): void
    {
        $job = $this->createWork([$this->condition()]);
        $upload = $this->upload();
        $bytes = file_get_contents($upload->getTempName());
        $first = $this->attach($job, ['kind_code' => 'other', 'upload' => $upload]);
        $this->success($first);
        $next = $this->upload();
        file_put_contents($next->getTempName(), $bytes);
        $second = $this->attach($job, ['kind_code' => 'work_order', 'upload' => $next]);
        $this->success($second);
        $repo = new Documents($this->connection);
        $a = $repo->document(1, 10, $job, $first['document_id']);
        $b = $repo->document(1, 10, $job, $second['document_id']);
        $this->assertSame($a['file_id'], $b['file_id']);
        $this->assertNotSame($a['id'], $b['id']);
        file_put_contents($this->stored[0], 'Synthetic corruption');
        $third = $this->upload();
        file_put_contents($third->getTempName(), $bytes);
        $this->failure($this->attach($job, ['kind_code' => 'other', 'upload' => $third]), 'candidate');
        $this->assertNull((new RepairDocumentStorageService($this->connection))->resolve(1, $a, $repo->metadata($a)));
    }

    public function testArchiveFingerprintRetentionAndImmutableCorrection(): void
    {
        $job = $this->createWork([$this->condition()]);
        $attached = $this->attach($job, ['kind_code' => 'other', 'upload' => $this->upload()]);
        $this->success($attached);
        $repo = new Documents($this->connection);
        $id = $attached['document_id'];
        $base = ['document_id' => $id, 'expected_document_state' => $repo->fingerprint(1, 10, $job, $id), 'confirmed' => '1', 'reason' => 'Synthetic correction'];
        $this->failure($this->work->archiveDocument(1, 10, $job, $this->command($job, array_replace($base, ['expected_document_state' => 'stale'])), 7), 'changed');
        $command = $this->command($job, $base);
        $this->success($this->work->archiveDocument(1, 10, $job, $command, 7));
        $this->assertTrue($this->work->archiveDocument(1, 10, $job, $command, 7)['replayed']);
        $doc = $repo->document(1, 10, $job, $id);
        $this->assertSame('Synthetic correction', $doc['archive_reason']);
        $this->assertNotNull((new RepairDocumentStorageService($this->connection))->resolve(1, $doc, $repo->metadata($doc)));
        $this->failure($this->work->archiveDocument(1, 10, $job, $this->command($job, $base), 7), 'archived');
        $this->success($this->attach($job, ['kind_code' => 'other', 'external_reference' => 'Synthetic corrected reference']));
        $this->assertSame($doc, $repo->document(1, 10, $job, $id));
    }

    public function testDocumentPairMustBelongToTheFrozenSubsetAndRawIdsNeverAuthorize(): void
    {
        $job = $this->createWork([$this->condition(), $this->condition()]);
        $members = $this->repairs->members(1, 10, $job);
        $quote = $this->work->createEstimate(1, 10, $job, $this->command($job, ['quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => 'historical_incomplete', 'amount' => '25.00', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'historical_recording_reason' => 'Synthetic partial source', 'scope_membership_ids' => [$members[0]['id']]]), 7);
        $this->success($quote);
        $this->failure($this->attach($job, ['kind_code' => 'other', 'estimate_id' => $quote['estimate_id'], 'membership_id' => $members[1]['id'], 'external_reference' => 'Synthetic reference']), 'frozen');
        $this->success($this->attach($job, ['kind_code' => 'other', 'estimate_id' => $quote['estimate_id'], 'membership_id' => $members[0]['id'], 'external_reference' => 'Synthetic reference']));
        $other = $this->createWork([$this->condition()]);
        $this->failure($this->attach($other, ['kind_code' => 'other', 'estimate_id' => $quote['estimate_id'], 'external_reference' => 'Synthetic reference']), 'context');
        $this->failure($this->attach($other, ['kind_code' => 'other', 'membership_id' => $members[0]['id'], 'external_reference' => 'Synthetic reference']), 'context');
    }

    public function testCompanyRootSymlinkFailsBeforeMovingBytes(): void
    {
        $directory = (new \Config\Paths())->writableDirectory . '/uploads/repair-documents';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $company = random_int(8800000, 8899999);
        $link = $directory . '/company-' . $company;
        $target = dirname(__DIR__, 2) . '/build/b22-review/synthetic-link-' . bin2hex(random_bytes(8));
        mkdir($target, 0700, true);
        if (DIRECTORY_SEPARATOR === '\\') {
            $pipes = [];
            $process = proc_open('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $target) . '"', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), 'Synthetic junction creation must succeed: ' . $output . $error);
        } else {
            $this->assertTrue(symlink($target, $link));
        }
        try {
            $upload = $this->upload();
            $storage = new RepairDocumentStorageService($this->connection);
            $descriptor = $storage->descriptor($upload);
            try {
                $storage->store($company, $upload, $descriptor, 7);
                $this->fail('A symlink company root must be rejected.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('unsafe path component', $e->getMessage());
            }
            $this->assertFileExists($upload->getTempName());
            $this->assertSame([], glob($target . '/*'));
        } finally {
            if (DIRECTORY_SEPARATOR === '\\') {
                rmdir($link);
            } else {
                unlink($link);
            }
            rmdir($target);
        }
    }

    public function testAuditFailureRollsBackRowsAndOnlyNewBytes(): void
    {
        $job = $this->createWork([$this->condition()]);
        $this->connection->query("CREATE TRIGGER synthetic_b22_audit_failure BEFORE INSERT ON db_audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $upload = $this->upload();
        $filesBefore = glob((new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1/*/*/*') ?: [];
        $before = $this->counts();
        $failed = $this->work->attachDocument(1, 10, $job, $this->command($job, ['document' => ['kind_code' => 'other', 'upload' => $upload]]), 7);
        $this->failure($failed);
        $this->assertSame($before, $this->counts());
        $this->assertSame($filesBefore, glob((new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1/*/*/*') ?: []);
        $this->assertSame(0, $this->connection->table(Estimates::DOCUMENTS)->countAllResults());
    }

    public function testMetadataReloadFailureRemovesOnlyTheNewUncommittedBinary(): void
    {
        $files = $this->getMockBuilder(\App\Repositories\FileRepository::class)
            ->setConstructorArgs([$this->connection])->onlyMethods(['find'])->getMock();
        $files->expects($this->once())->method('find')->willThrowException(new RuntimeException('Synthetic metadata reload failure'));
        $storage = new \App\Services\Files\PrivateEvidenceStorageService($files);
        $directory = (new \Config\Paths())->writableDirectory . '/uploads/repair-documents/company-1';
        $beforeBytes = glob($directory . '/*/*/*') ?: [];
        $beforeRows = $this->connection->table('files')->countAllResults();
        $upload = $this->upload();
        $this->connection->query('BEGIN IMMEDIATE');
        try {
            $storage->store($upload, 'repair-documents/company-1', ['application/pdf'], 10485760, null, 7, true, 0);
            $this->fail('Metadata reload failure must abort the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic metadata reload failure', $exception->getMessage());
            $this->assertSame($beforeRows + 1, $this->connection->table('files')->countAllResults());
            $this->assertSame($beforeBytes, glob($directory . '/*/*/*') ?: []);
        } finally {
            $this->connection->query('ROLLBACK');
        }
        $this->assertSame($beforeRows, $this->connection->table('files')->countAllResults());
        $this->assertFileDoesNotExist($upload->getTempName());
    }

    public function testSameBytesStayCompanyScopedAndDedupeRollbackPreservesCommittedBytes(): void
    {
        $job = $this->createWork([$this->condition()]);
        $upload = $this->upload();
        $bytes = file_get_contents($upload->getTempName());
        $first = $this->attach($job, ['kind_code' => 'other', 'upload' => $upload]);
        $this->success($first);
        $documents = new Documents($this->connection);
        $original = $documents->document(1, 10, $job, $first['document_id']);
        $this->connection->query("CREATE TRIGGER synthetic_b22_reuse_audit_failure BEFORE INSERT ON db_audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic reuse audit failure'); END");
        $retry = $this->upload();
        file_put_contents($retry->getTempName(), $bytes);
        $this->failure($this->attach($job, ['kind_code' => 'work_order', 'upload' => $retry]));
        $this->assertNotNull((new RepairDocumentStorageService($this->connection))->resolve(1, $original, $documents->metadata($original)));
        $this->assertCount(1, $documents->documents(1, 10, $job));
        $this->connection->query('DROP TRIGGER synthetic_b22_reuse_audit_failure');
        $otherCondition = $this->condition(2, 20);
        $other = $this->work->createJob(2, 20, \Tests\Support\VehicleDamageRepairDatabaseFixture::creation($this->connection, [$otherCondition]), 7);
        $this->success($other);
        $crossUpload = $this->upload();
        file_put_contents($crossUpload->getTempName(), $bytes);
        $created = $this->work->attachDocument(2, 20, $other['id'], ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 1, 'document' => ['kind_code' => 'other', 'upload' => $crossUpload]], 7);
        $this->success($created);
        $cross = $documents->document(2, 20, $other['id'], $created['document_id']);
        $this->assertNotSame($original['file_id'], $cross['file_id']);
        $resolved = (new RepairDocumentStorageService($this->connection))->resolve(2, $cross, $documents->metadata($cross));
        $this->assertNotNull($resolved);
        $this->stored[] = $resolved['path'];
        $this->assertNull((new RepairDocumentStorageService($this->connection))->resolve(1, $cross, $documents->metadata($cross)));
    }

    public function testFinancialAndPhysicalBoundaryHashesAcrossQuoteAndDocumentLifecycle(): void
    {
        $job = $this->createWork([$this->condition()]);
        $activity = new \App\Services\Fleet\FinancialActivityReadService(new \App\Repositories\TuroNormalizedTransactionRepository($this->connection), new \App\Repositories\TripMonthAllocationRepository($this->connection), new \App\Repositories\OperatingExpenseRepository($this->connection), new \App\Repositories\MaintenanceCostRepository($this->connection), new \App\Repositories\ChargingCostRepository($this->connection), new \App\Repositories\TuroAccessReimbursementRepository($this->connection));
        $financial = new \App\Services\Fleet\VehicleFinancialSummaryService(new \App\Services\Fleet\FinancialSummaryService($activity), new \App\Repositories\FleetVehicleRepository($this->connection));
        $financialBefore = $financial->period(1, '2026-10-01', '2026-11-01');
        $renderFinancial = static fn (array $report): string => preg_replace('/<!--.*?-->/s', '', \CodeIgniter\Config\Services::renderer()->setData(['assets' => ['css' => null, 'js' => null], 'navigation' => [], 'period' => ['preset' => 'custom', 'from' => '2026-10-01', 'to_inclusive' => '2026-10-31', 'label' => 'Synthetic financial period'], 'report' => $report])->render('vehicle_financial_results/index')) ?? '';
        \CodeIgniter\Shield\Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
            public function user(): ?\CodeIgniter\Shield\Entities\User
            {
                return null;
            }
            public function loggedIn(): bool
            {
                return false;
            }
        });
        $financialHtmlBefore = $renderFinancial($financialBefore);
        $before = [];
        foreach ($this->connection->listTables() as $table) {
            if (! preg_match('/(?:vehicle_damage_repair_|audit_logs$|files$)/', $table)) {
                $before[$table] = hash('sha256', json_encode($this->connection->table($table)->get()->getResultArray(), JSON_THROW_ON_ERROR));
            }
        }
        $member = $this->repairs->members(1, 10, $job)[0];
        $quote = $this->work->createEstimate(1, 10, $job, $this->command($job, ['quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => 'current_quote', 'amount' => '9876543210.99', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'vendor_confirmed' => '1', 'date_confirmed' => '1', 'scope_confirmed' => '1', 'vendor_snapshot' => 'Synthetic boundary vendor', 'quote_date' => '2026-10-06', 'scope_membership_ids' => [$member['id']], 'document' => ['kind_code' => 'estimate', 'upload' => $this->upload()]]), 7);
        $this->success($quote);
        $repo = new Estimates($this->connection);
        $decision = fn () => $this->command($job, ['estimate_id' => $quote['estimate_id'], 'expected_estimate_state' => $repo->fingerprint(1, 10, $job, $quote['estimate_id']), 'confirmed' => '1', 'reason' => 'Synthetic boundary withdrawal']);
        $this->success($this->work->acceptEstimate(1, 10, $job, $decision(), 7));
        $this->success($this->work->withdrawEstimate(1, 10, $job, $decision(), 7));
        $financialAfter = $financial->period(1, '2026-10-01', '2026-11-01');
        $this->assertSame($financialBefore, $financialAfter);
        $this->assertSame($financialHtmlBefore, $renderFinancial($financialAfter));
        foreach ($before as $table => $hash) {
            $this->assertSame($hash, hash('sha256', json_encode($this->connection->table($table)->get()->getResultArray(), JSON_THROW_ON_ERROR)), $table);
        }
    }
}
