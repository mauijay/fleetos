<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTuroAccessOverrideIncidents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_movement_workflow_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'incident_stage' => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'reported'],
            'claim_status' => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'not_ready'],
            'incident_context' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'unknown'],
            'operator_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'unknown'],
            'incident_at' => ['type' => 'DATETIME', 'null' => true],
            'ticket_number' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'parking_entry_at' => ['type' => 'DATETIME', 'null' => true],
            'parking_exit_at' => ['type' => 'DATETIME', 'null' => true],
            'parking_amount_paid' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'payment_at' => ['type' => 'DATETIME', 'null' => true],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'expected_reimbursement_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'host_unreimbursed_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'claim_filed_on' => ['type' => 'DATE', 'null' => true],
            'claim_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'claimed_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'approved_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'reimbursed_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'reimbursed_on' => ['type' => 'DATE', 'null' => true],
            'denial_reason' => ['type' => 'TEXT', 'null' => true],
            'operator_note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_movement_workflow_id', 'claim_status']);
        $this->forge->addKey(['fleet_vehicle_id', 'incident_at']);
        $this->forge->addKey('ticket_number');
        $this->forge->createTable('airport_turo_access_override_incidents');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_turo_access_override_incident_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'file_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'attachment_type' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'paid_receipt'],
            'original_filename' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'document_date' => ['type' => 'DATE', 'null' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'ticket_number' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_turo_access_override_incident_id', 'attachment_type']);
        $this->forge->addKey(['fleet_vehicle_id', 'document_date']);
        $this->forge->createTable('airport_turo_access_receipts');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_turo_access_override_incident_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 80],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_turo_access_override_incident_id', 'created_at'], false, false, 'airport_turo_access_audits_incident_created_index');
        $this->forge->createTable('airport_turo_access_audits');
    }

    public function down(): void
    {
        $this->forge->dropTable('airport_turo_access_audits', true);
        $this->forge->dropTable('airport_turo_access_receipts', true);
        $this->forge->dropTable('airport_turo_access_override_incidents', true);
    }
}
