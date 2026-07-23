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
    ) {
    }

    public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
    {
        $headers = $this->csvReader->headers($filePath);

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
                $issues = $this->validator->validate($csvRow->row);
                $hasErrors = false;

                foreach ($issues as $issue) {
                    $errorCount++;
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

    private function rawTransactionRow(int $rowNumber, array $row): RawTransactionRow
    {
        return new RawTransactionRow(
            rowNumber: $rowNumber,
            payload: $row,
            externalTransactionId: $this->normalizer->value($row, ['transaction_id', 'earnings_id', 'payout_id']),
            externalTripId: $this->normalizer->value($row, ['trip_id', 'reservation_id', 'booking_id']),
            transactionDate: $this->normalizer->value($row, ['transaction_date', 'date', 'created_at', 'processed_at']),
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
