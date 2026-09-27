<?php

namespace App\Services\Tesla;

use App\Repositories\LookupRepository;
use App\Repositories\SuperchargerReconciliationRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Turo\TuroCsvReader;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class TeslaChargingImportService
{
    private const REQUIRED_HEADERS = [
        'charge_start_date_time',
        'vin',
        'invoice_number',
        'site_location_name',
        'description',
        'total_inc_vat',
        'invoice',
    ];

    private const QUANTITY_FIELDS = ['quantity_base', 'quantity_tier1', 'quantity_tier2', 'quantity_tier3', 'quantity_tier4'];
    private const UNIT_COST_FIELDS = ['unit_cost_base', 'unit_cost_tier1', 'unit_cost_tier2', 'unit_cost_tier3', 'unit_cost_tier4'];

    public function __construct(
        private readonly SuperchargerReconciliationRepository $repository = new SuperchargerReconciliationRepository(),
        private readonly CurrentVehicleCustodyService $custody = new CurrentVehicleCustodyService(),
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly TuroCsvReader $reader = new TuroCsvReader(),
    ) {
    }

    /** @return array{batch_id:int,rows:int,imported:int,duplicate:int,review:int,rejected:int,replayed:bool} */
    public function import(string $filePath, int $companyId, int $actorUserId, string $sourceFilename): array
    {
        if ($companyId < 1 || $actorUserId < 1) {
            throw new RuntimeException('An active company and authenticated operator are required.');
        }
        $headers = array_map($this->canonicalHeader(...), $this->reader->headers($filePath));
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            throw new RuntimeException('Tesla CSV is missing required headers: ' . implode(', ', $missing) . '.');
        }
        $sourceHash = hash_file('sha256', $filePath);
        if (! is_string($sourceHash)) {
            throw new RuntimeException('Unable to fingerprint the Tesla CSV.');
        }
        $prior = $this->repository->batchByHash($companyId, $sourceHash);
        if ($prior !== null) {
            return $this->batchResult($prior, true);
        }

        $batchId = $this->repository->createBatch($companyId, $sourceFilename, $sourceHash, $actorUserId);
        $counts = ['rows' => 0, 'imported' => 0, 'duplicate' => 0, 'review' => 0, 'rejected' => 0];
        try {
            foreach ($this->reader->read($filePath) as $csvRow) {
                $counts['rows']++;
                $rawPayload = $csvRow->row;
                $payload = $this->canonicalPayload($rawPayload);
                $rowHash = hash('sha256', $this->canonicalJson($payload));
                $rawRowId = $this->repository->createImportRow($companyId, $batchId, $csvRow->rowNumber, $rowHash, $rawPayload, 'processing');
                $result = $this->importRow($companyId, $rawRowId, $payload);
                $counts[$result['counter']]++;
                $this->repository->updateImportRow($rawRowId, $result['status'], $result['issue_code'], $result['issue_detail']);
            }
            $this->repository->completeBatch($batchId, $counts);
        } catch (\Throwable $exception) {
            $this->repository->completeBatch($batchId, $counts, 'failed', $exception->getMessage());
            throw $exception;
        }

        return ['batch_id' => $batchId, ...$counts, 'replayed' => false];
    }

    /** @param array<string, mixed> $payload @return array{status:string,counter:string,issue_code:?string,issue_detail:?string} */
    private function importRow(int $companyId, int $rawRowId, array $payload): array
    {
        $vin = strtoupper(trim((string) ($payload['vin'] ?? '')));
        $invoice = trim((string) ($payload['invoice_number'] ?? ''));
        $sourceStartedAt = trim((string) ($payload['charge_start_date_time'] ?? ''));
        $site = trim((string) ($payload['site_location_name'] ?? ''));
        $description = strtoupper(trim((string) ($payload['description'] ?? '')));
        $invoiceUrl = $this->invoiceUrl($payload['invoice'] ?? null);

        if ($vin === '' || $invoice === '' || $sourceStartedAt === '' || $description === '') {
            return $this->result('rejected', 'rejected', 'missing_identity', 'VIN, invoice number, charge timestamp, and description are required.');
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $sourceStartedAt)) {
            return $this->result('rejected', 'rejected', 'timestamp_without_offset', 'ChargeStartDateTime must include its source UTC offset.');
        }
        try {
            $sourceDate = new DateTimeImmutable($sourceStartedAt);
        } catch (\Throwable) {
            return $this->result('rejected', 'rejected', 'invalid_timestamp', 'ChargeStartDateTime could not be parsed.');
        }
        $amountCents = $this->moneyCents($payload['total_inc_vat'] ?? null);
        if ($amountCents === null || $amountCents < 0) {
            return $this->result('rejected', 'rejected', 'invalid_total_inc_vat', 'Total Inc. VAT must be a non-negative amount with at most two decimal places.');
        }

        $vehicles = $this->repository->vehiclesByVin($companyId, $vin);
        if (count($vehicles) !== 1) {
            $issue = count($vehicles) > 1
                ? 'vin_ambiguous'
                : ($this->repository->vinExistsOutsideCompany($companyId, $vin) ? 'vin_company_mismatch' : 'vin_unmapped');

            return $this->result('review', 'review', $issue, 'Tesla row was preserved but was not attached to a vehicle or trip.');
        }

        $vehicleId = (int) $vehicles[0]['id'];
        $localDate = $sourceDate->setTimezone(new DateTimeZone('Pacific/Honolulu'));
        $startedAt = $localDate->format('Y-m-d H:i:s');
        $quantity = $this->sourceFields($payload, self::QUANTITY_FIELDS);
        $unitCost = $this->sourceFields($payload, self::UNIT_COST_FIELDS);
        $lineFingerprint = hash('sha256', $this->canonicalJson([
            'company_id' => $companyId,
            'vin' => $vin,
            'invoice' => $invoice,
            'started_at' => $sourceStartedAt,
            'site' => $site,
            'description' => $description,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_inc_vat_cents' => $amountCents,
            'invoice_url' => $invoiceUrl,
        ]));
        if ($this->repository->lineExists($companyId, $lineFingerprint)) {
            return $this->result('duplicate', 'duplicate', 'source_line_replayed', 'This exact Tesla line item was already imported.');
        }

        $classification = $this->classifyCustody($companyId, $vehicleId, $localDate);
        $knownFee = str_contains($description, 'CHARGING')
            || str_contains($description, 'CONGESTION')
            || str_contains($description, 'IDLE');
        if (! $knownFee) {
            $classification['candidate_trip_id'] = $classification['authoritative_trip_id'];
            $classification['authoritative_trip_id'] = null;
            $classification['custody_classification'] = 'review';
            $classification['custody_basis_code'] = 'unknown_fee_description';
        }
        $sessionFingerprint = hash('sha256', $this->canonicalJson([
            'company_id' => $companyId,
            'vin' => $vin,
            'invoice' => $invoice,
            'started_at' => $sourceStartedAt,
            'site' => $site,
        ]));
        $session = $this->repository->sessionByFingerprint($sessionFingerprint);
        if ($session === null) {
            $sessionId = $this->repository->createSession([
                'fleet_vehicle_id' => $vehicleId,
                'charging_provider_lookup_value_id' => $this->lookups->valueId('charging_provider', 'tesla_supercharger'),
                'charging_location' => $site ?: null,
                'started_at' => $startedAt,
                'ended_at' => null,
                'cost_amount' => '0.00',
                'turo_trip_normalized_id' => $classification['authoritative_trip_id'],
                'source_type' => 'tesla_invoice_csv',
                'source_session_fingerprint' => $sessionFingerprint,
                'source_invoice_number' => $invoice,
                'source_vin' => $vin,
                'source_started_at' => $sourceStartedAt,
                'source_invoice_url' => $invoiceUrl,
                'custody_classification' => $classification['custody_classification'],
                'custody_basis_code' => $classification['custody_basis_code'],
                'custody_basis_event_id' => $classification['custody_basis_event_id'],
                'candidate_turo_trip_normalized_id' => $classification['candidate_trip_id'],
            ]);
        } else {
            $sessionId = (int) $session['id'];
            if ($session['custody_classification'] === 'review') {
                $classification = [
                    'custody_classification' => 'review',
                    'custody_basis_code' => (string) $session['custody_basis_code'],
                    'custody_basis_event_id' => $session['custody_basis_event_id'] === null ? null : (int) $session['custody_basis_event_id'],
                    'authoritative_trip_id' => null,
                    'candidate_trip_id' => $session['candidate_turo_trip_normalized_id'] === null ? $classification['candidate_trip_id'] : (int) $session['candidate_turo_trip_normalized_id'],
                ];
            } elseif ($classification['custody_classification'] === 'review') {
                $classification['candidate_trip_id'] ??= $session['turo_trip_normalized_id'] === null ? null : (int) $session['turo_trip_normalized_id'];
                $this->repository->updateSessionClassification($sessionId, $classification);
            }
        }

        $this->repository->createLineItem([
            'company_id' => $companyId,
            'charging_session_id' => $sessionId,
            'tesla_charging_import_row_id' => $rawRowId,
            'source_line_fingerprint' => $lineFingerprint,
            'invoice_number' => $invoice,
            'vin' => $vin,
            'source_started_at' => $sourceStartedAt,
            'started_at' => $startedAt,
            'site_location_name' => $site ?: null,
            'fee_description' => $description,
            'quantity_source' => $quantity === [] ? null : json_encode($quantity, JSON_THROW_ON_ERROR),
            'unit_cost_source' => $unitCost === [] ? null : json_encode($unitCost, JSON_THROW_ON_ERROR),
            'total_inc_vat_amount' => $this->decimalFromCents($amountCents),
            'invoice_url' => $invoiceUrl,
        ]);
        $this->repository->refreshSessionCost($sessionId);
        if ($classification['authoritative_trip_id'] !== null) {
            $this->repository->ensureCase($companyId, $vehicleId, $classification['authoritative_trip_id']);
        }

        if ($classification['custody_classification'] === 'review') {
            return $this->result('review', 'review', $classification['custody_basis_code'], 'Vehicle matched, but authoritative custody did not justify automatic trip attachment.');
        }

        return $this->result('imported', 'imported', null, null);
    }

    /** @return array{custody_classification:string,custody_basis_code:string,custody_basis_event_id:?int,authoritative_trip_id:?int,candidate_trip_id:?int} */
    private function classifyCustody(int $companyId, int $vehicleId, DateTimeImmutable $at): array
    {
        $resolved = $this->custody->resolve($vehicleId, $at);
        if ($resolved['custody'] === 'guest' && $resolved['active_trip_id'] !== null) {
            $tripId = (int) $resolved['active_trip_id'];
            if ($this->repository->eligibleTripById($companyId, $vehicleId, $tripId) === null) {
                return [
                    'custody_classification' => 'review',
                    'custody_basis_code' => 'custody_trip_ineligible',
                    'custody_basis_event_id' => $resolved['basis_event_id'],
                    'authoritative_trip_id' => null,
                    'candidate_trip_id' => null,
                ];
            }

            return [
                'custody_classification' => 'guest',
                'custody_basis_code' => (string) ($resolved['basis_event_code'] ?? 'actual_handoff'),
                'custody_basis_event_id' => $resolved['basis_event_id'],
                'authoritative_trip_id' => $tripId,
                'candidate_trip_id' => null,
            ];
        }
        if ($resolved['custody'] === 'operator') {
            $scheduled = $this->repository->eligibleTripsContaining($companyId, $vehicleId, $at->format('Y-m-d H:i:s'));
            $surrounding = $scheduled === []
                ? $this->repository->surroundingEligibleTrips($companyId, $vehicleId, $at->format('Y-m-d H:i:s'))
                : ['previous' => null, 'next' => null];
            if ($surrounding['previous'] !== null && $surrounding['next'] !== null) {
                return [
                    'custody_classification' => 'between_trips',
                    'custody_basis_code' => 'authoritative_operator_between_trip_gap',
                    'custody_basis_event_id' => $resolved['basis_event_id'],
                    'authoritative_trip_id' => null,
                    'candidate_trip_id' => null,
                ];
            }

            return [
                'custody_classification' => 'operator',
                'custody_basis_code' => (string) ($resolved['basis_event_code'] ?? 'operator_custody'),
                'custody_basis_event_id' => $resolved['basis_event_id'],
                'authoritative_trip_id' => null,
                'candidate_trip_id' => null,
            ];
        }
        $scheduled = $this->repository->eligibleTripsContaining($companyId, $vehicleId, $at->format('Y-m-d H:i:s'));
        if (count($scheduled) === 1) {
            return [
                'custody_classification' => 'review',
                'custody_basis_code' => 'schedule_only_candidate',
                'custody_basis_event_id' => null,
                'authoritative_trip_id' => null,
                'candidate_trip_id' => (int) $scheduled[0]['id'],
            ];
        }
        $surrounding = $this->repository->surroundingEligibleTrips($companyId, $vehicleId, $at->format('Y-m-d H:i:s'));
        if ($scheduled === [] && $surrounding['previous'] !== null && $surrounding['next'] !== null) {
            return [
                'custody_classification' => 'review',
                'custody_basis_code' => 'schedule_only_between_trip_gap',
                'custody_basis_event_id' => null,
                'authoritative_trip_id' => null,
                'candidate_trip_id' => null,
            ];
        }

        return [
            'custody_classification' => 'review',
            'custody_basis_code' => count($scheduled) > 1 ? 'ambiguous_schedule_candidates' : 'custody_unknown',
            'custody_basis_event_id' => null,
            'authoritative_trip_id' => null,
            'candidate_trip_id' => null,
        ];
    }

    private function moneyCents(mixed $value): ?int
    {
        $text = trim((string) $value);
        $text = str_replace([',', '$'], '', $text);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalFromCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function invoiceUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** @param array<string, mixed> $payload @param list<string> $fields @return array<string, string> */
    private function sourceFields(array $payload, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '') {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    private function canonicalJson(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function canonicalPayload(array $payload): array
    {
        $canonical = [];
        foreach ($payload as $header => $value) {
            $canonical[$this->canonicalHeader((string) $header)] = $value;
        }

        return $canonical;
    }

    private function canonicalHeader(string $header): string
    {
        return match ($header) {
            'chargestartdatetime' => 'charge_start_date_time',
            'sitelocationname' => 'site_location_name',
            'invoicenumber' => 'invoice_number',
            'quantitybase' => 'quantity_base',
            'quantitytier1' => 'quantity_tier1',
            'quantitytier2' => 'quantity_tier2',
            'quantitytier3' => 'quantity_tier3',
            'quantitytier4' => 'quantity_tier4',
            'unitcostbase' => 'unit_cost_base',
            'unitcosttier1' => 'unit_cost_tier1',
            'unitcosttier2' => 'unit_cost_tier2',
            'unitcosttier3' => 'unit_cost_tier3',
            'unitcosttier4' => 'unit_cost_tier4',
            default => $header,
        };
    }

    /** @return array{status:string,counter:string,issue_code:?string,issue_detail:?string} */
    private function result(string $status, string $counter, ?string $issueCode, ?string $issueDetail): array
    {
        return [
            'status' => $status,
            'counter' => $counter,
            'issue_code' => $issueCode,
            'issue_detail' => $issueDetail,
        ];
    }

    /** @param array<string, mixed> $batch @return array{batch_id:int,rows:int,imported:int,duplicate:int,review:int,rejected:int,replayed:bool} */
    private function batchResult(array $batch, bool $replayed): array
    {
        return [
            'batch_id' => (int) $batch['id'],
            'rows' => (int) $batch['row_count'],
            'imported' => (int) $batch['imported_count'],
            'duplicate' => (int) $batch['duplicate_count'],
            'review' => (int) $batch['review_count'],
            'rejected' => (int) $batch['rejected_count'],
            'replayed' => $replayed,
        ];
    }
}
