<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

class CreateSuperchargerReconciliation extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('turo_trips_normalized', [
            'on_trip_ev_charging_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0, 'after' => 'airport_fee_amount'],
            'post_trip_ev_charging_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0, 'after' => 'on_trip_ev_charging_amount'],
        ]);

        $this->forge->addColumn('charging_sessions', [
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'turo_trip_normalized_id'],
            'source_session_fingerprint' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'source_type'],
            'source_invoice_number' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'source_session_fingerprint'],
            'source_vin' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'source_invoice_number'],
            'source_started_at' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'source_vin'],
            'source_invoice_url' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true, 'after' => 'source_started_at'],
            'custody_classification' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'source_invoice_url'],
            'custody_basis_code' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'custody_classification'],
            'custody_basis_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'custody_basis_code'],
            'candidate_turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'custody_basis_event_id'],
        ]);
        $connection = $this->baseConnection();
        $chargingSessions = $connection->protectIdentifiers($connection->prefixTable('charging_sessions'));
        $sourceIndex = $connection->protectIdentifiers($connection->getPrefix() . 'charging_sessions_source_fingerprint_unique');
        $candidateIndex = $connection->protectIdentifiers($connection->getPrefix() . 'charging_sessions_candidate_trip_idx');
        $this->db->query("CREATE UNIQUE INDEX {$sourceIndex} ON {$chargingSessions} (source_session_fingerprint)");
        $this->db->query("CREATE INDEX {$candidateIndex} ON {$chargingSessions} (candidate_turo_trip_normalized_id)");
        if ($this->db instanceof \CodeIgniter\Database\SQLite3\Connection) {
            (new \App\Database\SQLiteMigrationTable($this->db, new \CodeIgniter\Database\SQLite3\Forge($this->db)))
                ->fromTable($this->db->prefixTable('charging_sessions'))
                ->addForeignKey([
                    ['field' => ['custody_basis_event_id'], 'referenceTable' => $this->db->prefixTable('trip_movement_events'), 'referenceField' => ['id'], 'onUpdate' => 'CASCADE', 'onDelete' => 'SET NULL'],
                    ['field' => ['candidate_turo_trip_normalized_id'], 'referenceTable' => $this->db->prefixTable('turo_trips_normalized'), 'referenceField' => ['id'], 'onUpdate' => 'CASCADE', 'onDelete' => 'SET NULL'],
                ])->run();
        } else {
            $this->forge->addForeignKey('custody_basis_event_id', 'trip_movement_events', 'id', 'CASCADE', 'SET NULL', $this->foreignKeyName('charging_sessions_custody_event_fk'));
            $this->forge->addForeignKey('candidate_turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'SET NULL', $this->foreignKeyName('charging_sessions_candidate_trip_fk'));
            $this->forge->processIndexes('charging_sessions');
        }

        $this->createImportBatches();
        $this->createImportRows();
        $this->createLineItems();
        $this->createReimbursementCases();
    }

    public function down(): void
    {
        $this->forge->dropTable('supercharger_reimbursement_cases', true);
        $this->forge->dropTable('tesla_charging_line_items', true);
        $this->forge->dropTable('tesla_charging_import_rows', true);
        $this->forge->dropTable('tesla_charging_import_batches', true);

        if ($this->db->getPlatform() !== 'SQLite3') {
            $this->forge->dropForeignKey('charging_sessions', $this->foreignKeyName('charging_sessions_candidate_trip_fk'));
            $this->forge->dropForeignKey('charging_sessions', $this->foreignKeyName('charging_sessions_custody_event_fk'));
            $this->forge->dropKey('charging_sessions', 'charging_sessions_candidate_trip_idx');
            $this->forge->dropKey('charging_sessions', 'charging_sessions_source_fingerprint_unique');
        }
        $chargingColumns = [
            'candidate_turo_trip_normalized_id',
            'custody_basis_event_id',
            'custody_basis_code',
            'custody_classification',
            'source_invoice_url',
            'source_started_at',
            'source_vin',
            'source_invoice_number',
            'source_session_fingerprint',
            'source_type',
        ];
        foreach ($chargingColumns as $column) {
            $this->forge->dropColumn('charging_sessions', $column);
        }
        $this->forge->dropColumn('turo_trips_normalized', 'post_trip_ev_charging_amount');
        $this->forge->dropColumn('turo_trips_normalized', 'on_trip_ev_charging_amount');
    }

    private function createImportBatches(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'source_filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'source_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'processing'],
            'row_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'imported_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'duplicate_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'review_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'rejected_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'imported_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'source_hash'], 'tesla_import_batch_source_unique');
        $this->forge->addKey(['company_id', 'created_at'], false, false, 'tesla_import_batch_company_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('tesla_import_batch_company_fk'));
        $this->forge->createTable('tesla_charging_import_batches');
    }

    private function createImportRows(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'tesla_charging_import_batch_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'row_number' => ['type' => 'INT', 'unsigned' => true],
            'row_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'raw_payload' => ['type' => 'JSON'],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 30],
            'issue_code' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'issue_detail' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tesla_charging_import_batch_id', 'row_number'], 'tesla_import_row_number_unique');
        $this->forge->addKey(['company_id', 'status_code', 'issue_code'], false, false, 'tesla_import_row_review_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('tesla_import_row_company_fk'));
        $this->forge->addForeignKey('tesla_charging_import_batch_id', 'tesla_charging_import_batches', 'id', 'CASCADE', 'CASCADE', $this->foreignKeyName('tesla_import_row_batch_fk'));
        $this->forge->createTable('tesla_charging_import_rows');
    }

    private function createLineItems(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'charging_session_id' => ['type' => 'INT', 'unsigned' => true],
            'tesla_charging_import_row_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'source_line_fingerprint' => ['type' => 'CHAR', 'constraint' => 64],
            'invoice_number' => ['type' => 'VARCHAR', 'constraint' => 120],
            'vin' => ['type' => 'VARCHAR', 'constraint' => 32],
            'source_started_at' => ['type' => 'VARCHAR', 'constraint' => 64],
            'started_at' => ['type' => 'DATETIME'],
            'site_location_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'fee_description' => ['type' => 'VARCHAR', 'constraint' => 255],
            'quantity_source' => ['type' => 'JSON', 'null' => true],
            'unit_cost_source' => ['type' => 'JSON', 'null' => true],
            'total_inc_vat_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'invoice_url' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'source_line_fingerprint'], 'tesla_line_fingerprint_unique');
        $this->forge->addKey(['charging_session_id', 'id'], false, false, 'tesla_line_session_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('tesla_line_company_fk'));
        $this->forge->addForeignKey('charging_session_id', 'charging_sessions', 'id', 'CASCADE', 'CASCADE', $this->foreignKeyName('tesla_line_session_fk'));
        $this->forge->addForeignKey('tesla_charging_import_row_id', 'tesla_charging_import_rows', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('tesla_line_import_row_fk'));
        $this->forge->createTable('tesla_charging_line_items');
    }

    private function createReimbursementCases(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'workflow_state_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'not_submitted'],
            'workflow_note' => ['type' => 'TEXT', 'null' => true],
            'invoice_reference' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'workflow_changed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'workflow_changed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'turo_trip_normalized_id'], 'supercharger_case_trip_unique');
        $this->forge->addKey(['company_id', 'workflow_state_code', 'updated_at'], false, false, 'supercharger_case_workflow_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('supercharger_case_company_fk'));
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('supercharger_case_vehicle_fk'));
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'CASCADE', $this->foreignKeyName('supercharger_case_trip_fk'));
        $this->forge->createTable('supercharger_reimbursement_cases');
    }

    private function foreignKeyName(string $name): string
    {
        return $this->db->getPlatform() === 'SQLite3' ? '' : $name;
    }

    private function baseConnection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('Supercharger reconciliation migration requires a CodeIgniter base database connection.');
        }

        return $this->db;
    }
}
