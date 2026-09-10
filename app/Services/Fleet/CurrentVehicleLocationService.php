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
        $event = $this->repo()->latestCurrentStateEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($event === null) {
            return $this->unknownLocation();
        }

        return $this->presentEvent($event, $asOf);
    }

    /** @return array<int, array<string, mixed>> one row per active company vehicle */
    public function forCompany(int $companyId, ?\DateTimeImmutable $asOf = null): array
    {
        if ($companyId < 1) {
            return [];
        }
        $asOf ??= new \DateTimeImmutable();
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId, $asOf->format('Y-m-d'));
        $events = $this->repo()->latestCurrentStateEventsForCompany(
            $companyId,
            array_map('intval', array_column($vehicles, 'id')),
            $asOf->format('Y-m-d H:i:s'),
        );

        return array_map(function (array $vehicle) use ($events, $asOf): array {
            $event = $events[(int) $vehicle['id']] ?? null;
            if ($event === null) {
                return array_merge($vehicle, $this->unknownLocation());
            }

            return array_merge($vehicle, $this->presentEvent($event, $asOf));
        }, $vehicles);
    }

    /** @return array<string, mixed> */
    private function presentEvent(array $event, \DateTimeImmutable $asOf): array
    {
        $observed = new \DateTimeImmutable((string) $event['occurred_at']);
        $eventCode = (string) ($event['event_code'] ?? '');
        $isCurrent = in_array($eventCode, ['actual_return', 'vehicle_recovered', 'vehicle_positioned', 'vehicle_staged'], true);
        $isRented = $eventCode === 'actual_handoff';

        return [
            'location_class' => trim((string) ($event['location_class'] ?? '')) ?: 'unknown',
            'location_detail' => $event['location_detail'] ?? null,
            'airport_garage_code' => $event['airport_garage_code'] ?? null,
            'airport_parking_level' => $event['airport_parking_level'] ?? null,
            'airport_parking_row' => $event['airport_parking_row'] ?? null,
            'observed_at' => $event['occurred_at'],
            'source' => $event['source'] ?? null,
            'actor_user_id' => $event['actor_user_id'] ?? null,
            'trip_id' => $event['turo_trip_normalized_id'] ?? null,
            'event_id' => $event['id'],
            'event_code' => $eventCode,
            'operational_state' => $isRented ? 'rented' : ($isCurrent ? 'parked' : 'unknown'),
            'position_semantics' => $isRented ? 'rented' : ($isCurrent ? 'current' : 'last_known'),
            'location_label' => $isRented ? 'Handoff location' : ($isCurrent ? 'Current vehicle position' : 'Last known location'),
            'age_seconds' => max(0, $asOf->getTimestamp() - $observed->getTimestamp()),
        ];
    }

    /** @return array<string, mixed> */
    private function unknownLocation(): array
    {
        return ['location_class' => 'unknown', 'location_detail' => null, 'airport_garage_code' => null, 'airport_parking_level' => null, 'airport_parking_row' => null, 'observed_at' => null, 'source' => null, 'actor_user_id' => null, 'trip_id' => null, 'event_id' => null, 'event_code' => null, 'operational_state' => 'unknown', 'position_semantics' => 'unknown', 'location_label' => 'Current vehicle position', 'age_seconds' => null];
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
