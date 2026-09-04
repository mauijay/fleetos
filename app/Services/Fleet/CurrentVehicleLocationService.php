<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class CurrentVehicleLocationService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    /** @return array<string, mixed> */
    public function resolve(int $vehicleId, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $event = $this->repo()->latestLocationEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($event === null) {
            return ['location_class' => 'unknown', 'location_detail' => null, 'observed_at' => null, 'source' => null, 'actor_user_id' => null, 'trip_id' => null, 'event_id' => null, 'event_code' => null, 'position_semantics' => 'unknown', 'location_label' => 'Last known location', 'age_seconds' => null];
        }
        $observed = new \DateTimeImmutable((string) $event['occurred_at']);
        $isCurrent = in_array($event['event_code'], ['actual_return', 'vehicle_recovered', 'vehicle_positioned'], true);
        return ['location_class' => $event['location_class'], 'location_detail' => $event['location_detail'], 'observed_at' => $event['occurred_at'], 'source' => $event['source'], 'actor_user_id' => $event['actor_user_id'], 'trip_id' => $event['turo_trip_normalized_id'], 'event_id' => $event['id'], 'event_code' => $event['event_code'], 'position_semantics' => $isCurrent ? 'current' : 'last_known', 'location_label' => $event['event_code'] === 'actual_handoff' ? 'Handoff location' : ($isCurrent ? 'Current location' : 'Last known location'), 'age_seconds' => max(0, $asOf->getTimestamp() - $observed->getTimestamp())];
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
