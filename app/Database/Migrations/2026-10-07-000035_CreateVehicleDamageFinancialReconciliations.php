<?php

namespace App\Database\Migrations;

use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only, empty expense provenance. No monetary source or inferred relationship. */
class CreateVehicleDamageFinancialReconciliations extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db instanceof BaseConnection) {
            throw new RuntimeException('B3.2a requires a concrete database connection.');
        }
        $sqlite = $db->getPlatform() === 'SQLite3';
        if (! $sqlite && $db->getPlatform() !== 'MySQLi') {
            throw new RuntimeException('B3.2a requires SQLite or MariaDB.');
        }
        $name = fn (string $table): string => $db->escapeIdentifiers($db->prefixTable($table));
        // Establish a detectable B3.2a footprint before any other additive DDL.
        // An interrupted migration must block participating writers.
        foreach (Records::PARENTS as $table => [$index, $fields]) {
            $this->ddl('CREATE UNIQUE INDEX ' . $db->escapeIdentifiers($index) . ' ON ' . $name($table) . ' (' . implode(',', $fields) . ')');
        }
        // The explicit invalidation event code is 42 characters. SQLite does not
        // enforce declared VARCHAR widths; MariaDB does, so widen additively.
        if (! $sqlite) {
            $this->ddl('ALTER TABLE ' . $name('vehicle_damage_repair_job_events') . ' MODIFY event_code VARCHAR(64) NOT NULL');
        }
        $entries = $name(Records::TABLE);
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $big = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
        $primary = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $money = $sqlite ? 'TEXT' : 'DECIMAL(12,2)';
        $bin = fn (string $field): string => $sqlite ? $field : 'BINARY ' . $field;
        $status = $bin('status_code');
        $usd = $bin('currency');
        $positive = $sqlite ? "typeof(amount_snapshot) = 'text' AND length(amount_snapshot) BETWEEN 4 AND 13 AND amount_snapshot NOT GLOB '*[^0-9.]*' AND instr(amount_snapshot, '.') = length(amount_snapshot)-2 AND substr(amount_snapshot, 1, length(amount_snapshot)-3) NOT GLOB '*.*' AND (substr(amount_snapshot,1,1) <> '0' OR length(amount_snapshot)=4) AND amount_snapshot <> '0.00'" : 'amount_snapshot > 0 AND amount_snapshot <= 9999999999.99';
        $hex = fn (string $f): string => $sqlite ? "length({$f}) = 64 AND {$f} NOT GLOB '*[^0-9a-f]*'" : "{$bin($f)} REGEXP '^[0-9a-f]{64}$'";
        $bytes = fn (string $f): string => $sqlite ? "length(CAST({$f} AS BLOB))" : "length({$f})";
        $this->ddl("CREATE TABLE {$entries} (
            id {$primary}, company_id {$int} NOT NULL, fleet_vehicle_id {$int} NOT NULL, vehicle_damage_repair_job_id {$big} NOT NULL,
            cost_root_entry_id {$big} NOT NULL, operating_expense_id {$big} NOT NULL,
            fingerprint_version VARCHAR(12) NOT NULL, damage_lineage_fingerprint CHAR(64) NOT NULL, financial_source_fingerprint CHAR(64) NOT NULL,
            damage_lineage_snapshot JSON NOT NULL, financial_source_snapshot JSON NOT NULL,
            amount_snapshot {$money} NOT NULL, currency CHAR(3) NOT NULL, damage_occurred_on DATE NOT NULL, financial_occurred_on DATE NOT NULL,
            reason TEXT NOT NULL, date_difference_reason TEXT NULL, status_code VARCHAR(12) NOT NULL,
            created_by {$int} NOT NULL, created_at DATETIME NOT NULL, invalidated_by {$int} NULL, invalidated_at DATETIME NULL, invalidation_reason TEXT NULL,
            replacement_of_reconciliation_id {$big} NULL, current_cost_root_entry_id {$big} NULL, current_operating_expense_id {$big} NULL,
            CONSTRAINT b32_status_ck CHECK ({$status} IN ('active','invalidated')),
            CONSTRAINT b32_lifecycle_ck CHECK (
                ({$status} = 'active' AND invalidated_by IS NULL AND invalidated_at IS NULL AND invalidation_reason IS NULL AND current_cost_root_entry_id IS NOT NULL AND current_cost_root_entry_id = cost_root_entry_id AND current_operating_expense_id IS NOT NULL AND current_operating_expense_id = operating_expense_id)
                OR ({$status} = 'invalidated' AND invalidated_by IS NOT NULL AND invalidated_at IS NOT NULL AND invalidation_reason IS NOT NULL AND length(trim(invalidation_reason)) BETWEEN 1 AND 2000 AND current_cost_root_entry_id IS NULL AND current_operating_expense_id IS NULL)),
            CONSTRAINT b32_money_ck CHECK ({$usd} = 'USD' AND {$positive}),
            CONSTRAINT b32_fingerprint_ck CHECK ({$bin('fingerprint_version')} = 'b32a:1' AND {$hex('damage_lineage_fingerprint')} AND {$hex('financial_source_fingerprint')}),
            CONSTRAINT b32_snapshot_ck CHECK (json_valid(damage_lineage_snapshot) AND json_valid(financial_source_snapshot) AND {$bytes('damage_lineage_snapshot')} <= 65536 AND {$bytes('financial_source_snapshot')} <= 65536),
            CONSTRAINT b32_reason_ck CHECK (length(trim(reason)) BETWEEN 1 AND 2000 AND (date_difference_reason IS NULL OR length(trim(date_difference_reason)) BETWEEN 1 AND 2000)),
            CONSTRAINT b32_date_ck CHECK (damage_occurred_on = financial_occurred_on OR date_difference_reason IS NOT NULL),
            CONSTRAINT b32_vehicle_fk FOREIGN KEY (company_id,fleet_vehicle_id) REFERENCES {$name('fleet_vehicles')}(company_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT b32_job_fk FOREIGN KEY (company_id,fleet_vehicle_id,vehicle_damage_repair_job_id) REFERENCES {$name('vehicle_damage_repair_jobs')}(company_id,fleet_vehicle_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT b32_cost_fk FOREIGN KEY (company_id,vehicle_damage_repair_job_id,cost_root_entry_id) REFERENCES {$name('vehicle_damage_repair_cost_entries')}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT b32_expense_fk FOREIGN KEY (company_id,fleet_vehicle_id,operating_expense_id) REFERENCES {$name('operating_expenses')}(company_id,fleet_vehicle_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT b32_predecessor_fk FOREIGN KEY (company_id,fleet_vehicle_id,vehicle_damage_repair_job_id,cost_root_entry_id,replacement_of_reconciliation_id) REFERENCES {$entries}(company_id,fleet_vehicle_id,vehicle_damage_repair_job_id,cost_root_entry_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT b32_context_uq UNIQUE (company_id,fleet_vehicle_id,vehicle_damage_repair_job_id,cost_root_entry_id,id),
            CONSTRAINT b32_cost_current_uq UNIQUE (current_cost_root_entry_id),
            CONSTRAINT b32_expense_current_uq UNIQUE (current_operating_expense_id),
            CONSTRAINT b32_predecessor_uq UNIQUE (replacement_of_reconciliation_id)
        )" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'));
        foreach (Records::INDEXES as $index => [$type, $fields]) {
            if ($sqlite || $type === 'INDEX') {
                $this->ddl('CREATE ' . ($type === 'UNIQUE' ? 'UNIQUE ' : '') . 'INDEX ' . $db->escapeIdentifiers($index) . ' ON ' . $entries . ' (' . implode(',', $fields) . ')');
            }
        }
        $facts = array_diff(explode(' ', Records::FIELDS), explode(' ', 'status_code invalidated_by invalidated_at invalidation_reason current_cost_root_entry_id current_operating_expense_id'));
        $immutable = implode(' OR ', array_map(fn (string $field): string => $sqlite ? "NEW.{$field} IS NOT OLD.{$field}" : "NOT (BINARY NEW.{$field} <=> BINARY OLD.{$field})", $facts)) . " OR OLD.status_code <> 'active' OR NEW.status_code <> 'invalidated'";
        $predecessor = "NEW.replacement_of_reconciliation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$entries} WHERE id=NEW.replacement_of_reconciliation_id AND status_code='invalidated')";
        if ($sqlite) {
            $this->ddl('CREATE TRIGGER ' . $name('b32_immutable_update') . " BEFORE UPDATE ON {$entries} WHEN {$immutable} BEGIN SELECT RAISE(ABORT,'Reconciliation facts are immutable'); END");
            $this->ddl('CREATE TRIGGER ' . $name('b32_no_delete') . " BEFORE DELETE ON {$entries} BEGIN SELECT RAISE(ABORT,'Reconciliation history is retained'); END");
            $this->ddl('CREATE TRIGGER ' . $name('b32_predecessor_insert') . " BEFORE INSERT ON {$entries} WHEN {$predecessor} BEGIN SELECT RAISE(ABORT,'Invalidate predecessor before replacement'); END");
        } else {
            $this->ddl('CREATE TRIGGER ' . $name('b32_immutable_update') . " BEFORE UPDATE ON {$entries} FOR EACH ROW BEGIN IF {$immutable} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reconciliation facts are immutable'; END IF; END");
            $this->ddl('CREATE TRIGGER ' . $name('b32_no_delete') . " BEFORE DELETE ON {$entries} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reconciliation history is retained'");
            $this->ddl('CREATE TRIGGER ' . $name('b32_predecessor_insert') . " BEFORE INSERT ON {$entries} FOR EACH ROW BEGIN IF {$predecessor} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalidate predecessor before replacement'; END IF; END");
        }
        $db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('B3.2a reconciliation history is forward-only. Destructive rollback is refused. Restore an approved backup through the protected recovery process.');
    }

    private function ddl(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('B3.2a DDL failed. Inspect schema and ledger; never blindly rerun.');
        }
    }
}
