<?php

namespace App\Services\Fleet;

use App\Repositories\MovementReadinessReadModelRepository;

class MovementReadinessReadService
{
    public function __construct(
        private readonly MovementReadinessReadModelRepository $repository,
        private readonly MovementReadinessProjectionService $projector,
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

        return array_map(
            fn (array $context): array => $this->projector->project($context),
            $contexts,
        );
    }
}
