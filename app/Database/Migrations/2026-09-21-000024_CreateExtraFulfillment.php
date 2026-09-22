<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExtraFulfillment extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('fleet_extras', [
            'fulfillment_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'none', 'after' => 'notes'],
            'requires_operator_confirmation' => ['type' => 'BOOLEAN', 'default' => false, 'after' => 'fulfillment_type'],
            'readiness_blocking' => ['type' => 'BOOLEAN', 'default' => false, 'after' => 'requires_operator_confirmation'],
            'default_action_label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'readiness_blocking'],
            'fulfillment_phase' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'default_action_label'],
        ]);
        $this->forge->addColumn('fleet_trip_commitments', [
            'fleet_extra_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'turo_trip_normalized_id'],
        ]);

        $this->createFulfillments();
        $this->createAudits();
    }

    public function down(): void
    {
        $this->forge->dropTable('trip_extra_fulfillment_audits', true);
        $this->forge->dropTable('trip_extra_fulfillments', true);
        $this->forge->dropColumn('fleet_trip_commitments', 'fleet_extra_id');
        $this->forge->dropColumn('fleet_extras', [
            'fulfillment_type',
            'requires_operator_confirmation',
            'readiness_blocking',
            'default_action_label',
            'fulfillment_phase',
        ]);
    }

    private function createFulfillments(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_extra_selection_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'state' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'current_basis_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'completed_basis_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'completed_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completion_note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'turo_extra_selection_id'], 'trip_extra_fulfillment_company_selection_unique');
        $this->forge->addKey(['company_id', 'state'], false, false, 'trip_extra_fulfillment_company_state');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_extra_selection_id', 'turo_extra_selections', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('trip_extra_fulfillments');
    }

    private function createAudits(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'trip_extra_fulfillment_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 30],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'before_values' => ['type' => 'JSON', 'null' => true],
            'after_values' => ['type' => 'JSON', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['trip_extra_fulfillment_id', 'created_at'], false, false, 'trip_extra_fulfillment_audit_record');
        $this->forge->addKey(['company_id', 'created_at'], false, false, 'trip_extra_fulfillment_audit_company');
        $this->forge->addForeignKey('trip_extra_fulfillment_id', 'trip_extra_fulfillments', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('trip_extra_fulfillment_audits');
    }
}
