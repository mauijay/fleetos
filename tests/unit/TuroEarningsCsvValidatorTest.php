<?php

use App\Validation\Turo\TuroEarningsCsvValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroEarningsCsvValidatorTest extends CIUnitTestCase
{
    public function testValidEarningsRowHasNoErrors(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'transaction_id' => 'txn-123',
            'transaction_type' => 'Trip payment',
            'amount' => '$120.00',
            'transaction_date' => '2026-01-01',
        ]);

        $this->assertSame([], $issues);
    }

    public function testMissingAmountIsError(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'transaction_id' => 'txn-123',
            'transaction_type' => 'Trip payment',
        ]);

        $this->assertSame('missing_amount', $issues[0]->code);
        $this->assertSame('error', $issues[0]->severity);
    }

    public function testInvalidMoneyMessageExplainsExpectedFormat(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'transaction_id' => 'txn-123',
            'transaction_type' => 'Trip payment',
            'amount' => 'not money',
        ]);

        $this->assertSame('invalid_money', $issues[0]->code);
        $this->assertStringContainsString('Use a format like 100.00 or $100.00', $issues[0]->message);
        $this->assertStringContainsString('not money', $issues[0]->message);
    }
}
