<?php

use App\Repositories\ChargingCostRepository;
use App\Repositories\FleetVehicleRepository;
use App\Repositories\MaintenanceCostRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Fleet\FinancialActivityReadService;
use App\Services\Fleet\FinancialSummaryService;
use App\Services\Fleet\VehicleFinancialSummaryService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class VehicleFinancialSummaryServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seed();
    }

    public function testVehicleAttributionAndUnallocatedCostsReconcileExactlyInCents(): void
    {
        $report = $this->service(['Guest reimbursement'])->period(1, '2026-09-01', '2026-10-01');
        $rows = array_column($report['vehicles'], null, 'fleet_vehicle_id');

        $this->assertSame([1, 2, 4], array_column($report['vehicles'], 'fleet_vehicle_id'));
        $this->assertSame('Spaceship01', $rows[1]['vehicle_label']);
        $this->assertSame(486.0, $rows[1]['realized_operating_revenue']);
        $this->assertSame(25.0, $rows[1]['realized_recoveries']);
        $this->assertSame(130.26, $rows[1]['recorded_operating_costs']);
        $this->assertSame(380.74, $rows[1]['net_realized_operating_result']);
        $this->assertSame(200.0, $rows[2]['realized_operating_revenue']);
        $this->assertSame(45.25, $rows[2]['recorded_operating_costs']);
        $this->assertSame(154.75, $rows[2]['net_realized_operating_result']);
        $this->assertSame(0.0, $rows[4]['net_realized_operating_result']);

        $this->assertSame(35.52, $report['fleet_wide_unallocated_costs']);
        $this->assertSame(175.51, $report['reconciliation']['vehicle_costs']);
        $this->assertSame(211.03, $report['reconciliation']['fleet_costs']);
        $this->assertSame(0.0, $report['reconciliation']['cost_difference']);
        $this->assertSame(686.0, $report['reconciliation']['vehicle_revenue']);
        $this->assertSame(0.0, $report['reconciliation']['unallocated_revenue']);
        $this->assertSame(0.0, $report['reconciliation']['revenue_difference']);
        $this->assertSame(499.97, $report['reconciliation']['fleet_net_result']);
        $this->assertSame(0.0, $report['reconciliation']['net_difference']);
        $this->assertSame(6, $report['diagnostics']['source_query_count']);
        $this->assertSame(7, $report['diagnostics']['total_query_count']);
        $this->assertSame(0, $report['diagnostics']['invalid_airport_allocations_excluded']);
    }

    public function testAcceptedVehicleAndUnallocatedCostFixtureReconcilesToRecordedTotal(): void
    {
        $this->connection->table('operating_expenses')->where('id', 1)->update(['amount' => '12.50']);
        $this->connection->table('operating_expenses')->where('id', 2)->update(['archived_at' => '2026-09-11 00:00:00']);
        $this->connection->table('operating_expenses')->where('id', 3)->update(['amount' => '18.42']);
        $this->connection->table('maintenance_logs')->where('id', 1)->update(['maintenance_status_lookup_value_id' => 2]);
        $this->connection->table('charging_sessions')->where('id', 1)->update(['ended_at' => null]);
        $this->connection->table('airport_operations_expenses')->update(['accounting_status' => 'pending']);

        $report = $this->service()->period(1, '2026-09-01', '2026-10-01');

        $this->assertSame(12.5, $report['reconciliation']['vehicle_costs']);
        $this->assertSame(18.42, $report['fleet_wide_unallocated_costs']);
        $this->assertSame(30.92, $report['reconciliation']['fleet_costs']);
        $this->assertSame(30.92, round($report['reconciliation']['vehicle_costs'] + $report['fleet_wide_unallocated_costs'], 2));
        $this->assertSame(0.0, $report['reconciliation']['cost_difference']);
    }

    public function testSourceSemanticsExcludeUnsafeCrossCompanyAndBoundaryRows(): void
    {
        $report = $this->service()->period(1, '2026-09-01', '2026-10-01');
        $rows = array_column($report['vehicles'], null, 'fleet_vehicle_id');
        $identities = array_map(static fn (array $row): string => $row['source_type'] . ':' . $row['source_id'], $report['fleet_summary']['activities']);

        $this->assertSame(0.0, $rows[1]['realized_recoveries']);
        $this->assertSame(1, $report['diagnostics']['unvalidated_recovery_rows_excluded']);
        $this->assertSame(486.0, $rows[1]['realized_operating_revenue']);
        $this->assertNotContains('turo_transaction:5', $identities);
        $this->assertNotContains('turo_transaction:6', $identities);
        $this->assertNotContains('turo_transaction:7', $identities);
        $this->assertNotContains('operating_expense:5', $identities);
        $this->assertNotContains(3, array_column($report['vehicles'], 'fleet_vehicle_id'));
        $this->assertNotContains(5, array_column($report['vehicles'], 'fleet_vehicle_id'));
    }

    public function testAirportSourceIsCountedOnceWhileExplicitAllocationsAndResidualReconcile(): void
    {
        $report = $this->service()->period(1, '2026-09-01', '2026-10-01');
        $airport = array_values(array_filter(
            $report['fleet_summary']['activities'],
            static fn (array $row): bool => $row['source_type'] === 'airport_operations_expense',
        ));

        $this->assertCount(2, $airport);
        $this->assertSame(['20.25', '10.25'], array_column($airport[0]['vehicle_allocations'], 'amount'));
        $this->assertSame('partially_allocated', $airport[0]['allocation_state']);
        $this->assertSame(15.5, round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $report['unallocated_activities'])) - 20.02, 2));
        $this->assertSame(46.0, $report['fleet_summary']['costs_by_source']['airport_operations_expense']);
        $this->assertNotContains('airport_delivery:1', array_map(static fn (array $row): string => $row['source_type'] . ':' . $row['source_id'], $report['fleet_summary']['activities']));
    }

    public function testSortingIsAllowlistedAndDeterministic(): void
    {
        $default = $this->service()->period(1, '2026-09-01', '2026-10-01');
        $costs = $this->service()->period(1, '2026-09-01', '2026-10-01', 'costs', 'desc');
        $vehicle = $this->service()->period(1, '2026-09-01', '2026-10-01', 'vehicle', 'asc');
        $invalid = $this->service()->period(1, '2026-09-01', '2026-10-01', 'drop table', 'sideways');

        $this->assertSame([1, 2, 4], array_column($default['vehicles'], 'fleet_vehicle_id'));
        $this->assertSame([1, 2, 4], array_column($costs['vehicles'], 'fleet_vehicle_id'));
        $this->assertSame([1, 2, 4], array_column($vehicle['vehicles'], 'fleet_vehicle_id'));
        $this->assertSame('net', $invalid['sort']);
        $this->assertSame('desc', $invalid['direction']);
    }

    public function testOverallocatedAirportSourceIsNotAttributedOrProrated(): void
    {
        $this->connection->table('airport_operations_expense_allocations')->where('id', 1)->update(['allocated_amount' => '50.00']);

        $report = $this->service()->period(1, '2026-09-01', '2026-10-01');
        $rows = array_column($report['vehicles'], null, 'fleet_vehicle_id');

        $this->assertSame(110.01, $rows[1]['recorded_operating_costs']);
        $this->assertSame(35.0, $rows[2]['recorded_operating_costs']);
        $this->assertSame(66.02, $report['fleet_wide_unallocated_costs']);
        $this->assertSame(0.0, $report['reconciliation']['cost_difference']);
        $this->assertSame(2, $report['diagnostics']['invalid_airport_allocations_excluded']);
    }

    /** @param list<string> $validatedRecoveryTypes */
    private function service(array $validatedRecoveryTypes = []): VehicleFinancialSummaryService
    {
        $activity = new FinancialActivityReadService(
            new TuroNormalizedTransactionRepository($this->connection),
            new TripMonthAllocationRepository($this->connection),
            new OperatingExpenseRepository($this->connection),
            new MaintenanceCostRepository($this->connection),
            new ChargingCostRepository($this->connection),
            new TuroAccessReimbursementRepository($this->connection),
            validatedRecoveryTransactionTypes: $validatedRecoveryTypes,
        );

        return new VehicleFinancialSummaryService(
            new FinancialSummaryService($activity),
            new FleetVehicleRepository($this->connection),
        );
    }

    private function resetSchema(): void
    {
        foreach (['airport_operations_expense_allocations', 'airport_operations_expenses', 'airport_operations_runs', 'airport_turo_access_receipts', 'charging_sessions', 'maintenance_logs', 'operating_expenses', 'trip_month_allocations', 'turo_transactions_normalized', 'turo_transaction_raw', 'turo_trips_normalized', 'fleet_vehicles', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, trip_status_lookup_value_id INTEGER NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transaction_raw') . ' (id INTEGER PRIMARY KEY, raw_payload TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transactions_normalized') . ' (id INTEGER PRIMARY KEY, turo_transaction_raw_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, transaction_type VARCHAR(120), normalized_type VARCHAR(40), event_class VARCHAR(40), description VARCHAR(255) NULL, amount DECIMAL(10,2), transaction_date DATE)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_month_allocations') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, allocation_month DATE, allocated_host_payout_amount DECIMAL(10,2), is_forecast INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('operating_expenses') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_vehicle_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, expense_category_lookup_value_id INTEGER NULL, expense_date DATE, amount DECIMAL(10,2), business_purpose TEXT NULL, vendor VARCHAR(190) NULL, status_code VARCHAR(40), archived_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('maintenance_logs') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, maintenance_status_lookup_value_id INTEGER, service_on DATE, total_amount DECIMAL(10,2), description TEXT NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('charging_sessions') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER NULL, ended_at DATETIME NULL, cost_amount DECIMAL(10,2), charging_location VARCHAR(190) NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_receipts') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_runs') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expenses') . ' (id INTEGER PRIMARY KEY, airport_operations_run_id INTEGER NULL, airport_turo_access_receipt_id INTEGER NULL, expense_category VARCHAR(60), amount DECIMAL(10,2), expense_date DATE, business_purpose_note TEXT, accounting_status VARCHAR(60))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expense_allocations') . ' (id INTEGER PRIMARY KEY, airport_operations_expense_id INTEGER, fleet_vehicle_id INTEGER NULL, allocation_method VARCHAR(40), allocated_amount DECIMAL(10,2), allocated_percentage DECIMAL(5,2) NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('lookup_types')->insertBatch([
            ['id' => 1, 'code' => 'maintenance_status'],
            ['id' => 2, 'code' => 'operating_expense_category'],
        ]);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'lookup_type_id' => 1, 'code' => 'completed', 'name' => 'Completed'],
            ['id' => 2, 'lookup_type_id' => 1, 'code' => 'scheduled', 'name' => 'Scheduled'],
            ['id' => 10, 'lookup_type_id' => 2, 'code' => 'supplies', 'name' => 'Supplies'],
        ]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'fleet_number' => 1, 'fleet_code' => 'SS01', 'display_name' => 'Spaceship01', 'deleted_at' => null],
            ['id' => 2, 'company_id' => 1, 'fleet_number' => 2, 'fleet_code' => 'SS02', 'display_name' => 'Spaceship02', 'deleted_at' => null],
            ['id' => 3, 'company_id' => 2, 'fleet_number' => 1, 'fleet_code' => 'OTHER', 'display_name' => 'Other company', 'deleted_at' => null],
            ['id' => 4, 'company_id' => 1, 'fleet_number' => 4, 'fleet_code' => 'ZERO', 'display_name' => 'Zero activity', 'deleted_at' => null],
            ['id' => 5, 'company_id' => 1, 'fleet_number' => 5, 'fleet_code' => 'OLD', 'display_name' => 'Deleted', 'deleted_at' => '2026-08-01 00:00:00'],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 1], ['id' => 2, 'fleet_vehicle_id' => 2], ['id' => 3, 'fleet_vehicle_id' => 3],
        ]);
        $transactions = [
            [1, 1, 1, 1, 'Trip earning', 'operating_revenue', '500.00', '$500.00', '2026-09-15'],
            [2, 2, 1, 1, 'Trip adjustment', 'operating_revenue', '-14.00', '-$14.00', '2026-09-15'],
            [3, 3, 2, null, 'Trip earning', 'operating_revenue', '200.00', '$200.00', '2026-09-15'],
            [4, 4, 1, 1, 'Guest reimbursement', 'reimbursement', '25.00', '$25.00', '2026-09-15'],
            [5, 5, 3, 3, 'Other earning', 'operating_revenue', '999.00', '$999.00', '2026-09-15'],
            [6, 6, null, null, 'Unmatched earning', 'operating_revenue', '777.00', '$777.00', '2026-09-15'],
            [7, 7, 2, 1, 'Conflicting earning', 'operating_revenue', '333.00', '$333.00', '2026-09-15'],
            [8, 8, 1, 1, 'End boundary', 'operating_revenue', '900.00', '$900.00', '2026-10-01'],
        ];
        foreach ($transactions as [$id, $rawId, $tripId, $vehicleId, $type, $event, $amount, $rawAmount, $date]) {
            $this->connection->table('turo_transaction_raw')->insert(['id' => $rawId, 'raw_payload' => json_encode(['transaction_type' => $type, 'amount' => $rawAmount], JSON_THROW_ON_ERROR)]);
            $this->connection->table('turo_transactions_normalized')->insert(['id' => $id, 'turo_transaction_raw_id' => $rawId, 'turo_trip_normalized_id' => $tripId, 'fleet_vehicle_id' => $vehicleId, 'transaction_type' => $type, 'normalized_type' => $event, 'event_class' => $event, 'amount' => $amount, 'transaction_date' => $date]);
        }
        $this->connection->table('operating_expenses')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'fleet_vehicle_id' => 1, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '10.01', 'business_purpose' => 'Vehicle supplies', 'status_code' => 'recorded', 'archived_at' => null],
            ['id' => 2, 'company_id' => 1, 'fleet_vehicle_id' => 2, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '5.00', 'business_purpose' => 'Vehicle supplies', 'status_code' => 'recorded', 'archived_at' => null],
            ['id' => 3, 'company_id' => 1, 'fleet_vehicle_id' => null, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '20.02', 'business_purpose' => 'Fleet supplies', 'status_code' => 'recorded', 'archived_at' => null],
            ['id' => 4, 'company_id' => 1, 'fleet_vehicle_id' => 1, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '50.00', 'business_purpose' => 'Archived', 'status_code' => 'recorded', 'archived_at' => '2026-09-11'],
            ['id' => 5, 'company_id' => 1, 'fleet_vehicle_id' => 3, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '99.00', 'business_purpose' => 'Wrong company vehicle', 'status_code' => 'recorded', 'archived_at' => null],
        ]);
        $this->connection->table('maintenance_logs')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 1, 'maintenance_status_lookup_value_id' => 1, 'service_on' => '2026-09-11', 'total_amount' => '100.00'],
            ['id' => 2, 'fleet_vehicle_id' => 2, 'maintenance_status_lookup_value_id' => 2, 'service_on' => '2026-09-11', 'total_amount' => '200.00'],
        ]);
        $this->connection->table('charging_sessions')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 2, 'ended_at' => '2026-09-12 10:00:00', 'cost_amount' => '30.00'],
            ['id' => 2, 'fleet_vehicle_id' => 1, 'ended_at' => null, 'cost_amount' => '40.00'],
        ]);
        $this->connection->table('airport_turo_access_receipts')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('airport_operations_runs')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('airport_operations_expenses')->insertBatch([
            ['id' => 1, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => 1, 'expense_category' => 'parking', 'amount' => '41.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Allocated airport expense', 'accounting_status' => 'recorded'],
            ['id' => 2, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => 1, 'expense_category' => 'parking', 'amount' => '5.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Unallocated airport expense', 'accounting_status' => 'recorded'],
            ['id' => 3, 'airport_operations_run_id' => 2, 'airport_turo_access_receipt_id' => 2, 'expense_category' => 'parking', 'amount' => '50.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Other company', 'accounting_status' => 'recorded'],
        ]);
        $this->connection->table('airport_operations_expense_allocations')->insertBatch([
            ['id' => 1, 'airport_operations_expense_id' => 1, 'fleet_vehicle_id' => 1, 'allocation_method' => 'exact', 'allocated_amount' => '20.25'],
            ['id' => 2, 'airport_operations_expense_id' => 1, 'fleet_vehicle_id' => 2, 'allocation_method' => 'exact', 'allocated_amount' => '10.25'],
        ]);
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}
