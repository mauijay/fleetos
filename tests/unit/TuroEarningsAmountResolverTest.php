<?php

use App\Services\Turo\TuroEarningsAmountResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroEarningsAmountResolverTest extends CIUnitTestCase
{
    public function testEarningsAloneResolvesAsTransactionAmount(): void
    {
        $resolved = (new TuroEarningsAmountResolver())->resolve([
            'earnings' => '$63.18',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('earnings', $resolved->column);
        $this->assertSame('$63.18', $resolved->rawValue);
        $this->assertSame('63.18', $resolved->parsedValue);
    }

    public function testPaymentAloneResolvesAsTransactionAmount(): void
    {
        $resolved = (new TuroEarningsAmountResolver())->resolve([
            'payment' => '$1,001.63',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('payment', $resolved->column);
        $this->assertSame('$1,001.63', $resolved->rawValue);
        $this->assertSame('1001.63', $resolved->parsedValue);
    }

    public function testExistingAmountHeaderStillWinsByPrecedence(): void
    {
        $resolved = (new TuroEarningsAmountResolver())->resolve([
            'amount' => '$55.00',
            'earnings' => '$63.18',
            'payment' => '$70.00',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame('amount', $resolved->column);
        $this->assertSame('55.00', $resolved->parsedValue);
    }

    public function testMoneyParserSupportsExistingFormattingRules(): void
    {
        $resolver = new TuroEarningsAmountResolver();

        $this->assertSame('0.00', $resolver->money('$0.00'));
        $this->assertSame('-71.28', $resolver->money('-$71.28'));
        $this->assertSame('12.34', $resolver->money('($12.34)'));
        $this->assertSame('1001.63', $resolver->money('$1,001.63'));
    }
}
