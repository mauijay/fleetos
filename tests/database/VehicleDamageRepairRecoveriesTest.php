<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

final class VehicleDamageRepairRecoveriesTest extends VehicleDamageRepairRecoveryTestCase
{
    public static function sources(): array
    {
        return array_map(fn (string $source): array => [$source], ['guest_direct', 'insurance', 'vendor_compensation', 'other']);
    }

    #[DataProvider('sources')]
    public function testEvidenceBackedExternalSourcesOwnOnlyRecoveryMoney(string $source): void
    {
        $j = $this->createWork([$this->condition()]);
        $before = $this->counts();
        $result = $this->receipt($j, ['source_type' => $source, 'amount' => '0012.3']);
        $this->success($result);
        $row = $this->connection->table(Recoveries::TABLE)->get()->getRowArray();
        $this->assertSame('12.30', $row['amount']);
        $this->assertSame($source, $row['source_type']);
        $this->assertSame($row['source_identity_key'], $row['source_root_key']);
        $this->assertNull($row['turo_transaction_normalized_id']);
        $this->assertSame($before['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame(2, $result['version']);
        $this->assertNull($this->economics($j)['host_balance']);
    }

    public static function invalidFacts(): array
    {
        return array_map(fn (array $change): array => [$change], [
            ['amount' => 12.3], ['amount' => '0'], ['amount' => '-1.00'], ['amount' => '1.001'], ['currency' => 'usd'], ['currency' => 'EUR'], ['occurred_on' => '2026-02-30'], ['occurred_on' => '2099-01-01'],
            ['source_namespace' => 'unknown:account'], ['source_reference' => ''], ['source_details' => ''], ['payer_snapshot' => 'Turo payment'], ['source_namespace' => 'bank_transfer:turo-account'],
            ['whole_job_confirmed' => '0'], ['outside_turo_confirmed' => '0'], ['received_confirmed' => '0'], ['not_cost_reduction_confirmed' => '0'], ['not_duplicate_confirmed' => '0'],
            ['source_type' => 'approval'], ['file_id' => 1], ['image_id' => 1], ['source_snapshot' => []], ['source_identity_key' => str_repeat('a', 64)], ['expected_version' => []],
        ]);
    }

    #[DataProvider('invalidFacts')]
    public function testInvalidMoneyIdentityScopeAndRawBypassesLeaveNoHistory(array $change): void
    {
        $j = $this->createWork([$this->condition()]);
        $before = $this->counts();
        $this->failure($this->receipt($j, $change));
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, $this->connection->table(Recoveries::TABLE)->countAllResults());
        $this->assertSame(0, $this->connection->table('vehicle_damage_repair_documents')->countAllResults());
    }

    public function testEvidenceRequiredAndExistingDocumentAuditCountsAndRetention(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->failure($this->receipt($j, ['document' => []]));
        $this->failure($this->receipt($j, ['document' => ['kind_code' => 'recovery_payment', 'external_reference' => 'https://example.invalid/synthetic']]));
        $beforeBypasses = $this->counts();
        foreach (['estimate_id', 'membership_id', 'source_document_id', 'source_job_id', 'source_vehicle_id', 'file_id', 'image_id'] as $field) {
            $this->failure($this->receipt($j, ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload(), $field => 1]]));
            $this->assertSame($beforeBypasses, $this->counts());
            $this->assertSame(0, $this->connection->table('vehicle_damage_repair_documents')->countAllResults());
        }
        $attached = $this->work->attachDocument(1, 10, $j, $this->command($j, ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]), 7);
        $this->success($attached);
        $id = $attached['document_id'];
        $before = $this->counts();
        $result = $this->receipt($j, ['repair_document_id' => $id, 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $id)]);
        $this->success($result);
        $this->assertSame($before['audit_logs'] + 2, $this->counts()['audit_logs']);
        $this->failure($this->work->archiveDocument(1, 10, $j, $this->command($j, ['document_id' => $id, 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $id), 'confirmed' => '1', 'reason' => 'Synthetic archive']), 7), 'permanently retained');
        $void = $this->work->voidRecovery(1, 10, $j, $this->correction($j, $result['recovery_entry_id']), 7);
        $this->success($void);
        $this->failure($this->work->archiveDocument(1, 10, $j, $this->command($j, ['document_id' => $id, 'confirmed' => '1', 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $id), 'reason' => 'Synthetic voided archive']), 7), 'permanently retained');
    }

    public function testIdentityIsPermanentAcrossJobsSourcesVoidsAndReplacements(): void
    {
        $j = $this->createWork([$this->condition()]);
        $fact = self::facts();
        $first = $this->receipt($j, $fact);
        $this->success($first);
        $other = $this->createWork([$this->condition()]);
        $this->failure($this->receipt($other, array_replace($fact, ['source_type' => 'insurance'])), 'permanently reserved');
        $replacement = $this->work->replaceRecovery(1, 10, $j, $this->correction($j, $first['recovery_entry_id'], ['amount' => '90.00']), 7);
        $this->success($replacement);
        $row = $this->connection->table(Recoveries::TABLE)->where('id', $replacement['recovery_entry_id'])->get()->getRowArray();
        $this->assertNull($row['source_root_key']);
        $this->success($this->work->voidRecovery(1, 10, $j, $this->correction($j, $replacement['recovery_entry_id']), 7));
        $this->failure($this->receipt($other, $fact), 'permanently reserved');
        $this->assertSame(1, $this->connection->table(Recoveries::TABLE)->where('source_root_key IS NOT NULL', null, false)->countAllResults());
    }

    public function testReversalCapChronologyAndParentCorrectionPreserveOriginalReference(): void
    {
        $j = $this->createWork([$this->condition()]);
        $first = $this->receipt($j);
        $this->success($first);
        $reversal = $this->reverse($j, $first['recovery_entry_id'], '60.00');
        $this->success($reversal);
        $this->failure($this->reverse($j, $first['recovery_entry_id'], '40.01'), 'exceed');
        $this->failure($this->work->voidRecovery(1, 10, $j, $this->correction($j, $first['recovery_entry_id']), 7), 'active reversals');
        $this->failure($this->work->replaceRecovery(1, 10, $j, $this->correction($j, $first['recovery_entry_id'], ['amount' => '59.99']), 7), 'exceed');
        $before = $this->counts();
        $replacement = $this->work->replaceRecovery(1, 10, $j, $this->correction($j, $first['recovery_entry_id'], ['amount' => '120.00']), 7);
        $this->success($replacement);
        $this->assertSame($before['audit_logs'] + 3, $this->counts()['audit_logs']);
        $this->assertSame('60.00', $this->economics($j)['net']);
        $row = $this->connection->table(Recoveries::TABLE)->where('id', $reversal['recovery_entry_id'])->get()->getRowArray();
        $this->assertSame($first['recovery_entry_id'], (int) $row['related_recovery_entry_id']);
        $this->failure($this->work->replaceRecovery(1, 10, $j, $this->correction($j, $replacement['recovery_entry_id'], ['source_reference' => 'SYNTHETIC-WRONG']), 7), 'retain');
        $this->failure($this->work->replaceRecovery(1, 10, $j, $this->correction($j, $replacement['recovery_entry_id']), 7), 'No change');
        $this->failure($this->receipt($j, ['kind_code' => 'recovery_reversal', 'amount' => '1.00', 'related_recovery_entry_id' => $first['recovery_entry_id'], 'occurred_on' => '2026-10-05']), 'compatible');
    }

    public function testSoftMatchesRequireExplicitReviewNeverMerge(): void
    {
        $j = $this->createWork([$this->condition()]);
        $first = $this->receipt($j, ['payer_snapshot' => 'Synthetic Shared Payer']);
        $this->success($first);
        $facts = self::facts(['payer_snapshot' => 'Synthetic Shared Payer']);
        $this->failure($this->receipt($j, $facts), 'state changed');
        $review = $this->recovery->duplicates(1, $this->recovery->facts($facts), null);
        $this->assertCount(1, $review['candidates']);
        $second = $this->receipt($j, $facts + ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic independent bank receipt']);
        $this->success($second);
        $this->assertSame(2, $this->connection->table(Recoveries::TABLE)->countAllResults());
        $this->failure($this->finalizeRecovery($j), 'state changed');
        $review = $this->recovery->ledgerDuplicates(1, 10, $j);
        $this->success($this->finalizeRecovery($j, ['duplicate_review_fingerprint' => $review['fingerprint'], 'duplicate_review_confirmed' => '1', 'duplicate_review_reason' => 'Synthetic distinct receipts reviewed']));
    }

    public function testSameKeyReplayOriginalReceiptPrecedesStaleVersionAndCreatesNothing(): void
    {
        $j = $this->createWork([$this->condition()]);
        $data = $this->command($j, self::facts() + ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]);
        $first = $this->work->recordRecovery(1, 10, $j, $data, 7);
        $this->success($first);
        $this->success($this->work->voidRecovery(1, 10, $j, $this->correction($j, $first['recovery_entry_id']), 7));
        $before = $this->counts();
        $data['document']['descriptor'] = $first['source_descriptor'];
        unset($data['document']['upload']);
        $replay = $this->work->recordRecovery(1, 10, $j, $data, 7);
        $this->success($replay);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['version'], $replay['version']);
        $this->assertSame($before, $this->counts());
        $data['amount'] = '99.00';
        $this->failure($this->work->recordRecovery(1, 10, $j, $data, 7), 'different payload');
        $this->connection->transBegin();
        try {
            $this->failure($this->work->recordRecovery(1, 10, $j, $this->command($j, self::facts()), 7), 'outer transaction');
        } finally {
            $this->connection->transRollback();
        }
    }

    public function testExactExistingEvidenceReversalReplacementFinalizationAndInvalidationAuditDeltas(): void
    {
        $j = $this->createWork([$this->condition()]);
        $docs = [];
        foreach (['recovery_payment', 'recovery_reversal'] as $kind) {
            $doc = $this->work->attachDocument(1, 10, $j, $this->command($j, ['document' => ['kind_code' => $kind, 'upload' => $this->upload()]]), 7);
            $this->success($doc);
            $docs[$kind] = $doc['document_id'];
        }
        $assertDelta = function (callable $command, int $audits) use ($j): array {
            $before = $this->counts();
            $version = (int) $this->repairs->job(1, 10, $j)['version'];
            $result = $command();
            $this->success($result);
            $this->assertSame($before['audit_logs'] + $audits, $this->counts()['audit_logs']);
            $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
            $this->assertSame($version + 1, $result['version']);
            return $result;
        };
        $fact = self::facts();
        $document = fn (string $kind): array => ['repair_document_id' => $docs[$kind], 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $docs[$kind])];
        $receipt = $assertDelta(fn (): array => $this->receipt($j, $fact + $document('recovery_payment')), 2);
        $reversal = $assertDelta(fn (): array => $this->receipt($j, ['kind_code' => 'recovery_reversal', 'amount' => '20.00', 'related_recovery_entry_id' => $receipt['recovery_entry_id'], 'payer_snapshot' => $fact['payer_snapshot']] + $document('recovery_reversal')), 2);
        $assertDelta(fn (): array => $this->finalizeRecovery($j), 1);
        $replacement = $this->correction($j, $receipt['recovery_entry_id'], ['amount' => '90.00', 'document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]);
        unset($replacement['repair_document_id'], $replacement['expected_document_state']);
        $assertDelta(fn (): array => $this->work->replaceRecovery(1, 10, $j, $replacement, 7), 4);
        $assertDelta(fn (): array => $this->work->voidRecovery(1, 10, $j, $this->correction($j, $reversal['recovery_entry_id']), 7), 2);
        $assertDelta(fn (): array => $this->finalizeRecovery($j), 1);
        $assertDelta(fn (): array => $this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic audit review', 'expected_finalization_state' => $this->recovery->finalizationFingerprint(1, 10, $j)]), 7), 1);
    }
}
