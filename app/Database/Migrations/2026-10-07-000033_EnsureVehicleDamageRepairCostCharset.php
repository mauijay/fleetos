<?php

namespace App\Database\Migrations;

use App\Database\VehicleDamageRepairCostCharset as Charset;
use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only hardening of the accepted 000032 shape, including populated tables. */
class EnsureVehicleDamageRepairCostCharset extends Migration
{
    private const COLUMNS = [
        'id' => ['bigint unsigned', false, null, 'auto_increment'],
        'company_id' => ['int unsigned', false, null, ''],
        'vehicle_damage_repair_job_id' => ['bigint unsigned', false, null, ''],
        'kind_code' => ['varchar(20)', false, null, ''],
        'amount' => ['decimal(12,2)', false, null, ''],
        'currency' => ['char(3)', false, null, ''],
        'occurred_on' => ['date', false, null, ''],
        'vendor_company_id' => ['int unsigned', true, null, ''],
        'vendor_snapshot' => ['varchar(190)', false, null, ''],
        'vendor_reference' => ['varchar(120)', true, null, ''],
        'repair_document_id' => ['bigint unsigned', false, null, ''],
        'related_cost_entry_id' => ['bigint unsigned', true, null, ''],
        'replacement_of_cost_entry_id' => ['bigint unsigned', true, null, ''],
        'status_code' => ['varchar(12)', false, 'recorded', ''],
        'note' => ['text', true, null, ''],
        'created_by' => ['int unsigned', false, null, ''],
        'created_at' => ['datetime', false, null, ''],
        'voided_at' => ['datetime', true, null, ''],
        'voided_by' => ['int unsigned', true, null, ''],
        'void_reason' => ['text', true, null, ''],
    ];

