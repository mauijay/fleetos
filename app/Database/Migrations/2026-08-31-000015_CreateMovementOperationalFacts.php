<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMovementOperationalFacts extends Migration
{
    public function up(): void
    {
        $this->createScheduledMovementLocations();
        $this->createTripMovementEvents();
        $this->createMovementAssessments();
        $this->createVehicleOperationalProfiles();
        $this->createVehicleOperationalCapabilities();
        $this->createOperationalFactAudits();
    }

    public function down(): void
    {
        $this->forge->dropTable('operational_fact_audits', true);
        $this->forge->dropTable('vehicle_operational_capabilities', true);
        $this->forge->dropTable('vehicle_operational_profiles', true);
        $this->forge->dropTable('movement_assessments', true);
        $this->forge->dropTable('trip_movement_events', true);
        $this->forge->dropTable('scheduled_movement_locations', true);
    }

    private function createScheduledMovementLocations(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'location_class' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'unknown'],
            'source_text' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'airport_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'airport_movement_workflow_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'classification_source' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'unclassified'],
            'classification_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'pending'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['turo_trip_normalized_id', 'movement_type'], 'scheduled_movement_locations_trip_type_unique');
        $this->forge->addKey(['fleet_vehicle_id', 'movement_type']);
        $this->forge->addKey(['location_class', 'classification_status']);
        $this->forge->createTable('scheduled_movement_locations');
    }

    private function createTripMovementEvents(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'event_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'occurred_at' => ['type' => 'DATETIME'],
            'location_class' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'location_detail' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'source' => ['type' => 'VARCHAR', 'constraint' => 40],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'supersedes_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'voided_at' => ['type' => 'DATETIME', 'null' => true],
            'voided_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'void_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['fleet_vehicle_id', 'occurred_at']);
        $this->forge->addKey(['turo_trip_normalized_id', 'event_code']);
        $this->forge->addKey(['company_id', 'event_code']);
        $this->forge->addKey('supersedes_event_id');
        $this->forge->createTable('trip_movement_events');
    }

    private function createMovementAssessments(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'trip_movement_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'cleanliness' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'energy_percent' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'captured_at' => ['type' => 'DATETIME'],
            'source' => ['type' => 'VARCHAR', 'constraint' => 40],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'supersedes_assessment_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'voided_at' => ['type' => 'DATETIME', 'null' => true],
            'voided_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'void_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['fleet_vehicle_id', 'captured_at']);
        $this->forge->addKey(['turo_trip_normalized_id', 'movement_type']);
        $this->forge->addKey('trip_movement_event_id');
        $this->forge->addKey('supersedes_assessment_id');
        $this->forge->createTable('movement_assessments');
    }

    private function createVehicleOperationalProfiles(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'energy_kind' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'unknown'],
            'ready_energy_target_percent' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('fleet_vehicle_id');
        $this->forge->createTable('vehicle_operational_profiles');
    }

    private function createVehicleOperationalCapabilities(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'capability_code' => ['type' => 'VARCHAR', 'constraint' => 60],
            'is_applicable' => ['type' => 'BOOLEAN', 'default' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['fleet_vehicle_id', 'capability_code'], 'vehicle_operational_capabilities_unique');
        $this->forge->addKey(['capability_code', 'is_applicable']);
        $this->forge->createTable('vehicle_operational_capabilities');
    }

    private function createOperationalFactAudits(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'table_name' => ['type' => 'VARCHAR', 'constraint' => 80],
            'record_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 60],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['table_name', 'record_id', 'created_at']);
        $this->forge->addKey(['company_id', 'created_at']);
        $this->forge->createTable('operational_fact_audits');
    }
}
