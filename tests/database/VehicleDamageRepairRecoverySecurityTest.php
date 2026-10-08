<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

final class VehicleDamageRepairRecoverySecurityTest extends VehicleDamageRepairRecoveryTestCase
{
    public function testCompanyVehicleJobEntryEvidenceAndClaimBoundariesRejectWithoutWrites(): void
    {
        $j = $this->createWork([$this->condition()]);
        $other = $this->createWork([$this->condition()]);
        $recorded = $this->receipt($j);
        $this->success($recorded);
        $before = $this->counts();
        foreach ([[2, 10, $j], [1, 11, $j], [1, 10, 99999]] as [$c, $v, $id]) {
            $this->failure($this->work->recordRecovery($c, $v, $id, $this->command($j, self::facts() + ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]), 7));
        }
        $correction = $this->correction($j, $recorded['recovery_entry_id']);
        $correction['expected_version'] = $this->repairs->job(1, 10, $other)['version'];
        $this->failure($this->work->voidRecovery(1, 10, $other, $correction, 7));
        $this->failure($this->receipt($other, ['repair_document_id' => $recorded['document_id'], 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $recorded['document_id'])]));
        $this->failure($this->receipt($j, ['damage_claim_id' => 701, 'claim_context_confirmed' => '1']));
        $this->failure($this->receipt($j, ['kind_code' => 'recovery_reversal', 'related_recovery_entry_id' => 99999]));
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, $this->connection->table(Recoveries::TABLE)->countAllResults());
        $this->assertSame(1, $this->connection->table('vehicle_damage_repair_documents')->countAllResults());
    }

    public function testPartialRecoverySchemaBlocksCommandsAndArchiveBeforeBinaryChanges(): void
    {
        $j = $this->createWork([$this->condition()]);
        $doc = $this->work->attachDocument(1, 10, $j, $this->command($j, ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]), 7);
        $this->success($doc);
        $before = $this->counts();
        $this->connection->query('DROP TRIGGER ' . $this->connection->escapeIdentifiers($this->connection->prefixTable('b31_recovery_immutable_update')));
        $this->connection->resetDataCache();
        $this->assertTrue($this->recovery->recoveries->present());
        $this->assertFalse($this->recovery->recoveries->ready());
        $this->failure($this->receipt($j), 'incomplete');
        $this->failure($this->work->archiveDocument(1, 10, $j, $this->command($j, ['document_id' => $doc['document_id'], 'confirmed' => '1', 'reason' => 'Synthetic schema guard', 'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, $doc['document_id'])]), 7), 'incomplete');
        $this->assertSame($before, $this->counts());
        $this->assertSame($doc['document_id'], (int) $this->recovery->verifyDocument(1, 10, $j, $doc['document_id'], 'recovery')['id']);
    }

    public function testStoredUnprovenReceiptCannotBeFinalizedOrProduceEconomics(): void
    {
        $j = $this->createWork([$this->condition()]);
        $attached = $this->work->attachDocument(1, 10, $j, $this->command($j, ['document' => ['kind_code' => 'recovery_payment', 'upload' => $this->upload()]]), 7);
        $this->success($attached);
        $facts = $this->recovery->facts(self::facts());
        $snapshot = json_decode($facts['source_snapshot'], true);
        $snapshot['money_confirmed'] = false;
        $facts['source_snapshot'] = json_encode($snapshot);
        $this->connection->table(Recoveries::TABLE)->insert($facts + ['company_id' => 1, 'vehicle_damage_repair_job_id' => $j, 'source_root_key' => $facts['source_identity_key'], 'repair_document_id' => $attached['document_id'], 'created_by' => 7, 'created_at' => '2026-10-07 09:00:00']);
        $before = $this->counts();
        $state = $this->economics($j);
        $this->assertNull($state['net']);
        $this->assertNull($state['ledger_state']);
        $this->assertNull($state['host_balance']);
        $this->failure($this->work->finalizeRecovery(1, 10, $j, $this->command($j, ['confirmed' => '1', 'completeness_confirmed' => '1', 'expected_ledger_state' => str_repeat('a', 64), 'note' => 'Synthetic unproven source']), 7), 'confirmation');
        $this->assertSame($before, $this->counts());
    }
}
