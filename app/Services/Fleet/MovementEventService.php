<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class MovementEventService
{
    public const EVENT_CODES = ['vehicle_staged', 'actual_handoff', 'actual_return', 'vehicle_recovered', 'vehicle_positioned'];

    public function __construct(private readonly ?OperationalFactsRepository $repository = null, private readonly LocationClassificationService $classifier = new LocationClassificationService())
    {
    }

    public function record(int $vehicleId, ?int $tripId, string $eventCode, ?string $movementType, string $occurredAt, ?string $locationClass, ?string $locationDetail, string $source, int $actorUserId, ?string $note = null): int
    {
        return $this->repo()->createEvent($this->eventData($vehicleId, $tripId, $eventCode, $movementType, $occurredAt, $locationClass, $locationDetail, $source, $actorUserId, $note));
    }

    public function correct(int $eventId, array $replacement, int $actorUserId, string $reason): int
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A correction reason is required.');
        }
        $original = $this->repo()->event($eventId);
        if ($original === null) {
            throw new \InvalidArgumentException('Event not found.');
        }
        $data = $this->eventData((int) $original['fleet_vehicle_id'], $original['turo_trip_normalized_id'] === null ? null : (int) $original['turo_trip_normalized_id'], (string) ($replacement['event_code'] ?? $original['event_code']), $replacement['movement_type'] ?? $original['movement_type'], (string) ($replacement['occurred_at'] ?? $original['occurred_at']), $replacement['location_class'] ?? $original['location_class'], $replacement['location_detail'] ?? $original['location_detail'], (string) ($replacement['source'] ?? 'operator_correction'), $actorUserId, $replacement['note'] ?? $original['note']);
        return $this->repo()->correctEvent($eventId, $data, $actorUserId, trim($reason));
    }

    public function void(int $eventId, int $actorUserId, string $reason): bool
    {
        return $this->repo()->voidEvent($eventId, $actorUserId, $reason);
    }

    /** @return array<string, mixed>|null */
    public function find(int $eventId): ?array
    {
        return $this->repo()->event($eventId);
    }

    /** @return array<string, mixed> */
    private function eventData(int $vehicleId, ?int $tripId, string $eventCode, ?string $movementType, string $occurredAt, ?string $locationClass, ?string $locationDetail, string $source, int $actorUserId, ?string $note): array
    {
        if (! in_array($eventCode, self::EVENT_CODES, true) || ! in_array($movementType, ['pickup', 'return', null], true) || $actorUserId < 1) {
            throw new \InvalidArgumentException('Invalid movement event.');
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
        $location = $locationClass === null || $locationClass === '' ? null : $this->classifier->explicit($locationClass, $locationDetail);
        return [
            'company_id' => (int) $vehicle['company_id'], 'fleet_vehicle_id' => $vehicleId, 'turo_trip_normalized_id' => $tripId,
            'event_code' => $eventCode, 'movement_type' => $movementType, 'occurred_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
            'location_class' => $location['location_class'] ?? null, 'location_detail' => $location['source_text'] ?? null,
            'source' => $source, 'actor_user_id' => $actorUserId, 'note' => trim((string) $note) ?: null,
        ];
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
