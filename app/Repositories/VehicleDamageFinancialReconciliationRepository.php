<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Expense-specific provenance; never a financial activity source. */
class VehicleDamageFinancialReconciliationRepository
{
    public const TABLE = 'vehicle_damage_financial_reconciliations';
    public const FIELDS = 'id company_id fleet_vehicle_id vehicle_damage_repair_job_id cost_root_entry_id operating_expense_id fingerprint_version damage_lineage_fingerprint financial_source_fingerprint damage_lineage_snapshot financial_source_snapshot amount_snapshot currency damage_occurred_on financial_occurred_on reason date_difference_reason status_code created_by created_at invalidated_by invalidated_at invalidation_reason replacement_of_reconciliation_id current_cost_root_entry_id current_operating_expense_id';
    public const PARENTS = [
        'fleet_vehicles' => ['b32_vehicle_context_uq', ['company_id', 'id']],
        'vehicle_damage_repair_jobs' => ['b32_job_context_uq', ['company_id', 'fleet_vehicle_id', 'id']],
        'operating_expenses' => ['b32_expense_context_uq', ['company_id', 'fleet_vehicle_id', 'id']],
    ];
    public const INDEXES = [
        'b32_context_uq' => ['UNIQUE', ['company_id', 'fleet_vehicle_id', 'vehicle_damage_repair_job_id', 'cost_root_entry_id', 'id']],
        'b32_cost_current_uq' => ['UNIQUE', ['current_cost_root_entry_id']],
        'b32_expense_current_uq' => ['UNIQUE', ['current_operating_expense_id']],
        'b32_predecessor_uq' => ['UNIQUE', ['replacement_of_reconciliation_id']],
        'b32_history_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'id']],
        'b32_expense_idx' => ['INDEX', ['company_id', 'fleet_vehicle_id', 'operating_expense_id']],
        'b32_cost_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'cost_root_entry_id']],
    ];
    public const CHECKS = ['b32_status_ck', 'b32_lifecycle_ck', 'b32_money_ck', 'b32_fingerprint_ck', 'b32_snapshot_ck', 'b32_reason_ck', 'b32_date_ck'];
    public const TRIGGERS = ['b32_immutable_update', 'b32_no_delete', 'b32_predecessor_insert'];

    public function __construct(public readonly BaseConnection $db)
    {
    }

