<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTuroTransactionsNormalizedTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'turo_transaction_raw_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'external_transaction_id' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'external_trip_id' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'transaction_type' => ['type' => 'VARCHAR', 'constraint' => 120],
            'normalized_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'currency_code' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'USD'],
            'transaction_date' => ['type' => 'DATE', 'null' => true],
            'row_fingerprint' => ['type' => 'VARCHAR', 'constraint' => 128],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('external_transaction_id');
        $this->forge->addUniqueKey('row_fingerprint');
        $this->forge->addKey(['normalized_type', 'transaction_date']);
        $this->forge->addKey(['fleet_vehicle_id', 'transaction_date']);
        $this->forge->addForeignKey('turo_transaction_raw_id', 'turo_transaction_raw', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('turo_transactions_normalized');
    }

    public function down(): void
    {
        $this->forge->dropTable('turo_transactions_normalized', true);
    }
}
