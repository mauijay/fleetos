<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class CurrentVehicleLocationService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly ?CurrentVehicleCustodyService $custodyService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function resolve(int $vehicleId, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $custody = $this->custody()->resolve($vehicleId, $asOf);
        $lifecycle = $custody['basis_event'];
        if (($lifecycle['event_code'] ?? null) === 'guest_return_staged') {
            return $this->awaitingRecovery($lifecycle);
        }

        if ($custody['custody'] === 'guest' && is_array($lifecycle)) {
            return $this->presentEvent($lifecycle, $asOf, 'guest');
        }

        $event = $this->operatorLocationEvent($custody, $this->repo()->latestLocationEvent($vehicleId, $asOf->format('Y-m-d H:i:s')));
        if ($event === null) {
            return $this->unknownLocation();
        }

        return $this->presentEvent($event, $asOf, $custody['custody']);
    }

    /** @return array<int, array<string, mixed>> one row per active company vehicle */
    public function forCompany(int $companyId, ?\DateTimeImmutable $asOf = null): array
    {
        if ($companyId < 1) {
            return [];
        }
        $asOf ??= new \DateTimeImmutable();
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId, $asOf->format('Y-m-d'));
        $vehicleIds = array_map('intval', array_column($vehicles, 'id'));
        $custody = $this->custody()->forCompany($companyId, $vehicleIds, $asOf);
        $locations = $this->repo()->latestLocationEventsForCompany(
            $companyId,
            $vehicleIds,
            $asOf->format('Y-m-d H:i:s'),
        );

        return array_map(function (array $vehicle) use ($custody, $locations, $asOf): array {
            $vehicleId = (int) $vehicle['id'];
            $state = $custody[$vehicleId] ?? ['custody' => 'unknown', 'basis_event' => null];
            $lifecycle = $state['basis_event'] ?? null;
            if (($lifecycle['event_code'] ?? null) === 'guest_return_staged') {
                return array_merge($vehicle, $this->awaitingRecovery($lifecycle));
            }
            if (($state['custody'] ?? null) === 'guest' && is_array($lifecycle)) {
                return array_merge($vehicle, $this->presentEvent($lifecycle, $asOf, 'guest'));
            }
            $event = $this->operatorLocationEvent($state, $locations[$vehicleId] ?? null);
            if ($event === null) {
                return array_merge($vehicle, $this->unknownLocation());
            }

            return array_merge($vehicle, $this->presentEvent($event, $asOf, (string) ($state['custody'] ?? 'unknown')));
        }, $vehicles);
    }

    /** @return array<string, mixed> */
    private function awaitingRecovery(array $report): array
    {
        return array_merge($this->unknownLocation(), [
            'operational_state' => 'awaiting_recovery',
            'position_semantics' => 'unverified_guest_report',
            'location_label' => 'Current position unverified',
            'reported_location_class' => $report['location_class'] ?? null,
            'reported_location_detail' => $report['location_detail'] ?? null,
            'reported_airport_garage_code' => $report['airport_garage_code'] ?? null,
            'reported_airport_parking_level' => $report['airport_parking_level'] ?? null,
            'reported_airport_parking_row' => $report['airport_parking_row'] ?? null,
            'reported_at' => $report['occurred_at'],
            'report_time_basis' => $report['source'] ?? null,
            'trip_id' => $report['turo_trip_normalized_id'],
            'event_id' => $report['id'],
            'event_code' => 'guest_return_staged',
        ]);
    }

    /** @return array<string, mixed> */
    private function presentEvent(array $event, \DateTimeImmutable $asOf, string $custody): array
    {
        $observed = new \DateTimeImmutable((string) $event['occurred_at']);
        $eventCode = (string) ($event['event_code'] ?? '');
        $isRented = $custody === 'guest';
        $isCurrent = $custody === 'operator';

        return [
            'location_class' => trim((string) ($event['location_class'] ?? '')) ?: 'unknown',
            'location_detail' => $event['location_detail'] ?? null,
            'location_note' => $this->recoveryLocationNote($event),
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

    /** @param array<string, mixed> $custody @return array<string, mixed>|null */
    private function operatorLocationEvent(array $custody, ?array $latestLocation): ?array
    {
        $basis = $custody['basis_event'] ?? null;
        if (($custody['custody'] ?? null) !== 'operator' || ! is_array($basis)) {
            return $latestLocation;
        }
        if ($latestLocation === null || (string) ($latestLocation['occurred_at'] ?? '') < (string) ($basis['occurred_at'] ?? '')) {
            return $basis;
        }
        $locationTripId = (int) ($latestLocation['turo_trip_normalized_id'] ?? 0);
        $basisTripId = (int) ($basis['turo_trip_normalized_id'] ?? 0);
        if ($locationTripId > 0 && $basisTripId > 0 && $locationTripId !== $basisTripId) {
            return $basis;
        }

        return $latestLocation;
    }

    /** @return array<string, mixed> */
    private function unknownLocation(): array
    {
        return ['location_class' => 'unknown', 'location_detail' => null, 'location_note' => null, 'airport_garage_code' => null, 'airport_parking_level' => null, 'airport_parking_row' => null, 'observed_at' => null, 'source' => null, 'actor_user_id' => null, 'trip_id' => null, 'event_id' => null, 'event_code' => null, 'operational_state' => 'unknown', 'position_semantics' => 'unknown', 'location_label' => 'Current vehicle position', 'age_seconds' => null];
    }

    private function recoveryLocationNote(array $event): ?string
    {
        if (($event['event_code'] ?? null) !== 'vehicle_recovered'
            || preg_match('/(?:^|\R)Recovery location detail: ([^\r\n]+)/', (string) ($event['note'] ?? ''), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }

    private function custody(): CurrentVehicleCustodyService
    {
        return $this->custodyService ?? new CurrentVehicleCustodyService($this->repo());
    }
}
