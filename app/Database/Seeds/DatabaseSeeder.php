<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LookupSeeder::class);
        $this->call(FleetVehicleSeeder::class);
        $this->call(AdminUserSeeder::class);
    }
}
