<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateIncidentalEarningsPlanAssignments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'earnings_plan_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'effective_from_at_utc' => ['type' => 'DATETIME'],
            'assignment_reason' => ['type' => 'TEXT'],
            'created_by_user_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'fleet_vehicle_id', 'effective_from_at_utc'], false, false, 'incidental_plan_assignment_resolution_index');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('incidental_earnings_plan_assignments');
    }

    public function down(): void
    {
        $this->forge->dropTable('incidental_earnings_plan_assignments', true);
    }
}
