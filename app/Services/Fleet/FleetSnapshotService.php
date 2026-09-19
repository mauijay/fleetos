<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;
use Config\Services;
use RuntimeException;

class FleetSnapshotService
{
    public const BUCKETS = ['rented' => 'Rented', 'awaiting_recovery' => 'Awaiting Recovery', 'home' => 'Home', 'hnl' => 'HNL', 'other' => 'Other', 'unknown' => 'Unknown'];

    public function __construct(
        private readonly ?CurrentVehicleLocationService $locations = null,
        private readonly ?OperationalFactsRepository $repository = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function forSingleFleetCompany(?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable();
        $companyIds = $this->repo()->activeFleetCompanyIds($asOf->format('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Fleet Snapshot requires exactly one active fleet company context.');
        }

        return $this->forCompany($companyIds[0], $asOf);
    }

    /** @return array<string, mixed> */
    public function forCompany(int $companyId, ?\DateTimeImmutable $asOf = null): array
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('A company context is required for Fleet Snapshot.');
        }
        $rows = $this->locationService()->forCompany($companyId, $asOf);
        $buckets = [];
        foreach (self::BUCKETS as $code => $label) {
            $buckets[$code] = ['code' => $code, 'label' => $label, 'count' => 0, 'vehicles' => []];
        }

        foreach ($rows as $row) {
            $bucket = $this->bucket($row);
            $buckets[$bucket]['vehicles'][] = [
                'id' => (int) $row['id'],
                'label' => $this->vehicleLabel($row),
                'fleet_number' => isset($row['fleet_number']) ? (int) $row['fleet_number'] : null,
                'href' => '/fleet/vehicles/' . (int) $row['id'],
            ];
            $buckets[$bucket]['count']++;
        }
        foreach ($buckets as &$bucket) {
            usort($bucket['vehicles'], static function (array $left, array $right): int {
                $leftNumber = $left['fleet_number'];
                $rightNumber = $right['fleet_number'];
                if ($leftNumber !== null || $rightNumber !== null) {
                    if ($leftNumber === null) {
                        return 1;
                    }
                    if ($rightNumber === null) {
                        return -1;
                    }
                    if ($leftNumber !== $rightNumber) {
                        return $leftNumber <=> $rightNumber;
                    }
                }

                return strnatcasecmp($left['label'], $right['label']) ?: ($left['id'] <=> $right['id']);
            });
        }
        unset($bucket);

        return [
            'company_id' => $companyId,
            'total' => count($rows),
            'buckets' => array_values($buckets),
            'vehicles' => $rows,
        ];
    }

    /** @param array<string, mixed> $row */
    private function bucket(array $row): string
    {
        if (($row['operational_state'] ?? null) === 'rented') {
            return 'rented';
        }
        if (($row['operational_state'] ?? null) === 'awaiting_recovery') {
            return 'awaiting_recovery';
        }

        return match ((string) ($row['location_class'] ?? 'unknown')) {
            'home' => 'home',
            'airport_hnl' => 'hnl',
            'unknown', '' => 'unknown',
            default => 'other',
        };
    }

    /** @param array<string, mixed> $vehicle */
    private function vehicleLabel(array $vehicle): string
    {
        if (isset($vehicle['fleet_number']) && (int) $vehicle['fleet_number'] > 0) {
            return (string) (int) $vehicle['fleet_number'];
        }
        foreach (['fleet_code', 'display_name'] as $field) {
            $label = trim((string) ($vehicle[$field] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return 'Vehicle ' . (int) $vehicle['id'];
    }

    private function locationService(): CurrentVehicleLocationService
    {
        return $this->locations ?? Services::currentVehicleLocationService();
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? Services::operationalFactsRepository();
    }
}
