<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use Config\Services;
use DateTimeImmutable;
use RuntimeException;

/** Shared, company-scoped movement completion and physical-work read model. */
class OperationalMovementWorkService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly ?NextConfirmedTripService $nextTripService = null,
        private readonly ?TripEnergyRuleResolver $energyRuleResolver = null,
        private readonly ?CurrentVehicleCustodyService $custodyService = null,
    ) {
    }

    public function singleActiveCompanyId(DateTimeImmutable $asOf): int
    {
        $ids = $this->repo()->activeFleetCompanyIds($asOf->format('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Operational work requires exactly one active fleet company context.');
        }

        return (int) $ids[0];
    }

    /** @param list<array<string, mixed>> $facts @return array<string, array<string, mixed>> */
    public function completionByMovement(array $facts): array
    {
        $completions = [];
        foreach ($facts as $fact) {
            $type = match ($fact['event_code'] ?? null) {
                'actual_handoff' => 'pickup',
                'actual_return', 'vehicle_recovered' => 'return',
                default => null,
            };
            $tripId = (int) ($fact['turo_trip_normalized_id'] ?? 0);
            $recordedAt = (string) ($fact['created_at'] ?? $fact['occurred_at'] ?? '');
            if ($type === null || $tripId < 1 || $recordedAt === '') {
                continue;
            }
            $key = $tripId . ':' . $type;
            if (! isset($completions[$key]) || $recordedAt > $completions[$key]['recorded_at']) {
                $completions[$key] = array_merge($fact, ['recorded_at' => $recordedAt]);
            }
        }

        return $completions;
    }

    /** @param list<int> $tripIds @return array<string, array<string, mixed>> */
    public function completionsForCompany(int $companyId, array $tripIds, DateTimeImmutable $asOf): array
    {
        return $this->completionByMovement($this->repo()->authoritativeMovementCompletionsForCompany(
            $companyId,
            $tripIds,
            $asOf->format('Y-m-d H:i:s'),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function awaitingRecoveryForCompany(int $companyId, DateTimeImmutable $asOf): array
    {
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId, $asOf->format('Y-m-d'));
        $custody = $this->custody()->forCompany($companyId, array_map('intval', array_column($vehicles, 'id')), $asOf);
        $events = [];
        foreach ($custody as $state) {
            if (($state['basis_event_code'] ?? null) === 'guest_return_staged' && is_array($state['basis_event'] ?? null)) {
                $events[] = $state['basis_event'];
            }
        }

        return array_map(function (array $event): array {
            $tripId = (int) $event['turo_trip_normalized_id'];
            $garage = (new HnlGarageCatalog())->presentation($event['airport_garage_code'] ?? null, $event['airport_parking_level'] ?? null, $event['airport_parking_row'] ?? null);

            return array_merge($event, [
                'fleet_vehicle_id' => (int) $event['fleet_vehicle_id'],
                'fleet_label' => trim((string) ($event['display_name'] ?? '')) ?: (string) $event['fleet_code'],
                'reported_location_label' => $garage === null ? 'HNL location unverified' : $garage['location_label'] . ' · unverified',
                'href' => ($this->repo()->movementChecklistHref($tripId, 'return') ?? '/operations/vehicles/' . (int) $event['fleet_vehicle_id'] . '/trip-history?trip=' . $tripId) . '#recover-vehicle-entry',
            ]);
        }, $events);
    }

    /** @return list<array<string, mixed>>
     *  @phpstan-impure Reads mutable movement and assessment state.
     */
    public function cleaningNeedsForCompany(int $companyId, DateTimeImmutable $asOf): array
    {
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId);
        $vehicleIds = array_map('intval', array_column($vehicles, 'id'));
        $timestamp = $asOf->format('Y-m-d H:i:s');
        $custody = $this->custody()->forCompany($companyId, $vehicleIds, $asOf);
        $cleanliness = $this->repo()->latestCleanlinessForCompany($companyId, $vehicleIds, $timestamp);
        $needs = [];
        foreach ($vehicles as $vehicle) {
            $id = (int) $vehicle['id'];
            $event = $custody[$id]['basis_event'] ?? null;
            $assessment = $cleanliness[$id] ?? null;
            if (! in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
                || (($assessment['cleanliness'] ?? null) === 'clean'
                    && (string) ($assessment['captured_at'] ?? '') > (string) ($event['occurred_at'] ?? ''))) {
                continue;
            }
            $needs[] = array_merge($vehicle, [
                'fleet_vehicle_id' => $id,
                'turo_trip_normalized_id' => (int) ($event['turo_trip_normalized_id'] ?? 0),
                'cleanliness' => $assessment['cleanliness'] ?? null,
                'condition_observed_at' => $assessment['captured_at'] ?? null,
                'recovered_at' => $event['occurred_at'],
            ]);
        }

        return $needs;
    }

    /** @return list<array<string, mixed>> One current energy action per operator-held vehicle.
     *  @phpstan-impure Reads mutable movement and assessment state.
     */
    public function energyNeedsForCompany(int $companyId, DateTimeImmutable $asOf): array
    {
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId);
        $vehicleIds = array_map('intval', array_column($vehicles, 'id'));
        $timestamp = $asOf->format('Y-m-d H:i:s');
        $custody = $this->custody()->forCompany($companyId, $vehicleIds, $asOf);
        $measurements = $this->repo()->latestEnergyForCompany($companyId, $vehicleIds, $timestamp);
        $needs = [];
        foreach ($vehicles as $vehicle) {
            $id = (int) $vehicle['id'];
            $event = $custody[$id]['basis_event'] ?? null;
            if (! in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)) {
                continue;
            }
            $profile = $this->repo()->profile($id);
            $nextTrip = $this->nextTrips()->forVehicle($id, $asOf);
            $targetTripId = (int) ($nextTrip['id'] ?? 0);
            $rule = $targetTripId > 0
                ? $this->energyRules()->forTrip($companyId, $targetTripId, $profile)
                : $this->energyRules()->forProfile($profile);
            $target = $this->energyRules()->readinessMinimum($rule);
            $measurement = $measurements[$id] ?? null;
            $isRecoveryMeasurement = $measurement !== null
                && (int) ($measurement['trip_movement_event_id'] ?? 0) === (int) $event['id'];
            $isLaterObservation = $measurement !== null
                && (string) $measurement['captured_at'] > (string) $event['occurred_at'];
            $isCurrent = $measurement !== null && ($isLaterObservation
                || ($isRecoveryMeasurement && ($targetTripId === 0 || ($nextTrip['is_same_day_turnaround'] ?? false) === true)));
            $energy = $isCurrent ? (int) $measurement['energy_percent'] : null;
            $evaluation = $this->energyRules()->evaluate($rule, $energy);
            $condition = $evaluation['condition'];
            if ($condition === null) {
                continue;
            }
            $tripId = (int) ($event['turo_trip_normalized_id'] ?? 0);
            $energyKind = (string) ($profile['energy_kind'] ?? 'unknown');
            $noun = $energyKind === 'electric' ? 'Charge' : ($energyKind === 'unknown' ? 'Fuel/Charge' : 'Fuel');
            $needs[] = array_merge($vehicle, [
                'fleet_vehicle_id' => $id,
                'turo_trip_normalized_id' => $tripId,
                'target_trip_id' => $targetTripId > 0 ? $targetTripId : null,
                'condition_code' => $condition,
                'label' => match ($condition) {
                    'charge_required' => $noun . ' Needed — ' . $energy . '%, ' . $this->policyDescription($rule),
                    'measurement_needed' => $noun . ' level unknown',
                    'above_maximum' => 'Above guest-requested maximum — ' . $energy . '%, limit ' . $target . '%',
                    default => $noun . ' readiness not configured',
                },
                'action_label' => $condition === 'target_needed'
                    ? 'Configure Vehicle'
                    : ($condition === 'above_maximum'
                        ? 'Review guest charge limit'
                        : ($condition === 'measurement_needed'
                            ? 'Record current ' . $noun . ' percentage'
                            : str_replace('Charge/Fuel', $noun, (string) $evaluation['action_label']))),
                'energy_percent' => $energy,
                'last_known_energy_percent' => $measurement === null ? null : (int) $measurement['energy_percent'],
                'last_known_energy_at' => $measurement['captured_at'] ?? null,
                'target_percent' => $target,
                'energy_rule' => $rule,
                'energy_policy_label' => $this->energyRules()->policyLabel($rule, ($rule['source'] ?? null) === 'trip_commitment'),
                'energy_kind' => $energyKind,
                'configuration_required' => $condition === 'target_needed',
                'href' => $condition === 'target_needed'
                    ? '/fleet/vehicles/' . $id . '/edit'
                    : ($this->repo()->movementChecklistHref($tripId, 'return') ?? '/fleet/vehicles/' . $id) . '#turnaround-actions',
            ]);
        }

        return $needs;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? Services::operationalFactsRepository();
    }

    private function nextTrips(): NextConfirmedTripService
    {
        return $this->nextTripService ?? Services::nextConfirmedTripService();
    }

    private function energyRules(): TripEnergyRuleResolver
    {
        return $this->energyRuleResolver ?? Services::tripEnergyRuleResolver();
    }

    private function custody(): CurrentVehicleCustodyService
    {
        return $this->custodyService ?? new CurrentVehicleCustodyService($this->repo());
    }

    /** @param array<string, mixed> $rule */
    private function policyDescription(array $rule): string
    {
        return match ($rule['mode'] ?? null) {
            'preferred_range' => 'ready range ' . (int) $rule['minimum_percent'] . '–' . (int) $rule['preferred_max_percent'] . '%',
            'target' => 'trip target ' . (int) $rule['target_percent'] . '%',
            default => 'minimum ' . (int) $rule['minimum_percent'] . '%',
        };
    }
}
