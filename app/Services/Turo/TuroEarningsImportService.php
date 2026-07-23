<?php

namespace App\Services\Turo;

use App\DTOs\Turo\ImportResult;
use App\DTOs\Turo\RawTransactionRow;
use App\DTOs\Turo\ValidationIssue;
use App\Repositories\LookupRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Repositories\TuroRawTransactionRepository;
use App\Validation\Turo\TuroEarningsCsvValidator;
use RuntimeException;
use Throwable;

class TuroEarningsImportService
{
    /** @var string[] */
    private const TRANSACTION_TYPE_ALIASES = ['transaction_type', 'earnings_type', 'type', 'category', 'details', 'description'];

    /** @var string[] */
    private const DATE_ALIASES = ['transaction_date', 'date', 'created_at', 'processed_at'];

    /** @var string[] */
    private const TRANSACTION_ID_ALIASES = ['transaction_id', 'earnings_id', 'payout_id'];

    /** @var string[] */
    private const TRIP_ID_ALIASES = ['trip_id', 'reservation_id', 'booking_id'];

    /** @var string[] */
    private const RESERVATION_URL_ALIASES = ['reservation_url'];

    public function __construct(
        private readonly TuroCsvReader $csvReader = new TuroCsvReader(),
        private readonly TuroCsvShapeDetector $shapeDetector = new TuroCsvShapeDetector(),
        private readonly TuroEarningsCsvValidator $validator = new TuroEarningsCsvValidator(),
        private readonly TuroEarningsNormalizer $normalizer = new TuroEarningsNormalizer(),
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly TuroImportBatchRepository $batches = new TuroImportBatchRepository(),
        private readonly TuroRawTransactionRepository $rawTransactions = new TuroRawTransactionRepository(),
        private readonly TuroNormalizedTransactionRepository $normalizedTransactions = new TuroNormalizedTransactionRepository(),
        private readonly TuroImportErrorRepository $errors = new TuroImportErrorRepository(),
        private readonly TuroImportAuditService $audit = new TuroImportAuditService(),
        private readonly TuroEarningsAmountResolver $amountResolver = new TuroEarningsAmountResolver(),
        private readonly TuroReservationUrlTripIdExtractor $reservationUrlTripIdExtractor = new TuroReservationUrlTripIdExtractor(),
    ) {
    }

