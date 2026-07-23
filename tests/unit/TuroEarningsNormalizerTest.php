<?php

use App\DTOs\Turo\RawTransactionRow;
use App\Repositories\TuroNormalizedTripRepository;
use App\Services\Turo\TuroEarningsAmountResolver;
use App\Services\Turo\TuroEarningsNormalizer;
use App\Validation\Turo\TuroEarningsCsvValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroEarningsNormalizerTest extends CIUnitTestCase
{
    public function testClassifiesTripPatternWithReservationUrlAsTripEarning(): void
    {
        $tripRepo = $this->createMock(TuroNormalizedTripRepository::class);
        $tripRepo->method('findByTuroTripId')->willReturn(null);

        $normalizer = new TuroEarningsNormalizer($tripRepo);

        $tripEarning = new RawTransactionRow(
            rowNumber: 2,
            payload: [
                'type' => "Wice's trip\nWith Tesla Model Y 2026",
                'reservation_url' => 'https://turo.com/reservation/59419470',
                'earnings' => '$100.00',
                'date' => '2026-01-01',
            ],
            externalTransactionId: null,
            externalTripId: '59419470',
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', 'trip-earning'),
        );

        $payment = new RawTransactionRow(
            rowNumber: 3,
            payload: [
                'type' => 'Payment(......0811)',
                'reservation_url' => 'N/A',
                'payment' => '$500.00',
                'date' => '2026-01-01',
            ],
            externalTransactionId: null,
            externalTripId: null,
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', 'payment'),
        );

        $unknown = new RawTransactionRow(
            rowNumber: 4,
            payload: [
                'type' => 'Wallet transfer',
                'reservation_url' => 'N/A',
                'earnings' => '$12.00',
                'date' => '2026-01-01',
            ],
            externalTransactionId: null,
            externalTripId: null,
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', 'unknown'),
        );

        $normalizedTrip = $normalizer->normalize($tripEarning, 11);
        $normalizedPayment = $normalizer->normalize($payment, 12);
        $normalizedUnknown = $normalizer->normalize($unknown, 13);

        $this->assertSame('trip_earning', $normalizedTrip->normalizedType);
        $this->assertSame('operating_revenue', $normalizedTrip->eventClass);
        $this->assertSame('payment', $normalizedPayment->normalizedType);
        $this->assertSame('cash_movement', $normalizedPayment->eventClass);
        $this->assertSame('other', $normalizedUnknown->normalizedType);
        $this->assertSame('other', $normalizedUnknown->eventClass);
    }

    public function testExplicitKeywordTypesMapToExpectedCategories(): void
    {
        $tripRepo = $this->createMock(TuroNormalizedTripRepository::class);
        $tripRepo->method('findByTuroTripId')->willReturn(null);
        $normalizer = new TuroEarningsNormalizer($tripRepo);

        $fee = $normalizer->normalize($this->row('txn-fee', 'trip-1', 'Airport fee', '$12.00'), 14);
        $reimbursement = $normalizer->normalize($this->row('txn-reimb', 'trip-1', 'Guest reimbursement', '$8.00'), 15);
        $adjustment = $normalizer->normalize($this->row('txn-adjust', 'trip-1', 'Manual adjustment', '$2.00'), 16);
        $tax = $normalizer->normalize($this->row('txn-tax', 'trip-1', 'Sales tax', '$4.00'), 17);

        $this->assertSame('fee', $fee->normalizedType);
        $this->assertSame('expense', $fee->eventClass);
        $this->assertSame('reimbursement', $reimbursement->normalizedType);
        $this->assertSame('reimbursement', $reimbursement->eventClass);
        $this->assertSame('adjustment', $adjustment->normalizedType);
        $this->assertSame('adjustment', $adjustment->eventClass);
        $this->assertSame('tax', $tax->normalizedType);
        $this->assertSame('tax', $tax->eventClass);
    }

    public function testUsesEarningsExportAmountAliases(): void
    {
        $tripRepo = $this->createMock(TuroNormalizedTripRepository::class);
        $tripRepo->method('findByTuroTripId')->willReturn(null);

        $normalizer = new TuroEarningsNormalizer($tripRepo);

        $earningsAmount = new RawTransactionRow(
            rowNumber: 2,
            payload: [
                'type' => 'Trip payment',
                'date' => '2026-01-01',
                'earnings' => '$120.00',
            ],
            externalTransactionId: null,
            externalTripId: null,
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', 'row-earnings'),
        );

        $paymentAmount = new RawTransactionRow(
            rowNumber: 3,
            payload: [
                'type' => 'Payment',
                'date' => '2026-01-01',
                'payment' => '$500.00',
            ],
            externalTransactionId: null,
            externalTripId: null,
            transactionDate: '2026-01-01',
            rowHash: hash('sha256', 'row-payment'),
        );

        $normalizedEarnings = $normalizer->normalize($earningsAmount, 21);
        $normalizedPayment = $normalizer->normalize($paymentAmount, 22);

        $this->assertSame('120.00', $normalizedEarnings->amount);
        $this->assertSame('500.00', $normalizedPayment->amount);
    }

    public function testValidatorAndNormalizerResolveSameAmountSourceAndValue(): void
    {
        $tripRepo = $this->createMock(TuroNormalizedTripRepository::class);
        $tripRepo->method('findByTuroTripId')->willReturn(null);

        $resolver = new TuroEarningsAmountResolver();
        $validator = new TuroEarningsCsvValidator($resolver);
        $normalizer = new TuroEarningsNormalizer($tripRepo, $resolver);

        $row = [
            'type' => 'Payment(......0811)',
            'date' => '2026-01-01',
            'earnings' => '$123.45',
            'payment' => '$500.00',
        ];

        $validatorResolution = $validator->resolveAmount($row);
        $normalizerResolution = $normalizer->resolveAmount($row);

        $this->assertNotNull($validatorResolution);
        $this->assertNotNull($normalizerResolution);
        $this->assertSame($validatorResolution->column, $normalizerResolution->column);
        $this->assertSame($validatorResolution->rawValue, $normalizerResolution->rawValue);
        $this->assertSame('earnings', $validatorResolution->column);
        $this->assertSame('$123.45', $validatorResolution->rawValue);
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
