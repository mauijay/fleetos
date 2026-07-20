<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAirportMovementWorkflows extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_delivery_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'trip_movement_checklist_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'airport_id' => ['type' => 'INT', 'unsigned' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'scheduled_at' => ['type' => 'DATETIME'],
            'workflow_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'not_started'],
            'garage' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'terminal' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'airline_or_flight' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'parking_level' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'parking_zone' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'parking_row' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'parking_stall' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'parking_entry_at' => ['type' => 'DATETIME', 'null' => true],
            'parking_exit_at' => ['type' => 'DATETIME', 'null' => true],
            'vehicle_staged_at' => ['type' => 'DATETIME', 'null' => true],
            'vehicle_recovered_at' => ['type' => 'DATETIME', 'null' => true],
            'key_card_confirmed_at' => ['type' => 'DATETIME', 'null' => true],
            'vehicle_locked_at' => ['type' => 'DATETIME', 'null' => true],
            'parking_ticket_location' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'parking_access_method' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'estimated_parking_cost_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'actual_parking_cost_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'parking_cost_responsibility' => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'unknown'],
            'guest_instructions' => ['type' => 'TEXT', 'null' => true],
            'guest_instructions_sent_at' => ['type' => 'DATETIME', 'null' => true],
            'guest_pickup_confirmed_at' => ['type' => 'DATETIME', 'null' => true],
            'return_location_reported_at' => ['type' => 'DATETIME', 'null' => true],
            'guest_reported_level' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'guest_reported_zone' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'guest_reported_row' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'guest_reported_stall' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'guest_note' => ['type' => 'TEXT', 'null' => true],
            'operator_notes' => ['type' => 'TEXT', 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['turo_trip_normalized_id', 'movement_type', 'scheduled_at'], 'airport_movement_workflows_unique');
        $this->forge->addKey(['fleet_vehicle_id', 'scheduled_at']);
        $this->forge->addKey(['workflow_status', 'scheduled_at']);
        $this->forge->createTable('airport_movement_workflows');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_movement_workflow_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'exception_type' => ['type' => 'VARCHAR', 'constraint' => 80],
            'severity' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'today'],
            'note' => ['type' => 'TEXT'],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'resolution_note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_movement_workflow_id', 'resolved_at']);
        $this->forge->createTable('airport_movement_exceptions');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_movement_workflow_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 60],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_movement_workflow_id', 'created_at']);
        $this->forge->createTable('airport_movement_audits');
    }

    public function down(): void
    {
        $this->forge->dropTable('airport_movement_audits', true);
        $this->forge->dropTable('airport_movement_exceptions', true);
        $this->forge->dropTable('airport_movement_workflows', true);
    }
}
