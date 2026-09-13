<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

class CreateOperatingExpensesAndReceipts extends Migration
{
    private const CATEGORY_TYPE = 'operating_expense_category';

    /** @var array<string, string> */
    private const CATEGORIES = [
        'cleaning_detailing' => 'Cleaning & Detailing',
        'supplies_consumables' => 'Supplies & Consumables',
        'fuel' => 'Fuel',
        'non_airport_parking_tolls' => 'Non-airport Parking & Tolls',
        'towing_roadside' => 'Towing & Roadside',
        'registration_fees' => 'Registration Fees',
        'software_services' => 'Fleet Software & Services',
        'other' => 'Other Operating Expense',
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTIONS = [
        'uploaded' => 'Uploaded',
        'corrected' => 'Corrected',
        'classified' => 'Classified',
        'attached' => 'Attached',
        'non_business' => 'Marked Non-business',
        'duplicate' => 'Marked Duplicate',
        'archived' => 'Archived',
        'restored' => 'Restored',
    ];

    public function up(): void
    {
        $this->seedLookups();
        $this->createOperatingExpensesTable();
        $this->createOperatingExpenseReceiptsTable();
    }

    public function down(): void
    {
        $this->forge->dropTable('operating_expense_receipts', true);
        $this->forge->dropTable('operating_expenses', true);
        $this->removeLookups();
    }

    private function createOperatingExpensesTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'turo_trip_normalized_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'expense_category_lookup_value_id' => ['type' => 'INT', 'unsigned' => true],
            'expense_date' => ['type' => 'DATE'],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'vendor' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'payment_method_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'payment_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'business_purpose' => ['type' => 'TEXT', 'null' => true],
            'source_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'manual'],
            'status_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'recorded'],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'archived_at' => ['type' => 'DATETIME', 'null' => true],
            'archived_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'archive_reason' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'status_code', 'expense_date'], false, false, 'operating_expenses_company_status_date');
        $this->forge->addKey(['company_id', 'fleet_vehicle_id', 'expense_date'], false, false, 'operating_expenses_company_vehicle_date');
        $this->forge->addKey(['company_id', 'turo_trip_normalized_id'], false, false, 'operating_expenses_company_trip');
        $this->forge->addKey('expense_category_lookup_value_id', false, false, 'operating_expenses_category');
        $this->forge->addKey('created_by');
        $this->forge->addKey('updated_by');
        $this->forge->addKey('archived_by');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('turo_trip_normalized_id', 'turo_trips_normalized', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('expense_category_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('operating_expenses');
    }

    private function createOperatingExpenseReceiptsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'operating_expense_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'file_id' => ['type' => 'INT', 'unsigned' => true],
            'classification_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'needs_classification'],
            'document_date' => ['type' => 'DATE', 'null' => true],
            'observed_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'vendor' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'note' => ['type' => 'TEXT', 'null' => true],
            'duplicate_of_receipt_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'classified_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'classified_at' => ['type' => 'DATETIME', 'null' => true],
            'archived_at' => ['type' => 'DATETIME', 'null' => true],
            'archived_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'archive_reason' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'file_id'], 'operating_expense_receipts_company_file');
        $this->forge->addKey(['company_id', 'classification_code', 'created_at'], false, false, 'operating_expense_receipts_company_class_created');
        $this->forge->addKey(['company_id', 'document_date'], false, false, 'operating_expense_receipts_company_document_date');
        $this->forge->addKey(['company_id', 'file_id'], false, false, 'operating_expense_receipts_company_file_lookup');
        $this->forge->addKey('operating_expense_id', false, false, 'operating_expense_receipts_expense');
        $this->forge->addKey('duplicate_of_receipt_id', false, false, 'operating_expense_receipts_duplicate');
        $this->forge->addKey('created_by');
        $this->forge->addKey('classified_by');
        $this->forge->addKey('archived_by');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('operating_expense_id', 'operating_expenses', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('duplicate_of_receipt_id', 'operating_expense_receipts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('operating_expense_receipts');
    }

    private function seedLookups(): void
    {
        $categoryTypeId = $this->lookupTypeId(self::CATEGORY_TYPE, 'Operating Expense Category');
        $sortOrder = 10;
        foreach (self::CATEGORIES as $code => $name) {
            $this->firstOrCreate('lookup_values', ['lookup_type_id' => $categoryTypeId, 'code' => $code], [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
            $sortOrder += 10;
        }

        $auditTypeId = $this->lookupTypeId('audit_action', 'Audit Action');
        foreach (self::AUDIT_ACTIONS as $code => $name) {
            $this->firstOrCreate('lookup_values', ['lookup_type_id' => $auditTypeId, 'code' => $code], [
                'name' => $name,
                'sort_order' => 100,
                'is_active' => true,
            ]);
        }
    }

    private function removeLookups(): void
    {
        $connection = $this->forge->getConnection();
        if (! $connection instanceof BaseConnection) {
            throw new \RuntimeException('Operating expense rollback requires a database connection.');
        }
        $categoryType = $this->db->table('lookup_types')->where('code', self::CATEGORY_TYPE)->get()->getRowArray();
        if ($categoryType !== null) {
            $this->db->table('lookup_values')->where('lookup_type_id', (int) $categoryType['id'])->whereIn('code', array_keys(self::CATEGORIES))->delete();
            if ($this->db->table('lookup_values')->where('lookup_type_id', (int) $categoryType['id'])->countAllResults() === 0) {
                $this->db->table('lookup_types')->where('id', (int) $categoryType['id'])->delete();
            }
        }

        $auditType = $this->db->table('lookup_types')->where('code', 'audit_action')->get()->getRowArray();
        if ($auditType !== null) {
            foreach (array_keys(self::AUDIT_ACTIONS) as $code) {
                $value = $this->db->table('lookup_values')->where(['lookup_type_id' => (int) $auditType['id'], 'code' => $code])->get()->getRowArray();
                if ($value === null) {
                    continue;
                }
                if ($connection->tableExists('audit_logs')
                    && $this->db->table('audit_logs')->where('action_lookup_value_id', (int) $value['id'])->countAllResults() > 0) {
                    continue;
                }
                $this->db->table('lookup_values')->where('id', (int) $value['id'])->delete();
            }
        }
    }

    private function lookupTypeId(string $code, string $name): int
    {
        return $this->firstOrCreate('lookup_types', ['code' => $code], ['name' => $name]);
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $data */
    private function firstOrCreate(string $table, array $where, array $data): int
    {
        $existing = $this->db->table($table)->where($where)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $insert = array_merge($where, $data);
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $this->db->table($table)->insert($insert);

        $created = $this->db->table($table)->where($where)->get()->getRowArray();
        if ($created === null) {
            throw new \RuntimeException("Failed to create {$table} lookup row.");
        }

        return (int) $created['id'];
    }
}
