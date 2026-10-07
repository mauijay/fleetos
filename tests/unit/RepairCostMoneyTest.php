<?php

namespace Tests\Unit;

use App\Repositories\VehicleDamageRepairCostRepository;
use App\Services\Fleet\RepairCostMoney;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class RepairCostMoneyTest extends CIUnitTestCase
{
    public function testArbitraryAggregatePrecisionAndExactBorrow(): void
    {
        $this->assertSame('19999999999.98', RepairCostMoney::add('9999999999.99', '9999999999.99'));
        $this->assertSame('9999999999.99', RepairCostMoney::subtract('19999999999.98', '9999999999.99'));
        $this->assertSame('0.01', RepairCostMoney::subtract('100000000000000000000.00', '99999999999999999999.99'));
        $this->assertSame('0.00', RepairCostMoney::subtract('0.01', '0.01'));
        $this->assertGreaterThan(0, RepairCostMoney::compare('100.00', '99.99'));
        $this->assertLessThan(0, RepairCostMoney::compare('0.00', '0.01'));
        $this->assertSame(0, RepairCostMoney::compare('12.34', '12.34'));
    }

    public function testUnknownZeroAndIndependentPaymentAuthority(): void
    {
        $this->assertNull(VehicleDamageRepairCostRepository::totals([])['invoiced']);
        $this->assertNull(VehicleDamageRepairCostRepository::totals([])['payments']);
        $rows = [['kind_code' => 'invoice', 'amount' => '0.00', 'status_code' => 'recorded'], ['kind_code' => 'payment', 'amount' => '100.00', 'status_code' => 'recorded'], ['kind_code' => 'payment_refund', 'amount' => '100.00', 'status_code' => 'recorded'], ['kind_code' => 'invoice', 'amount' => '1000.00', 'status_code' => 'voided']];
        $totals = VehicleDamageRepairCostRepository::totals($rows);
        $this->assertSame('0.00', $totals['invoiced']);
        $this->assertSame('0.00', $totals['payments']);
    }

    public function testNegativeAggregateFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepairCostMoney::subtract('0.00', '0.01');
    }
}
