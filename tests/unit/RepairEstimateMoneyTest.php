<?php

use App\Services\Fleet\RepairEstimateMoney;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class RepairEstimateMoneyTest extends CIUnitTestCase
{
    public static function valid(): array
    {
        return [['0', '0.00'], ['0.00', '0.00'], ['12', '12.00'], ['12.1', '12.10'], ['12.12', '12.12'], ['9999999999.99', '9999999999.99'], ['00012.1', '12.10']];
    }
    #[DataProvider('valid')]
    public function testCanonicalDecimalStrings(string $input, string $expected): void
    {
        $this->assertSame($expected, RepairEstimateMoney::normalize($input));
    }
    public static function invalid(): array
    {
        return [[1.25], [0], [null], ['-1'], ['+1'], ['1e3'], ['1,000'], ['1.123'], ['10000000000.00'], [''], ['.1'], ['1.']];
    }
    #[DataProvider('invalid')]
    public function testNeverUsesFloatingPoint(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepairEstimateMoney::normalize($value);
    }
}
