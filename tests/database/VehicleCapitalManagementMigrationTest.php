<?php

use App\Database\Migrations\AddVehicleCapitalManagement;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-29-000013_AddVehicleCapitalManagement.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleCapitalManagementMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['loan_balance_snapshots', 'vehicle_acquisitions', 'loans', 'lenders', 'fleet_vehicles', 'companies', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(150), created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150), sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE(lookup_type_id, code))');
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, purchase_date DATE NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lenders') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER UNIQUE)');
        $this->connection->query('CREATE TABLE ' . $this->table('loans') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, lender_id INTEGER, loan_status_lookup_value_id INTEGER NULL, account_number_last4 VARCHAR(4) NULL, original_principal DECIMAL(12,2) NULL, current_balance DECIMAL(12,2) NULL, interest_rate DECIMAL(6,4) NULL, monthly_payment DECIMAL(10,2) NULL, term_months INTEGER NULL, opened_on DATE NULL, matures_on DATE NULL, paid_off_on DATE NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->table('lookup_types')->insert(['code' => 'file_type', 'name' => 'File Type']);
        $this->connection->table('companies')->insert(['id' => 1, 'name' => 'Test Lender']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 1, 'company_id' => 1]);
        $this->connection->table('fleet_vehicles')->insert(['id' => 7, 'company_id' => 1]);
        $this->connection->table('lenders')->insert(['id' => 1, 'company_id' => 1]);
        $this->connection->table('loans')->insert(['fleet_vehicle_id' => 1, 'lender_id' => 1, 'current_balance' => '12345.67']);
    }

    public function testMigrationIsAdditiveAndPreservesLegacyLoanBalance(): void
    {
        $this->migration()->up();

        $fields = $this->connection->getFieldNames('loans');
        $this->assertContains('first_payment_on', $fields);
        $this->assertContains('refinanced_from_loan_id', $fields);
        $this->assertTrue($this->connection->tableExists('vehicle_acquisitions'));
        $this->assertTrue($this->connection->tableExists('loan_balance_snapshots'));
        $acquisitionFields = $this->connection->getFieldNames('vehicle_acquisitions');
        $this->assertContains('purchase_order_subtotal', $acquisitionFields);
        $this->assertContains('acquisition_loan_id', $acquisitionFields);
        $this->assertNotContains('purchase_price', $acquisitionFields);
        $this->assertNotContains('amount_financed', $acquisitionFields);
        $this->assertSame(12345.67, (float) $this->connection->table('loans')->where('id', 1)->get()->getRowArray()['current_balance']);
        $this->assertSame(0, $this->connection->table('loan_balance_snapshots')->countAllResults());
        $this->assertSame(1, $this->connection->table('lookup_types')->where('code', 'acquisition_method')->countAllResults());
    }

    public function testAcquisitionAndSnapshotUniquenessPoliciesAreEnforced(): void
    {
        $this->migration()->up();
        $this->connection->table('vehicle_acquisitions')->insert(['fleet_vehicle_id' => 7, 'acquisition_loan_id' => null]);
        $this->connection->table('loans')->insert(['fleet_vehicle_id' => 7, 'lender_id' => 1]);
        $loanId = (int) $this->connection->insertID();
        $source = $this->connection->table('lookup_values')->where('code', 'manual')->get()->getRowArray();
        $this->connection->table('loan_balance_snapshots')->insert(['loan_id' => $loanId, 'as_of_date' => '2026-08-29', 'principal_balance' => '100.00', 'source_method_lookup_value_id' => (int) $source['id']]);

        $this->expectException(Throwable::class);
        $this->connection->table('loan_balance_snapshots')->insert(['loan_id' => $loanId, 'as_of_date' => '2026-08-29', 'principal_balance' => '90.00', 'source_method_lookup_value_id' => (int) $source['id']]);
    }

    public function testOnlyOneAcquisitionCanExistPerVehicle(): void
    {
        $this->migration()->up();
        $this->connection->table('vehicle_acquisitions')->insert(['fleet_vehicle_id' => 7]);

        $this->expectException(Throwable::class);
        $this->connection->table('vehicle_acquisitions')->insert(['fleet_vehicle_id' => 7]);
    }

    public function testMigrationDownRemovesOnlyNewSchema(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse($this->connection->tableExists('vehicle_acquisitions'));
        $this->assertFalse($this->connection->tableExists('loan_balance_snapshots'));
        $this->assertNotContains('loan_name', $this->connection->getFieldNames('loans'));
        $this->assertSame(1, $this->connection->table('loans')->countAllResults());
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function migration(): AddVehicleCapitalManagement
    {
        return new AddVehicleCapitalManagement(Database::forge($this->connection));
    }
}
