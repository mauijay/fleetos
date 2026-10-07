<?php

namespace App\Repositories;

use App\Database\VehicleDamageRepairCostCharset;
use App\Services\Fleet\RepairCostMoney;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\VehicleDamageRepairCosts as Policy;
use InvalidArgumentException;
use RuntimeException;

class VehicleDamageRepairCostRepository
{
    public const TABLE = 'vehicle_damage_repair_cost_entries';
    public const FIELDS = 'id company_id vehicle_damage_repair_job_id kind_code amount currency occurred_on vendor_company_id vendor_snapshot vendor_reference repair_document_id related_cost_entry_id replacement_of_cost_entry_id status_code note created_by created_at voided_at voided_by void_reason';
    public const CHECKS = ['b23_cost_kind_ck', 'b23_cost_status_ck', 'b23_cost_currency_ck', 'b23_cost_amount_ck', 'b23_cost_vendor_ck', 'b23_cost_void_ck', 'b23_cost_relation_ck'];
    public const INDEXES = [
        'b23_cost_context_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'id']],
        'b23_cost_replace_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'replacement_of_cost_entry_id']],
        'b23_cost_history_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'occurred_on', 'id']],
        'b23_cost_current_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'status_code', 'kind_code', 'id']],
        'b23_cost_document_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'repair_document_id']],
        'b23_cost_related_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'related_cost_entry_id']],
        'b23_cost_vendor_idx' => ['INDEX', ['vendor_company_id']],
    ];
    private BaseConnection $db;
    private VehicleDamageRepairRepository $work;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->work = new VehicleDamageRepairRepository($this->db);
    }

    /** Any partial footprint must disable monetary commands and source archiving. */
    public function present(): bool
    {
        return $this->db->tableExists(self::TABLE)
            || array_any(['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'], fn (string $field): bool => $this->db->fieldExists($field, 'vehicle_damage_repair_jobs'))
            || ($this->db->tableExists(VehicleDamageRepairEstimateRepository::DOCUMENTS) && isset($this->db->getIndexData(VehicleDamageRepairEstimateRepository::DOCUMENTS)['b23_doc_context_uq']));
    }

    public function ready(): bool
    {
        return $this->schemaReady() && VehicleDamageRepairCostCharset::correct($this->db);
    }

    /** Structural prerequisites, also used before forward charset hardening. */
    public function schemaReady(): bool
    {
        if (! (new VehicleDamageRepairEstimateRepository($this->db))->ready() || ! $this->db->tableExists(self::TABLE)
            || array_diff(explode(' ', self::FIELDS), $this->db->getFieldNames(self::TABLE)) !== []) {
            return false;
        }
        foreach (['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'] as $field) {
            if (! $this->db->fieldExists($field, 'vehicle_damage_repair_jobs')) {
                return false;
            }
        }
        foreach ([self::TABLE => self::INDEXES, VehicleDamageRepairEstimateRepository::DOCUMENTS => ['b23_doc_context_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'id']]]] as $table => $requirements) {
            $indexes = $this->db->getIndexData($table);
            foreach ($requirements as $name => [$type, $fields]) {
                if (! isset($indexes[$name]) || strtoupper($indexes[$name]->type) !== $type || $indexes[$name]->fields !== $fields) {
                    return false;
                }
            }
        }
        $context = ['company_id', 'vehicle_damage_repair_job_id'];
        $requirements = [[['company_id'], 'companies', ['id']], [$context, 'vehicle_damage_repair_jobs', ['company_id', 'id']], [['vendor_company_id'], 'companies', ['id']]];
        foreach (['repair_document_id' => VehicleDamageRepairEstimateRepository::DOCUMENTS, 'related_cost_entry_id' => self::TABLE, 'replacement_of_cost_entry_id' => self::TABLE] as $column => $target) {
            $requirements[] = [[...$context, $column], $target, [...$context, 'id']];
        }
        $keys = $this->db->getForeignKeyData(self::TABLE);
        foreach ($requirements as [$columns, $table, $fields]) {
            if (! array_any($keys, fn ($key): bool => $key->column_name === $columns && $key->foreign_table_name === $this->db->prefixTable($table) && $key->foreign_column_name === $fields && strtoupper($key->on_update) === 'RESTRICT' && strtoupper($key->on_delete) === 'RESTRICT')) {
                return false;
            }
        }
        if ($this->db->getPlatform() === 'SQLite3') {
            $schema = $this->db->query('SELECT sql FROM sqlite_master WHERE type=\'table\' AND name=?', [$this->db->prefixTable(self::TABLE)])->getRowArray()['sql'] ?? '';
            foreach (self::CHECKS as $check) {
                if (! str_contains($schema, $check)) {
                    return false;
                }
            }
            foreach (['b23_cost_immutable_update', 'b23_cost_no_delete', 'b23_job_finalization_insert', 'b23_job_finalization_update'] as $trigger) {
                if ($this->db->query('SELECT name FROM sqlite_master WHERE type=\'trigger\' AND name=?', [$this->db->prefixTable($trigger)])->getRowArray() === null) {
                    return false;
                }
            }
        } else {
            $checks = array_column($this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE=\'CHECK\'', [$this->db->prefixTable(self::TABLE)])->getResultArray(), 'CONSTRAINT_NAME');
            if (array_diff(self::CHECKS, $checks) !== []) {
                return false;
            }
            if ($this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=\'b23_job_finalization_ck\'', [$this->db->prefixTable('vehicle_damage_repair_jobs')])->getRowArray() === null) {
                return false;
            }
            foreach (['b23_cost_immutable_update', 'b23_cost_no_delete'] as $trigger) {
                if ($this->db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$this->db->prefixTable($trigger)])->getRowArray() === null) {
                    return false;
                }
            }
        }
        return true;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Repair cost schema is incomplete. Monetary commands and referenced-document archive are unavailable.');
        }
    }

    public function entries(int $c, int $v, int $j): array
    {
        if ($this->work->job($c, $v, $j) === null) {
            throw new InvalidArgumentException('Work job not found in this context.');
        }
        $rows = $this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->orderBy('id')->limit(Policy::MAX_ENTRIES + 1)->get()->getResultArray();
        if (count($rows) > Policy::MAX_ENTRIES) {
            throw new RuntimeException('Repair cost history exceeds the supported job bound. Review is required before further commands.');
        }
        return $rows;
    }

    public function lock(array $jobs, int $company): void
    {
        $sql = $this->db->table(self::TABLE)->where('company_id', $company)->whereIn('vehicle_damage_repair_job_id', $jobs)->orderBy('id')->getCompiledSelect();
        if ($this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE')) === false) {
            throw new RuntimeException('Repair costs are busy. Retry the same command key.');
        }
    }

    public function archiveGuard(int $c, int $j, int $document): void
    {
        if ($this->present()) {
            $this->requireReady();
            // B2.2 may participate in an existing repeatable-read transaction. Re-read
            // the cost rows already locked by the runner, never its earlier snapshot.
            $sql = $this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->where('repair_document_id', $document)->orderBy('id')->limit(1)->getCompiledSelect();
            if ($this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'))->getRowArray() !== null) {
                throw new InvalidArgumentException('Document is permanently retained because repair cost history references it, including voided entries.');
            }
        }
    }

    /** At most one creation and one void per retained row; unrelated job history cannot widen this read. */
    public function latestEntryEvent(int $c, int $j, int $id): int
    {
        $events = $this->db->table('vehicle_damage_repair_job_events')->select('id, after_json')->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)
            ->whereIn('event_code', ['repair_charge_recorded', 'repair_payment_recorded', 'repair_cost_entry_voided', 'repair_cost_entry_replaced'])
            ->orderBy('id', 'DESC')->limit(2 * Policy::MAX_ENTRIES)->get()->getResultArray();
        foreach ($events as $event) {
            $snapshot = json_decode($event['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (in_array($id, $snapshot['b23']['entry_ids'] ?? [], true)) {
                return (int) $event['id'];
            }
        }
        return 0;
    }

    public function insert(array $values): int
    {
        if ($this->db->table(self::TABLE)->insert($values) === false) {
            throw new RuntimeException('Repair cost entry could not be recorded.');
        }
        return (int) $this->db->insertID();
    }

    public function void(int $c, int $j, int $id, int $actor, string $reason, string $now): void
    {
        if ($this->db->table(self::TABLE)->where('company_id', $c)->where('vehicle_damage_repair_job_id', $j)->where('id', $id)->where('status_code', 'recorded')->update(['status_code' => 'voided', 'voided_at' => $now, 'voided_by' => $actor, 'void_reason' => $reason]) === false || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('Repair cost entry changed before correction.');
        }
    }

    /** Immutable original references follow a unique, same-kind correction chain. */
    public static function head(array $rows, int $id, bool $allowVoided = false): array
    {
        $byId = array_column($rows, null, 'id');
        $original = $byId[$id] ?? throw new InvalidArgumentException('Related cost entry is missing.');
        $seen = [];
        // Validate ancestors too: a directly referenced successor cannot conceal a broken root.
        while (! empty($byId[$id]['replacement_of_cost_entry_id'])) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Cost replacement lineage contains a cycle.');
            }
            $seen[$id] = true;
            $id = (int) $byId[$id]['replacement_of_cost_entry_id'];
            if (! isset($byId[$id])) {
                throw new InvalidArgumentException('Cost replacement lineage is broken.');
            }
        }
        $seen = [];
        while (true) {
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Cost replacement lineage contains a cycle.');
            }
            $seen[$id] = true;
            $row = $byId[$id] ?? throw new InvalidArgumentException('Cost replacement lineage is broken.');
            if ($row['kind_code'] !== $original['kind_code'] || $row['currency'] !== $original['currency'] || (string) $row['company_id'] !== (string) $original['company_id'] || (string) $row['vehicle_damage_repair_job_id'] !== (string) $original['vehicle_damage_repair_job_id']) {
                throw new InvalidArgumentException('Cost replacement lineage is incompatible.');
            }
            $next = array_values(array_filter($rows, fn (array $e): bool => (int) ($e['replacement_of_cost_entry_id'] ?? 0) === $id));
            if (count($next) > 1 || ($next !== [] && $row['status_code'] !== 'voided')) {
                throw new InvalidArgumentException('Cost replacement lineage branches or retains a recorded predecessor.');
            }
            if ($next === []) {
                if (! $allowVoided && $row['status_code'] !== 'recorded') {
                    throw new InvalidArgumentException('Cost replacement lineage has no recorded head.');
                }
                return $row;
            }
            $id = (int) $next[0]['id'];
        }
    }

    public static function totals(array $rows): array
    {
        $sums = array_fill_keys(array_keys(Policy::KINDS), '0.00');
        $known = ['invoice' => false, 'payment' => false];
        foreach ($rows as $row) {
            if ($row['status_code'] === 'recorded') {
                $sums[$row['kind_code']] = RepairCostMoney::add($sums[$row['kind_code']], $row['amount']);
                if (isset($known[$row['kind_code']])) {
                    $known[$row['kind_code']] = true;
                }
            }
        }
        return ['invoiced' => $known['invoice'] ? RepairCostMoney::subtract($sums['invoice'], $sums['invoice_credit']) : null,
            'payments' => $known['payment'] ? RepairCostMoney::subtract($sums['payment'], $sums['payment_refund']) : null, 'gross' => $sums];
    }
}
