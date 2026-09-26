<?php

use App\Repositories\FleetVehicleRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Fleet\VehiclePerformanceReportService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class VehiclePerformanceReportServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['turo_transactions_normalized', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->createSchema();
        $this->seed();
    }

    public function testCurrentWindowsUseHonoluluCalendarBoundariesAndExcludeFutureRevenue(): void
    {
        $report = $this->service()->report(1, '2026-09-26');
        $rows = array_column($report['rows'], null, 'fleet_vehicle_id');

        $this->assertSame('2026-09-01', $report['month_start']);
        $this->assertSame('2026-01-01', $report['year_start']);
        $this->assertSame('2026-09-27', $report['to_date_exclusive']);
        $this->assertSame(175.25, $rows[1]['mtd_earnings']);
        $this->assertSame(275.25, $rows[1]['ytd_earnings']);
        $this->assertSame(325.25, $rows[1]['lifetime_earnings']);
        $this->assertSame(0.0, $rows[2]['mtd_earnings']);
        $this->assertSame(75.0, $rows[2]['ytd_earnings']);
        $this->assertSame(75.0, $rows[2]['lifetime_earnings']);
        $this->assertSame(0.0, $rows[3]['mtd_earnings']);
        $this->assertSame(0.0, $rows[3]['ytd_earnings']);
        $this->assertSame(0.0, $rows[3]['lifetime_earnings']);
        $this->assertSame(0.0, $rows[5]['lifetime_earnings']);
    }

    public function testOnlyRealizedOperatingRevenueForTheCompanyAndResolvedVehicleIsIncluded(): void
    {
        $report = $this->service()->report(1, '2026-09-26');
        $rows = array_column($report['rows'], null, 'fleet_vehicle_id');

        $this->assertSame(325.25, $rows[1]['lifetime_earnings']);
        $this->assertSame(75.0, $rows[2]['lifetime_earnings']);
        $this->assertArrayNotHasKey(9, $rows);
        $this->assertSame(0.0, $report['reconciliation']['lifetime_difference']);
        $this->assertSame(2, $report['diagnostics']['total_query_count']);
    }

    public function testHistoricalRevenueOutsideTheActiveRosterIsExplicitlyUnallocatedAndTotalsReconcile(): void
    {
        $report = $this->service()->report(1, '2026-09-26');
        $unallocated = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['is_unallocated']));

        $this->assertTrue($report['has_unallocated']);
        $this->assertCount(1, $unallocated);
        $this->assertSame('Unallocated / Unmatched', $unallocated[0]['vehicle_label']);
        $this->assertSame(20.0, $unallocated[0]['mtd_earnings']);
        $this->assertSame(20.0, $unallocated[0]['ytd_earnings']);
        $this->assertSame(20.0, $unallocated[0]['lifetime_earnings']);
        $this->assertSame(195.25, $report['totals']['mtd_earnings']);
        $this->assertSame(370.25, $report['totals']['ytd_earnings']);
        $this->assertSame(420.25, $report['totals']['lifetime_earnings']);
        $this->assertSame($report['totals']['lifetime_earnings'], round(array_sum(array_column($report['rows'], 'lifetime_earnings')), 2));
    }

    public function testRosterUsesExactInServiceDateAndRetainsNullWithoutManufacturingAValue(): void
    {
        $report = $this->service()->report(1, '2026-09-26');
        $rows = array_column($report['rows'], null, 'fleet_vehicle_id');

        $this->assertSame(4, $report['vehicle_count']);
        $this->assertSame('2025-12-15', $rows[1]['in_service_date']);
        $this->assertSame('2026-03-10', $rows[2]['in_service_date']);
        $this->assertNull($rows[3]['in_service_date']);
        $this->assertSame('2026-09-10', $rows[5]['in_service_date']);
        $this->assertSame('Zero Revenue', $rows[3]['vehicle_label']);
    }

    public function testSortsAreAllowlistedDeterministicAndKeepUnallocatedLast(): void
    {
        $default = $this->service()->report(1, '2026-09-26');
        $mtd = $this->service()->report(1, '2026-09-26', 'mtd', 'desc');
        $dates = $this->service()->report(1, '2026-09-26', 'in_service_date', 'desc');
        $invalid = $this->service()->report(1, '2026-09-26', 'amount); drop table', 'sideways');

        $this->assertSame([1, 2, 3, 5, null], array_column($default['rows'], 'fleet_vehicle_id'));
        $this->assertSame([1, 2, 3, 5, null], array_column($mtd['rows'], 'fleet_vehicle_id'));
        $this->assertSame([5, 2, 1, 3, null], array_column($dates['rows'], 'fleet_vehicle_id'));
        $this->assertSame('vehicle', $invalid['sort']);
        $this->assertSame('asc', $invalid['direction']);
    }

    public function testReadDoesNotMutateSourceTables(): void
    {
        $before = [
            'vehicles' => $this->connection->table('fleet_vehicles')->countAllResults(),
            'trips' => $this->connection->table('turo_trips_normalized')->countAllResults(),
            'transactions' => $this->connection->table('turo_transactions_normalized')->countAllResults(),
        ];

        $this->service()->report(1, '2026-09-26');

        $this->assertSame($before, [
            'vehicles' => $this->connection->table('fleet_vehicles')->countAllResults(),
            'trips' => $this->connection->table('turo_trips_normalized')->countAllResults(),
            'transactions' => $this->connection->table('turo_transactions_normalized')->countAllResults(),
        ]);
    }

    private function service(): VehiclePerformanceReportService
    {
        return new VehiclePerformanceReportService(
            new FleetVehicleRepository($this->connection),
            new TuroNormalizedTransactionRepository($this->connection),
        );
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), in_service_date DATE NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transactions_normalized') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, event_class VARCHAR(40), amount DECIMAL(10,2), transaction_date DATE)');
    }

    private function seed(): void
    {
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'fleet_number' => 1, 'fleet_code' => 'SS01', 'display_name' => 'Spaceship01', 'in_service_date' => '2025-12-15', 'deleted_at' => null],
            ['id' => 2, 'company_id' => 1, 'fleet_number' => 2, 'fleet_code' => 'SS02', 'display_name' => 'Added This Year', 'in_service_date' => '2026-03-10', 'deleted_at' => null],
            ['id' => 3, 'company_id' => 1, 'fleet_number' => 3, 'fleet_code' => 'ZERO', 'display_name' => 'Zero Revenue', 'in_service_date' => null, 'deleted_at' => null],
            ['id' => 4, 'company_id' => 1, 'fleet_number' => 4, 'fleet_code' => 'OLD', 'display_name' => 'Historical Vehicle', 'in_service_date' => '2024-01-01', 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 5, 'company_id' => 1, 'fleet_number' => 5, 'fleet_code' => 'NEW', 'display_name' => 'Added This Month', 'in_service_date' => '2026-09-10', 'deleted_at' => null],
            ['id' => 9, 'company_id' => 2, 'fleet_number' => 1, 'fleet_code' => 'OTHER', 'display_name' => 'Other Company', 'in_service_date' => '2025-01-01', 'deleted_at' => null],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 10, 'fleet_vehicle_id' => 1],
            ['id' => 20, 'fleet_vehicle_id' => 2],
            ['id' => 40, 'fleet_vehicle_id' => 4],
            ['id' => 90, 'fleet_vehicle_id' => 9],
        ]);
        $this->connection->table('turo_transactions_normalized')->insertBatch([
            ['id' => 1, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '50.00', 'transaction_date' => '2025-12-31'],
            ['id' => 2, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '100.00', 'transaction_date' => '2026-01-01'],
            ['id' => 3, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '25.00', 'transaction_date' => '2026-09-01'],
            ['id' => 4, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '150.25', 'transaction_date' => '2026-09-26'],
            ['id' => 5, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '999.00', 'transaction_date' => '2026-09-27'],
            ['id' => 6, 'turo_trip_normalized_id' => 20, 'fleet_vehicle_id' => null, 'event_class' => 'operating_revenue', 'amount' => '75.00', 'transaction_date' => '2026-08-31'],
            ['id' => 7, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'forecast_host_payout', 'amount' => '500.00', 'transaction_date' => '2026-09-10'],
            ['id' => 8, 'turo_trip_normalized_id' => 10, 'fleet_vehicle_id' => 1, 'event_class' => 'reimbursement', 'amount' => '45.00', 'transaction_date' => '2026-09-10'],
            ['id' => 9, 'turo_trip_normalized_id' => 90, 'fleet_vehicle_id' => 9, 'event_class' => 'operating_revenue', 'amount' => '700.00', 'transaction_date' => '2026-09-10'],
            ['id' => 10, 'turo_trip_normalized_id' => null, 'fleet_vehicle_id' => null, 'event_class' => 'operating_revenue', 'amount' => '800.00', 'transaction_date' => '2026-09-10'],
            ['id' => 11, 'turo_trip_normalized_id' => 20, 'fleet_vehicle_id' => 1, 'event_class' => 'operating_revenue', 'amount' => '900.00', 'transaction_date' => '2026-09-10'],
            ['id' => 12, 'turo_trip_normalized_id' => 40, 'fleet_vehicle_id' => 4, 'event_class' => 'operating_revenue', 'amount' => '20.00', 'transaction_date' => '2026-09-15'],
        ]);
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}
