<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;

/** Invented reservations and lifecycle chronology for presentation regression only. */
final class MovementPresentationFixture
{
    public static function seed(BaseConnection $db, string $scenario = 'historical'): void
    {
        HnlStagingChecklistFixture::seed($db);
        $db->table('movement_assessments')->where('fleet_vehicle_id', 10)->delete();
        $db->table('trip_movement_events')->where('fleet_vehicle_id', 10)->delete();
        $day = new \DateTimeImmutable('-2 days');
        $repository = new OperationalFactsRepository($db);
        $events = new MovementEventService($repository);
        $assessments = new MovementAssessmentService($repository);
        $at = static fn (string $time): string => $day->format('Y-m-d') . ' ' . $time . ':00';
        $parking = ['garage_code' => 'international', 'level' => 7, 'row' => 'F'];
        $events->record(10, 100, 'actual_handoff', 'pickup', $day->modify('-2 days')->format('Y-m-d H:i:s'), 'home', null, 'checklist_operator', 7);
        $stage = $events->record(10, 102, 'vehicle_staged', 'pickup', $at('14:47'), 'airport_hnl', null, 'checklist_operator', 7, 'Synthetic historical staging', $parking);
        $assessments->record(10, 102, $stage, 'pickup', 'clean', 79, $at('14:47'), 'checklist_operator', 7);
        if ($scenario !== 'guest') {
            $events->record(10, 100, 'vehicle_recovered', 'return', $at(in_array($scenario, ['valid', 'position', 'later', 'handoff'], true) ? '11:52' : '16:42'), 'airport_hnl', null, 'checklist_operator', 7, null, $parking);
            $observation = $events->record(10, null, 'vehicle_readiness_observed', null, $at('16:43'), null, null, 'vehicle_operator', 7);
            $assessments->record(10, null, $observation, 'current', 'clean', 79, $at('16:43'), 'vehicle_operator', 7);
        }
        if (in_array($scenario, ['historical', 'later'], true)) {
            $events->record(10, 103, 'vehicle_staged', 'pickup', $at('16:58'), 'airport_hnl', null, 'checklist_operator', 7, 'Synthetic future reservation stage', $parking);
        } elseif ($scenario === 'position') {
            $events->record(10, 100, 'vehicle_positioned', null, $at('16:58'), 'airport_hnl', null, 'checklist_operator', 7, 'Synthetic position only', $parking);
        }
        if (in_array($scenario, ['valid', 'handoff'], true)) {
            $start = $day->modify('+1 day')->setTime(21, 0)->format('Y-m-d H:i:s');
            $db->table('turo_trips_normalized')->where('id', 102)->update(['starts_at' => $start, 'ends_at' => $day->modify('+3 days')->format('Y-m-d H:i:s'), 'trip_status_lookup_value_id' => (new LookupRepository($db))->valueId('trip_status', 'in_progress')]);
            $db->table('trip_movement_checklists')->where('id', 102)->update(['scheduled_at' => $start]);
            $db->table('airport_movement_workflows')->where('id', 102)->update(['scheduled_at' => $start]);
            if ($scenario === 'handoff') {
                $events->record(10, 102, 'actual_handoff', 'pickup', $day->modify('+1 day')->setTime(21, 33)->format('Y-m-d H:i:s'), 'airport_hnl', null, 'checklist_operator', 7, null, $parking);
            }
        }
        foreach (['exterior_photos_completed', 'interior_photos_completed', 'key_card_confirmed', 'charging_adapter_confirmed'] as $code) {
            $db->table('trip_movement_checklist_items')->insert(['trip_movement_checklist_id' => 102, 'item_code' => $code, 'label' => 'Synthetic check', 'completion_state' => 'complete', 'applicability' => 'applicable', 'completed_at' => $at('14:40')]);
        }
        self::seedHotel($db, $events, $assessments, $day);
    }

    private static function seedHotel(BaseConnection $db, MovementEventService $events, MovementAssessmentService $assessments, \DateTimeImmutable $day): void
    {
        $db->table('fleet_vehicles')->insert(['id' => 20, 'company_id' => 1, 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'SYNTHETIC-HOTEL', 'display_name' => 'Synthetic Hotel Vehicle']);
        $db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => 20, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 70, 'created_by' => 7, 'updated_by' => 7]);
        $start = $day->modify('-4 days')->setTime(8, 0)->format('Y-m-d H:i:s');
        $end = $day->modify('-2 days')->setTime(8, 0)->format('Y-m-d H:i:s');
        $db->table('turo_trips_normalized')->insert(['id' => 200, 'fleet_vehicle_id' => 20, 'turo_trip_id' => 'SYNTHETIC-HOTEL-200', 'turo_reservation_id' => 'SYNTHETIC-HOTEL-200', 'guest_name' => 'Synthetic Hotel Guest', 'starts_at' => $start, 'ends_at' => $end, 'trip_status_lookup_value_id' => (new LookupRepository($db))->valueId('trip_status', 'completed')]);
        foreach (['pickup' => [200, $start], 'return' => [201, $end]] as $movement => [$id, $time]) {
            $db->table('trip_movement_checklists')->insert(['id' => $id, 'turo_trip_normalized_id' => 200, 'fleet_vehicle_id' => 20, 'movement_type' => $movement, 'scheduled_at' => $time, 'readiness_status' => 'not_started']);
            $db->table('scheduled_movement_locations')->insert(['turo_trip_normalized_id' => 200, 'fleet_vehicle_id' => 20, 'movement_type' => $movement, 'location_class' => 'unknown', 'source_text' => 'Synthetic hotel reservation address', 'classification_source' => 'unclassified', 'classification_status' => 'pending']);
            $code = $movement === 'pickup' ? 'actual_handoff' : 'vehicle_recovered';
            $old = $events->record(20, 200, $code, $movement, $time, 'unknown', null, 'checklist_operator', 7);
            $db->table('trip_movement_events')->where('id', $old)->update(['voided_at' => $day->format('Y-m-d H:i:s')]);
            $current = $events->record(20, 200, $code, $movement, $time, 'waikiki_hotel', 'Synthetic Garden Hotel', 'operator_correction', 7);
            $db->table('trip_movement_events')->where('id', $current)->update(['supersedes_event_id' => $old]);
            $assessments->record(20, 200, $current, $movement, 'clean', $movement === 'pickup' ? 81 : 57, $time, 'operator_correction', 7);
        }
        $events->record(20, null, 'vehicle_positioned', null, $day->modify('-1 day')->format('Y-m-d H:i:s'), 'home', null, 'vehicle_operator', 7);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function businessRows(BaseConnection $db): array
    {
        $rows = [];
        foreach (['trip_movement_events', 'movement_assessments', 'trip_movement_checklists', 'trip_movement_checklist_items', 'turo_trips_normalized', 'scheduled_movement_locations', 'operational_fact_audits', 'airport_movement_workflows', 'airport_movement_audits', 'fleet_trip_commitments', 'trip_extra_fulfillments', 'vehicle_positioning_plans'] as $table) {
            $rows[$table] = $db->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $rows;
    }
}