    public function present(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }
        foreach (self::PARENTS as $table => [$name]) {
            if ($this->db->tableExists($table) && isset($this->db->getIndexData($table)[$name])) {
                return true;
            }
        }
        return false;
    }

    public function ready(): bool
    {
        if (! $this->db->tableExists(self::TABLE) || ! (new VehicleDamageRepairCostRepository($this->db))->ready()
            || array_diff(explode(' ', self::FIELDS), $this->db->getFieldNames(self::TABLE)) !== []
            || count($this->db->getFieldNames(self::TABLE)) !== count(explode(' ', self::FIELDS))) {
            return false;
        }
        if (! $this->typesReady()) {
            return false;
        }
        foreach (self::PARENTS as $table => [$name, $columns]) {
            $index = $this->db->getIndexData($table)[$name] ?? null;
            if ($index === null || strtoupper($index->type) !== 'UNIQUE' || $index->fields !== $columns) {
                return false;
            }
        }
        foreach (self::INDEXES as $name => [$type, $columns]) {
            $index = $this->db->getIndexData(self::TABLE)[$name] ?? null;
            if ($index === null || strtoupper($index->type) !== $type || $index->fields !== $columns) {
                return false;
            }
        }
        $context = ['company_id', 'fleet_vehicle_id'];
        $requirements = [
            [$context, 'fleet_vehicles', ['company_id', 'id']],
            [[...$context, 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', [...$context, 'id']],
            [['company_id', 'vehicle_damage_repair_job_id', 'cost_root_entry_id'], VehicleDamageRepairCostRepository::TABLE, ['company_id', 'vehicle_damage_repair_job_id', 'id']],
            [[...$context, 'operating_expense_id'], 'operating_expenses', [...$context, 'id']],
            [[...$context, 'vehicle_damage_repair_job_id', 'cost_root_entry_id', 'replacement_of_reconciliation_id'], self::TABLE, [...$context, 'vehicle_damage_repair_job_id', 'cost_root_entry_id', 'id']],
        ];
        $keys = $this->db->getForeignKeyData(self::TABLE);
        foreach ($requirements as [$columns, $table, $fields]) {
            if (! array_any($keys, fn ($key): bool => $key->column_name === $columns && $key->foreign_table_name === $this->db->prefixTable($table) && $key->foreign_column_name === $fields && strtoupper($key->on_update) === 'RESTRICT' && strtoupper($key->on_delete) === 'RESTRICT')) {
                return false;
            }
        }
        if ($this->db->getPlatform() === 'SQLite3') {
            $schema = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$this->db->prefixTable(self::TABLE)])->getRowArray()['sql'] ?? '';
            if (array_any(self::CHECKS, fn (string $name): bool => ! str_contains($schema, $name))) {
                return false;
            }
            foreach (self::TRIGGERS as $name) {
                if ($this->db->query("SELECT name FROM sqlite_master WHERE type='trigger' AND name=?", [$this->db->prefixTable($name)])->getRowArray() === null) {
                    return false;
                }
            }
        } else {
            $checks = array_column($this->db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'", [$this->db->prefixTable(self::TABLE)])->getResultArray(), 'CONSTRAINT_NAME');
            if (array_diff(self::CHECKS, $checks) !== []) {
                return false;
            }
            foreach (self::TRIGGERS as $name) {
                if ($this->db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$this->db->prefixTable($name)])->getRowArray() === null) {
                    return false;
                }
            }
            $event = $this->db->query('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'event_code\'', [$this->db->prefixTable('vehicle_damage_repair_job_events')])->getRowArray();
            if (strtolower($event['COLUMN_TYPE'] ?? '') !== 'varchar(64)') {
                return false;
            }
            $table = $this->db->query('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable(self::TABLE)])->getRowArray();
            $columns = $this->db->query('SELECT COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CHARACTER_SET_NAME IS NOT NULL', [$this->db->prefixTable(self::TABLE)])->getResultArray();
            if (($table['TABLE_COLLATION'] ?? '') !== 'utf8mb4_general_ci' || ! array_all($columns, fn (array $row): bool => $row['CHARACTER_SET_NAME'] === 'utf8mb4' && $row['COLLATION_NAME'] === (str_ends_with($row['COLUMN_NAME'], '_snapshot') && $row['COLUMN_NAME'] !== 'amount_snapshot' ? 'utf8mb4_bin' : 'utf8mb4_general_ci'))) {
                return false;
            }
        }
        return true;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Financial reconciliation schema is incomplete. Participating writes are unavailable.');
        }
    }

    private function typesReady(): bool
    {
        $sqlite = $this->db->getPlatform() === 'SQLite3';
        $types = [];
        foreach (['bigint unsigned' => 'id vehicle_damage_repair_job_id cost_root_entry_id operating_expense_id replacement_of_reconciliation_id current_cost_root_entry_id current_operating_expense_id', 'int unsigned' => 'company_id fleet_vehicle_id created_by invalidated_by', 'varchar(12)' => 'fingerprint_version status_code', 'char(64)' => 'damage_lineage_fingerprint financial_source_fingerprint', 'longtext' => 'damage_lineage_snapshot financial_source_snapshot', 'decimal(12,2)' => 'amount_snapshot', 'char(3)' => 'currency', 'date' => 'damage_occurred_on financial_occurred_on', 'text' => 'reason date_difference_reason invalidation_reason', 'datetime' => 'created_at invalidated_at'] as $type => $fields) {
            foreach (explode(' ', $fields) as $field) {
                $types[$field] = $sqlite ? match ($type) {
                    'int unsigned', 'bigint unsigned' => 'integer',
                    'longtext' => 'json',
                    'decimal(12,2)' => 'text',
                    default => $type,
                } : $type;
            }
        }
        $nullable = explode(' ', 'date_difference_reason invalidated_by invalidated_at invalidation_reason replacement_of_reconciliation_id current_cost_root_entry_id current_operating_expense_id');
        $columns = $sqlite ? $this->db->query('PRAGMA table_info(' . $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE)) . ')')->getResultArray()
            : $this->db->query('SELECT COLUMN_NAME AS name,COLUMN_TYPE AS type,IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable(self::TABLE)])->getResultArray();
        foreach ($columns as $column) {
            $type = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($column['type']));
            $allowsNull = $sqlite ? ! $column['notnull'] && ! $column['pk'] : $column['nullable'] === 'YES';
            if (($types[$column['name']] ?? null) !== $type || $allowsNull !== in_array($column['name'], $nullable, true)) {
                return false;
            }
        }
        return true;
    }

    public function rows(int $c, int $v, int $j): array
    {
        $rows = $this->db->table(self::TABLE)->where(['company_id' => $c, 'fleet_vehicle_id' => $v, 'vehicle_damage_repair_job_id' => $j])->orderBy('id')->limit(1001)->get()->getResultArray();
        if (count($rows) > 1000) {
            throw new RuntimeException('Reconciliation history exceeds the supported job bound.');
        }
        return $rows;
    }

    public function lockingRows(string $table, array $where): array
    {
        $sql = $this->db->table($table)->where($where)->orderBy('id')->getCompiledSelect();
        $result = $this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'));
        if ($result === false) {
            throw new RuntimeException('Reconciliation source is busy. Retry after reviewing current state.');
        }
        return $result->getResultArray();
    }

    /** Ancestors and operational rows have already been locked by the aggregate. */
    public function lock(int $c, int $v, int $j, array $expenseIds = []): array
    {
        $expenseIds = array_unique([...$expenseIds, ...array_column($this->rows($c, $v, $j), 'operating_expense_id')]);
        sort($expenseIds, SORT_NUMERIC);
        foreach ($expenseIds as $id) {
            $this->lockingRows('operating_expenses', ['company_id' => $c, 'id' => $id]);
        }
        foreach ($expenseIds as $id) {
            $this->lockingRows('operating_expense_receipts', ['company_id' => $c, 'operating_expense_id' => $id]);
        }
        $this->lockingRows(self::TABLE, ['company_id' => $c, 'vehicle_damage_repair_job_id' => $j]);
        $fileIds = [];
        foreach ($expenseIds as $id) {
            $fileIds = [...$fileIds, ...array_column($this->db->table('operating_expense_receipts')->where(['company_id' => $c, 'operating_expense_id' => $id])->get()->getResultArray(), 'file_id')];
        }
        return array_unique($fileIds);
    }

    public function insert(array $row): int
    {
        if ($this->db->table(self::TABLE)->insert($row) === false) {
            throw new RuntimeException('Reconciliation could not be created. A source may already be reserved.');
        }
        return (int) $this->db->insertID();
    }

    public function invalidate(array $row, int $actor, string $reason, string $now): void
    {
        if ($this->db->table(self::TABLE)->where(['id' => $row['id'], 'status_code' => 'active'])->update(['status_code' => 'invalidated', 'invalidated_by' => $actor, 'invalidated_at' => $now, 'invalidation_reason' => $reason, 'current_cost_root_entry_id' => null, 'current_operating_expense_id' => null]) === false || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('Reconciliation changed before invalidation.');
        }
    }
}
