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
                'note' => $this->presentValue($data, 'note', $event['note']),
            ], $actorUserId, $reason);
            $this->assessments->correct($assessmentId, [
                'trip_movement_event_id' => $replacementEventId,
                'captured_at' => $this->presentValue($data, 'occurred_at', $assessment['captured_at']),
                'cleanliness' => $this->presentValue($data, 'cleanliness', $assessment['cleanliness']),
                'energy_percent' => $this->presentValue($data, 'energy_percent', $assessment['energy_percent']),
                'note' => $this->presentValue($data, 'note', $assessment['note']),
            ], $actorUserId, $reason);
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
}
