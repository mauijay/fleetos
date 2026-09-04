<?php

namespace App\Services\Fleet;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class MovementOperationalFactService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly MovementEventService $events = new MovementEventService(),
        private readonly MovementAssessmentService $assessments = new MovementAssessmentService(),
        private readonly ?VehiclePositioningPlanService $positioningPlans = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    public function recordForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $movementType = (string) $checklist['movement_type'];
        $eventCode = $movementType === 'pickup' ? 'actual_handoff' : 'actual_return';
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        if ($occurredAt === '') {
            throw new \InvalidArgumentException('Actual time is required.');
        }

        $this->db->transBegin();
        try {
            $eventId = $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                $eventCode,
                $movementType,
                $occurredAt,
                (string) ($data['location_class'] ?? 'unknown'),
                $data['location_detail'] ?? null,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
                [
                    'garage_code' => $data['airport_garage_code'] ?? null,
                    'level' => $data['airport_parking_level'] ?? null,
                    'row' => $data['airport_parking_row'] ?? null,
                    'structured_input_present' => array_key_exists('airport_garage_code', $data),
                ],
            );
            $this->assessments->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                $eventId,
                $movementType,
                ($data['cleanliness'] ?? '') === '' ? null : (string) $data['cleanliness'],
                $data['energy_percent'] ?? null,
                $occurredAt,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
            );
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'new_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operational fact transaction failed.');
            }
            $this->db->transCommit();
            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function correctForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $eventId = (int) ($data['event_id'] ?? 0);
        $assessmentId = (int) ($data['assessment_id'] ?? 0);
        $reason = trim((string) ($data['correction_reason'] ?? ''));
        $event = $this->events->find($eventId);
        $assessment = $this->assessments->find($assessmentId);
        if ($event === null || $assessment === null
            || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['trip_movement_event_id'] !== $eventId) {
            throw new \InvalidArgumentException('The recorded facts do not belong to this movement.');
        }

        $this->db->transBegin();
        try {
            $replacementEventId = $this->events->correct($eventId, [
                'occurred_at' => $this->presentValue($data, 'occurred_at', $event['occurred_at']),
                'location_class' => $this->presentValue($data, 'location_class', $event['location_class']),
                'location_detail' => $this->presentValue($data, 'location_detail', $event['location_detail']),
                'airport_garage_code' => $this->presentValue($data, 'airport_garage_code', $event['airport_garage_code'] ?? null),
                'airport_parking_level' => $this->presentValue($data, 'airport_parking_level', $event['airport_parking_level'] ?? null),
                'airport_parking_row' => $this->presentValue($data, 'airport_parking_row', $event['airport_parking_row'] ?? null),
                'note' => $this->presentValue($data, 'note', $event['note']),
            ], $actorUserId, $reason, false);
            $this->assessments->correct($assessmentId, [
                'trip_movement_event_id' => $replacementEventId,
                'captured_at' => $this->presentValue($data, 'occurred_at', $assessment['captured_at']),
                'cleanliness' => $this->presentValue($data, 'cleanliness', $assessment['cleanliness']),
                'energy_percent' => $this->presentValue($data, 'energy_percent', $assessment['energy_percent']),
                'note' => $this->presentValue($data, 'note', $assessment['note']),
            ], $actorUserId, $reason, false);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'corrected_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operational fact correction transaction failed.');
            }
            $this->db->transCommit();
            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function presentValue(array $data, string $key, mixed $fallback): mixed
    {
        if (! array_key_exists($key, $data) || (is_string($data[$key]) && trim($data[$key]) === '')) {
            return $fallback;
        }

        return $data[$key];
    }

    private function plans(): VehiclePositioningPlanService
    {
        return $this->positioningPlans ?? new VehiclePositioningPlanService(new \App\Repositories\OperationalFactsRepository($this->db));
    }
}
