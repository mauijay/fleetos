<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroRawTransactionRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function create(array $data): int
    {
        $this->db->table('turo_transaction_raw')->insert(array_merge($data, [
            'raw_payload' => json_encode($data['raw_payload'], JSON_THROW_ON_ERROR),
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]));

        return (int) $this->db->insertID();
    }
}
