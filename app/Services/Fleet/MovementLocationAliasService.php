<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class MovementLocationAliasService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function unknownSources(): array
    {
        return $this->repo()->unknownLocationSources();
    }

    public function save(int $companyId, string $sourceText, string $locationClass, ?string $note, int $actorUserId): int
    {
        if ($companyId < 1 || $actorUserId < 1 || trim($sourceText) === '') {
            throw new \InvalidArgumentException('Company, source location, and actor are required.');
        }
        if (! in_array($locationClass, array_diff(LocationClassificationService::CLASSES, ['unknown']), true)) {
            throw new \InvalidArgumentException('Choose a supported operational location class.');
        }

        return $this->repo()->saveLocationAlias(
            $companyId,
            trim($sourceText),
            $this->normalize($sourceText),
            $locationClass,
            trim((string) $note) ?: null,
            $actorUserId,
        );
    }

    /** @return array<string, mixed>|null */
    public function match(int $companyId, ?string $sourceText): ?array
    {
        if ($companyId < 1 || trim((string) $sourceText) === '') {
            return null;
        }

        return $this->repo()->locationAlias($companyId, $this->normalize((string) $sourceText));
    }

    public function normalize(string $sourceText): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $sourceText) ?? $sourceText));
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
