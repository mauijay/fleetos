<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVehicleDamageLedger extends Migration
{
    public function up(): void
    {
        $this->createDamageItems();
        $this->createDamageItemEvents();
        $this->createDamageItemEvidence();
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicle_damage_item_evidence', true);
        $this->forge->dropTable('vehicle_damage_item_events', true);
        $this->forge->dropTable('vehicle_damage_items', true);
    }

    private function createDamageItems(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'discovered_turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'discovered_trip_movement_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'vehicle_recovery_exception_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'damage_claim_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'zone_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'damage_type_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'description' => ['type' => 'TEXT'],
            'severity_code' => ['type' => 'VARCHAR', 'constraint' => 20],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'open'],
            'discovered_at' => ['type' => 'DATETIME'],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_at' => ['type' => 'DATETIME'],
            'resolved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'resolution_note' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('vehicle_recovery_exception_id', 'vehicle_damage_recovery_exception_unique');
        $this->forge->addKey(
            ['company_id', 'fleet_vehicle_id', 'status_code', 'discovered_at', 'id'],
            false,
            false,
            'vehicle_damage_current_idx',
        );
        $this->forge->addKey(['company_id', 'discovered_turo_trip_normalized_id'], false, false, 'vehicle_damage_trip_idx');
        $this->forge->addKey(['company_id', 'damage_claim_id'], false, false, 'vehicle_damage_claim_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_company_fk'));
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_vehicle_fk'));
        $this->forge->addForeignKey('discovered_turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_trip_fk'));
        $this->forge->addForeignKey('discovered_trip_movement_event_id', 'trip_movement_events', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_movement_event_fk'));
        $this->forge->addForeignKey('vehicle_recovery_exception_id', 'vehicle_recovery_exceptions', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_recovery_exception_fk'));
        $this->forge->addForeignKey('damage_claim_id', 'damage_claims', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_claim_fk'));
        $this->forge->createTable('vehicle_damage_items');
    }

    private function createDamageItemEvents(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'vehicle_damage_item_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'event_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'source_trip_movement_event_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'occurred_at' => ['type' => 'DATETIME'],
            'prior_status_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'new_status_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'prior_severity_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'new_severity_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'prior_description' => ['type' => 'TEXT', 'null' => true],
            'new_description' => ['type' => 'TEXT', 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(
            ['company_id', 'vehicle_damage_item_id', 'occurred_at', 'id'],
            false,
            false,
            'vehicle_damage_event_history_idx',
        );
        $this->forge->addKey(['company_id', 'source_turo_trip_normalized_id'], false, false, 'vehicle_damage_event_trip_idx');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_event_company_fk'));
        $this->forge->addForeignKey('vehicle_damage_item_id', 'vehicle_damage_items', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_event_item_fk'));
        $this->forge->addForeignKey('source_turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_event_trip_fk'));
        $this->forge->addForeignKey('source_trip_movement_event_id', 'trip_movement_events', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_event_movement_fk'));
        $this->forge->createTable('vehicle_damage_item_events');
    }

    private function createDamageItemEvidence(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'vehicle_damage_item_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'file_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'image_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'external_reference' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'vehicle_damage_item_id', 'id'], false, false, 'vehicle_damage_evidence_item_idx');
        $this->forge->addKey('file_id');
        $this->forge->addKey('image_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_evidence_company_fk'));
        $this->forge->addForeignKey('vehicle_damage_item_id', 'vehicle_damage_items', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_evidence_item_fk'));
        $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_evidence_file_fk'));
        $this->forge->addForeignKey('image_id', 'images', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vehicle_damage_evidence_image_fk'));
        $this->forge->createTable('vehicle_damage_item_evidence');
    }

    private function foreignKeyName(string $name): string
    {
        return $this->db->getPlatform() === 'SQLite3' ? '' : $name;
    }
}
