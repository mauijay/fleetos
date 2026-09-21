<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFleetTripCommitments extends Migration
{
    public function up(): void
    {
        $this->createCommitments();
        $this->createAudits();
    }

    public function down(): void
    {
        $this->forge->dropTable('fleet_trip_commitment_audits', true);
        $this->forge->dropTable('fleet_trip_commitments', true);
    }

    private function createCommitments(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'category' => ['type' => 'VARCHAR', 'constraint' => 40],
            'instruction' => ['type' => 'TEXT'],
            'applies_during' => ['type' => 'VARCHAR', 'constraint' => 30],
            'handling_mode' => ['type' => 'VARCHAR', 'constraint' => 30],
            'required_before_dispatch' => ['type' => 'BOOLEAN', 'default' => false],
            'energy_comparison' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'energy_percent' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'arranged_at' => ['type' => 'DATETIME', 'null' => true],
            'state' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'active_override_slot' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
            'acknowledged_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'completed_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'canceled_at' => ['type' => 'DATETIME', 'null' => true],
            'canceled_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'cancellation_reason' => ['type' => 'TEXT', 'null' => true],
            'created_by_user_id' => ['type' => 'INT', 'unsigned' => true],
            'updated_by_user_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['turo_trip_normalized_id', 'active_override_slot'], 'fleet_trip_commitment_active_override_unique');
        $this->forge->addKey(['company_id', 'turo_trip_normalized_id', 'state'], false, false, 'fleet_trip_commitment_company_trip_state');
        $this->forge->addKey(['company_id', 'applies_during', 'state'], false, false, 'fleet_trip_commitment_company_phase_state');
        $this->forge->addKey(['turo_trip_normalized_id', 'category'], false, false, 'fleet_trip_commitment_trip_category');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('fleet_trip_commitments');
    }

    private function createAudits(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'commitment_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 30],
            'before_values' => ['type' => 'JSON', 'null' => true],
            'after_values' => ['type' => 'JSON', 'null' => true],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['commitment_id', 'created_at'], false, false, 'fleet_trip_commitment_audit_record');
        $this->forge->addKey(['company_id', 'created_at'], false, false, 'fleet_trip_commitment_audit_company');
        $this->forge->addForeignKey('commitment_id', 'fleet_trip_commitments', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('fleet_trip_commitment_audits');
    }
}
