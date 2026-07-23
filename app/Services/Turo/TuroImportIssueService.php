<?php

namespace App\Services\Turo;

use App\Repositories\TuroImportErrorRepository;
use App\Repositories\VehicleTuroListingRepository;

class TuroImportIssueService
{
    public function __construct(
        private readonly ?TuroImportErrorRepository $repository = null,
        private readonly ?VehicleTuroListingRepository $turoListings = null,
    )
    {
    }

    /** @return array<string, mixed> */
    public function review(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $filters = $this->normalizeFilters($filters);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = $this->repo()->issueCount($filters);
        $issues = $this->repo()->issues($filters, $perPage, ($page - 1) * $perPage);

        return [
            'filters' => $filters,
            'issues' => array_map(fn (array $row): array => $this->issueView($row), $issues),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => max(1, (int) ceil($total / $perPage)),
            'summary' => $this->attentionSummary(),
            'batches' => $this->repo()->batchesWithIssues(),
            'categories' => $this->repo()->issueCategories(),
            'is_empty' => $total === 0,
        ];
    }

    /** @return array<string, int|bool|string> */
    public function attentionSummary(): array
    {
        $counts = $this->repo()->unresolvedCountsBySeverity();
        $total = $counts['error'] + $counts['warning'];

        return [
            'unresolved_errors' => $counts['error'],
            'unresolved_warnings' => $counts['warning'],
            'total_unresolved' => $total,
            'has_unresolved' => $total > 0,
            'href' => '/turo/import-issues',
        ];
    }

    public function resolve(int $id, ?string $note = null): bool
    {
        return $id > 0 && $this->repo()->resolve($id, $note);
    }

    public function reopen(int $id, ?string $note = null): bool
    {
        return $id > 0 && $this->repo()->reopen($id, $note);
    }

    /** @return array<string, string> */
    private function normalizeFilters(array $filters): array
    {
        $status = $filters['status'] ?? 'unresolved';
        $severity = $filters['severity'] ?? '';

        return [
            'status' => in_array($status, ['unresolved', 'resolved', 'all'], true) ? (string) $status : 'unresolved',
            'severity' => in_array($severity, ['error', 'warning'], true) ? (string) $severity : '',
            'batch_id' => (string) max(0, (int) ($filters['batch_id'] ?? 0)),
            'vehicle' => trim((string) ($filters['vehicle'] ?? '')),
            'category' => trim((string) ($filters['category'] ?? '')),
            'from' => $this->dateFilter($filters['from'] ?? ''),
            'to' => $this->dateFilter($filters['to'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function issueView(array $row): array
    {
        $payload = $this->payload($row['raw_payload'] ?? null);
        $severity = (string) ($row['severity_code'] ?? 'error');
        $externalVehicleId = $this->firstValue($payload, ['vehicle_id', 'turo_vehicle_id', 'car_id']);
        $mapping = $externalVehicleId === null ? null : $this->turoListings()->findActiveByTuroVehicleId($externalVehicleId);

        return array_merge($row, [
            'severity_code' => $severity,
            'severity_label' => ucfirst($severity),
            'category_label' => $this->categoryLabel((string) ($row['error_code'] ?? 'unknown_import_error')),
            'requires_action' => ($row['resolved_at'] ?? null) === null,
            'resolution_status' => ($row['resolved_at'] ?? null) === null ? 'Unresolved' : 'Resolved',
            'source_label' => '#' . (string) $row['turo_import_batch_id'] . ' ' . ((string) ($row['source_filename'] ?? 'Turo import')),
            'plain_message' => $this->plainMessage((string) ($row['error_code'] ?? ''), (string) ($row['message'] ?? ''), $payload),
            'raw_payload_array' => $payload,
            'vehicle_label' => $this->firstValue($payload, ['vehicle', 'vehicle_name', 'car_name', 'fleet_code', 'vehicle_id', 'turo_vehicle_id', 'car_id']) ?? 'Not provided',
            'external_vehicle_id' => $externalVehicleId,
            'vehicle_mapping_status' => $mapping === null ? 'Not mapped' : 'Mapped to ' . (string) ($mapping['fleet_code'] ?? $mapping['display_name'] ?? 'FleetOS vehicle'),
            'vehicle_mapping_href' => $externalVehicleId === null ? null : '/turo/vehicle-matches?status=all&vehicle=' . rawurlencode($externalVehicleId),
            'vehicle_reprocess_href' => $externalVehicleId === null ? null : '/turo/vehicle-matches/reprocess?turo_vehicle_id=' . rawurlencode($externalVehicleId),
            'trip_id' => $this->firstValue($payload, ['trip_id', 'reservation_id', 'booking_id']) ?? 'Not provided',
            'guest_name' => $this->firstValue($payload, ['guest_name', 'guest', 'renter_name']) ?? 'Not provided',
            'trip_dates' => $this->tripDates($payload),
            'relevant_values' => $this->relevantValues($payload),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function plainMessage(string $code, string $message, array $payload): string
    {
        return match ($code) {
            'vehicle_unmatched' => 'No FleetOS vehicle matches Turo vehicle ID ' . ($this->firstValue($payload, ['vehicle_id', 'turo_vehicle_id', 'car_id']) ?? 'from this row') . '.',
            'duplicate_trip_in_file' => 'This trip appears more than once in the imported CSV and the duplicate row was skipped.',
            'missing_trip_id' => 'The trip or reservation ID is missing.',
            'missing_start', 'missing_end' => 'A required trip date is missing.',
            'invalid_start', 'invalid_end', 'invalid_date_range' => 'The trip dates are missing or invalid.',
            'invalid_money' => 'A revenue or fee amount could not be read.',
            default => $message,
        };
    }

    private function categoryLabel(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    private function tripDates(array $payload): string
    {
        $start = $this->firstValue($payload, ['starts_at', 'start_time', 'start_date', 'trip_start', 'reservation_start']);
        $end = $this->firstValue($payload, ['ends_at', 'end_time', 'end_date', 'trip_end', 'reservation_end']);

        if ($start === null && $end === null) {
            return 'Not provided';
        }

        return ($start ?? 'Missing start') . ' to ' . ($end ?? 'Missing return');
    }

    /** @return array<string, string> */
    private function relevantValues(array $payload): array
    {
        $keys = ['trip_id', 'reservation_id', 'vehicle_id', 'turo_vehicle_id', 'fleet_code', 'guest_name', 'starts_at', 'ends_at', 'host_payout', 'gross_revenue', 'status'];
        $values = [];

        foreach ($keys as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $values[$key] = (string) $payload[$key];
            }
        }

        return $values;
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

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function repo(): TuroImportErrorRepository
    {
        return $this->repository ?? service('turoImportErrorRepository');
    }

    private function turoListings(): VehicleTuroListingRepository
    {
        return $this->turoListings ?? service('vehicleTuroListingRepository');
    }
}
