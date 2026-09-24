<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVehicleHealthObservationFoundation extends Migration
{
    public function up(): void
    {
        $this->createObservations();
        $this->createTirePressureObservations();
        $this->createOdometerObservations();
        $this->createPolicies();
    }

    public function down(): void
    {
        $this->forge->dropTable('vehicle_health_policies', true);
        $this->forge->dropTable('vehicle_odometer_observations', true);
        $this->forge->dropTable('vehicle_tire_pressure_observations', true);
        $this->forge->dropTable('vehicle_health_observations', true);
    }

    private function createObservations(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'observation_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'observed_at' => ['type' => 'DATETIME'],
            'received_at' => ['type' => 'DATETIME'],
            'source' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_external_id' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'source_payload_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'supersedes_observation_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'voided_at' => ['type' => 'DATETIME', 'null' => true],
            'voided_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'void_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('supersedes_observation_id', 'vehicle_health_observation_supersedes_unique');
        $this->forge->addUniqueKey(
            ['company_id', 'source', 'observation_code', 'source_external_id'],
            'vehicle_health_observation_source_unique',
        );
        $this->forge->addKey(
            ['company_id', 'fleet_vehicle_id', 'observation_code', 'observed_at', 'id'],
            false,
            false,
            'vehicle_health_observation_authority_idx',
        );
        $this->forge->addKey(
            ['company_id', 'fleet_vehicle_id', 'observed_at', 'id'],
            false,
            false,
            'vehicle_health_observation_vehicle_idx',
        );
        $this->forge->addKey(['source', 'source_external_id'], false, false, 'vehicle_health_observation_source_idx');
        $this->forge->addKey('voided_at');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_observation_company_fk'));
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_observation_vehicle_fk'));
        $this->forge->addForeignKey('supersedes_observation_id', 'vehicle_health_observations', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_observation_supersedes_fk'));
        $this->forge->createTable('vehicle_health_observations');
    }

    private function createTirePressureObservations(): void
    {
        $this->forge->addField([
            'vehicle_health_observation_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'lf_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'rf_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'lr_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'rr_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'recommended_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
        ]);
        $this->forge->addKey('vehicle_health_observation_id', true);
        $this->forge->addForeignKey('vehicle_health_observation_id', 'vehicle_health_observations', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_pressure_observation_fk'));
        $this->forge->createTable('vehicle_tire_pressure_observations');
    }

    private function createOdometerObservations(): void
    {
        $this->forge->addField([
            'vehicle_health_observation_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'odometer_miles' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey('vehicle_health_observation_id', true);
        $this->forge->addForeignKey('vehicle_health_observation_id', 'vehicle_health_observations', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_odometer_observation_fk'));
        $this->forge->createTable('vehicle_odometer_observations');
    }

    private function createPolicies(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'reminder_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'rule_type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'interval_value' => ['type' => 'SMALLINT', 'unsigned' => true],
            'recommended_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'acceptable_min_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'acceptable_max_psi' => ['type' => 'SMALLINT', 'unsigned' => true],
            'safety_min_psi' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'safety_max_psi' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'is_enabled' => ['type' => 'BOOLEAN', 'default' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'fleet_vehicle_id', 'reminder_code'], 'vehicle_health_policy_vehicle_reminder_unique');
        $this->forge->addKey(['company_id', 'reminder_code', 'is_enabled'], false, false, 'vehicle_health_policy_active_idx');
        $this->forge->addKey('fleet_vehicle_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_policy_company_fk'));
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT', $this->foreignKeyName('vh_policy_vehicle_fk'));
        $this->forge->createTable('vehicle_health_policies');
    }

    private function foreignKeyName(string $name): string
    {
        return $this->db->getPlatform() === 'SQLite3' ? '' : $name;
    }
}
