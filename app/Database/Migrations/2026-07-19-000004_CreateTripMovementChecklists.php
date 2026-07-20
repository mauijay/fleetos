<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTripMovementChecklists extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'scheduled_at' => ['type' => 'DATETIME'],
            'readiness_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'not_started'],
            'vehicle_disposition' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'completion_note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['turo_trip_normalized_id', 'movement_type', 'scheduled_at'], 'trip_movement_checklists_unique');
        $this->forge->addKey(['fleet_vehicle_id', 'scheduled_at']);
        $this->forge->createTable('trip_movement_checklists');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'trip_movement_checklist_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'item_code' => ['type' => 'VARCHAR', 'constraint' => 80],
            'label' => ['type' => 'VARCHAR', 'constraint' => 190],
            'is_required' => ['type' => 'BOOLEAN', 'default' => true],
            'is_critical' => ['type' => 'BOOLEAN', 'default' => false],
            'applicability' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'applicable'],
            'completion_state' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'open'],
            'completion_source' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'sort_order' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['trip_movement_checklist_id', 'item_code'], 'trip_movement_checklist_items_unique');
        $this->forge->addKey(['trip_movement_checklist_id', 'completion_state']);
        $this->forge->createTable('trip_movement_checklist_items');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'trip_movement_checklist_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'trip_movement_checklist_item_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 60],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['trip_movement_checklist_id', 'created_at']);
        $this->forge->createTable('trip_movement_checklist_audits');
    }

    public function down(): void
    {
        $this->forge->dropTable('trip_movement_checklist_audits', true);
        $this->forge->dropTable('trip_movement_checklist_items', true);
        $this->forge->dropTable('trip_movement_checklists', true);
    }
}
