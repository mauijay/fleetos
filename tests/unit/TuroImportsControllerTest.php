<?php

use App\Controllers\TuroImports;
use App\DTOs\Turo\ImportResult;
use App\Services\Turo\TuroEarningsImportService;
use App\Services\Turo\TuroTripImportService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * @internal
 */
final class TuroImportsControllerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        service('superglobals')->setFilesArray([]);
    }

    public function testTripsFormRejectsEarningsShapedFileMessage(): void
    {
        Services::injectMock('turoTripImportService', new class () extends TuroTripImportService {
            public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
            {
                throw new \RuntimeException('This file matches earnings_export. Use the Earnings importer instead of the Trips importer.');
            }
        });

        $response = $this->controllerWithFile('trips_csv', $this->csvUpload('earnings.csv', [
            ['transaction_id', 'transaction_type', 'amount', 'transaction_date'],
            ['txn-1', 'Trip payment', '$10.00', '2026-01-01'],
        ]))->store();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('This file matches earnings_export. Use the Earnings importer instead of the Trips importer.', session()->getFlashdata('turo_trip_import_error'));
    }

    public function testEarningsFormRejectsTripShapedFileMessage(): void
    {
        Services::injectMock('turoEarningsImportService', new class () extends TuroEarningsImportService {
            public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
            {
                throw new \RuntimeException('This file matches trip_earnings_export. Use the Trips importer instead of the Earnings importer.');
            }
        });

        $response = $this->controllerWithFile('earnings_csv', $this->csvUpload('trips.csv', [
            ['trip_id', 'starts_at', 'ends_at', 'host_payout'],
            ['trip-1', '2026-01-01 10:00:00', '2026-01-02 10:00:00', '$50.00'],
        ]))->storeEarnings();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('This file matches trip_earnings_export. Use the Trips importer instead of the Earnings importer.', session()->getFlashdata('turo_earnings_import_error'));
    }

    public function testFormsUseIndependentFlashState(): void
    {
        Services::injectMock('turoEarningsImportService', new class () extends TuroEarningsImportService {
            public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
            {
                return new ImportResult(90, 2, 2, 2, 0, 1, 0, 0, 1);
            }
        });

        session()->setFlashdata('turo_trip_import_result', ['batch_id' => 44, 'rows_read' => 3]);

        $this->controllerWithFile('earnings_csv', $this->csvUpload('earnings.csv', [
            ['transaction_id', 'transaction_type', 'amount', 'transaction_date'],
            ['txn-1', 'Trip payment', '$10.00', '2026-01-01'],
        ]))->storeEarnings();

        $this->assertIsArray(session()->getFlashdata('turo_trip_import_result'));
        $this->assertSame(44, session()->getFlashdata('turo_trip_import_result')['batch_id']);
        $this->assertIsArray(session()->getFlashdata('turo_earnings_import_result'));
        $this->assertSame(90, session()->getFlashdata('turo_earnings_import_result')['batch_id']);
    }

    public function testEarningsSuccessSummaryIncludesRequiredCounts(): void
    {
        Services::injectMock('turoEarningsImportService', new class () extends TuroEarningsImportService {
            public function import(string $filePath, ?int $actorUserId = null, ?string $sourceFilename = null): ImportResult
            {
                return new ImportResult(12, 6, 5, 4, 0, 3, 2, 1, 2);
            }
        });

        $this->controllerWithFile('earnings_csv', $this->csvUpload('earnings.csv', [
            ['transaction_id', 'transaction_type', 'amount', 'transaction_date'],
            ['txn-1', 'Trip payment', '$10.00', '2026-01-01'],
        ]))->storeEarnings();

        $summary = session()->getFlashdata('turo_earnings_import_result');

        $this->assertSame(6, $summary['rows_read']);
        $this->assertSame(4, $summary['trips_normalized']);
        $this->assertSame(2, $summary['rows_skipped']);
        $this->assertSame(1, $summary['rows_duplicate']);
        $this->assertSame(2, $summary['rows_unmatched']);
        $this->assertSame(3, $summary['row_issues']);
    }

    private function controllerWithFile(string $field, UploadedFile $file): TuroImports
    {
        $request = $this->createMock(\CodeIgniter\HTTP\IncomingRequest::class);
        $request->method('getFile')->willReturnCallback(static fn (string $name): ?UploadedFile => $name === $field ? $file : null);

        $controller = new TuroImports();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    /** @param array<int, array<int, string>> $rows */
    private function csvUpload(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'controller_csv_');
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
}
