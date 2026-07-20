<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTuroVehicleMappingAudit extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('vehicle_turo_listings', [
            'source_system' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'turo', 'after' => 'turo_vehicle_id'],
            'mapping_note' => ['type' => 'TEXT', 'null' => true, 'after' => 'unlisted_at'],
            'mapped_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'mapping_note'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'vehicle_turo_listing_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 40],
            'turo_vehicle_id' => ['type' => 'VARCHAR', 'constraint' => 80],
            'old_fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'new_fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['turo_vehicle_id', 'created_at']);
        $this->forge->addKey('vehicle_turo_listing_id');
        $this->forge->createTable('vehicle_turo_listing_audits');
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicle_turo_listing_audits', true);
        $this->forge->dropColumn('vehicle_turo_listings', ['source_system', 'mapping_note', 'mapped_by']);
    }
}
