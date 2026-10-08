<?php

namespace Tests\Support;

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Services\Fleet\VehicleDamageRepairCostService;
use App\Services\Fleet\VehicleDamageRepairRecoveryReadService;
use App\Services\Fleet\VehicleDamageRepairRecoveryService;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\VehicleDamageRepairRecoveries as Policy;

abstract class VehicleDamageRepairRecoveryTestCase extends VehicleDamageRepairTestCase
{
    protected VehicleDamageRepairRecoveryService $recovery;
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recovery = new VehicleDamageRepairRecoveryService($this->connection);
    }

    protected function tearDown(): void
    {
        foreach ($this->connection->table('vehicle_damage_repair_documents')->get()->getResultArray() as $doc) {
            $resolved = $this->recovery->sources->documents->storage->resolve((int) $doc['company_id'], $doc, $this->recovery->sources->documents->documents->metadata($doc));
            if ($resolved !== null) {
                $this->paths[] = $resolved['path'];
            }
        }
        foreach (array_unique($this->paths) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    protected function upload(?string $bytes = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'b31_synthetic_');
        $this->paths[] = $path;
        file_put_contents($path, $bytes ?? "%PDF-1.4\n% Synthetic recovery source " . bin2hex(random_bytes(12)) . "\n%%EOF\n");
        return new UploadedFile($path, 'synthetic-recovery.pdf', 'application/pdf', filesize($path), UPLOAD_ERR_OK);
    }

    public static function facts(array $extra = []): array
    {
        $ref = 'SYNTHETIC-' . bin2hex(random_bytes(8));
        return $extra + ['kind_code' => 'recovery', 'authority_code' => 'external_receipt', 'source_type' => 'guest_direct', 'amount' => '100.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06',
            'payer_snapshot' => 'Synthetic Payer ' . $ref, 'source_namespace' => 'bank_transfer:synthetic-account', 'source_reference' => $ref, 'source_details' => 'Synthetic documented receipt belongs wholly to this job.',
            'confirmed' => '1', 'received_confirmed' => '1', 'returned_confirmed' => '1', 'whole_job_confirmed' => '1', 'outside_turo_confirmed' => '1', 'not_cost_reduction_confirmed' => '1', 'not_duplicate_confirmed' => '1'];
    }

    protected function receipt(int $j, array $extra = []): array
    {
        $facts = self::facts($extra);
        if (! isset($facts['repair_document_id']) && ! isset($facts['document'])) {
            $facts['document'] = ['kind_code' => Policy::DOCUMENT_KINDS[$facts['kind_code']], 'upload' => $this->upload()];
        }
        return $this->work->recordRecovery(1, 10, $j, $this->command($j, $facts), 7);
    }

    protected function reverse(int $j, int $parent, string $amount = '20.00'): array
    {
        $original = Recoveries::head($this->recovery->recoveries->entries(1, 10, $j), $parent);
        return $this->receipt($j, ['kind_code' => 'recovery_reversal', 'related_recovery_entry_id' => $parent, 'amount' => $amount, 'source_type' => $original['source_type'], 'payer_snapshot' => $original['payer_snapshot'], 'source_namespace' => $original['source_namespace']]);
    }

    protected function finalizeRecovery(int $j, array $extra = []): array
    {
        return $this->work->finalizeRecovery(1, 10, $j, $this->command($j, $extra + ['confirmed' => '1', 'completeness_confirmed' => '1', 'no_recovery_confirmed' => '1', 'scope_review_confirmed' => '1',
            'expected_ledger_state' => $this->recovery->ledgerFingerprint(1, 10, $j), 'note' => 'Synthetic recovery completeness.']), 7);
    }

    protected function correction(int $j, int $id, array $extra = []): array
    {
        $old = array_column($this->recovery->recoveries->entries(1, 10, $j), null, 'id')[$id];
        $facts = array_intersect_key($old, array_flip(explode(' ', 'kind_code authority_code source_type amount currency occurred_on payer_snapshot source_namespace source_reference damage_claim_id related_recovery_entry_id note repair_document_id')));
        $facts['source_details'] = json_decode($old['source_snapshot'], true)['details'];
        return $this->command($j, $extra + $facts + self::facts() + ['recovery_entry_id' => $id, 'expected_entry_state' => $this->recovery->entryFingerprint(1, 10, $j, $id),
            'expected_document_state' => $this->recovery->sources->documents->documents->fingerprint(1, 10, $j, (int) $old['repair_document_id']), 'reason' => 'Synthetic corrected receipt.']);
    }

    protected function economics(int $j): array
    {
        return (new VehicleDamageRepairRecoveryReadService($this->connection))->workspace(1, 10, $j);
    }

    protected function finalizedCost(int $j, string $amount): void
    {
        $cost = new VehicleDamageRepairCostService($this->connection);
        $this->success($this->work->recordCostEntry(1, 10, $j, $this->command($j, ['kind_code' => 'invoice', 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic Shop', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'verified_zero_confirmed' => '1', 'document' => ['kind_code' => 'invoice', 'upload' => $this->upload()]]), 7));
        $this->success($this->work->start(1, 10, $j, $this->command($j, ['started_at' => '2026-10-01T09:00']), 7));
        $this->success($this->work->cancel(1, 10, $j, $this->command($j, ['reason' => 'Synthetic performed work']), 7));
        $this->success($this->work->finalizeRepairCost(1, 10, $j, $this->command($j, ['confirmed' => '1', 'expected_invoiced_state' => $cost->invoicedFingerprint(1, 10, $j), 'note' => 'Synthetic final cost']), 7));
    }
}
