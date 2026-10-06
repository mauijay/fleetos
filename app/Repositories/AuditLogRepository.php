<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AuditLogRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(?int $actorUserId, int $actionLookupValueId, string $tableName, int $recordId, ?array $oldValues = null, ?array $newValues = null): void
    {
        if (! $this->db->table('audit_logs')->insert([
            'actor_user_id' => $actorUserId,
            'action_lookup_value_id' => $actionLookupValueId,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Audit history could not be recorded.');
        }
    }
}
