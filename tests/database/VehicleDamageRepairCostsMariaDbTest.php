<?php

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

/** @internal Independent contenders resume a real InnoDB wait after a canonical winner commits. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairCostsMariaDbTest extends CIUnitTestCase
{
    public static function races(): array
    {
        return array_map(fn (string $case): array => [$case], ['A_duplicate_invoice', 'B_competing_credits', 'C_competing_refunds', 'D_finalization', 'E_invoice_finalization', 'F_void_replace', 'G_parent_replace_credit', 'G_parent_replace_refund', 'H_archive_record', 'I_upload_replay_dedupe', 'J_shared_lock_order']);
    }

    #[DataProvider('races')]
    public function testRealIndependentConnectionWaitsAndRevalidates(string $case): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture(false, '', 32);
        $db = $fixture->db;
        $paths = [];
        try {
            $work = new Work($db);
            $repo = new VehicleDamageRepairRepository($db);
            $costs = new VehicleDamageRepairCostService($db);
            $job = $work->createJob(1, 10, Fixture::creation($db, [Fixture::condition($db)]), 7)['id'];
            $command = fn (array $d): array => $d + ['expected_version' => $repo->job(1, 10, $job)['version'], 'command_key' => Work::commandKey()];
            $documents = [];
            $bytes = "%PDF-1.4\n% Synthetic cost contention " . bin2hex(random_bytes(16)) . "\n%%EOF\n";
            foreach (['invoice', 'invoice_credit', 'payment_receipt', 'payment_refund', 'estimate'] as $kind) {
                $tmp = tempnam(sys_get_temp_dir(), 'b23_synthetic_');
                $paths[] = $tmp;
                file_put_contents($tmp, $bytes);
                $result = $work->attachDocument(1, 10, $job, $command(['document' => ['kind_code' => $kind, 'upload' => new UploadedFile($tmp, 'synthetic-contention.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK)]]), 7);
                $this->assertTrue($result['success'], json_encode($result));
                $documents[$kind] = $result['document_id'];
                $doc = $costs->sources->documents->documents->document(1, 10, $job, $result['document_id']);
                $paths[] = $costs->sources->documents->storage->resolve(1, $doc, $costs->sources->documents->documents->metadata($doc))['path'];
            }
            $fact = function (string $kind, string $amount, ?int $parent = null, int $except = 0) use ($costs, $documents, $job, $command): array {
                $doc = $documents[\Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$kind]];
                $data = ['kind_code' => $kind, 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Contention Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'repair_document_id' => $doc, 'expected_document_state' => $costs->sources->documents->documents->fingerprint(1, 10, $job, $doc)];
                if ($parent !== null) {
                    $data['related_cost_entry_id'] = $parent;
                }
                $checksum = $costs->sources->documents->documents->document(1, 10, $job, $doc)['content_checksum'];
                $review = $costs->duplicates(1, 10, $job, $data, $checksum, $except);
                if ($review['candidates'] !== []) {
                    $data += ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic separate economic fact review'];
                }
                return $command($data);
            };
            $invoice = $work->recordCostEntry(1, 10, $job, $fact('invoice', '100.00'), 7);
            $this->assertTrue($invoice['success'], json_encode($invoice));
            $payment = $work->recordCostEntry(1, 10, $job, $fact('payment', '100.00'), 7);
            $this->assertTrue($payment['success'], json_encode($payment));
            $operation = 'recordCostEntry';
            $payload = $fact('invoice', '11.00');
            $winnerMethod = $operation;
            $winnerPayload = $payload;
            $replay = false;
            $succeeds = false;
            switch ($case) {
                case 'A_duplicate_invoice':
                    $replay = $succeeds = true;
                    break;
                case 'B_competing_credits':
                    $payload = $winnerPayload = $fact('invoice_credit', '60.00', $invoice['cost_entry_id']);
                    $payload['command_key'] = Work::commandKey();
                    break;
                case 'C_competing_refunds':
                    $payload = $winnerPayload = $fact('payment_refund', '60.00', $payment['cost_entry_id']);
                    $payload['command_key'] = Work::commandKey();
                    break;
                case 'D_finalization':
                case 'E_invoice_finalization':
                    $this->assertTrue($work->start(1, 10, $job, $command(['started_at' => '2026-10-01T09:00']), 7)['success']);
                    $this->assertTrue($work->cancel(1, 10, $job, $command(['reason' => 'Synthetic performed work']), 7)['success']);
                    $operation = 'finalizeRepairCost';
                    $payload = $command(['expected_invoiced_state' => $costs->invoicedFingerprint(1, 10, $job), 'confirmed' => '1', 'note' => 'Synthetic concurrent finalization']);
                    if ($case === 'D_finalization') {
                        $winnerMethod = $operation;
                        $winnerPayload = $payload;
                        $payload['command_key'] = Work::commandKey();
                    } else {
                        $winnerPayload = $fact('invoice', '15.00');
                    }
                    break;
                case 'F_void_replace':
                case 'G_parent_replace_credit':
                case 'G_parent_replace_refund':
                    $parent = $case === 'G_parent_replace_refund' ? $payment['cost_entry_id'] : $invoice['cost_entry_id'];
                    $kind = $case === 'G_parent_replace_refund' ? 'payment' : 'invoice';
                    $winnerMethod = 'replaceCostEntry';
                    $winnerPayload = $fact($kind, '50.00', null, $parent) + ['cost_entry_id' => $parent, 'expected_entry_state' => $costs->entryFingerprint(1, 10, $job, $parent), 'reason' => 'Synthetic corrected parent'];
                    if ($case === 'F_void_replace') {
                        $operation = 'voidCostEntry';
                        $payload = $command(['cost_entry_id' => $parent, 'expected_entry_state' => $costs->entryFingerprint(1, 10, $job, $parent), 'confirmed' => '1', 'reason' => 'Synthetic competing void']);
                    } else {
                        $payload = $fact($kind === 'invoice' ? 'invoice_credit' : 'payment_refund', '60.00', $parent);
                    }
                    break;
                case 'H_archive_record':
                    $operation = 'archiveDocument';
                    $id = $documents['invoice_credit'];
                    $payload = $command(['document_id' => $id, 'expected_document_state' => $costs->sources->documents->documents->fingerprint(1, 10, $job, $id), 'confirmed' => '1', 'reason' => 'Synthetic competing archive']);
                    $winnerPayload = $fact('invoice_credit', '10.00', $invoice['cost_entry_id']);
                    break;
                case 'I_upload_replay_dedupe':
                    $tmp = tempnam(sys_get_temp_dir(), 'b23_synthetic_');
                    $paths[] = $tmp;
                    file_put_contents($tmp, $bytes);
                    $upload = new UploadedFile($tmp, 'synthetic-contention.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK);
                    $descriptor = $costs->sources->documents->storage->descriptor($upload);
                    $winnerPayload = $fact('invoice', '12.00');
                    unset($winnerPayload['repair_document_id'], $winnerPayload['expected_document_state']);
                    $winnerPayload['document'] = ['kind_code' => 'invoice', 'upload' => $upload, 'descriptor' => $descriptor];
                    $payload = $winnerPayload;
                    unset($payload['document']['upload']);
                    $replay = $succeeds = true;
                    break;
                case 'J_shared_lock_order':
                    $operation = 'archiveDocument';
                    $id = $documents['estimate'];
                    $payload = $command(['document_id' => $id, 'expected_document_state' => $costs->sources->documents->documents->fingerprint(1, 10, $job, $id), 'confirmed' => '1', 'reason' => 'Synthetic shared order']);
                    $payload['expected_version']++;
                    $succeeds = true;
                    break;
            }
            // Reach business-state checks after waking, rather than stopping at stale version validation.
            if (! $replay && $case !== 'J_shared_lock_order') {
                $payload['expected_version']++;
            }
            $before = $db->table(Costs::TABLE)->countAllResults();
            $beforeEvents = $db->table('vehicle_damage_repair_job_events')->countAllResults();
            $beforeAudits = $db->table('audit_logs')->countAllResults();
            $beforeDocuments = $db->table('vehicle_damage_repair_documents')->countAllResults();
            $beforeVersion = (int) $repo->job(1, 10, $job)['version'];
            $result = $fixture->contendUntilCommit($operation, [1, 10, $job, $payload, 7], function (\Closure $barrier) use ($db, $winnerMethod, $winnerPayload, $job): array {
                return (new Work($db, commandClock: $barrier))->{$winnerMethod}(1, 10, $job, $winnerPayload, 7);
            });
            $this->assertTrue($result['blocked'], 'The independent contender must actually wait in InnoDB.');
            $this->assertSame($operation === 'archiveDocument', $result['snapshot_primed']);
            $receipt = $result['result'];
            $this->assertSame($succeeds, $receipt['success'], json_encode($receipt));
            $this->assertSame($replay, $receipt['replayed'] ?? false);
            if ($replay) {
                $this->assertSame($result['winner']['cost_entry_id'], $receipt['cost_entry_id']);
                $this->assertSame($result['winner']['event_id'], $receipt['event_id']);
            } elseif (! $succeeds) {
                $expectedError = match ($case) {
                    'B_competing_credits', 'C_competing_refunds', 'G_parent_replace_credit', 'G_parent_replace_refund' => 'exceed',
                    'D_finalization' => 'unfinalized',
                    'E_invoice_finalization' => 'state changed',
                    'F_void_replace' => 'current recorded lineage head',
                    'H_archive_record' => 'permanently retained',
                    default => throw new LogicException('Unexpected failing contention case: ' . $case),
                };
                $this->assertStringContainsString($expectedError, $receipt['errors']['work']);
            }
            $this->assertSame($before + ($winnerMethod === 'finalizeRepairCost' ? 0 : 1), $db->table(Costs::TABLE)->countAllResults());
            $extraArchive = (int) ($case === 'J_shared_lock_order');
            $this->assertSame($beforeEvents + 1 + $extraArchive, $db->table('vehicle_damage_repair_job_events')->countAllResults());
            $winnerAudits = $winnerMethod === 'finalizeRepairCost' ? 1 : ($winnerMethod === 'replaceCostEntry' || $case === 'I_upload_replay_dedupe' ? 3 : 2);
            $this->assertSame($beforeAudits + $winnerAudits + 2 * $extraArchive, $db->table('audit_logs')->countAllResults());
            $this->assertSame($beforeDocuments + (int) ($case === 'I_upload_replay_dedupe'), $db->table('vehicle_damage_repair_documents')->countAllResults());
            $this->assertSame($beforeVersion + 1 + $extraArchive, (int) $repo->job(1, 10, $job)['version']);
            $totals = $costs->snapshot(1, 10, $job)['totals'];
            $this->assertSame(match ($case) {
                'A_duplicate_invoice', 'J_shared_lock_order' => '111.00',
                'B_competing_credits' => '40.00',
                'E_invoice_finalization' => '115.00',
                'F_void_replace', 'G_parent_replace_credit' => '50.00',
                'H_archive_record' => '90.00',
                'I_upload_replay_dedupe' => '112.00',
                default => '100.00',
            }, $totals['invoiced']);
            $this->assertSame(match ($case) {
                'C_competing_refunds' => '40.00', 'G_parent_replace_refund' => '50.00', default => '100.00',
            }, $totals['payments']);
            $this->assertSame((int) in_array($case, ['F_void_replace', 'G_parent_replace_credit', 'G_parent_replace_refund'], true), $db->table(Costs::TABLE)->where('status_code', 'voided')->countAllResults());
            $this->assertSame($case === 'D_finalization', $repo->job(1, 10, $job)['cost_finalized_at'] !== null);
            $this->assertNull($costs->sources->documents->documents->document(1, 10, $job, $documents['invoice_credit'])['archived_at']);
            $this->assertSame(1, $db->table('files')->where('checksum', hash('sha256', $bytes))->countAllResults(), 'Same-content documents reuse a single verified binary.');
            if ($case === 'J_shared_lock_order') {
                $trace = implode("\n", $result['locks']);
                $this->assertLessThan(strpos($trace, '`vehicle_damage_repair_cost_entries`'), strpos($trace, '`vehicle_damage_repair_documents`'));
                $this->assertLessThan(strpos($trace, '`files`'), strpos($trace, '`vehicle_damage_repair_cost_entries`'));
            }
        } finally {
            $fixture->close();
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testSharedBinaryWaitAcrossVehiclesRefreshesDuplicateReviewWithoutGapDeadlock(): void
    {
        $this->assertNotEmpty(getenv('B21_MARIADB_CONFIG'));
        $fixture = new VehicleDamageRepairMariaDbFixture(false, '', 32);
        $db = $fixture->db;
        $paths = [];
        try {
            $work = new Work($db);
            $helper = new VehicleDamageRepairCostService($db);
            $repo = new VehicleDamageRepairRepository($db);
            $facts = [];
            $jobs = [];
            $bytes = "%PDF-1.4\n% Synthetic shared binary wait " . bin2hex(random_bytes(16)) . "\n%%EOF\n";
            foreach ([10, 11] as $vehicle) {
                $created = $work->createJob(1, $vehicle, Fixture::creation($db, [Fixture::condition($db, 1, $vehicle)]), 7);
                $this->assertTrue($created['success']);
                $job = $jobs[$vehicle] = $created['id'];
                $path = tempnam(sys_get_temp_dir(), 'b23_synthetic_shared_');
                $paths[] = $path;
                file_put_contents($path, $bytes);
                $source = $work->attachDocument(1, $vehicle, $job, ['command_key' => Work::commandKey(), 'expected_version' => 1, 'document' => ['kind_code' => 'invoice', 'upload' => new UploadedFile($path, 'synthetic-shared.pdf', 'application/pdf', filesize($path), UPLOAD_ERR_OK)]], 7);
                $this->assertTrue($source['success'], json_encode($source));
                $document = $helper->sources->documents->documents->document(1, $vehicle, $job, $source['document_id']);
                $paths[] = $helper->sources->documents->storage->resolve(1, $document, $helper->sources->documents->documents->metadata($document))['path'];
                $facts[$vehicle] = ['command_key' => Work::commandKey(), 'expected_version' => 2, 'kind_code' => 'invoice', 'amount' => $vehicle === 10 ? '100.00' : '200.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shared Vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'repair_document_id' => $source['document_id'], 'expected_document_state' => $helper->sources->documents->documents->fingerprint(1, $vehicle, $job, $source['document_id'])];
            }
            $preview = $helper->duplicates(1, 11, $jobs[11], $facts[11], hash('sha256', $bytes));
            $this->assertSame([], $preview['candidates']);
            $facts[11] += ['duplicate_review_fingerprint' => $preview['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic initial shared source review'];
            $events = $db->table('vehicle_damage_repair_job_events')->countAllResults();
            $audits = $db->table('audit_logs')->countAllResults();
            $result = $fixture->contendUntilCommit('recordCostEntry', [1, 11, $jobs[11], $facts[11], 7], fn (\Closure $barrier): array => (new Work($db, commandClock: $barrier))->recordCostEntry(1, 10, $jobs[10], $facts[10], 7), 'files');
            $this->assertTrue($result['blocked']);
            $this->assertFalse($result['result']['success']);
            $this->assertStringContainsString('state changed', $result['result']['errors']['work']);
            $this->assertSame(1, $db->table(Costs::TABLE)->countAllResults());
            $this->assertSame($events + 1, $db->table('vehicle_damage_repair_job_events')->countAllResults());
            $this->assertSame($audits + 2, $db->table('audit_logs')->countAllResults());
            $this->assertSame(3, (int) $repo->job(1, 10, $jobs[10])['version']);
            $this->assertSame(2, (int) $repo->job(1, 11, $jobs[11])['version']);
            $this->assertSame('100.00', $helper->snapshot(1, 10, $jobs[10])['totals']['invoiced']);
            $this->assertNull($helper->snapshot(1, 11, $jobs[11])['totals']['invoiced']);
            $this->assertSame(1, $db->table('files')->where('checksum', hash('sha256', $bytes))->countAllResults());
            $this->assertSame(2, $db->table('vehicle_damage_repair_documents')->countAllResults());
            $this->assertSame('REPEATABLE-READ', $db->query('SELECT @@tx_isolation AS isolation')->getRowArray()['isolation'], 'Command isolation must not change subsequent caller transactions.');
        } finally {
            $fixture->close();
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
