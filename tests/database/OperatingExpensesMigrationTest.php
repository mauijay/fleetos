<?php

use App\Database\Migrations\CreateOperatingExpensesAndReceipts;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-12-000020_CreateOperatingExpensesAndReceipts.php';

/** @internal */
final class OperatingExpensesMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['operating_expense_receipts', 'operating_expenses', 'files', 'turo_trips_normalized', 'fleet_vehicles', 'companies', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(150), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150), sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE(lookup_type_id, code))');
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('files') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->table('lookup_types')->insert(['code' => 'audit_action', 'name' => 'Audit Action']);
    }

    public function testCreatesRequiredSchemaLookupsIndexesAndForeignKeys(): void
    {
        $this->migration()->up();

        $this->assertTrue($this->connection->tableExists('operating_expenses'));
        $this->assertTrue($this->connection->tableExists('operating_expense_receipts'));
        foreach (['company_id', 'fleet_vehicle_id', 'turo_trip_normalized_id', 'expense_category_lookup_value_id', 'amount', 'archived_at', 'archive_reason'] as $field) {
            $this->assertContains($field, $this->connection->getFieldNames('operating_expenses'));
        }
        foreach (['company_id', 'operating_expense_id', 'file_id', 'classification_code', 'duplicate_of_receipt_id', 'classified_at'] as $field) {
            $this->assertContains($field, $this->connection->getFieldNames('operating_expense_receipts'));
        }
        $categoryType = $this->connection->table('lookup_types')->where('code', 'operating_expense_category')->get()->getRowArray();
        $this->assertNotNull($categoryType);
        $this->assertSame(
            ['cleaning_detailing', 'supplies_consumables', 'fuel', 'non_airport_parking_tolls', 'towing_roadside', 'registration_fees', 'software_services', 'other'],
            array_column($this->connection->table('lookup_values')->where('lookup_type_id', $categoryType['id'])->orderBy('sort_order')->get()->getResultArray(), 'code'),
        );
        $this->assertContains('operating_expenses_company_status_date', array_keys($this->connection->getIndexData('operating_expenses')));
        $this->assertContains('operating_expense_receipts_company_file', array_keys($this->connection->getIndexData('operating_expense_receipts')));
        $this->assertNotEmpty($this->connection->getForeignKeyData('operating_expenses'));
        $this->assertNotEmpty($this->connection->getForeignKeyData('operating_expense_receipts'));
    }

    public function testCompanyFilePairIsUniqueAndRollbackRemovesOnlySliceSchema(): void
    {
        $migration = $this->migration();
        $migration->up();
        $this->connection->table('companies')->insert(['id' => 1]);
        $this->connection->table('files')->insert(['id' => 2]);
        $now = '2026-09-12 12:00:00';
        $receipt = ['company_id' => 1, 'file_id' => 2, 'classification_code' => 'needs_classification', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now];
        $this->connection->table('operating_expense_receipts')->insert($receipt);
        try {
            $this->connection->table('operating_expense_receipts')->insert($receipt);
            $this->fail('A company cannot create two generic receipt rows for one stored file.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        $migration->down();
        $this->assertFalse($this->connection->tableExists('operating_expense_receipts'));
        $this->assertFalse($this->connection->tableExists('operating_expenses'));
        $this->assertSame(1, $this->connection->table('lookup_types')->where('code', 'audit_action')->countAllResults());
    }

    private function migration(): CreateOperatingExpensesAndReceipts
    {
        return new CreateOperatingExpensesAndReceipts(Database::forge($this->connection));
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
