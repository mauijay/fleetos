<?php

use App\Database\Migrations\AddAirportCompanyOwnership;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-11-000019_AddAirportCompanyOwnership.php';

/** @internal */
final class AirportCompanyOwnershipMigrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'airport_operations_expenses',
        'airport_operations_run_activities',
        'airport_operations_runs',
        'airport_turo_access_receipts',
        'airport_turo_access_override_incidents',
        'airport_movement_workflows',
        'turo_trips_normalized',
        'fleet_vehicles',
        'companies',
    ];

    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (self::TABLES as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->createSchema();
        $this->seedCompanies();
    }

    public function testDeterministicPreflightBackfillsBothRootsAndAddsConstraints(): void
    {
        $this->connection->table('airport_operations_runs')->insert(['id' => 101, 'chase_fleet_vehicle_id' => 1, 'run_date' => '2026-09-11', 'run_status' => 'open']);
        $this->connection->table('airport_operations_runs')->insert(['id' => 102, 'chase_fleet_vehicle_id' => null, 'run_date' => '2026-09-11', 'run_status' => 'open']);
        $this->connection->table('airport_operations_run_activities')->insert(['id' => 202, 'airport_operations_run_id' => 102, 'turo_trip_normalized_id' => 20]);
        $this->connection->table('airport_turo_access_override_incidents')->insert(['id' => 301, 'airport_movement_workflow_id' => 10, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1]);
        $this->connection->table('airport_turo_access_receipts')->insert(['id' => 401, 'fleet_vehicle_id' => 1, 'receipt_classification' => 'unresolved', 'document_date' => '2026-09-11']);
        $this->connection->table('airport_turo_access_receipts')->insert(['id' => 402, 'airport_turo_access_override_incident_id' => 301, 'receipt_classification' => 'trip_reimbursement', 'document_date' => '2026-09-11']);
        $this->connection->table('airport_turo_access_receipts')->insert(['id' => 403, 'airport_operations_expense_id' => 503, 'receipt_classification' => 'airport_operations_expense', 'document_date' => '2026-09-11']);
        $this->connection->table('airport_operations_expenses')->insert(['id' => 503, 'airport_operations_run_id' => 102, 'airport_turo_access_receipt_id' => 403]);

        $migration = $this->migration();
        $this->assertSame(['runs' => [101 => 1, 102 => 2], 'receipts' => [401 => 1, 402 => 1, 403 => 2]], $migration->preflight());
        $migration->up();

        $this->assertSame([1, 2], array_map('intval', array_column($this->connection->table('airport_operations_runs')->orderBy('id')->get()->getResultArray(), 'company_id')));
        $this->assertSame([1, 1, 2], array_map('intval', array_column($this->connection->table('airport_turo_access_receipts')->orderBy('id')->get()->getResultArray(), 'company_id')));
        $this->assertFalse((bool) $this->field('airport_operations_runs', 'company_id')->nullable);
        $this->assertFalse((bool) $this->field('airport_turo_access_receipts', 'company_id')->nullable);

        $runIndexes = array_keys($this->connection->getIndexData('airport_operations_runs'));
        $receiptIndexes = array_keys($this->connection->getIndexData('airport_turo_access_receipts'));
        $this->assertContains('airport_operations_runs_company_date_status_index', $runIndexes);
        $this->assertContains('airport_turo_access_receipts_company_classification_date_index', $receiptIndexes);
        $this->assertNotEmpty($this->connection->getForeignKeyData('airport_operations_runs'));
        $this->assertNotEmpty($this->connection->getForeignKeyData('airport_turo_access_receipts'));

        try {
            $this->connection->table('airport_operations_runs')->insert(['run_date' => '2026-09-12', 'run_status' => 'open']);
            $this->fail('New airport operation runs must require company_id.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->connection->table('airport_turo_access_receipts')->insert(['receipt_classification' => 'unresolved']);
            $this->fail('New airport receipts must require company_id.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $migration->down();
        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_operations_runs'));
        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_turo_access_receipts'));
        $this->assertSame(2, $this->connection->table('airport_operations_runs')->countAllResults());
        $this->assertSame(3, $this->connection->table('airport_turo_access_receipts')->countAllResults());
    }

    public function testMissingOwnershipStopsBeforeAnySchemaChange(): void
    {
        $this->connection->table('airport_operations_runs')->insert(['id' => 111, 'run_date' => '2026-09-11', 'run_status' => 'open']);

        try {
            $this->migration()->up();
            $this->fail('Missing legacy ownership must fail preflight.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('airport_operations_runs row 111', $exception->getMessage());
            $this->assertStringContainsString('ownership cannot be proven', $exception->getMessage());
        }

        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_operations_runs'));
        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_turo_access_receipts'));
    }

    public function testConflictingOwnershipStopsBeforeAnySchemaChange(): void
    {
        $this->connection->table('airport_operations_runs')->insert(['id' => 112, 'chase_fleet_vehicle_id' => 1, 'run_date' => '2026-09-11', 'run_status' => 'open']);
        $this->connection->table('airport_operations_run_activities')->insert(['id' => 212, 'airport_operations_run_id' => 112, 'fleet_vehicle_id' => 2]);

        try {
            $this->migration()->up();
            $this->fail('Conflicting legacy ownership must fail preflight.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('airport_operations_runs row 112', $exception->getMessage());
            $this->assertStringContainsString('conflicting company IDs 1, 2', $exception->getMessage());
        }

        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_operations_runs'));
        $this->assertNotContains('company_id', $this->connection->getFieldNames('airport_turo_access_receipts'));
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, turo_trip_normalized_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_override_incidents') . ' (id INTEGER PRIMARY KEY, airport_movement_workflow_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_runs') . ' (id INTEGER PRIMARY KEY, chase_fleet_vehicle_id INTEGER NULL, run_date DATE NULL, run_status VARCHAR(40) NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_run_activities') . ' (id INTEGER PRIMARY KEY, airport_operations_run_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, airport_movement_workflow_id INTEGER NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_receipts') . ' (id INTEGER PRIMARY KEY, airport_turo_access_override_incident_id INTEGER NULL, airport_operations_expense_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, receipt_classification VARCHAR(60) NULL, document_date DATE NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expenses') . ' (id INTEGER PRIMARY KEY, airport_operations_run_id INTEGER NULL, airport_turo_access_receipt_id INTEGER NULL)');
    }

    private function seedCompanies(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('turo_trips_normalized')->insertBatch([['id' => 10, 'fleet_vehicle_id' => 1], ['id' => 20, 'fleet_vehicle_id' => 2]]);
        $this->connection->table('airport_movement_workflows')->insertBatch([['id' => 10, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 10], ['id' => 20, 'fleet_vehicle_id' => 2, 'turo_trip_normalized_id' => 20]]);
    }

    private function field(string $table, string $name): object
    {
        foreach ($this->connection->getFieldData($table) as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        throw new RuntimeException("Missing {$table}.{$name} field.");
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function migration(): AddAirportCompanyOwnership
    {
        return new AddAirportCompanyOwnership(Database::forge($this->connection));
    }
}
