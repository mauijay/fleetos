<?php

namespace App\Services\Turo;

use App\DTOs\Turo\ValidationIssue;
use App\Repositories\VehicleHealthObservationRepository;
use App\Services\Fleet\VehicleHealthObservationService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

class TuroOdometerIngestionService
{
    private const CANCELED_STATUSES = ['canceled', 'canceled_zero_payout', 'canceled_host_payout'];

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?VehicleHealthObservationService $observations = null,
        private readonly ?VehicleHealthObservationRepository $observationRepository = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{created:int,existing:int,corrected:int,issues:list<ValidationIssue>}
     */
    public function ingest(int $normalizedTripId, int $rawTripId, ?int $actorUserId = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $result = ['created' => 0, 'existing' => 0, 'corrected' => 0, 'issues' => []];
        $source = $this->sourceRow($normalizedTripId, $rawTripId);
        if ($source === null) {
            $result['issues'][] = $this->issue('turo_odometer_source_mismatch', 'The Turo odometer source row no longer matches the normalized trip.', 'trip_id');

            return $result;
        }

        $payload = json_decode((string) $source['raw_payload'], true);
        if (! is_array($payload)) {
            $result['issues'][] = $this->issue('turo_odometer_payload_invalid', 'The stored Turo trip payload could not be read.', 'raw_payload');

            return $result;
        }
        $scopeIssue = $this->scopeIssue($source, $payload);
        if ($scopeIssue !== null) {
            $result['issues'][] = $scopeIssue;

            return $result;
        }
        if (in_array(strtolower((string) $source['trip_status_code']), self::CANCELED_STATUSES, true)) {
            return $result;
        }

        foreach ([
            'pickup' => ['value' => 'check_in_odometer', 'time' => 'trip_start', 'normalized_time' => 'starts_at'],
            'return' => ['value' => 'check_out_odometer', 'time' => 'trip_end', 'normalized_time' => 'ends_at'],
        ] as $kind => $fields) {
            $value = $payload[$fields['value']] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                $result['issues'][] = $this->issue("turo_{$kind}_odometer_invalid", 'The Turo odometer reading must be a non-negative whole number.', $fields['value']);
                continue;
            }
            $value = trim((string) $value);
            if (preg_match('/^\d+$/', $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false) {
                $result['issues'][] = $this->issue("turo_{$kind}_odometer_invalid", 'The Turo odometer reading must be a non-negative whole number.', $fields['value']);
                continue;
            }

            $sourceTime = $payload[$fields['time']] ?? null;
            $observedAt = trim((string) ($source[$fields['normalized_time']] ?? ''));
            if (! is_string($sourceTime) || ! $this->sourceTimeMatches($sourceTime, $observedAt)) {
                $result['issues'][] = $this->issue("turo_{$kind}_odometer_time_missing", 'The Turo odometer reading has no usable trip boundary timestamp.', $fields['time']);
                continue;
            }

            $fact = [
                'turo_trip_id' => (string) $source['turo_trip_id'],
                'fleet_vehicle_id' => (int) $source['fleet_vehicle_id'],
                'kind' => $kind,
                'odometer_miles' => (int) $value,
                'observed_at' => $observedAt,
            ];
            $payloadHash = hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR));
            $identityPrefix = sprintf(
                'turo:%d:%s:%s:',
                $normalizedTripId,
                (string) $source['turo_trip_id'],
                $kind,
            );
            $externalId = $identityPrefix . $payloadHash;
            $versions = $this->repo()->sourceVersions(
                (int) $source['company_id'],
                (int) $source['fleet_vehicle_id'],
                'import',
                'odometer',
                $identityPrefix,
            );
            $same = array_values(array_filter(
                $versions,
                static fn (array $row): bool => hash_equals((string) ($row['source_payload_hash'] ?? ''), $payloadHash),
            ));
            if ($same !== []) {
                $result['existing']++;
                continue;
            }

            $active = null;
            foreach (array_reverse($versions) as $version) {
                if ($version['voided_at'] === null && ! $this->repo()->hasReplacement((int) $source['company_id'], (int) $source['fleet_vehicle_id'], (int) $version['id'])) {
                    $active = $version;
                    break;
                }
            }
            if ($versions !== [] && $active === null) {
                $result['issues'][] = $this->issue("turo_{$kind}_odometer_conflict", 'The prior imported odometer observation was manually replaced or voided; review is required before accepting a new source version.', $fields['value']);
                continue;
            }

