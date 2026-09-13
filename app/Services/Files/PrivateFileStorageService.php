<?php

namespace App\Services\Files;

use App\Repositories\FileRepository;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\AirportReceipts;
use Config\Services;

/** Airport facade over the shared parent-authorized private evidence store. */
class PrivateFileStorageService
{
    public function __construct(
        private readonly ?FileRepository $files = null,
        private readonly ?AirportReceipts $config = null,
        private readonly ?PrivateEvidenceStorageService $evidence = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function storeReceiptEvidence(UploadedFile $upload, ?string $documentDate = null): array
    {
        return $this->storage()->store(
            $upload,
            $this->settings()->storageDirectory,
            $this->settings()->allowedMimeTypes,
            $this->settings()->maxFileSizeBytes,
            $documentDate,
        );
    }

    /**
     * @param array<string, mixed> $receipt Metadata obtained through an authorized airport receipt.
     * @return array{path:string,metadata:array<string,mixed>}|null
     */
    public function resolveReceipt(array $receipt): ?array
    {
        return $this->storage()->resolve(
            $receipt,
            $this->settings()->storageDirectory,
            $this->settings()->allowedMimeTypes,
        );
    }

    private function storage(): PrivateEvidenceStorageService
    {
        return $this->evidence ?? new PrivateEvidenceStorageService($this->files ?? Services::fileRepository());
    }

    private function settings(): AirportReceipts
    {
        return $this->config ?? new AirportReceipts();
    }
}
