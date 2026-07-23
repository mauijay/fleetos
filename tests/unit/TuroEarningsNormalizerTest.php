<?php

use App\DTOs\Turo\RawTransactionRow;
use App\Repositories\TuroNormalizedTripRepository;
use App\Services\Turo\TuroEarningsNormalizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroEarningsNormalizerTest extends CIUnitTestCase
{
    public function testClassifiesCommonTransactionTypes(): void
    {
        $tripRepo = $this->createMock(TuroNormalizedTripRepository::class);
        $tripRepo->method('findByTuroTripId')->willReturn(null);

        $normalizer = new TuroEarningsNormalizer($tripRepo);

        $payment = $normalizer->normalize($this->row('txn-1', 'trip-1', 'Trip payment', '$100.00'), 11);
        $fee = $normalizer->normalize($this->row('txn-2', 'trip-1', 'Airport fee', '$12.00'), 12);
        $tax = $normalizer->normalize($this->row('txn-3', 'trip-1', 'Sales tax', '$8.00'), 13);

        $this->assertSame('payment', $payment->normalizedType);
        $this->assertSame('fee', $fee->normalizedType);
        $this->assertSame('tax', $tax->normalizedType);
    }

    private function row(string $transactionId, string $tripId, string $type, string $amount): RawTransactionRow
    {
        return new RawTransactionRow(
            rowNumber: 2,
            payload: [
                'transaction_id' => $transactionId,
                'trip_id' => $tripId,
                'transaction_type' => $type,
                'amount' => $amount,
                'transaction_date' => '2026-01-01',
            ],
            externalTransactionId: $transactionId,
            externalTripId: $tripId,
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', $transactionId),
        );
    }
}
