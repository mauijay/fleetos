<?php

namespace App\Services\Turo;

use App\DTOs\Turo\RawTripRow;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Validation\Turo\TuroTripCsvValidator;
use Throwable;

class TuroTripReconciliationService
{
    public function __construct(
        private readonly ?TuroVehicleMappingIssueRepository $mappingIssues = null,
        private readonly ?TuroImportErrorRepository $errors = null,
        private readonly ?TuroNormalizedTripRepository $normalizedTrips = null,
        private readonly ?TuroTripImportService $importer = null,
        private readonly TuroTripCsvValidator $validator = new TuroTripCsvValidator(),
        private readonly TuroTripNormalizer $normalizer = new TuroTripNormalizer(),
    ) {
    }

    /** @return array<string, mixed> */
    public function preview(string $turoVehicleId): array
    {
        $rows = $this->rows($turoVehicleId);
        $items = array_map(fn (array $row): array => $this->classify($row), $rows);

        return [
            'turo_vehicle_id' => trim($turoVehicleId),
            'items' => $items,
            'summary' => $this->summary($items),
            'is_empty' => $items === [],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(string $turoVehicleId, ?string $note = null, ?int $actorUserId = null): array
    {
        $preview = $this->preview($turoVehicleId);
        $results = [];

        foreach ($preview['items'] as $item) {
            if ($item['classification'] === 'already_imported_equivalent') {
                $this->recordAttempt($item, 'already_imported_equivalent', $item['message'], $item['trip_id'], $actorUserId);
                $this->errors()->markReconciled((int) $item['issue_id'], 'already_imported_equivalent', $item['message'], $item['trip_id'], $note);
                $results[] = $item;
                continue;
            }

            if ($item['classification'] !== 'ready') {
                $this->recordAttempt($item, $item['classification'], $item['message'], $item['trip_id'], $actorUserId);
                $this->errors()->markReconciliationBlocked((int) $item['issue_id'], $item['classification'], $item['message']);
                $results[] = $item;
                continue;
            }

            try {
                $rowResult = $this->importer()->importStoredRow((int) $item['batch_id'], (int) $item['row_number'], $item['payload'], $item['raw_row_id'], $actorUserId);
                if ($rowResult->success && count($rowResult->issues) === 0) {
                    $message = $rowResult->created ? 'Trip was imported successfully.' : 'Trip was reconciled through the importer and allocations were refreshed.';
                    $result = array_merge($item, ['classification' => $rowResult->created ? 'successfully_imported' : 'reconciled_successfully', 'message' => $message, 'trip_id' => $rowResult->normalizedTripId]);
                    $this->recordAttempt($result, $result['classification'], $message, $rowResult->normalizedTripId, $actorUserId);
                    $this->errors()->markReconciled((int) $item['issue_id'], $result['classification'], $message, $rowResult->normalizedTripId, $note);
                    $results[] = $result;
                    continue;
                }

                $message = $rowResult->issues[0]->message ?? 'The row still could not be reconciled.';
                $result = array_merge($item, ['classification' => 'reprocessing_failed', 'message' => $message]);
                $this->recordAttempt($result, 'reprocessing_failed', $message, null, $actorUserId);
                $this->errors()->markReconciliationBlocked((int) $item['issue_id'], 'reprocessing_failed', $message);
                $results[] = $result;
            } catch (Throwable $exception) {
                $message = 'Reprocessing failed: ' . $exception->getMessage();
                $result = array_merge($item, ['classification' => 'reprocessing_failed', 'message' => $message]);
                $this->recordAttempt($result, 'reprocessing_failed', $message, null, $actorUserId);
                $this->errors()->markReconciliationBlocked((int) $item['issue_id'], 'reprocessing_failed', $message);
                $results[] = $result;
            }
        }

        return [
            'turo_vehicle_id' => trim($turoVehicleId),
            'results' => $results,
            'summary' => $this->summary($results),
        ];
    }

    /** @return array<string, int|bool|string> */
    public function attentionSummary(): array
    {
        $rows = $this->mappingIssues()->vehicleUnmatchedIssues(['status' => 'unmapped']);
        $items = array_map(fn (array $row): array => $this->classify($row), $rows);
        $awaiting = array_values(array_filter($items, static fn (array $item): bool => in_array($item['classification'], ['ready', 'already_imported_equivalent', 'already_imported_conflict', 'reprocessing_failed'], true)));

        return [
            'awaiting_reconciliation' => count($awaiting),
            'has_reconciliation_work' => count($awaiting) > 0,
            'href' => '/turo/vehicle-matches',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $turoVehicleId): array
    {
        $target = trim($turoVehicleId);

        return array_values(array_filter($this->mappingIssues()->vehicleUnmatchedIssues(['status' => 'unmapped']), function (array $row) use ($target): bool {
            $payload = $this->payload($row['raw_payload'] ?? null);

            return in_array($target, $this->vehicleIds($payload), true);
        }));
    }

    /** @return array<string, mixed> */
    private function classify(array $row): array
    {
        $payload = $this->payload($row['raw_payload'] ?? null);
        $base = [
            'issue_id' => (int) $row['id'],
            'batch_id' => (int) $row['turo_import_batch_id'],
            'row_number' => (int) ($row['row_number'] ?? 0),
            'raw_row_id' => $row['raw_row_id'] === null ? null : (int) $row['raw_row_id'],
            'payload' => $payload,
            'trip_id' => null,
            'source_filename' => (string) ($row['source_filename'] ?? 'Turo import'),
        ];

        if (($row['error_code'] ?? '') !== 'vehicle_unmatched') {
            return array_merge($base, ['classification' => 'unsupported_issue', 'message' => 'This issue is not a vehicle matching issue.']);
        }

        if ($payload === []) {
            return array_merge($base, ['classification' => 'missing_source_payload', 'message' => 'The original CSV row was not stored with enough information.']);
        }

        if ($this->vehicleIds($payload) === []) {
            return array_merge($base, ['classification' => 'mapping_missing', 'message' => 'The original row does not include a stable Turo vehicle ID.']);
        }

        $validationIssues = $this->validator->validate($payload);
        foreach ($validationIssues as $issue) {
            if ($issue->severity === 'error') {
                return array_merge($base, ['classification' => 'invalid_source_data', 'message' => $issue->message]);
            }
        }

        $rawTripRow = new RawTripRow(
            rowNumber: (int) ($row['row_number'] ?? 0),
            payload: $payload,
            externalTripId: $this->normalizer->value($payload, ['trip_id', 'reservation_id', 'booking_id']),
            externalVehicleId: $this->normalizer->value($payload, ['vehicle_id', 'turo_vehicle_id', 'car_id']),
            rowHash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
        $normalized = $this->normalizer->normalize($rawTripRow, (int) ($row['raw_row_id'] ?? 0));

        if ($normalized->fleetVehicleId === null) {
            return array_merge($base, ['classification' => 'mapping_missing', 'message' => 'A FleetOS vehicle mapping is still missing for this Turo vehicle ID.']);
        }

        $existing = $this->normalizedTrips()->findByTuroTripId($normalized->turoTripId);
        if ($existing === null || (int) ($existing['fleet_vehicle_id'] ?? 0) === 0) {
            return array_merge($base, ['classification' => 'ready', 'message' => 'Ready to reprocess.', 'trip_id' => $existing === null ? null : (int) $existing['id']]);
        }

        if ($this->equivalent($existing, $normalized)) {
            return array_merge($base, ['classification' => 'already_imported_equivalent', 'message' => 'This trip already exists with matching values.', 'trip_id' => (int) $existing['id']]);
        }

        return array_merge($base, ['classification' => 'already_imported_conflict', 'message' => 'A trip with this Turo ID exists, but important values differ.', 'trip_id' => (int) $existing['id']]);
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, int> */
    private function summary(array $items): array
    {
        $summary = ['total' => count($items), 'ready' => 0, 'already_imported_equivalent' => 0, 'already_imported_conflict' => 0, 'missing_source_payload' => 0, 'mapping_missing' => 0, 'invalid_source_data' => 0, 'unsupported_issue' => 0, 'reprocessing_failed' => 0, 'reconciled_successfully' => 0, 'successfully_imported' => 0];

        foreach ($items as $item) {
            $classification = (string) $item['classification'];
            $summary[$classification] = ($summary[$classification] ?? 0) + 1;
        }

        return $summary;
    }

    private function equivalent(array $existing, \App\DTOs\Turo\NormalizedTripData $normalized): bool
    {
        $fields = [
            'fleet_vehicle_id' => $normalized->fleetVehicleId,
            'starts_at' => $normalized->startsAt,
            'ends_at' => $normalized->endsAt,
            'guest_name' => $normalized->guestName,
            'gross_revenue_amount' => $normalized->grossRevenueAmount,
            'host_payout_amount' => $normalized->hostPayoutAmount,
            'delivery_fee_amount' => $normalized->deliveryFeeAmount,
            'trip_status_lookup_value_id' => $normalized->tripStatusLookupValueId,
        ];

        foreach ($fields as $field => $value) {
            if (in_array($field, ['gross_revenue_amount', 'host_payout_amount', 'delivery_fee_amount'], true)) {
                if (number_format((float) ($existing[$field] ?? 0), 2, '.', '') !== number_format((float) $value, 2, '.', '')) {
                    return false;
                }

                continue;
            }

            if ((string) ($existing[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function recordAttempt(array $item, string $resultCode, string $message, ?int $tripId, ?int $actorUserId): void
    {
        $vehicleId = $this->normalizer->value($item['payload'], ['vehicle_id', 'turo_vehicle_id', 'car_id']);
        $this->errors()->recordReprocessAttempt((int) $item['issue_id'], $vehicleId, $resultCode, $message, $tripId, $actorUserId);
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> */
    private function vehicleIds(array $payload): array
    {
        $ids = [];
        foreach (['vehicle_id', 'turo_vehicle_id', 'car_id'] as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $ids[] = trim((string) $payload[$key]);
            }
        }

        return $ids;
    }

    private function mappingIssues(): TuroVehicleMappingIssueRepository
    {
        return $this->mappingIssues ?? service('turoVehicleMappingIssueRepository');
    }

    private function errors(): TuroImportErrorRepository
    {
        return $this->errors ?? service('turoImportErrorRepository');
    }

    private function normalizedTrips(): TuroNormalizedTripRepository
    {
        return $this->normalizedTrips ?? service('turoNormalizedTripRepository');
    }

    private function importer(): TuroTripImportService
    {
        return $this->importer ?? service('turoTripImportService');
    }
}
