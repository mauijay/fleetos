<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use Config\Services;

/** One trip-aware authority for current vehicle custody. */
class CurrentVehicleCustodyService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    /** @return array{custody:string,active_trip_id:?int,basis_trip_id:?int,basis_event_id:?int,basis_event_code:?string,occurred_at:?string,basis_event:?array<string,mixed>} */
    public function resolve(int $vehicleId, ?\DateTimeImmutable $asOf = null): array
    {
        if ($vehicleId < 1) {
            return $this->unknown();
        }
        $asOf ??= new \DateTimeImmutable();

        return $this->resolveEvents($this->repo()->activeCustodyTimelineEvents(
            [$vehicleId],
            $asOf->format('Y-m-d H:i:s'),
        ));
    }

    /** @param list<int> $vehicleIds @return array<int, array<string, mixed>> keyed by fleet vehicle id */
    public function forCompany(int $companyId, array $vehicleIds, ?\DateTimeImmutable $asOf = null): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
        if ($companyId < 1 || $vehicleIds === []) {
            return [];
        }
        $asOf ??= new \DateTimeImmutable();
        $events = $this->repo()->activeCustodyTimelineEvents($vehicleIds, $asOf->format('Y-m-d H:i:s'), $companyId);
        $byVehicle = [];
        foreach ($events as $event) {
            $byVehicle[(int) $event['fleet_vehicle_id']][] = $event;
        }

        $resolved = [];
        foreach ($vehicleIds as $vehicleId) {
            $resolved[$vehicleId] = $this->resolveEvents($byVehicle[$vehicleId] ?? []);
        }

        return $resolved;
    }

    /**
     * Finds an authoritative handoff belonging to a reservation later than the
     * proposed return/recovery trip when that handoff has already occurred.
     *
     * @return array<string, mixed>|null
     */
    public function laterHandoffConflict(int $vehicleId, int $tripId, string $proposedAt): ?array
    {
        $handoff = $this->laterTripHandoff($vehicleId, $tripId);

        if ($handoff === null) {
            return null;
        }

        try {
            $handoffAt = new \DateTimeImmutable((string) ($handoff['occurred_at'] ?? ''));
            $proposed = new \DateTimeImmutable($proposedAt);
        } catch (\Exception) {
            return null;
        }

        return $handoffAt <= $proposed ? $handoff : null;
    }

    /** @return array<string, mixed>|null */
    public function laterTripHandoff(int $vehicleId, int $tripId): ?array
    {
        $target = $this->repo()->tripSchedule($tripId);
        $targetStartsAt = trim((string) ($target['starts_at'] ?? ''));
        if ($vehicleId < 1 || $tripId < 1 || $targetStartsAt === '') {
            return null;
        }

        $events = $this->repo()->activeCustodyTimelineEvents([$vehicleId], date('Y-m-d H:i:s'));
        $conflicts = array_values(array_filter($events, function (array $event) use ($tripId, $targetStartsAt): bool {
            return (int) ($event['turo_trip_normalized_id'] ?? 0) !== $tripId
                && ($event['event_code'] ?? null) === 'actual_handoff'
                && $this->tripIsOperational($event)
                && (string) ($event['custody_trip_starts_at'] ?? '') > $targetStartsAt;
        }));
        usort($conflicts, fn (array $left, array $right): int => $this->compareTripChronology($left, $right));

        return $conflicts[0] ?? null;
    }

    /** Returns whether the selected trip precedes the current trip-aware custody basis. */
    public function hasLaterTripLifecycle(int $vehicleId, int $tripId, ?\DateTimeImmutable $asOf = null): bool
    {
        $target = $this->repo()->tripSchedule($tripId);
        $targetStartsAt = trim((string) ($target['starts_at'] ?? ''));
        $basis = $this->resolve($vehicleId, $asOf)['basis_event'] ?? null;

        return $targetStartsAt !== '' && is_array($basis)
            && (int) ($basis['turo_trip_normalized_id'] ?? 0) !== $tripId
            && (string) ($basis['custody_trip_starts_at'] ?? '') > $targetStartsAt;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{custody:string,active_trip_id:?int,basis_trip_id:?int,basis_event_id:?int,basis_event_code:?string,occurred_at:?string,basis_event:?array<string,mixed>}
     */
    public function resolveEvents(array $events): array
    {
        $events = array_values(array_filter($events, fn (array $event): bool => $this->tripIsOperational($event)));
        if ($events === []) {
            return $this->unknown();
        }

        $byTrip = [];
        foreach ($events as $event) {
            $tripId = (int) ($event['turo_trip_normalized_id'] ?? 0);
            if ($tripId > 0) {
                $byTrip[$tripId][] = $event;
            }
        }
        if ($byTrip === []) {
            return $this->unknown();
        }

        $tripStates = array_map($this->resolveTripEvents(...), array_values($byTrip));
        $guestStates = array_values(array_filter(
            $tripStates,
            static fn (array $state): bool => $state['custody'] === 'guest',
        ));

        if ($guestStates !== []) {
            usort($guestStates, function (array $left, array $right): int {
                $tripComparison = $this->compareTripChronology($left['basis'], $right['basis']);

                return $tripComparison !== 0
                    ? $tripComparison
                    : $this->compareEventChronology($left['basis'], $right['basis']);
            });
            $basis = $guestStates[count($guestStates) - 1]['basis'];
        } else {
            usort($tripStates, function (array $left, array $right): int {
                $eventComparison = $this->compareEventChronology($left['basis'], $right['basis']);

                return $eventComparison !== 0
                    ? $eventComparison
                    : $this->compareTripChronology($left['basis'], $right['basis']);
            });
            $basis = $tripStates[count($tripStates) - 1]['basis'];
        }

        $code = (string) ($basis['event_code'] ?? '');
        $custody = in_array($code, ['actual_handoff', 'guest_return_staged'], true)
            ? 'guest'
            : (in_array($code, ['vehicle_staged', 'actual_return', 'vehicle_recovered'], true) ? 'operator' : 'unknown');
        $tripId = (int) ($basis['turo_trip_normalized_id'] ?? 0) ?: null;
        $activeTripId = in_array($code, ['vehicle_staged', 'actual_handoff', 'guest_return_staged'], true) ? $tripId : null;

        return [
            'custody' => $custody,
            'active_trip_id' => $activeTripId,
            'basis_trip_id' => $tripId,
            'basis_event_id' => isset($basis['id']) ? (int) $basis['id'] : null,
            'basis_event_code' => $code === '' ? null : $code,
            'occurred_at' => $basis['occurred_at'] ?? null,
            'basis_event' => $basis,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{custody:string,basis:array<string, mixed>}
     */
    private function resolveTripEvents(array $events): array
    {
        usort($events, $this->compareEventChronology(...));
        $handoffs = array_values(array_filter($events, static fn (array $event): bool => ($event['event_code'] ?? null) === 'actual_handoff'));
        $handoff = $handoffs === [] ? null : end($handoffs);
        if (is_array($handoff)) {
            $basis = $handoff;
            foreach ($events as $event) {
                if (! in_array($event['event_code'] ?? null, ['actual_handoff', 'guest_return_staged', 'actual_return', 'vehicle_recovered'], true)) {
                    continue;
                }
                if ($this->compareEventChronology($event, $handoff) >= 0
                    && $this->compareEventChronology($event, $basis) >= 0) {
                    $basis = $event;
                }
            }
        } else {
            $basis = $events[count($events) - 1];
        }

        return [
            'custody' => in_array($basis['event_code'] ?? null, ['actual_handoff', 'guest_return_staged'], true) ? 'guest' : 'operator',
            'basis' => $basis,
        ];
    }

    private function tripIsOperational(array $event): bool
    {
        $status = strtolower(trim((string) ($event['custody_trip_status_code'] ?? '')));

        return ($event['custody_trip_canceled_at'] ?? null) === null
            && $status !== 'invalid'
            && ! str_starts_with($status, 'canceled');
    }

    private function compareTripChronology(array $left, array $right): int
    {
        $comparison = strcmp((string) ($left['custody_trip_starts_at'] ?? ''), (string) ($right['custody_trip_starts_at'] ?? ''));
        if ($comparison !== 0) {
            return $comparison;
        }

        return (int) ($left['turo_trip_normalized_id'] ?? 0) <=> (int) ($right['turo_trip_normalized_id'] ?? 0);
    }

    private function compareEventChronology(array $left, array $right): int
    {
        $comparison = strcmp((string) ($left['occurred_at'] ?? ''), (string) ($right['occurred_at'] ?? ''));
        if ($comparison !== 0) {
            return $comparison;
        }

        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
    }

    /** @return array{custody:string,active_trip_id:null,basis_trip_id:null,basis_event_id:null,basis_event_code:null,occurred_at:null,basis_event:null} */
    private function unknown(): array
    {
        return [
            'custody' => 'unknown',
            'active_trip_id' => null,
            'basis_trip_id' => null,
            'basis_event_id' => null,
            'basis_event_code' => null,
            'occurred_at' => null,
            'basis_event' => null,
        ];
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? Services::operationalFactsRepository();
    }
}
