<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOperationalTripForeignKeys extends Migration
{
    public function up(): void
    {
        if ($this->db instanceof \CodeIgniter\Database\SQLite3\Connection) {
            foreach (['damage_claims', 'charging_sessions', 'airport_deliveries'] as $table) {
                (new \App\Database\SQLiteMigrationTable($this->db, new \CodeIgniter\Database\SQLite3\Forge($this->db)))
                    ->fromTable($this->db->prefixTable($table))
                    ->addForeignKey([['field' => ['turo_trip_normalized_id'], 'referenceTable' => $this->db->prefixTable('turo_trips_normalized'), 'referenceField' => ['id'], 'onUpdate' => 'CASCADE', 'onDelete' => 'SET NULL']])
                    ->run();
            }

            return;
        }
        $this->forge->addForeignKey(
            'turo_trip_normalized_id',
            'turo_trips_normalized',
            'id',
            'CASCADE',
            'SET NULL',
            'damage_claims_turo_trip_normalized_id_foreign'
        );
        $this->forge->processIndexes('damage_claims');

        $this->forge->addForeignKey(
            'turo_trip_normalized_id',
            'turo_trips_normalized',
            'id',
            'CASCADE',
            'SET NULL',
            'charging_sessions_turo_trip_normalized_id_foreign'
        );
        $this->forge->processIndexes('charging_sessions');

        $this->forge->addForeignKey(
            'turo_trip_normalized_id',
            'turo_trips_normalized',
            'id',
            'CASCADE',
            'SET NULL',
            'airport_deliveries_turo_trip_normalized_id_foreign'
        );
        $this->forge->processIndexes('airport_deliveries');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('airport_deliveries', 'airport_deliveries_turo_trip_normalized_id_foreign');
        $this->forge->dropForeignKey('charging_sessions', 'charging_sessions_turo_trip_normalized_id_foreign');
        $this->forge->dropForeignKey('damage_claims', 'damage_claims_turo_trip_normalized_id_foreign');
    }
}
