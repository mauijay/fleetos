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
    ) {
    }

    /**
     * @param list<int> $checklistIds
     * @return array<int, array<string, mixed>>
     */
    public function forCompany(int $companyId, array $checklistIds, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $contexts = $this->repository->loadForCompany($companyId, $checklistIds, $asOf);

        foreach ($contexts as &$context) {
            $tripId = (int) $context['turo_trip_normalized_id'];
            $movementType = (string) $context['movement_type'];
            $phases = $movementType === 'return' ? ['return', 'entire_trip'] : ['preparation', 'pickup', 'entire_trip'];
            $context['active_commitments'] = $this->commitmentService?->activeForTrip($companyId, $tripId, $phases) ?? [];
            $context['energy_rule'] = $this->energyRuleResolver?->forTrip($companyId, $tripId, $context['profile'] ?? null)
                ?? (new TripEnergyRuleResolver())->forProfile($context['profile'] ?? null);
            $nextTripId = (int) ($context['next_trip']['id'] ?? 0);
            $context['next_trip_commitments'] = $nextTripId > 0 && $this->commitmentService !== null
                ? $this->commitmentService->activeForTrip($companyId, $nextTripId, ['preparation', 'pickup', 'entire_trip'])
                : [];
            $context['next_trip_energy_rule'] = $nextTripId > 0 && $this->energyRuleResolver !== null
                ? $this->energyRuleResolver->forTrip($companyId, $nextTripId, $context['profile'] ?? null)
                : null;
        }
        unset($context);

        return array_map(
            fn (array $context): array => $this->projector->project($context),
            $contexts,
        );
    }

}
