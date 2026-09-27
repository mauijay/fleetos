<?php

use App\Database\Migrations\CreateSuperchargerReconciliation;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-26-000028_CreateSuperchargerReconciliation.php';

/** @internal */
final class SuperchargerReconciliationMigrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->dropTables();
        $this->createPrerequisites();
    }

    public function testMigrationAddsCanonicalFieldsAndReconciliationFoundation(): void
    {
        (new CreateSuperchargerReconciliation(Database::forge($this->connection)))->up();

        foreach (['tesla_charging_import_batches', 'tesla_charging_import_rows', 'tesla_charging_line_items', 'supercharger_reimbursement_cases'] as $table) {
            $this->assertTrue($this->connection->tableExists($table));
        }
        $tripFields = $this->connection->getFieldNames('turo_trips_normalized');
        $sessionFields = $this->connection->getFieldNames('charging_sessions');
        $this->assertContains('on_trip_ev_charging_amount', $tripFields);
        $this->assertContains('post_trip_ev_charging_amount', $tripFields);
        foreach (['source_type', 'source_session_fingerprint', 'source_invoice_number', 'source_vin', 'source_started_at', 'source_invoice_url', 'custody_classification', 'custody_basis_code', 'custody_basis_event_id', 'candidate_turo_trip_normalized_id'] as $field) {
            $this->assertContains($field, $sessionFields);
        }
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, airport_fee_amount DECIMAL(10,2), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('charging_sessions') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER NULL, deleted_at DATETIME NULL)');
    }

    private function dropTables(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['supercharger_reimbursement_cases', 'tesla_charging_line_items', 'tesla_charging_import_rows', 'tesla_charging_import_batches', 'charging_sessions', 'turo_trips_normalized', 'trip_movement_events', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}
