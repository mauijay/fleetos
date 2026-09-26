<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleHealthObservationRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use Throwable;

class VehicleHealthObservationService
{
    public const OBSERVATION_CODES = ['tire_pressure', 'odometer'];
    public const SOURCES = ['manual', 'tesla_api', 'import', 'legacy_confirmed'];

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?VehicleHealthObservationRepository $repository = null,
        private readonly ?CurrentVehicleOdometerResolver $odometerResolver = null,
        private readonly ?AuditLogRepository $auditRepository = null,
        private readonly ?LookupRepository $lookupRepository = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{success:bool,id?:int,existing?:bool,errors:array<string,string>} */
    public function recordTirePressure(
        int $companyId,
        int $vehicleId,
        array $data,
        ?int $actorUserId,
        string $source = 'manual',
        ?string $sourceExternalId = null,
        ?string $sourcePayloadHash = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable();
        $errors = $this->baseErrors($companyId, $vehicleId, $data, $actorUserId, $source, $sourceExternalId, $sourcePayloadHash, $now);
        foreach (['lf_psi', 'rf_psi', 'lr_psi', 'rr_psi', 'recommended_psi'] as $field) {
            if (! $this->validPsi($data[$field] ?? null)) {
                $errors[$field] = 'Enter a whole PSI value from 1 through 200.';
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $idempotent = $this->idempotentResult($companyId, 'tire_pressure', $source, $sourceExternalId, $sourcePayloadHash);
        if ($idempotent !== null) {
            return $idempotent;
        }

        return $this->createObservation(
            $companyId,
            $vehicleId,
            'tire_pressure',
            $data,
            $actorUserId,
            $source,
            $sourceExternalId,
            $sourcePayloadHash,
            $now,
        );
    }

    /** @return array{success:bool,id?:int,existing?:bool,errors:array<string,string>} */
    public function recordOdometer(
        int $companyId,
        int $vehicleId,
        array $data,
        ?int $actorUserId,
        string $source = 'manual',
        ?string $sourceExternalId = null,
        ?string $sourcePayloadHash = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable();
        $errors = $this->baseErrors($companyId, $vehicleId, $data, $actorUserId, $source, $sourceExternalId, $sourcePayloadHash, $now);
        $odometer = filter_var($data['odometer_miles'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($odometer === false) {
            $errors['odometer_miles'] = 'Enter a non-negative whole number of miles.';
        }
        $current = $this->odometer()->resolve($companyId, $vehicleId, $now);
        if ($odometer !== false && $current !== null && $odometer < (int) $current['odometer_miles']) {
            $errors['odometer_miles'] = 'This reading is below the authoritative odometer. Use correction on the mistaken observation.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $idempotent = $this->idempotentResult($companyId, 'odometer', $source, $sourceExternalId, $sourcePayloadHash);
        if ($idempotent !== null) {
            return $idempotent;
        }

        return $this->createObservation(
            $companyId,
            $vehicleId,
            'odometer',
            array_merge($data, ['odometer_miles' => $odometer]),
            $actorUserId,
            $source,
            $sourceExternalId,
            $sourcePayloadHash,
            $now,
        );
    }

    /**
     * Records an immutable imported odometer fact without treating import order as
     * observation chronology or updating the legacy compatibility scalar.
     *
     * @return array{success:bool,id?:int,existing?:bool,errors:array<string,string>}
     */
    public function recordImportedOdometer(
        int $companyId,
        int $vehicleId,
        array $data,
        string $sourceExternalId,
        string $sourcePayloadHash,
        ?int $actorUserId = null,
        ?int $supersedesObservationId = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable();
        $errors = $this->baseErrors(
            $companyId,
            $vehicleId,
            $data,
            $actorUserId,
            'import',
            $sourceExternalId,
            $sourcePayloadHash,
            $now,
        );
        $odometer = filter_var($data['odometer_miles'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 4294967295],
        ]);
        if ($odometer === false) {
            $errors['odometer_miles'] = 'Enter a non-negative whole number of miles.';
        }
        if ($supersedesObservationId !== null) {
            $original = $this->repo()->observation($companyId, $vehicleId, $supersedesObservationId);
            if (
                $original === null
                || $original['observation_code'] !== 'odometer'
                || $original['source'] !== 'import'
                || $original['voided_at'] !== null
                || $this->repo()->hasReplacement($companyId, $vehicleId, $supersedesObservationId)
            ) {
                $errors['supersedes_observation_id'] = 'The active imported odometer observation is not available for supersession.';
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $idempotent = $this->idempotentResult($companyId, 'odometer', 'import', $sourceExternalId, $sourcePayloadHash);
        if ($idempotent !== null) {
            return $idempotent;
        }

        $this->db->transBegin();
        try {
            $id = $this->insert(
                $companyId,
                $vehicleId,
                'odometer',
                array_merge($data, ['odometer_miles' => $odometer]),
                $actorUserId,
                'import',
                $sourceExternalId,
                $sourcePayloadHash,
                $now,
                $supersedesObservationId,
            );
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Imported odometer observation transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function correct(int $companyId, int $vehicleId, int $observationId, array $data, int $actorUserId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $original = $this->repo()->observation($companyId, $vehicleId, $observationId);
        if ($original === null || $original['voided_at'] !== null || $this->repo()->hasReplacement($companyId, $vehicleId, $observationId)) {
            return $this->failure('observation', 'The active observation was not found or has already been replaced.');
        }
        $reason = trim((string) ($data['correction_reason'] ?? ''));
        if ($reason === '') {
            return $this->failure('correction_reason', 'Correction reason is required.');
        }
        $payload = array_merge($data, ['observed_at' => $data['observed_at'] ?? $original['observed_at'], 'note' => $data['note'] ?? $original['note']]);
        $errors = $this->baseErrors($companyId, $vehicleId, $payload, $actorUserId, 'manual', null, null, $now);
        if ($original['observation_code'] === 'tire_pressure') {
            foreach (['lf_psi', 'rf_psi', 'lr_psi', 'rr_psi', 'recommended_psi'] as $field) {
                if (! $this->validPsi($payload[$field] ?? null)) {
                    $errors[$field] = 'Enter a whole PSI value from 1 through 200.';
                }
            }
        } else {
            $odometer = filter_var($payload['odometer_miles'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($odometer === false) {
                $errors['odometer_miles'] = 'Enter a non-negative whole number of miles.';
            } else {
                $payload['odometer_miles'] = $odometer;
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->transBegin();
        try {
            $newId = $this->insert($companyId, $vehicleId, (string) $original['observation_code'], $payload, $actorUserId, 'manual', null, null, $now, $observationId);
            if (! $this->repo()->void($companyId, $vehicleId, $observationId, $actorUserId, $reason, $now->format('Y-m-d H:i:s'))) {
                throw new \RuntimeException('The original observation could not be superseded.');
            }
            $this->audit('updated', $observationId, $original, ['replacement_id' => $newId, 'reason' => $reason], $actorUserId);
            if ($original['observation_code'] === 'odometer') {
                $this->recomputeOdometerCache($companyId, $vehicleId, $now);
            }
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Observation correction transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $newId, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function void(int $companyId, int $vehicleId, int $observationId, string $reason, int $actorUserId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $reason = trim($reason);
        if ($reason === '') {
            return $this->failure('void_reason', 'Void reason is required.');
        }
        $observation = $this->repo()->observation($companyId, $vehicleId, $observationId);
        if ($observation === null || $observation['voided_at'] !== null || $this->repo()->hasReplacement($companyId, $vehicleId, $observationId)) {
            return $this->failure('observation', 'The active observation was not found.');
        }
        $this->db->transBegin();
        try {
            if (! $this->repo()->void($companyId, $vehicleId, $observationId, $actorUserId, $reason, $now->format('Y-m-d H:i:s'))) {
                throw new \RuntimeException('Observation was not voided.');
            }
            $this->audit('updated', $observationId, $observation, ['void_reason' => $reason], $actorUserId);
            if ($observation['observation_code'] === 'odometer') {
                $this->recomputeOdometerCache($companyId, $vehicleId, $now);
            }
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Observation void transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $observationId, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    private function createObservation(int $companyId, int $vehicleId, string $code, array $data, ?int $actorUserId, string $source, ?string $externalId, ?string $payloadHash, DateTimeImmutable $now): array
    {
        $this->db->transBegin();
        try {
            $id = $this->insert($companyId, $vehicleId, $code, $data, $actorUserId, $source, $externalId, $payloadHash, $now);
            if ($code === 'odometer') {
                $this->recomputeOdometerCache($companyId, $vehicleId, $now);
            }
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Observation transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    private function insert(int $companyId, int $vehicleId, string $code, array $data, ?int $actorUserId, string $source, ?string $externalId, ?string $payloadHash, DateTimeImmutable $now, ?int $supersedes = null): int
    {
        $observedAt = $this->observedAt($data['observed_at'] ?? null, $now);
        $values = [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'observation_code' => $code,
            'observed_at' => $observedAt->format('Y-m-d H:i:s'),
            'received_at' => $now->format('Y-m-d H:i:s'),
            'source' => $source,
            'source_external_id' => $externalId,
            'source_payload_hash' => $payloadHash,
            'actor_user_id' => $actorUserId,
            'note' => $this->nullable($data['note'] ?? null),
            'supersedes_observation_id' => $supersedes,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
        $id = $this->repo()->insertObservation($companyId, $vehicleId, $values);
        if ($code === 'tire_pressure') {
            $this->repo()->insertTirePressure($companyId, $vehicleId, $id, [
                'lf_psi' => $this->psi($data['lf_psi']),
                'rf_psi' => $this->psi($data['rf_psi']),
                'lr_psi' => $this->psi($data['lr_psi']),
                'rr_psi' => $this->psi($data['rr_psi']),
                'recommended_psi' => $this->psi($data['recommended_psi']),
            ]);
        } else {
            $this->repo()->insertOdometer($companyId, $vehicleId, $id, (int) $data['odometer_miles']);
        }
        $this->audit('created', $id, null, $values, $actorUserId);

        return $id;
    }

    /** @return array<string, string> */
    private function baseErrors(int $companyId, int $vehicleId, array $data, ?int $actorUserId, string $source, ?string $externalId, ?string $payloadHash, DateTimeImmutable $now): array
    {
        $errors = [];
        if ($this->repo()->vehicle($companyId, $vehicleId) === null) {
            $errors['vehicle'] = 'Vehicle not found.';
        }
        if (! in_array($source, self::SOURCES, true)) {
            $errors['source'] = 'Observation source is not supported.';
        }
        if ($source === 'manual' && ($actorUserId ?? 0) < 1) {
            $errors['actor'] = 'An authenticated operator is required.';
        }
        if ($source !== 'manual' && trim((string) $externalId) === '') {
            $errors['source_external_id'] = 'External source identity is required.';
        }
        if ($payloadHash !== null && preg_match('/^[a-f0-9]{64}$/i', $payloadHash) !== 1) {
            $errors['source_payload_hash'] = 'Payload hash must be a SHA-256 value.';
        }
        try {
            $observedAt = $this->observedAt($data['observed_at'] ?? null, $now);
            if ($source === 'manual' && $observedAt > $now) {
                $errors['observed_at'] = 'Observed time cannot be in the future.';
            }
        } catch (\Throwable) {
            $errors['observed_at'] = 'Enter a valid observed date and time.';
        }

        return $errors;
    }

    /** @return array{success:bool,id?:int,existing?:bool,errors:array<string,string>}|null */
    private function idempotentResult(int $companyId, string $code, string $source, ?string $externalId, ?string $payloadHash): ?array
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }
        $existing = $this->repo()->byExternalIdentity($companyId, $source, $code, trim($externalId));
        if ($existing === null) {
            return null;
        }
        if (($existing['source_payload_hash'] ?? null) !== $payloadHash) {
            return $this->failure('source_external_id', 'External observation identity was already received with different content.');
        }

        return ['success' => true, 'id' => (int) $existing['id'], 'existing' => true, 'errors' => []];
    }

    private function recomputeOdometerCache(int $companyId, int $vehicleId, DateTimeImmutable $asOf): void
    {
        $current = $this->odometer()->resolve($companyId, $vehicleId, $asOf);
        if ($current !== null) {
            $this->repo()->updateOdometerCache($companyId, $vehicleId, (int) $current['odometer_miles'], $asOf->format('Y-m-d H:i:s'));
        }
    }

    private function observedAt(mixed $value, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return $fallback;
        }
        $candidate = trim((string) $value);
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $candidate, $fallback->getTimezone());
            if ($parsed !== false && $parsed->format($format) === $candidate) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException('Unsupported observation timestamp.');
    }

    private function validPsi(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) === 1 && (int) $value >= 1 && (int) $value <= 200;
    }

    private function psi(mixed $value): int
    {
        return (int) $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function audit(string $action, int $id, ?array $old, array $new, ?int $actorUserId): void
    {
        $this->audits()->record($actorUserId, $this->lookups()->valueId('audit_action', $action), 'vehicle_health_observations', $id, $old, $new);
    }

    /** @return array{success:false,errors:array<string,string>} */
    private function failure(string $field, string $message): array
    {
        return ['success' => false, 'errors' => [$field => $message]];
    }

    private function repo(): VehicleHealthObservationRepository
    {
        return $this->repository ?? new VehicleHealthObservationRepository($this->db);
    }

    private function odometer(): CurrentVehicleOdometerResolver
    {
        return $this->odometerResolver ?? new CurrentVehicleOdometerResolver($this->repo());
    }

    private function audits(): AuditLogRepository
    {
        return $this->auditRepository ?? new AuditLogRepository($this->db);
    }

    private function lookups(): LookupRepository
    {
        return $this->lookupRepository ?? new LookupRepository($this->db);
    }
}
