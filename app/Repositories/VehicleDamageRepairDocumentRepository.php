<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class VehicleDamageRepairDocumentRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly bool $locking = false)
    {
        $this->db = $db ?? Database::connect();
    }

    public function documents(int $company, int $vehicle, int $job, bool $lock = false): array
    {
        if ((new VehicleDamageRepairRepository($this->db))->job($company, $vehicle, $job) === null) {
            return [];
        }
        $sql = $this->db->table(VehicleDamageRepairEstimateRepository::DOCUMENTS)->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->orderBy('id')->getCompiledSelect();
        $result = $this->db->query($sql . (($lock || $this->locking) && $this->db->getPlatform() !== 'SQLite3' ? ' FOR UPDATE' : ''));
        if ($result === false) {
            throw new RuntimeException('Document state is locked. Reload and retry.');
        }
        return $result->getResultArray();
    }

    public function document(int $company, int $vehicle, int $job, int $id): ?array
    {
        foreach ($this->documents($company, $vehicle, $job) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** Metadata only after the caller has established business-parent ownership. */
    public function metadata(array $document, bool $lock = false): ?array
    {
        $file = $document['file_id'] !== null;
        $id = $file ? $document['file_id'] : $document['image_id'];
        if ($id === null) {
            return null;
        }
        $sql = $this->db->table($file ? 'files' : 'images')->where('id', $id)->getCompiledSelect();
        $result = $this->db->query($sql . (($lock || $this->locking) && $this->db->getPlatform() !== 'SQLite3' ? ' FOR UPDATE' : ''));
        if ($result === false) {
            throw new RuntimeException('Document metadata is locked.');
        }
        return $result->getRowArray();
    }

    public function fingerprint(int $company, int $vehicle, int $job, int $id): string
    {
        $document = $this->document($company, $vehicle, $job, $id);
        if ($document === null) {
            throw new RuntimeException('Document not found in this work context.');
        }
        $estimates = new VehicleDamageRepairEstimateRepository($this->db);
        return $estimates::digest([$document, $this->metadata($document), $estimates->latestRelatedEvent($company, $vehicle, $job, 'document_ids', $id)]);
    }

    public function insert(array $values): int
    {
        if (! $this->db->table(VehicleDamageRepairEstimateRepository::DOCUMENTS)->insert($values)) {
            throw new RuntimeException('Document could not be attached.');
        }
        return (int) $this->db->insertID();
    }

    public function archive(int $company, int $job, int $id, int $actor, string $reason, string $now): void
    {
        if (! $this->db->table(VehicleDamageRepairEstimateRepository::DOCUMENTS)->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->where('id', $id)->update(['archived_at' => $now, 'archived_by' => $actor, 'archive_reason' => $reason, 'updated_by' => $actor, 'updated_at' => $now])) {
            throw new RuntimeException('Document could not be archived.');
        }
    }
}
