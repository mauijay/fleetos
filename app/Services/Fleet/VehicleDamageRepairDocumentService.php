<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairDocumentRepository;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Services\Files\RepairDocumentStorageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\VehicleDamageRepairDocuments as Policy;
use InvalidArgumentException;

/** Participates in the canonical work command transaction; never commits. */
class VehicleDamageRepairDocumentService
{
    public readonly VehicleDamageRepairDocumentRepository $documents;
    public readonly RepairDocumentStorageService $storage;
    private array $created = [];
    public int $uploadCandidateId = 0;

    public function __construct(BaseConnection $db)
    {
        $this->connection = $db;
        $this->documents = new VehicleDamageRepairDocumentRepository($db, true);
        $this->storage = new RepairDocumentStorageService($db);
    }

    /** Determine storage reuse before acquiring lower-order aggregate locks. */
    public function prepareUpload(int $company, array $data): void
    {
        if (($data['upload'] ?? null) instanceof UploadedFile) {
            $candidate = (new \App\Repositories\FileRepository($this->connection))->findByChecksumInDirectory($data['descriptor']['checksum'], 'repair-documents/company-' . $company);
            $this->uploadCandidateId = (int) ($candidate['id'] ?? 0);
        }
    }

    public function normalizeSource(array $source, int $company, int $vehicle): array
    {
        if (($source['upload'] ?? null) instanceof UploadedFile && is_file($source['upload']->getTempName())) {
            $descriptor = $this->storage->descriptor($source['upload']);
            if (isset($source['descriptor']) && $source['descriptor'] !== $descriptor) {
                throw new InvalidArgumentException('Upload descriptor has changed.');
            }
            $source['descriptor'] = $descriptor;
        }
        if (! empty($source['source_document_id'])) {
            $sourceVehicle = (int) ($source['source_vehicle_id'] ?? 0) ?: $vehicle;
            // This preliminary owned read supplies immutable semantic identity only.
            // All authority and current content are validated again under ordered locks.
            $repository = new VehicleDamageRepairDocumentRepository($this->connection);
            $doc = $repository->document($company, $sourceVehicle, (int) ($source['source_job_id'] ?? 0), (int) $source['source_document_id']);
            if ($doc === null) {
                throw new InvalidArgumentException('Owned source document not found.');
            }
            $descriptor = ['checksum' => $doc['content_checksum'], 'mime_type' => $doc['content_mime_type'], 'size_bytes' => (int) $doc['content_size_bytes'], 'original_filename' => $doc['content_original_filename']];
            if (isset($source['descriptor']) && $source['descriptor'] !== $descriptor) {
                throw new InvalidArgumentException('Owned source descriptor has changed.');
            }
            $source['descriptor'] = $descriptor;
        }
        return $source;
    }

