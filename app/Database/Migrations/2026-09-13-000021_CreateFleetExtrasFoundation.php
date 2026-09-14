<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

class CreateFleetExtrasFoundation extends Migration
{
    public function up(): void
    {
        $this->seedImportType();
        $this->createFleetExtrasTable();
        $this->createSourceMappingsTable();
        $this->createReservationSnapshotsTable();
        $this->createSelectionsTable();
    }

    public function down(): void
    {
        $this->forge->dropTable('turo_extra_selections', true);
        $this->forge->dropTable('turo_extra_reservation_snapshots', true);
        $this->forge->dropTable('fleet_extra_source_mappings', true);
        $this->forge->dropTable('fleet_extras', true);
        $this->removeImportTypeIfUnused();
    }

    private function createFleetExtrasTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 80],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 190],
            'active' => ['type' => 'BOOLEAN', 'default' => true],
            'sort_order' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['id', 'company_id'], 'fleet_extras_id_company');
        $this->forge->addUniqueKey(['company_id', 'code'], 'fleet_extras_company_code');
        $this->forge->addKey(['company_id', 'active', 'sort_order', 'id'], false, false, 'fleet_extras_company_active_sort');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('fleet_extras');
    }

    private function createSourceMappingsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_extra_id' => ['type' => 'INT', 'unsigned' => true],
            'source_system' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'turo'],
            'source_extra_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'latest_source_label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'latest_source_description' => ['type' => 'TEXT', 'null' => true],
            'first_seen_at' => ['type' => 'DATETIME'],
            'last_seen_at' => ['type' => 'DATETIME'],
            'last_change_reason' => ['type' => 'TEXT', 'null' => true],
            'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'source_system', 'source_extra_id'], 'fleet_extra_source_company_system_id');
        $this->forge->addKey(['company_id', 'fleet_extra_id'], false, false, 'fleet_extra_source_company_extra');
        $this->forge->addKey(['company_id', 'source_system', 'last_seen_at'], false, false, 'fleet_extra_source_company_seen');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey(['fleet_extra_id', 'company_id'], 'fleet_extras', ['id', 'company_id'], 'CASCADE', 'RESTRICT');
        $this->forge->createTable('fleet_extra_source_mappings');
    }

    private function createReservationSnapshotsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_import_batch_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'turo_reservation_id' => ['type' => 'VARCHAR', 'constraint' => 80],
            'snapshot_complete' => ['type' => 'BOOLEAN', 'default' => false],
            'reservation_status' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'trip_starts_at' => ['type' => 'DATETIME', 'null' => true],
            'trip_ends_at' => ['type' => 'DATETIME', 'null' => true],
            'observed_at' => ['type' => 'DATETIME'],
            'source_payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'source_payload' => ['type' => 'JSON'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'turo_import_batch_id', 'turo_reservation_id'], 'turo_extra_snapshot_company_batch_reservation');
        $this->forge->addKey(['company_id', 'turo_reservation_id', 'observed_at'], false, false, 'turo_extra_snapshot_company_reservation_seen');
        $this->forge->addKey(['company_id', 'turo_trip_normalized_id'], false, false, 'turo_extra_snapshot_company_trip');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_import_batch_id', 'turo_import_batches', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('turo_extra_reservation_snapshots');
    }

    private function createSelectionsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'first_snapshot_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'last_snapshot_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'turo_reservation_id' => ['type' => 'VARCHAR', 'constraint' => 80],
            'source_extra_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'reservation_state_extra_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'reservation_state_id' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'source_label' => ['type' => 'VARCHAR', 'constraint' => 190],
            'source_description' => ['type' => 'TEXT', 'null' => true],
            'unit_price' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'quantity' => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'gross_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'currency_code' => ['type' => 'CHAR', 'constraint' => 3],
            'pricing_type' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'trip_starts_at' => ['type' => 'DATETIME', 'null' => true],
            'trip_ends_at' => ['type' => 'DATETIME', 'null' => true],
            'reservation_status' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'first_observed_at' => ['type' => 'DATETIME'],
            'last_observed_at' => ['type' => 'DATETIME'],
            'removed_at' => ['type' => 'DATETIME', 'null' => true],
            'source_payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'source_payload' => ['type' => 'JSON'],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'turo_reservation_id', 'reservation_state_extra_id'], 'turo_extra_selection_stable_identity');
        $this->forge->addKey(['company_id', 'turo_reservation_id', 'removed_at'], false, false, 'turo_extra_selection_company_reservation');
        $this->forge->addKey(['company_id', 'source_extra_id', 'removed_at'], false, false, 'turo_extra_selection_company_source');
        $this->forge->addKey(['company_id', 'last_observed_at'], false, false, 'turo_extra_selection_company_seen');
        $this->forge->addKey(['company_id', 'turo_trip_normalized_id'], false, false, 'turo_extra_selection_company_trip');
        $this->forge->addKey('first_snapshot_id', false, false, 'turo_extra_selection_first_snapshot');
        $this->forge->addKey('last_snapshot_id', false, false, 'turo_extra_selection_last_snapshot');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('first_snapshot_id', 'turo_extra_reservation_snapshots', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('last_snapshot_id', 'turo_extra_reservation_snapshots', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('turo_extra_selections');
    }

    private function seedImportType(): void
    {
        $typeId = $this->firstOrCreate('lookup_types', ['code' => 'import_type'], ['name' => 'Import Type']);
        $this->firstOrCreate('lookup_values', ['lookup_type_id' => $typeId, 'code' => 'turo_extras'], [
            'name' => 'Turo Reservation Extras',
            'sort_order' => 30,
            'is_active' => true,
        ]);
    }

    private function removeImportTypeIfUnused(): void
    {
        $connection = $this->forge->getConnection();
        if (! $connection instanceof BaseConnection) {
            return;
        }
        $type = $this->db->table('lookup_types')->where('code', 'import_type')->get()->getRowArray();
        if ($type === null) {
            return;
        }
        $value = $this->db->table('lookup_values')->where(['lookup_type_id' => (int) $type['id'], 'code' => 'turo_extras'])->get()->getRowArray();
        if ($value === null || ($connection->tableExists('turo_import_batches')
            && $this->db->table('turo_import_batches')->where('import_type_lookup_value_id', (int) $value['id'])->countAllResults() > 0)) {
            return;
        }
        $this->db->table('lookup_values')->where('id', (int) $value['id'])->delete();
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $data */
    private function firstOrCreate(string $table, array $where, array $data): int
    {
        $connection = $this->forge->getConnection();
        if (! $connection instanceof BaseConnection) {
            throw new \RuntimeException('Fleet Extras migration requires a database connection.');
        }
        $existing = $connection->table($table)->where($where)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $now = date('Y-m-d H:i:s');
        $connection->table($table)->insert(array_merge($where, $data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $connection->insertID();
    }
}
