<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLookupTypes();
        $this->seedVehicleLookups();
        $this->seedStatesAndCities();
        $this->seedAirports();
    }

    private function seedLookupTypes(): void
    {
        $lookupTypes = [
            'company_type' => ['Company Type', ['fleet_owner' => 'Fleet Owner', 'lender' => 'Lender', 'insurer' => 'Insurer', 'vendor' => 'Vendor']],
            'address_type' => ['Address Type', ['business' => 'Business', 'mailing' => 'Mailing', 'storage' => 'Storage', 'delivery' => 'Delivery']],
            'phone_type' => ['Phone Type', ['main' => 'Main', 'mobile' => 'Mobile', 'billing' => 'Billing']],
            'note_visibility' => ['Note Visibility', ['internal' => 'Internal', 'private' => 'Private']],
            'note_type' => ['Note Type', ['general' => 'General', 'operations' => 'Operations', 'claim' => 'Claim']],
            'image_type' => ['Image Type', ['profile' => 'Profile', 'exterior' => 'Exterior', 'interior' => 'Interior', 'damage' => 'Damage']],
            'file_type' => ['File Type', ['registration' => 'Registration', 'insurance' => 'Insurance', 'receipt' => 'Receipt', 'claim' => 'Claim']],
            'audit_action' => ['Audit Action', ['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'imported' => 'Imported']],
            'loan_status' => ['Loan Status', ['active' => 'Active', 'paid_off' => 'Paid Off', 'refinanced' => 'Refinanced']],
            'policy_status' => ['Policy Status', ['active' => 'Active', 'expired' => 'Expired', 'canceled' => 'Canceled']],
            'coverage_type' => ['Coverage Type', ['commercial_auto' => 'Commercial Auto', 'personal_auto' => 'Personal Auto', 'turo_protection' => 'Turo Protection']],
            'registration_status' => ['Registration Status', ['active' => 'Active', 'expired' => 'Expired', 'pending' => 'Pending']],
            'startup_cost_type' => ['Startup Cost Type', ['down_payment' => 'Down Payment', 'delivery' => 'Delivery', 'accessory' => 'Accessory', 'inspection' => 'Inspection']],
            'maintenance_type' => ['Maintenance Type', ['routine' => 'Routine', 'repair' => 'Repair', 'tires' => 'Tires', 'cleaning' => 'Cleaning']],
            'maintenance_status' => ['Maintenance Status', ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'canceled' => 'Canceled']],
            'claim_status' => ['Claim Status', ['open' => 'Open', 'submitted' => 'Submitted', 'approved' => 'Approved', 'paid' => 'Paid', 'closed' => 'Closed']],
            'claim_source' => ['Claim Source', ['turo' => 'Turo', 'insurance' => 'Insurance', 'direct' => 'Direct']],
            'charging_provider' => ['Charging Provider', ['tesla_supercharger' => 'Tesla Supercharger', 'third_party' => 'Third Party', 'home' => 'Home']],
            'delivery_status' => ['Delivery Status', ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'canceled' => 'Canceled']],
            'import_type' => ['Import Type', ['turo_trips' => 'Turo Trips', 'turo_transactions' => 'Turo Transactions']],
            'import_status' => ['Import Status', ['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed']],
            'import_error_severity' => ['Import Error Severity', ['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error']],
            'trip_status' => ['Trip Status', [
                'booked' => 'Booked',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'canceled_zero_payout' => 'Canceled - Zero Payout',
                'canceled_host_payout' => 'Canceled - Host Payout',
            ]],
        ];

        foreach ($lookupTypes as $code => [$name, $values]) {
            $lookupTypeId = $this->firstOrCreate('lookup_types', ['code' => $code], ['name' => $name]);

            $sortOrder = 10;
            foreach ($values as $valueCode => $valueName) {
                $this->firstOrCreate(
                    'lookup_values',
                    ['lookup_type_id' => $lookupTypeId, 'code' => $valueCode],
                    ['name' => $valueName, 'sort_order' => $sortOrder, 'is_active' => true]
                );
                $sortOrder += 10;
            }
        }
    }

    private function seedVehicleLookups(): void
    {
        $trimLevels = [
            ['code' => 'base', 'name' => 'Base', 'is_premium' => false, 'sort_order' => 10],
            ['code' => 'long_range', 'name' => 'Long Range', 'is_premium' => true, 'sort_order' => 20],
            ['code' => 'premium', 'name' => 'Premium', 'is_premium' => true, 'sort_order' => 30],
        ];

        foreach ($trimLevels as $trimLevel) {
            $this->firstOrCreate('vehicle_trim_levels', ['code' => $trimLevel['code']], $trimLevel);
        }

        $drivetrains = [
            ['code' => 'awd', 'name' => 'All-Wheel Drive', 'motor_count' => 2, 'sort_order' => 10],
            ['code' => 'fwd', 'name' => 'Front-Wheel Drive', 'motor_count' => 1, 'sort_order' => 20],
            ['code' => 'rwd', 'name' => 'Rear-Wheel Drive', 'motor_count' => 1, 'sort_order' => 30],
        ];

        foreach ($drivetrains as $drivetrain) {
            $this->firstOrCreate('vehicle_drivetrains', ['code' => $drivetrain['code']], $drivetrain);
        }

        $statuses = [
            ['code' => 'active', 'name' => 'Active', 'is_available_for_booking' => true, 'sort_order' => 10],
            ['code' => 'pending_onboarding', 'name' => 'Pending Onboarding', 'is_available_for_booking' => false, 'sort_order' => 20],
            ['code' => 'maintenance', 'name' => 'Maintenance', 'is_available_for_booking' => false, 'sort_order' => 30],
            ['code' => 'retired', 'name' => 'Retired', 'is_available_for_booking' => false, 'sort_order' => 40],
        ];

        foreach ($statuses as $status) {
            $this->firstOrCreate('vehicle_statuses', ['code' => $status['code']], $status);
        }
    }

    private function seedStatesAndCities(): void
    {
        $stateId = $this->firstOrCreate('states', ['country_code' => 'US', 'code' => 'HI'], ['name' => 'Hawaii']);

        foreach (['Kahului' => 'Maui', 'Honolulu' => 'Honolulu', 'Kailua-Kona' => 'Hawaii'] as $city => $county) {
            $this->firstOrCreate('cities', ['state_id' => $stateId, 'name' => $city], ['county' => $county]);
        }
    }

    private function seedAirports(): void
    {
        $airports = [
            ['code' => 'OGG', 'name' => 'Kahului Airport', 'city' => 'Kahului'],
            ['code' => 'HNL', 'name' => 'Daniel K. Inouye International Airport', 'city' => 'Honolulu'],
            ['code' => 'KOA', 'name' => 'Ellison Onizuka Kona International Airport', 'city' => 'Kailua-Kona'],
        ];

        foreach ($airports as $airport) {
            $city = $this->db->table('cities')->where('name', $airport['city'])->get()->getRowArray();

            $this->firstOrCreate('airports', ['code' => $airport['code']], [
                'name' => $airport['name'],
                'city_id' => $city === null ? null : (int) $city['id'],
            ]);
        }
    }

    private function firstOrCreate(string $table, array $where, array $data): int
    {
        $builder = $this->db->table($table);
        $existing = $builder->where($where)->get()->getRowArray();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $fields = $this->db->getFieldNames($table);
        $insert = array_merge($where, $data);

        if (in_array('created_at', $fields, true) && ! array_key_exists('created_at', $insert)) {
            $insert['created_at'] = $now;
        }

        if (in_array('updated_at', $fields, true) && ! array_key_exists('updated_at', $insert)) {
            $insert['updated_at'] = $now;
        }

        $builder->insert($insert);

        return (int) $this->db->insertID();
    }
}
