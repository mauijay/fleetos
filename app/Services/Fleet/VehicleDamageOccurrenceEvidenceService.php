<?php

namespace App\Services\Fleet;

use App\Repositories\FileRepository;
use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Files\PrivateEvidenceStorageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/** Private images participate in the owning occurrence transaction, never commit. */
class VehicleDamageOccurrenceEvidenceService
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const ROLES = ['full_wheel' => 'Full wheel context', 'close_up' => 'Close-up'];
    public const MAX_IMAGE_BYTES = 10485760;
    public const MAX_COMMAND_BYTES = 31457280;
    public const MAX_MEMBERSHIP_IMAGES = 6;
    public const MAX_COMMAND_IMAGES = 12;
    private array $created = [];
    private PrivateEvidenceStorageService $storage;

    public function __construct(private readonly BaseConnection $db, ?PrivateEvidenceStorageService $storage = null)
    {
        $this->storage = $storage ?? new PrivateEvidenceStorageService(new FileRepository($db));
    }

    public function normalize(array $photos): array
    {
        if (! array_is_list($photos) || count($photos) > self::MAX_COMMAND_IMAGES) {
            throw new InvalidArgumentException('Choose at most 12 images per command.');
        }
        $size = 0;
        foreach ($photos as &$photo) {
            if (! is_array($photo) || ! isset(self::ROLES[$photo['role'] ?? '']) || isset($photo['file_id']) || isset($photo['image_id'])) {
                throw new InvalidArgumentException('Choose Full wheel context or Close-up for each new upload; raw file IDs are not accepted.');
            }
            if (($photo['upload'] ?? null) instanceof UploadedFile && is_file($photo['upload']->getTempName())) {
                $descriptor = $this->descriptor($photo['upload']);
                if (isset($photo['descriptor']) && $photo['descriptor'] !== $descriptor) {
                    throw new InvalidArgumentException('Selected photo content changed.');
                }
                $photo['descriptor'] = $descriptor;
            }
            $this->validateDescriptor($photo['descriptor'] ?? null);
            $size += $photo['descriptor']['size_bytes'];
            $photo = array_intersect_key($photo, array_flip(['upload', 'descriptor', 'role']));
        }
        unset($photo);
        if ($size > self::MAX_COMMAND_BYTES) {
            throw new InvalidArgumentException('Selected images exceed 30 MiB per command.');
        }
        return $photos;
    }

    public function descriptor(UploadedFile $upload): array
    {
        $path = $upload->getTempName();
        if ($upload->getError() !== UPLOAD_ERR_OK || ! is_file($path) || is_link($path)
            || ((defined('ENVIRONMENT') ? constant('ENVIRONMENT') : 'production') !== 'testing' && ! $upload->isValid())) {
            throw new InvalidArgumentException('Image upload did not complete. Select the file again.');
        }
        $size = filesize($path);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $name = $upload->getClientName();
        if ($size === false || $size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Each image must contain bytes and be at most 10 MiB.');
        }
        if (! in_array($mime, self::MIME_TYPES, true)) {
            throw new InvalidArgumentException('Upload JPEG, PNG, or WebP images. HEIC, PDF, and SVG are not supported.');
        }
        if ($name === '' || strlen($name) > 190 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('Image filename is invalid or too long.');
        }
        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions['mime'] !== $mime || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 20000000) {
            throw new InvalidArgumentException('Image must be readable, at most 20 megapixels and 10,000 pixels on either dimension.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || ! $this->completeImage($bytes, $mime)) {
            throw new InvalidArgumentException('Image contains corrupt, appended, or unsupported content.');
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new InvalidArgumentException('Image decoder could not read this photo.');
        }
        $valid = imagesx($decoded) === $dimensions[0] && imagesy($decoded) === $dimensions[1];
        imagedestroy($decoded);
        if (! $valid) {
            throw new InvalidArgumentException('Image dimensions changed during decoding.');
        }
        return ['checksum' => hash('sha256', $bytes), 'mime_type' => $mime, 'size_bytes' => $size,
            'original_filename' => $name, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    public function attach(int $company, int $vehicle, int $condition, int $membership, array $photos, int $actor): array
    {
        if ($this->db->transDepth < 1) {
            throw new RuntimeException('Photos require an owning occurrence transaction.');
        }
        $occurrences = new VehicleDamageOccurrenceRepository($this->db);
        $occurrences->validateEvidenceLink($company, $vehicle, $condition, $membership);
        $count = $this->db->table('vehicle_damage_item_evidence')->where('company_id', $company)->where('vehicle_damage_incident_item_id', $membership)->countAllResults();
        if ($count + count($photos) > self::MAX_MEMBERSHIP_IMAGES) {
            throw new InvalidArgumentException('An occurrence supports at most 6 photos, including previously attached photos.');
        }
        $ids = [];
        foreach ($photos as $photo) {
            if (! ($photo['upload'] ?? null) instanceof UploadedFile || $this->descriptor($photo['upload']) !== $photo['descriptor']) {
                throw new InvalidArgumentException('Select the original photo again. Frozen descriptors can only recover an existing receipt.');
            }
            // Use verified byte facts instead of the HTTP object's cached size/MIME.
            $verifiedUpload = new UploadedFile($photo['upload']->getTempName(), $photo['descriptor']['original_filename'], $photo['descriptor']['mime_type'], $photo['descriptor']['size_bytes'], UPLOAD_ERR_OK);
            $stored = $this->storage->store($verifiedUpload, $this->directory($company, $vehicle), self::MIME_TYPES, self::MAX_IMAGE_BYTES, null, $actor, true);
            $this->created[] = $stored;
            if ($this->db->table('vehicle_files')->where('fleet_vehicle_id', $vehicle)->where('file_id', $stored['file_id'])->countAllResults() === 0
                && ! $this->db->table('vehicle_files')->insert(['fleet_vehicle_id' => $vehicle, 'file_id' => $stored['file_id'], 'file_type_lookup_value_id' => (new \App\Repositories\LookupRepository($this->db))->valueId('file_type', 'claim'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')])) {
                throw new RuntimeException('Private vehicle file ownership could not be recorded.');
            }
            $items = new VehicleDamageRepository($this->db);
            if (! $items->fileBelongsToVehicle($company, $vehicle, $stored['file_id'])) {
                throw new RuntimeException('Private file ownership is unavailable.');
            }
            $values = ['company_id' => $company, 'vehicle_damage_item_id' => $condition,
                'vehicle_damage_incident_item_id' => $membership, 'file_id' => $stored['file_id'], 'image_id' => null,
                'external_reference' => null, 'label' => self::ROLES[$photo['role']], 'created_by' => $actor, 'created_at' => date('Y-m-d H:i:s')];
            $id = (new VehicleDamageService($this->db))->writeOccurrenceEvidenceInTransaction($company, $vehicle, $condition, $membership, ['file_id' => $stored['file_id'], 'evidence_label' => self::ROLES[$photo['role']]], $actor);
            (new \App\Repositories\AuditLogRepository($this->db))->record($actor, (new \App\Repositories\LookupRepository($this->db))->valueId('audit_action', 'created'), 'vehicle_damage_item_evidence', $id, null, $values);
            $ids[] = $id;
        }
        return $ids;
    }

    /** Bounded occurrence reads; no reconstruction or mutation. */
    public function gallery(int $company, int $vehicle, int $condition, int $membership, int $after = 0): array
    {
        $this->authorizeParent($company, $vehicle, $condition, $membership);
        return $this->db->table('vehicle_damage_item_evidence evidence')->select('evidence.id, evidence.label, evidence.created_at, files.original_filename')
            ->join('files', 'files.id=evidence.file_id AND files.deleted_at IS NULL')
            ->join('vehicle_files owned_files', 'owned_files.file_id=files.id')
            ->where('owned_files.fleet_vehicle_id', $vehicle)->where('files.storage_disk', 'local')
            ->like('files.path', $this->directory($company, $vehicle) . '/', 'after')
            ->where('evidence.company_id', $company)->where('evidence.vehicle_damage_item_id', $condition)->where('evidence.vehicle_damage_incident_item_id', $membership)
            ->where('evidence.id >', max(0, $after))->orderBy('evidence.id')->get(12)->getResultArray();
    }

    public function resolve(int $company, int $vehicle, int $condition, int $membership, int $evidence): ?array
    {
        try {
            $this->authorizeParent($company, $vehicle, $condition, $membership);
            $items = new VehicleDamageRepository($this->db);
            $original = $items->item($company, $vehicle, $condition);
            $canonical = $items->canonicalItem($company, $vehicle, $condition);
            if ($original === null || $canonical === null || $original['panel_code'] !== $canonical['panel_code'] || $original['damage_type_code'] !== $canonical['damage_type_code']) {
                return null;
            }
            $row = $this->db->table('vehicle_damage_item_evidence')->where('id', $evidence)->where('company_id', $company)
                ->where('vehicle_damage_item_id', $condition)->where('vehicle_damage_incident_item_id', $membership)->get()->getRowArray();
            if ($row === null || $row['file_id'] === null || ! $items->fileBelongsToVehicle($company, $vehicle, (int) $row['file_id'])) {
                return null;
            }
            $file = (new FileRepository($this->db))->find((int) $row['file_id']);
            if ($file === null) {
                return null;
            }
            $metadata = [];
            foreach ($file as $key => $value) {
                $metadata['file_' . $key] = $value;
            }
            return $this->storage->resolve($metadata, $this->directory($company, $vehicle), self::MIME_TYPES);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Legacy episode evidence has no occurrence; preserve and authorize its original parent. */
    public function resolveEpisodeEvidence(int $company, int $vehicle, int $condition, int $evidence): ?array
    {
        try {
            $items = new VehicleDamageRepository($this->db);
            $original = $items->item($company, $vehicle, $condition);
            $canonical = $items->canonicalItem($company, $vehicle, $condition);
            if ($original === null || $canonical === null || $original['panel_code'] !== $canonical['panel_code'] || $original['damage_type_code'] !== $canonical['damage_type_code'] || $original['zone_code'] !== $canonical['zone_code']) {
                return null;
            }
            $rows = (new \App\Repositories\VehicleWheelReadRepository($this->db))->gallery($company, $vehicle, $condition, null, 0, [$evidence]);
            $row = $rows[0] ?? null;
            if ($row === null || (int) $row['id'] !== $evidence || (int) $row['vehicle_damage_item_id'] !== $condition) {
                return null;
            }
            return $this->storage->resolve($row, $this->directory($company, $vehicle), self::MIME_TYPES);
        } catch (\Throwable) {
            return null;
        }
    }

    public function discardAfterRollback(): void
    {
        foreach ($this->created as $stored) {
            $this->storage->discardNewFile($stored);
        }
        $this->created = [];
    }

    public function release(): void
    {
        $this->created = [];
    }

    private function authorizeParent(int $company, int $vehicle, int $condition, int $membership): void
    {
        (new VehicleDamageOccurrenceRepository($this->db))->validateEvidenceLink($company, $vehicle, $condition, $membership);
        $items = new VehicleDamageRepository($this->db);
        $original = $items->item($company, $vehicle, $condition);
        $canonical = $items->canonicalItem($company, $vehicle, $condition);
        if ($original === null || $canonical === null || $original['panel_code'] !== $canonical['panel_code']
            || $original['damage_type_code'] !== $canonical['damage_type_code'] || $original['zone_code'] !== $canonical['zone_code']) {
            throw new InvalidArgumentException('Occurrence evidence has an invalid original/canonical relationship.');
        }
    }

    private function directory(int $company, int $vehicle): string
    {
        if ($company < 1 || $vehicle < 1) {
            throw new InvalidArgumentException('Owned company and vehicle required.');
        }
        return 'damage-evidence/company-' . $company . '/vehicle-' . $vehicle;
    }

    private function validateDescriptor(mixed $descriptor): void
    {
        if (! is_array($descriptor) || ! is_string($descriptor['checksum'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $descriptor['checksum'])
            || ! in_array($descriptor['mime_type'] ?? null, self::MIME_TYPES, true) || ! is_int($descriptor['size_bytes'] ?? null)
            || $descriptor['size_bytes'] < 1 || $descriptor['size_bytes'] > self::MAX_IMAGE_BYTES
            || ! is_string($descriptor['original_filename'] ?? null) || ! is_int($descriptor['width'] ?? null) || ! is_int($descriptor['height'] ?? null)
            || $descriptor['width'] < 1 || $descriptor['height'] < 1 || $descriptor['width'] > 10000 || $descriptor['height'] > 10000 || $descriptor['width'] * $descriptor['height'] > 20000000) {
            throw new InvalidArgumentException('Invalid frozen photo descriptor.');
        }
    }

    private function completeImage(string $bytes, string $mime): bool
    {
        if (preg_match('/<\?(?:php|=)|<script\b|<svg\b/i', $bytes)) {
            return false;
        }
        if ($mime === 'image/jpeg') {
            return str_starts_with($bytes, "\xFF\xD8") && str_ends_with($bytes, "\xFF\xD9");
        }
        if ($mime === 'image/webp') {
            return strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP'
                && unpack('Vsize', substr($bytes, 4, 4))['size'] + 8 === strlen($bytes);
        }
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return false;
        }
        $offset = 8;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('Nsize', substr($bytes, $offset, 4))['size'];
            if ($length > strlen($bytes) - $offset - 12) {
                return false;
            }
            $chunk = substr($bytes, $offset + 4, $length + 4);
            if (hash('crc32b', $chunk, true) !== substr($bytes, $offset + 8 + $length, 4)) {
                return false;
            }
            $offset += $length + 12;
            if (substr($chunk, 0, 4) === 'IEND') {
                return $length === 0 && $offset === strlen($bytes);
            }
        }
        return false;
    }
}
