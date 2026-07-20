<?php

namespace App\Services\Files;

use App\Repositories\FileRepository;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\AirportReceipts;
use RuntimeException;

class PrivateFileStorageService
{
    public function __construct(
        private readonly ?FileRepository $files = null,
        private readonly ?AirportReceipts $config = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function storeReceiptEvidence(UploadedFile $upload, ?string $documentDate = null): array
    {
        if (! $upload->isValid() && ENVIRONMENT !== 'testing') {
            throw new RuntimeException('Receipt upload failed. Choose the file again and retry.');
        }

        if ($upload->getSize() <= 0) {
            throw new RuntimeException('Receipt file is empty.');
        }

        if ($upload->getSize() > $this->config()->maxFileSizeBytes) {
            throw new RuntimeException('Receipt file is larger than the configured upload limit.');
        }

        $tempPath = $upload->getTempName();
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath);
        if (! in_array($mimeType, $this->config()->allowedMimeTypes, true)) {
            throw new RuntimeException('Receipt file type is not supported. Upload JPEG, PNG, WebP, or PDF evidence.');
        }

        $checksum = hash_file('sha256', $tempPath);
        $existing = $this->repo()->findByChecksum($checksum);
        if ($existing !== null) {
            return ['file_id' => (int) $existing['id'], 'duplicate' => true, 'file' => $existing];
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $relativePath = $this->config()->storageDirectory . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = WRITEPATH . 'uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($absolutePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (! rename($tempPath, $absolutePath)) {
            throw new RuntimeException('Receipt file could not be stored.');
        }

        $fileId = $this->repo()->create([
            'storage_disk' => 'local',
            'path' => $relativePath,
            'original_filename' => $upload->getClientName(),
            'mime_type' => $mimeType,
            'size_bytes' => $upload->getSize(),
            'document_date' => $documentDate,
            'checksum' => $checksum,
        ]);

        return ['file_id' => $fileId, 'duplicate' => false, 'file' => $this->repo()->find($fileId)];
    }

    /** @return array{path: string, metadata: array<string, mixed>}|null */
    public function resolve(int $fileId): ?array
    {
        $file = $this->repo()->find($fileId);
        if ($file === null || ($file['storage_disk'] ?? '') !== 'local') {
            return null;
        }

        $path = WRITEPATH . 'uploads/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
        if (! is_file($path)) {
            return null;
        }

        return ['path' => $path, 'metadata' => $file];
    }

    private function repo(): FileRepository
    {
        return $this->files ?? service('fileRepository');
    }

    private function config(): AirportReceipts
    {
        return $this->config ?? config(AirportReceipts::class);
    }
}
