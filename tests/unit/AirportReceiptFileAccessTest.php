<?php

use App\Controllers\AirportReimbursements;
use App\Repositories\OperationalFactsRepository;
use App\Services\Files\PrivateFileStorageService;
use App\Services\Fleet\TuroAccessReimbursementService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\AirportReceipts;
use Config\Paths;
use Config\Services;

/** @internal */
final class AirportReceiptFileAccessTest extends CIUnitTestCase
{
    private string $baseDirectory;
    private string $storageDirectory;
    private string $storageRoot;
    private string $validPdf;

    protected function setUp(): void
    {
        parent::setUp();
        $token = bin2hex(random_bytes(8));
        $writableDirectory = rtrim((new Paths())->writableDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $this->baseDirectory = $writableDirectory . 'uploads/airport-receipt-access-tests/' . $token;
        $this->storageDirectory = 'airport-receipt-access-tests/' . $token . '/approved';
        $this->storageRoot = $writableDirectory . 'uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $this->storageDirectory);
        mkdir($this->storageRoot, 0775, true);
        $this->validPdf = $this->storageRoot . DIRECTORY_SEPARATOR . 'receipt.pdf';
        file_put_contents($this->validPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->removeDirectory($this->baseDirectory);
        parent::tearDown();
    }

    public function testValidReceiptMetadataResolvesInsideConfiguredRoot(): void
    {
        $resolved = $this->storage()->resolveReceipt($this->metadata($this->storageDirectory . '/receipt.pdf'));

        $this->assertNotNull($resolved);
        $this->assertSame(realpath($this->validPdf), $resolved['path']);
        $this->assertSame('application/pdf', $resolved['metadata']['mime_type']);
        $this->assertSame('Airport receipt.pdf', $resolved['metadata']['original_filename']);
    }

    public function testTraversalAbsoluteEscapedAndMalformedPathsFailClosed(): void
    {
        $outside = $this->baseDirectory . DIRECTORY_SEPARATOR . 'outside.pdf';
        file_put_contents($outside, "%PDF-1.4\n%%EOF\n");
        $paths = [
            $this->storageDirectory . '/../outside.pdf',
            str_replace(DIRECTORY_SEPARATOR, '/', $this->validPdf),
            dirname($this->storageDirectory) . '/outside.pdf',
            $this->storageDirectory . '/bad' . "\0" . '.pdf',
            str_replace('/', '\\', $this->storageDirectory) . '\\..\\outside.pdf',
        ];

        foreach ($paths as $path) {
            $this->assertNull($this->storage()->resolveReceipt($this->metadata($path)), $path);
        }
    }

    public function testMissingDeletedNonLocalAndMimeMismatchMetadataFailClosed(): void
    {
        $this->assertNull($this->storage()->resolveReceipt($this->metadata($this->storageDirectory . '/missing.pdf')));
        $this->assertNull($this->storage()->resolveReceipt($this->metadata($this->storageDirectory . '/receipt.pdf', ['file_deleted_at' => '2026-09-11 00:00:00'])));
        $this->assertNull($this->storage()->resolveReceipt($this->metadata($this->storageDirectory . '/receipt.pdf', ['file_storage_disk' => 'public'])));
        $this->assertNull($this->storage()->resolveReceipt($this->metadata($this->storageDirectory . '/receipt.pdf', ['file_mime_type' => 'image/png'])));
        $this->assertNull($this->storage()->resolveReceipt(array_merge($this->metadata($this->storageDirectory . '/receipt.pdf'), ['file_id' => null])));
    }

    public function testControllerStreamsInlineWithSafeHeadersAndReceiptParentIdentifiers(): void
    {
        $service = new AirportReceiptFileAccessTestService([
            'path' => realpath($this->validPdf),
            'metadata' => [
                'original_filename' => "Boarding\r\nX-Evil: yes; \"Q\".pdf",
                'mime_type' => 'application/pdf',
            ],
        ]);
        Services::injectMock('operationalFactsRepository', new AirportReceiptFileAccessTestCompanyRepository());
        Services::injectMock('turoAccessReimbursementService', $service);
        $request = $this->createStub(IncomingRequest::class);
        $controller = new AirportReimbursements();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());

        $response = $controller->receiptFile(42);

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertSame([7, 42], $service->requested);
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('inline; filename="', $disposition);
        $this->assertStringContainsString('Boarding', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('X-Evil:', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
        ob_start();
        $response->sendBody();
        $body = ob_get_clean();
        $this->assertSame(file_get_contents($this->validPdf), $body);
    }

    public function testControllerFailsClosedWithoutExactlyOneActiveCompany(): void
    {
        $service = new AirportReceiptFileAccessTestService([
            'path' => realpath($this->validPdf),
            'metadata' => ['original_filename' => 'receipt.pdf', 'mime_type' => 'application/pdf'],
        ]);
        Services::injectMock('operationalFactsRepository', new AirportReceiptFileAccessTestCompanyRepository([7, 8]));
        Services::injectMock('turoAccessReimbursementService', $service);
        $controller = new AirportReimbursements();
        $controller->initController($this->createStub(IncomingRequest::class), CoreServices::response(), CoreServices::logger());

        try {
            $controller->receiptFile(42);
            $this->fail('Expected a generic not-found response for an ambiguous company context.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $this->assertNull($service->requested);
        }
    }

    public function testConfiguredReceiptPreviewMimeTypesRemainRestricted(): void
    {
        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            (new AirportReceipts())->allowedMimeTypes,
        );
    }

    public function testInvalidUnmatchedReceiptVehicleReturnsOperatorSafeError(): void
    {
        $file = $this->createStub(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $request = $this->createStub(IncomingRequest::class);
        $request->method('getFile')->willReturn($file);
        $request->method('getPost')->willReturn(['fleet_vehicle_id' => '999']);
        Services::injectMock('operationalFactsRepository', new AirportReceiptFileAccessTestCompanyRepository());
        Services::injectMock('turoAccessReimbursementService', new AirportReceiptFileAccessInvalidVehicleService());
        $controller = new AirportReimbursements();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());

        $response = $controller->createUnmatchedReceipt();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Choose an active fleet vehicle or leave Vehicle unassigned.',
            CoreServices::session()->getFlashdata('airport_reimbursement_error'),
        );
    }

    public function testReceiptViewsExposeOnlyReceiptParentFileUrls(): void
    {
        foreach (['index.php', 'match.php'] as $view) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/Views/airport_reimbursements/' . $view);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('/files/receipts/', $source);
            $this->assertStringContainsString('/operations/airport/reimbursements/receipts/', $source);
            $this->assertStringContainsString('$receipt[\'id\']', $source);
        }
    }

    private function storage(): PrivateFileStorageService
    {
        $config = new AirportReceipts();
        $config->storageDirectory = $this->storageDirectory;

        return new PrivateFileStorageService(null, $config);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function metadata(string $path, array $overrides = []): array
    {
        return array_merge([
            'file_id' => 10,
            'file_storage_disk' => 'local',
            'file_path' => $path,
            'file_original_filename' => 'Airport receipt.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size_bytes' => filesize($this->validPdf),
            'file_checksum' => hash_file('sha256', $this->validPdf),
            'file_deleted_at' => null,
        ], $overrides);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class AirportReceiptFileAccessTestService extends TuroAccessReimbursementService
{
    /** @var array{path: string, metadata: array<string, mixed>} */
    private array $file;
    /** @var array{int, int}|null */
    public ?array $requested = null;

    /** @param array{path: string, metadata: array<string, mixed>} $file */
    public function __construct(array $file)
    {
        $this->file = $file;
    }

    public function receiptFile(int $companyId, int $receiptId): array
    {
        $this->requested = [$companyId, $receiptId];

        return $this->file;
    }
}

final class AirportReceiptFileAccessTestCompanyRepository extends OperationalFactsRepository
{
    /** @param array<int, int> $companyIds */
    public function __construct(private readonly array $companyIds = [7])
    {
    }

    public function activeFleetCompanyIds(?string $asOfDate = null): array
    {
        return $this->companyIds;
    }
}

final class AirportReceiptFileAccessInvalidVehicleService extends TuroAccessReimbursementService
{
    public function uploadUnmatchedReceipt(int $companyId, UploadedFile $upload, array $data): array
    {
        throw new InvalidArgumentException('Airport relationship is invalid.');
    }
}
