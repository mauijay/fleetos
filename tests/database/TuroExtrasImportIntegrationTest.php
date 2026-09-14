<?php

use App\Database\Migrations\CreateFleetExtrasFoundation;
use App\Repositories\AuditLogRepository;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Services\Fleet\FleetExtraService;
use App\Services\Turo\TuroExtrasImportService;
use App\Services\Turo\TuroImportAuditService;
use App\Validation\Turo\TuroExtrasPayloadValidator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-13-000021_CreateFleetExtrasFoundation.php';

/** @internal */
final class TuroExtrasImportIntegrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'turo_extra_selections', 'turo_extra_reservation_snapshots', 'fleet_extra_source_mappings', 'fleet_extras',
        'audit_logs', 'turo_import_errors', 'turo_import_batches', 'turo_trips_normalized', 'fleet_vehicles',
        'lookup_values', 'lookup_types', 'companies',
    ];

    private BaseConnection $connection;
    private FleetExtraRepository $repository;
    private TuroExtrasImportService $importer;
    private FleetExtraService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (self::TABLES as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->createPrerequisites();
        (new CreateFleetExtrasFoundation(Database::forge($this->connection)))->up();
        $this->seedLookups();
        $this->connection->query('PRAGMA foreign_keys = ON');

        $this->repository = new FleetExtraRepository($this->connection);
        $lookups = new LookupRepository($this->connection);
        $audit = new AuditLogRepository($this->connection);
        $this->importer = new TuroExtrasImportService(
            $this->repository,
            new TuroExtrasPayloadValidator(),
            $lookups,
            new TuroImportBatchRepository($this->connection),
            new TuroImportErrorRepository($this->connection),
            new TuroImportAuditService($audit, $lookups),
        );
        $this->catalog = new FleetExtraService($this->repository, $audit, $lookups);
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    public function testImportIsStablePreservesSourceFactsAndOnlyCompleteSnapshotsRemove(): void
    {
        $fixture = $this->fixture('turo_extras_export_v1.json');
        $first = $this->importer->import($fixture, 1, 10, 'extras.json');

        $this->assertSame(3, $first->reservationsProcessed);
        $this->assertSame(4, $first->selectionsAdded);
        $this->assertSame(3, $first->unmappedSourceExtraIds);
        $this->assertSame(4, $this->connection->table('turo_extra_selections')->where('company_id', 1)->countAllResults());
        $missingQuantity = $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRowArray();
        $this->assertNull($missingQuantity['quantity']);
        $this->assertNull($missingQuantity['gross_amount']);
        $this->assertSame(30.0, (float) $missingQuantity['unit_price']);
        $this->assertNull($missingQuantity['turo_trip_normalized_id'], 'A same-ID trip belonging to another company must not attach.');
        $matched = $this->connection->table('turo_extra_selections')->where('turo_reservation_id', '70000001')->get()->getRowArray();
        $this->assertSame(100, (int) $matched['turo_trip_normalized_id']);

        $duplicate = $this->importer->import($fixture, 1, 10, 'extras-again.json');
        $this->assertTrue($duplicate->duplicateFile);
        $this->assertSame(4, $duplicate->selectionsUnchanged);
        $this->assertSame(3, $this->connection->table('turo_extra_reservation_snapshots')->where('company_id', 1)->countAllResults());

        $older = json_decode($fixture, true, 64, JSON_THROW_ON_ERROR);
        $older['exported_at'] = '2026-09-12T08:00:00-10:00';
        $older['reservations'] = [$older['reservations'][0]];
        $older['reservations'][0]['extras'][0]['price'] = '12.00';
        $this->importer->import(json_encode($older, JSON_THROW_ON_ERROR), 1, 10, 'older.json');
        $this->assertSame(47.0, (float) $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020576')->get()->getRow('unit_price'));

        $partial = json_decode($this->fixture('turo_extras_export_v1_removal.json'), true, 64, JSON_THROW_ON_ERROR);
        $partial['exported_at'] = '2026-09-13T08:30:00-10:00';
        $partial['reservations'][0]['snapshot_complete'] = false;
        $this->importer->import(json_encode($partial, JSON_THROW_ON_ERROR), 1, 10, 'partial.json');
        $this->assertNull($this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRow('removed_at'));

        $removed = $this->importer->import($this->fixture('turo_extras_export_v1_removal.json'), 1, 10, 'complete-removal.json');
        $this->assertSame(1, $removed->selectionsRemoved);
        $selection = $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRowArray();
        $this->assertNotNull($selection['removed_at']);
        $this->assertSame('2026-09-13 19:00:00', $selection['last_observed_at']);
        $this->assertSame(6, (int) $selection['last_snapshot_id']);

        $failureOnly = ['schema' => 'fleetos-turo-extras-v1', 'exported_at' => '2026-09-13T10:00:00-10:00', 'reservations' => [], 'failures' => [['reservation_id' => '79999999', 'error' => 'HTTP 404']]];
        $failureResult = $this->importer->import(json_encode($failureOnly, JSON_THROW_ON_ERROR), 1, 10, 'failure.json');
        $this->assertSame(1, $failureResult->exportFailures);
        $this->assertSame('extras_export_failure', $this->connection->table('turo_import_errors')->where('message', 'Reservation 79999999: HTTP 404')->get()->getRow('error_code'));
    }

    public function testCatalogMappingsAreCompanyScopedExplicitManyToOneAndAudited(): void
    {
        $fixture = $this->fixture('turo_extras_export_v1.json');
        $this->importer->import($fixture, 1, 10, 'company-a.json');
        $this->importer->import($fixture, 2, 20, 'company-b.json');

        $premiumId = $this->catalog->createExtra(1, ['code' => 'premium_beach_gear', 'display_name' => 'Premium Beach Gear', 'active' => '1', 'sort_order' => 10], 10);
        $basicId = $this->catalog->createExtra(1, ['code' => 'basic_beach_gear', 'display_name' => 'Basic Beach Gear', 'active' => '1', 'sort_order' => 20], 10);
        $companyBId = $this->catalog->createExtra(2, ['code' => 'beach_gear', 'display_name' => 'Company B Beach Gear', 'active' => '1', 'sort_order' => 10], 20);

        $this->catalog->mapSource(1, '3154920', $premiumId, null, 10);
        $this->catalog->mapSource(1, '3199029', $basicId, null, 10);
        $this->catalog->mapSource(2, '3154920', $companyBId, null, 20);
        $this->assertSame($premiumId, (int) $this->repository->mapping(1, 'turo', '3154920')['fleet_extra_id']);
        $this->assertSame($companyBId, (int) $this->repository->mapping(2, 'turo', '3154920')['fleet_extra_id']);
        $this->assertSame('Beach gear', $this->repository->latestSourceObservation(1, '3199029')['source_label']);

        $this->catalog->mapSource(1, '3199029', $premiumId, 'Operator consolidated recreated source Extra', 10);
        $this->assertSame($premiumId, (int) $this->repository->mapping(1, 'turo', '3199029')['fleet_extra_id']);
        $audit = $this->connection->table('audit_logs')->where('table_name', 'fleet_extra_source_mappings')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringContainsString('Operator consolidated', (string) $audit['new_values']);

        try {
            $this->catalog->mapSource(1, '4000000', $companyBId, null, 10);
            $this->fail('A Company B canonical Extra must not be a Company A mapping target.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $created = $this->catalog->createAndMap(1, '4000000', ['code' => 'fsd_upgrade', 'display_name' => 'FSD Upgrade', 'active' => '1', 'sort_order' => 30], 10);
        $this->assertSame($created['extra_id'], (int) $this->repository->mapping(1, 'turo', '4000000')['fleet_extra_id']);

        $this->catalog->updateExtra(1, $premiumId, ['code' => 'premium_beach_gear', 'display_name' => 'Premium Beach Gear', 'sort_order' => 10], 10);
        $this->assertSame(0, (int) $this->repository->extra(1, $premiumId)['active']);
        $this->assertNotNull($this->repository->mapping(1, 'turo', '3154920'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot change after source activity');
        $this->catalog->updateExtra(1, $premiumId, ['code' => 'renamed_code', 'display_name' => 'Premium Beach Gear', 'active' => '1', 'sort_order' => 10], 10);
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(190), created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(190), sort_order INTEGER DEFAULT 0, is_active BOOLEAN DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), starts_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type_lookup_value_id INTEGER, import_status_lookup_value_id INTEGER, source_filename VARCHAR(190), source_hash VARCHAR(128) UNIQUE, row_count INTEGER DEFAULT 0, started_at DATETIME, completed_at DATETIME, error_message TEXT, created_by INTEGER, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, severity_lookup_value_id INTEGER, raw_table VARCHAR(120), raw_row_id INTEGER, row_number INTEGER, error_code VARCHAR(120), field_name VARCHAR(120), message TEXT, raw_payload TEXT, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action_lookup_value_id INTEGER, table_name VARCHAR(120), record_id INTEGER, old_values TEXT, new_values TEXT, created_at DATETIME)');
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 100, 'fleet_vehicle_id' => 1, 'turo_trip_id' => 'trip-a', 'turo_reservation_id' => '70000001', 'starts_at' => '2026-09-14 10:00:00'],
            ['id' => 200, 'fleet_vehicle_id' => 2, 'turo_trip_id' => 'trip-b', 'turo_reservation_id' => '70000002', 'starts_at' => '2026-09-17 10:00:00'],
        ]);
    }

    private function seedLookups(): void
    {
        $now = '2026-09-13 00:00:00';
        foreach (['import_status' => ['processing', 'completed', 'failed'], 'import_error_severity' => ['error', 'warning'], 'audit_action' => ['imported', 'created', 'updated']] as $typeCode => $values) {
            $this->connection->table('lookup_types')->insert(['code' => $typeCode, 'name' => $typeCode, 'created_at' => $now, 'updated_at' => $now]);
            $typeId = (int) $this->connection->insertID();
            foreach ($values as $code) {
                $this->connection->table('lookup_values')->insert(['lookup_type_id' => $typeId, 'code' => $code, 'name' => $code, 'sort_order' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/_support/fixtures/' . $name);
        $this->assertIsString($contents);

        return $contents;
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
