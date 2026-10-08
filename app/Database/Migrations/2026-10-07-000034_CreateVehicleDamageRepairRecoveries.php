<?php

namespace App\Database\Migrations;

use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\VehicleDamageRepairRecoveries as Policy;
use RuntimeException;

/** Additive immutable receipt history; source recognition is structurally reserved, disabled in code. */
class CreateVehicleDamageRepairRecoveries extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db instanceof BaseConnection || ! in_array($db->getPlatform(), ['SQLite3', 'MySQLi'], true)) {
            throw new RuntimeException('B3.1 requires SQLite or MariaDB.');
        }
        $sqlite = $db->getPlatform() === 'SQLite3';
        $name = fn (string $table): string => $db->escapeIdentifiers($db->prefixTable($table));
        $entries = $name(Recoveries::TABLE);
        $jobs = $name('vehicle_damage_repair_jobs');
        $docs = $name('vehicle_damage_repair_documents');
        $companies = $name('companies');
        $claims = $name('damage_claims');
        $normalized = $name('turo_transactions_normalized');
        $raw = $name('turo_transaction_raw');
        $id = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $primary = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $money = $sqlite ? 'TEXT' : 'DECIMAL(12,2)';
        $binary = fn (string $field): string => $sqlite ? $field : 'BINARY ' . $field;
        $kind = $binary('kind_code');
        $status = $binary('status_code');
        $authority = $binary('authority_code');
        $source = $binary('source_type');
        $currency = $binary('currency');
        $amountCheck = $sqlite
            ? "typeof(amount) = 'text' AND length(amount) BETWEEN 4 AND 13 AND amount NOT GLOB '*[^0-9.]*' AND instr(amount, '.') = length(amount)-2 AND substr(amount, 1, length(amount)-3) NOT GLOB '*.*' AND (substr(amount, 1, 1) <> '0' OR length(amount) = 4) AND amount <> '0.00'"
            : 'amount > 0.00 AND amount <= 9999999999.99';
        $debug = new \ReflectionProperty($db, 'DBDebug');
        $old = $debug->getValue($db);
        $debug->setValue($db, true);
        try {
            $this->ddl("CREATE TABLE {$entries} (
                id {$primary}, company_id {$int} NOT NULL, vehicle_damage_repair_job_id {$id} NOT NULL,
                kind_code VARCHAR(20) NOT NULL, authority_code VARCHAR(20) NOT NULL, source_type VARCHAR(32) NOT NULL,
                amount {$money} NULL, currency CHAR(3) NULL, occurred_on DATE NULL,
                payer_snapshot VARCHAR(190) NOT NULL, source_namespace VARCHAR(120) NOT NULL, source_reference VARCHAR(120) NOT NULL,
                source_identity_key CHAR(64) NOT NULL, source_root_key CHAR(64) NULL,
                turo_transaction_normalized_id {$id} NULL, turo_transaction_raw_id {$id} NULL, turo_source_root_id {$id} NULL,
                damage_claim_id {$int} NULL, repair_document_id {$id} NOT NULL, recognized_source_fingerprint CHAR(64) NULL,
                source_snapshot JSON NOT NULL, related_recovery_entry_id {$id} NULL, replacement_of_recovery_entry_id {$id} NULL,
                status_code VARCHAR(12) NOT NULL DEFAULT 'recorded', note TEXT NULL,
                created_by {$int} NOT NULL, created_at DATETIME NOT NULL,
                voided_by {$int} NULL, voided_at DATETIME NULL, void_reason TEXT NULL,
                CONSTRAINT b31_recovery_context_uq UNIQUE (company_id,vehicle_damage_repair_job_id,id),
                CONSTRAINT b31_recovery_replace_uq UNIQUE (company_id,vehicle_damage_repair_job_id,replacement_of_recovery_entry_id),
                CONSTRAINT b31_recovery_source_uq UNIQUE (company_id,source_root_key),
                CONSTRAINT b31_recovery_turo_uq UNIQUE (turo_source_root_id),
                CONSTRAINT b31_recovery_kind_ck CHECK ({$kind} IN ('recovery','recovery_reversal')),
                CONSTRAINT b31_recovery_status_ck CHECK ({$status} IN ('recorded','voided')),
                CONSTRAINT b31_recovery_source_ck CHECK ({$source} IN ('turo_reimbursement','guest_direct','insurance','vendor_compensation','other')),
                CONSTRAINT b31_recovery_authority_ck CHECK (
                    ({$authority} = 'external_receipt' AND {$source} <> 'turo_reimbursement' AND amount IS NOT NULL AND currency IS NOT NULL AND {$currency} = 'USD' AND occurred_on IS NOT NULL AND {$amountCheck}
                     AND turo_transaction_normalized_id IS NULL AND turo_transaction_raw_id IS NULL AND turo_source_root_id IS NULL AND recognized_source_fingerprint IS NULL)
                    OR ({$authority} = 'turo_transaction' AND {$source} = 'turo_reimbursement' AND amount IS NULL AND currency IS NULL AND occurred_on IS NULL
                     AND turo_transaction_normalized_id IS NOT NULL AND turo_transaction_raw_id IS NOT NULL AND recognized_source_fingerprint IS NOT NULL AND length(recognized_source_fingerprint) = 64)),
                CONSTRAINT b31_recovery_identity_ck CHECK (length(source_identity_key) = 64 AND length(trim(source_namespace)) BETWEEN 1 AND 120 AND length(trim(source_reference)) BETWEEN 1 AND 120 AND length(trim(payer_snapshot)) BETWEEN 1 AND 190),
                CONSTRAINT b31_recovery_root_ck CHECK (
                    (replacement_of_recovery_entry_id IS NULL AND source_root_key IS NOT NULL AND " . $binary('source_root_key') . ' = ' . $binary('source_identity_key') . " AND (authority_code <> 'turo_transaction' OR (turo_source_root_id IS NOT NULL AND turo_source_root_id = turo_transaction_normalized_id)))
                    OR (replacement_of_recovery_entry_id IS NOT NULL AND source_root_key IS NULL AND turo_source_root_id IS NULL)),
                CONSTRAINT b31_recovery_relation_ck CHECK (({$kind} = 'recovery' AND related_recovery_entry_id IS NULL) OR ({$kind} = 'recovery_reversal' AND related_recovery_entry_id IS NOT NULL)),
                CONSTRAINT b31_recovery_void_ck CHECK (({$status} = 'recorded' AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR ({$status} = 'voided' AND voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND length(trim(void_reason)) BETWEEN 1 AND 2000)),
                CONSTRAINT b31_recovery_note_ck CHECK (note IS NULL OR length(note) <= 2000),
                CONSTRAINT b31_recovery_snapshot_ck CHECK (json_valid(source_snapshot)),
                CONSTRAINT b31_recovery_company_fk FOREIGN KEY (company_id) REFERENCES {$companies}(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_job_fk FOREIGN KEY (company_id,vehicle_damage_repair_job_id) REFERENCES {$jobs}(company_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_doc_fk FOREIGN KEY (company_id,vehicle_damage_repair_job_id,repair_document_id) REFERENCES {$docs}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_related_fk FOREIGN KEY (company_id,vehicle_damage_repair_job_id,related_recovery_entry_id) REFERENCES {$entries}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_replacement_fk FOREIGN KEY (company_id,vehicle_damage_repair_job_id,replacement_of_recovery_entry_id) REFERENCES {$entries}(company_id,vehicle_damage_repair_job_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_claim_fk FOREIGN KEY (damage_claim_id) REFERENCES {$claims}(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_turo_fk FOREIGN KEY (turo_transaction_normalized_id) REFERENCES {$normalized}(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_raw_fk FOREIGN KEY (turo_transaction_raw_id) REFERENCES {$raw}(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT b31_recovery_turo_root_fk FOREIGN KEY (turo_source_root_id) REFERENCES {$normalized}(id) ON UPDATE RESTRICT ON DELETE RESTRICT
            )" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARACTER SET ' . Policy::CHARSET . ' COLLATE ' . Policy::COLLATION));
            // SQLite reports constraint-generated index names differently; give readiness deterministic names.
            if ($sqlite) {
                foreach (array_filter(Recoveries::INDEXES, fn (array $index): bool => $index[0] === 'UNIQUE') as $index => [$type, $fields]) {
                    $this->ddl('CREATE UNIQUE INDEX ' . $index . ' ON ' . $entries . ' (' . implode(',', $fields) . ')');
                }
            }
            foreach (array_filter(Recoveries::INDEXES, fn (array $index): bool => $index[0] === 'INDEX') as $index => [$type, $fields]) {
                $this->ddl('CREATE INDEX ' . $index . ' ON ' . $entries . ' (' . implode(',', $fields) . ')');
            }
            foreach (['recovery_finalized_at' => 'DATETIME', 'recovery_finalized_by' => $int, 'recovery_finalization_note' => 'TEXT'] as $column => $type) {
                $this->ddl("ALTER TABLE {$jobs} ADD COLUMN {$column} {$type} NULL");
            }
            $coherent = '(NEW.recovery_finalized_at IS NULL AND NEW.recovery_finalized_by IS NULL AND NEW.recovery_finalization_note IS NULL) OR (NEW.recovery_finalized_at IS NOT NULL AND NEW.recovery_finalized_by IS NOT NULL AND NEW.recovery_finalization_note IS NOT NULL AND length(trim(NEW.recovery_finalization_note)) BETWEEN 1 AND 2000)';
            if ($sqlite) {
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                    $this->ddl('CREATE TRIGGER ' . $name('b31_job_finalization_' . $suffix) . " BEFORE {$operation} ON {$jobs} WHEN NOT ({$coherent}) BEGIN SELECT RAISE(ABORT, 'Incoherent recovery finalization'); END");
                }
            } else {
                $this->ddl("ALTER TABLE {$jobs} ADD CONSTRAINT b31_job_finalization_ck CHECK (" . str_replace('NEW.', '', $coherent) . ')');
            }
            $facts = array_diff(explode(' ', Recoveries::FIELDS), ['status_code', 'voided_by', 'voided_at', 'void_reason']);
            $immutable = implode(' OR ', array_map(fn (string $f): string => $sqlite ? "NEW.{$f} IS NOT OLD.{$f}" : "NOT (BINARY NEW.{$f} <=> BINARY OLD.{$f})", $facts)) . " OR OLD.status_code <> 'recorded' OR NEW.status_code <> 'voided'";
            if ($sqlite) {
                $this->ddl('CREATE TRIGGER ' . $name('b31_recovery_immutable_update') . " BEFORE UPDATE ON {$entries} WHEN {$immutable} BEGIN SELECT RAISE(ABORT, 'Recovery facts are immutable'); END");
                $this->ddl('CREATE TRIGGER ' . $name('b31_recovery_no_delete') . " BEFORE DELETE ON {$entries} BEGIN SELECT RAISE(ABORT, 'Recovery history is retained'); END");
            } else {
                $this->ddl('CREATE TRIGGER ' . $name('b31_recovery_immutable_update') . " BEFORE UPDATE ON {$entries} FOR EACH ROW BEGIN IF {$immutable} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery facts are immutable'; END IF; END");
                $this->ddl('CREATE TRIGGER ' . $name('b31_recovery_no_delete') . " BEFORE DELETE ON {$entries} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery history is retained'");
            }
        } finally {
            $db->resetDataCache();
            $debug->setValue($db, $old);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('B3.1 recovery history is forward-only. Restore an approved backup of the database/private runtime instead of destructive rollback.');
    }

    private function ddl(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('B3.1 DDL failed. Inspect actual schema and ledger before recovery; never blindly rerun.');
        }
    }
}