            $recorded = $this->service()->recordImportedOdometer(
                (int) $source['company_id'],
                (int) $source['fleet_vehicle_id'],
                [
                    'odometer_miles' => (int) $value,
                    'observed_at' => $observedAt,
                    'note' => sprintf(
                        'Turo %s odometer for normalized trip #%d from trip_earnings_export raw row #%d. observed_at uses the scheduled %s because the export provides no separate check-%s timestamp.',
                        $kind,
                        $normalizedTripId,
                        $rawTripId,
                        $fields['time'],
                        $kind === 'pickup' ? 'in' : 'out',
                    ),
                ],
                $externalId,
                $payloadHash,
                $actorUserId,
                $active === null ? null : (int) $active['id'],
                $now,
            );
            if (! $recorded['success']) {
                $result['issues'][] = $this->issue(
                    "turo_{$kind}_odometer_rejected",
                    implode(' ', array_values($recorded['errors'])),
                    $fields['value'],
                );
                continue;
            }
            if (($recorded['existing'] ?? false) === true) {
                $result['existing']++;
            } elseif ($active === null) {
                $result['created']++;
            } else {
                $result['corrected']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function sourceRow(int $normalizedTripId, int $rawTripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, trips.turo_trip_raw_id, trips.turo_trip_id, trips.starts_at, trips.ends_at, trips.deleted_at')
            ->select('statuses.code AS trip_status_code, vehicles.company_id, vehicles.deleted_at AS vehicle_deleted_at')
            ->select('raw.external_trip_id, raw.external_vehicle_id, raw.raw_payload')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id', 'left')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->where('trips.id', $normalizedTripId)
            ->where('trips.turo_trip_raw_id', $rawTripId)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    private function scopeIssue(array $source, array $payload): ?ValidationIssue
    {
        if (($source['deleted_at'] ?? null) !== null || ($source['vehicle_deleted_at'] ?? null) !== null) {
            return $this->issue('turo_odometer_source_inactive', 'The Turo trip or FleetOS vehicle is inactive.', 'trip_id');
        }
        if ((int) ($source['fleet_vehicle_id'] ?? 0) < 1 || (int) ($source['company_id'] ?? 0) < 1) {
            return $this->issue('turo_odometer_vehicle_unmatched', 'The Turo trip is not attached to an exact company-scoped FleetOS vehicle.', 'vehicle_id');
        }
        if (trim((string) ($source['external_trip_id'] ?? '')) === '' || (string) $source['external_trip_id'] !== (string) $source['turo_trip_id']) {
            return $this->issue('turo_odometer_trip_mismatch', 'The raw Turo trip identity does not match the normalized trip.', 'trip_id');
        }
        if (trim((string) ($payload['reservation_id'] ?? '')) !== (string) $source['turo_trip_id']) {
            return $this->issue('turo_odometer_trip_mismatch', 'The stored Turo reservation identity does not match the normalized trip.', 'trip_id');
        }
        $externalVehicleId = trim((string) ($source['external_vehicle_id'] ?? ''));
        if ($externalVehicleId === '') {
            return $this->issue('turo_odometer_vehicle_identity_missing', 'The Turo odometer source has no external vehicle identity.', 'vehicle_id');
        }
        if (trim((string) ($payload['vehicle_id'] ?? '')) !== $externalVehicleId) {
            return $this->issue('turo_odometer_vehicle_mismatch', 'The stored Turo vehicle identity does not match the raw source identity.', 'vehicle_id');
        }
        $mapped = $this->db->table('vehicle_turo_listings')
            ->select('fleet_vehicle_id')
            ->where('turo_vehicle_id', $externalVehicleId)
            ->where('is_active', true)
            ->get()->getRowArray();
        if ($mapped === null || (int) $mapped['fleet_vehicle_id'] !== (int) $source['fleet_vehicle_id']) {
            return $this->issue('turo_odometer_vehicle_mismatch', 'The Turo source vehicle does not match the normalized trip vehicle.', 'vehicle_id');
        }

        return null;
    }

    private function sourceTimeMatches(string $sourceTime, string $normalizedTime): bool
    {
        if (trim($sourceTime) === '' || $normalizedTime === '') {
            return false;
        }
        try {
            return (new DateTimeImmutable(trim($sourceTime)))->format('Y-m-d H:i:s') === $normalizedTime;
        } catch (\Throwable) {
            return false;
        }
    }

    private function issue(string $code, string $message, string $field): ValidationIssue
    {
        return new ValidationIssue($code, $message, $field, 'warning');
    }

    private function service(): VehicleHealthObservationService
    {
        return $this->observations ?? new VehicleHealthObservationService($this->db, $this->repo());
    }

    private function repo(): VehicleHealthObservationRepository
    {
        return $this->observationRepository ?? new VehicleHealthObservationRepository($this->db);
    }
}
