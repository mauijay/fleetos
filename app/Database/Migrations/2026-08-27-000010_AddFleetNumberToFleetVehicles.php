<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use LogicException;

class AddFleetNumberToFleetVehicles extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('fleet_vehicles', [
            'fleet_number' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'vehicle_status_id',
            ],
        ]);

        if (! $this->db instanceof BaseConnection) {
            throw new LogicException('Fleet number migration requires a CodeIgniter database connection.');
        }

        $table = $this->db->prefixTable('fleet_vehicles');
        $this->db->query("CREATE UNIQUE INDEX fleet_vehicles_company_fleet_number_unique ON {$table} (company_id, fleet_number)");
    }

    public function down(): void
    {
        $this->forge->dropKey('fleet_vehicles', 'fleet_vehicles_company_fleet_number_unique', false);
        $this->forge->dropColumn('fleet_vehicles', 'fleet_number');
    }
}