    public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
    {
        $headers = $this->csvReader->headers($filePath);
        $diagnostics = $this->startDiagnostics($headers);

        if ($this->shapeDetector->isTripExport($headers)) {
            throw new RuntimeException('This file matches trip_earnings_export. Use the Trips importer instead of the Earnings importer.');
        }

        if (! $this->shapeDetector->isEarningsExport($headers)) {
            throw new RuntimeException('This CSV does not match earnings_export. Upload a Turo earnings export with transaction type and amount columns.');
        }

        $sourceHash = hash_file('sha256', $filePath);

        if ($sourceHash === false) {
            throw new RuntimeException("Unable to hash CSV file: {$filePath}");
        }

        if ($this->batches->findBySourceHash($sourceHash) !== null) {
            throw new RuntimeException('This CSV source hash has already been imported.');
        }

        $sourceFilename ??= basename($filePath);

        $batchId = $this->batches->create([
            'import_type_lookup_value_id' => $this->lookups->valueId('import_type', 'turo_transactions'),
            'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'processing'),
            'source_filename' => $sourceFilename,
            'source_hash' => $sourceHash,
            'created_by' => $actorUserId,
        ]);
        $this->audit->imported($actorUserId, 'turo_import_batches', $batchId, ['source_filename' => $sourceFilename, 'source_hash' => $sourceHash]);

        $rowsRead = 0;
        $rawRowsCreated = 0;
        $normalizedRows = 0;
        $errorCount = 0;
        $skippedRows = 0;
        $duplicateRows = 0;
        $unmatchedRows = 0;
        $seenTransactionIds = [];

        try {
            foreach ($this->csvReader->read($filePath) as $csvRow) {
                $rowsRead++;
                $this->recordRawRowSample($diagnostics, $csvRow->rowNumber, $csvRow->row);
                $this->recordTransactionTypeSample($diagnostics, $csvRow->row);
                $issues = $this->validator->validate($csvRow->row);
                $hasErrors = false;

                foreach ($issues as $issue) {
                    $errorCount++;
                    $this->recordValidationIssue($diagnostics, $csvRow->rowNumber, $issue, $csvRow->row);
                    $this->recordIssue($batchId, $csvRow->rowNumber, $issue, $csvRow->row);
                    $hasErrors = $hasErrors || $issue->severity === 'error';
                }

                if ($hasErrors) {
                    $skippedRows++;
                    continue;
                }

                $rawTransaction = $this->rawTransactionRow($csvRow->rowNumber, $csvRow->row);

                if ($rawTransaction->externalTransactionId !== null && isset($seenTransactionIds[$rawTransaction->externalTransactionId])) {
                    $errorCount++;
                    $skippedRows++;
                    $duplicateRows++;
                    $this->recordIssue(
                        $batchId,
                        $csvRow->rowNumber,
                        new ValidationIssue('duplicate_transaction_in_file', "This transaction id already appeared on row {$seenTransactionIds[$rawTransaction->externalTransactionId]} of this CSV. The first row was imported and this duplicate row was skipped.", 'transaction_id', 'warning'),
                        $csvRow->row,
                    );

                    continue;
                }

                if ($rawTransaction->externalTransactionId !== null) {
                    $seenTransactionIds[$rawTransaction->externalTransactionId] = $csvRow->rowNumber;
                }

                $rawTransactionId = $this->createRawTransaction($batchId, $rawTransaction);
                $rawRowsCreated++;

                $normalized = $this->normalizer->normalize($rawTransaction, $rawTransactionId);
                $upsert = $this->normalizedTransactions->upsert($normalized);
                $normalizedRows++;
                if (! (bool) $upsert['created']) {
                    $duplicateRows++;
                }
                if ($normalized->turoTripNormalizedId === null) {
                    $unmatchedRows++;
                }

                if ($upsert['created']) {
                    $this->audit->created($actorUserId, 'turo_transactions_normalized', (int) $upsert['id'], $upsert['new']);
                } else {
                    $this->audit->updated($actorUserId, 'turo_transactions_normalized', (int) $upsert['id'], $upsert['old'], $upsert['new']);
                }
            }

            $this->batches->update($batchId, [
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'completed'),
                'row_count' => $rowsRead,
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => $errorCount === 0 ? null : "Completed with {$errorCount} row issue(s).",
            ]);

            $this->emitDiagnosticsReport(
                $batchId,
                $sourceFilename,
                $diagnostics,
                $rowsRead,
                $rawRowsCreated,
                $normalizedRows,
                $skippedRows,
                $duplicateRows,
                $unmatchedRows,
            );
        } catch (Throwable $exception) {
            $this->batches->update($batchId, [
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'failed'),
                'row_count' => $rowsRead,
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return new ImportResult($batchId, $rowsRead, $rawRowsCreated, $normalizedRows, 0, $errorCount, $skippedRows, $duplicateRows, $unmatchedRows);
    }

    /** @return array<string, mixed> */
    private function startDiagnostics(array $headers): array
    {
        return [
            'detected_headers' => $headers,
            'expected_headers' => [
                'transaction_id' => self::TRANSACTION_ID_ALIASES,
                'trip_id' => self::TRIP_ID_ALIASES,
                'reservation_url' => self::RESERVATION_URL_ALIASES,
                'transaction_type' => self::TRANSACTION_TYPE_ALIASES,
                'amount' => TuroEarningsAmountResolver::aliases(),
                'transaction_date' => self::DATE_ALIASES,
            ],
            'first_validation_failures' => [],
            'reason_counts' => [],
            'first_transaction_types' => [],
            'first_raw_rows' => [],
        ];
    }

    /** @param array<string, mixed> $diagnostics */
    private function recordRawRowSample(array &$diagnostics, int $rowNumber, array $row): void
    {
        if (count($diagnostics['first_raw_rows']) >= 5) {
            return;
        }

        $diagnostics['first_raw_rows'][] = [
            'row_number' => $rowNumber,
            'row' => $row,
        ];
    }

    /** @param array<string, mixed> $diagnostics */
    private function recordTransactionTypeSample(array &$diagnostics, array $row): void
    {
        $type = $this->normalizer->value($row, self::TRANSACTION_TYPE_ALIASES);
        if ($type === null) {
            return;
        }

        $type = preg_replace('/\s+/', ' ', trim($type)) ?? trim($type);
        if ($type === '' || in_array($type, $diagnostics['first_transaction_types'], true)) {
            return;
        }

        if (count($diagnostics['first_transaction_types']) >= 5) {
            return;
        }

        $diagnostics['first_transaction_types'][] = $type;
    }

    /** @param array<string, mixed> $diagnostics */
    private function recordValidationIssue(array &$diagnostics, int $rowNumber, ValidationIssue $issue, array $row): void
    {
        $resolvedAmount = $this->amountResolver->resolve($row);
        $reasonKey = $issue->severity . ':' . $issue->code;
        $diagnostics['reason_counts'][$reasonKey] = ($diagnostics['reason_counts'][$reasonKey] ?? 0) + 1;

        if (count($diagnostics['first_validation_failures']) >= 5) {
            return;
        }

        $diagnostics['first_validation_failures'][] = [
            'row_number' => $rowNumber,
            'severity' => $issue->severity,
            'code' => $issue->code,
            'field' => $issue->fieldName,
            'message' => $issue->message,
            'amount_probe' => $resolvedAmount?->rawValue,
            'amount_source_column_probe' => $resolvedAmount?->column,
            'transaction_type_probe' => $this->normalizer->value($row, self::TRANSACTION_TYPE_ALIASES),
            'transaction_date_probe' => $this->normalizer->value($row, self::DATE_ALIASES),
        ];
    }

    /** @param array<string, mixed> $diagnostics */
    private function emitDiagnosticsReport(
        int $batchId,
        string $sourceFilename,
        array $diagnostics,
        int $rowsRead,
        int $rawRowsCreated,
        int $normalizedRows,
        int $skippedRows,
        int $duplicateRows,
        int $unmatchedRows,
    ): void {
        arsort($diagnostics['reason_counts']);

        $report = [
            'batch_id' => $batchId,
            'source_filename' => $sourceFilename,
            'detected_headers' => $diagnostics['detected_headers'],
            'expected_headers' => $diagnostics['expected_headers'],
            'first_validation_failures' => $diagnostics['first_validation_failures'],
            'reason_counts' => $diagnostics['reason_counts'],
            'first_transaction_types' => $diagnostics['first_transaction_types'],
            'first_raw_rows' => $diagnostics['first_raw_rows'],
            'summary' => [
                'rows_read' => $rowsRead,
                'raw_rows_created' => $rawRowsCreated,
                'transactions_normalized' => $normalizedRows,
                'rows_skipped' => $skippedRows,
                'duplicate_rows' => $duplicateRows,
                'unmatched_rows' => $unmatchedRows,
                'all_rows_skipped_reason' => $this->summarizeAllRowsSkipped($diagnostics, $rowsRead, $skippedRows),
            ],
        ];

        log_message('notice', 'turo_earnings_import_diagnostics: ' . json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $diagnostics */
    private function summarizeAllRowsSkipped(array $diagnostics, int $rowsRead, int $skippedRows): string
    {
        if ($rowsRead === 0) {
            return 'No CSV data rows were parsed after header detection.';
        }

        if ($skippedRows !== $rowsRead) {
            return 'Not all rows were skipped; inspect reason_counts for partial failures.';
        }

        $headers = $diagnostics['detected_headers'];
        $reasonCounts = $diagnostics['reason_counts'];

        $hasAmountHeader = $this->containsAnyHeader($headers, TuroEarningsAmountResolver::aliases());
        $hasTypeHeader = $this->containsAnyHeader($headers, self::TRANSACTION_TYPE_ALIASES);
        $hasDateHeader = $this->containsAnyHeader($headers, self::DATE_ALIASES);

        if (! $hasAmountHeader) {
            return 'All rows were skipped due to header mismatch/field mapping: no recognized amount header was detected.';
        }

        if (isset($reasonCounts['error:invalid_money'])) {
            return 'All rows were skipped due to amount parsing failures (invalid_money).';
        }

        if (isset($reasonCounts['error:missing_amount'])) {
            return 'All rows were skipped due to required-field validation on amount (missing_amount), likely a field mapping mismatch.';
        }

        if (! $hasTypeHeader || isset($reasonCounts['warning:missing_transaction_type'])) {
            return 'Rows were skipped with transaction-type mapping warnings/errors; verify transaction type field mapping.';
        }

        if (! $hasDateHeader || isset($reasonCounts['error:invalid_transaction_date'])) {
            return 'Rows were skipped due to date/required-field validation issues; verify transaction date mapping and format.';
        }

        if ($reasonCounts !== []) {
            $topReason = array_key_first($reasonCounts);

            return "All rows were skipped due to validation failures led by {$topReason}.";
        }

        return 'All rows were skipped, but no validation reason was recorded; inspect duplicate handling and parser output samples.';
    }

    /** @param string[] $headers */
    private function containsAnyHeader(array $headers, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (in_array($alias, $headers, true)) {
                return true;
            }
        }

        return false;
    }

    private function rawTransactionRow(int $rowNumber, array $row): RawTransactionRow
    {
        $directTripId = $this->normalizer->value($row, self::TRIP_ID_ALIASES);
        $reservationUrl = $this->normalizer->value($row, self::RESERVATION_URL_ALIASES);
        $tripIdFromReservationUrl = $this->reservationUrlTripIdExtractor->extract($reservationUrl);

        return new RawTransactionRow(
            rowNumber: $rowNumber,
            payload: $row,
            externalTransactionId: $this->normalizer->value($row, self::TRANSACTION_ID_ALIASES),
            // Prefer explicit trip_id columns and only fall back to deterministic reservation URL extraction.
            externalTripId: $directTripId ?? $tripIdFromReservationUrl,
            transactionDate: $this->normalizer->value($row, self::DATE_ALIASES),
            rowHash: hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
        );
    }

    private function createRawTransaction(int $batchId, RawTransactionRow $row): int
    {
        return $this->rawTransactions->create([
            'turo_import_batch_id' => $batchId,
            'external_transaction_id' => $row->externalTransactionId,
            'external_trip_id' => $row->externalTripId,
            'transaction_date' => $row->transactionDate,
            'row_number' => $row->rowNumber,
            'row_hash' => $row->rowHash,
            'raw_payload' => $row->payload,
        ]);
    }

    private function recordIssue(int $batchId, int $rowNumber, ValidationIssue $issue, array $row): void
    {
        $this->errors->create([
            'turo_import_batch_id' => $batchId,
            'severity_lookup_value_id' => $this->lookups->valueId('import_error_severity', $issue->severity),
            'raw_table' => 'turo_transaction_raw',
            'raw_row_id' => null,
            'row_number' => $rowNumber,
            'error_code' => $issue->code,
            'field_name' => $issue->fieldName,
            'message' => $issue->message,
            'raw_payload' => $row,
        ]);
    }
}
