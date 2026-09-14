<?php

namespace App\Services\Turo;

use App\DTOs\Turo\TuroExtrasImportResult;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Validation\Turo\TuroExtrasPayloadValidator;
use InvalidArgumentException;
use Throwable;

class TuroExtrasImportService
{
    public function __construct(
        private readonly FleetExtraRepository $extras = new FleetExtraRepository(),
        private readonly TuroExtrasPayloadValidator $validator = new TuroExtrasPayloadValidator(),
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly TuroImportBatchRepository $batches = new TuroImportBatchRepository(),
        private readonly TuroImportErrorRepository $errors = new TuroImportErrorRepository(),
        private readonly TuroImportAuditService $audit = new TuroImportAuditService(),
    ) {
    }

    public function import(string $json, int $companyId, int $actorUserId, string $sourceFilename): TuroExtrasImportResult
    {
        if ($companyId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('An active company and authenticated operator are required.');
        }
        $validated = $this->validator->validate($json);
        // turo_import_batches historically owns a globally unique source hash.
        // Scope this import's deduplication identity to the server-derived company
        // so an identical sanitized export cannot cross company boundaries.
        $sourceHash = hash('sha256', "turo_extras\0{$companyId}\0{$json}");
        $existingBatch = $this->batches->findBySourceHash($sourceHash);
        $selectionCount = array_sum(array_map(static fn (array $reservation): int => count($reservation['extras']), $validated['reservations']));
        if ($existingBatch !== null && ($existingBatch['status_code'] ?? null) === 'completed') {
            return new TuroExtrasImportResult(
                batchId: (int) $existingBatch['id'],
                reservationsProcessed: count($validated['reservations']),
                selectionsAdded: 0,
                selectionsUpdated: 0,
                selectionsUnchanged: $selectionCount,
                selectionsRemoved: 0,
                unmappedSourceExtraIds: $this->unmappedCount($companyId, $validated['reservations']),
                invalidReservations: count($validated['invalid']),
                exportFailures: count($validated['failures']),
                duplicateFile: true,
            );
        }

        $batchId = $existingBatch === null
            ? $this->batches->create([
                'import_type_lookup_value_id' => $this->lookups->valueId('import_type', 'turo_extras'),
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'processing'),
                'source_filename' => mb_substr(trim($sourceFilename), 0, 190),
                'source_hash' => $sourceHash,
                'row_count' => count($validated['reservations']) + count($validated['invalid']),
                'created_by' => $actorUserId,
            ])
            : (int) $existingBatch['id'];

