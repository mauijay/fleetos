<?php

use App\Services\Turo\TuroTransactionEventClassMapper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroTransactionEventClassMapperTest extends CIUnitTestCase
{
    public function testTripEarningMapsToOperatingRevenue(): void
    {
        $this->assertSame('operating_revenue', (new TuroTransactionEventClassMapper())->eventClassForType('trip_earning'));
    }

    public function testPaymentMapsToCashMovement(): void
    {
        $this->assertSame('cash_movement', (new TuroTransactionEventClassMapper())->eventClassForType('payment'));
    }

    public function testFeeMapsToExpense(): void
    {
        $this->assertSame('expense', (new TuroTransactionEventClassMapper())->eventClassForType('fee'));
    }

    public function testReimbursementMapsToReimbursement(): void
    {
        $this->assertSame('reimbursement', (new TuroTransactionEventClassMapper())->eventClassForType('reimbursement'));
    }

    public function testAdjustmentMapsToAdjustment(): void
    {
        $this->assertSame('adjustment', (new TuroTransactionEventClassMapper())->eventClassForType('adjustment'));
    }

    public function testTaxMapsToTax(): void
    {
        $this->assertSame('tax', (new TuroTransactionEventClassMapper())->eventClassForType('tax'));
    }

    public function testUnknownMapsToOther(): void
    {
        $this->assertSame('other', (new TuroTransactionEventClassMapper())->eventClassForType('mystery'));
    }
}
