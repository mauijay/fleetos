<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVehicleRecoveryExceptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'trip_movement_event_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'exception_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'note' => ['type' => 'TEXT', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'open'],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'resolved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'resolution_note' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['trip_movement_event_id', 'exception_code'], 'recovery_exceptions_event_code_unique');
        $this->forge->addKey(['company_id', 'status', 'fleet_vehicle_id'], false, false, 'recovery_exceptions_company_status_vehicle');
        $this->forge->addKey(['company_id', 'turo_trip_normalized_id'], false, false, 'recovery_exceptions_company_trip');
        $this->forge->addKey(['company_id', 'trip_movement_event_id'], false, false, 'recovery_exceptions_company_event');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('trip_movement_event_id', 'trip_movement_events', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('vehicle_recovery_exceptions');
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicle_recovery_exceptions', true);
    }
}
