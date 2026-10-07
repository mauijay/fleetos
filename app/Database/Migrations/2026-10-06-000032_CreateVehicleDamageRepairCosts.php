<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive, evidence-preserving cost authority. Never rerun partial DDL blindly. */
class CreateVehicleDamageRepairCosts extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db instanceof \CodeIgniter\Database\BaseConnection) {
            throw new RuntimeException('B2.3 requires a supported database connection.');
        }
        $sqlite = $db->getPlatform() === 'SQLite3';
        $name = fn (string $table): string => $db->escapeIdentifiers($db->prefixTable($table));
        $costs = $name('vehicle_damage_repair_cost_entries');
        $jobs = $name('vehicle_damage_repair_jobs');
        $docs = $name('vehicle_damage_repair_documents');
        $companies = $name('companies');
        $id = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $primary = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $money = $sqlite ? 'TEXT' : 'DECIMAL(12,2)';
        $kindValue = $sqlite ? 'kind_code' : 'BINARY kind_code';
        $statusValue = $sqlite ? 'status_code' : 'BINARY status_code';
        $currencyValue = $sqlite ? 'currency' : 'BINARY currency';
        $unique = $sqlite ? '' : 'CONSTRAINT b23_cost_context_uq UNIQUE (company_id,vehicle_damage_repair_job_id,id), CONSTRAINT b23_cost_replace_uq UNIQUE (company_id,vehicle_damage_repair_job_id,replacement_of_cost_entry_id),';
        $amountCheck = $sqlite
            ? "typeof(amount) = 'text' AND length(amount) BETWEEN 4 AND 13 AND amount NOT GLOB '*[^0-9.]*' AND instr(amount, '.') = length(amount)-2 AND substr(amount, 1, length(amount)-3) NOT GLOB '*.*' AND (substr(amount, 1, 1) <> '0' OR length(amount) = 4)"
            : 'amount BETWEEN 0.00 AND 9999999999.99';
        $debug = new \ReflectionProperty($db, 'DBDebug');
        $old = $debug->getValue($db);
        $debug->setValue($db, true);
        try {
            $this->ddl("CREATE UNIQUE INDEX b23_doc_context_uq ON {$docs} (company_id, vehicle_damage_repair_job_id, id)");
            // One explicit CREATE statement avoids Forge's raw-field first-token overwrite.
            $this->ddl("CREATE TABLE {$costs} (
                id {$primary}, company_id {$int} NOT NULL,
                vehicle_damage_repair_job_id {$id} NOT NULL,
                kind_code VARCHAR(20) NOT NULL, amount {$money} NOT NULL,
                currency CHAR(3) NOT NULL, occurred_on DATE NOT NULL,
                vendor_company_id {$int} NULL, vendor_snapshot VARCHAR(190) NOT NULL,
                vendor_reference VARCHAR(120) NULL, repair_document_id {$id} NOT NULL,
                related_cost_entry_id {$id} NULL, replacement_of_cost_entry_id {$id} NULL,
                status_code VARCHAR(12) NOT NULL DEFAULT 'recorded', note TEXT NULL,
                created_by {$int} NOT NULL, created_at DATETIME NOT NULL,
                voided_at DATETIME NULL, voided_by {$int} NULL, void_reason TEXT NULL,
                {$unique}
                CONSTRAINT b23_cost_kind_ck CHECK ({$kindValue} IN ('invoice','invoice_credit','payment','payment_refund')),
                CONSTRAINT b23_cost_status_ck CHECK ({$statusValue} IN ('recorded','voided')),
                CONSTRAINT b23_cost_currency_ck CHECK ({$currencyValue} = 'USD'),
                CONSTRAINT b23_cost_amount_ck CHECK ({$amountCheck} AND (kind_code = 'invoice' OR amount <> '0.00')),
                CONSTRAINT b23_cost_vendor_ck CHECK (length(trim(vendor_snapshot)) BETWEEN 1 AND 190),
                CONSTRAINT b23_cost_void_ck CHECK ((status_code = 'recorded' AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (status_code = 'voided' AND voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND length(trim(void_reason)) BETWEEN 1 AND 2000)),
                CONSTRAINT b23_cost_relation_ck CHECK ((kind_code = 'invoice' AND related_cost_entry_id IS NULL) OR kind_code = 'payment' OR (kind_code IN ('invoice_credit','payment_refund') AND related_cost_entry_id IS NOT NULL)),
                CONSTRAINT b23_cost_company_fk FOREIGN KEY (company_id) REFERENCES {$companies}(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b23_cost_job_fk FOREIGN KEY (company_id, vehicle_damage_repair_job_id) REFERENCES {$jobs}(company_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b23_cost_document_fk FOREIGN KEY (company_id, vehicle_damage_repair_job_id, repair_document_id) REFERENCES {$docs}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b23_cost_related_fk FOREIGN KEY (company_id, vehicle_damage_repair_job_id, related_cost_entry_id) REFERENCES {$costs}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b23_cost_replacement_fk FOREIGN KEY (company_id, vehicle_damage_repair_job_id, replacement_of_cost_entry_id) REFERENCES {$costs}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b23_cost_vendor_fk FOREIGN KEY (vendor_company_id) REFERENCES {$companies}(id) ON UPDATE RESTRICT ON DELETE RESTRICT
            )" . ($sqlite ? '' : ' ENGINE=InnoDB'));
            if ($sqlite) {
                $this->ddl("CREATE UNIQUE INDEX b23_cost_context_uq ON {$costs} (company_id,vehicle_damage_repair_job_id,id)");
                $this->ddl("CREATE UNIQUE INDEX b23_cost_replace_uq ON {$costs} (company_id,vehicle_damage_repair_job_id,replacement_of_cost_entry_id)");
            }
            foreach ([
                'b23_cost_history_idx' => 'company_id,vehicle_damage_repair_job_id,occurred_on,id',
                'b23_cost_current_idx' => 'company_id,vehicle_damage_repair_job_id,status_code,kind_code,id',
                'b23_cost_document_idx' => 'company_id,vehicle_damage_repair_job_id,repair_document_id',
                'b23_cost_related_idx' => 'company_id,vehicle_damage_repair_job_id,related_cost_entry_id',
                'b23_cost_vendor_idx' => 'vendor_company_id',
            ] as $index => $columns) {
                $this->ddl("CREATE INDEX {$index} ON {$costs} ({$columns})");
            }
            foreach (['cost_finalized_at' => 'DATETIME', 'cost_finalized_by' => $int, 'cost_finalization_note' => 'TEXT'] as $column => $type) {
                $this->ddl("ALTER TABLE {$jobs} ADD COLUMN {$column} {$type} NULL");
            }
            $finalization = '(NEW.cost_finalized_at IS NULL AND NEW.cost_finalized_by IS NULL AND NEW.cost_finalization_note IS NULL) OR (NEW.cost_finalized_at IS NOT NULL AND NEW.cost_finalized_by IS NOT NULL AND NEW.cost_finalization_note IS NOT NULL AND length(trim(NEW.cost_finalization_note)) BETWEEN 1 AND 2000)';
            if ($sqlite) {
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                    $trigger = $name('b23_job_finalization_' . $suffix);
                    $this->ddl("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$jobs} WHEN NOT ({$finalization}) BEGIN SELECT RAISE(ABORT, 'Incoherent cost finalization'); END");
                }
            } else {
                $check = str_replace('NEW.', '', $finalization);
                $this->ddl("ALTER TABLE {$jobs} ADD CONSTRAINT b23_job_finalization_ck CHECK ({$check})");
            }
            $facts = explode(' ', 'id company_id vehicle_damage_repair_job_id kind_code amount currency occurred_on vendor_company_id vendor_snapshot vendor_reference repair_document_id related_cost_entry_id replacement_of_cost_entry_id note created_by created_at');
            $comparisons = array_map(fn (string $f): string => $sqlite ? "NEW.{$f} IS NOT OLD.{$f}" : "NOT (BINARY NEW.{$f} <=> BINARY OLD.{$f})", $facts);
            $immutable = implode(' OR ', $comparisons) . " OR OLD.status_code <> 'recorded' OR NEW.status_code <> 'voided'";
            $updateTrigger = $name('b23_cost_immutable_update');
            $deleteTrigger = $name('b23_cost_no_delete');
            if ($sqlite) {
                $this->ddl("CREATE TRIGGER {$updateTrigger} BEFORE UPDATE ON {$costs} WHEN {$immutable} BEGIN SELECT RAISE(ABORT, 'Cost facts are immutable'); END");
                $this->ddl("CREATE TRIGGER {$deleteTrigger} BEFORE DELETE ON {$costs} BEGIN SELECT RAISE(ABORT, 'Cost history is retained'); END");
            } else {
                $this->ddl("CREATE TRIGGER {$updateTrigger} BEFORE UPDATE ON {$costs} FOR EACH ROW BEGIN IF {$immutable} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cost facts are immutable'; END IF; END");
                $this->ddl("CREATE TRIGGER {$deleteTrigger} BEFORE DELETE ON {$costs} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cost history is retained'");
            }
        } finally {
            $db->resetDataCache();
            $debug->setValue($db, $old);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('B2.3 retains monetary facts and their evidence. Restore an approved backup of the database and private runtime instead of destructive rollback.');
    }

    private function ddl(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('B2.3 DDL failed. Inspect the partial schema and migration ledger before recovery; do not blindly rerun.');
        }
    }
}