    public function up(): void
    {
        $db = $this->db;
        if (! $db instanceof BaseConnection) {
            throw new RuntimeException('Repair cost charset hardening requires a supported connection.');
        }
        $db->resetDataCache();
        if (! (new Costs($db))->schemaReady()) {
            throw new RuntimeException('Repair cost schema is missing or incomplete; inspect schema and ledger before recovery.');
        }
        if ($db->getPlatform() === 'SQLite3') {
            $db->resetDataCache();
            return;
        }
        if ($db->getPlatform() !== 'MySQLi') {
            throw new RuntimeException('Unsupported repair cost charset platform.');
        }
        $before = $this->inventory($db);
        $this->validate($before);
        // A correct default with inconsistent columns is malformed, not a license
        // to ALTER a table advertised as already corrected.
        if ($before['table']['TABLE_COLLATION'] === Charset::COLLATION) {
            if (! Charset::correct($db)) {
                throw new RuntimeException('Repair cost default and column collations disagree; inspect before recovery.');
            }
            $db->resetDataCache();
            return;
        }
        $clauses = ['DEFAULT CHARACTER SET ' . Charset::CHARSET . ' COLLATE ' . Charset::COLLATION];
        foreach ($before['columns'] as $column) {
            if ($column['CHARACTER_SET_NAME'] === null || ($column['CHARACTER_SET_NAME'] === Charset::CHARSET && $column['COLLATION_NAME'] === Charset::COLLATION)) {
                continue;
            }
            [$type, $nullable, $default] = self::COLUMNS[$column['COLUMN_NAME']];
            // Explicit logical types avoid CONVERT TO CHARACTER SET widening TEXT
            // to MEDIUMTEXT when converting latin1 to utf8mb4.
            $definition = strtoupper($type) . ' CHARACTER SET ' . Charset::CHARSET . ' COLLATE ' . Charset::COLLATION
                . ($nullable ? ' NULL DEFAULT NULL' : ' NOT NULL')
                . ($default === null ? '' : ' DEFAULT ' . $db->escape($default));
            $clauses[] = 'MODIFY COLUMN ' . $db->escapeIdentifiers($column['COLUMN_NAME']) . ' ' . $definition;
        }
        $mode = $db->query('SELECT @@SESSION.sql_mode AS mode')->getRowArray()['mode'];
        $debug = new \ReflectionProperty($db, 'DBDebug');
        $previous = $debug->getValue($db);
        $debug->setValue($db, true);
        try {
            // A non-strict application connection must never silently truncate
            // populated TEXT when its UTF-8 representation exceeds TEXT capacity.
            if (! in_array('STRICT_ALL_TABLES', explode(',', $mode), true)) {
                $db->query('SET SESSION sql_mode=?', [trim($mode . ',STRICT_ALL_TABLES', ',')]);
            }
            if ($db->query('ALTER TABLE ' . $db->escapeIdentifiers($db->prefixTable(Costs::TABLE)) . ' ' . implode(', ', $clauses)) === false) {
                throw new RuntimeException('Repair cost charset DDL failed. Inspect actual schema and ledger; never blindly rerun.');
            }
            $db->resetDataCache();
            $after = $this->inventory($db);
            if (! Charset::correct($db) || $this->withoutCharset($before) !== $this->withoutCharset($after) || ! (new Costs($db))->ready()) {
                throw new RuntimeException('Repair cost charset postconditions failed. Keep writes suspended; inspect schema and ledger before recovery.');
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('Repair cost charset hardening failed. Keep writes suspended; inspect actual schema and ledger before recovery; never blindly rerun.', 0, $error);
        } finally {
            try {
                $db->query('SET SESSION sql_mode=?', [$mode]);
            } finally {
                $debug->setValue($db, $previous);
                $db->resetDataCache();
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Repair cost charset hardening is forward-only. Restore an approved backup instead of destructive charset rollback.');
    }

    private function inventory(BaseConnection $db): array
    {
        $name = $db->prefixTable(Costs::TABLE);
        return [
            'table' => $db->query('SELECT ENGINE, ROW_FORMAT, AUTO_INCREMENT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name])->getRowArray(),
            'columns' => $db->query('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$name])->getResultArray(),
            'indexes' => $db->query('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$name])->getResultArray(),
            'keys' => $db->query('SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$name])->getResultArray(),
            'actions' => $db->query('SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME', [$name])->getResultArray(),
            'checks' => $db->query('SELECT c.CONSTRAINT_NAME, c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE=\'CHECK\' ORDER BY c.CONSTRAINT_NAME', [$name])->getResultArray(),
            'triggers' => $db->query('SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$name])->getResultArray(),
        ];
    }

    private function validate(array $inventory): void
    {
        if (($inventory['table']['ENGINE'] ?? '') !== 'InnoDB' || array_column($inventory['columns'], 'COLUMN_NAME') !== array_keys(self::COLUMNS)) {
            throw new RuntimeException('Unexpected repair cost table shape; inspect schema and ledger before recovery.');
        }
        foreach ($inventory['columns'] as $column) {
            $default = $column['COLUMN_DEFAULT'];
            $default = $default === 'NULL' ? null : ($default === "'recorded'" ? 'recorded' : $default);
            $type = preg_replace('/\b(bigint|int)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ([$type, $column['IS_NULLABLE'] === 'YES', $default, $column['EXTRA']] !== self::COLUMNS[$column['COLUMN_NAME']] || $column['COLUMN_COMMENT'] !== '') {
                throw new RuntimeException('Unexpected repair cost column definition; inspect schema and ledger before recovery.');
            }
        }
        $expectedKeys = ['b23_cost_company_fk', 'b23_cost_document_fk', 'b23_cost_job_fk', 'b23_cost_related_fk', 'b23_cost_replacement_fk', 'b23_cost_vendor_fk'];
        $primary = array_values(array_filter($inventory['keys'], static fn (array $key): bool => $key['CONSTRAINT_NAME'] === 'PRIMARY'));
        if (array_column($inventory['actions'], 'CONSTRAINT_NAME') !== $expectedKeys || array_column($primary, 'COLUMN_NAME') !== ['id']) {
            throw new RuntimeException('Unexpected repair cost keys; inspect schema and ledger before recovery.');
        }
    }

    private function withoutCharset(array $inventory): array
    {
        unset($inventory['table']['TABLE_COLLATION']);
        foreach ($inventory['columns'] as &$column) {
            unset($column['CHARACTER_SET_NAME'], $column['COLLATION_NAME']);
        }
        unset($column);
        return $inventory;
    }
}
