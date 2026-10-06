<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVehicleDamageRepairWork extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $integer = ['type' => 'INT', 'unsigned' => true];
        $date = ['type' => 'DATETIME'];
        $text = ['type' => 'TEXT', 'null' => true];
        $actors = ['created_by' => $integer, 'updated_by' => $integer, 'created_at' => $date, 'updated_at' => $date];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer, 'fleet_vehicle_id' => $integer,
            'intent_code' => ['type' => 'VARCHAR', 'constraint' => 20],
            'category_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'summary' => ['type' => 'VARCHAR', 'constraint' => 190],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'planned'],
            'vendor_company_id' => $integer + ['null' => true],
            'vendor_snapshot' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'vendor_order_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'scheduled_at' => $date + ['null' => true], 'started_at' => $date + ['null' => true],
            'completed_at' => $date + ['null' => true], 'completion_note' => $text,
            'version' => $integer + ['default' => 1],
            'creation_command_key' => ['type' => 'CHAR', 'constraint' => 36],
            'creation_command_payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
        ] + $actors);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'id'], 'repair_jobs_company_id_uq');
        $this->forge->addUniqueKey(['company_id', 'creation_command_key'], 'repair_jobs_creation_command_uq');
        $this->forge->addKey(['company_id', 'fleet_vehicle_id', 'status_code', 'id'], false, false, 'repair_jobs_vehicle_status_idx');
        $this->forge->addKey('vendor_company_id', false, false, 'repair_jobs_vendor_idx');
        $this->foreign('company_id', 'companies', 'id', 'repair_jobs_company_fk');
        $this->foreign('fleet_vehicle_id', 'fleet_vehicles', 'id', 'repair_jobs_vehicle_fk');
        $this->foreign('vendor_company_id', 'companies', 'id', 'repair_jobs_vendor_fk');
        $this->forge->createTable('vehicle_damage_repair_jobs');

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer,
            'vehicle_damage_repair_job_id' => $id, 'vehicle_damage_item_id' => $id,
            'result_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'unassessed'],
            'note' => $text, 'occurred_at' => $date + ['null' => true],
            'withdrawn_at' => $date + ['null' => true], 'withdrawn_by' => $integer + ['null' => true], 'withdrawal_reason' => $text,
        ] + $actors);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['vehicle_damage_repair_job_id', 'vehicle_damage_item_id'], 'repair_items_job_condition_uq');
        $this->forge->addUniqueKey(['company_id', 'id'], 'repair_items_company_id_uq');
        $this->forge->addKey(['company_id', 'vehicle_damage_item_id', 'vehicle_damage_repair_job_id'], false, false, 'repair_items_condition_idx');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', 'withdrawn_at', 'id'], false, false, 'repair_items_job_active_idx');
        $this->foreign('company_id', 'companies', 'id', 'repair_items_company_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id'], 'repair_items_job_fk');
        $this->foreign('vehicle_damage_item_id', 'vehicle_damage_items', 'id', 'repair_items_condition_fk');
        $this->forge->createTable('vehicle_damage_repair_job_items');

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'company_id' => $integer, 'vehicle_damage_repair_job_id' => $id,
            'vehicle_damage_repair_job_item_id' => $id + ['null' => true], 'vehicle_damage_item_id' => $id + ['null' => true],
            'event_code' => ['type' => 'VARCHAR', 'constraint' => 40], 'job_version' => $integer, 'actor_user_id' => $integer,
            'recorded_at' => $date, 'occurred_at' => $date + ['null' => true],
            'reason_category_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true], 'reason' => $text,
            'before_json' => ['type' => 'JSON', 'null' => true], 'after_json' => ['type' => 'JSON'],
            'command_key' => ['type' => 'CHAR', 'constraint' => 36], 'command_payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['vehicle_damage_repair_job_id', 'job_version'], 'repair_events_job_version_uq');
        $this->forge->addUniqueKey(['company_id', 'command_key'], 'repair_events_command_uq');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_id', 'id'], false, false, 'repair_events_history_idx');
        $this->forge->addKey(['company_id', 'vehicle_damage_item_id', 'id'], false, false, 'repair_events_condition_idx');
        $this->forge->addKey(['company_id', 'vehicle_damage_repair_job_item_id', 'id'], false, false, 'repair_events_member_idx');
        $this->foreign('company_id', 'companies', 'id', 'repair_events_company_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id'], 'repair_events_job_fk');
        $this->foreign(['company_id', 'vehicle_damage_repair_job_item_id'], 'vehicle_damage_repair_job_items', ['company_id', 'id'], 'repair_events_member_fk');
        $this->foreign('vehicle_damage_item_id', 'vehicle_damage_items', 'id', 'repair_events_condition_fk');
        $this->forge->createTable('vehicle_damage_repair_job_events');

        if ($this->db->getPlatform() === 'SQLite3') {
            if (! $this->db instanceof \CodeIgniter\Database\BaseConnection) {
                throw new \RuntimeException('B2.1 requires the application database connection.');
            }
            $events = $this->db->escapeIdentifiers($this->db->prefixTable('vehicle_damage_item_events'));
            $jobs = $this->db->escapeIdentifiers($this->db->prefixTable('vehicle_damage_repair_job_events'));
            $this->db->query('ALTER TABLE ' . $events . ' ADD COLUMN repair_job_event_id INTEGER NULL REFERENCES ' . $jobs . '(id) ON UPDATE CASCADE ON DELETE RESTRICT');
        } else {
            $this->forge->addColumn('vehicle_damage_item_events', ['repair_job_event_id' => $id + ['null' => true]]);
            $this->foreign('repair_job_event_id', 'vehicle_damage_repair_job_events', 'id', 'damage_event_repair_job_event_fk');
        }
        $this->forge->addKey('repair_job_event_id', false, false, 'damage_event_repair_job_event_idx');
        $this->forge->processIndexes('vehicle_damage_item_events');
    }

    public function down(): void
    {
        throw new \RuntimeException('B2.1 contains append-only work history. Restore an approved backup instead of destructive rollback.');
    }

    private function foreign(string|array $column, string $table, string|array $target, string $name): void
    {
        $this->forge->addForeignKey($column, $table, $target, 'CASCADE', 'RESTRICT', $this->db->getPlatform() === 'SQLite3' ? '' : $name);
    }
}
