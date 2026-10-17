<?php

namespace App\DTOs\Fleet;

/** Immutable value snapshot; contains no models, writers or live database objects. */
final readonly class OperationalReconciliation implements \JsonSerializable
{
    /** @param array<int, array<string, mixed>> $vehicles */
    public function __construct(public int $companyId, public string $asOf, public array $vehicles)
    {
    }

    public function jsonSerialize(): array
    {
        return ['company_id' => $this->companyId, 'as_of' => $this->asOf, 'vehicles' => $this->vehicles];
    }
}
