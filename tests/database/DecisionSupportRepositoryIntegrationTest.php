<?php

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\DecisionSupport\PricingRecommendationService;
use App\Services\Fleet\DecisionSupport\RecommendationFactory;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\DecisionSupport;

/**
 * @internal
 */
final class DecisionSupportRepositoryIntegrationTest extends CIUnitTestCase
{
    private \CodeIgniter\Database\BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedMeasuredFleetData();
    }

    public function testPricingRecommendationsUseRepositoryBackedFleetMetrics(): void
    {
        $repository = new FleetIntelligenceRepository($this->connection);
        $revenue = new RevenueService($repository);
        $statistics = new FleetStatisticsService($repository, $revenue);
        $config = new DecisionSupport();
        $factory = new RecommendationFactory($config);

        $recommendations = (new PricingRecommendationService($statistics, $revenue, $config, $factory))
            ->recommendations(new DateTimeImmutable('2026-06-15 08:00:00'));
        $titles = array_map(static fn ($recommendation): string => $recommendation->title, $recommendations);

        $this->assertContains('Increase Fleet-001 pricing', $titles);
        $this->assertContains('Demand trend is increasing', $titles);
        $this->assertSame(PricingRecommendationService::class, $recommendations[0]->sourceService);
        $this->assertArrayHasKey('occupancy', $recommendations[0]->metrics);
    }

    private function resetSchema(): void
    {
        foreach (['trip_month_allocations', 'turo_trips_normalized', 'fleet_vehicles', 'vehicle_trim_levels', 'vehicle_specs', 'vehicle_models'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->connection->escapeIdentifiers($this->connection->prefixTable($table)));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_models') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_specs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_model_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_trim_levels') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) NOT NULL, is_premium INTEGER NOT NULL DEFAULT 0)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80) NOT NULL, display_name VARCHAR(150) NOT NULL, vehicle_spec_id INTEGER NOT NULL, vehicle_trim_level_id INTEGER NOT NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_month_allocations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, allocation_month DATE NOT NULL, allocated_trip_days DECIMAL(8,3) NOT NULL DEFAULT 0, allocated_billable_days DECIMAL(8,3) NOT NULL DEFAULT 0, allocated_gross_revenue_amount DECIMAL(10,2) NOT NULL DEFAULT 0, allocated_host_payout_amount DECIMAL(10,2) NOT NULL DEFAULT 0, allocated_delivery_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0, allocated_reimbursement_amount DECIMAL(10,2) NOT NULL DEFAULT 0, is_forecast INTEGER NOT NULL DEFAULT 0)');
    }

    private function seedMeasuredFleetData(): void
    {
        $this->connection->table('vehicle_models')->insert(['id' => 1, 'name' => 'Model Y']);
        $this->connection->table('vehicle_specs')->insert(['id' => 1, 'vehicle_model_id' => 1]);
        $this->connection->table('vehicle_trim_levels')->insertBatch([
            ['id' => 1, 'code' => 'premium', 'is_premium' => 1],
            ['id' => 2, 'code' => 'base', 'is_premium' => 0],
        ]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 1, 'fleet_code' => 'Fleet-001', 'display_name' => 'Fleet-001', 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'fleet_code' => 'Fleet-002', 'display_name' => 'Fleet-002', 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 2, 'deleted_at' => null],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 1, 'deleted_at' => null],
            ['id' => 2, 'deleted_at' => null],
            ['id' => 3, 'deleted_at' => null],
            ['id' => 4, 'deleted_at' => null],
            ['id' => 5, 'deleted_at' => null],
            ['id' => 6, 'deleted_at' => null],
        ]);
        $this->connection->table('trip_month_allocations')->insertBatch([
            $this->allocation(1, 1, '2026-04-01', 1, 1, 90),
            $this->allocation(2, 2, '2026-04-01', 1, 1, 80),
            $this->allocation(3, 1, '2026-05-01', 1, 1, 90),
            $this->allocation(4, 2, '2026-05-01', 1, 1, 80),
            $this->allocation(5, 1, '2026-06-01', 90, 90, 8010),
            $this->allocation(6, 2, '2026-06-01', 10, 10, 700),
        ]);
    }

    /** @return array<string, int|float|string> */
    private function allocation(int $tripId, int $vehicleId, string $month, float $tripDays, float $billableDays, float $payout): array
    {
        return [
            'turo_trip_normalized_id' => $tripId,
            'fleet_vehicle_id' => $vehicleId,
            'allocation_month' => $month,
            'allocated_trip_days' => $tripDays,
            'allocated_billable_days' => $billableDays,
            'allocated_gross_revenue_amount' => $payout,
            'allocated_host_payout_amount' => $payout,
            'allocated_delivery_fee_amount' => 0,
            'allocated_reimbursement_amount' => 0,
            'is_forecast' => 0,
        ];
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
