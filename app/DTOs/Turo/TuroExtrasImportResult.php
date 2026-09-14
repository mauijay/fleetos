<?php

namespace App\DTOs\Turo;

class TuroExtrasImportResult
{
    public function __construct(
        public readonly int $batchId,
        public readonly int $reservationsProcessed,
        public readonly int $selectionsAdded,
        public readonly int $selectionsUpdated,
        public readonly int $selectionsUnchanged,
        public readonly int $selectionsRemoved,
        public readonly int $unmappedSourceExtraIds,
        public readonly int $invalidReservations,
        public readonly int $exportFailures,
        public readonly bool $duplicateFile = false,
    ) {
    }
}
