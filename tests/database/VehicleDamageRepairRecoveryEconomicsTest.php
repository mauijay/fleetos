<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

final class VehicleDamageRepairRecoveryEconomicsTest extends VehicleDamageRepairRecoveryTestCase
{
    public static function cases(): array
    {
        return [
            'A_receipt_before_invoice' => [null, '675.00', true, null],
            'B_zero_cost_unknown_recovery' => ['0.00', null, false, null],
            'B_zero_cost_known_zero' => ['0.00', null, true, '0.00'],
            'C_final_cost_no_recovery' => ['100.00', null, true, '100.00'],
            'D_unfinalized_recovery' => ['100.00', '25.00', false, null],
            'E_both_finalized' => ['100.00', '25.00', true, '75.00'],
            'F_excess_recovery' => ['500.00', '675.00', true, '-175.00'],
            'F_unfinalized_excess_warning' => ['500.00', '675.00', false, null],
        ];
    }

    #[DataProvider('cases')]
    public function testUnknownZeroPartialAndSignedFinalEconomics(?string $cost, ?string $received, bool $finalize, ?string $balance): void
    {
        $j = $this->createWork([$this->condition()]);
        if ($cost !== null) {
            $this->finalizedCost($j, $cost);
        }
        if ($received !== null) {
            $this->success($this->receipt($j, ['amount' => $received]));
        }
        if ($finalize) {
            $before = $this->counts();
            $this->success($this->finalizeRecovery($j));
            $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        }
        $view = $this->economics($j);
        $this->assertSame($cost, $view['cost']);
        $this->assertSame($balance, $view['host_balance']);
        $this->assertSame($finalize, $view['recovery_finalized_valid'], json_encode($view));
        $this->assertSame($cost !== null, $view['cost_finalized_valid'], json_encode($view));
        $this->assertSame($received ?? ($finalize ? '0.00' : null), $view['net']);
        if ($received === '675.00' && $cost === '500.00') {
            $this->assertSame('175.00', $view['excess']);
        }
    }

    public function testEmptyFinalizationRequiresExplicitNoRecoveryAndUnrelatedVersionDoesNotInvalidate(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->failure($this->finalizeRecovery($j, ['no_recovery_confirmed' => '0']));
        $this->failure($this->finalizeRecovery($j, ['completeness_confirmed' => '0']));
        $this->failure($this->finalizeRecovery($j, ['note' => ' ']));
        $this->success($this->finalizeRecovery($j));
        $state = $this->recovery->ledgerFingerprint(1, 10, $j);
        $this->success($this->work->schedule(1, 10, $j, $this->command($j, ['scheduled_at' => '2026-10-07T09:00']), 7));
        $this->assertSame($state, $this->recovery->ledgerFingerprint(1, 10, $j));
        $this->assertTrue($this->economics($j)['recovery_finalized_valid']);
    }

    public function testIntegerConfirmationsFreezeTheSameScopeAuthorityAsFormStrings(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->finalizedCost($j, '50.00');
        $this->success($this->receipt($j, ['amount' => '60.00', 'received_confirmed' => 1]));
        $this->success($this->finalizeRecovery($j, ['scope_review_confirmed' => 1, 'confirmed' => 1, 'completeness_confirmed' => 1]));
        $this->assertSame('-10.00', $this->economics($j)['host_balance']);
    }

