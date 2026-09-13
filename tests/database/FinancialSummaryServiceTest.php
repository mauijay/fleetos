<?php

use App\Repositories\ChargingCostRepository;
use App\Repositories\MaintenanceCostRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Fleet\FinancialActivityReadService;
use App\Services\Fleet\FinancialSummaryService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FinancialSummaryServiceTest extends CIUnitTestCase
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

    public function testCompanyScopedSummaryUsesOnlyApprovedSourcesAndExactFormula(): void
    {
        $summary = $this->service(['Guest reimbursement'])->period(1, '2026-09-01', '2026-10-01');

        $this->assertSame(486.0, $summary['realized_operating_revenue']);
        $this->assertSame(25.0, $summary['realized_recoveries']);
        $this->assertSame(194.42, $summary['recorded_operating_costs']);
        $this->assertSame(316.58, $summary['net_realized_operating_result']);
        $this->assertSame(80.0, $summary['forecast_host_payout']);
        $this->assertSame(6, $summary['diagnostics']['source_query_count']);
        $this->assertSame(1, $summary['diagnostics']['unsafe_turo_rows_excluded']);
        $this->assertSame(0, $summary['diagnostics']['unvalidated_recovery_rows_excluded']);

        $identities = array_map(static fn (array $row): string => $row['source_type'] . ':' . $row['source_id'], $summary['activities']);
        $this->assertSame($identities, array_values(array_unique($identities)));
        $this->assertNotContains('airport_delivery:1', $identities);
        $this->assertNotContains('airport_movement_workflow:1', $identities);
        foreach ([10, 11, 12, 13, 14] as $excludedTuroId) {
            $this->assertNotContains('turo_transaction:' . $excludedTuroId, $identities);
        }
    }

    public function testProductionRecoveryGateExcludesUnprovenLabelAndOtherCompanyData(): void
    {
        $companyA = $this->service()->period(1, '2026-09-01', '2026-10-01');
        $companyB = $this->service()->period(2, '2026-09-01', '2026-10-01');

        $this->assertSame(0.0, $companyA['realized_recoveries']);
        $this->assertSame(1, $companyA['diagnostics']['unvalidated_recovery_rows_excluded']);
        $this->assertSame([], $companyA['diagnostics']['validated_recovery_transaction_types']);
        $this->assertSame(999.0, $companyB['realized_operating_revenue']);
        $this->assertSame(486.0, $companyA['realized_operating_revenue']);
        $this->assertNotSame($companyA['recorded_operating_costs'], $companyB['recorded_operating_costs']);
    }

    /** @param list<string> $validatedRecoveryTypes */
    private function service(array $validatedRecoveryTypes = []): FinancialSummaryService
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

        return new FinancialSummaryService($activity);
    }

    private function resetSchema(): void
    {
        foreach (['insurance_policies', 'loans', 'airport_movement_workflows', 'airport_deliveries', 'airport_operations_expenses', 'airport_operations_runs', 'airport_turo_access_receipts', 'charging_sessions', 'maintenance_logs', 'operating_expenses', 'trip_month_allocations', 'turo_transactions_normalized', 'turo_transaction_raw', 'turo_trips_normalized', 'fleet_vehicles', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, trip_status_lookup_value_id INTEGER NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transaction_raw') . ' (id INTEGER PRIMARY KEY, raw_payload TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transactions_normalized') . ' (id INTEGER PRIMARY KEY, turo_transaction_raw_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, transaction_type VARCHAR(120), normalized_type VARCHAR(40), event_class VARCHAR(40), description VARCHAR(255) NULL, amount DECIMAL(10,2), transaction_date DATE)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_month_allocations') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, allocation_month DATE, allocated_host_payout_amount DECIMAL(10,2), is_forecast INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('operating_expenses') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_vehicle_id INTEGER NULL, turo_trip_normalized_id INTEGER NULL, expense_category_lookup_value_id INTEGER NULL, expense_date DATE, amount DECIMAL(10,2), business_purpose TEXT NULL, vendor VARCHAR(190) NULL, status_code VARCHAR(40), archived_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('maintenance_logs') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, maintenance_status_lookup_value_id INTEGER, service_on DATE, total_amount DECIMAL(10,2), description TEXT NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('charging_sessions') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER NULL, ended_at DATETIME NULL, cost_amount DECIMAL(10,2), charging_location VARCHAR(190) NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_turo_access_receipts') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_runs') . ' (id INTEGER PRIMARY KEY, company_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_operations_expenses') . ' (id INTEGER PRIMARY KEY, airport_operations_run_id INTEGER NULL, airport_turo_access_receipt_id INTEGER, expense_category VARCHAR(60), amount DECIMAL(10,2), expense_date DATE, business_purpose_note TEXT, accounting_status VARCHAR(60))');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_deliveries') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, parking_cost_amount DECIMAL(10,2), scheduled_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, actual_parking_cost_amount DECIMAL(10,2))');
        $this->connection->query('CREATE TABLE ' . $this->table('loans') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, monthly_payment DECIMAL(10,2))');
        $this->connection->query('CREATE TABLE ' . $this->table('insurance_policies') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, premium_amount DECIMAL(10,2))');
    }

    private function seed(): void
    {
        $this->connection->table('lookup_types')->insert(['id' => 1, 'code' => 'maintenance_status']);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'lookup_type_id' => 1, 'code' => 'completed', 'name' => 'Completed'],
            ['id' => 2, 'lookup_type_id' => 1, 'code' => 'scheduled', 'name' => 'Scheduled'],
            ['id' => 3, 'lookup_type_id' => 1, 'code' => 'canceled', 'name' => 'Canceled'],
            ['id' => 10, 'lookup_type_id' => 2, 'code' => 'supplies_consumables', 'name' => 'Supplies'],
        ]);
        $this->connection->table('fleet_vehicles')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('turo_trips_normalized')->insertBatch([['id' => 1, 'fleet_vehicle_id' => 1], ['id' => 2, 'fleet_vehicle_id' => 2]]);

        $transactions = [
            [1, 1, 1, 1, 'Trip earning', 'trip_earning', 'operating_revenue', '500.00', '$500.00'],
            [2, 2, 1, 1, 'Trip adjustment', 'trip_earning', 'operating_revenue', '-14.00', '-$14.00'],
            [3, 3, 1, 1, 'Guest reimbursement', 'reimbursement', 'reimbursement', '25.00', '$25.00'],
            [4, 4, 1, 1, 'Airport fee', 'fee', 'expense', '40.00', '$40.00'],
            [5, 5, 2, 2, 'Other company earning', 'trip_earning', 'operating_revenue', '999.00', '$999.00'],
            [6, 6, null, null, 'Unmatched earning', 'trip_earning', 'operating_revenue', '777.00', '$777.00'],
            [7, 7, 2, 1, 'Conflicting earning', 'trip_earning', 'operating_revenue', '333.00', '$333.00'],
            [8, 8, 1, 1, 'Bad persisted sign', 'trip_earning', 'operating_revenue', '12.34', '($12.34)'],
            [9, 9, 1, 1, 'End boundary', 'trip_earning', 'operating_revenue', '900.00', '$900.00'],
            [10, 10, 1, 1, 'Payment', 'payment', 'cash_movement', '700.00', '$700.00'],
            [11, 11, 1, 1, 'Airport fee', 'fee', 'expense', '40.00', '$40.00'],
            [12, 12, 1, 1, 'Adjustment fee', 'adjustment', 'adjustment', '-12.34', '($12.34)'],
            [13, 13, 1, 1, 'Tax', 'tax', 'tax', '20.00', '$20.00'],
            [14, 14, 1, 1, 'Failed Payment', 'failed_payment', 'other', '-50.00', '-$50.00'],
        ];
        foreach ($transactions as [$id, $rawId, $tripId, $vehicleId, $type, $normalized, $event, $amount, $rawAmount]) {
            $this->connection->table('turo_transaction_raw')->insert(['id' => $rawId, 'raw_payload' => json_encode(['transaction_type' => $type, 'amount' => $rawAmount], JSON_THROW_ON_ERROR)]);
            $this->connection->table('turo_transactions_normalized')->insert(['id' => $id, 'turo_transaction_raw_id' => $rawId, 'turo_trip_normalized_id' => $tripId, 'fleet_vehicle_id' => $vehicleId, 'transaction_type' => $type, 'normalized_type' => $normalized, 'event_class' => $event, 'amount' => $amount, 'transaction_date' => $id === 9 ? '2026-10-01' : '2026-09-15']);
        }

        $this->connection->table('trip_month_allocations')->insertBatch([
            ['id' => 1, 'turo_trip_normalized_id' => 1, 'fleet_vehicle_id' => 1, 'allocation_month' => '2026-09-01', 'allocated_host_payout_amount' => '80.00', 'is_forecast' => 1],
            ['id' => 2, 'turo_trip_normalized_id' => 2, 'fleet_vehicle_id' => 2, 'allocation_month' => '2026-09-01', 'allocated_host_payout_amount' => '90.00', 'is_forecast' => 1],
        ]);
        $this->connection->table('operating_expenses')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '18.42', 'business_purpose' => 'Cleaning supplies', 'status_code' => 'recorded', 'archived_at' => null],
            ['id' => 2, 'company_id' => 1, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '100.00', 'business_purpose' => 'Archived', 'status_code' => 'recorded', 'archived_at' => '2026-09-11 00:00:00'],
            ['id' => 3, 'company_id' => 2, 'expense_category_lookup_value_id' => 10, 'expense_date' => '2026-09-10', 'amount' => '200.00', 'business_purpose' => 'Other company', 'status_code' => 'recorded', 'archived_at' => null],
        ]);
        $this->connection->table('maintenance_logs')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 1, 'maintenance_status_lookup_value_id' => 1, 'service_on' => '2026-09-11', 'total_amount' => '100.00', 'deleted_at' => null],
            ['id' => 2, 'fleet_vehicle_id' => 1, 'maintenance_status_lookup_value_id' => 2, 'service_on' => '2026-09-11', 'total_amount' => '200.00', 'deleted_at' => null],
            ['id' => 3, 'fleet_vehicle_id' => 1, 'maintenance_status_lookup_value_id' => 3, 'service_on' => '2026-09-11', 'total_amount' => '300.00', 'deleted_at' => null],
            ['id' => 4, 'fleet_vehicle_id' => 1, 'maintenance_status_lookup_value_id' => 1, 'service_on' => '2026-09-11', 'total_amount' => '400.00', 'deleted_at' => '2026-09-12 00:00:00'],
        ]);
        $this->connection->table('charging_sessions')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 1, 'ended_at' => '2026-09-12 23:59:59', 'cost_amount' => '30.00', 'deleted_at' => null],
            ['id' => 2, 'fleet_vehicle_id' => 1, 'ended_at' => null, 'cost_amount' => '50.00', 'deleted_at' => null],
            ['id' => 3, 'fleet_vehicle_id' => 2, 'ended_at' => '2026-09-12 12:00:00', 'cost_amount' => '300.00', 'deleted_at' => null],
            ['id' => 4, 'fleet_vehicle_id' => 1, 'ended_at' => '2026-10-01 00:00:00', 'cost_amount' => '400.00', 'deleted_at' => null],
            ['id' => 5, 'fleet_vehicle_id' => 1, 'ended_at' => '2026-09-12 12:00:00', 'cost_amount' => '500.00', 'deleted_at' => '2026-09-12 13:00:00'],
        ]);
        $this->connection->table('airport_turo_access_receipts')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('airport_operations_runs')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('airport_operations_expenses')->insertBatch([
            ['id' => 1, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => 1, 'expense_category' => 'parking', 'amount' => '41.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Airport parking', 'accounting_status' => 'recorded'],
            ['id' => 2, 'airport_operations_run_id' => 2, 'airport_turo_access_receipt_id' => 2, 'expense_category' => 'parking', 'amount' => '410.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Other company', 'accounting_status' => 'recorded'],
            ['id' => 3, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => 1, 'expense_category' => 'parking', 'amount' => '55.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Unreviewed', 'accounting_status' => 'unreviewed'],
            ['id' => 4, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => null, 'expense_category' => 'parking', 'amount' => '2.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Run-owned', 'accounting_status' => 'reimbursable'],
            ['id' => 5, 'airport_operations_run_id' => null, 'airport_turo_access_receipt_id' => 1, 'expense_category' => 'parking', 'amount' => '3.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Receipt-owned', 'accounting_status' => 'reimbursed'],
            ['id' => 6, 'airport_operations_run_id' => 1, 'airport_turo_access_receipt_id' => 2, 'expense_category' => 'parking', 'amount' => '50.00', 'expense_date' => '2026-09-13', 'business_purpose_note' => 'Conflicting ownership', 'accounting_status' => 'recorded'],
        ]);
        $this->connection->table('airport_deliveries')->insert(['id' => 1, 'fleet_vehicle_id' => 1, 'parking_cost_amount' => '41.00', 'scheduled_at' => '2026-09-13 12:00:00']);
        $this->connection->table('airport_movement_workflows')->insert(['id' => 1, 'fleet_vehicle_id' => 1, 'actual_parking_cost_amount' => '41.00']);
        $this->connection->table('loans')->insert(['id' => 1, 'fleet_vehicle_id' => 1, 'monthly_payment' => '700.00']);
        $this->connection->table('insurance_policies')->insert(['id' => 1, 'fleet_vehicle_id' => 1, 'premium_amount' => '1200.00']);
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}
