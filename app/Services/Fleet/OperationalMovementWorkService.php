<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use Config\Services;
use DateTimeImmutable;
use RuntimeException;

/** Shared, company-scoped movement completion and physical-work read model. */
class OperationalMovementWorkService
{
    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
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
    public function cleaningNeedsForCompany(int $companyId, DateTimeImmutable $asOf): array
    {
        $vehicles = $this->repo()->activeFleetVehiclesForCompany($companyId);
        $vehicleIds = array_map('intval', array_column($vehicles, 'id'));
        $timestamp = $asOf->format('Y-m-d H:i:s');
        $custody = $this->repo()->latestCustodyEventsForCompany($companyId, $vehicleIds, $timestamp);
        $cleanliness = $this->repo()->latestCleanlinessForCompany($companyId, $vehicleIds, $timestamp);
        $needs = [];
        foreach ($vehicles as $vehicle) {
            $id = (int) $vehicle['id'];
            $event = $custody[$id] ?? null;
            $assessment = $cleanliness[$id] ?? null;
            if (! in_array($event['event_code'] ?? null, ['actual_return', 'vehicle_recovered'], true)
                || ($assessment['cleanliness'] ?? null) !== 'dirty'
                || (string) ($assessment['captured_at'] ?? '') < (string) ($event['occurred_at'] ?? '')) {
                continue;
            }
            $needs[] = array_merge($vehicle, [
                'fleet_vehicle_id' => $id,
                'turo_trip_normalized_id' => (int) ($event['turo_trip_normalized_id'] ?? 0),
                'condition_observed_at' => $assessment['captured_at'],
            ]);
        }

        return $needs;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? Services::operationalFactsRepository();
    }
}
