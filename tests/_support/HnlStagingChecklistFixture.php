<?php

namespace Tests\Support;

use App\Database\Seeds\LookupSeeder;
use App\Repositories\LookupRepository;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Synthetic old stage, intervening trip, Home recovery, then an HNL pickup. */
final class HnlStagingChecklistFixture
{
    public static function seed(BaseConnection $db, string $energyKind = 'electric'): void
    {
        (new LookupSeeder(new Database(), $db))->run();
        $db->query('CREATE TABLE ' . $db->prefixTable('users') . ' (id INTEGER PRIMARY KEY, username VARCHAR(30))');
        $db->table('users')->insert(['id' => 7, 'username' => 'synthetic-hnl-operator']);
        $db->table('companies')->insert(['id' => 1, 'name' => 'Synthetic HNL Company', 'slug' => 'synthetic-hnl']);
        $db->table('vehicle_makes')->insert(['id' => 1, 'code' => 'synthetic', 'name' => 'Synthetic']);
        $db->table('vehicle_models')->insert(['id' => 1, 'vehicle_make_id' => 1, 'code' => 'synthetic', 'name' => 'Synthetic']);
        $db->table('vehicle_body_styles')->insert(['code' => 'synthetic', 'name' => 'Synthetic']);
        $body = (int) $db->insertID();
        $db->table('vehicle_colors')->insert(['code' => 'synthetic', 'name' => 'Synthetic']);
        $color = (int) $db->insertID();
        $db->table('vehicle_specs')->insert(['id' => 1, 'vehicle_model_id' => 1, 'model_year' => 2026, 'vehicle_body_style_id' => $body, 'exterior_vehicle_color_id' => $color, 'interior_vehicle_color_id' => $color]);
        $db->table('fleet_vehicles')->insert(['id' => 10, 'company_id' => 1, 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'SYNTHETIC-HNL', 'display_name' => 'Synthetic HNL Vehicle']);
        $db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => 10, 'energy_kind' => $energyKind, 'ready_energy_target_percent' => 80, 'created_by' => 7, 'updated_by' => 7]);
        $airport = $db->table('airports')->where('code', 'HNL')->get()->getRowArray();
        $lookups = new LookupRepository($db);
        $now = new \DateTimeImmutable();
        foreach ([100 => ['-4 days', '-2 days', 'completed'], 102 => ['+1 day', '+2 days', 'booked'], 103 => ['+3 days', '+4 days', 'booked']] as $id => [$start, $end, $status]) {
            $db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => 10, 'turo_trip_id' => 'SYNTHETIC-HNL-' . $id, 'turo_reservation_id' => 'SYNTHETIC-HNL-' . $id, 'guest_name' => 'Synthetic HNL Guest ' . $id, 'starts_at' => $now->modify($start)->format('Y-m-d H:i:s'), 'ends_at' => $now->modify($end)->format('Y-m-d H:i:s'), 'trip_status_lookup_value_id' => $lookups->valueId('trip_status', $status)]);
            $db->table('scheduled_movement_locations')->insert(['turo_trip_normalized_id' => $id, 'fleet_vehicle_id' => 10, 'movement_type' => 'pickup', 'location_class' => $id === 100 ? 'home' : 'airport_hnl', 'classification_source' => 'operator', 'classification_status' => 'classified']);
            $db->table('trip_movement_checklists')->insert(['id' => $id, 'turo_trip_normalized_id' => $id, 'fleet_vehicle_id' => 10, 'movement_type' => 'pickup', 'scheduled_at' => $now->modify($start)->format('Y-m-d H:i:s'), 'readiness_status' => 'not_ready']);
        }
        $db->table('airport_movement_workflows')->insert(['id' => 102, 'turo_trip_normalized_id' => 102, 'trip_movement_checklist_id' => 102, 'fleet_vehicle_id' => 10, 'airport_id' => $airport['id'], 'movement_type' => 'pickup', 'scheduled_at' => $now->modify('+1 day')->format('Y-m-d H:i:s')]);
        $events = new MovementEventService(new \App\Repositories\OperationalFactsRepository($db));
        $events->record(10, 102, 'vehicle_staged', 'pickup', $now->modify('-5 days')->format('Y-m-d H:i:s'), 'airport_hnl', null, 'checklist_operator', 7, 'Synthetic historical staging', ['garage_code' => 'international', 'level' => 7, 'row' => 'F']);
        $events->record(10, 100, 'actual_handoff', 'pickup', $now->modify('-4 days')->format('Y-m-d H:i:s'), 'home', null, 'checklist_operator', 7);
        $events->record(10, 100, 'vehicle_recovered', 'return', $now->modify('-2 days')->format('Y-m-d H:i:s'), 'home', 'Synthetic driveway', 'checklist_operator', 7);
    }

    /** @return array<string, mixed> */
    public static function stagingData(): array
    {
        return ['occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d\TH:i'), 'location_class' => 'airport_hnl', 'airport_garage_code' => 'international', 'airport_parking_level' => '7', 'airport_parking_row' => 'G', 'cleanliness' => 'clean', 'energy_percent' => '85', 'note' => 'Synthetic new HNL staging'];
    }
}
