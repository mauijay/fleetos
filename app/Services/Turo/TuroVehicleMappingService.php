<?php

namespace App\Services\Turo;

use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Repositories\VehicleTuroListingRepository;

class TuroVehicleMappingService
{
    public function __construct(
        private readonly ?VehicleTuroListingRepository $listings = null,
        private readonly ?TuroVehicleMappingIssueRepository $issues = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function queue(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $fleetVehicles = $this->listings()->fleetVehicles();
        $items = $this->queueItems($this->issues()->vehicleUnmatchedIssues($filters), $fleetVehicles);

        if ($filters['fleet_vehicle_id'] !== '0') {
            $items = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['mapping']['fleet_vehicle_id'] ?? $item['suggestion']['fleet_vehicle_id'] ?? '0') === $filters['fleet_vehicle_id']));
        }

        if ($filters['vehicle'] !== '') {
            $vehicle = strtolower($filters['vehicle']);
            $items = array_values(array_filter($items, static fn (array $item): bool => str_contains(strtolower((string) $item['turo_vehicle_id']), $vehicle) || str_contains(strtolower((string) $item['vehicle_name']), $vehicle)));
        }

        if ($filters['status'] === 'suggested') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['suggestion']['fleet_vehicle_id'] !== null));
        } elseif ($filters['status'] === 'conflicts') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['has_conflict']));
        } elseif ($filters['status'] === 'mapped') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['mapping'] !== null));
        } elseif ($filters['status'] === 'unmapped') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['mapping'] === null));
        }

        usort($items, static fn (array $left, array $right): int => $right['affected_issue_count'] <=> $left['affected_issue_count']);

        return [
            'filters' => $filters,
            'items' => $items,
            'fleet_vehicles' => $fleetVehicles,
            'batches' => $this->batches($items),
            'summary' => $this->attentionSummary(),
            'is_empty' => $items === [],
        ];
    }

    /** @return array<string, int|bool|string> */
    public function attentionSummary(): array
    {
        $items = $this->queueItems($this->issues()->vehicleUnmatchedIssues(['status' => 'unmapped']), $this->listings()->fleetVehicles());
        $unmapped = array_values(array_filter($items, static fn (array $item): bool => $item['mapping'] === null));
        $issueCount = array_reduce($unmapped, static fn (int $carry, array $item): int => $carry + (int) $item['affected_issue_count'], 0);

        return [
            'unique_unmatched_vehicles' => count($unmapped),
            'affected_issues' => $issueCount,
            'has_unmatched' => count($unmapped) > 0,
            'href' => '/turo/vehicle-matches',
        ];
    }

    /** @return array<string, mixed> */
    public function map(string $turoVehicleId, int $fleetVehicleId, bool $confirmRemap = false, ?string $note = null, ?int $actorUserId = null): array
    {
        $turoVehicleId = trim($turoVehicleId);

        if ($turoVehicleId === '') {
            return $this->failed('missing_external_id', 'Turo vehicle ID is required before a mapping can be saved.');
        }

        $fleetVehicle = $this->listings()->fleetVehicle($fleetVehicleId);
        if ($fleetVehicle === null) {
            return $this->failed('invalid_fleet_vehicle', 'Choose an active FleetOS vehicle before saving this mapping.');
        }

        $existing = $this->listings()->findActiveByTuroVehicleId($turoVehicleId);
        if ($existing !== null && (int) $existing['fleet_vehicle_id'] !== $fleetVehicleId && ! $confirmRemap) {
            return $this->failed('external_already_mapped', 'This Turo vehicle is already mapped to ' . (string) ($existing['fleet_code'] ?? $existing['display_name'] ?? 'another FleetOS vehicle') . '. Confirm remap to change it.');
        }

        $fleetConflicts = array_values(array_filter(
            $this->listings()->activeListingsForFleetVehicle($fleetVehicleId),
            static fn (array $listing): bool => (string) $listing['turo_vehicle_id'] !== $turoVehicleId,
        ));

        if ($fleetConflicts !== [] && ! $confirmRemap) {
            return $this->failed('fleet_vehicle_conflict', 'This FleetOS vehicle already has a different active Turo vehicle mapping. Confirm remap to replace it.');
        }

        if ($fleetConflicts !== []) {
            $this->listings()->deactivateFleetConflicts($fleetVehicleId, $turoVehicleId, $note, $actorUserId);
        }

        if ($existing === null) {
            $this->listings()->createMapping($turoVehicleId, $fleetVehicleId, $note, $actorUserId);
        } elseif ((int) $existing['fleet_vehicle_id'] !== $fleetVehicleId) {
            $this->listings()->remap($turoVehicleId, $fleetVehicleId, $note, $actorUserId);
        }

        return [
            'success' => true,
            'code' => 'mapped',
            'message' => 'Turo vehicle ' . $turoVehicleId . ' is mapped to ' . (string) ($fleetVehicle['fleet_code'] ?? $fleetVehicle['display_name']) . '.',
        ];
    }

    public function resolveRelated(string $turoVehicleId, ?string $note = null): int
    {
        return 0;
    }

    /** @param array<int, array<string, mixed>> $issueRows @param array<int, array<string, mixed>> $fleetVehicles @return array<int, array<string, mixed>> */
    private function queueItems(array $issueRows, array $fleetVehicles): array
    {
        $items = [];

        foreach ($issueRows as $row) {
            $payload = $this->payload($row['raw_payload'] ?? null);
            $turoVehicleId = $this->firstValue($payload, ['vehicle_id', 'turo_vehicle_id', 'car_id']);

            if ($turoVehicleId === null) {
                continue;
            }

            $key = $turoVehicleId;
            $items[$key] ??= [
                'turo_vehicle_id' => $turoVehicleId,
                'turo_listing_id' => $this->firstValue($payload, ['listing_id', 'turo_listing_id']),
                'vehicle_name' => $this->firstValue($payload, ['vehicle_name', 'car_name', 'fleet_code']) ?? 'Unknown Turo vehicle',
                'year' => $this->firstValue($payload, ['year', 'model_year']),
                'make' => $this->firstValue($payload, ['make', 'vehicle_make']),
                'model' => $this->firstValue($payload, ['model', 'vehicle_model']),
                'trim' => $this->firstValue($payload, ['trim', 'vehicle_trim']),
                'license_plate' => $this->firstValue($payload, ['license_plate', 'plate']),
                'vin_fragment' => $this->vinFragment($this->firstValue($payload, ['vin'])),
                'source_filename' => (string) ($row['source_filename'] ?? 'Turo import'),
                'first_seen' => (string) ($row['created_at'] ?? ''),
                'last_seen' => (string) ($row['created_at'] ?? ''),
                'affected_issue_count' => 0,
                'affected_trip_count' => 0,
                'import_ids' => [],
                'issue_ids' => [],
                'mapping' => null,
                'suggestion' => ['fleet_vehicle_id' => null, 'label' => 'None', 'confidence' => 'None', 'reason' => 'No deterministic match found.'],
                'has_conflict' => false,
            ];

            $items[$key]['affected_issue_count']++;
            $items[$key]['affected_trip_count']++;
            $items[$key]['issue_ids'][] = (int) $row['id'];
            $items[$key]['import_ids'][(int) $row['turo_import_batch_id']] = true;
            $items[$key]['first_seen'] = min($items[$key]['first_seen'], (string) ($row['created_at'] ?? $items[$key]['first_seen']));
            $items[$key]['last_seen'] = max($items[$key]['last_seen'], (string) ($row['created_at'] ?? $items[$key]['last_seen']));
        }

        foreach ($items as &$item) {
            $mapping = $this->listings()->findActiveByTuroVehicleId($item['turo_vehicle_id']);
            $item['mapping'] = $mapping;
            $item['mapping_status'] = $mapping === null ? 'Unmapped' : 'Mapped to ' . (string) ($mapping['fleet_code'] ?? $mapping['display_name']);
            $item['import_count'] = count($item['import_ids']);
            $item['import_ids'] = array_keys($item['import_ids']);
            $item['suggestion'] = $this->suggestion($item, $fleetVehicles);
            $item['has_conflict'] = $mapping !== null && $item['suggestion']['fleet_vehicle_id'] !== null && (int) $mapping['fleet_vehicle_id'] !== (int) $item['suggestion']['fleet_vehicle_id'];
        }
        unset($item);

        return array_values($items);
    }

    /** @param array<string, mixed> $item @param array<int, array<string, mixed>> $fleetVehicles @return array<string, mixed> */
    private function suggestion(array $item, array $fleetVehicles): array
    {
        foreach ($fleetVehicles as $vehicle) {
            if ($this->same($item['license_plate'] ?? null, $vehicle['license_plate'] ?? null)) {
                return $this->suggestionResult($vehicle, 'Exact', 'License plate matches exactly.');
            }
            if ($this->same($item['vin_fragment'] ?? null, $this->vinFragment($vehicle['vin'] ?? null))) {
                return $this->suggestionResult($vehicle, 'Exact', 'VIN fragment matches exactly.');
            }
        }

        $spaceship = $this->spaceshipNumber((string) ($item['vehicle_name'] ?? ''));
        if ($spaceship !== null) {
            foreach ($fleetVehicles as $vehicle) {
                if ($this->spaceshipNumber((string) ($vehicle['fleet_code'] ?? $vehicle['display_name'] ?? '')) === $spaceship) {
                    return $this->suggestionResult($vehicle, 'Strong', 'Spaceship number matches.');
                }
            }
        }

        $exactNameMatches = array_values(array_filter($fleetVehicles, fn (array $vehicle): bool => $this->normalized((string) $item['vehicle_name']) !== '' && $this->normalized((string) $item['vehicle_name']) === $this->normalized((string) ($vehicle['display_name'] ?? $vehicle['fleet_code'] ?? ''))));
        if (count($exactNameMatches) === 1) {
            return $this->suggestionResult($exactNameMatches[0], 'Strong', 'Vehicle name matches exactly.');
        }

        $specMatches = array_values(array_filter($fleetVehicles, fn (array $vehicle): bool => $this->same($item['year'] ?? null, $vehicle['model_year'] ?? null) && $this->same($item['make'] ?? null, $vehicle['make_name'] ?? null) && $this->same($item['model'] ?? null, $vehicle['model_name'] ?? null) && $this->same($item['trim'] ?? null, $vehicle['trim_name'] ?? null)));
        if (count($specMatches) === 1) {
            return $this->suggestionResult($specMatches[0], 'Possible', 'Year, make, model, and trim match one FleetOS vehicle.');
        }

        return ['fleet_vehicle_id' => null, 'label' => 'None', 'confidence' => 'None', 'reason' => count($specMatches) > 1 ? 'Multiple FleetOS vehicles share similar specs.' : 'No deterministic match found.'];
    }

    /** @return array<string, mixed> */
    private function suggestionResult(array $vehicle, string $confidence, string $reason): array
    {
        return [
            'fleet_vehicle_id' => (int) $vehicle['id'],
            'label' => (string) ($vehicle['fleet_code'] ?? $vehicle['display_name']),
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }

    /** @return array<string, string> */
    private function normalizeFilters(array $filters): array
    {
        $status = $filters['status'] ?? 'unmapped';

        return [
            'status' => in_array($status, ['unmapped', 'mapped', 'conflicts', 'suggested', 'all'], true) ? (string) $status : 'unmapped',
            'fleet_vehicle_id' => (string) max(0, (int) ($filters['fleet_vehicle_id'] ?? 0)),
            'batch_id' => (string) max(0, (int) ($filters['batch_id'] ?? 0)),
            'vehicle' => trim((string) ($filters['vehicle'] ?? '')),
            'from' => $this->dateFilter($filters['from'] ?? ''),
            'to' => $this->dateFilter($filters['to'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function batches(array $items): array
    {
        $batchIds = [];
        foreach ($items as $item) {
            foreach ($item['import_ids'] as $batchId) {
                $batchIds[$batchId] = ['id' => $batchId];
            }
        }

        return array_values($batchIds);
    }

    private function failed(string $code, string $message): array
    {
        return ['success' => false, 'code' => $code, 'message' => $message];
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    private function firstValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $left !== null && $right !== null && $this->normalized((string) $left) !== '' && $this->normalized((string) $left) === $this->normalized((string) $right);
    }

    private function normalized(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    private function spaceshipNumber(string $value): ?string
    {
        return preg_match('/spaceship[^0-9]*(\d+)/i', $value, $matches) === 1 ? ltrim($matches[1], '0') : null;
    }

    private function vinFragment(?string $vin): ?string
    {
        if ($vin === null || trim($vin) === '') {
            return null;
        }

        return strtoupper(substr(trim($vin), -6));
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function listings(): VehicleTuroListingRepository
    {
        return $this->listings ?? service('vehicleTuroListingRepository');
    }

    private function issues(): TuroVehicleMappingIssueRepository
    {
        return $this->issues ?? service('turoVehicleMappingIssueRepository');
    }
}
