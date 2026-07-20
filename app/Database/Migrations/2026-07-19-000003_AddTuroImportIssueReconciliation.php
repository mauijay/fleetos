<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTuroImportIssueReconciliation extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('turo_import_errors', [
            'reconciliation_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'resolution_note'],
            'reconciliation_result_code' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'reconciliation_status'],
            'reconciliation_message' => ['type' => 'TEXT', 'null' => true, 'after' => 'reconciliation_result_code'],
            'reconciled_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'reconciliation_message'],
            'reconciled_trip_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'reconciled_at'],
            'last_reprocess_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'reconciled_trip_id'],
            'reprocess_attempt_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'last_reprocess_attempt_at'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'turo_import_error_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'turo_vehicle_id' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'result_code' => ['type' => 'VARCHAR', 'constraint' => 80],
            'message' => ['type' => 'TEXT'],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['turo_import_error_id', 'created_at']);
        $this->forge->addKey(['turo_vehicle_id', 'result_code']);
        $this->forge->createTable('turo_import_reprocess_attempts');
    }

    public function down(): void
    {
        $this->forge->dropTable('turo_import_reprocess_attempts', true);
        $this->forge->dropColumn('turo_import_errors', [
            'reconciliation_status',
            'reconciliation_result_code',
            'reconciliation_message',
            'reconciled_at',
            'reconciled_trip_id',
            'last_reprocess_attempt_at',
            'reprocess_attempt_count',
        ]);
    }
}
