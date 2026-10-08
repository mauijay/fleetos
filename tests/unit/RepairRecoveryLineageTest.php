<?php

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Services\Fleet\VehicleDamageRepairRecoveryService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal Deliberately corrupted in-memory graphs fail closed independently of FK protection. */
final class RepairRecoveryLineageTest extends CIUnitTestCase
{
    private static function row(int $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'company_id' => 1, 'vehicle_damage_repair_job_id' => 1, 'kind_code' => 'recovery', 'authority_code' => 'external_receipt', 'source_type' => 'guest_direct',
            'amount' => '10.00', 'currency' => 'USD', 'source_namespace' => 'bank_transfer:synthetic', 'source_reference' => 'SYNTHETIC-ROOT', 'source_identity_key' => str_repeat('a', 64), 'source_root_key' => str_repeat('a', 64),
            'turo_transaction_normalized_id' => null, 'turo_source_root_id' => null, 'related_recovery_entry_id' => null, 'replacement_of_recovery_entry_id' => null, 'status_code' => 'recorded'];
    }

    public static function brokenGraphs(): array
    {
        $root = self::row(1, ['status_code' => 'voided']);
        $next = self::row(2, ['replacement_of_recovery_entry_id' => 1, 'source_root_key' => null]);
        return [
            'cycle' => [[self::row(1, ['replacement_of_recovery_entry_id' => 2, 'source_root_key' => null]), self::row(2, ['replacement_of_recovery_entry_id' => 1, 'source_root_key' => null])], 1],
            'branch' => [[$root, $next, self::row(3, ['replacement_of_recovery_entry_id' => 1, 'source_root_key' => null])], 1],
            'broken ancestor' => [[self::row(2, ['replacement_of_recovery_entry_id' => 99, 'source_root_key' => null])], 2],
            'missing head' => [[$root], 1],
            'recorded predecessor' => [[self::row(1), $next], 1],
            'wrong source' => [[$root, array_replace($next, ['source_reference' => 'SYNTHETIC-WRONG'])], 1],
            'wrong job' => [[$root, array_replace($next, ['vehicle_damage_repair_job_id' => 2])], 1],
            'wrong kind' => [[$root, array_replace($next, ['kind_code' => 'recovery_reversal'])], 1],
            'duplicate root' => [[self::row(1), self::row(2)], 1],
            'wrong root reservation' => [[self::row(1, ['source_root_key' => str_repeat('b', 64)])], 1],
            'replacement reserves root' => [[$root, array_replace($next, ['source_root_key' => str_repeat('a', 64)])], 2],
        ];
    }

    #[DataProvider('brokenGraphs')]
    public function testCorruptGraphsCannotProduceAnAuthoritativeHead(array $rows, int $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        Recoveries::head($rows, $id);
    }

    public function testOriginalAndSuccessorReferencesResolveSameHeadAndVoidRetainsReservation(): void
    {
        $rows = [self::row(1, ['status_code' => 'voided']), self::row(2, ['replacement_of_recovery_entry_id' => 1, 'source_root_key' => null, 'amount' => '9.00'])];
        $this->assertSame(2, Recoveries::head($rows, 1)['id']);
        $this->assertSame(2, Recoveries::head($rows, 2)['id']);
        $this->assertSame('9.00', Recoveries::totals($rows)['net']);
        $rows[1]['status_code'] = 'voided';
        $this->assertSame(2, Recoveries::head($rows, 1, true)['id']);
        $this->assertNull(Recoveries::totals($rows)['net']);
        $this->assertSame(str_repeat('a', 64), $rows[0]['source_root_key']);
    }

    public function testIdentityExcludesCategoryAmountDateAndJobButPreservesReferenceCase(): void
    {
        $this->assertSame(VehicleDamageRepairRecoveryService::sourceIdentity('check:synthetic-account', 'Receipt-A'), VehicleDamageRepairRecoveryService::sourceIdentity('check:synthetic-account', 'Receipt-A'));
        $this->assertNotSame(VehicleDamageRepairRecoveryService::sourceIdentity('check:synthetic-account', 'Receipt-A'), VehicleDamageRepairRecoveryService::sourceIdentity('check:synthetic-account', 'receipt-a'));
    }

    public function testAggregateRecoveryRemainsExactBeyondSingleRowDecimalCapacity(): void
    {
        $rows = [self::row(1, ['amount' => '9999999999.99']), self::row(2, ['amount' => '9999999999.99', 'source_identity_key' => str_repeat('b', 64), 'source_root_key' => str_repeat('b', 64)]),
            self::row(3, ['kind_code' => 'recovery_reversal', 'amount' => '0.01', 'related_recovery_entry_id' => 1, 'source_identity_key' => str_repeat('c', 64), 'source_root_key' => str_repeat('c', 64)])];
        $this->assertSame('19999999999.98', Recoveries::totals($rows)['gross']['recovery']);
        $this->assertSame('19999999999.97', Recoveries::totals($rows)['net']);
    }
}
