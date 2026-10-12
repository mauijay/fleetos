<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use App\Services\Fleet\VehicleDamageService as Damage;
use App\Services\Fleet\VehicleWheelEpisodeGuard as Guard;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageRepairMariaDbFixture as Fixture;

/** Mandatory independent connections with an observed live InnoDB vehicle-row wait. */
final class VehicleWheelEpisodeMariaDbConcurrencyTest extends CIUnitTestCase
{
    public static function races(): array
    {
        return [['two_create'], ['create_vs_worsen'], ['repair_vs_create'], ['reopen_first'], ['new_episode_first'], ['correction_first'], ['create_before_correction'], ['incident_create']];
    }

    #[DataProvider('races')]
    public function testOnlyOneValidWriterCommits(string $race): void
    {
        $f = new Fixture(through: 36);
        try {
            $db = $f->db;
            $damage = new Damage($db);
            $items = new VehicleDamageRepository($db);
            $guard = new Guard($db);
            $data = VehicleWheelEpisodeGuardTest::data();
            $id = 0;
            if (in_array($race, ['create_vs_worsen', 'repair_vs_create', 'reopen_first', 'new_episode_first'], true)) {
                $initial = $damage->create(1, 10, $data, 7);
                $this->assertTrue($initial['success'], json_encode($initial));
                $id = (int) $initial['id'];
                if (in_array($race, ['reopen_first', 'new_episode_first'], true)) {
                    $this->assertTrue($damage->transitionStatus(1, 10, $id, 'repaired', 'Synthetic prior repair', 7)['success']);
                }
            } elseif (in_array($race, ['correction_first', 'create_before_correction'], true)) {
                $initial = $damage->create(1, 10, array_replace($data, ['panel_code' => null]), 7);
                $this->assertTrue($initial['success'], json_encode($initial));
                $id = (int) $initial['id'];
            }
            $work = new Work($db);
            $repair = [];
            if ($race === 'repair_vs_create') {
                $job = $work->createJob(1, 10, \Tests\Support\VehicleDamageRepairDatabaseFixture::creation($db, [$id]), 7);
                $this->assertTrue($job['success'], json_encode($job));
                $repository = new VehicleDamageRepairRepository($db);
                $started = $work->start(1, 10, (int) $job['id'], ['command_key' => Work::commandKey(), 'expected_version' => 1, 'started_at' => '2026-10-01T11:00'], 7);
                $this->assertTrue($started['success'], json_encode($started));
                $repair = ['job' => (int) $job['id'], 'data' => ['command_key' => Work::commandKey(), 'expected_version' => $repository->job(1, 10, (int) $job['id'])['version'],
                    'membership_id' => $repository->members(1, 10, (int) $job['id'])[0]['id'], 'expected_condition_state' => $guard->conditionState($items->item(1, 10, $id)),
                    'confirmed' => 1, 'inspected_at' => '2026-10-02T10:00', 'inspection_note' => 'Synthetic race physical repair confirmation']];
            }
            $reopen = ['command_key' => Work::commandKey(), 'expected_condition_state' => $id > 0 ? $guard->conditionState($items->item(1, 10, $id)) : '',
                'confirmed' => 1, 'observed_at' => date('Y-m-d H:i:s'), 'reason_category_code' => 'repair_failure', 'note' => 'Synthetic reopening race'];
            $correction = $data + ['note' => 'Synthetic wheel classification race', 'expected_condition_state' => $reopen['expected_condition_state']];
            $operation = match ($race) {
                'new_episode_first' => 'reopenCondition', 'create_before_correction' => 'wheelCorrect', 'incident_create' => 'wheelIncidentCreate', default => 'wheelCreate'
            };
            $arguments = match ($operation) {
                'reopenCondition' => [1, 10, $id, $reopen, 7],
                'wheelCorrect' => [1, 10, $id, $correction, 7],
                'wheelIncidentCreate' => [1, 10, \Tests\Support\VehicleDamageOccurrenceFixture::incident(), 7],
                default => [1, 10, $data, 7],
            };
            $db->transBegin();
            $items->lockVehicle(1, 10);
            $contender = $f->contendUntilCommit($operation, $arguments, function (\Closure $wait) use ($db, $damage, $work, $repair, $race, $id, $data, $reopen, $correction): array {
                // Worker takes its pre-lock snapshot while the winner holds only the vehicle lock.
                $wait();
                $winner = match ($race) {
                    'create_vs_worsen' => $damage->worsen(1, 10, $id, ['severity_code' => 'moderate', 'note' => 'Synthetic winner worsening'], 7),
                    'repair_vs_create' => $work->confirmConditionRepaired(1, 10, $repair['job'], $repair['data'], 7),
                    'reopen_first' => $damage->reopenRepairedCondition(1, 10, $id, $reopen, 7),
                    'correction_first' => $damage->correctDetails(1, 10, $id, $correction, 7),
                    default => $damage->create(1, 10, $data, 7),
                };
                if (! $winner['success']) {
                    $db->transRollback();
                    return $winner;
                }
                $db->transCommit();
                return $winner;
            });
            $this->assertTrue($contender['blocked'], 'Independent connection must enter an actual InnoDB vehicle lock wait.');
            $this->assertTrue($contender['winner']['success']);
            $this->assertFalse($contender['result']['success'], json_encode($contender));
            $this->assertNotEmpty($contender['locks']);
            $state = $guard->resolve(1, 10, $data['panel_code']);
            $this->assertSame($race === 'repair_vs_create' ? 'none' : 'one', $state['state']);
            $this->assertSame('valid', $state['alias_integrity']);
            if ($race === 'repair_vs_create') {
                $later = $damage->create(1, 10, $data + ['expected_wheel_state' => $state['fingerprint']], 7);
                $this->assertTrue($later['success'], json_encode($later));
                $this->assertNotSame($id, $later['id']);
            }
            $this->assertSame([], $db->table('vehicle_damage_incidents')->get()->getResultArray(), 'Rejected incident must not retain headers, memberships or audits.');
            $this->assertSame(0, $db->table('vehicle_damage_incident_items')->countAllResults());
        } finally {
            $f->close();
        }
    }
}
