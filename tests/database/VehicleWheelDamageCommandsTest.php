<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot as Basis;
use App\Services\Fleet\VehicleDamageService;
use App\Services\Fleet\VehicleWheelIdentity;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelDamageCommandsTest extends CIUnitTestCase
{
    private ?VehicleWheelDamageFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->close();
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['corners', 'chain', 'invalid_create', 'invalid_existing', 'attribution', 'reuse', 'replay', 'bad_receipts', 'repair_boundary', 'basis', 'context'] as $case) {
                yield $engine . '-' . $case => [$engine, $case];
            }
        }
    }

    #[DataProvider('cases')]
    public function testExplicitCommands(string $engine, string $case): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $service = $f->service;
        $items = new VehicleDamageRepository($db);
        $initial = $f->schema->rows();
        if ($case === 'corners') {
            foreach (array_keys(VehicleWheelIdentity::choices()) as $wheel) {
                $data = $f->data($wheel);
                $receipt = $service->createNew(1, 10, $data, 7);
                self::assertTrue($receipt['success'], json_encode($receipt));
                $member = $f->member($receipt);
                $condition = $items->item(1, 10, $receipt['condition_id']);
                self::assertSame(VehicleWheelIdentity::panel($wheel), $condition['panel_code']);
                self::assertSame('wheel_tire', $condition['zone_code']);
                self::assertSame('wheel_curb_rash', $member['damage_type_code']);
                self::assertSame('new_damage', $member['effect_code']);
                self::assertSame($receipt['condition_event_id'], (int) $member['condition_event_id']);
                self::assertSame('created', $items->events(1, $receipt['condition_id'])[0]['event_code']);
                $basis = Basis::read($member['basis_snapshot']);
                self::assertSame('recorded', $basis['state']);
                self::assertSame(1, $basis['snapshot']['schema_version']);
                self::assertNull($basis['snapshot']['prior_condition']);
                self::assertNull($member['incident_occurred_at']);
                $this->reject(fn () => $service->createNew(1, 10, $f->data($wheel), 7));
            }
        } elseif ($case === 'invalid_create') {
            $data = $f->data();
            foreach ([['wheel_code' => 'XX'], ['panel_code' => 'rear_right_wheel_tire'], ['zone_code' => 'front'], ['damage_type_code' => 'scratch_scuff'],
                ['command_uuid' => 'bad'], ['expected_wheel_state' => str_repeat('0', 64)], ['expected_wheel_state' => null],
                ['trip_id' => 200], ['trip_id' => 101], ['trip_id' => 100, 'movement_event_id' => 1001],
                ['occurred_at' => '2026-10-02 10:00:00'], ['discovered_at' => '2026-02-30 10:00:00'], ['note' => ''], ['severity_code' => 'bogus'],
                ['attribution_type' => 'suspected_cause', 'trip_id' => 100], ['basis_snapshot' => '{}', 'note' => str_repeat('x', 2001)]] as $change) {
                $this->reject(fn () => $service->createNew(1, 10, array_replace($data, $change), 7));
            }
            $this->reject(fn () => $service->createNew(2, 10, $data, 7));
            $this->reject(fn () => $service->createNew(1, 20, $data, 7));
            $this->reject(fn () => $service->createNew(1, 10, $data, 0));
        } elseif ($case === 'chain') {
            $created = $service->createNew(1, 10, $f->data(), 7);
            self::assertTrue($created['success'], json_encode($created));
            $original = $items->item(1, 10, $created['condition_id']);
            $prior = $created;
            foreach (['cosmetic', 'severe'] as $severity) {
                $data = $f->data('LF', ['severity_code' => $severity, 'note' => "Deep 4-inch rash at 2 o'clock; light 3-inch scuff at 8 o'clock.", 'discovered_at' => '2026-10-02 10:00:00']);
                $receipt = $service->recordWorsening(1, 10, $data, 7);
                self::assertTrue($receipt['success'], json_encode($receipt));
                $member = $f->member($receipt);
                $basis = Basis::read($member['basis_snapshot'])['snapshot'];
                self::assertSame($prior['condition_event_id'], $basis['prior_condition']['latest_condition_event_id']);
                self::assertSame($prior['membership_id'], $basis['prior_occurrence']['incident_item_id']);
                self::assertSame($data['note'], $member['note']);
                self::assertSame('worsened', $member['effect_code']);
                self::assertSame($original['description'], $items->item(1, 10, $created['condition_id'])['description']);
                $replayRows = $f->schema->rows();
                self::assertTrue($service->recordWorsening(1, 10, $data, 7)['replayed']);
                self::assertSame($replayRows, $f->schema->rows());
                $prior = $receipt;
            }
            $before = $items->item(1, 10, $created['condition_id']);
            $data = $f->data('LF', ['observed_at' => '2026-10-03 09:17:00', 'severity_code' => null, 'note' => 'Inspected; same rash.']);
            $observed = $service->confirmUnchanged(1, 10, $data, 7);
            self::assertTrue($observed['success'], json_encode($observed));
            $event = $db->table('vehicle_damage_item_events')->where('id', $observed['condition_event_id'])->get()->getRowArray();
            self::assertSame('observed_existing', $event['event_code']);
            self::assertSame('2026-10-03 09:17:00', $event['occurred_at']);
            self::assertSame('2026-10-03 09:17:00', $f->member($observed)['incident_discovered_at']);
            $after = $items->item(1, 10, $created['condition_id']);
            foreach (['severity_code', 'status_code', 'description', 'discovered_at', 'discovered_turo_trip_normalized_id'] as $field) {
                self::assertSame($before[$field], $after[$field]);
            }
            $rows = $f->schema->rows();
            self::assertTrue($service->confirmUnchanged(1, 10, $data, 7)['replayed']);
            self::assertSame($rows, $f->schema->rows());
            $service->entry(1, 10, 'LF');
            self::assertSame($rows, $f->schema->rows());
        } elseif ($case === 'invalid_existing') {
            self::assertTrue($service->createNew(1, 10, $f->data('LF', ['severity_code' => 'severe']), 7)['success']);
            $data = $f->data('LF', ['severity_code' => 'severe']);
            foreach ([['severity_code' => 'cosmetic'], ['condition_id' => 9999], ['wheel_code' => 'RF'], ['damage_type_code' => 'dent'],
                ['expected_condition_state' => str_repeat('0', 64)], ['expected_wheel_state' => str_repeat('0', 64)]] as $change) {
                $this->reject(fn () => $service->recordWorsening(1, 10, array_replace($data, $change), 7));
            }
            foreach ([[], ['observed_at' => '2026-10-02 09:00:00', 'severity_code' => 'cosmetic'],
                ['observed_at' => '2026-10-02 09:00:00', 'attribution_type' => 'operator_attributed_cause', 'reason' => 'Synthetic', 'trip_id' => 100],
                ['observed_at' => '2026-10-02 09:00:00', 'occurred_at' => '2026-10-01 09:00:00'],
                ['observed_at' => '2026-10-02 09:00:00', 'expected_condition_state' => str_repeat('0', 64)]] as $change) {
                $this->reject(fn () => $service->confirmUnchanged(1, 10, array_replace($data, $change), 7));
            }
            $clone = $db->table('vehicle_damage_items')->where('id', $data['condition_id'])->get()->getRowArray();
            unset($clone['id']);
            $db->table('vehicle_damage_items')->insert($clone);
            $this->reject(fn () => $service->recordWorsening(1, 10, $f->data('LF', ['severity_code' => 'severe']), 7));
        } elseif ($case === 'attribution') {
            foreach (['unknown', 'discovered_during_trip', 'suspected_cause', 'operator_attributed_cause'] as $i => $attribution) {
                $wheel = array_keys(VehicleWheelIdentity::choices())[$i];
                $data = $f->data($wheel, ['trip_id' => 100, 'movement_event_id' => 1000, 'attribution_type' => $attribution, 'reason' => 'Synthetic operator reason.', 'occurred_at' => '2026-10-01 09:00:00']);
                $receipt = $service->createNew(1, 10, $data, 7);
                self::assertTrue($receipt['success'], json_encode($receipt));
                $incident = (new VehicleDamageIncidentRepository($db))->incident(1, 10, $receipt['incident_id']);
                self::assertSame($attribution, $incident['attribution_type']);
                self::assertSame(100, (int) $incident['turo_trip_normalized_id']);
                self::assertSame('2026-10-01 09:00:00', $incident['occurred_at']);
                $basis = Basis::read($f->member($receipt)['basis_snapshot'])['snapshot'];
                self::assertSame('scheduled_start', $basis['comparison']['boundary_kind']);
                self::assertSame('2026-09-25 08:00:00', $basis['comparison']['boundary_at']);
            }
        } elseif ($case === 'reuse') {
            $db->transBegin();
            $created = $service->createNew(1, 10, $f->data(), 7);
            self::assertTrue($created['success'], json_encode($created));
            $incident = (new VehicleDamageIncidentRepository($db))->incident(1, 10, $created['incident_id']);
            $data = $f->data('RF', ['incident_id' => $created['incident_id'], 'expected_incident_state' => VehicleDamageIncidentService::fingerprint($incident)]);
            $second = $service->createNew(1, 10, $data, 7);
            self::assertTrue($second['success'], json_encode($second));
            self::assertSame($created['incident_id'], $second['incident_id']);
            self::assertSame(2, $db->table('vehicle_damage_incident_items')->countAllResults());
            $db->transCommit();
            $this->reject(fn () => $service->createNew(1, 10, $f->data('RR', ['incident_id' => $created['incident_id'], 'expected_incident_state' => str_repeat('0', 64)]), 7));
        } elseif ($case === 'replay') {
            $data = $f->data();
            $created = $service->createNew(1, 10, $data, 7);
            self::assertTrue($created['success'], json_encode($created));
            self::assertTrue((new VehicleDamageService($db))->transitionStatus(1, 10, $created['condition_id'], 'repaired', 'Synthetic repair.', 7)['success']);
            $rows = $f->schema->rows();
            $replay = $service->createNew(1, 10, $data + ['csrf_test_name' => 'presentation', 'wheel_label' => 'not semantic'], 7);
            self::assertTrue($replay['replayed'], json_encode($replay));
            foreach (['incident_id', 'condition_id', 'condition_event_id', 'membership_id'] as $field) {
                self::assertSame($created[$field], $replay[$field]);
            }
            self::assertSame($rows, $f->schema->rows());
            $this->reject(fn () => $service->createNew(1, 10, array_replace($data, ['note' => 'Other facts.']), 7));
            $this->reject(fn () => $service->createNew(1, 10, $data, 8));
        } elseif ($case === 'bad_receipts') {
            $data = $f->data();
            self::assertTrue($service->createNew(1, 10, $data, 7)['success']);
            $audit = $db->table('audit_logs')->like('new_values', $data['command_uuid'])->get()->getRowArray();
            $json = json_decode($audit['new_values'], true);
            $json['wheel_command']['version'] = 9;
            $db->table('audit_logs')->where('id', $audit['id'])->update(['new_values' => json_encode($json)]);
            $this->reject(fn () => $service->createNew(1, 10, $data, 7));
            $db->table('audit_logs')->where('id', $audit['id'])->update(['new_values' => $audit['new_values']]);
            unset($audit['id']);
            $db->table('audit_logs')->insert($audit);
            $this->reject(fn () => $service->createNew(1, 10, $data, 7));
        } elseif ($case === 'context') {
            $db->table('trip_movement_checklists')->insertBatch([
                ['id' => 901, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10, 'movement_type' => 'pickup', 'scheduled_at' => '2026-10-01 08:00:00'],
                ['id' => 902, 'turo_trip_normalized_id' => 101, 'fleet_vehicle_id' => 11, 'movement_type' => 'pickup', 'scheduled_at' => '2026-10-01 08:00:00'],
            ]);
            $initial['trip_movement_checklists'] = $db->table('trip_movement_checklists')->get()->getResultArray();
            $this->reject(fn () => $service->createNew(1, 10, $f->data('LF', ['checklist_id' => 902]), 7));
            $this->reject(fn () => $service->createNew(1, 10, $f->data('LF', ['checklist_id' => 901, 'trip_id' => 102]), 7));
            $receipt = $service->createNew(1, 10, $f->data('LF', ['checklist_id' => 901]), 7);
            self::assertTrue($receipt['success'], json_encode($receipt));
            self::assertSame(100, (int) $items->item(1, 10, $receipt['condition_id'])['discovered_turo_trip_normalized_id']);
            $trusted = $service->createNew(1, 10, $f->data('RF', ['trip_id' => 200, 'movement_event_id' => 1001]), 7, ['trip_id' => 100, 'movement_event_id' => 1000]);
            self::assertTrue($trusted['success'], json_encode($trusted));
            self::assertSame(100, (int) $items->item(1, 10, $trusted['condition_id'])['discovered_turo_trip_normalized_id']);
        } elseif ($case === 'repair_boundary') {
            $created = $service->createNew(1, 10, $f->data(), 7);
            self::assertTrue($created['success']);
            self::assertTrue((new VehicleDamageService($db))->transitionStatus(1, 10, $created['condition_id'], 'repaired', 'Synthetic repair.', 7)['success']);
            $this->reject(fn () => $service->createNew(1, 10, $f->data('LF', ['occurred_at' => '2026-10-01 09:00:00']), 7));
            $this->reject(fn () => $service->createNew(1, 10, $f->data('LF', ['occurred_at' => null]), 7));
            self::assertTrue($service->createNew(1, 10, $f->data('LF', ['discovered_at' => '2026-10-20 10:00:00', 'occurred_at' => '2026-10-20 09:00:00']), 7)['success']);
        } else {
            $created = $service->createNew(1, 10, $f->data(), 7);
            self::assertTrue($created['success']);
            $observed = $service->confirmUnchanged(1, 10, $f->data('LF', ['observed_at' => '2026-09-24 07:00:00', 'severity_code' => null]), 7);
            self::assertTrue($observed['success'], json_encode($observed));
            $db->table('trip_movement_events')->insert(['company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff', 'occurred_at' => '2026-09-25 08:30:00', 'source' => 'operator', 'actor_user_id' => 7]);
            $initial['trip_movement_events'] = $db->table('trip_movement_events')->get()->getResultArray();
            $data = $f->data('LF', ['trip_id' => 100, 'baseline_event_id' => $observed['condition_event_id'], 'baseline_membership_id' => $observed['membership_id'], 'basis_snapshot' => '{"forged":true}']);
            $receipt = $service->recordWorsening(1, 10, $data, 7);
            self::assertTrue($receipt['success'], json_encode($receipt));
            $snapshot = Basis::read($f->member($receipt)['basis_snapshot'])['snapshot'];
            self::assertSame('actual_handoff', $snapshot['comparison']['boundary_kind']);
            self::assertSame('observed_existing', $snapshot['comparison']['baseline']['kind']);
            self::assertSame($observed['condition_event_id'], $snapshot['comparison']['baseline']['condition_event_id']);
            self::assertArrayNotHasKey('forged', $snapshot);
            $this->reject(fn () => $service->recordWorsening(1, 10, $f->data('LF', ['baseline_event_id' => $observed['condition_event_id'], 'baseline_membership_id' => 999]), 7));
            // Recorded evidence does not claim an observed-before-guest baseline.
            $recorded = $service->recordWorsening(1, 10, $f->data('LF', ['baseline_event_id' => $created['condition_event_id']]), 7);
            self::assertTrue($recorded['success'], json_encode($recorded));
            self::assertSame('recorded_condition', Basis::read($f->member($recorded)['basis_snapshot'])['snapshot']['comparison']['baseline']['kind']);
        }
        $after = $f->schema->rows();
        foreach ($initial as $table => $rows) {
            if (! str_starts_with($table, 'vehicle_damage_') && ! in_array($table, ['audit_logs', 'files', 'vehicle_files'], true)) {
                self::assertSame($rows, $after[$table], 'Authority firewall: ' . $table);
            }
        }
    }

    private function reject(callable $command): void
    {
        $before = $this->fixture->schema->rows();
        $result = $command();
        self::assertFalse($result['success'], json_encode($result));
        self::assertNotEmpty($result['errors']);
        self::assertSame($before, $this->fixture->schema->rows(), 'Rejected command must leave no partial state.');
    }
}
