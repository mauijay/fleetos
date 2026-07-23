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

    public function testEarningsExportAliasesAreAcceptedForAmount(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'type' => 'Trip payment',
            'date' => '2026-01-01',
            'earnings' => '$120.00',
        ]);

        $this->assertSame([], $issues);

        $paymentOnlyIssues = (new TuroEarningsCsvValidator())->validate([
            'type' => 'Payment',
            'date' => '2026-01-01',
            'payment' => '$500.00',
        ]);

        $this->assertSame([], $paymentOnlyIssues);
    }

    public function testBlankMonetaryFieldsStillProduceMissingAmount(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'type' => 'Trip payment',
            'date' => '2026-01-01',
            'earnings' => '   ',
            'payment' => '',
            'failed_payment' => null,
        ]);

        $this->assertSame('missing_amount', $issues[0]->code);
    }

    public function testExplicitZeroAmountIsValid(): void
    {
        $issues = (new TuroEarningsCsvValidator())->validate([
            'type' => 'Trip payment',
            'date' => '2026-01-01',
            'earnings' => '$0.00',
        ]);

        $this->assertSame([], $issues);
    }

    public function testMonetaryFormattingParsesUsingExistingMoneyParserRules(): void
    {
        $validator = new TuroEarningsCsvValidator();

        $negativeIssues = $validator->validate([
            'type' => 'Adjustment',
            'date' => '2026-01-01',
            'earnings' => '-$1,234.56',
        ]);
        $this->assertSame([], $negativeIssues);

        $parenthesesIssues = $validator->validate([
            'type' => 'Adjustment',
            'date' => '2026-01-01',
            'earnings' => '($12.34)',
        ]);
        $this->assertSame([], $parenthesesIssues);
    }
}
