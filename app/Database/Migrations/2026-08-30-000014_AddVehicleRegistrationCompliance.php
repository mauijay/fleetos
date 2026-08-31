<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVehicleRegistrationCompliance extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('fleet_vehicles', [
            'registered_owner' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'license_plate'],
            'registration_renewal_on' => ['type' => 'DATE', 'null' => true, 'after' => 'registered_owner'],
            'safety_inspection_due_on' => ['type' => 'DATE', 'null' => true, 'after' => 'registration_renewal_on'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('fleet_vehicles', [
            'registered_owner',
            'registration_renewal_on',
            'safety_inspection_due_on',
        ]);
    }
}
