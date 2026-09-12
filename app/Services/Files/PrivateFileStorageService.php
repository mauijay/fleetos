<?php

namespace App\Services\Files;

use App\Repositories\FileRepository;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\AirportReceipts;
use Config\Paths;
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
        $absolutePath = $this->writableDirectory() . 'uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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

    /**
     * Resolve only file metadata obtained through an authorized airport receipt.
     * Raw files.id values are intentionally insufficient for file access.
     *
     * @param array<string, mixed> $receipt
     * @return array{path: string, metadata: array<string, mixed>}|null
     */
    public function resolveReceipt(array $receipt): ?array
    {
        if ((int) ($receipt['file_id'] ?? 0) < 1
            || ($receipt['file_storage_disk'] ?? null) !== 'local'
            || ($receipt['file_deleted_at'] ?? null) !== null) {
            return null;
        }

        $relativePath = (string) ($receipt['file_path'] ?? '');
        if (! $this->isSafeRelativePath($relativePath)) {
            return null;
        }

        $receiptRoot = $this->receiptStorageRoot();
        if ($receiptRoot === null) {
            return null;
        }

        $path = realpath($this->writableDirectory() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($path === false || ! is_file($path) || ! $this->isWithin($path, $receiptRoot)) {
            return null;
        }

        $mimeType = (string) ($receipt['file_mime_type'] ?? '');
        $actualMimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mimeType, $this->config()->allowedMimeTypes, true) || $actualMimeType !== $mimeType) {
            return null;
        }

        return [
            'path' => $path,
            'metadata' => [
                'id' => (int) $receipt['file_id'],
                'original_filename' => $receipt['file_original_filename'] ?? null,
                'mime_type' => $mimeType,
                'size_bytes' => isset($receipt['file_size_bytes']) ? (int) $receipt['file_size_bytes'] : null,
                'checksum' => $receipt['file_checksum'] ?? null,
            ],
        ];
    }

    private function receiptStorageRoot(): ?string
    {
        $directory = str_replace('\\', '/', trim($this->config()->storageDirectory));
        if (! $this->isSafeRelativePath($directory)) {
            return null;
        }

        $uploadsRoot = realpath($this->writableDirectory() . 'uploads');
        $receiptRoot = realpath($this->writableDirectory() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory));
        if ($uploadsRoot === false || $receiptRoot === false || ! is_dir($receiptRoot) || ! $this->isWithin($receiptRoot, $uploadsRoot)) {
            return null;
        }

        return $receiptRoot;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        $storageDirectory = trim(str_replace('\\', '/', $this->config()->storageDirectory), '/');

        return $normalized === $storageDirectory || str_starts_with($normalized, $storageDirectory . '/');
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function repo(): FileRepository
    {
        return $this->files ?? service('fileRepository');
    }

    private function writableDirectory(): string
    {
        return rtrim((new Paths())->writableDirectory, '/\\') . DIRECTORY_SEPARATOR;
    }

    private function config(): AirportReceipts
    {
        return $this->config ?? config(AirportReceipts::class);
    }
}
