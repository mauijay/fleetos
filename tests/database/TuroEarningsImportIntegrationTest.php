<?php

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroRawTransactionRepository;
use App\Controllers\TuroImports;
use App\Services\Turo\TuroCsvReader;
use App\Services\Turo\TuroCsvShapeDetector;
use App\Services\Turo\TuroEarningsImportService;
use App\Services\Turo\TuroEarningsNormalizer;
use App\Services\Turo\TuroImportAuditService;
use App\Validation\Turo\TuroEarningsCsvValidator;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

/**
 * @internal
 */
final class TuroEarningsImportIntegrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroEarningsImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedLookups();
        $this->seedTrips();

        $lookups = new LookupRepository($this->connection);
        $this->service = new TuroEarningsImportService(
            new TuroCsvReader(),
            new TuroCsvShapeDetector(),
            new TuroEarningsCsvValidator(),
            new TuroEarningsNormalizer(new TuroNormalizedTripRepository($this->connection)),
            $lookups,
            new TuroImportBatchRepository($this->connection),
            new TuroRawTransactionRepository($this->connection),
            new TuroNormalizedTransactionRepository($this->connection),
            new TuroImportErrorRepository($this->connection),
            new TuroImportAuditService(new AuditLogRepository($this->connection), $lookups),
        );
    }

    public function testValidImportPreservesRawRowsNormalizesAmountsAndLinksTrips(): void
    {
        $file = $this->csvFile([
            'transaction_id',
            'trip_id',
            'transaction_type',
            'amount',
            'transaction_date',
        ], [
            ['txn-100', 'trip-100', 'Trip payment', '$125.50', '2026-01-10'],
            ['txn-101', '', 'Airport fee', '$15.00', '2026-01-10'],
        ]);

        $result = $this->service->import($file, null, 'earnings-valid.csv');

        $this->assertSame(2, $result->rowsRead);
        $this->assertSame(2, $result->rawRowsCreated);
        $this->assertSame(2, $result->tripsNormalized);
        $this->assertSame(0, $result->skippedRows);
        $this->assertSame(0, $result->duplicateRows);
        $this->assertSame(1, $result->unmatchedRows);
        $this->assertSame(0, $result->errorCount);
        $this->assertSame(2, $this->connection->table('turo_transaction_raw')->countAllResults());

        $linked = $this->connection->table('turo_transactions_normalized')->where('external_transaction_id', 'txn-100')->get()->getRowArray();
        $unmatched = $this->connection->table('turo_transactions_normalized')->where('external_transaction_id', 'txn-101')->get()->getRowArray();

        $this->assertSame('payment', $linked['normalized_type']);
        $this->assertSame('125.50', number_format((float) $linked['amount'], 2, '.', ''));
        $this->assertSame(501, (int) $linked['turo_trip_normalized_id']);
        $this->assertSame(9, (int) $linked['fleet_vehicle_id']);

        $this->assertSame('fee', $unmatched['normalized_type']);
        $this->assertNull($unmatched['turo_trip_normalized_id']);
        $this->assertNull($unmatched['fleet_vehicle_id']);
        $this->assertSame(1, $this->connection->table('turo_trips_normalized')->countAllResults());
    }

    public function testControllerPipelineImportsValidEarningsExportUsingDatabaseBackedService(): void
    {
        Services::reset();

        $upload = $this->csvUpload('earnings-controller.csv', [
            ['transaction_id', 'trip_id', 'transaction_type', 'amount', 'transaction_date'],
            ['txn-150', 'trip-100', 'Trip payment', '$150.00', '2026-01-10'],
        ]);

        $request = $this->createMock(\CodeIgniter\HTTP\IncomingRequest::class);
        $request->method('getFile')->willReturnCallback(static fn (string $name): ?UploadedFile => $name === 'earnings_csv' ? $upload : null);

        $controller = new TuroImports();
        $controller->initController($request, service('response'), service('logger'));

        $response = $controller->storeEarnings();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $summary = session()->getFlashdata('turo_earnings_import_result');
        $this->assertIsArray($summary);
        $this->assertSame(1, $summary['rows_read']);
        $this->assertSame(1, $summary['trips_normalized']);
        $this->assertSame(0, $summary['rows_skipped']);
        $this->assertSame(0, $summary['rows_duplicate']);
        $this->assertSame(0, $summary['rows_unmatched']);
        $this->assertSame(1, $this->connection->table('turo_transactions_normalized')->where('external_transaction_id', 'txn-150')->countAllResults());
    }

    public function testReimportingSameFileIsRejectedBySourceHash(): void
    {
        $file = $this->csvFile([
            'transaction_id', 'transaction_type', 'amount', 'transaction_date',
        ], [
            ['txn-200', 'Trip payment', '$90.00', '2026-01-11'],
        ]);

        $this->service->import($file, null, 'earnings-hash.csv');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This CSV source hash has already been imported.');
        $this->service->import($file, null, 'earnings-hash.csv');
    }

    public function testDuplicateExternalIdOrFingerprintAcrossDifferentFilesDoesNotDuplicateTransactions(): void
    {
        $fileOne = $this->csvFile([
            'transaction_id', 'trip_id', 'transaction_type', 'amount', 'transaction_date',
        ], [
            ['txn-300', 'trip-100', 'Trip payment', '$100.00', '2026-01-12'],
        ]);

        $fileTwo = $this->csvFile([
            'transaction_id', 'trip_id', 'transaction_type', 'amount', 'transaction_date', 'description',
        ], [
            ['txn-300', 'trip-100', 'Trip payment', '$105.00', '2026-01-12', 'updated payout'],
            ['', '', 'Adjustment', '$5.00', '2026-01-12', 'goodwill adjustment'],
        ]);

        $fileThree = $this->csvFile([
            'transaction_id', 'trip_id', 'transaction_type', 'amount', 'transaction_date', 'description',
        ], [
            ['', '', 'Adjustment', '$5.00', '2026-01-12', 'goodwill adjustment'],
        ]);

        $this->service->import($fileOne, null, 'earnings-a.csv');
        $secondResult = $this->service->import($fileTwo, null, 'earnings-b.csv');
        $thirdResult = $this->service->import($fileThree, null, 'earnings-c.csv');

        $this->assertSame(1, $secondResult->duplicateRows);
        $this->assertSame(1, $thirdResult->duplicateRows);
        $this->assertSame(2, $this->connection->table('turo_transactions_normalized')->countAllResults());

        $txn = $this->connection->table('turo_transactions_normalized')->where('external_transaction_id', 'txn-300')->get()->getRowArray();
        $this->assertSame('105.00', number_format((float) $txn['amount'], 2, '.', ''));
    }

    public function testPartiallyInvalidFileRecordsIssuesAndPreservesValidRows(): void
    {
        $file = $this->csvFile([
            'transaction_id', 'trip_id', 'transaction_type', 'amount', 'transaction_date',
        ], [
            ['txn-400', 'trip-100', 'Trip payment', '$120.00', '2026-01-13'],
            ['txn-401', 'trip-100', 'Trip payment', 'not-money', '2026-01-13'],
            ['txn-402', '', '', '$20.00', '2026-01-13'],
        ]);

        $result = $this->service->import($file, null, 'earnings-partial.csv');

        $this->assertSame(3, $result->rowsRead);
        $this->assertSame(2, $result->rawRowsCreated);
        $this->assertSame(2, $result->tripsNormalized);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame(0, $result->duplicateRows);
        $this->assertSame(1, $result->unmatchedRows);
        $this->assertSame(2, $result->errorCount);

        $this->assertSame(2, $this->connection->table('turo_transactions_normalized')->countAllResults());
        $this->assertSame(2, $this->connection->table('turo_import_errors')->countAllResults());
    }

    private function resetSchema(): void
    {
        foreach (['audit_logs', 'turo_import_errors', 'turo_transactions_normalized', 'turo_transaction_raw', 'turo_import_batches', 'turo_trips_normalized', 'fleet_vehicles', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80), name VARCHAR(150), description TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150), description TEXT NULL, sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, metadata TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NULL, turo_trip_id VARCHAR(80), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type_lookup_value_id INTEGER NULL, import_status_lookup_value_id INTEGER NULL, source_filename VARCHAR(190), source_hash VARCHAR(128), row_count INTEGER DEFAULT 0, started_at DATETIME NULL, completed_at DATETIME NULL, error_message TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('turo_import_batches_source_hash_unique') . ' ON ' . $this->table('turo_import_batches') . ' (source_hash)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transaction_raw') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, external_transaction_id VARCHAR(80) NULL, external_trip_id VARCHAR(80) NULL, transaction_date DATE NULL, row_number INTEGER NULL, row_hash VARCHAR(128), raw_payload TEXT, created_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('turo_transaction_raw_batch_hash_unique') . ' ON ' . $this->table('turo_transaction_raw') . ' (turo_import_batch_id, row_hash)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transactions_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_transaction_raw_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, external_transaction_id VARCHAR(80) NULL, external_trip_id VARCHAR(80) NULL, transaction_type VARCHAR(120), normalized_type VARCHAR(40), description VARCHAR(255) NULL, amount DECIMAL(10,2), currency_code CHAR(3), transaction_date DATE NULL, row_fingerprint VARCHAR(128), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('turo_transactions_normalized_external_txn_unique') . ' ON ' . $this->table('turo_transactions_normalized') . ' (external_transaction_id)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('turo_transactions_normalized_fingerprint_unique') . ' ON ' . $this->table('turo_transactions_normalized') . ' (row_fingerprint)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, severity_lookup_value_id INTEGER NULL, raw_table VARCHAR(80) NULL, raw_row_id INTEGER NULL, row_number INTEGER NULL, error_code VARCHAR(120), field_name VARCHAR(120) NULL, message TEXT, raw_payload TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)');
    }

    private function seedLookups(): void
    {
        $this->connection->table('lookup_types')->insertBatch([
            ['id' => 1, 'code' => 'import_type', 'name' => 'Import Type'],
            ['id' => 2, 'code' => 'import_status', 'name' => 'Import Status'],
            ['id' => 3, 'code' => 'import_error_severity', 'name' => 'Import Error Severity'],
            ['id' => 4, 'code' => 'audit_action', 'name' => 'Audit Action'],
        ]);

        $this->connection->table('lookup_values')->insertBatch([
            ['lookup_type_id' => 1, 'code' => 'turo_transactions', 'name' => 'Turo Transactions', 'is_active' => 1],
            ['lookup_type_id' => 2, 'code' => 'processing', 'name' => 'Processing', 'is_active' => 1],
            ['lookup_type_id' => 2, 'code' => 'completed', 'name' => 'Completed', 'is_active' => 1],
            ['lookup_type_id' => 2, 'code' => 'failed', 'name' => 'Failed', 'is_active' => 1],
            ['lookup_type_id' => 3, 'code' => 'error', 'name' => 'Error', 'is_active' => 1],
            ['lookup_type_id' => 3, 'code' => 'warning', 'name' => 'Warning', 'is_active' => 1],
            ['lookup_type_id' => 4, 'code' => 'created', 'name' => 'Created', 'is_active' => 1],
            ['lookup_type_id' => 4, 'code' => 'updated', 'name' => 'Updated', 'is_active' => 1],
            ['lookup_type_id' => 4, 'code' => 'imported', 'name' => 'Imported', 'is_active' => 1],
        ]);
    }

    private function seedTrips(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 9, 'fleet_code' => 'Spaceship-009', 'display_name' => 'Spaceship-009', 'deleted_at' => null]);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 501, 'fleet_vehicle_id' => 9, 'turo_trip_id' => 'trip-100', 'deleted_at' => null]);
    }

    /** @param array<int, array<int, string>> $rows */
    private function csvUpload(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'earnings_upload_');
        $handle = fopen($path, 'wb');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new class ($path, $name, 'text/csv', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
            {
                $name ??= $this->getClientName();
                if (! is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }

                $destination = rtrim($targetPath, '/\\') . DIRECTORY_SEPARATOR . $name;
                copy($this->getTempName(), $destination);

                return true;
            }
        };
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    private function csvFile(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'earnings_csv_');
        $handle = fopen($path, 'wb');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
