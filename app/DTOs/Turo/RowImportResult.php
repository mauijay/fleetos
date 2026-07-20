<?php

namespace App\DTOs\Turo;

class RowImportResult
{
    /** @param ValidationIssue[] $issues */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $normalizedTripId = null,
        public readonly bool $created = false,
        public readonly int $allocationCount = 0,
        public readonly array $issues = [],
    ) {
    }
}
