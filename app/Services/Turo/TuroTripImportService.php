<?php

namespace App\Services\Turo;

use App\DTOs\Turo\ImportResult;
use App\DTOs\Turo\RawTripRow;
use App\DTOs\Turo\RowImportResult;
use App\DTOs\Turo\ValidationIssue;
use App\Repositories\LookupRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroRawTripRepository;
use App\Validation\Turo\TuroTripCsvValidator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

class TuroTripImportService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly TuroCsvReader $csvReader = new TuroCsvReader(),
        private readonly TuroCsvShapeDetector $shapeDetector = new TuroCsvShapeDetector(),
        private readonly TuroTripCsvValidator $validator = new TuroTripCsvValidator(),
        private readonly TuroTripNormalizer $normalizer = new TuroTripNormalizer(),
        private readonly TripMonthAllocationService $allocationService = new TripMonthAllocationService(),
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly TuroImportBatchRepository $batches = new TuroImportBatchRepository(),
        private readonly TuroRawTripRepository $rawTrips = new TuroRawTripRepository(),
        private readonly TuroNormalizedTripRepository $normalizedTrips = new TuroNormalizedTripRepository(),
        private readonly TripMonthAllocationRepository $allocations = new TripMonthAllocationRepository(),
        private readonly TuroImportErrorRepository $errors = new TuroImportErrorRepository(),
        private readonly TuroImportAuditService $audit = new TuroImportAuditService(),
    ) {
        $this->db = $db ?? Database::connect();
    }

    public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
    {
        $headers = $this->csvReader->headers($filePath);

        if ($this->shapeDetector->isEarningsExport($headers)) {
            throw new RuntimeException('This file matches earnings_export. Use the Earnings importer instead of the Trips importer.');
        }

        if (! $this->shapeDetector->isTripExport($headers)) {
            throw new RuntimeException('This CSV does not match trip_earnings_export. Upload a Turo trip earnings export with trip id and trip start/end columns.');
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
            'import_type_lookup_value_id' => $this->lookups->valueId('import_type', 'turo_trips'),
            'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'processing'),
            'source_filename' => $sourceFilename,
            'source_hash' => $sourceHash,
            'created_by' => $actorUserId,
        ]);
        $this->audit->imported($actorUserId, 'turo_import_batches', $batchId, ['source_filename' => $sourceFilename, 'source_hash' => $sourceHash]);

        $rowsRead = 0;
        $rawRowsCreated = 0;
        $tripsNormalized = 0;
        $allocationRowsCreated = 0;
        $errorCount = 0;
        $seenTripIds = [];

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
                    continue;
                }

                $rawTripRow = $this->rawTripRow($csvRow->rowNumber, $csvRow->row);

                if ($rawTripRow->externalTripId !== null && isset($seenTripIds[$rawTripRow->externalTripId])) {
                    $errorCount++;
                    $this->recordIssue(
                        $batchId,
                        $csvRow->rowNumber,
                        new ValidationIssue('duplicate_trip_in_file', "This trip id already appeared on row {$seenTripIds[$rawTripRow->externalTripId]} of this CSV. The first row was imported and this duplicate row was skipped.", 'trip_id', 'warning'),
                        $csvRow->row,
                    );

                    continue;
                }

                if ($rawTripRow->externalTripId !== null) {
                    $seenTripIds[$rawTripRow->externalTripId] = $csvRow->rowNumber;
                }

                $rawTripId = $this->createRawTrip($batchId, $rawTripRow);
                $rawRowsCreated++;

                $rowResult = $this->persistNormalizedRow($rawTripRow, $rawTripId, $actorUserId);
                $tripsNormalized++;

                if (count($rowResult->issues) > 0) {
                    $errorCount++;
                    $this->recordIssue(
                        $batchId,
                        $csvRow->rowNumber,
                        $rowResult->issues[0],
                        $csvRow->row,
                        'turo_trip_raw',
                        $rawTripId,
                    );
                }
                $allocationRowsCreated += $rowResult->allocationCount;
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

        return new ImportResult($batchId, $rowsRead, $rawRowsCreated, $tripsNormalized, $allocationRowsCreated, $errorCount);
    }

    public function importStoredRow(int $batchId, int $rowNumber, array $row, ?int $rawTripId = null, ?int $actorUserId = null): RowImportResult
    {
        $issues = $this->validator->validate($row);
        $hasErrors = false;

        foreach ($issues as $issue) {
            $hasErrors = $hasErrors || $issue->severity === 'error';
        }

        if ($hasErrors) {
            return new RowImportResult(false, issues: $issues);
        }

        $rawTripRow = $this->rawTripRow($rowNumber, $row);
        $rawTripId ??= $this->createRawTrip($batchId, $rawTripRow);

        return $this->persistNormalizedRow($rawTripRow, $rawTripId, $actorUserId);
    }

    private function rawTripRow(int $rowNumber, array $row): RawTripRow
    {
        return new RawTripRow(
            rowNumber: $rowNumber,
            payload: $row,
            externalTripId: $this->normalizer->value($row, ['trip_id', 'reservation_id', 'booking_id']),
            externalVehicleId: $this->normalizer->value($row, ['vehicle_id', 'turo_vehicle_id', 'car_id']),
            rowHash: hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
        );
    }

    private function createRawTrip(int $batchId, RawTripRow $rawTripRow): int
    {
        return $this->rawTrips->create([
            'turo_import_batch_id' => $batchId,
            'external_trip_id' => $rawTripRow->externalTripId,
            'external_vehicle_id' => $rawTripRow->externalVehicleId,
            'row_number' => $rawTripRow->rowNumber,
            'row_hash' => $rawTripRow->rowHash,
            'raw_payload' => $rawTripRow->payload,
        ]);
    }

    private function persistNormalizedRow(RawTripRow $rawTripRow, int $rawTripId, ?int $actorUserId = null): RowImportResult
    {
        $this->db->transStart();

        $normalizedTrip = $this->normalizer->normalize($rawTripRow, $rawTripId);
        $upsert = $this->normalizedTrips->upsert($normalizedTrip);
        $tripAllocations = $this->allocationService->allocate($normalizedTrip);
        $this->allocations->replaceForTrip($upsert['id'], $tripAllocations);

        if ($upsert['created']) {
            $this->audit->created($actorUserId, 'turo_trips_normalized', $upsert['id'], $upsert['new']);
        } else {
            $this->audit->updated($actorUserId, 'turo_trips_normalized', $upsert['id'], $upsert['old'], $upsert['new']);
        }

        $this->audit->imported($actorUserId, 'trip_month_allocations', $upsert['id'], ['allocation_count' => count($tripAllocations)]);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new RuntimeException("Database transaction failed for CSV row {$rawTripRow->rowNumber}.");
        }

        $issues = [];
        if ($normalizedTrip->fleetVehicleId === null) {
            $issues[] = new ValidationIssue('vehicle_unmatched', 'Trip imported, but no fleet vehicle could be matched. Check the Vehicle ID, Turo Vehicle ID, or Fleet Code in this row against the fleet vehicle record.', 'external_vehicle_id', 'warning');
        }

        return new RowImportResult(true, (int) $upsert['id'], (bool) $upsert['created'], count($tripAllocations), $issues);
    }

    private function recordIssue(int $batchId, int $rowNumber, ValidationIssue $issue, array $row, ?string $rawTable = null, ?int $rawRowId = null): void
    {
        $this->errors->create([
            'turo_import_batch_id' => $batchId,
            'severity_lookup_value_id' => $this->lookups->valueId('import_error_severity', $issue->severity),
            'raw_table' => $rawTable,
            'raw_row_id' => $rawRowId,
            'row_number' => $rowNumber,
            'error_code' => $issue->code,
            'field_name' => $issue->fieldName,
            'message' => $issue->message,
            'raw_payload' => $row,
        ]);
    }
}
