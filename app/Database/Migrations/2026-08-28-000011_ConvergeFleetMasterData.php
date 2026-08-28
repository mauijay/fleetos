<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ConvergeFleetMasterData extends Migration
{
    private const COMPANY_SLUG = 'go808-fleetos';
    private const COMPANY_NAME = '808biz, Inc.';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('companies')
            ->where('slug', self::COMPANY_SLUG)
            ->update([
                'name' => self::COMPANY_NAME,
                'legal_name' => self::COMPANY_NAME,
                'updated_at' => $now,
            ]);

        $drivetrain = $this->db->table('vehicle_drivetrains')->where('code', '4wd')->get()->getRowArray();
        if ($drivetrain === null) {
            $this->db->table('vehicle_drivetrains')->insert([
                'code' => '4wd',
                'name' => 'Four-Wheel Drive',
                'motor_count' => null,
                'sort_order' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->db->table('vehicle_drivetrains')->where('id', (int) $drivetrain['id'])->update([
            'name' => 'Four-Wheel Drive',
            'motor_count' => null,
            'sort_order' => 15,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('companies')
            ->where('slug', self::COMPANY_SLUG)
            ->where('name', self::COMPANY_NAME)
            ->where('legal_name', self::COMPANY_NAME)
            ->update([
                'name' => 'GO808 FleetOS',
                'legal_name' => 'GO808 FleetOS',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