    public function attach(int $company, int $vehicle, int $job, array $data, int $actor, string $now, ?int $newEstimate = null): array
    {
        $kind = (string) ($data['kind_code'] ?? '');
        if (! array_key_exists($kind, Policy::KINDS)) {
            throw new InvalidArgumentException('Choose a supported repair document kind.');
        }
        if (isset($data['file_id']) || isset($data['image_id'])) {
            throw new InvalidArgumentException('Raw file or image IDs cannot authorize repair documents.');
        }
        $estimate = $newEstimate ?? ((int) ($data['estimate_id'] ?? 0) ?: null);
        $member = (int) ($data['membership_id'] ?? 0) ?: null;
        $estimates = new Estimates($this->db());
        if ($estimate !== null && $estimates->estimate($company, $vehicle, $job, $estimate) === null) {
            throw new InvalidArgumentException('Estimate not found in this work context.');
        }
        $work = new \App\Repositories\VehicleDamageRepairRepository($this->db());
        if ($member !== null && ! array_any($work->members($company, $vehicle, $job), fn ($m) => (int) $m['id'] === $member)) {
            throw new InvalidArgumentException('Membership not found in this work context.');
        }
        if ($member !== null && $estimate !== null && ! in_array($member, array_map('intval', array_column($estimates->scope($company, $job, $estimate), 'vehicle_damage_repair_job_item_id')), true)) {
            throw new InvalidArgumentException('Document membership is outside the frozen estimate scope.');
        }
        $external = self::text($data['external_reference'] ?? null, 500);
        $reuse = (int) ($data['source_document_id'] ?? 0);
        $binary = ($data['upload'] ?? null) instanceof UploadedFile;
        if ((int) ($external !== null) + (int) ($reuse > 0) + (int) $binary !== 1) {
            throw new InvalidArgumentException('Supply exactly one upload, owned source document, or external reference.');
        }
        if (in_array($kind, \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS, true)) {
            (new \App\Repositories\VehicleDamageRepairCostRepository($this->db()))->requireReady();
            if ($external !== null) {
                throw new InvalidArgumentException('Monetary source documents require verified binary evidence.');
            }
        }
        $file = null;
        $image = null;
        $descriptor = null;
        if ($external !== null) {
            self::external($external);
        } elseif ($reuse > 0) {
            $sourceJob = (int) ($data['source_job_id'] ?? 0);
            $sourceVehicle = (int) ($data['source_vehicle_id'] ?? 0) ?: $vehicle;
            $source = $this->documents->document($company, $sourceVehicle, $sourceJob, $reuse);
            if ($source === null || $source['archived_at'] !== null || $this->storage->resolve($company, $source, $this->documents->metadata($source, true)) === null) {
                throw new InvalidArgumentException('Owned binary source is unavailable or has changed.');
            }
            $file = $source['file_id'];
            $image = $source['image_id'];
            $descriptor = ['checksum' => $source['content_checksum'], 'mime_type' => $source['content_mime_type'], 'size_bytes' => (int) $source['content_size_bytes'], 'original_filename' => $source['content_original_filename']];
            if ($descriptor !== ($data['descriptor'] ?? null)) {
                throw new InvalidArgumentException('Owned source identity changed. Reload and review.');
            }
        } else {
            $stored = $this->storage->store($company, $data['upload'], $data['descriptor'] ?? [], $actor, $this->uploadCandidateId);
            $this->created[] = $stored;
            $file = $stored['file_id'];
            $descriptor = $stored['descriptor'];
        }
        if (in_array($kind, ['before_photo', 'after_photo'], true) && ($descriptor === null || ! str_starts_with($descriptor['mime_type'], 'image/'))) {
            throw new InvalidArgumentException('Photo documents require verified image content.');
        }
        $values = ['company_id' => $company, 'vehicle_damage_repair_job_id' => $job,
            'vehicle_damage_repair_estimate_id' => $estimate, 'vehicle_damage_repair_job_item_id' => $member,
            'kind_code' => $kind, 'file_id' => $file, 'image_id' => $image, 'external_reference' => $external,
            'label' => self::text($data['label'] ?? null, 190), 'note' => self::text($data['note'] ?? null, 2000),
            'content_checksum' => $descriptor['checksum'] ?? null, 'content_mime_type' => $descriptor['mime_type'] ?? null,
            'content_size_bytes' => $descriptor['size_bytes'] ?? null, 'content_original_filename' => $descriptor['original_filename'] ?? null,
            'archived_at' => null, 'archived_by' => null, 'archive_reason' => null,
            'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now];
        $id = $this->documents->insert($values);
        return $this->documents->document($company, $vehicle, $job, $id);
    }

    public function discardAfterRollback(): void
    {
        foreach ($this->created as $stored) {
            $this->storage->discard($stored);
        }
        $this->created = [];
    }

    public static function text(mixed $input, int $max, bool $required = false): ?string
    {
        if ($input !== null && ! is_string($input)) {
            throw new InvalidArgumentException('Text fields must contain text.');
        }
        $text = trim($input ?? '');
        if (strlen($text) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || ($required && $text === '')) {
            throw new InvalidArgumentException('Required text is missing, invalid, or too long.');
        }
        return $text === '' ? null : $text;
    }

    public static function external(string $reference): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $reference) || str_contains($reference, '\\') || str_starts_with($reference, '/')
            || preg_match('/^[A-Za-z]:/', $reference) || str_contains($reference, '../')) {
            throw new InvalidArgumentException('External reference cannot be a filesystem path or executable scheme.');
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            $parts = parse_url($reference);
            if (! filter_var($reference, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('External links must use HTTPS without credentials.');
            }
        }
    }

    private BaseConnection $connection;

    private function db(): BaseConnection
    {
        return $this->connection;
    }
}
