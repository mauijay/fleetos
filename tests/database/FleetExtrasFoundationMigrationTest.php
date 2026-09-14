<?php

use App\Database\Migrations\CreateFleetExtrasFoundation;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-13-000021_CreateFleetExtrasFoundation.php';

/** @internal */
final class FleetExtrasFoundationMigrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'turo_extra_selections',
        'turo_extra_reservation_snapshots',
        'fleet_extra_source_mappings',
        'fleet_extras',
        'turo_import_batches',
        'turo_trips_normalized',
        'fleet_vehicles',
        'lookup_values',
        'lookup_types',
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
        $this->createPrerequisites();
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    public function testCreatesCompanyOwnedTablesForeignKeysIndexesAndUniqueIdentities(): void
    {
        $this->migration()->up();

        foreach (array_slice(self::TABLES, 0, 4) as $table) {
            $this->assertTrue($this->connection->tableExists($table));
            $this->assertNotEmpty($this->connection->getForeignKeyData($table));
        }
        $this->assertContains('company_id', $this->connection->getFieldNames('fleet_extras'));
        $this->assertNotContains('fleet_extra_id', $this->connection->getFieldNames('turo_extra_selections'));
        $this->assertContains('fleet_extras_company_code', array_keys($this->connection->getIndexData('fleet_extras')));
        $this->assertContains('fleet_extra_source_company_system_id', array_keys($this->connection->getIndexData('fleet_extra_source_mappings')));
        $this->assertContains('turo_extra_selection_stable_identity', array_keys($this->connection->getIndexData('turo_extra_selections')));

        $now = '2026-09-13 10:00:00';
        $this->connection->table('fleet_extras')->insert(['company_id' => 1, 'code' => 'beach_gear', 'display_name' => 'Beach Gear', 'created_at' => $now, 'updated_at' => $now]);
        $extraId = (int) $this->connection->insertID();
        $this->expectDatabaseFailure(fn () => $this->connection->table('fleet_extras')->insert(['company_id' => 1, 'code' => 'beach_gear', 'display_name' => 'Duplicate', 'created_at' => $now, 'updated_at' => $now]));

        $this->connection->table('fleet_extras')->insert(['company_id' => 2, 'code' => 'company_b_extra', 'display_name' => 'Company B Extra', 'created_at' => $now, 'updated_at' => $now]);
        $companyBExtraId = (int) $this->connection->insertID();
        $crossCompany = $this->mapping($companyBExtraId, $now);
        $crossCompany['source_extra_id'] = 'cross-company';
        $this->expectDatabaseFailure(fn () => $this->connection->table('fleet_extra_source_mappings')->insert($crossCompany));

        $this->connection->table('fleet_extra_source_mappings')->insert($this->mapping($extraId, $now));
        $this->expectDatabaseFailure(fn () => $this->connection->table('fleet_extra_source_mappings')->insert($this->mapping($extraId, $now)));

        $typeId = (int) $this->connection->table('lookup_values')->select('id')->where('code', 'turo_extras')->get()->getRow('id');
        $this->connection->table('turo_import_batches')->insert(['import_type_lookup_value_id' => $typeId, 'source_hash' => 'fixture', 'created_at' => $now]);
        $batchId = (int) $this->connection->insertID();
        $this->connection->table('turo_extra_reservation_snapshots')->insert([
            'company_id' => 1, 'turo_import_batch_id' => $batchId, 'turo_reservation_id' => '70000001', 'snapshot_complete' => 1,
            'observed_at' => $now, 'source_payload_hash' => str_repeat('a', 64), 'source_payload' => '{}', 'created_at' => $now,
        ]);
        $snapshotId = (int) $this->connection->insertID();
        $selection = $this->selection($snapshotId, $now);
        $this->connection->table('turo_extra_selections')->insert($selection);
        $this->expectDatabaseFailure(fn () => $this->connection->table('turo_extra_selections')->insert($selection));
    }

    public function testRollbackDropsSliceTablesAndOnlyItsUnusedLookupValue(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        foreach (array_slice(self::TABLES, 0, 4) as $table) {
            $this->assertFalse($this->connection->tableExists($table));
        }
        $this->assertSame(0, $this->connection->table('lookup_values')->where('code', 'turo_extras')->countAllResults());
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(190), created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(190), sort_order INTEGER, is_active BOOLEAN, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), starts_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type_lookup_value_id INTEGER NULL, source_hash VARCHAR(128) UNIQUE, created_at DATETIME NULL)');
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
    }

    private function mapping(int $extraId, string $now): array
    {
        return ['company_id' => 1, 'fleet_extra_id' => $extraId, 'source_system' => 'turo', 'source_extra_id' => '3154920', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    }

    private function selection(int $snapshotId, string $now): array
    {
        return [
            'company_id' => 1, 'first_snapshot_id' => $snapshotId, 'last_snapshot_id' => $snapshotId,
            'turo_reservation_id' => '70000001', 'source_extra_id' => '3154920', 'reservation_state_extra_id' => '8020576',
            'source_label' => 'Beach gear', 'unit_price' => '47.00', 'currency_code' => 'USD', 'first_observed_at' => $now,
            'last_observed_at' => $now, 'source_payload_hash' => str_repeat('b', 64), 'source_payload' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function expectDatabaseFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a database uniqueness failure.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function migration(): CreateFleetExtrasFoundation
    {
        return new CreateFleetExtrasFoundation(Database::forge($this->connection));
    }
}
