<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class MovementAssessmentService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    public function record(int $vehicleId, ?int $tripId, ?int $eventId, string $movementType, ?string $cleanliness, mixed $energyPercent, string $capturedAt, string $source, int $actorUserId, ?string $note = null): int
    {
        return $this->repo()->createAssessment($this->assessmentData($vehicleId, $tripId, $eventId, $movementType, $cleanliness, $energyPercent, $capturedAt, $source, $actorUserId, $note));
    }

    public function correct(int $assessmentId, array $replacement, int $actorUserId, string $reason, bool $manageTransaction = true): int
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A correction reason is required.');
        }
        $original = $this->repo()->assessment($assessmentId);
        if ($original === null) {
            throw new \InvalidArgumentException('Assessment not found.');
        }
        $eventId = array_key_exists('trip_movement_event_id', $replacement)
            ? (int) $replacement['trip_movement_event_id']
            : ($original['trip_movement_event_id'] === null ? null : (int) $original['trip_movement_event_id']);
        $data = $this->assessmentData((int) $original['fleet_vehicle_id'], $original['turo_trip_normalized_id'] === null ? null : (int) $original['turo_trip_normalized_id'], $eventId, (string) ($replacement['movement_type'] ?? $original['movement_type']), $replacement['cleanliness'] ?? $original['cleanliness'], $replacement['energy_percent'] ?? $original['energy_percent'], (string) ($replacement['captured_at'] ?? $original['captured_at']), (string) ($replacement['source'] ?? 'operator_correction'), $actorUserId, $replacement['note'] ?? $original['note']);
        return $this->repo()->correctAssessment($assessmentId, $data, $actorUserId, trim($reason), $manageTransaction);
    }

    public function void(int $assessmentId, int $actorUserId, string $reason): bool
    {
        return $this->repo()->voidAssessment($assessmentId, $actorUserId, $reason);
    }

    /** @return array<string, mixed>|null */
    public function find(int $assessmentId): ?array
    {
        return $this->repo()->assessment($assessmentId);
    }

    /** @return array<string, mixed> */
    private function assessmentData(int $vehicleId, ?int $tripId, ?int $eventId, string $movementType, ?string $cleanliness, mixed $energyPercent, string $capturedAt, string $source, int $actorUserId, ?string $note): array
    {
        if (! in_array($movementType, ['pickup', 'return'], true) || ! in_array($cleanliness, ['clean', 'dirty', null], true) || $actorUserId < 1) {
            throw new \InvalidArgumentException('Invalid movement assessment.');
        }
        $energy = $energyPercent === null || $energyPercent === '' ? null : filter_var($energyPercent, FILTER_VALIDATE_INT);
        if ($energy === false || ($energy !== null && ($energy < 0 || $energy > 100))) {
            throw new \InvalidArgumentException('Energy must be between 0 and 100.');
        }
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            throw new \InvalidArgumentException('Vehicle not found.');
        }
        if ($tripId !== null) {
            $trip = $this->repo()->trip($tripId);
            if ($trip === null || (int) $trip['fleet_vehicle_id'] !== $vehicleId || (int) $trip['company_id'] !== (int) $vehicle['company_id']) {
                throw new \InvalidArgumentException('Trip does not belong to this vehicle and company.');
            }
        }
        if ($eventId !== null) {
            $event = $this->repo()->event($eventId);
            if ($event === null || (int) $event['fleet_vehicle_id'] !== $vehicleId || (int) $event['company_id'] !== (int) $vehicle['company_id']) {
                throw new \InvalidArgumentException('Movement event does not belong to this vehicle and company.');
            }
        }
        return ['company_id' => (int) $vehicle['company_id'], 'fleet_vehicle_id' => $vehicleId, 'turo_trip_normalized_id' => $tripId, 'trip_movement_event_id' => $eventId, 'movement_type' => $movementType, 'cleanliness' => $cleanliness, 'energy_percent' => $energy, 'captured_at' => (new \DateTimeImmutable($capturedAt))->format('Y-m-d H:i:s'), 'source' => $source, 'actor_user_id' => $actorUserId, 'note' => trim((string) $note) ?: null];
    }

    /** @return array<int, array<string, mixed>> */
    public function forTrip(int $tripId): array
    {
        return $this->repo()->assessmentsForTrip($tripId);
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
