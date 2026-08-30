<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

class AddVehicleCapitalManagement extends Migration
{
    public function up(): void
    {
        $this->seedLookups();
        $this->extendLoansTable();
        $this->createVehicleAcquisitionsTable();
        $this->createLoanBalanceSnapshotsTable();
    }

    public function down(): void
    {
        $this->forge->dropTable('loan_balance_snapshots', true);
        $this->forge->dropTable('vehicle_acquisitions', true);
        $this->dropLoanIndex('loans_lender_status');
        $this->dropLoanIndex('loans_matures_on');
        $this->dropRefinanceForeignKey();
        $this->forge->dropColumn('loans', [
            'loan_name',
            'first_payment_on',
            'payment_due_day',
            'balloon_amount',
            'closed_on',
            'notes',
            'refinanced_from_loan_id',
        ]);
        $this->removeLookups();
    }

    private function dropLoanIndex(string $indexName): void
    {
        /** @var BaseConnection $db */
        $db = $this->db;
        foreach (array_keys($db->getIndexData('loans')) as $existingName) {
            if ($existingName === $indexName || str_ends_with($existingName, '_' . $indexName)) {
                $this->forge->dropKey('loans', $existingName, false);

                return;
            }
        }
    }

    private function dropRefinanceForeignKey(): void
    {
        /** @var BaseConnection $db */
        $db = $this->db;
        foreach ($db->getForeignKeyData('loans') as $foreignKey) {
            if (in_array('refinanced_from_loan_id', $foreignKey->column_name, true)) {
                $this->forge->dropForeignKey('loans', $foreignKey->constraint_name);

                return;
            }
        }
    }

    private function createVehicleAcquisitionsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'fleet_vehicle_id' => ['type' => 'INT', 'unsigned' => true],
            'acquisition_method_lookup_value_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'funding_method_lookup_value_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'acquisition_loan_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'source_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'external_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'purchase_order_subtotal' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'rebates_incentives' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'trade_in_credit' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'cash_paid_at_closing' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('fleet_vehicle_id');
        $this->forge->addKey('acquisition_method_lookup_value_id');
        $this->forge->addKey('funding_method_lookup_value_id');
        $this->forge->addKey('acquisition_loan_id');
        $this->forge->addForeignKey('fleet_vehicle_id', 'fleet_vehicles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('acquisition_method_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('funding_method_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('acquisition_loan_id', 'loans', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('vehicle_acquisitions');
    }

    private function extendLoansTable(): void
    {
        $this->forge->addColumn('loans', [
            'loan_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'lender_id'],
            'first_payment_on' => ['type' => 'DATE', 'null' => true, 'after' => 'opened_on'],
            'payment_due_day' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'after' => 'first_payment_on'],
            'balloon_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'monthly_payment'],
            'closed_on' => ['type' => 'DATE', 'null' => true, 'after' => 'paid_off_on'],
            'notes' => ['type' => 'TEXT', 'null' => true, 'after' => 'closed_on'],
            'refinanced_from_loan_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'notes'],
        ]);
        $this->forge->addKey('refinanced_from_loan_id');
        $this->forge->addKey(['lender_id', 'loan_status_lookup_value_id'], false, false, 'loans_lender_status');
        $this->forge->addKey('matures_on');
        $this->forge->addForeignKey('refinanced_from_loan_id', 'loans', 'id', 'CASCADE', 'SET NULL');
        $this->forge->processIndexes('loans');
    }

    private function createLoanBalanceSnapshotsTable(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'loan_id' => ['type' => 'INT', 'unsigned' => true],
            'as_of_date' => ['type' => 'DATE'],
            'principal_balance' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'payoff_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'source_method_lookup_value_id' => ['type' => 'INT', 'unsigned' => true],
            'source_reference' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['loan_id', 'as_of_date'], 'loan_balance_snapshots_loan_date');
        $this->forge->addKey(['loan_id', 'as_of_date'], false, false, 'loan_balance_snapshots_latest');
        $this->forge->addKey('source_method_lookup_value_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('loan_id', 'loans', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('source_method_lookup_value_id', 'lookup_values', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('loan_balance_snapshots');
    }

    private function seedLookups(): void
    {
        $lookups = [
            'acquisition_method' => ['Acquisition Method', [
                'dealer_purchase' => 'Dealer Purchase',
                'private_party_purchase' => 'Private-Party Purchase',
                'auction' => 'Auction',
                'lease' => 'Lease',
                'transfer' => 'Transfer',
                'other' => 'Other',
            ]],
            'funding_method' => ['Funding Method', [
                'cash' => 'Cash',
                'financed' => 'Financed',
                'mixed' => 'Mixed',
                'lease' => 'Lease',
                'transfer' => 'Transfer',
                'unknown' => 'Unknown',
            ]],
            'loan_balance_source' => ['Loan Balance Source', [
                'lender_statement' => 'Lender Statement',
                'payoff_quote' => 'Payoff Quote',
                'manual' => 'Manual',
                'imported' => 'Imported',
            ]],
        ];

        foreach ($lookups as $typeCode => [$typeName, $values]) {
            $typeId = $this->lookupTypeId($typeCode, $typeName);
            $sortOrder = 10;
            foreach ($values as $code => $name) {
                $this->firstOrCreate('lookup_values', ['lookup_type_id' => $typeId, 'code' => $code], [
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]);
                $sortOrder += 10;
            }
        }

        $fileTypeId = $this->lookupTypeId('file_type', 'File Type');
        foreach (['purchase_agreement' => 'Purchase Agreement', 'finance_agreement' => 'Finance Agreement', 'lender_statement' => 'Lender Statement', 'payoff_quote' => 'Payoff Quote'] as $code => $name) {
            $this->firstOrCreate('lookup_values', ['lookup_type_id' => $fileTypeId, 'code' => $code], ['name' => $name, 'sort_order' => 100, 'is_active' => true]);
        }
    }

    private function removeLookups(): void
    {
        foreach (['acquisition_method', 'funding_method', 'loan_balance_source'] as $typeCode) {
            $type = $this->db->table('lookup_types')->where('code', $typeCode)->get()->getRowArray();
            if ($type === null) {
                continue;
            }
            $this->db->table('lookup_values')->where('lookup_type_id', (int) $type['id'])->delete();
            $this->db->table('lookup_types')->where('id', (int) $type['id'])->delete();
        }

        $fileType = $this->db->table('lookup_types')->where('code', 'file_type')->get()->getRowArray();
        if ($fileType !== null) {
            $this->db->table('lookup_values')->where('lookup_type_id', (int) $fileType['id'])->whereIn('code', ['purchase_agreement', 'finance_agreement', 'lender_statement', 'payoff_quote'])->delete();
        }
    }

    private function lookupTypeId(string $code, string $name): int
    {
        return $this->firstOrCreate('lookup_types', ['code' => $code], ['name' => $name]);
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $data */
    private function firstOrCreate(string $table, array $where, array $data): int
    {
        /** @var BaseConnection $db */
        $db = $this->db;
        $existing = $db->table($table)->where($where)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $fields = $db->getFieldNames($table);
        $insert = array_merge($where, $data);
        if (in_array('created_at', $fields, true)) {
            $insert['created_at'] = $now;
        }
        if (in_array('updated_at', $fields, true)) {
            $insert['updated_at'] = $now;
        }
        $db->table($table)->insert($insert);

        return (int) $db->insertID();
    }
}
