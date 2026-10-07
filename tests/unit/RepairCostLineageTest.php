<?php

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal Corrupt lineage must never become an economic reduction authority. */
final class RepairCostLineageTest extends CIUnitTestCase
{
    private static function row(int $id, ?int $previous, string $status = 'voided', string $kind = 'invoice'): array
    {
        return ['id' => $id, 'replacement_of_cost_entry_id' => $previous, 'status_code' => $status, 'kind_code' => $kind, 'company_id' => 1, 'vehicle_damage_repair_job_id' => 1, 'currency' => 'USD'];
    }

    public function testOriginalReferenceResolvesToUniqueCurrentHead(): void
    {
        $rows = [self::row(1, null), self::row(2, 1), self::row(3, 2, 'recorded')];
        $this->assertSame(3, Costs::head($rows, 1)['id']);
        $this->assertSame(3, Costs::head($rows, 2)['id']);
        $this->assertSame(3, Costs::head($rows, 3)['id']);
    }

    public static function invalid(): array
    {
        return [
            [[self::row(1, 2), self::row(2, 1)], 1],
            [[self::row(1, null), self::row(2, 1, 'recorded'), self::row(3, 1, 'recorded')], 1],
            [[self::row(1, null)], 1],
            [[self::row(1, null), self::row(2, 1, 'recorded', 'payment')], 1],
            [[self::row(1, null, 'recorded'), self::row(2, 1, 'recorded')], 1],
            [[self::row(1, null, 'recorded')], 99],
            [[self::row(2, 999, 'recorded')], 2],
            [[self::row(1, null), self::row(2, 1, 'recorded', 'payment')], 2],
            [[self::row(1, null), array_replace(self::row(2, 1, 'recorded'), ['company_id' => 2])], 1],
            [[self::row(1, null), array_replace(self::row(2, 1, 'recorded'), ['vehicle_damage_repair_job_id' => 2])], 2],
            [[self::row(1, null), array_replace(self::row(2, 1, 'recorded'), ['currency' => 'EUR'])], 2],
        ];
    }

    #[DataProvider('invalid')]
    public function testCyclesBranchesMissingHeadsAndIncompatibleCorrectionsFailClosed(array $rows, int $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        Costs::head($rows, $id);
    }
}