        if ($existingBatch !== null) {
            $this->batches->update($batchId, [
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'processing'),
                'error_message' => null,
                'completed_at' => null,
            ]);
        } else {
            $this->audit->imported($actorUserId, 'turo_import_batches', $batchId, [
                'source_filename' => mb_substr(trim($sourceFilename), 0, 190),
                'source_hash' => $sourceHash,
                'schema' => TuroExtrasPayloadValidator::SCHEMA,
            ]);
        }

        try {
            foreach ($validated['invalid'] as $invalid) {
                $this->errors->create([
                    'turo_import_batch_id' => $batchId,
                    'severity_lookup_value_id' => $this->lookups->valueId('import_error_severity', 'error'),
                    'raw_table' => 'turo_extra_reservation_snapshots',
                    'raw_row_id' => null,
                    'row_number' => $invalid['row'],
                    'error_code' => 'invalid_extras_reservation',
                    'field_name' => null,
                    'message' => $invalid['message'],
                    'raw_payload' => ['reservation_id' => $invalid['reservation_id'], 'validation_error' => $invalid['message']],
                ]);
            }
            foreach ($validated['failures'] as $failure) {
                $this->errors->create([
                    'turo_import_batch_id' => $batchId,
                    'severity_lookup_value_id' => $this->lookups->valueId('import_error_severity', 'warning'),
                    'raw_table' => 'turo_extra_reservation_snapshots',
                    'raw_row_id' => null,
                    'row_number' => null,
                    'error_code' => 'extras_export_failure',
                    'field_name' => 'reservation_id',
                    'message' => "Reservation {$failure['reservation_id']}: {$failure['error']}",
                    'raw_payload' => $failure,
                ]);
            }

            $result = $this->persist(
                $validated['reservations'],
                $validated['exported_at'],
                $batchId,
                $companyId,
                count($validated['invalid']),
                count($validated['failures']),
            );
            $this->batches->update($batchId, [
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'completed'),
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => $validated['invalid'] === [] ? null : count($validated['invalid']) . ' reservation block(s) rejected.',
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->batches->update($batchId, [
                'import_status_lookup_value_id' => $this->lookups->valueId('import_status', 'failed'),
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $reservations */
    private function persist(array $reservations, string $observedAt, int $batchId, int $companyId, int $invalidCount, int $exportFailures): TuroExtrasImportResult
    {
        $reservationIds = array_values(array_unique(array_column($reservations, 'reservation_id')));
        $sourceExtraIds = $this->sourceExtraIds($reservations);
        $trips = $this->extras->tripsByReservationIds($companyId, $reservationIds);
        $mappings = $this->extras->mappingsBySourceIds($companyId, $sourceExtraIds);
        $existing = $this->extras->selectionsByIdentity($companyId, $reservationIds);
        $latestSnapshots = $this->extras->latestSnapshotObservations($companyId, $reservationIds);
        $added = $updated = $unchanged = $removed = 0;

        $this->extras->transaction(function () use ($reservations, $observedAt, $batchId, $companyId, $trips, $mappings, $latestSnapshots, &$existing, &$added, &$updated, &$unchanged, &$removed): void {
            foreach ($reservations as $reservation) {
                $reservationId = (string) $reservation['reservation_id'];
                $trip = $trips[$reservationId] ?? null;
                $payloadHash = $this->hash($reservation);
                $now = date('Y-m-d H:i:s');
                $snapshotId = $this->extras->createSnapshot([
                    'company_id' => $companyId,
                    'turo_import_batch_id' => $batchId,
                    'turo_trip_normalized_id' => $trip === null ? null : (int) $trip['id'],
                    'turo_reservation_id' => $reservationId,
                    'snapshot_complete' => $reservation['snapshot_complete'] ? 1 : 0,
                    'reservation_status' => $reservation['status'],
                    'trip_starts_at' => $reservation['trip_start'],
                    'trip_ends_at' => $reservation['trip_end'],
                    'observed_at' => $observedAt,
                    'source_payload_hash' => $payloadHash,
                    'source_payload' => $reservation,
                    'created_at' => $now,
                ]);
                if (($latestSnapshots[$reservationId] ?? '') > $observedAt) {
                    $unchanged += count($reservation['extras']);

                    continue;
                }
                $present = [];
                foreach ($reservation['extras'] as $extra) {
                    $stateExtraId = (string) $extra['reservation_state_extra_id'];
                    $present[] = $stateExtraId;
                    $key = $this->extras->selectionKey($reservationId, $stateExtraId);
                    $sourceHash = $this->hash($extra);
                    $data = [
                        'company_id' => $companyId,
                        'turo_trip_normalized_id' => $trip === null ? null : (int) $trip['id'],
                        'last_snapshot_id' => $snapshotId,
                        'turo_reservation_id' => $reservationId,
                        'source_extra_id' => $extra['extra_id'],
                        'reservation_state_extra_id' => $stateExtraId,
                        'reservation_state_id' => $extra['reservation_state_id'],
                        'source_type' => $extra['type'],
                        'source_label' => $extra['label'],
                        'source_description' => $extra['description'],
                        'unit_price' => $extra['price'],
                        'quantity' => $extra['quantity'],
                        'gross_amount' => $this->grossAmount($extra['price'], $extra['quantity']),
                        'currency_code' => $extra['currency'],
                        'pricing_type' => $extra['pricing_type'],
                        'trip_starts_at' => $reservation['trip_start'],
                        'trip_ends_at' => $reservation['trip_end'],
                        'reservation_status' => $reservation['status'],
                        'last_observed_at' => $observedAt,
                        'removed_at' => null,
                        'source_payload_hash' => $sourceHash,
                        'source_payload' => $extra,
                        'updated_at' => $now,
                    ];
                    if (! isset($existing[$key])) {
                        $selectionId = $this->extras->createSelection(array_merge($data, [
                            'first_snapshot_id' => $snapshotId,
                            'first_observed_at' => $observedAt,
                            'created_at' => $now,
                        ]));
                        $existing[$key] = array_merge($data, ['id' => $selectionId, 'first_snapshot_id' => $snapshotId, 'first_observed_at' => $observedAt]);
                        $added++;
                    } else {
                        $current = $existing[$key];
                        if ((string) $current['last_observed_at'] > $observedAt) {
                            $unchanged++;

                            continue;
                        }
                        $changed = (string) ($current['source_payload_hash'] ?? '') !== $sourceHash
                            || $current['removed_at'] !== null
                            || (string) ($current['turo_trip_normalized_id'] ?? '') !== (string) ($data['turo_trip_normalized_id'] ?? '');
                        $this->extras->updateSelection($companyId, (int) $current['id'], $data);
                        $existing[$key] = array_merge($current, $data);
                        if ($changed) {
                            $updated++;
                        } else {
                            $unchanged++;
                        }
                    }
                    if (isset($mappings[(string) $extra['extra_id']])) {
                        $this->extras->touchMappingFromObservation($companyId, (string) $extra['extra_id'], $extra, $observedAt);
                    }
                }
                if ($reservation['snapshot_complete']) {
                    $removed += $this->extras->markMissingSelectionsRemoved($companyId, $reservationId, $present, $observedAt, $snapshotId);
                }
            }
        });

        return new TuroExtrasImportResult(
            batchId: $batchId,
            reservationsProcessed: count($reservations),
            selectionsAdded: $added,
            selectionsUpdated: $updated,
            selectionsUnchanged: $unchanged,
            selectionsRemoved: $removed,
            unmappedSourceExtraIds: count(array_diff($sourceExtraIds, array_keys($mappings))),
            invalidReservations: $invalidCount,
            exportFailures: $exportFailures,
        );
    }

    /** @param list<array<string,mixed>> $reservations @return list<string> */
    private function sourceExtraIds(array $reservations): array
    {
        $ids = [];
        foreach ($reservations as $reservation) {
            foreach ($reservation['extras'] as $extra) {
                $ids[(string) $extra['extra_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /** @param list<array<string,mixed>> $reservations */
    private function unmappedCount(int $companyId, array $reservations): int
    {
        $ids = $this->sourceExtraIds($reservations);

        return count(array_diff($ids, array_keys($this->extras->mappingsBySourceIds($companyId, $ids))));
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function grossAmount(string $unitPrice, ?string $quantity): ?string
    {
        if ($quantity === null) {
            return null;
        }
        $priceCents = (int) str_replace('.', '', $unitPrice);
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $quantityThousandths = ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
        $grossCents = intdiv(($priceCents * $quantityThousandths) + 500, 1000);

        return sprintf('%d.%02d', intdiv($grossCents, 100), $grossCents % 100);
    }
}