    public static function mutations(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], ['receipt', 'reversal', 'void', 'replace']);
    }

    #[DataProvider('mutations')]
    public function testEachMoneyChangeClearsOnlyRecoveryFinalityInItsOnlyEvent(string $mutation): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->finalizedCost($j, '200.00');
        $receipt = $this->receipt($j);
        $this->success($receipt);
        $this->success($this->finalizeRecovery($j));
        $costMarker = $this->repairs->job(1, 10, $j)['cost_finalized_at'];
        $before = $this->counts();
        $version = (int) $this->repairs->job(1, 10, $j)['version'];
        $result = match ($mutation) {
            'receipt' => $this->receipt($j, ['amount' => '10.00']),
            'reversal' => $this->reverse($j, $receipt['recovery_entry_id']),
            'void' => $this->work->voidRecovery(1, 10, $j, $this->correction($j, $receipt['recovery_entry_id']), 7),
            'replace' => $this->work->replaceRecovery(1, 10, $j, $this->correction($j, $receipt['recovery_entry_id'], ['amount' => '90.00']), 7),
            default => throw new \InvalidArgumentException('Unknown synthetic mutation.'),
        };
        $this->success($result);
        $job = $this->repairs->job(1, 10, $j);
        $this->assertNull($job['recovery_finalized_at']);
        $this->assertNull($job['recovery_finalized_by']);
        $this->assertNull($job['recovery_finalization_note']);
        $this->assertSame($costMarker, $job['cost_finalized_at']);
        $this->assertSame($version + 1, (int) $job['version']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $this->assertSame($before['audit_logs'] + ($mutation === 'void' ? 2 : 3), $this->counts()['audit_logs']);
        $this->assertNull($this->economics($j)['host_balance']);
    }

    public function testExplicitInvalidationChecksStateAndWritesOnlyOneJobAudit(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->success($this->finalizeRecovery($j));
        $data = $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic completeness review', 'expected_finalization_state' => $this->recovery->finalizationFingerprint(1, 10, $j)]);
        $before = $this->counts();
        $this->failure($this->work->invalidateRecoveryFinalization(1, 10, $j, array_replace($data, ['expected_finalization_state' => str_repeat('0', 64)]), 7));
        $this->assertSame($before, $this->counts());
        $result = $this->work->invalidateRecoveryFinalization(1, 10, $j, $data, 7);
        $this->success($result);
        $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        $this->assertSame(0, $this->connection->table(Recoveries::TABLE)->countAllResults());
        $before = $this->counts();
        $this->failure($this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic repeated invalidation', 'expected_finalization_state' => $data['expected_finalization_state']]), 7));
        $this->assertSame($before, $this->counts());
    }

    public function testStaleBinarySuppressesEconomicsAndStillAllowsExplicitInvalidation(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->finalizedCost($j, '100.00');
        $receipt = $this->receipt($j, ['amount' => '25.00']);
        $this->success($receipt);
        $this->success($this->finalizeRecovery($j));
        $doc = $this->recovery->verifyDocument(1, 10, $j, $receipt['document_id'], 'recovery');
        $path = $this->recovery->sources->documents->storage->resolve(1, $doc, $this->recovery->sources->documents->documents->metadata($doc))['path'];
        $this->paths[] = $path;
        file_put_contents($path, 'Synthetic invalid binary');
        $before = $this->counts();
        $view = $this->economics($j);
        $this->assertNull($view['host_balance']);
        $this->assertFalse($view['recovery_finalized_valid']);
        $this->assertNotEmpty($view['issues']);
        $this->assertSame($before, $this->counts());
        $this->success($this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic unavailable evidence review', 'expected_finalization_state' => $view['finalization_state']]), 7));
    }

    public function testCostReopenSuppressesEconomicFinalityWithoutRecoveryWrite(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->finalizedCost($j, '100.00');
        $this->success($this->finalizeRecovery($j));
        $recoveryRows = $this->connection->table(Recoveries::TABLE)->get()->getResultArray();
        $marker = $this->repairs->job(1, 10, $j)['recovery_finalized_at'];
        $this->success($this->work->reopenJob(1, 10, $j, $this->command($j, ['same_work_order_confirmed' => '1', 'reason' => 'Synthetic continuing order', 'reason_category_code' => 'continuing_order']), 7));
        $view = $this->economics($j);
        $this->assertNull($view['host_balance']);
        $this->assertFalse($view['cost_finalized_valid']);
        $this->assertTrue($view['recovery_finalized_valid']);
        $this->assertSame($marker, $this->repairs->job(1, 10, $j)['recovery_finalized_at']);
        $this->assertSame($recoveryRows, $this->connection->table(Recoveries::TABLE)->get()->getResultArray());
    }

    public function testCorruptLineageStillAllowsStandaloneCompletenessInvalidationOnly(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->finalizedCost($j, '100.00');
        $receipt = $this->receipt($j, ['amount' => '25.00']);
        $this->success($receipt);
        $this->success($this->finalizeRecovery($j));
        $this->assertSame('75.00', $this->economics($j)['host_balance']);
        $table = \App\Repositories\VehicleDamageRepairRecoveryRepository::TABLE;
        $corrupt = $this->connection->table($table)->where('id', $receipt['recovery_entry_id'])->get()->getRowArray();
        $corrupt['id'] = (int) $corrupt['id'] + 1;
        $corrupt['replacement_of_recovery_entry_id'] = $corrupt['id'];
        $corrupt['source_root_key'] = null;
        $this->assertTrue($this->connection->table($table)->insert($corrupt), 'A direct synthetic self-cycle exercises semantic corruption while structural constraints remain present.');
        $this->assertTrue($this->recovery->recoveries->ready());
        $state = $this->economics($j);
        $this->assertNull($state['host_balance']);
        $this->assertNotEmpty($state['issues']);
        $before = $this->counts();
        $this->failure($this->receipt($j, ['amount' => '10.00']));
        $this->assertSame($before, $this->counts());
        $history = $this->connection->table($table)->orderBy('id')->get()->getResultArray();
        $job = $this->repairs->job(1, 10, $j);
        $result = $this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic corrupt lineage completeness review', 'expected_finalization_state' => $state['finalization_state']]), 7);
        $this->success($result);
        $after = $this->repairs->job(1, 10, $j);
        foreach (['recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'] as $field) {
            $this->assertNull($after[$field]);
        }
        $this->assertSame((int) $job['version'] + 1, (int) $after['version']);
        $this->assertSame($job['cost_finalized_at'], $after['cost_finalized_at']);
        $this->assertSame($history, $this->connection->table($table)->orderBy('id')->get()->getResultArray());
        $this->assertSame($before['audit_logs'] + 1, $this->counts()['audit_logs']);
        $this->assertSame($before['vehicle_damage_repair_job_events'] + 1, $this->counts()['vehicle_damage_repair_job_events']);
        $event = $this->connection->table('vehicle_damage_repair_job_events')->where('id', $result['event_id'])->get()->getRowArray();
        $this->assertSame('repair_recovery_finalization_invalidated', $event['event_code']);
        foreach (['before_json', 'after_json'] as $field) {
            $snapshot = json_decode($event[$field], true, 512, JSON_THROW_ON_ERROR)['b31'];
            $this->assertTrue($snapshot['source_review_required']);
            $this->assertNull($snapshot['totals']['net']);
            $this->assertSame($history, $snapshot['entries']);
        }
    }

    public function testRecoveryExcessNeedsScopeReviewIncludingCostFinalizedLater(): void
    {
        $j = $this->createWork([$this->condition()]);
        $this->success($this->receipt($j, ['amount' => '675.00']));
        $this->success($this->finalizeRecovery($j, ['scope_review_confirmed' => '0']));
        $this->finalizedCost($j, '500.00');
        $view = $this->economics($j);
        $this->assertNull($view['host_balance']);
        $this->assertSame('175.00', $view['excess']);
        $this->assertArrayHasKey('scope', $view['issues']);
        $this->success($this->work->invalidateRecoveryFinalization(1, 10, $j, $this->command($j, ['confirmed' => '1', 'reason' => 'Synthetic scope review', 'expected_finalization_state' => $view['finalization_state']]), 7));
        $this->failure($this->finalizeRecovery($j, ['scope_review_confirmed' => '0']));
        $this->success($this->finalizeRecovery($j));
        $this->assertSame('-175.00', $this->economics($j)['host_balance']);
    }

    public function testFullyReversedReceiptKnownZeroAndMitigationNeedNoRepair(): void
    {
        $j = $this->createWork([$this->condition()], ['intent_code' => 'mitigation']);
        $receipt = $this->receipt($j);
        $this->success($receipt);
        $this->success($this->reverse($j, $receipt['recovery_entry_id'], '100.00'));
        $this->assertSame('0.00', $this->economics($j)['net']);
        $this->assertFalse($this->economics($j)['recovery_finalized_valid']);
        $this->success($this->finalizeRecovery($j));
        $this->assertTrue($this->economics($j)['recovery_finalized_valid']);
        $this->assertNull($this->economics($j)['cost']);
        $this->assertNull($this->economics($j)['host_balance']);
        $this->assertSame('planned', $this->repairs->job(1, 10, $j)['status_code']);
    }
}
