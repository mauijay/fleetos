<?php

namespace Tests\Support;

use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleWheelDamageService;
use CodeIgniter\Database\BaseConnection;

final class VehicleWheelRepairFixture
{
    public readonly VehicleDamageRepairService $work;
    public readonly VehicleDamageRepairRepository $repairs;
    public readonly VehicleDamageRepository $items;
    public readonly VehicleWheelDamageService $wheels;

    public function __construct(public readonly BaseConnection $db)
    {
        $this->work = new VehicleDamageRepairService($db);
        $this->repairs = new VehicleDamageRepairRepository($db);
        $this->items = new VehicleDamageRepository($db);
        $this->wheels = new VehicleWheelDamageService($db);
    }

    public function data(string $wheel = 'LF', array $changes = []): array
    {
        $entry = $this->wheels->entry(1, 10, $wheel);
        return array_replace(['wheel_code' => $wheel, 'command_uuid' => VehicleDamageRepairService::commandKey(),
            'expected_wheel_state' => $entry['state']['fingerprint'], 'condition_id' => $entry['condition']['id'] ?? null,
            'expected_condition_state' => $entry['state']['candidates'][0]['fingerprint'] ?? null,
            'discovered_at' => '2026-10-01 08:00:00', 'occurred_at' => '2026-10-01 08:00:00',
            'severity_code' => 'cosmetic', 'note' => 'Synthetic wheel rash at 2 o\'clock.'], $changes);
    }

    public static function requireSuccess(array $receipt): array
    {
        if (! $receipt['success']) {
            throw new \RuntimeException(json_encode($receipt, JSON_THROW_ON_ERROR));
        }
        return $receipt;
    }

    public function create(string $wheel, array $changes = []): array
    {
        return self::requireSuccess($this->wheels->createNew(1, 10, $this->data($wheel, $changes), 7));
    }

    public function job(array $ids): int
    {
        return (int) self::requireSuccess($this->work->createJob(1, 10, array_replace(VehicleDamageRepairDatabaseFixture::creation($this->db, $ids), ['category_code' => 'wheel_tire', 'summary' => 'Synthetic batch wheel visit']), 7))['id'];
    }

    public function command(int $job, array $changes = []): array
    {
        return $changes + ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => $this->repairs->job(1, 10, $job)['version']];
    }

    public function start(int $job): void
    {
        self::requireSuccess($this->work->start(1, 10, $job, $this->command($job, ['started_at' => '2026-10-01 09:00:00']), 7));
    }

    public function member(int $job, int $condition): array
    {
        return array_find($this->repairs->members(1, 10, $job), static fn (array $member): bool => (int) $member['vehicle_damage_item_id'] === $condition);
    }

    public function confirmation(int $job, int $condition, string $time = '2026-10-01 10:00:00'): array
    {
        return $this->command($job, ['membership_id' => $this->member($job, $condition)['id'],
            'expected_condition_state' => $this->repairs->conditionFingerprint($this->items->item(1, 10, $condition)),
            'confirmed' => 1, 'inspected_at' => $time, 'inspection_note' => 'Synthetic physical inspection.']);
    }

    public function reopening(int $job, int $condition): array
    {
        return $this->command($job, ['job_id' => $job, 'membership_id' => $this->member($job, $condition)['id'],
            'condition_id' => $condition, 'expected_condition_state' => $this->repairs->conditionFingerprint($this->items->item(1, 10, $condition)),
            'confirmed' => 1, 'observed_at' => '2026-10-01 16:00:00', 'reason_category_code' => 'residual_damage', 'note' => 'Synthetic residual rash.']);
    }
}
