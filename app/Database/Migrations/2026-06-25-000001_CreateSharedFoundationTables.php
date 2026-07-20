<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSharedFoundationTables extends Migration
{
    public function up(): void
    {
        if ($this->db->getPlatform() === 'MySQLi') {
            $this->db->simpleQuery('SET default_storage_engine=InnoDB');
        }

        $this->createLookupTables();
        $this->createLocationTables();
        $this->createSharedAssetTables();
        $this->createAuditLogsTable();
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_logs', true);
        $this->forge->dropTable('notes', true);
        $this->forge->dropTable('files', true);
        $this->forge->dropTable('images', true);
        $this->forge->dropTable('phone_numbers', true);
        $this->forge->dropTable('addresses', true);
        $this->forge->dropTable('cities', true);
        $this->forge->dropTable('states', true);
        $this->forge->dropTable('lookup_values', true);
        $this->forge->dropTable('lookup_types', true);
    }

    private function createLookupTables(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 80],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'description' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('lookup_types');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'lookup_type_id' => ['type' => 'INT', 'unsigned' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 80],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'description' => ['type' => 'TEXT', 'null' => true],
            'sort_order' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'is_active' => ['type' => 'BOOLEAN', 'default' => true],
            'metadata' => ['type' => 'JSON', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lookup_type_id');
        $this->forge->addUniqueKey(['lookup_type_id', 'code']);
        $this->forge->addForeignKey('lookup_type_id', 'lookup_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lookup_values');
    }

    private function createLocationTables(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'country_code' => ['type' => 'CHAR', 'constraint' => 2, 'default' => 'US'],
            'code' => ['type' => 'VARCHAR', 'constraint' => 10],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['country_code', 'code']);
        $this->forge->createTable('states');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'state_id' => ['type' => 'INT', 'unsigned' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'county' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('state_id');
        $this->forge->addUniqueKey(['state_id', 'name', 'county']);
        $this->forge->addForeignKey('state_id', 'states', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('cities');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'address_line1' => ['type' => 'VARCHAR', 'constraint' => 190],
            'address_line2' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'city_id' => ['type' => 'INT', 'unsigned' => true],
            'postal_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('city_id');
        $this->forge->addForeignKey('city_id', 'cities', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('addresses');
    }

    private function createSharedAssetTables(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'country_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => '+1'],
            'phone_number' => ['type' => 'VARCHAR', 'constraint' => 40],
            'extension' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => ''],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['country_code', 'phone_number', 'extension'], 'phone_numbers_unique');
        $this->forge->createTable('phone_numbers');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'storage_disk' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'local'],
            'path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'original_filename' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'size_bytes' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'width' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'height' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'alt_text' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'checksum' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'uploaded_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('checksum');
        $this->forge->addKey('uploaded_by');
        $this->forge->createTable('images');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'storage_disk' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'local'],
            'path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'original_filename' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'size_bytes' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'document_date' => ['type' => 'DATE', 'null' => true],
            'checksum' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'uploaded_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('checksum');
        $this->forge->addKey('uploaded_by');
        $this->forge->createTable('files');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'body' => ['type' => 'TEXT'],
            'visibility_lookup_value_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'noted_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('visibility_lookup_value_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('visibility_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('notes');
    }

    private function createAuditLogsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'action_lookup_value_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'table_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'record_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'old_values' => ['type' => 'JSON', 'null' => true],
            'new_values' => ['type' => 'JSON', 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['table_name', 'record_id']);
        $this->forge->addKey('actor_user_id');
        $this->forge->addKey('action_lookup_value_id');
        $this->forge->addForeignKey('action_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('audit_logs');
    }
}
