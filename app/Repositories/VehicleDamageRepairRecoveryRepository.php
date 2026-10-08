<?php

namespace App\Repositories;

use App\Services\Fleet\RepairCostMoney;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\VehicleDamageRepairRecoveries as Policy;
use InvalidArgumentException;
use RuntimeException;

class VehicleDamageRepairRecoveryRepository
{
    public const TABLE = 'vehicle_damage_repair_recovery_entries';
    public const FIELDS = 'id company_id vehicle_damage_repair_job_id kind_code authority_code source_type amount currency occurred_on payer_snapshot source_namespace source_reference source_identity_key source_root_key turo_transaction_normalized_id turo_transaction_raw_id turo_source_root_id damage_claim_id repair_document_id recognized_source_fingerprint source_snapshot related_recovery_entry_id replacement_of_recovery_entry_id status_code note created_by created_at voided_by voided_at void_reason';
    public const CHECKS = ['b31_recovery_kind_ck', 'b31_recovery_status_ck', 'b31_recovery_source_ck', 'b31_recovery_authority_ck', 'b31_recovery_identity_ck', 'b31_recovery_root_ck', 'b31_recovery_relation_ck', 'b31_recovery_void_ck', 'b31_recovery_note_ck', 'b31_recovery_snapshot_ck'];
    public const INDEXES = [
        'b31_recovery_context_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'id']],
        'b31_recovery_replace_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'replacement_of_recovery_entry_id']],
        'b31_recovery_source_uq' => ['UNIQUE', ['company_id', 'source_root_key']],
        'b31_recovery_turo_uq' => ['UNIQUE', ['turo_source_root_id']],
        'b31_recovery_history_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'occurred_on', 'id']],
        'b31_recovery_current_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'status_code', 'kind_code', 'id']],
        'b31_recovery_document_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'repair_document_id']],
        'b31_recovery_related_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'related_recovery_entry_id']],
        'b31_recovery_claim_idx' => ['INDEX', ['damage_claim_id']],
        'b31_recovery_turo_idx' => ['INDEX', ['turo_transaction_normalized_id']],
        'b31_recovery_raw_idx' => ['INDEX', ['turo_transaction_raw_id']],
    ];
    private BaseConnection $db;
    private VehicleDamageRepairRepository $work;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->work = new VehicleDamageRepairRepository($this->db);
    }

    public function present(): bool
    {
        return $this->db->tableExists(self::TABLE) || array_any(['recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'], fn (string $field): bool => $this->db->fieldExists($field, 'vehicle_damage_repair_jobs'));
    }

    public function ready(): bool
    {
        if (! (new VehicleDamageRepairCostRepository($this->db))->ready() || ! $this->db->tableExists(self::TABLE)
            || array_diff(explode(' ', self::FIELDS), $this->db->getFieldNames(self::TABLE)) !== []
            || array_diff($this->db->getFieldNames(self::TABLE), explode(' ', self::FIELDS)) !== []
            || array_any(['recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'], fn (string $field): bool => ! $this->db->fieldExists($field, 'vehicle_damage_repair_jobs'))) {
            return false;
        }
        if (! $this->typesReady()) {
            return false;
        }
        $indexes = $this->db->getIndexData(self::TABLE);
        foreach (self::INDEXES as $name => [$type, $fields]) {
            if (! isset($indexes[$name]) || strtoupper($indexes[$name]->type) !== $type || $indexes[$name]->fields !== $fields) {
                return false;
            }
        }
        $context = ['company_id', 'vehicle_damage_repair_job_id'];
        $requirements = [[['company_id'], 'companies', ['id']], [$context, 'vehicle_damage_repair_jobs', ['company_id', 'id']]];
        foreach (['repair_document_id' => VehicleDamageRepairEstimateRepository::DOCUMENTS, 'related_recovery_entry_id' => self::TABLE, 'replacement_of_recovery_entry_id' => self::TABLE] as $column => $target) {
            $requirements[] = [[...$context, $column], $target, [...$context, 'id']];
        }
        foreach (['damage_claim_id' => 'damage_claims', 'turo_transaction_normalized_id' => 'turo_transactions_normalized', 'turo_source_root_id' => 'turo_transactions_normalized', 'turo_transaction_raw_id' => 'turo_transaction_raw'] as $column => $target) {
            $requirements[] = [[$column], $target, ['id']];
        }
        $keys = $this->db->getForeignKeyData(self::TABLE);
        foreach ($requirements as [$columns, $table, $fields]) {
            if (! array_any($keys, fn ($key): bool => $key->column_name === $columns && $key->foreign_table_name === $this->db->prefixTable($table) && $key->foreign_column_name === $fields && strtoupper($key->on_update) === 'RESTRICT' && strtoupper($key->on_delete) === 'RESTRICT')) {
                return false;
            }
        }
        if ($this->db->getPlatform() === 'SQLite3') {
            $schema = $this->db->query('SELECT sql FROM sqlite_master WHERE type=\'table\' AND name=?', [$this->db->prefixTable(self::TABLE)])->getRowArray()['sql'] ?? '';
            if (array_any(self::CHECKS, fn (string $check): bool => ! str_contains($schema, $check))) {
                return false;
            }
            foreach (['b31_recovery_immutable_update', 'b31_recovery_no_delete', 'b31_job_finalization_insert', 'b31_job_finalization_update'] as $trigger) {
                if ($this->db->query('SELECT name FROM sqlite_master WHERE type=\'trigger\' AND name=?', [$this->db->prefixTable($trigger)])->getRowArray() === null) {
                    return false;
                }
            }
        } else {
            $checks = array_column($this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE=\'CHECK\'', [$this->db->prefixTable(self::TABLE)])->getResultArray(), 'CONSTRAINT_NAME');
            if (array_diff(self::CHECKS, $checks) !== [] || $this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=\'b31_job_finalization_ck\'', [$this->db->prefixTable('vehicle_damage_repair_jobs')])->getRowArray() === null) {
                return false;
            }
            foreach (['b31_recovery_immutable_update', 'b31_recovery_no_delete'] as $trigger) {
                if ($this->db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$this->db->prefixTable($trigger)])->getRowArray() === null) {
                    return false;
                }
            }
            $table = $this->db->query('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable(self::TABLE)])->getRowArray();
            $columns = $this->db->query('SELECT COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CHARACTER_SET_NAME IS NOT NULL', [$this->db->prefixTable(self::TABLE)])->getResultArray();
            // MariaDB JSON is a binary-collated LONGTEXT alias, intentionally preserving snapshot bytes.
            if (($table['TABLE_COLLATION'] ?? null) !== Policy::COLLATION || ! array_all($columns, fn (array $column): bool => $column['CHARACTER_SET_NAME'] === Policy::CHARSET && $column['COLLATION_NAME'] === ($column['COLUMN_NAME'] === 'source_snapshot' ? 'utf8mb4_bin' : Policy::COLLATION))) {
                return false;
            }
        }
        return true;
    }

    private function typesReady(): bool
    {
        $sqlite = $this->db->getPlatform() === 'SQLite3';
        $types = [];
        foreach (['bigint unsigned' => 'id vehicle_damage_repair_job_id turo_transaction_normalized_id turo_transaction_raw_id turo_source_root_id repair_document_id related_recovery_entry_id replacement_of_recovery_entry_id', 'int unsigned' => 'company_id damage_claim_id created_by voided_by', 'varchar(20)' => 'kind_code authority_code', 'varchar(32)' => 'source_type', 'char(3)' => 'currency', 'varchar(190)' => 'payer_snapshot', 'varchar(120)' => 'source_namespace source_reference', 'char(64)' => 'source_identity_key source_root_key recognized_source_fingerprint', 'varchar(12)' => 'status_code', 'text' => 'note void_reason', 'datetime' => 'created_at voided_at', 'date' => 'occurred_on', 'decimal(12,2)' => 'amount', 'longtext' => 'source_snapshot'] as $type => $fields) {
            foreach (explode(' ', $fields) as $field) {
                $types[$field] = $sqlite ? match ($type) {
                    'bigint unsigned', 'int unsigned' => 'integer',
                    'decimal(12,2)' => 'text',
                    'longtext' => 'json',
                    default => $type,
                } : $type;
            }
        }
        $types += ['recovery_finalized_at' => 'datetime', 'recovery_finalized_by' => $sqlite ? 'integer' : 'int unsigned', 'recovery_finalization_note' => 'text'];
        $nullable = explode(' ', 'amount currency occurred_on source_root_key turo_transaction_normalized_id turo_transaction_raw_id turo_source_root_id damage_claim_id recognized_source_fingerprint related_recovery_entry_id replacement_of_recovery_entry_id note voided_by voided_at void_reason recovery_finalized_at recovery_finalized_by recovery_finalization_note');
        foreach ([self::TABLE, 'vehicle_damage_repair_jobs'] as $table) {
            $columns = $sqlite
                ? $this->db->query('PRAGMA table_info(' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ')')->getResultArray()
                : $this->db->query('SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($table)])->getResultArray();
            foreach ($columns as $column) {
                if (isset($types[$column['name']]) && ($table === self::TABLE || str_starts_with($column['name'], 'recovery_'))) {
                    $type = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($column['type']));
                    $allowsNull = $sqlite ? ! $column['notnull'] && ! $column['pk'] : $column['nullable'] === 'YES';
                    if ($types[$column['name']] !== $type || $allowsNull !== in_array($column['name'], $nullable, true)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Recovery schema is incomplete. Recovery commands and recovery-aware document archive are unavailable.');
        }
    }

    public function entries(int $c, int $v, int $j): array
    {
        if ($this->work->job($c, $v, $j) === null) {
            throw new InvalidArgumentException('Work job not found in this context.');
        }
        $rows = $this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->orderBy('id')->limit(Policy::MAX_ENTRIES + 1)->get()->getResultArray();
        if (count($rows) > Policy::MAX_ENTRIES) {
            throw new RuntimeException('Recovery history exceeds its supported job bound.');
        }
        return $rows;
    }

    public function lock(array $jobs, int $company): void
    {
        $sql = $this->db->table(self::TABLE)->where('company_id', $company)->whereIn('vehicle_damage_repair_job_id', $jobs)->orderBy('id')->getCompiledSelect();
        if ($this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE')) === false) {
            throw new RuntimeException('Recovery history is busy. Retry the same command key.');
        }
    }

    public function archiveGuard(int $c, int $j, int $document): void
    {
        if ($this->present()) {
            $this->requireReady();
            $sql = $this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->where('repair_document_id', $document)->orderBy('id')->limit(1)->getCompiledSelect();
            if ($this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'))->getRowArray() !== null) {
                throw new InvalidArgumentException('Document is permanently retained by recovery history, including voided/replaced entries.');
            }
        }
    }

    public function insert(array $values): int
    {
        if ($this->db->table(self::TABLE)->insert($values) === false) {
            throw new RuntimeException('Recovery entry could not be recorded. Source identity may already be reserved.');
        }
        return (int) $this->db->insertID();
    }

    public function void(int $c, int $j, int $id, int $actor, string $reason, string $now): void
    {
        if ($this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->where('id', $id)->where('status_code', 'recorded')->update(['status_code' => 'voided', 'voided_at' => $now, 'voided_by' => $actor, 'void_reason' => $reason]) === false || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('Recovery entry changed before correction.');
        }
    }

    /** Validate both ancestors and successors, including permanently retained root reservations. */
    public static function head(array $rows, int $id, bool $allowVoided = false): array
    {
        $byId = array_column($rows, null, 'id');
        $original = $byId[$id] ?? throw new InvalidArgumentException('Related recovery entry is missing.');
        $roots = array_filter($rows, fn (array $row): bool => empty($row['replacement_of_recovery_entry_id']) && (string) $row['company_id'] === (string) $original['company_id'] && $row['source_identity_key'] === $original['source_identity_key']);
        if (count($roots) !== 1) {
            throw new InvalidArgumentException('Recovery root reservation is duplicated or missing.');
        }
        $seen = [];
        while (! empty($byId[$id]['replacement_of_recovery_entry_id'])) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Recovery lineage contains a cycle.');
            }
            $seen[$id] = true;
            $id = (int) $byId[$id]['replacement_of_recovery_entry_id'];
            if (! isset($byId[$id])) {
                throw new InvalidArgumentException('Recovery lineage is broken.');
            }
        }
        $seen = [];
        while (true) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Recovery lineage contains a cycle.');
            }
            $seen[$id] = true;
            $row = $byId[$id];
            foreach (['company_id', 'vehicle_damage_repair_job_id', 'kind_code', 'authority_code', 'source_type', 'source_identity_key', 'source_namespace', 'source_reference', 'currency', 'turo_transaction_normalized_id', 'related_recovery_entry_id'] as $field) {
                if ((string) ($row[$field] ?? '') !== (string) ($original[$field] ?? '')) {
                    throw new InvalidArgumentException('Recovery replacement lineage is incompatible.');
                }
            }
            if (empty($row['replacement_of_recovery_entry_id'])) {
                if (($row['source_root_key'] ?? null) !== $row['source_identity_key'] || ($row['authority_code'] === 'turo_transaction' && (int) ($row['turo_source_root_id'] ?? 0) !== (int) $row['turo_transaction_normalized_id'])) {
                    throw new InvalidArgumentException('Recovery root reservation is broken.');
                }
            } elseif (($row['source_root_key'] ?? null) !== null || ($row['turo_source_root_id'] ?? null) !== null) {
                throw new InvalidArgumentException('Recovery replacement must not create a root reservation.');
            }
            $next = array_values(array_filter($rows, fn (array $e): bool => (int) ($e['replacement_of_recovery_entry_id'] ?? 0) === $id));
            if (count($next) > 1 || ($next !== [] && $row['status_code'] !== 'voided')) {
                throw new InvalidArgumentException('Recovery lineage branches or retains a recorded predecessor.');
            }
            if ($next === []) {
                if (! $allowVoided && $row['status_code'] !== 'recorded') {
                    throw new InvalidArgumentException('Recovery lineage has no recorded head.');
                }
                return $row;
            }
            $id = (int) $next[0]['id'];
        }
    }

    public static function totals(array $rows): array
    {
        $gross = ['recovery' => '0.00', 'recovery_reversal' => '0.00'];
        $known = false;
        foreach ($rows as $row) {
            self::head($rows, (int) $row['id'], true);
            if ($row['status_code'] === 'recorded') {
                if ($row['authority_code'] !== 'external_receipt' || $row['currency'] !== 'USD' || ! is_string($row['amount'])) {
                    throw new InvalidArgumentException('Recovery source review required. Linked Turo authority is unavailable.');
                }
                $gross[$row['kind_code']] = RepairCostMoney::add($gross[$row['kind_code']], $row['amount']);
                $known = $known || $row['kind_code'] === 'recovery';
            }
        }
        return ['net' => $known ? RepairCostMoney::subtract($gross['recovery'], $gross['recovery_reversal']) : null, 'gross' => $gross];
    }
}
