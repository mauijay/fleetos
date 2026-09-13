<?php

namespace App\Services\Files;

use App\Repositories\FileRepository;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Paths;
use Config\Services;
use RuntimeException;

class PrivateEvidenceStorageService
{
    public function __construct(private readonly ?FileRepository $files = null)
    {
    }

    /**
     * @param list<string> $allowedMimeTypes
     * @return array{file_id:int,duplicate:bool,file:array<string,mixed>,absolute_path:?string}
     */
    public function store(
        UploadedFile $upload,
        string $storageDirectory,
        array $allowedMimeTypes,
        int $maxFileSizeBytes,
        ?string $documentDate = null,
        ?int $uploadedBy = null,
    ): array {
        $environment = defined('ENVIRONMENT') ? constant('ENVIRONMENT') : 'production';
        if (! $upload->isValid() && $environment !== 'testing') {
            throw new RuntimeException('Evidence upload failed. Choose the file again and retry.');
        }
        if ($upload->getSize() <= 0) {
            throw new RuntimeException('Evidence file is empty.');
        }
        if ($upload->getSize() > $maxFileSizeBytes) {
            throw new RuntimeException('Evidence file is larger than the configured upload limit.');
        }

        $tempPath = $upload->getTempName();
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath);
        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('Evidence file type is not supported. Upload JPEG, PNG, WebP, or PDF evidence.');
        }

        $directory = $this->validatedStorageDirectory($storageDirectory);
        $checksum = hash_file('sha256', $tempPath);
        $existing = $this->repo()->findByChecksumInDirectory($checksum, $directory);
        if ($existing !== null) {
            return ['file_id' => (int) $existing['id'], 'duplicate' => true, 'file' => $existing, 'absolute_path' => null];
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $relativePath = $directory . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = $this->writableDirectory() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $parent = dirname($absolutePath);
        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new RuntimeException('Private evidence directory could not be created.');
        }
        if (! rename($tempPath, $absolutePath)) {
            throw new RuntimeException('Evidence file could not be stored.');
        }

        try {
            $fileId = $this->repo()->create([
                'storage_disk' => 'local',
                'path' => $relativePath,
                'original_filename' => $upload->getClientName(),
                'mime_type' => $mimeType,
                'size_bytes' => $upload->getSize(),
                'document_date' => $documentDate,
                'checksum' => $checksum,
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (\Throwable $exception) {
            @unlink($absolutePath);
            throw $exception;
        }

        return ['file_id' => $fileId, 'duplicate' => false, 'file' => $this->repo()->find($fileId) ?? [], 'absolute_path' => $absolutePath];
    }

    /**
     * Resolve only metadata obtained through an already-authorized business parent.
     *
     * @param array<string, mixed> $metadata
     * @param list<string> $allowedMimeTypes
     * @return array{path:string,metadata:array<string,mixed>}|null
     */
    public function resolve(array $metadata, string $storageDirectory, array $allowedMimeTypes): ?array
    {
        if ((int) ($metadata['file_id'] ?? 0) < 1
            || ($metadata['file_storage_disk'] ?? null) !== 'local'
            || ($metadata['file_deleted_at'] ?? null) !== null) {
            return null;
        }

        $relativePath = (string) ($metadata['file_path'] ?? '');
        $directory = $this->safeStorageDirectory($storageDirectory);
        if ($directory === null || ! $this->isSafeRelativePath($relativePath, $directory)) {
            return null;
        }

        $root = $this->storageRoot($directory);
        if ($root === null) {
            return null;
        }
        $path = realpath($this->writableDirectory() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($path === false || ! is_file($path) || ! $this->isWithin($path, $root)) {
            return null;
        }

        $mimeType = (string) ($metadata['file_mime_type'] ?? '');
        $actualMimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mimeType, $allowedMimeTypes, true) || $actualMimeType !== $mimeType) {
            return null;
        }

        return [
            'path' => $path,
            'metadata' => [
                'id' => (int) $metadata['file_id'],
                'original_filename' => $metadata['file_original_filename'] ?? null,
                'mime_type' => $mimeType,
                'size_bytes' => isset($metadata['file_size_bytes']) ? (int) $metadata['file_size_bytes'] : null,
                'checksum' => $metadata['file_checksum'] ?? null,
            ],
        ];
    }

    /** @param array{duplicate:bool,absolute_path:?string} $stored */
    public function discardNewFile(array $stored): void
    {
        if (! $stored['duplicate'] && $stored['absolute_path'] !== null && is_file($stored['absolute_path'])) {
            @unlink($stored['absolute_path']);
        }
    }

    public function safeResponseFilename(?string $filename, string $mimeType, string $fallback = 'evidence'): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $basename = basename(str_replace('\\', '/', str_replace(["\r", "\n", "\0"], '', (string) $filename)));
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9._() -]+/', '_', $stem) ?? '';
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? '', " .-_\t\n\r\0\x0B");

        return substr($stem === '' ? $fallback : $stem, 0, 100) . '.' . $extension;
    }

    private function validatedStorageDirectory(string $storageDirectory): string
    {
        $directory = $this->safeStorageDirectory($storageDirectory);
        if ($directory === null) {
            throw new RuntimeException('Private evidence storage directory is invalid.');
        }

        return $directory;
    }

    private function safeStorageDirectory(string $storageDirectory): ?string
    {
        $directory = trim(str_replace('\\', '/', trim($storageDirectory)), '/');
        if ($directory === '' || ! $this->isSafeRelativePath($directory, $directory)) {
            return null;
        }

        return $directory;
    }

    private function storageRoot(string $directory): ?string
    {
        $uploads = $this->writableDirectory() . 'uploads';
        $target = $uploads . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (! is_dir($target)) {
            return null;
        }
        $uploadsRoot = realpath($uploads);
        $root = realpath($target);

        return $uploadsRoot !== false && $root !== false && $this->isWithin($root, $uploadsRoot) ? $root : null;
    }

    private function isSafeRelativePath(string $path, string $requiredRoot): bool
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

        return $normalized === $requiredRoot || str_starts_with($normalized, $requiredRoot . '/');
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
        return $this->files ?? Services::fileRepository();
    }

    private function writableDirectory(): string
    {
        return rtrim((new Paths())->writableDirectory, '/\\') . DIRECTORY_SEPARATOR;
    }
}
