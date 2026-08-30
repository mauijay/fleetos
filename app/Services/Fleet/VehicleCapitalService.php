<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleCapitalRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

class VehicleCapitalService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?VehicleCapitalRepository $repository = null,
        private readonly ?AuditLogRepository $auditRepository = null,
        private readonly ?LookupRepository $lookupRepository = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function workspace(int $vehicleId): ?array
    {
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            return null;
        }
        $acquisition = $this->repo()->acquisition($vehicleId);
        $loans = $this->repo()->loans($vehicleId);
        foreach ($loans as &$loan) {
            $loan['snapshots'] = $this->repo()->snapshots((int) $loan['id']);
        }
        unset($loan);

        return [
            'vehicle' => $vehicle,
            'acquisition' => $acquisition,
            'acquisition_state' => $this->acquisitionState($acquisition),
            'financing_state' => $this->financingState($loans),
            'loans' => $loans,
            'startup_costs' => $this->repo()->startupCosts($vehicleId),
            'notes' => $this->repo()->vehicleNotes($vehicleId),
            'files' => $this->repo()->vehicleFiles($vehicleId),
            'lifetime_operating_revenue' => $this->repo()->lifetimeOperatingRevenue($vehicleId),
            'lenders' => $this->repo()->lenders(),
            'acquisition_methods' => $this->repo()->lookupValues('acquisition_method'),
            'funding_methods' => $this->repo()->lookupValues('funding_method'),
            'loan_statuses' => $this->repo()->lookupValues('loan_status'),
            'balance_sources' => $this->repo()->lookupValues('loan_balance_source'),
        ];
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function saveAcquisition(int $vehicleId, array $data, int $actorUserId): array
    {
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            return $this->failure('vehicle', 'Vehicle not found.');
        }
        $errors = $this->validateAcquisition($vehicleId, $data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $existing = $this->repo()->acquisition($vehicleId);
        $values = $this->acquisitionValues($vehicleId, $data);

        return $this->transactional(function () use ($existing, $values, $actorUserId): int {
            if ($existing === null) {
                $values['created_at'] = date('Y-m-d H:i:s');
                $id = $this->repo()->insert('vehicle_acquisitions', $values);
                $this->audit('created', 'vehicle_acquisitions', $id, null, $values, $actorUserId);

                return $id;
            }
            $id = (int) $existing['id'];
            $old = $this->auditFields($existing, array_keys($values));
            $this->repo()->update('vehicle_acquisitions', $id, $values);
            $this->audit('updated', 'vehicle_acquisitions', $id, $old, $values, $actorUserId);

            return $id;
        });
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function saveLoan(int $vehicleId, ?int $loanId, array $data, int $actorUserId): array
    {
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            return $this->failure('vehicle', 'Vehicle not found.');
        }
        $existing = $loanId === null ? null : $this->repo()->loan($loanId);
        if ($loanId !== null && ($existing === null || (int) $existing['fleet_vehicle_id'] !== $vehicleId)) {
            return $this->failure('loan', 'Loan not found for this vehicle.');
        }
        $errors = $this->validateLoan($vehicleId, $loanId, $data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $values = $this->loanValues($vehicleId, $data);

        return $this->transactional(function () use ($existing, $values, $actorUserId): int {
            if ($existing === null) {
                $values['created_at'] = date('Y-m-d H:i:s');
                $id = $this->repo()->insert('loans', $values);
                $this->audit('created', 'loans', $id, null, $values, $actorUserId);

                return $id;
            }
            $id = (int) $existing['id'];
            $old = $this->auditFields($existing, array_keys($values));
            $this->repo()->update('loans', $id, $values);
            $this->audit('updated', 'loans', $id, $old, $values, $actorUserId);

            return $id;
        });
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function saveSnapshot(int $vehicleId, int $loanId, array $data, int $actorUserId): array
    {
        $loan = $this->repo()->loan($loanId);
        if ($loan === null || (int) $loan['fleet_vehicle_id'] !== $vehicleId) {
            return $this->failure('loan', 'Loan not found for this vehicle.');
        }
        $snapshotId = $this->nullableInt($data['snapshot_id'] ?? null);
        $existing = $snapshotId === null ? null : $this->repo()->snapshot($snapshotId);
        if ($snapshotId !== null && ($existing === null || (int) $existing['loan_id'] !== $loanId)) {
            return $this->failure('snapshot', 'Snapshot not found for this financing agreement.');
        }
        $merged = $existing === null ? $data : $this->mergeSnapshotCorrection($existing, $data);
        $errors = $this->validateSnapshot($merged);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }
        $values = $this->snapshotValues($loanId, $merged, $actorUserId);
        $existing ??= $this->repo()->snapshotForDate($loanId, (string) $values['as_of_date']);

        return $this->transactional(function () use ($existing, $values, $actorUserId): int {
            if ($existing === null) {
                $values['created_at'] = date('Y-m-d H:i:s');
                $id = $this->repo()->insert('loan_balance_snapshots', $values);
                $this->audit('created', 'loan_balance_snapshots', $id, null, $values, $actorUserId);

                return $id;
            }
            $id = (int) $existing['id'];
            $old = $this->auditFields($existing, array_keys($values));
            $this->repo()->update('loan_balance_snapshots', $id, $values);
            $this->audit('updated', 'loan_balance_snapshots', $id, $old, $values, $actorUserId);

            return $id;
        });
    }

    /** @return array<string, mixed> */
    private function mergeSnapshotCorrection(array $existing, array $data): array
    {
        $merged = $existing;
        foreach (['as_of_date', 'principal_balance', 'payoff_amount', 'source_method_lookup_value_id', 'source_reference', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $merged[$field] = $data[$field];
            }
        }

        return $merged;
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function createLender(array $data, int $actorUserId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->failure('name', 'Lender name is required.');
        }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        if ($this->db->table('companies')->where('slug', $slug)->countAllResults() > 0) {
            return $this->failure('name', 'A company with this lender name already exists.');
        }

        return $this->transactional(function () use ($name, $slug, $actorUserId): int {
            $now = date('Y-m-d H:i:s');
            $company = ['company_type_lookup_value_id' => $this->lookups()->valueId('company_type', 'lender'), 'name' => $name, 'slug' => $slug, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            $companyId = $this->repo()->insert('companies', $company);
            $lender = ['company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now];
            $lenderId = $this->repo()->insert('lenders', $lender);
            $this->audit('created', 'companies', $companyId, null, $company, $actorUserId);
            $this->audit('created', 'lenders', $lenderId, null, $lender, $actorUserId);

            return $lenderId;
        });
    }

    /** @return array<string, string> */
    private function validateAcquisition(int $vehicleId, array $data): array
    {
        $errors = [];
        $this->validLookup($errors, 'acquisition_method_lookup_value_id', $data, 'acquisition_method');
        $this->validLookup($errors, 'funding_method_lookup_value_id', $data, 'funding_method');
        foreach (['purchase_order_subtotal', 'rebates_incentives', 'trade_in_credit', 'cash_paid_at_closing'] as $field) {
            $this->validMoney($errors, $field, $data[$field] ?? null);
        }
        $acquisitionLoanId = $this->nullableInt($data['acquisition_loan_id'] ?? null);
        if ($acquisitionLoanId !== null) {
            $loan = $this->repo()->loan($acquisitionLoanId);
            if ($loan === null || (int) $loan['fleet_vehicle_id'] !== $vehicleId) {
                $errors['acquisition_loan_id'] = 'Choose a financing agreement for this vehicle.';
            } elseif ($this->lookupCode('funding_method', $data['funding_method_lookup_value_id'] ?? null) === 'cash') {
                $errors['acquisition_loan_id'] = 'A cash acquisition cannot have an acquisition financing agreement.';
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateLoan(int $vehicleId, ?int $loanId, array $data): array
    {
        $errors = [];
        $lenderId = (int) ($data['lender_id'] ?? 0);
        if ($lenderId <= 0 || ! $this->repo()->lenderIsValid($lenderId)) {
            $errors['lender_id'] = 'Choose a valid active lender.';
        }
        $this->validLookup($errors, 'loan_status_lookup_value_id', $data, 'loan_status', true);
        $accountLast4 = trim((string) ($data['account_number_last4'] ?? ''));
        if ($accountLast4 !== '' && ! preg_match('/^\d{4}$/', $accountLast4)) {
            $errors['account_number_last4'] = 'Account reference must contain exactly four digits.';
        }
        foreach (['original_principal', 'monthly_payment', 'balloon_amount'] as $field) {
            $this->validMoney($errors, $field, $data[$field] ?? null);
        }
        $apr = trim((string) ($data['interest_rate'] ?? ''));
        if ($apr !== '' && (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $apr) || (float) $apr >= 100)) {
            $errors['interest_rate'] = 'APR must be at least 0 and less than 100 with up to four decimal places.';
        }
        $term = trim((string) ($data['term_months'] ?? ''));
        if ($term !== '' && (! ctype_digit($term) || (int) $term < 1 || (int) $term > 600)) {
            $errors['term_months'] = 'Term must be between 1 and 600 months.';
        }
        $dueDay = trim((string) ($data['payment_due_day'] ?? ''));
        if ($dueDay !== '' && (! ctype_digit($dueDay) || (int) $dueDay < 1 || (int) $dueDay > 31)) {
            $errors['payment_due_day'] = 'Payment due day must be between 1 and 31.';
        }
        $opened = $this->nullable($data['opened_on'] ?? null);
        foreach (['first_payment_on', 'matures_on', 'paid_off_on', 'closed_on'] as $field) {
            $date = $this->nullable($data[$field] ?? null);
            if ($opened !== null && $date !== null && $date < $opened) {
                $errors[$field] = 'Date cannot be before the origination date.';
            }
        }
        $predecessorId = $this->nullableInt($data['refinanced_from_loan_id'] ?? null);
        if ($predecessorId !== null && ($predecessorId === $loanId || $this->createsRefinanceCycle($vehicleId, $loanId, $predecessorId))) {
            $errors['refinanced_from_loan_id'] = 'Refinance relationship cannot reference itself or create a cycle.';
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateSnapshot(array $data): array
    {
        $errors = [];
        if (! $this->validDate((string) ($data['as_of_date'] ?? ''))) {
            $errors['as_of_date'] = 'Enter a valid as-of date.';
        }
        $principal = trim((string) ($data['principal_balance'] ?? ''));
        $payoff = trim((string) ($data['payoff_amount'] ?? ''));
        if ($principal === '' && $payoff === '') {
            $errors['balance'] = 'Enter a principal balance, payoff amount, or both.';
        }
        $this->validMoney($errors, 'principal_balance', $principal);
        $this->validMoney($errors, 'payoff_amount', $payoff);
        $this->validLookup($errors, 'source_method_lookup_value_id', $data, 'loan_balance_source', true);

        return $errors;
    }

    private function createsRefinanceCycle(int $vehicleId, ?int $loanId, int $predecessorId): bool
    {
        $seen = [];
        $currentId = $predecessorId;
        while ($currentId > 0 && ! isset($seen[$currentId])) {
            $seen[$currentId] = true;
            $loan = $this->repo()->loan($currentId);
            if ($loan === null || (int) $loan['fleet_vehicle_id'] !== $vehicleId) {
                return true;
            }
            if ($loanId !== null && (int) $loan['id'] === $loanId) {
                return true;
            }
            $currentId = (int) ($loan['refinanced_from_loan_id'] ?? 0);
        }

        return $currentId > 0;
    }

    private function validLookup(array &$errors, string $field, array $data, string $type, bool $required = false): void
    {
        $id = (int) ($data[$field] ?? 0);
        if ($id === 0 && ! $required) {
            return;
        }
        if (! in_array($id, array_map(static fn (array $row): int => (int) $row['id'], $this->repo()->lookupValues($type)), true)) {
            $errors[$field] = 'Choose a valid option.';
        }
    }

    private function validMoney(array &$errors, string $field, mixed $value): void
    {
        $value = trim((string) $value);
        if ($value !== '' && ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            $errors[$field] = 'Enter a nonnegative amount with no more than two decimal places.';
        }
    }

    /** @return array<string, mixed> */
    private function acquisitionValues(int $vehicleId, array $data): array
    {
        return [
            'fleet_vehicle_id' => $vehicleId,
            'acquisition_method_lookup_value_id' => $this->nullableInt($data['acquisition_method_lookup_value_id'] ?? null),
            'funding_method_lookup_value_id' => $this->nullableInt($data['funding_method_lookup_value_id'] ?? null),
            'acquisition_loan_id' => $this->nullableInt($data['acquisition_loan_id'] ?? null),
            'source_name' => $this->nullable($data['source_name'] ?? null),
            'external_reference' => $this->nullable($data['external_reference'] ?? null),
            'purchase_order_subtotal' => $this->nullable($data['purchase_order_subtotal'] ?? null),
            'rebates_incentives' => $this->nullable($data['rebates_incentives'] ?? null),
            'trade_in_credit' => $this->nullable($data['trade_in_credit'] ?? null),
            'cash_paid_at_closing' => $this->nullable($data['cash_paid_at_closing'] ?? null),
            'notes' => $this->nullable($data['notes'] ?? null),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function loanValues(int $vehicleId, array $data): array
    {
        return [
            'fleet_vehicle_id' => $vehicleId,
            'lender_id' => (int) $data['lender_id'],
            'loan_name' => $this->nullable($data['loan_name'] ?? null),
            'loan_status_lookup_value_id' => (int) $data['loan_status_lookup_value_id'],
            'account_number_last4' => $this->nullable($data['account_number_last4'] ?? null),
            'original_principal' => $this->nullable($data['original_principal'] ?? null),
            'interest_rate' => $this->nullable($data['interest_rate'] ?? null),
            'monthly_payment' => $this->nullable($data['monthly_payment'] ?? null),
            'balloon_amount' => $this->nullable($data['balloon_amount'] ?? null),
            'term_months' => $this->nullableInt($data['term_months'] ?? null),
            'opened_on' => $this->nullable($data['opened_on'] ?? null),
            'first_payment_on' => $this->nullable($data['first_payment_on'] ?? null),
            'payment_due_day' => $this->nullableInt($data['payment_due_day'] ?? null),
            'matures_on' => $this->nullable($data['matures_on'] ?? null),
            'paid_off_on' => $this->nullable($data['paid_off_on'] ?? null),
            'closed_on' => $this->nullable($data['closed_on'] ?? null),
            'notes' => $this->nullable($data['notes'] ?? null),
            'refinanced_from_loan_id' => $this->nullableInt($data['refinanced_from_loan_id'] ?? null),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotValues(int $loanId, array $data, int $actorUserId): array
    {
        return [
            'loan_id' => $loanId,
            'as_of_date' => trim((string) $data['as_of_date']),
            'principal_balance' => $this->nullable($data['principal_balance'] ?? null),
            'payoff_amount' => $this->nullable($data['payoff_amount'] ?? null),
            'source_method_lookup_value_id' => (int) $data['source_method_lookup_value_id'],
            'source_reference' => $this->nullable($data['source_reference'] ?? null),
            'notes' => $this->nullable($data['notes'] ?? null),
            'created_by' => $actorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    private function transactional(callable $operation): array
    {
        $this->db->transBegin();
        try {
            $id = $operation();
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Financial write transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    private function audit(string $action, string $table, int $id, ?array $old, array $new, int $actorUserId): void
    {
        $this->audits()->record($actorUserId, $this->lookups()->valueId('audit_action', $action), $table, $id, $old, $new);
    }

    /** @param string[] $fields @return array<string, mixed> */
    private function auditFields(array $row, array $fields): array
    {
        return array_intersect_key($row, array_fill_keys($fields, true));
    }

    private function acquisitionState(?array $acquisition): string
    {
        if ($acquisition === null) {
            return 'not_entered';
        }
        $funding = (string) ($acquisition['funding_method_code'] ?? '');
        if ($funding === 'cash') {
            return 'cash_purchase';
        }
        if (in_array($funding, ['financed', 'mixed'], true)) {
            return 'financed';
        }

        return 'unknown_incomplete';
    }

    private function financingState(array $loans): string
    {
        if ($loans === []) {
            return 'none';
        }
        $statuses = array_column($loans, 'status_code');
        if (in_array('active', $statuses, true)) {
            return 'active';
        }
        if (in_array('refinanced', $statuses, true)) {
            return 'refinanced';
        }
        if (in_array('paid_off', $statuses, true)) {
            return 'paid_off';
        }

        return 'unknown_incomplete';
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }

    private function lookupCode(string $type, mixed $value): ?string
    {
        $id = $this->nullableInt($value);
        foreach ($this->repo()->lookupValues($type) as $lookup) {
            if ((int) $lookup['id'] === $id) {
                return (string) $lookup['code'];
            }
        }

        return null;
    }

    /** @return array{success:false,errors:array<string,string>} */
    private function failure(string $field, string $message): array
    {
        return ['success' => false, 'errors' => [$field => $message]];
    }

    private function repo(): VehicleCapitalRepository
    {
        return $this->repository ?? new VehicleCapitalRepository($this->db);
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
