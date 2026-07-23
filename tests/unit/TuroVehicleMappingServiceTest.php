<?php

use App\Repositories\FleetVehicleRepository;
use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Repositories\VehicleTuroListingRepository;
use App\Services\Turo\TuroVehicleMappingService;
use App\Services\Turo\TuroVehicleMatcher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class TuroVehicleMappingServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroVehicleMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();
        $this->service = new TuroVehicleMappingService(
            new VehicleTuroListingRepository($this->connection),
            new TuroVehicleMappingIssueRepository($this->connection),
        );
    }

    public function testUniqueUnmatchedTuroVehiclesAreGrouped(): void
    {
        $queue = $this->service->queue();

        $this->assertSame(2, count($queue['items']));
        $this->assertSame('turo-009', $queue['items'][0]['turo_vehicle_id']);
        $this->assertSame(2, $queue['items'][0]['affected_issue_count']);
        $this->assertSame(2, $queue['items'][0]['import_count']);
    }

    public function testValidMappingCanBeCreatedAndFutureMatcherUsesIt(): void
    {
        $result = $this->service->map('turo-009', 9, false, 'Mapped from cleanup queue.');

        $this->assertTrue($result['success']);
        $matcher = new TuroVehicleMatcher(new FleetVehicleRepository($this->connection));
        $this->assertSame(9, $matcher->match('turo-009'));
    }

    public function testMatcherHandlesOldAndNewSpaceshipNamingConventions(): void
    {
        $matcher = new TuroVehicleMatcher(new FleetVehicleRepository($this->connection));

        $this->assertSame(8, $matcher->match(null, 'Spaceship-008 (HI #651359)'));
        $this->assertSame(8, $matcher->match(null, 'Spaceship_008'));
        $this->assertSame(9, $matcher->match(null, 'Spaceship09'));
    }

    public function testMatcherPersistsInferredTuroVehicleIdMapping(): void
    {
        $matcher = new TuroVehicleMatcher(new FleetVehicleRepository($this->connection), new VehicleTuroListingRepository($this->connection));

        $this->assertSame(8, $matcher->match('3775859', 'Spaceship-008 (HI #651359)'));
        $this->assertSame(8, (int) $this->connection->table('vehicle_turo_listings')->where('turo_vehicle_id', '3775859')->get()->getRowArray()['fleet_vehicle_id']);
        $this->assertSame(8, $matcher->match('3775859', 'Jay\'s Tesla'));
    }

    public function testDuplicateExternalMappingIsPreventedWithoutConfirmation(): void
    {
        $this->service->map('turo-009', 9);
        $result = $this->service->map('turo-009', 8);

        $this->assertFalse($result['success']);
        $this->assertSame('external_already_mapped', $result['code']);
    }

    public function testFleetVehicleConflictIsSurfacedWithoutConfirmation(): void
    {
        $this->service->map('turo-existing-008', 8);
        $result = $this->service->map('turo-009', 8);

        $this->assertFalse($result['success']);
        $this->assertSame('fleet_vehicle_conflict', $result['code']);
    }

    public function testExplicitRemapIsHandledSafelyAndAudited(): void
    {
        $this->service->map('turo-009', 9);
        $result = $this->service->map('turo-009', 8, true, 'Confirmed listing moved.');

        $this->assertTrue($result['success']);
        $this->assertSame(8, (int) $this->connection->table('vehicle_turo_listings')->where('turo_vehicle_id', 'turo-009')->get()->getRowArray()['fleet_vehicle_id']);
        $this->assertGreaterThanOrEqual(2, $this->connection->table('vehicle_turo_listing_audits')->where('turo_vehicle_id', 'turo-009')->countAllResults());
    }

    public function testSuggestedExactAndStrongMatchesAreGenerated(): void
    {
        $queue = $this->service->queue();
        $byExternalId = array_column($queue['items'], null, 'turo_vehicle_id');

        $this->assertSame('Strong', $byExternalId['turo-009']['suggestion']['confidence']);
        $this->assertSame(9, $byExternalId['turo-009']['suggestion']['fleet_vehicle_id']);
        $this->assertSame('Exact', $byExternalId['turo-plate']['suggestion']['confidence']);
        $this->assertSame(8, $byExternalId['turo-plate']['suggestion']['fleet_vehicle_id']);
    }

    public function testVehicleColumnCanDriveStrongMatchSuggestion(): void
    {
        $this->insertIssue(4, 'turo-spaceship-008', 'Tesla Model Y 2026', ['vehicle' => 'Spaceship-008 (HI #651359)']);

        $queue = $this->service->queue();
        $byExternalId = array_column($queue['items'], null, 'turo_vehicle_id');

        $this->assertSame('Spaceship-008 (HI #651359)', $byExternalId['turo-spaceship-008']['vehicle_name']);
        $this->assertSame('Strong', $byExternalId['turo-spaceship-008']['suggestion']['confidence']);
        $this->assertSame(8, $byExternalId['turo-spaceship-008']['suggestion']['fleet_vehicle_id']);
    }

    public function testAmbiguousSpecMatchIsNotTreatedAsExact(): void
    {
        $this->insertIssue(5, 'turo-ambiguous', '2026 Tesla Model Y', ['year' => '2026', 'make' => 'Tesla', 'model' => 'Model Y', 'trim' => 'Long Range']);

        $queue = $this->service->queue();
        $byExternalId = array_column($queue['items'], null, 'turo_vehicle_id');

        $this->assertSame('None', $byExternalId['turo-ambiguous']['suggestion']['confidence']);
    }

    public function testMappingAloneDoesNotResolveHistoricalIssues(): void
    {
        $this->service->map('turo-009', 9);

        $this->assertSame(0, $this->service->resolveRelated('turo-009', 'Mapping saved.'));
        $this->assertSame(2, $this->connection->table('turo_import_errors')->where('error_code', 'vehicle_unmatched')->where('resolved_at', null)->like('raw_payload', 'turo-009')->countAllResults());
    }

    public function testCommandCenterCountsUniqueUnmatchedVehicles(): void
    {
        $summary = $this->service->attentionSummary();

        $this->assertSame(2, $summary['unique_unmatched_vehicles']);
        $this->assertSame(3, $summary['affected_issues']);
        $this->assertTrue($summary['has_unmatched']);
    }

    public function testEmptyStateBehaviorWorks(): void
    {
        $this->service->map('turo-009', 9);
        $this->connection->table('turo_import_errors')->like('raw_payload', 'turo-009')->update(['resolved_at' => '2026-07-19 09:00:00']);
        $this->service->map('turo-plate', 8);
        $this->connection->table('turo_import_errors')->like('raw_payload', 'turo-plate')->update(['resolved_at' => '2026-07-19 09:00:00']);

        $queue = $this->service->queue();
        $summary = $this->service->attentionSummary();

        $this->assertTrue($queue['is_empty']);
        $this->assertFalse($summary['has_unmatched']);
    }

    public function testInvalidMappingRequestsAreRejectedSafely(): void
    {
        $this->assertSame('missing_external_id', $this->service->map('', 9)['code']);
        $this->assertSame('invalid_fleet_vehicle', $this->service->map('turo-009', 999)['code']);
    }

    private function resetSchema(): void
    {
        foreach (['vehicle_turo_listing_audits', 'vehicle_turo_listings', 'turo_import_errors', 'turo_import_batches', 'fleet_vehicles', 'vehicle_specs', 'vehicle_models', 'vehicle_makes', 'vehicle_trim_levels'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_makes') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_models') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_make_id INTEGER, name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_specs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_model_id INTEGER, model_year INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_trim_levels') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150), vehicle_spec_id INTEGER, vehicle_trim_level_id INTEGER, vin VARCHAR(32) NULL, license_plate VARCHAR(32) NULL, sort_order INTEGER DEFAULT 0, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NOT NULL, turo_vehicle_id VARCHAR(80) NOT NULL UNIQUE, source_system VARCHAR(40) DEFAULT \'turo\', listing_url VARCHAR(255) NULL, daily_rate DECIMAL(10,2) NULL, is_active INTEGER DEFAULT 1, listed_at DATETIME NULL, unlisted_at DATETIME NULL, mapping_note TEXT NULL, mapped_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listing_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_turo_listing_id INTEGER NULL, action VARCHAR(40), turo_vehicle_id VARCHAR(80), old_fleet_vehicle_id INTEGER NULL, new_fleet_vehicle_id INTEGER NULL, note TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source_filename VARCHAR(190), started_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER NOT NULL, raw_table VARCHAR(80) NULL, raw_row_id INTEGER NULL, row_number INTEGER NULL, error_code VARCHAR(120), message TEXT, raw_payload TEXT NULL, created_at DATETIME NULL, resolved_at DATETIME NULL, resolution_note TEXT NULL, reconciliation_status VARCHAR(40) NULL, reconciliation_result_code VARCHAR(80) NULL, reconciliation_message TEXT NULL, reconciled_at DATETIME NULL, reconciled_trip_id INTEGER NULL, updated_at DATETIME NULL)');
    }

    private function seedData(): void
    {
        $this->connection->table('vehicle_makes')->insert(['id' => 1, 'name' => 'Tesla']);
        $this->connection->table('vehicle_models')->insert(['id' => 1, 'vehicle_make_id' => 1, 'name' => 'Model Y']);
        $this->connection->table('vehicle_specs')->insert(['id' => 1, 'vehicle_model_id' => 1, 'model_year' => 2026]);
        $this->connection->table('vehicle_trim_levels')->insert(['id' => 1, 'name' => 'Long Range']);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 8, 'fleet_code' => 'Spaceship08', 'display_name' => 'Spaceship08', 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vin' => 'VIN000008', 'license_plate' => 'ABC008', 'sort_order' => 8, 'deleted_at' => null],
            ['id' => 9, 'fleet_code' => 'Spaceship09', 'display_name' => 'Spaceship09', 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vin' => 'VIN000009', 'license_plate' => 'ABC009', 'sort_order' => 9, 'deleted_at' => null],
        ]);
        $this->connection->table('turo_import_batches')->insertBatch([
            ['id' => 1, 'source_filename' => 'july-a.csv', 'started_at' => '2026-07-18 08:00:00'],
            ['id' => 2, 'source_filename' => 'july-b.csv', 'started_at' => '2026-07-19 08:00:00'],
        ]);
        $this->insertIssue(1, 'turo-009', '2026 Tesla Model Y Spaceship-009');
        $this->insertIssue(2, 'turo-009', '2026 Tesla Model Y Spaceship-009', [], 2);
        $this->insertIssue(3, 'turo-plate', 'Tesla Model Y', ['license_plate' => 'ABC008']);
    }

    private function insertIssue(int $id, string $turoVehicleId, string $vehicleName, array $extraPayload = [], int $batchId = 1): void
    {
        $payload = array_merge(['vehicle_id' => $turoVehicleId, 'vehicle_name' => $vehicleName, 'trip_id' => 'trip-' . $id], $extraPayload);
        $this->connection->table('turo_import_errors')->insert([
            'id' => $id,
            'turo_import_batch_id' => $batchId,
            'row_number' => $id + 1,
            'error_code' => 'vehicle_unmatched',
            'message' => 'Trip imported, but no fleet vehicle could be matched.',
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => '2026-07-19 08:0' . $id . ':00',
            'resolved_at' => null,
        ]);
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
