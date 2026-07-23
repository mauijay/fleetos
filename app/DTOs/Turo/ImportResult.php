<?php

namespace App\DTOs\Turo;

class ImportResult
{
    public function __construct(
        public readonly int $batchId,
        public readonly int $rowsRead,
        public readonly int $rawRowsCreated,
        public readonly int $tripsNormalized,
        public readonly int $allocationRowsCreated,
        public readonly int $errorCount,
        public readonly int $skippedRows = 0,
        public readonly int $duplicateRows = 0,
        public readonly int $unmatchedRows = 0,
    ) {
    }
}
