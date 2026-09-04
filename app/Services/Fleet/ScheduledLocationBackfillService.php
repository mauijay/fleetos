<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use App\Services\Turo\TuroTripNormalizer;

class ScheduledLocationBackfillService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly LocationClassificationService $classifier = new LocationClassificationService(),
        private readonly TuroTripNormalizer $normalizer = new TuroTripNormalizer(),
    ) {
    }

    /** @return array{trips_scanned:int,locations_found:int,would_upsert:int,upserted:int,unknown:int,invalid_payloads:int} */
    public function run(bool $apply = false): array
    {
        $summary = ['trips_scanned' => 0, 'locations_found' => 0, 'would_upsert' => 0, 'upserted' => 0, 'unknown' => 0, 'invalid_payloads' => 0];
        foreach ($this->repo()->locationBackfillCandidates() as $candidate) {
            $summary['trips_scanned']++;
            $payload = json_decode((string) $candidate['raw_payload'], true);
            if (! is_array($payload)) {
                $summary['invalid_payloads']++;
                continue;
            }
            foreach (['pickup', 'return'] as $movementType) {
                $sourceText = $this->normalizer->scheduledLocationText($payload, $movementType);
                if ($sourceText === null) {
                    continue;
                }
                $summary['locations_found']++;
                $workflow = $this->repo()->airportWorkflow((int) $candidate['id'], $movementType);
                $classification = $this->classifier->classify(
                    $sourceText,
                    $workflow['airport_code'] ?? null,
                    isset($workflow['airport_id']) ? (int) $workflow['airport_id'] : null,
                    isset($workflow['id']) ? (int) $workflow['id'] : null,
                );
                if ($classification['location_class'] === 'unknown') {
                    $summary['unknown']++;
                }
                if (! $this->changed((int) $candidate['id'], $movementType, $classification)) {
                    continue;
                }
                $summary['would_upsert']++;
                if ($apply) {
                    $this->repo()->upsertScheduledLocation((int) $candidate['id'], $candidate['fleet_vehicle_id'] === null ? null : (int) $candidate['fleet_vehicle_id'], $movementType, $classification);
                    $summary['upserted']++;
                }
            }
        }
        return $summary;
    }

    private function changed(int $tripId, string $movementType, array $classification): bool
    {
        $existing = $this->repo()->scheduledLocation($tripId, $movementType);
        return $existing === null
            || $existing['source_text'] !== $classification['source_text']
            || $existing['location_class'] !== $classification['location_class']
            || $existing['classification_source'] !== $classification['classification_source']
            || $existing['classification_status'] !== $classification['classification_status'];
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
