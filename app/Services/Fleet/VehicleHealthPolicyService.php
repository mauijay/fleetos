<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleHealthObservationRepository;
use App\Repositories\VehicleHealthPolicyRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

class VehicleHealthPolicyService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?VehicleHealthPolicyRepository $repository = null,
        private readonly ?VehicleHealthObservationRepository $observationRepository = null,
        private readonly ?AuditLogRepository $auditRepository = null,
        private readonly ?LookupRepository $lookupRepository = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function saveTirePressurePolicy(int $companyId, int $vehicleId, array $data, int $actorUserId): array
    {
        if ($this->observations()->vehicle($companyId, $vehicleId) === null) {
            return $this->failure('vehicle', 'Vehicle not found.');
        }
        $errors = $this->validate($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $existing = $this->repo()->tirePressurePolicy($companyId, $vehicleId);
        $now = date('Y-m-d H:i:s');
        $values = [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'reminder_code' => 'tire_pressure_check',
            'rule_type' => 'interval_days',
            'interval_value' => (int) $data['interval_value'],
            'recommended_psi' => $this->psi($data['recommended_psi']),
            'acceptable_min_psi' => $this->psi($data['acceptable_min_psi']),
            'acceptable_max_psi' => $this->psi($data['acceptable_max_psi']),
            'safety_min_psi' => $this->nullablePsi($data['safety_min_psi'] ?? null),
            'safety_max_psi' => $this->nullablePsi($data['safety_max_psi'] ?? null),
            'is_enabled' => $this->boolean($data['is_enabled'] ?? null),
            'updated_by' => $actorUserId,
            'updated_at' => $now,
        ];

        $this->db->transBegin();
        try {
            if ($existing === null) {
                $values['created_by'] = $actorUserId;
                $values['created_at'] = $now;
                $id = $this->repo()->insert($companyId, $vehicleId, $values);
                $this->audit('created', $id, null, $values, $actorUserId);
            } else {
                $id = (int) $existing['id'];
                $this->repo()->update($companyId, $vehicleId, $id, $values);
                $this->audit('updated', $id, $existing, array_merge($existing, $values), $actorUserId);
            }
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Tire-pressure policy transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function disableTirePressurePolicy(int $companyId, int $vehicleId, int $actorUserId): array
    {
        $existing = $this->repo()->tirePressurePolicy($companyId, $vehicleId);
        if ($existing === null) {
            return $this->failure('policy', 'Tire-pressure policy not found.');
        }
        $values = ['is_enabled' => false, 'updated_by' => $actorUserId, 'updated_at' => date('Y-m-d H:i:s')];
        $this->db->transBegin();
        try {
            $this->repo()->update($companyId, $vehicleId, (int) $existing['id'], $values);
            $this->audit('updated', (int) $existing['id'], $existing, array_merge($existing, $values), $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Tire-pressure policy disable transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => (int) $existing['id'], 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];
        $interval = filter_var($data['interval_value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($interval === false) {
            $errors['interval_value'] = 'Check interval must be a positive whole number of days.';
        }
        foreach (['recommended_psi', 'acceptable_min_psi', 'acceptable_max_psi'] as $field) {
            if (! $this->validPsi($data[$field] ?? null)) {
                $errors[$field] = 'Enter a whole PSI value from 1 through 200.';
            }
        }
        foreach (['safety_min_psi', 'safety_max_psi'] as $field) {
            if ($this->present($data[$field] ?? null) && ! $this->validPsi($data[$field])) {
                $errors[$field] = 'Enter a whole PSI value from 1 through 200.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $recommended = (int) $data['recommended_psi'];
        $minimum = (int) $data['acceptable_min_psi'];
        $maximum = (int) $data['acceptable_max_psi'];
        if ($minimum > $maximum) {
            $errors['acceptable_max_psi'] = 'Acceptable maximum must be at least the acceptable minimum.';
        } elseif ($recommended < $minimum || $recommended > $maximum) {
            $errors['recommended_psi'] = 'Recommended PSI must be inside the acceptable range.';
        }
        $safetyMinimum = $this->present($data['safety_min_psi'] ?? null) ? (int) $data['safety_min_psi'] : null;
        $safetyMaximum = $this->present($data['safety_max_psi'] ?? null) ? (int) $data['safety_max_psi'] : null;
        if ($safetyMinimum !== null && $safetyMinimum > $minimum) {
            $errors['safety_min_psi'] = 'Safety minimum cannot exceed the acceptable minimum.';
        }
        if ($safetyMaximum !== null && $safetyMaximum < $maximum) {
            $errors['safety_max_psi'] = 'Safety maximum cannot be below the acceptable maximum.';
        }
        if ($safetyMinimum !== null && $safetyMaximum !== null && $safetyMinimum > $safetyMaximum) {
            $errors['safety_max_psi'] = 'Safety maximum must be at least the safety minimum.';
        }

        return $errors;
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

    private function nullablePsi(mixed $value): ?int
    {
        return $this->present($value) ? $this->psi($value) : null;
    }

    private function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function boolean(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'true', 'on'], true);
    }

    private function audit(string $action, int $id, ?array $old, array $new, int $actorUserId): void
    {
        $this->audits()->record($actorUserId, $this->lookups()->valueId('audit_action', $action), 'vehicle_health_policies', $id, $old, $new);
    }

    /** @return array{success:false,errors:array<string,string>} */
    private function failure(string $field, string $message): array
    {
        return ['success' => false, 'errors' => [$field => $message]];
    }

    private function repo(): VehicleHealthPolicyRepository
    {
        return $this->repository ?? new VehicleHealthPolicyRepository($this->db);
    }

    private function observations(): VehicleHealthObservationRepository
    {
        return $this->observationRepository ?? new VehicleHealthObservationRepository($this->db);
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
