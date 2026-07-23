<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

class FleetCapacityService
{
    public function __construct(private readonly ?FleetIntelligenceRepository $repository = null)
    {
    }

    /**
     * @return array{occupied_vehicle_days:int,available_vehicle_days:int,utilization:float}
     */
    public function utilizationForRange(DateTimeImmutable $startsAt, DateTimeImmutable $endsAtExclusive): array
    {
        $occupiedVehicleDays = $this->occupiedVehicleDays($startsAt, $endsAtExclusive);
        $availableVehicleDays = $this->availableVehicleDays($startsAt, $endsAtExclusive);

        return [
            'occupied_vehicle_days' => $occupiedVehicleDays,
            'available_vehicle_days' => $availableVehicleDays,
            'utilization' => $availableVehicleDays <= 0 ? 0.0 : round($occupiedVehicleDays / $availableVehicleDays, 4),
        ];
    }

    public function occupiedVehicleDays(DateTimeImmutable $startsAt, DateTimeImmutable $endsAtExclusive): int
    {
        $reservations = $this->repo()->operationalReservationsBetween($startsAt->format('Y-m-d H:i:s'), $endsAtExclusive->format('Y-m-d H:i:s'));
        $occupied = [];

        foreach ($reservations as $reservation) {
            $vehicleId = (int) ($reservation['fleet_vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            if (str_starts_with((string) ($reservation['status_code'] ?? ''), 'canceled')) {
                continue;
            }

            $windowStart = new DateTimeImmutable((string) $reservation['starts_at']);
            $windowEnd = new DateTimeImmutable((string) $reservation['ends_at']);

            if ($windowEnd <= $startsAt || $windowStart >= $endsAtExclusive) {
                continue;
            }

            if ($windowStart < $startsAt) {
                $windowStart = $startsAt;
            }

            if ($windowEnd > $endsAtExclusive) {
                $windowEnd = $endsAtExclusive;
            }

            $lastOccupiedMoment = $windowEnd->modify('-1 second');
            if ($lastOccupiedMoment < $windowStart) {
                continue;
            }

            $dayCursor = $windowStart->setTime(0, 0);
            $lastDay = $lastOccupiedMoment->setTime(0, 0);

            while ($dayCursor <= $lastDay) {
                $occupied[$vehicleId . '|' . $dayCursor->format('Y-m-d')] = true;
                $dayCursor = $dayCursor->modify('+1 day');
            }
        }

        return count($occupied);
    }

    public function availableVehicleDays(DateTimeImmutable $startsAt, DateTimeImmutable $endsAtExclusive): int
    {
        $vehicles = $this->repo()->fleetVehicles();
        $dayCount = 0;

        $period = new DatePeriod(
            $startsAt->setTime(0, 0),
            new DateInterval('P1D'),
            $endsAtExclusive->setTime(0, 0),
        );

        foreach ($period as $day) {
            foreach ($vehicles as $vehicle) {
                if ($this->isVehicleAvailableForDay($vehicle, $day)) {
                    $dayCount++;
                }
            }
        }

        return $dayCount;
    }

    private function isVehicleAvailableForDay(array $vehicle, DateTimeImmutable $day): bool
    {
        if (! (bool) ($vehicle['is_available_for_booking'] ?? false)) {
            return false;
        }

        $inServiceDate = ($vehicle['in_service_date'] ?? null) !== null
            ? new DateTimeImmutable((string) $vehicle['in_service_date'])
            : null;
        $outOfServiceDate = ($vehicle['out_of_service_date'] ?? null) !== null
            ? new DateTimeImmutable((string) $vehicle['out_of_service_date'])
            : null;

        if ($inServiceDate !== null && $day < $inServiceDate->setTime(0, 0)) {
            return false;
        }

        if ($outOfServiceDate !== null && $day > $outOfServiceDate->setTime(0, 0)) {
            return false;
        }

        return true;
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }
}
