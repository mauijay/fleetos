<?php

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageRepairRecoveryService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture as Maria;
use Tests\Support\VehicleDamageRepairRecoveryTestCase as RecoveryFixture;

/** @internal Zero skips: observe real InnoDB waits on aggregate locks and the global source unique key. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairRecoveriesMariaDbTest extends CIUnitTestCase
{
    public static function races(): array
    {
        return array_map(fn (string $case): array => [$case], ['duplicate_identity', 'competing_reversal', 'competing_replacement', 'write_vs_finalization', 'finalization_vs_write', 'archive_vs_record', 'record_vs_archive', 'upload_replay', 'shared_b2_b3', 'disabled_turo_source']);
    }

    #[DataProvider('races')]
    public function testIndependentContenderWaitsForCommitThenRevalidates(string $case): void
    {
        $fixture = new Maria(false, '', 34);
        $db = $fixture->db;
        $paths = [];
        try {
            $work = new Work($db);
            $repo = new VehicleDamageRepairRepository($db);
            $helper = new VehicleDamageRepairRecoveryService($db);
            $j = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7)['id'];
            $command = fn (array $facts): array => $facts + ['expected_version' => $repo->job(1, 10, $j)['version'], 'command_key' => Work::commandKey()];
            $upload = function () use (&$paths): UploadedFile {
                $tmp = tempnam(sys_get_temp_dir(), 'b31_synthetic_contention_');
                $paths[] = $tmp;
                file_put_contents($tmp, "%PDF-1.4\n% Synthetic recovery contention " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
                return new UploadedFile($tmp, 'synthetic-recovery-contention.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK);
            };
            $documents = [];
            foreach (['recovery_payment', 'recovery_reversal', 'invoice'] as $kind) {
                $attached = $work->attachDocument(1, 10, $j, $command(['document' => ['kind_code' => $kind, 'upload' => $upload()]]), 7);
                $this->assertTrue($attached['success'], json_encode($attached));
                $documents[$kind] = $attached['document_id'];
            }
            $fact = function (array $extra = []) use ($helper, $documents, $j, $command): array {
                $facts = RecoveryFixture::facts($extra);
                $doc = $documents[\Config\VehicleDamageRepairRecoveries::DOCUMENT_KINDS[$facts['kind_code']]];
                $facts += ['repair_document_id' => $doc, 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $j, $doc)];
                $checksum = $helper->sources->documents->documents->document(1, 10, $j, $doc)['content_checksum'];
                $review = $helper->duplicates(1, $helper->facts($facts), $checksum, (int) ($facts['recovery_entry_id'] ?? 0));
                if ($review['candidates'] !== []) {
                    $facts += ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic independent receipt review'];
                }
                return $command($facts);
            };
            $firstFacts = $fact();
            $first = $work->recordRecovery(1, 10, $j, $firstFacts, 7);
            $this->assertTrue($first['success'], json_encode($first));
            $operation = $winnerMethod = 'recordRecovery';
            $payload = $winnerPayload = $fact(['amount' => '11.00']);
            $payload['command_key'] = Work::commandKey();
            $succeeds = false;
            $replay = false;
            switch ($case) {
                case 'duplicate_identity':
                    break;
                case 'competing_reversal':
                    $payload = $winnerPayload = $fact(['kind_code' => 'recovery_reversal', 'amount' => '60.00', 'related_recovery_entry_id' => $first['recovery_entry_id'], 'payer_snapshot' => $firstFacts['payer_snapshot']]);
                    $payload['command_key'] = Work::commandKey();
                    break;
                case 'competing_replacement':
                    $operation = $winnerMethod = 'replaceRecovery';
                    $winnerPayload = $fact(array_intersect_key($firstFacts, array_flip(['source_type', 'source_namespace', 'source_reference', 'payer_snapshot'])) + ['amount' => '90.00', 'recovery_entry_id' => $first['recovery_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $j, $first['recovery_entry_id']), 'reason' => 'Synthetic correction']);
                    $payload = array_replace($winnerPayload, ['command_key' => Work::commandKey(), 'amount' => '80.00']);
                    break;
                case 'write_vs_finalization':
                    $operation = 'finalizeRecovery';
                    $payload = $command(['confirmed' => '1', 'completeness_confirmed' => '1', 'expected_ledger_state' => $helper->ledgerFingerprint(1, 10, $j), 'note' => 'Synthetic completeness']);
                    break;
                case 'finalization_vs_write':
                    $winnerMethod = 'finalizeRecovery';
                    $winnerPayload = $command(['confirmed' => '1', 'completeness_confirmed' => '1', 'expected_ledger_state' => $helper->ledgerFingerprint(1, 10, $j), 'note' => 'Synthetic completeness']);
                    break;
                case 'archive_vs_record':
                    $operation = 'archiveDocument';
                    $payload = $command(['document_id' => $documents['recovery_reversal'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $j, $documents['recovery_reversal']), 'confirmed' => '1', 'reason' => 'Synthetic archive']);
                    $payload['expected_version'] = (int) $repo->job(1, 10, $j)['version'] + 1;
                    $winnerPayload = $fact(['kind_code' => 'recovery_reversal', 'amount' => '10.00', 'related_recovery_entry_id' => $first['recovery_entry_id'], 'payer_snapshot' => $firstFacts['payer_snapshot']]);
                    break;
                case 'record_vs_archive':
                    $payload = $fact(['kind_code' => 'recovery_reversal', 'amount' => '10.00', 'related_recovery_entry_id' => $first['recovery_entry_id'], 'payer_snapshot' => $firstFacts['payer_snapshot']]);
                    $payload['expected_version'] = (int) $repo->job(1, 10, $j)['version'] + 1;
                    $winnerMethod = 'archiveDocument';
                    $winnerPayload = $command(['document_id' => $documents['recovery_reversal'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $j, $documents['recovery_reversal']), 'confirmed' => '1', 'reason' => 'Synthetic archive before recognition']);
                    break;
                case 'upload_replay':
                    $uploadFile = $upload();
                    $descriptor = $helper->sources->documents->storage->descriptor($uploadFile);
                    $winnerPayload = $command(RecoveryFixture::facts(['amount' => '13.00']) + ['document' => ['kind_code' => 'recovery_payment', 'upload' => $uploadFile, 'descriptor' => $descriptor]]);
                    $payload = $helper::semantic($winnerPayload) + ['command_key' => $winnerPayload['command_key']];
                    $succeeds = $replay = true;
                    break;
                case 'shared_b2_b3':
                    $winnerMethod = 'recordCostEntry';
                    $winnerPayload = $command(['kind_code' => 'invoice', 'amount' => '50.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'repair_document_id' => $documents['invoice'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, 10, $j, $documents['invoice'])]);
                    break;
                case 'disabled_turo_source':
                    $payload = $command(RecoveryFixture::facts(['authority_code' => 'turo_transaction', 'source_type' => 'turo_reimbursement', 'turo_transaction_normalized_id' => 999]));
                    $payload['expected_version'] = (int) $repo->job(1, 10, $j)['version'] + 1;
                    break;
                default:
                    throw new InvalidArgumentException('Unknown synthetic contention case.');
            }
            $beforeEvents = $db->table('vehicle_damage_repair_job_events')->countAllResults();
            $race = $fixture->contendUntilCommit($operation, [1, 10, $j, $payload, 7], fn (Closure $barrier): array => (new Work($db, commandClock: $barrier))->{$winnerMethod}(1, 10, $j, $winnerPayload, 7));
            $this->assertTrue($race['blocked'], 'Independent connection must enter an observed InnoDB wait.');
            $this->assertSame($succeeds, $race['result']['success'], json_encode($race));
            if ($replay) {
                $this->assertTrue($race['result']['replayed']);
                $this->assertSame($race['winner']['recovery_entry_id'], $race['result']['recovery_entry_id']);
            }
            if ($case === 'disabled_turo_source') {
                $this->assertStringContainsString('unavailable', implode(' ', $race['result']['errors']));
            }
            if ($case === 'archive_vs_record') {
                $this->assertTrue($race['snapshot_primed']);
                $this->assertStringContainsString('permanently retained', implode(' ', $race['result']['errors']));
            }
            $this->assertSame($beforeEvents + 1, $db->table('vehicle_damage_repair_job_events')->countAllResults());
            $this->assertSame((int) $winnerPayload['expected_version'] + 1, (int) $repo->job(1, 10, $j)['version']);
        } finally {
            foreach ($db->table('vehicle_damage_repair_documents')->get()->getResultArray() as $doc) {
                $helper = new VehicleDamageRepairRecoveryService($db);
                $resolved = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc));
                if ($resolved !== null) {
                    $paths[] = $resolved['path'];
                }
            }
            $fixture->close();
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testDifferentVehiclesContendOnPermanentSourceUniqueKeyWithoutLateAncestorLocks(): void
    {
        $fixture = new Maria(false, '', 34);
        $db = $fixture->db;
        $listener = null;
        $paths = [];
        try {
            $work = new Work($db);
            $helper = new VehicleDamageRepairRecoveryService($db);
            $repo = new VehicleDamageRepairRepository($db);
            $jobs = $docs = [];
            foreach ([10, 11] as $v) {
                $jobs[$v] = $work->createJob(1, $v, Fixture::creation($db, [Fixture::condition($db, 1, $v)]), 7)['id'];
                $tmp = tempnam(sys_get_temp_dir(), 'b31_synthetic_unique_');
                $paths[] = $tmp;
                file_put_contents($tmp, "%PDF-1.4\n% Synthetic source reservation " . bin2hex(random_bytes(8)) . "\n%%EOF\n");
                $result = $work->attachDocument(1, $v, $jobs[$v], ['expected_version' => 1, 'command_key' => Work::commandKey(), 'document' => ['kind_code' => 'recovery_payment', 'upload' => new UploadedFile($tmp, 'synthetic-unique.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK)]], 7);
                $this->assertTrue($result['success']);
                $docs[$v] = $result['document_id'];
            }
            $facts = RecoveryFixture::facts();
            $payloads = [];
            foreach ([10, 11] as $v) {
                $payloads[$v] = $facts + ['expected_version' => $repo->job(1, $v, $jobs[$v])['version'], 'command_key' => Work::commandKey(), 'repair_document_id' => $docs[$v], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, $v, $jobs[$v], $docs[$v])];
            }
            $race = $fixture->contendUntilCommit('recordRecovery', [1, 11, $jobs[11], $payloads[11], 7], function (Closure $barrier) use ($db, $jobs, $payloads, &$listener): array {
                $started = false;
                $listener = static function (Query $query) use ($barrier, &$started): void {
                    if (! $started && str_starts_with($query->getQuery(), 'INSERT INTO `vehicle_damage_repair_recovery_entries`')) {
                        $started = true;
                        $barrier();
                    }
                };
                Events::on('DBQuery', $listener);
                return (new Work($db))->recordRecovery(1, 10, $jobs[10], $payloads[10], 7);
            }, Recoveries::TABLE, 'INSERT INTO');
            $this->assertTrue($race['blocked']);
            $this->assertFalse($race['result']['success']);
            $this->assertStringContainsString('permanently reserved', implode(' ', $race['result']['errors']));
            $this->assertSame(1, $db->table(Recoveries::TABLE)->countAllResults());
            $this->assertSame(2, (int) $repo->job(1, 11, $jobs[11])['version']);
        } finally {
            if ($listener !== null) {
                Events::removeListener('DBQuery', $listener);
            }
            foreach ($db->table('vehicle_damage_repair_documents')->get()->getResultArray() as $doc) {
                $helper = new VehicleDamageRepairRecoveryService($db);
                $resolved = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc));
                if ($resolved !== null) {
                    $paths[] = $resolved['path'];
                }
            }
            $fixture->close();
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
