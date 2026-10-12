<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use App\Services\Fleet\VehicleWheelDamageService;
use App\Services\Fleet\VehicleWheelEpisodeGuard;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairDatabaseFixture;
use Tests\Support\VehicleDamageRepairMariaDbFixture;

final class VehicleWheelOccurrenceMariaDbConcurrencyTest extends CIUnitTestCase
{
    public static function races(): iterable
    {
        foreach (['two_create', 'create_vs_worsening', 'worsening_vs_repair', 'duplicate_replay', 'attach_vs_worsening'] as $race) {
            yield $race => [$race];
        }
    }

    #[DataProvider('races')]
    public function testRealVehicleLockContention(string $race): void
    {
        $fixture = new VehicleDamageRepairMariaDbFixture(through: 36);
        $db = $fixture->db;
        try {
            $service = new VehicleWheelDamageService($db);
            $items = new VehicleDamageRepository($db);
            $data = $this->data($service);
            $created = null;
            if (! in_array($race, ['two_create', 'duplicate_replay'], true)) {
                $created = $service->createNew(1, 10, $data, 7);
                self::assertTrue($created['success'], json_encode($created));
                $data = $this->data($service);
            }
            $work = new Work($db);
            $repair = null;
            if ($race === 'worsening_vs_repair') {
                $job = $work->createJob(1, 10, VehicleDamageRepairDatabaseFixture::creation($db, [$created['condition_id']]), 7);
                self::assertTrue($job['success'], json_encode($job));
                self::assertTrue($work->start(1, 10, $job['id'], ['command_key' => Work::commandKey(), 'expected_version' => 1, 'started_at' => '2026-10-01T11:00'], 7)['success']);
                $repository = new VehicleDamageRepairRepository($db);
                $repair = ['id' => $job['id'], 'data' => ['command_key' => Work::commandKey(), 'expected_version' => $repository->job(1, 10, $job['id'])['version'],
                    'membership_id' => $repository->members(1, 10, $job['id'])[0]['id'], 'expected_condition_state' => (new VehicleWheelEpisodeGuard($db))->conditionState($items->item(1, 10, $created['condition_id'])),
                    'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic real physical repair race']];
            }
            $memberBefore = $created === null ? null : (new VehicleDamageOccurrenceRepository($db))->membership(1, 10, $created['membership_id']);
            $operation = match ($race) {
                'worsening_vs_repair' => 'wheelOccurrenceWorsen', 'attach_vs_worsening' => 'wheelOccurrenceAttach', default => 'wheelOccurrenceCreate',
            };
            $arguments = $race === 'attach_vs_worsening'
                ? [1, 10, $created['condition_id'], $created['membership_id'], ['command_uuid' => Work::commandKey()], 7]
                : [1, 10, $data, 7];
            $db->transBegin();
            $items->lockVehicle(1, 10);
            $result = $fixture->contendUntilCommit($operation, $arguments, function (\Closure $wait) use ($race, $db, $service, $work, $repair, $data): array {
                $wait();
                $winnerData = $race === 'duplicate_replay' ? $data : array_replace($data, ['command_uuid' => Work::commandKey()]);
                $winner = match ($race) {
                    'worsening_vs_repair' => $work->confirmConditionRepaired(1, 10, $repair['id'], $repair['data'], 7),
                    'create_vs_worsening', 'attach_vs_worsening' => $service->recordWorsening(1, 10, array_replace($winnerData, ['severity_code' => 'moderate', 'note' => 'Synthetic winning worsening.']), 7),
                    default => $service->createNew(1, 10, $winnerData, 7),
                };
                if ($winner['success']) {
                    $db->transCommit();
                } else {
                    $db->transRollback();
                }
                return $winner;
            });
            self::assertTrue($result['blocked']);
            self::assertNotEmpty($result['locks']);
            self::assertTrue($result['winner']['success']);
            $contender = $result['result'];
            self::assertSame(in_array($race, ['duplicate_replay', 'attach_vs_worsening'], true), $contender['success'], json_encode($result));
            if ($race === 'duplicate_replay') {
                self::assertTrue($contender['replayed']);
                self::assertSame($result['winner']['membership_id'], $contender['membership_id']);
                self::assertSame(1, $db->table('vehicle_damage_incidents')->countAllResults());
                self::assertSame(1, $db->table('vehicle_damage_incident_items')->countAllResults());
                self::assertSame(1, $db->table('audit_logs')->like('new_values', $data['command_uuid'])->countAllResults());
            } elseif ($race === 'attach_vs_worsening') {
                self::assertSame($memberBefore, (new VehicleDamageOccurrenceRepository($db))->membership(1, 10, $created['membership_id']));
                self::assertSame($created['condition_event_id'], $contender['condition_event_id']);
                self::assertCount(1, $contender['evidence_ids']);
            } else {
                self::assertSame($race === 'two_create' ? 1 : ($race === 'worsening_vs_repair' ? 1 : 2), $db->table('vehicle_damage_incident_items')->countAllResults());
                self::assertSame(0, $db->table('audit_logs')->like('new_values', $arguments[2]['command_uuid'])->countAllResults());
                if ($race === 'two_create') {
                    self::assertSame(1, $db->table('vehicle_damage_items')->countAllResults());
                }
            }
        } finally {
            foreach ($db->table('files')->like('path', 'damage-evidence/company-1/vehicle-10/', 'after')->get()->getResultArray() as $file) {
                $path = rtrim((new \Config\Paths())->writableDirectory, '/\\') . '/uploads/' . $file['path'];
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $fixture->close();
        }
    }

    private function data(VehicleWheelDamageService $service): array
    {
        $entry = $service->entry(1, 10, 'LF');
        return ['wheel_code' => 'LF', 'command_uuid' => Work::commandKey(), 'expected_wheel_state' => $entry['state']['fingerprint'],
            'condition_id' => $entry['condition']['id'] ?? null, 'expected_condition_state' => $entry['state']['candidates'][0]['fingerprint'] ?? null,
            'discovered_at' => '2026-10-01 10:00:00', 'severity_code' => 'cosmetic', 'note' => 'Synthetic real race report.'];
    }
}
