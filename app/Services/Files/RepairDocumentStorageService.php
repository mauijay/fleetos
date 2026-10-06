<?php

namespace App\Services\Files;

use App\Repositories\FileRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\VehicleDamageRepairDocuments as Policy;
use InvalidArgumentException;
use RuntimeException;

/** Binary authority is reached only through an owned repair document. */
class RepairDocumentStorageService
{
    private PrivateEvidenceStorageService $storage;

    public function __construct(BaseConnection $db)
    {
        $this->storage = new PrivateEvidenceStorageService(new FileRepository($db));
    }

    public function descriptor(UploadedFile $upload): array
    {
        $path = $upload->getTempName();
        if (! is_file($path) || is_link($path) || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose a valid repair document upload.');
        }
        $size = filesize($path);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $name = $upload->getClientName();
        if ($size === false || $size < 1 || $size > Policy::MAX_BYTES || ! in_array($mime, Policy::MIME_TYPES, true)
            || $name === '' || strlen($name) > 190 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('Repair document must be PDF, JPEG, PNG, or WebP, at most 10 MiB, with a valid filename.');
        }
        return ['checksum' => hash_file('sha256', $path), 'mime_type' => $mime, 'size_bytes' => $size,
            'original_filename' => $this->storage->safeResponseFilename($name, $mime, 'repair-document')];
    }

    public function store(int $company, UploadedFile $upload, array $expected, int $actor, int $candidateId = 0): array
    {
        $actual = $this->descriptor($upload);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Upload content differs from the confirmed source descriptor.');
        }
        $stored = $this->storage->store($upload, $this->directory($company), Policy::MIME_TYPES, Policy::MAX_BYTES, null, $actor, true, $candidateId);
        $stored['descriptor'] = $actual;
        return $stored;
    }

    public function resolve(int $company, array $document, ?array $metadata): ?array
    {
        if ($metadata === null || (int) $document['company_id'] !== $company) {
            return null;
        }
        $alias = [];
        foreach ($metadata as $key => $value) {
            $alias['file_' . $key] = $value;
        }
        foreach (['checksum', 'mime_type', 'size_bytes'] as $field) {
            if ($document['content_' . $field] === null || (string) $document['content_' . $field] !== (string) ($metadata[$field] ?? '')) {
                return null;
            }
        }
        if ((int) $document['content_size_bytes'] < 1 || (int) $document['content_size_bytes'] > Policy::MAX_BYTES) {
            return null;
        }
        $resolved = $this->storage->resolve($alias, $this->directory($company), Policy::MIME_TYPES);
        if ($resolved !== null) {
            $resolved['metadata']['original_filename'] = $document['content_original_filename'];
        }
        return $resolved;
    }

    public function discard(array $stored): void
    {
        $this->storage->discardNewFile($stored);
    }

    public function filename(array $document): string
    {
        return $this->storage->safeResponseFilename($document['content_original_filename'], $document['content_mime_type'], 'repair-document');
    }

    private function directory(int $company): string
    {
        if ($company < 1) {
            throw new RuntimeException('An owned company is required for private storage.');
        }
        return 'repair-documents/company-' . $company;
    }
}
