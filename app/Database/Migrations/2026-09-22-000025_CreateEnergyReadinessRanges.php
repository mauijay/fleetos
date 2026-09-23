<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEnergyReadinessRanges extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('vehicle_operational_profiles', [
            'ready_energy_min_percent' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'ready_energy_target_percent',
            ],
            'ready_energy_preferred_max_percent' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'ready_energy_min_percent',
            ],
        ]);
        $this->forge->addColumn('fleet_trip_commitments', [
            'energy_min_percent' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'energy_percent',
            ],
            'energy_max_percent' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'energy_min_percent',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('fleet_trip_commitments', [
            'energy_min_percent',
            'energy_max_percent',
        ]);
        $this->forge->dropColumn('vehicle_operational_profiles', [
            'ready_energy_min_percent',
            'ready_energy_preferred_max_percent',
        ]);
    }
}
