<?php

namespace App\Services\Fleet;

use App\Repositories\MovementReadinessReadModelRepository;

class MovementReadinessReadService
{
    public function __construct(
        private readonly MovementReadinessReadModelRepository $repository,
        private readonly MovementReadinessProjectionService $projector,
        private readonly ?TripCommitmentService $commitmentService = null,
        private readonly ?TripEnergyRuleResolver $energyRuleResolver = null,
        private readonly ?TripExtraFulfillmentService $extraFulfillmentService = null,
        private readonly ?FleetExtraService $extraVerificationService = null,
        private readonly ?GuestCommitmentProjectionService $commitmentProjection = null,
    ) {
    }

    /**
     * @param list<int> $checklistIds
     * @return array<int, array<string, mixed>>
     */
    public function forCompany(int $companyId, array $checklistIds, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $asOf = $asOf->setTimezone(new \DateTimeZone((new \Config\App())->appTimezone));
        $contexts = $this->repository->loadForCompany($companyId, $checklistIds, $asOf);
        $tripIds = [];
        foreach ($contexts as $context) {
            $tripIds[] = (int) $context['turo_trip_normalized_id'];
            if ((int) ($context['next_trip']['id'] ?? 0) > 0) {
                $tripIds[] = (int) $context['next_trip']['id'];
            }
        }
        $projections = $this->commitmentProjection?->forTrips($companyId, array_values(array_unique($tripIds)), $asOf);
        $extrasByTrip = $projections === null ? ($this->extraFulfillmentService?->forTrips($companyId, array_values(array_unique($tripIds))) ?? []) : array_column($projections, 'purchased', 'trip_id');

        $verificationByTrip = $projections === null ? ($this->extraVerificationService?->verificationForTrips($companyId, array_values(array_unique($tripIds)), $asOf) ?? []) : array_column($projections, 'verification', 'trip_id');
        foreach ($contexts as &$context) {
            $context['as_of'] = $asOf->format('Y-m-d H:i:s');
            $tripId = (int) $context['turo_trip_normalized_id'];
            $movementType = (string) $context['movement_type'];
            $phases = $movementType === 'return' ? ['return', 'entire_trip'] : ['preparation', 'pickup', 'entire_trip'];
            $context['active_commitments'] = $projections === null ? ($this->commitmentService?->activeForTrip($companyId, $tripId, $phases) ?? []) : array_values(array_filter($projections[$tripId]['manual'] ?? [], static fn (array $row): bool => in_array($row['phase'], $phases, true)));
            $context['energy_rule'] = $this->energyRuleResolver?->forTrip($companyId, $tripId, $context['profile'] ?? null)
                ?? (new TripEnergyRuleResolver())->forProfile($context['profile'] ?? null);
            $context['extra_fulfillments'] = $extrasByTrip[$tripId] ?? [];
            $context['extra_verification'] = $verificationByTrip[$tripId] ?? null;
            $nextTripId = (int) ($context['next_trip']['id'] ?? 0);
            $context['next_trip_commitments'] = $projections !== null ? array_values(array_filter($projections[$nextTripId]['manual'] ?? [], static fn (array $row): bool => in_array($row['phase'], ['preparation', 'pickup', 'entire_trip'], true))) : ($nextTripId > 0 && $this->commitmentService !== null
                ? $this->commitmentService->activeForTrip($companyId, $nextTripId, ['preparation', 'pickup', 'entire_trip'])
                : []);
            $context['next_trip_energy_rule'] = $nextTripId > 0 && $this->energyRuleResolver !== null
                ? $this->energyRuleResolver->forTrip($companyId, $nextTripId, $context['profile'] ?? null)
                : null;
            $context['next_trip_extra_fulfillments'] = $extrasByTrip[$nextTripId] ?? [];
            $context['next_trip_extra_verification'] = $verificationByTrip[$nextTripId] ?? null;
        }
        unset($context);

        return array_map(
            fn (array $context): array => $this->projector->project($context),
            $contexts,
        );
    }

}
