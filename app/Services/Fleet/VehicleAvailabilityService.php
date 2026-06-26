<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateTimeImmutable;

class VehicleAvailabilityService
{
    public function __construct(private readonly ?FleetIntelligenceRepository $repository = null)
    {
    }

    /** Returns vehicles available at the requested instant. */
    public function availableNow(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return array_values(array_filter($this->vehicleStatus($asOf), static fn (array $vehicle): bool => $vehicle['status'] === 'available'));
    }

    /** Returns vehicles available tomorrow. */
    public function availableTomorrow(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        return array_values(array_filter($this->vehicleStatus($asOf->modify('+1 day')), static fn (array $vehicle): bool => $vehicle['status'] === 'available'));
    }

    /** Returns reservation and delivery timeline entries for the requested period. */
    public function timeline(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        $reservations = array_map(static function (array $reservation): array {
            return [
                'type' => 'reservation',
                'source' => $reservation['source'] ?? 'unknown',
                'fleet_vehicle_id' => $reservation['fleet_vehicle_id'],
                'starts_at' => $reservation['starts_at'],
                'ends_at' => $reservation['ends_at'],
                'status' => $reservation['status_code'],
                'reservation' => $reservation,
            ];
        }, $this->repo()->reservationsBetween($startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s')));

        $deliveries = array_map(static function (array $delivery): array {
            return [
                'type' => 'airport_delivery',
                'fleet_vehicle_id' => $delivery['fleet_vehicle_id'],
                'starts_at' => $delivery['scheduled_at'],
                'ends_at' => $delivery['completed_at'],
                'status' => $delivery['delivery_status_lookup_value_id'],
                'delivery' => $delivery,
            ];
        }, $this->repo()->airportDeliveriesBetween($startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s')));

        $timeline = array_merge($reservations, $deliveries);
        usort($timeline, static fn (array $left, array $right): int => strcmp((string) $left['starts_at'], (string) $right['starts_at']));

        return $timeline;
    }

    /** Returns operational status of every vehicle at the requested instant. */
    public function vehicleStatus(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $now = $asOf->format('Y-m-d H:i:s');
        $futureWindow = $asOf->modify('+180 days')->format('Y-m-d H:i:s');
        $reservations = $this->repo()->reservationsBetween($asOf->modify('-30 days')->format('Y-m-d H:i:s'), $futureWindow);
        $deliveries = $this->repo()->airportDeliveriesBetween($now, $asOf->modify('+14 days')->format('Y-m-d H:i:s'));

        return array_map(function (array $vehicle) use ($asOf, $reservations, $deliveries): array {
            $vehicleReservations = array_values(array_filter($reservations, static fn (array $reservation): bool => (int) ($reservation['fleet_vehicle_id'] ?? 0) === (int) $vehicle['id']));
            $current = $this->currentReservation($vehicleReservations, $asOf);
            $future = array_values(array_filter($vehicleReservations, static fn (array $reservation): bool => ($reservation['starts_at'] ?? '') > $asOf->format('Y-m-d H:i:s')));
            $nextReservation = $future[0] ?? null;

            return [
                'fleet_vehicle_id' => (int) $vehicle['id'],
                'fleet_code' => $vehicle['fleet_code'],
                'display_name' => $vehicle['display_name'],
                'status' => $this->statusForVehicle($vehicle, $current),
                'next_reservation' => $nextReservation,
                'current_battery' => null,
                'current_location' => null,
                'current_odometer' => $vehicle['odometer_miles'] === null ? null : (int) $vehicle['odometer_miles'],
                'cleaning_status' => $current === null ? 'ready' : 'pending_after_return',
                'airport_delivery_scheduled' => $this->hasUpcomingDelivery((int) $vehicle['id'], $deliveries),
                'upcoming_maintenance' => null,
                'future_reservations' => $future,
            ];
        }, $this->repo()->fleetVehicles());
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    /** @param array<int, array<string, mixed>> $reservations */
    private function currentReservation(array $reservations, DateTimeImmutable $asOf): ?array
    {
        $now = $asOf->format('Y-m-d H:i:s');

        foreach ($reservations as $reservation) {
            if (($reservation['starts_at'] ?? '') <= $now && ($reservation['ends_at'] ?? '') >= $now) {
                return $reservation;
            }
        }

        return null;
    }

    private function statusForVehicle(array $vehicle, ?array $currentReservation): string
    {
        if (! (bool) ($vehicle['is_available_for_booking'] ?? false)) {
            return (string) ($vehicle['status_code'] ?? 'out_of_service');
        }

        if ($currentReservation !== null) {
            return ($currentReservation['status_code'] ?? '') === 'in_progress' ? 'in_progress' : 'reserved';
        }

        return 'available';
    }

    /** @param array<int, array<string, mixed>> $deliveries */
    private function hasUpcomingDelivery(int $vehicleId, array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ((int) ($delivery['fleet_vehicle_id'] ?? 0) === $vehicleId) {
                return true;
            }
        }

        return false;
    }
}
