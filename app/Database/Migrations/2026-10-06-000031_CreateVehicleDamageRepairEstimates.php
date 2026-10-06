<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class CreateVehicleDamageRepairEstimates extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db instanceof \CodeIgniter\Database\BaseConnection) {
            throw new RuntimeException('B2.2 requires a supported database connection.');
        }
        $debugProperty = new \ReflectionProperty($db, 'DBDebug');
        $debug = $debugProperty->getValue($db);
        $debugProperty->setValue($db, true);
        try {
            $this->createSchema($db);
        } finally {
            $debugProperty->setValue($db, $debug);
        }
    }

    private function createSchema(\CodeIgniter\Database\BaseConnection $db): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $integer = ['type' => 'INT', 'unsigned' => true];
        $datetime = ['type' => 'DATETIME'];
        $text = ['type' => 'TEXT', 'null' => true];
        $actors = ['created_by' => $integer, 'updated_by' => $integer, 'created_at' => $datetime, 'updated_at' => $datetime];
        $member = 'vehicle_damage_repair_job_items';
        $this->forge->addUniqueKey(['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_member_context_uq');
        $this->ddl($this->forge->processIndexes($member));

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer,
            'vehicle_damage_repair_job_id' => $id,
            'quote_series_key' => ['type' => 'CHAR', 'constraint' => 36], 'revision_number' => $integer,
            'previous_estimate_id' => $id + ['null' => true], 'superseded_by_estimate_id' => $id + ['null' => true],
            'amount' => $this->db->getPlatform() === 'SQLite3' ? ['type' => 'TEXT'] : ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'currency' => ['type' => 'CHAR', 'constraint' => 3],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'received'],
            'recording_mode' => ['type' => 'VARCHAR', 'constraint' => 24],
            'vendor_company_id' => $integer + ['null' => true],
            'vendor_snapshot' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'quote_date' => ['type' => 'DATE', 'null' => true], 'expires_at' => $datetime + ['null' => true],
            'expires_on' => ['type' => 'DATE', 'null' => true],
            'vendor_quote_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'note' => $text, 'historical_recording_reason' => $text,
        ] + $actors);
        $this->forge->addField('CHECK (revision_number > 0 AND (expires_at IS NULL OR expires_on IS NULL))');
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'id'], 'b22_est_company_id_uq');
        $this->forge->addUniqueKey(['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_est_context_id_uq');
        $this->forge->addUniqueKey(['company_id', 'vehicle_damage_repair_job_id', 'quote_series_key', 'revision_number'], 'b22_est_series_revision_uq');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', 'status_code', 'id'], false, false, 'b22_est_job_status_idx');
        $this->forge->addKey('vendor_company_id', false, false, 'b22_est_vendor_idx');
        foreach (['previous_estimate_id' => 'previous', 'superseded_by_estimate_id' => 'replacement'] as $column => $name) {
            $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', $column], false, false, 'b22_est_' . $name . '_idx');
            $this->foreign(['company_id', 'vehicle_damage_repair_job_id', $column], 'vehicle_damage_repair_estimates', ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_est_' . $name . '_fk');
        }
        $this->foreign('company_id', 'companies', 'id', 'b22_est_company_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id'], 'b22_est_job_fk');
        $this->foreign('vendor_company_id', 'companies', 'id', 'b22_est_vendor_fk');
        $this->ddl($this->forge->createTable('vehicle_damage_repair_estimates'));

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer,
            'vehicle_damage_repair_job_id' => $id, 'vehicle_damage_repair_estimate_id' => $id,
            'vehicle_damage_repair_job_item_id' => $id, 'condition_snapshot' => ['type' => 'JSON'],
            'created_by' => $integer, 'created_at' => $datetime,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id'], 'b22_scope_est_member_uq');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_estimate_id', 'id'], false, false, 'b22_scope_est_idx');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id', 'id'], false, false, 'b22_scope_member_idx');
        $this->foreign('company_id', 'companies', 'id', 'b22_scope_company_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_estimate_id'], 'vehicle_damage_repair_estimates', ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_scope_est_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id'], $member, ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_scope_member_fk');
        $this->ddl($this->forge->createTable('vehicle_damage_repair_estimate_items'));

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer, 'vehicle_damage_repair_job_id' => $id,
            'vehicle_damage_repair_estimate_id' => $id + ['null' => true], 'vehicle_damage_repair_job_item_id' => $id + ['null' => true],
            'kind_code' => ['type' => 'VARCHAR', 'constraint' => 30],
            'file_id' => $integer + ['null' => true], 'image_id' => $integer + ['null' => true],
            'external_reference' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true], 'note' => $text,
            'content_checksum' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'content_mime_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'content_size_bytes' => $integer + ['null' => true],
            'content_original_filename' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'archived_at' => $datetime + ['null' => true], 'archived_by' => $integer + ['null' => true], 'archive_reason' => $text,
        ] + $actors);
        $this->forge->addField('CHECK ((CASE WHEN file_id IS NULL THEN 0 ELSE 1 END + CASE WHEN image_id IS NULL THEN 0 ELSE 1 END + CASE WHEN external_reference IS NULL THEN 0 ELSE 1 END) = 1)');
        $this->forge->addField('CHECK (external_reference IS NULL OR LENGTH(TRIM(external_reference)) > 0)');
        $this->forge->addField('CHECK ((archived_at IS NULL AND archived_by IS NULL AND archive_reason IS NULL) OR (archived_at IS NOT NULL AND archived_by IS NOT NULL AND archive_reason IS NOT NULL AND LENGTH(TRIM(archive_reason)) > 0))');
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'id'], 'b22_doc_company_id_uq');
        foreach (['archived_at' => 'history', 'vehicle_damage_repair_estimate_id' => 'estimate', 'vehicle_damage_repair_job_item_id' => 'member'] as $column => $name) {
            $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', $column, 'id'], false, false, 'b22_doc_' . $name . '_idx');
        }
        $this->forge->addKey(['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id'], false, false, 'b22_doc_scope_idx');
        $this->foreign('company_id', 'companies', 'id', 'b22_doc_company_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id'], 'b22_doc_job_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_estimate_id'], 'vehicle_damage_repair_estimates', ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_doc_est_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id'], $member, ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_doc_member_fk');
        $this->foreign(['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id'], 'vehicle_damage_repair_estimate_items', ['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id'], 'b22_doc_scope_fk');
        foreach (['file_id' => 'files', 'image_id' => 'images'] as $column => $target) {
            $this->forge->addKey($column, false, false, 'b22_doc_' . $column . '_idx');
            $this->foreign($column, $target, 'id', 'b22_doc_' . $column . '_fk');
        }
        $this->ddl($this->forge->createTable('vehicle_damage_repair_documents'));

        $jobs = 'vehicle_damage_repair_jobs';
        if ($this->db->getPlatform() === 'SQLite3') {
            $j = $db->escapeIdentifiers($db->prefixTable($jobs));
            $e = $db->escapeIdentifiers($db->prefixTable('vehicle_damage_repair_estimates'));
            $this->ddl($this->db->query('ALTER TABLE ' . $j . ' ADD COLUMN accepted_estimate_id INTEGER NULL REFERENCES ' . $e . '(id) ON UPDATE RESTRICT ON DELETE RESTRICT'));
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                $trigger = $db->escapeIdentifiers($db->prefixTable('b22_job_accepted_' . $suffix));
                $this->ddl($this->db->query('CREATE TRIGGER ' . $trigger . ' BEFORE ' . $operation . ' ON ' . $j . ' WHEN NEW.accepted_estimate_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $e . ' WHERE id=NEW.accepted_estimate_id AND company_id=NEW.company_id AND vehicle_damage_repair_job_id=NEW.id) BEGIN SELECT RAISE(ABORT, \'Accepted estimate must belong to this company and job\'); END'));
            }
        } else {
            $this->ddl($this->forge->addColumn($jobs, ['accepted_estimate_id' => $id + ['null' => true]]));
            $this->foreign(['company_id', 'id', 'accepted_estimate_id'], 'vehicle_damage_repair_estimates', ['company_id', 'vehicle_damage_repair_job_id', 'id'], 'b22_job_accepted_fk');
        }
        $this->forge->addKey('accepted_estimate_id', false, false, 'b22_job_accepted_idx');
        $this->forge->addKey(['company_id', 'id', 'accepted_estimate_id'], false, false, 'b22_job_accepted_context_idx');
        $this->ddl($this->forge->processIndexes($jobs));
    }

    public function down(): void
    {
        throw new RuntimeException('B2.2 preserves append-only quote revisions and private document authority. Restore an approved backup of the database and private runtime instead of destructive rollback.');
    }

    private function foreign(string|array $column, string $table, string|array $target, string $name): void
    {
        $this->forge->addForeignKey($column, $table, $target, 'RESTRICT', 'RESTRICT', $this->db->getPlatform() === 'SQLite3' ? '' : $name);
    }

    private function ddl(mixed $result): void
    {
        if ($result === false) {
            throw new RuntimeException('B2.2 DDL failed. Inspect the partial schema and migration ledger before recovery; do not blindly rerun.');
        }
    }
}
