<?php

use App\Validation\Turo\TuroTripCsvValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroTripCsvValidatorTest extends CIUnitTestCase
{
    public function testMissingStatusIsWarningInsteadOfError(): void
    {
        $issues = (new TuroTripCsvValidator())->validate([
            'trip_id' => 'abc123',
            'starts_at' => '2026-01-01 10:00:00',
            'ends_at' => '2026-01-03 10:00:00',
            'host_payout' => '$240.00',
        ]);

        $warning = array_values(array_filter($issues, static fn ($issue): bool => $issue->code === 'missing_status'));

        $this->assertCount(1, $warning);
        $this->assertSame('warning', $warning[0]->severity);
    }

    public function testValidTripRowHasNoErrors(): void
    {
        $issues = (new TuroTripCsvValidator())->validate([
            'trip_id' => 'abc123',
            'status' => 'Completed',
            'starts_at' => '2026-01-01 10:00:00',
            'ends_at' => '2026-01-03 10:00:00',
            'host_payout' => '$240.00',
        ]);

        $errors = array_values(array_filter($issues, static fn ($issue): bool => $issue->severity === 'error'));
        $this->assertSame([], $errors);
    }

    public function testMissingTripIdIsError(): void
    {
        $issues = (new TuroTripCsvValidator())->validate([
            'status' => 'Completed',
            'starts_at' => '2026-01-01 10:00:00',
            'ends_at' => '2026-01-03 10:00:00',
        ]);

        $this->assertSame('missing_trip_id', $issues[0]->code);
        $this->assertSame('error', $issues[0]->severity);
    }

    public function testInvalidMoneyMessageExplainsExpectedFormat(): void
    {
        $issues = (new TuroTripCsvValidator())->validate([
            'trip_id' => 'abc123',
            'status' => 'Completed',
            'starts_at' => '2026-01-01 10:00:00',
            'ends_at' => '2026-01-03 10:00:00',
            'host_payout' => 'not money',
        ]);

        $this->assertSame('invalid_money', $issues[0]->code);
        $this->assertStringContainsString('Use a format like 100.00 or $100.00', $issues[0]->message);
        $this->assertStringContainsString('not money', $issues[0]->message);
    }
}
