<?php

namespace Tests\Support;

use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

abstract class VehicleDamageRepairTestCase extends CIUnitTestCase
{
    protected BaseConnection $connection;
    protected VehicleDamageRepairRepository $repairs;
    protected VehicleDamageRepairService $work;
    protected VehicleDamageService $damage;
    protected VehicleDamageRepository $conditions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        VehicleDamageRepairDatabaseFixture::prepare($this->connection);
        $this->repairs = new VehicleDamageRepairRepository($this->connection);
        $this->work = new VehicleDamageRepairService($this->connection);
        $this->damage = new VehicleDamageService($this->connection);
        $this->conditions = new VehicleDamageRepository($this->connection);
    }

    protected function condition(int $company = 1, int $vehicle = 10): int
    {
        return VehicleDamageRepairDatabaseFixture::condition($this->connection, $company, $vehicle);
    }

    protected function createWork(array $ids, array $extra = []): int
    {
        $result = $this->work->createJob(1, 10, array_replace(VehicleDamageRepairDatabaseFixture::creation($this->connection, $ids), $extra), 7);
        $this->success($result);
        return (int) $result['id'];
    }

    protected function command(int $job, array $extra = []): array
    {
        return $extra + ['expected_version' => $this->repairs->job(1, 10, $job)['version'], 'command_key' => VehicleDamageRepairService::commandKey()];
    }

    protected function state(int $id): string
    {
        return $this->repairs->conditionFingerprint($this->conditions->item(1, 10, $id));
    }

    protected function success(array $result): void
    {
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    protected function failure(array $result, string $message = ''): void
    {
        $this->assertFalse($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        if ($message !== '') {
            $this->assertStringContainsString($message, implode(' ', $result['errors']));
        }
    }

    protected function counts(): array
    {
        $counts = [];
        foreach (['vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'vehicle_damage_item_events', 'audit_logs'] as $table) {
            $counts[$table] = $this->connection->table($table)->countAllResults();
        }
        return $counts;
    }
}
