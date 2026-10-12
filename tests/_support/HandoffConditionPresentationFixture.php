<?php

namespace Tests\Support;

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\BaseConnection;

/** Invented evidence only; no production data or application write route. */
final class HandoffConditionPresentationFixture
{
    public static function seed(BaseConnection $db, string $scenario): void
    {
        MovementPresentationFixture::seed($db, 'handoff');
        $db->table('movement_assessments')->where('fleet_vehicle_id', 10)->delete();
        $db->table('trip_movement_events')->where('fleet_vehicle_id', 10)->delete();
        $repository = new OperationalFactsRepository($db);
        $events = new MovementEventService($repository);
        $assessments = new MovementAssessmentService($repository);
        $day = new \DateTimeImmutable('-1 day');
        $at = static fn (string $time): string => $day->format('Y-m-d') . ' ' . $time . ':00';
        $parking = ['garage_code' => 'international', 'level' => 7, 'row' => 'F'];
        $stage = $events->record(10, 102, 'vehicle_staged', 'pickup', $at('20:45'), 'airport_hnl', null, 'checklist_operator', 7, null, $parking);
        $cleanliness = in_array($scenario, ['condition-charge', 'condition-none'], true) ? null : 'clean';
        $energy = in_array($scenario, ['condition-cleanliness', 'condition-none'], true) ? null : 80;
        $assessments->record(10, 102, $stage, 'pickup', $cleanliness, $energy, $at('20:45'), $scenario === 'condition-long' ? str_repeat('synthetic_operator_', 4) : 'checklist_operator', 7);
        $handoff = $events->record(10, 102, 'actual_handoff', 'pickup', $at('21:35'), $scenario === 'condition-long' ? 'other_delivery' : 'airport_hnl', $scenario === 'condition-long' ? str_repeat('SyntheticTerminalMeetingPoint', 12) : null, 'checklist_operator', 7, null, $scenario === 'condition-long' ? [] : $parking);
        if (in_array($scenario, ['condition-direct', 'condition-mixed'], true)) {
            $assessments->record(10, 102, $handoff, 'pickup', 'dirty', $scenario === 'condition-direct' ? 61 : null, $at('21:35'), 'checklist_operator', 7);
        }
        $db->table('trip_movement_events')->where('id', $stage)->update(['created_at' => $day->modify('+1 day')->format('Y-m-d H:i:s')]);
    }
}
