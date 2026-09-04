<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMovementPlanningControls extends Migration
{
    public function up(): void
    {
        $this->addStructuredAirportParkingToMovementEvents();
        $this->addChecklistProjectionControls();
        $this->createMovementLocationAliases();
        $this->createVehiclePositioningPlans();
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicle_positioning_plans', true);
        $this->forge->dropTable('movement_location_aliases', true);
        try {
            $this->forge->dropKey('trip_movement_checklists', 'trip_movement_checklists_scheduled_at');
        } catch (\Throwable) {
            // Supports rollback when an earlier local draft of 000016 was applied.
        }
        foreach (['projected_at', 'projection_source'] as $column) {
            try {
                $this->forge->dropColumn('trip_movement_checklists', $column);
            } catch (\Throwable) {
                // Supports rollback when an earlier local draft of 000016 was applied.
            }
        }
        foreach (['airport_parking_row', 'airport_parking_level', 'airport_garage_code'] as $column) {
            try {
                $this->forge->dropColumn('trip_movement_events', $column);
            } catch (\Throwable) {
                // Supports rollback when an earlier local draft of 000016 was applied.
            }
        }
    }

    private function addChecklistProjectionControls(): void
    {
        $this->forge->addColumn('trip_movement_checklists', [
            'projection_source' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'completion_note'],
            'projected_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'projection_source'],
        ]);
        $this->forge->addKey('scheduled_at', false, false, 'trip_movement_checklists_scheduled_at');
        $this->forge->processIndexes('trip_movement_checklists');
    }

    private function addStructuredAirportParkingToMovementEvents(): void
    {
        $this->forge->addColumn('trip_movement_events', [
            'airport_garage_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'location_detail'],
            'airport_parking_level' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'after' => 'airport_garage_code'],
            'airport_parking_row' => ['type' => 'VARCHAR', 'constraint' => 4, 'null' => true, 'after' => 'airport_parking_level'],
        ]);
    }

    private function createMovementLocationAliases(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'source_text' => ['type' => 'VARCHAR', 'constraint' => 500],
            'normalized_source_key' => ['type' => 'VARCHAR', 'constraint' => 500],
            'location_class' => ['type' => 'VARCHAR', 'constraint' => 40],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'normalized_source_key'], 'movement_location_aliases_company_source_unique');
        $this->forge->addKey(['company_id', 'location_class']);
        $this->forge->createTable('movement_location_aliases');
    }

    private function createVehiclePositioningPlans(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'positioning_code' => ['type' => 'VARCHAR', 'constraint' => 60],
            'target_location_class' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'reason_code' => ['type' => 'VARCHAR', 'constraint' => 60],
            'note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'transportation_state' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'unknown'],
            'basis_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'basis_next_trip_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'basis_next_trip_starts_at' => ['type' => 'DATETIME', 'null' => true],
            'basis_next_trip_fingerprint' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
            'invalidated_at' => ['type' => 'DATETIME', 'null' => true],
            'invalidation_reason' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'invalidated_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['fleet_vehicle_id', 'created_at']);
        $this->forge->addKey(['company_id', 'invalidated_at']);
        $this->forge->addKey('basis_event_id');
        $this->forge->addKey('basis_next_trip_id');
        $this->forge->createTable('vehicle_positioning_plans');
    }
}
