<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class FileRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('files')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('files')
            ->where('id', $id)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByChecksum(string $checksum): ?array
    {
        $row = $this->db->table('files')
            ->where('checksum', $checksum)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }
}
