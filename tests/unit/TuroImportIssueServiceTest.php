<?php

use App\Repositories\TuroImportErrorRepository;
use App\Repositories\VehicleTuroListingRepository;
use App\Services\Turo\TuroImportIssueService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class TuroImportIssueServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroImportIssueService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedIssues();
        $this->service = new TuroImportIssueService(new TuroImportErrorRepository($this->connection), new VehicleTuroListingRepository($this->connection));
    }

    public function testDefaultReviewListsOnlyUnresolvedIssues(): void
    {
        $review = $this->service->review();

        $this->assertSame(2, $review['total']);
        $this->assertFalse($review['is_empty']);
        $this->assertSame([3, 1], array_column($review['issues'], 'id'));
        $this->assertSame('No FleetOS vehicle matches Turo vehicle ID turo-123.', $review['issues'][0]['plain_message']);
    }

    public function testResolvedIssuesAreExcludedFromDefaultView(): void
    {
        $defaultReview = $this->service->review();
        $resolvedReview = $this->service->review(['status' => 'resolved']);

        $this->assertNotContains(2, array_column($defaultReview['issues'], 'id'));
        $this->assertSame([2], array_column($resolvedReview['issues'], 'id'));
        $this->assertSame('Resolved', $resolvedReview['issues'][0]['resolution_status']);
    }

    public function testSeverityFilteringWorks(): void
    {
        $review = $this->service->review(['severity' => 'warning']);

        $this->assertSame(1, $review['total']);
        $this->assertSame('warning', $review['issues'][0]['severity_code']);
        $this->assertSame('vehicle_unmatched', $review['issues'][0]['error_code']);
    }

    public function testIssueCanBeMarkedResolvedWithNoteAndTimestamp(): void
    {
        $this->assertTrue($this->service->resolve(1, 'Corrected the CSV export and reimported.'));

        $issue = $this->connection->table('turo_import_errors')->where('id', 1)->get()->getRowArray();
        $this->assertNotNull($issue['resolved_at']);
        $this->assertSame('Corrected the CSV export and reimported.', $issue['resolution_note']);
        $this->assertNotNull($issue['updated_at']);
        $this->assertSame(1, $this->service->review()['total']);
    }

    public function testResolvedIssueCanBeReopenedWithNote(): void
    {
        $this->assertTrue($this->service->reopen(2, 'Still visible in the latest Turo export.'));

        $issue = $this->connection->table('turo_import_errors')->where('id', 2)->get()->getRowArray();
        $this->assertNull($issue['resolved_at']);
        $this->assertSame('Still visible in the latest Turo export.', $issue['resolution_note']);
        $this->assertSame(3, $this->service->review()['total']);
    }

    public function testCommandCenterAlertCountsUnresolvedErrorsAndWarnings(): void
    {
        $summary = $this->service->attentionSummary();

        $this->assertSame(1, $summary['unresolved_errors']);
        $this->assertSame(1, $summary['unresolved_warnings']);
        $this->assertSame(2, $summary['total_unresolved']);
        $this->assertTrue($summary['has_unresolved']);
    }

    public function testEmptyStateIsReportedWhenFiltersMatchNothing(): void
    {
        $review = $this->service->review(['category' => 'allocation_failed']);

        $this->assertSame(0, $review['total']);
        $this->assertTrue($review['is_empty']);
        $this->assertSame([], $review['issues']);
    }

    public function testInvalidResolutionRequestsAreHandledSafely(): void
    {
        $this->assertFalse($this->service->resolve(0, 'No-op'));
        $this->assertFalse($this->service->resolve(999, 'No matching issue'));
        $this->assertFalse($this->service->reopen(999, 'No matching issue'));
    }

    private function resetSchema(): void
    {
        foreach (['vehicle_turo_listings', 'fleet_vehicles', 'turo_import_errors', 'turo_import_batches', 'lookup_values'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, type_code VARCHAR(80), code VARCHAR(80), name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER NOT NULL, turo_vehicle_id VARCHAR(80) NOT NULL UNIQUE, is_active INTEGER DEFAULT 1)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source_filename VARCHAR(190), source_hash VARCHAR(128), row_count INTEGER DEFAULT 0, started_at DATETIME NULL, completed_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER NOT NULL, severity_lookup_value_id INTEGER NULL, raw_table VARCHAR(80) NULL, raw_row_id INTEGER NULL, row_number INTEGER NULL, error_code VARCHAR(120) NOT NULL, field_name VARCHAR(120) NULL, message TEXT NOT NULL, raw_payload TEXT NULL, created_at DATETIME NULL, resolved_at DATETIME NULL, resolution_note TEXT NULL, updated_at DATETIME NULL)');
    }

    private function seedIssues(): void
    {
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'type_code' => 'import_error_severity', 'code' => 'error', 'name' => 'Error'],
            ['id' => 2, 'type_code' => 'import_error_severity', 'code' => 'warning', 'name' => 'Warning'],
        ]);
        $this->connection->table('turo_import_batches')->insert([
            'id' => 10,
            'source_filename' => 'turo-trips-july.csv',
            'source_hash' => 'hash-1',
            'row_count' => 3,
            'started_at' => '2026-07-19 08:00:00',
            'completed_at' => '2026-07-19 08:02:00',
        ]);
        $this->connection->table('turo_import_errors')->insertBatch([
            $this->issue(1, 1, 'missing_trip_id', 'Trip row is missing a trip or reservation id.', ['vehicle_id' => 'turo-999', 'guest_name' => 'Missing Id Guest'], null),
            $this->issue(2, 2, 'duplicate_trip_in_file', 'This trip id already appeared earlier.', ['trip_id' => 'trip-2', 'vehicle_id' => 'turo-222'], '2026-07-19 09:00:00'),
            $this->issue(3, 2, 'vehicle_unmatched', 'Trip imported, but no fleet vehicle could be matched.', ['trip_id' => 'trip-3', 'vehicle_id' => 'turo-123', 'guest_name' => 'A Guest', 'starts_at' => '2026-07-20 10:00:00', 'ends_at' => '2026-07-22 10:00:00', 'host_payout' => '$220.00'], null),
        ]);
    }

    /** @return array<string, mixed> */
    private function issue(int $id, int $severityId, string $code, string $message, array $payload, ?string $resolvedAt): array
    {
        return [
            'id' => $id,
            'turo_import_batch_id' => 10,
            'severity_lookup_value_id' => $severityId,
            'row_number' => $id + 1,
            'error_code' => $code,
            'field_name' => null,
            'message' => $message,
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => '2026-07-19 08:0' . $id . ':00',
            'resolved_at' => $resolvedAt,
            'resolution_note' => $resolvedAt === null ? null : 'Already checked.',
            'updated_at' => $resolvedAt,
        ];
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
