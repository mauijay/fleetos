<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class TripIncidentalReviewPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (! $this->db->tableExists('incidental_review_policies')) {
            return;
        }
        $companies = $this->db->table('companies')->select('id')->where('deleted_at', null)->get()->getResultArray();
        $plans = [
            ['more_peace_of_mind', 'More peace of mind', 7200],
            ['balanced', 'Balanced', 5760],
            ['more_earnings', 'More earnings', 4320],
        ];
        foreach ($companies as $company) {
            foreach ($plans as [$code, $label, $minutes]) {
                $exists = $this->db->table('incidental_review_policies')->where([
                    'company_id' => $company['id'], 'earnings_plan_code' => $code, 'rule_version' => '2026-09-host-terms-v1',
                ])->countAllResults() > 0;
                if ($exists) {
                    continue;
                }
                $now = date('Y-m-d H:i:s');
                $this->db->table('incidental_review_policies')->insert([
                    'company_id' => $company['id'], 'platform' => 'turo', 'jurisdiction_code' => 'US',
                    'earnings_plan_code' => $code, 'display_name' => $label, 'rule_version' => '2026-09-host-terms-v1',
                    'effective_from_at_utc' => '2026-01-01 00:00:00', 'filing_window_minutes' => $minutes,
                    'status' => 'draft',
                    'source_reference' => 'Turo US host earnings-plan incidental filing terms',
                    'source_note' => 'Operator must verify this host-facing plan window before activation; review timing is a separate FleetOS workflow setting.',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
