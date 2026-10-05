<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVehicleDamageIncidentsAndConditionLinks extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $integer = ['type' => 'INT', 'unsigned' => true];
        $date = ['type' => 'DATETIME'];
        // SQLite's table rebuild renames references in existing event/evidence tables.
        // Native additive columns avoid that rewrite and preserve every existing FK.
        if ($this->db->getPlatform() === 'SQLite3') {
            $connection = $this->forge->getConnection();
            if (! $connection instanceof \CodeIgniter\Database\BaseConnection) {
                throw new \RuntimeException('B1 requires a supported database connection.');
            }
            $table = $connection->escapeIdentifiers($connection->prefixTable('vehicle_damage_items'));
            $this->db->query('ALTER TABLE ' . $table . ' ADD COLUMN panel_code VARCHAR(40) NULL');
            $this->db->query('ALTER TABLE ' . $table . ' ADD COLUMN current_condition_item_id INTEGER NULL REFERENCES ' . $table . '(id) ON UPDATE CASCADE ON DELETE RESTRICT');
        } else {
            $this->forge->addColumn('vehicle_damage_items', [
                'panel_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'current_condition_item_id' => $id + ['null' => true],
            ]);
            $this->forge->addForeignKey('current_condition_item_id', 'vehicle_damage_items', 'id', 'CASCADE', 'RESTRICT', $this->fk('damage_current_condition_fk'));
        }
        $this->forge->addKey(['company_id', 'fleet_vehicle_id', 'current_condition_item_id'], false, false, 'damage_canonical_idx');
        $this->forge->processIndexes('vehicle_damage_items');
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer, 'fleet_vehicle_id' => $integer,
            'turo_trip_normalized_id' => $id + ['null' => true],
            'trip_movement_event_id' => $id + ['null' => true],
            'vehicle_recovery_exception_id' => $id + ['null' => true],
            'occurred_at' => $date + ['null' => true], 'discovered_at' => $date,
            'attribution_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'overall_note' => ['type' => 'TEXT', 'null' => true],
            'created_by' => $integer, 'created_at' => $date, 'updated_by' => $integer, 'updated_at' => $date,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'fleet_vehicle_id', 'discovered_at']);
        foreach (['company_id' => 'companies', 'fleet_vehicle_id' => 'fleet_vehicles', 'turo_trip_normalized_id' => 'turo_trips_normalized', 'trip_movement_event_id' => 'trip_movement_events', 'vehicle_recovery_exception_id' => 'vehicle_recovery_exceptions'] as $column => $table) {
            $this->forge->addForeignKey($column, $table, 'id', 'CASCADE', 'RESTRICT', $this->fk('damage_incident_' . $column . '_fk'));
        }
        $this->forge->createTable('vehicle_damage_incidents');
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer,
            'vehicle_damage_incident_id' => $id, 'vehicle_damage_item_id' => $id,
            'effect_code' => ['type' => 'VARCHAR', 'constraint' => 30],
            'panel_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'damage_type_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'severity_code' => ['type' => 'VARCHAR', 'constraint' => 20],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_by' => $integer, 'created_at' => $date,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['vehicle_damage_incident_id', 'vehicle_damage_item_id']);
        $this->forge->addKey(['company_id', 'vehicle_damage_item_id']);
        foreach (['company_id' => 'companies', 'vehicle_damage_incident_id' => 'vehicle_damage_incidents', 'vehicle_damage_item_id' => 'vehicle_damage_items'] as $column => $table) {
            $this->forge->addForeignKey($column, $table, 'id', 'CASCADE', 'RESTRICT', $this->fk('damage_membership_' . $column . '_fk'));
        }
        $this->forge->createTable('vehicle_damage_incident_items');
    }

    public function down(): void
    {
        throw new \RuntimeException('B1 contains append-only incident history. Restore an approved backup instead of destructive rollback.');
    }

    private function fk(string $name): string
    {
        return $this->db->getPlatform() === 'SQLite3' ? '' : $name;
    }
}
