<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ConvergeVehicleCatalog extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->converge('vehicle_body_styles', 'truck', ['name' => 'Truck', 'updated_at' => $now], $now);
        $this->converge('vehicle_colors', 'tan', ['name' => 'Tan', 'hex_color' => '#D2B48C', 'updated_at' => $now], $now);
    }

    public function down(): void
    {
        // Retain catalog rows because vehicle specifications may reference them.
    }

    /** @param array<string, mixed> $data */
    private function converge(string $table, string $code, array $data, string $now): void
    {
        $existing = $this->db->table($table)->where('code', $code)->get()->getRowArray();
        if ($existing === null) {
            $this->db->table($table)->insert(array_merge(['code' => $code, 'created_at' => $now], $data));

            return;
        }

        $this->db->table($table)->where('id', (int) $existing['id'])->update($data);
    }
}
