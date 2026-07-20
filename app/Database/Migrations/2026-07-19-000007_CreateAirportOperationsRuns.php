<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAirportOperationsRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('airport_turo_access_receipts', [
            'receipt_classification' => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'unresolved', 'after' => 'attachment_type'],
            'airport_operations_expense_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'airport_turo_access_override_incident_id'],
            'classification_note' => ['type' => 'TEXT', 'null' => true, 'after' => 'note'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_date' => ['type' => 'DATE'],
            'start_time' => ['type' => 'TIME', 'null' => true],
            'end_time' => ['type' => 'TIME', 'null' => true],
            'chase_vehicle_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'personal_vehicle'],
            'chase_fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'chase_vehicle_description' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'operator_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'purpose' => ['type' => 'VARCHAR', 'constraint' => 190],
            'airport_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'starting_location' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'ending_location' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'starting_mileage' => ['type' => 'DECIMAL', 'constraint' => '10,1', 'null' => true],
            'ending_mileage' => ['type' => 'DECIMAL', 'constraint' => '10,1', 'null' => true],
            'business_miles' => ['type' => 'DECIMAL', 'constraint' => '10,1', 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'run_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'open'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['run_date', 'run_status']);
        $this->forge->addKey(['chase_vehicle_type', 'chase_fleet_vehicle_id']);
        $this->forge->createTable('airport_operations_runs');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_operations_run_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'activity_type' => ['type' => 'VARCHAR', 'constraint' => 60],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'airport_movement_workflow_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'movement_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_operations_run_id', 'activity_type']);
        $this->forge->addKey(['fleet_vehicle_id', 'airport_movement_workflow_id']);
        $this->forge->createTable('airport_operations_run_activities');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_operations_run_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'airport_turo_access_receipt_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'expense_category' => ['type' => 'VARCHAR', 'constraint' => 60],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'expense_date' => ['type' => 'DATE'],
            'vendor' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'file_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'business_purpose_note' => ['type' => 'TEXT'],
            'is_reimbursable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'reimbursement_source' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'accounting_status' => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'unreviewed'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_operations_run_id', 'expense_date']);
        $this->forge->addKey(['expense_category', 'accounting_status']);
        $this->forge->createTable('airport_operations_expenses');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_operations_expense_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'allocation_method' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'unallocated'],
            'allocated_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'allocated_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['airport_operations_expense_id', 'fleet_vehicle_id']);
        $this->forge->createTable('airport_operations_expense_allocations');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'airport_turo_access_receipt_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'original_receipt_total' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'reimbursement_portion_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'operations_expense_portion_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'remaining_unclassified_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'note' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('airport_turo_access_receipt_id');
        $this->forge->createTable('airport_receipt_splits');
    }

    public function down(): void
    {
        $this->forge->dropTable('airport_receipt_splits', true);
        $this->forge->dropTable('airport_operations_expense_allocations', true);
        $this->forge->dropTable('airport_operations_expenses', true);
        $this->forge->dropTable('airport_operations_run_activities', true);
        $this->forge->dropTable('airport_operations_runs', true);
        $this->forge->dropColumn('airport_turo_access_receipts', ['receipt_classification', 'airport_operations_expense_id', 'classification_note']);
    }
}
