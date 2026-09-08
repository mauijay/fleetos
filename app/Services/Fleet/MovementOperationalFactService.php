<?php

namespace App\Services\Fleet;

use App\Exceptions\EarlyHandoffConfirmationRequired;
use App\Repositories\AirportMovementRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\MovementIntelligence;
use RuntimeException;

class MovementOperationalFactService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly MovementEventService $events = new MovementEventService(),
        private readonly MovementAssessmentService $assessments = new MovementAssessmentService(),
        private readonly ?VehiclePositioningPlanService $positioningPlans = null,
        private readonly MovementIntelligence $movementConfig = new MovementIntelligence(),
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
        if ($eventCode === 'actual_handoff' && ($data['location_class'] ?? null) === 'airport_hnl') {
            throw new \InvalidArgumentException('Stage the vehicle at HNL, then confirm guest pickup separately.');
        }
        if ($eventCode === 'actual_handoff') {
            $this->requireEarlyHandoffConfirmation($checklist, $data);
        }

        return $this->recordObservation($checklist, $data, $actorUserId, $eventCode);
    }

    public function stageForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        if (($checklist['movement_type'] ?? null) !== 'pickup' || ($data['location_class'] ?? null) !== 'airport_hnl') {
            throw new \InvalidArgumentException('Only an Airport HNL pickup movement can be staged.');
        }
        if (! in_array($data['cleanliness'] ?? null, ['clean', 'dirty'], true) || ($data['energy_percent'] ?? '') === '') {
            throw new \InvalidArgumentException('Capture cleanliness and charge or fuel before staging at HNL.');
        }
        $active = $this->events->latestForTrip((int) $checklist['turo_trip_normalized_id']);
        if (in_array($active['event_code'] ?? null, ['vehicle_staged', 'actual_handoff'], true)) {
            throw new \InvalidArgumentException('This pickup is already staged or confirmed.');
        }

        return $this->recordObservation($checklist, $data, $actorUserId, 'vehicle_staged');
    }

    public function confirmGuestPickup(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        if (($checklist['movement_type'] ?? null) !== 'pickup') {
            throw new \InvalidArgumentException('Guest pickup can only be confirmed for a pickup movement.');
        }
        $active = $this->events->latestForTrip((int) $checklist['turo_trip_normalized_id']);
        if (($active['event_code'] ?? null) !== 'vehicle_staged') {
            throw new \InvalidArgumentException('Stage the vehicle at HNL before confirming guest pickup.');
        }
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        if ($occurredAt === '') {
            throw new \InvalidArgumentException('Guest pickup time is required.');
        }
        $this->requireEarlyHandoffConfirmation($checklist, $data);

        $this->db->transBegin();
        try {
            $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                'actual_handoff',
                'pickup',
                $occurredAt,
                $active['location_class'] ?? 'airport_hnl',
                $active['location_detail'] ?? null,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
                [
                    'garage_code' => $active['airport_garage_code'] ?? null,
                    'level' => $active['airport_parking_level'] ?? null,
                    'row' => $active['airport_parking_row'] ?? null,
                ],
            );
            $this->syncAirportWorkflow($checklist, [
                'workflow_status' => 'picked_up',
                'guest_pickup_confirmed_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
                'parking_exit_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
            ], 'authoritative_guest_pickup_confirmed', $actorUserId);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'new_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Guest pickup confirmation transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function recordObservation(array $checklist, array $data, int $actorUserId, string $eventCode): bool
    {
        $movementType = (string) $checklist['movement_type'];
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
            if ($eventCode === 'vehicle_staged') {
                $this->syncAirportWorkflow($checklist, [
                    'workflow_status' => 'staged',
                    'vehicle_staged_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
                    'garage' => $data['airport_garage_code'] ?? null,
                    'parking_level' => $data['airport_parking_level'] ?? null,
                    'parking_row' => $data['airport_parking_row'] ?? null,
                    'operator_notes' => $data['note'] ?? null,
                ], 'authoritative_vehicle_staged', $actorUserId);
            }
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

    /** @return array<int, array<string, mixed>> */
    public function wrongTripCandidates(array $checklist, int $eventId): array
    {
        $event = $this->events->find($eventId);
        if (! ($checklist['exists'] ?? false) || $event === null || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']) {
            return [];
        }
        $movementType = (string) $event['movement_type'];
        $scheduleField = $movementType === 'pickup' ? 'starts_at' : 'ends_at';
        $occurredAt = new \DateTimeImmutable((string) $event['occurred_at']);
        $windowHours = $this->movementConfig->repairCandidateWindowHours;

        return array_values(array_filter(
            $this->repo()->vehicleTripHistory((int) $event['fleet_vehicle_id'], 100),
            function (array $trip) use ($checklist, $movementType, $scheduleField, $occurredAt, $windowHours): bool {
                if ((int) $trip['id'] === (int) $checklist['turo_trip_normalized_id'] || empty($trip[$scheduleField])) {
                    return false;
                }
                if (str_starts_with((string) ($trip['trip_status_code'] ?? ''), 'canceled')) {
                    return false;
                }
                $distance = abs((new \DateTimeImmutable((string) $trip[$scheduleField]))->getTimestamp() - $occurredAt->getTimestamp());

                return $distance <= $windowHours * 3600 && ! $this->repo()->hasActiveMovementFact((int) $trip['id'], $movementType);
            },
        ));
    }

    public function repairWrongTrip(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $eventId = (int) ($data['event_id'] ?? 0);
        $assessmentId = (int) ($data['assessment_id'] ?? 0);
        $targetTripId = (int) ($data['target_trip_id'] ?? 0);
        $reason = trim((string) ($data['repair_reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('A repair reason is required.');
        }
        $event = $this->events->find($eventId);
        $assessment = $this->assessments->find($assessmentId);
        if ($event === null || $assessment === null
            || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['trip_movement_event_id'] !== $eventId) {
            throw new \InvalidArgumentException('The recorded facts do not belong to this movement.');
        }
        $compatibleEvents = (string) $event['movement_type'] === 'pickup' ? ['vehicle_staged', 'actual_handoff'] : ['actual_return', 'vehicle_recovered'];
        if (! in_array($event['event_code'], $compatibleEvents, true)) {
            throw new \InvalidArgumentException('This fact is not compatible with a reservation movement repair.');
        }
        $candidateIds = array_map(static fn (array $trip): int => (int) $trip['id'], $this->wrongTripCandidates($checklist, $eventId));
        if (! in_array($targetTripId, $candidateIds, true)) {
            throw new \InvalidArgumentException('Choose a plausible nearby trip for the same vehicle without a conflicting fact.');
        }

        $auditReason = 'Recorded on wrong trip: ' . $reason;
        $this->db->transBegin();
        try {
            $replacementEventId = $this->events->correct($eventId, [
                'turo_trip_normalized_id' => $targetTripId,
                'source' => $event['source'],
                'actor_user_id' => $event['actor_user_id'],
            ], $actorUserId, $auditReason, false);
            $this->assessments->correct($assessmentId, [
                'turo_trip_normalized_id' => $targetTripId,
                'trip_movement_event_id' => $replacementEventId,
                'source' => $assessment['source'],
                'actor_user_id' => $assessment['actor_user_id'],
            ], $actorUserId, $auditReason, false);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'repaired_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Wrong-trip repair transaction failed.');
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

    private function requireEarlyHandoffConfirmation(array $checklist, array $data): void
    {
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        $scheduledAt = trim((string) ($checklist['scheduled_at'] ?? $checklist['starts_at'] ?? ''));
        if ($occurredAt === '' || $scheduledAt === '') {
            return;
        }
        $threshold = $this->movementConfig->earlyHandoffWarningHours;
        $warningBoundary = (new \DateTimeImmutable($scheduledAt))->modify('-' . $threshold . ' hours');
        if (new \DateTimeImmutable($occurredAt) < $warningBoundary && ($data['confirm_early_handoff'] ?? null) !== '1') {
            $scheduledLabel = (new \DateTimeImmutable($scheduledAt))->format('M j, Y \a\t g:i A');
            $message = 'This reservation does not begin until ' . $scheduledLabel . '. The handoff time entered is more than ' . $threshold . ' hours early. Are you sure this is the correct reservation?';
            if (($data['location_class'] ?? null) === 'airport_hnl') {
                $message .= ' If the guest does not yet have possession, use Stage at HNL instead.';
            }

            throw new EarlyHandoffConfirmationRequired($message);
        }
    }

    private function plans(): VehiclePositioningPlanService
    {
        return $this->positioningPlans ?? new VehiclePositioningPlanService(new \App\Repositories\OperationalFactsRepository($this->db));
    }

    private function repo(): \App\Repositories\OperationalFactsRepository
    {
        return new \App\Repositories\OperationalFactsRepository($this->db);
    }

    private function syncAirportWorkflow(array $checklist, array $data, string $action, int $actorUserId): void
    {
        if (! $this->db->tableExists('airport_movement_workflows') || ! in_array('workflow_status', $this->db->getFieldNames('airport_movement_workflows'), true)) {
            return;
        }
        $workflow = $this->db->table('airport_movement_workflows')
            ->select('id')
            ->where('turo_trip_normalized_id', (int) $checklist['turo_trip_normalized_id'])
            ->where('movement_type', 'pickup')
            ->orderBy('scheduled_at', 'DESC')
            ->get(1)
            ->getRowArray();
        if ($workflow !== null) {
            if (! (new AirportMovementRepository($this->db))->updateWorkflow((int) $workflow['id'], $data, $action, $actorUserId)) {
                throw new RuntimeException('Airport workflow synchronization failed.');
            }
        }
    }
}
