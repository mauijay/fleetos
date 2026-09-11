<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTripIncidentalReviews extends Migration
{
    public function up(): void
    {
        $this->createPolicies();
        $this->createReviews();
    }

    public function down(): void
    {
        $this->forge->dropTable('trip_incidental_reviews', true);
        $this->forge->dropTable('incidental_review_policies', true);
    }

    private function createPolicies(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'platform' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'turo'],
            'jurisdiction_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'US'],
            'earnings_plan_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'rule_version' => ['type' => 'VARCHAR', 'constraint' => 40],
            'effective_from_at_utc' => ['type' => 'DATETIME'],
            'effective_until_at_utc' => ['type' => 'DATETIME', 'null' => true],
            'filing_window_minutes' => ['type' => 'INT', 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'source_reference' => ['type' => 'TEXT'],
            'source_note' => ['type' => 'TEXT'],
            'approved_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at_utc' => ['type' => 'DATETIME', 'null' => true],
            'approval_rationale' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'earnings_plan_code', 'rule_version'], 'incidental_policy_plan_version_unique');
        $this->forge->addKey(['company_id', 'earnings_plan_code', 'status', 'effective_from_at_utc'], false, false, 'incidental_policy_lookup_index');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('incidental_review_policies');
    }

    private function createReviews(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'waiting_for_review'],
            'trip_ended_at_utc' => ['type' => 'DATETIME'],
            'review_after_at_utc' => ['type' => 'DATETIME'],
            'review_delay_minutes_snapshot' => ['type' => 'INT', 'unsigned' => true],
            'earnings_plan_code_snapshot' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'filing_deadline_at_utc' => ['type' => 'DATETIME', 'null' => true],
            'incidental_review_policy_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'policy_rule_version_snapshot' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'filing_window_minutes_snapshot' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'policy_source_reference_snapshot' => ['type' => 'TEXT', 'null' => true],
            'plan_source_reference' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'plan_selection_reason' => ['type' => 'TEXT', 'null' => true],
            'plan_confirmed_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'plan_confirmed_at_utc' => ['type' => 'DATETIME', 'null' => true],
            'completion_reference' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'completion_note' => ['type' => 'TEXT', 'null' => true],
            'completed_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completed_at_utc' => ['type' => 'DATETIME', 'null' => true],
            'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'creation_source' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'trip_projection'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'turo_trip_normalized_id'], 'trip_incidental_reviews_company_trip_unique');
        $this->forge->addKey(['company_id', 'status', 'review_after_at_utc', 'filing_deadline_at_utc'], false, false, 'trip_incidental_reviews_queue_index');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('incidental_review_policy_id', 'incidental_review_policies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('trip_incidental_reviews');
    }
}
