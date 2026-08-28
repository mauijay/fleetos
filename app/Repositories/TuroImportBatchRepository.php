<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroImportBatchRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findBySourceHash(string $sourceHash): ?array
    {
        return $this->db->table('turo_import_batches batches')
            ->select('batches.*, statuses.code AS status_code')
            ->join('lookup_values statuses', 'statuses.id = batches.import_status_lookup_value_id', 'left')
            ->where('batches.source_hash', $sourceHash)
            ->get()
            ->getRowArray();
    }

    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('turo_import_batches')->insert(array_merge($data, [
            'started_at' => $data['started_at'] ?? $now,
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ]));

        return (int) $this->db->insertID();
    }

    public function update(int $id, array $data): void
    {
        $this->db->table('turo_import_batches')
            ->where('id', $id)
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
    }
}
