<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRepository;
use CodeIgniter\Database\BaseConnection;
use Config\VehicleDamageRepairCosts as Policy;
use DateTimeImmutable;
use InvalidArgumentException;

/** No transaction or financial writes: the aggregate runner owns every material command. */
class VehicleDamageRepairCostService
{
    public readonly Costs $costs;
    public readonly VehicleDamageRepairEstimateService $sources;
    private VehicleDamageRepairRepository $work;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->costs = new Costs($db);
        $this->sources = new VehicleDamageRepairEstimateService($db);
        $this->work = new VehicleDamageRepairRepository($db);
    }

    public function normalize(array $data, int $c, int $v, int $j): array
    {
        foreach (['expected_version', 'vendor_company_id', 'repair_document_id', 'related_cost_entry_id', 'cost_entry_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== '' && ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1)) {
                throw new InvalidArgumentException('Command identities and versions must be positive scalar integers.');
            }
        }
        if (isset($data['document'])) {
            if (! is_array($data['document']) || ! empty($data['document']['source_document_id'])) {
                throw new InvalidArgumentException('Choose a new binary upload or an existing same-job repair_document_id.');
            }
            if (isset($data['document']['descriptor']['size_bytes']) && is_string($data['document']['descriptor']['size_bytes']) && ctype_digit($data['document']['descriptor']['size_bytes'])) {
                $data['document']['descriptor']['size_bytes'] = (int) $data['document']['descriptor']['size_bytes'];
            }
            $data['document'] = $this->sources->documents->normalizeSource($data['document'], $c, $v);
        }
        return $data;
    }

    /** B2.3 semantics deliberately do not call or alter either earlier normalizer. */
    public static function semantic(array $data): array
    {
        $fields = explode(' ', 'expected_version kind_code amount currency occurred_on vendor_company_id vendor_snapshot vendor_reference note repair_document_id expected_document_state related_cost_entry_id cost_entry_id expected_entry_state confirmed performed_work_confirmed verified_zero_confirmed reason vendor_mismatch_confirmed vendor_mismatch_reason duplicate_review_fingerprint duplicate_review_confirmed duplicate_review_reason expected_invoiced_state expected_finalization_state document');
        $data = array_intersect_key($data, array_flip($fields));
        if (isset($data['amount'])) {
            $data['amount'] = RepairEstimateMoney::normalize($data['amount']);
        }
        if (isset($data['document'])) {
            $data['document'] = array_intersect_key($data['document'], array_flip(['kind_code', 'label', 'note', 'descriptor']));
        }
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                $value = array_map($normalize, $value);
                if (! array_is_list($value)) {
                    ksort($value);
                }
                return $value;
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Command fields must contain scalar values.');
            }
            return $value === null ? null : trim((string) $value);
        };
        return $normalize($data);
    }

    public function snapshot(int $c, int $v, int $j): array
    {
        $rows = $this->costs->entries($c, $v, $j);
        return ['entries' => $rows, 'totals' => Costs::totals($rows)];
    }

    public function entryFingerprint(int $c, int $v, int $j, int $id): string
    {
        $rows = $this->costs->entries($c, $v, $j);
        $row = $this->owned($rows, $id);
        $successors = array_values(array_filter($rows, fn (array $e): bool => (int) ($e['replacement_of_cost_entry_id'] ?? 0) === $id));
        $parent = empty($row['related_cost_entry_id']) ? null : Costs::head($rows, (int) $row['related_cost_entry_id'], $row['kind_code'] === 'payment' || $row['status_code'] === 'voided');
        $budget = null;
        if (in_array($row['kind_code'], ['invoice', 'payment', 'invoice_credit', 'payment_refund'], true)) {
            $budgetParent = $parent !== null && $row['kind_code'] !== 'payment' ? $parent : Costs::head($rows, $id, true);
            $budget = $this->remaining($rows, $budgetParent);
        }
        $latest = $this->costs->latestEntryEvent($c, $j, $id);
        return Estimates::digest([$row, $successors, $parent, $budget, $this->sources->documents->documents->fingerprint($c, $v, $j, (int) $row['repair_document_id']), $latest]);
    }

    public function invoicedFingerprint(int $c, int $v, int $j): string
    {
        $rows = $this->costs->entries($c, $v, $j);
        $current = [];
        foreach ($rows as $row) {
            if ($row['status_code'] === 'recorded' && in_array($row['kind_code'], ['invoice', 'invoice_credit'], true)) {
                $current[] = [(int) $row['id'], $this->entryFingerprint($c, $v, $j, (int) $row['id'])];
            }
        }
        return Estimates::digest([$current, Costs::totals($rows)['invoiced'], $this->work->job($c, $v, $j)['version']]);
    }

    public function finalizationFingerprint(int $c, int $v, int $j): string
    {
        $job = $this->work->job($c, $v, $j);
        return Estimates::digest([$this->invoicedFingerprint($c, $v, $j), $job['cost_finalized_at'], $job['cost_finalized_by'], $job['cost_finalization_note']]);
    }

    public static function clearFinalization(array $job): array
    {
        foreach (['cost_finalized_at', 'cost_finalized_by', 'cost_finalization_note'] as $field) {
            $job[$field] = null;
        }
        return $job;
    }

    public function apply(string $action, int $c, int $v, array $job, array $data, int $actor, string $now): array
    {
        $j = (int) $job['id'];
        $rows = $this->costs->entries($c, $v, $j);
        self::confirmed($data['confirmed'] ?? null);
        $receipt = [];
        if ($action === 'b23_finalize') {
            if ($job['cost_finalized_at'] !== null || ! in_array($job['status_code'], ['completed', 'cancelled'], true) || ! $this->work->hasPerformedWork($c, $v, $j) || Costs::totals($rows)['invoiced'] === null) {
                throw new InvalidArgumentException('Finalization requires an unfinalized terminal job, retained performed work, and a recorded invoice.');
            }
            $this->fingerprint($this->invoicedFingerprint($c, $v, $j), $data['expected_invoiced_state'] ?? null);
            $frozen = [];
            foreach ($rows as $row) {
                if ($row['status_code'] === 'recorded' && in_array($row['kind_code'], ['invoice', 'invoice_credit'], true)) {
                    $this->verifyDocument($c, $v, $j, (int) $row['repair_document_id'], $row['kind_code']);
                    $this->validateRelations($rows, $row);
                    $frozen[] = ['id' => (int) $row['id'], 'fingerprint' => $this->entryFingerprint($c, $v, $j, (int) $row['id'])];
                }
            }
            $job['cost_finalized_at'] = $now;
            $job['cost_finalized_by'] = $actor;
            $job['cost_finalization_note'] = VehicleDamageRepairDocumentService::text($data['note'] ?? null, 2000, true);
            $receipt['finalization'] = ['entries' => $frozen, 'invoiced_total' => Costs::totals($rows)['invoiced'], 'actor' => $actor, 'recorded_at' => $now, 'note' => $job['cost_finalization_note']];
            return [$job, 'repair_cost_finalized', $receipt];
        }
        if ($action === 'b23_invalidate') {
            if ($job['cost_finalized_at'] === null) {
                throw new InvalidArgumentException('Invoiced cost is not finalized.');
            }
            $this->fingerprint($this->finalizationFingerprint($c, $v, $j), $data['expected_finalization_state'] ?? null);
            VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
            return [self::clearFinalization($job), 'repair_cost_finalization_invalidated', []];
        }
        $old = null;
        if (in_array($action, ['b23_void', 'b23_replace'], true)) {
            $old = $this->owned($rows, (int) ($data['cost_entry_id'] ?? 0));
            if ($old['status_code'] !== 'recorded' || (int) Costs::head($rows, (int) $old['id'])['id'] !== (int) $old['id']) {
                throw new InvalidArgumentException('Correction requires the current recorded lineage head.');
            }
            $this->fingerprint($this->entryFingerprint($c, $v, $j, (int) $old['id']), $data['expected_entry_state'] ?? null);
            $reason = VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
            if ($action === 'b23_void') {
                foreach ($rows as $dependent) {
                    if ($dependent['status_code'] === 'recorded' && in_array($dependent['kind_code'], ['invoice_credit', 'payment_refund'], true) && (int) Costs::head($rows, (int) $dependent['related_cost_entry_id'])['id'] === (int) $old['id']) {
                        throw new InvalidArgumentException('Correct recorded dependent credits/refunds before voiding their parent.');
                    }
                }
                $this->costs->void($c, $j, (int) $old['id'], $actor, $reason, $now);
                return [in_array($old['kind_code'], ['invoice', 'invoice_credit'], true) ? self::clearFinalization($job) : $job, 'repair_cost_entry_voided', ['cost_entry_id' => (int) $old['id']]];
            }
        } elseif ($action !== 'b23_record') {
            throw new InvalidArgumentException('Unsupported repair cost command.');
        }
        $facts = $this->facts($job, $data);
        if ($old !== null && $old['kind_code'] !== $facts['kind_code']) {
            throw new InvalidArgumentException('Replacement must retain the same kind. Use an explicit void and a separate new fact to change kind.');
        }
        if ($old !== null) {
            $rows = array_map(function (array $row) use ($old): array {
                if ((int) $row['id'] === (int) $old['id']) {
                    $row['status_code'] = 'voided';
                }
                return $row;
            }, $rows);
        }
        $syntheticId = max([0, ...array_map('intval', array_column($rows, 'id'))]) + 1;
        $candidate = $facts + ['id' => $syntheticId, 'company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'replacement_of_cost_entry_id' => $old['id'] ?? null, 'status_code' => 'recorded'];
        $projected = [...$rows, $candidate];
        // Revalidate every genuine reduction after a parent correction; never rewrite links.
        foreach ($projected as $row) {
            if ($row['status_code'] === 'recorded') {
                $this->validateRelations($projected, $row);
            }
        }
        $docData = $data['document'] ?? [];
        if (! empty($data['repair_document_id'])) {
            if ($docData !== []) {
                throw new InvalidArgumentException('Choose exactly one existing document or a new upload.');
            }
            $doc = $this->verifyDocument($c, $v, $j, (int) $data['repair_document_id'], $facts['kind_code']);
            $this->fingerprint($this->sources->documents->documents->fingerprint($c, $v, $j, (int) $doc['id']), $data['expected_document_state'] ?? null);
            if ($old !== null && (int) $doc['id'] === (int) $old['repair_document_id'] && Estimates::digest($facts) === Estimates::digest(array_intersect_key($old, $facts))) {
                throw new InvalidArgumentException('No change to replace.');
            }
        } else {
            if (($docData['kind_code'] ?? null) !== Policy::DOCUMENT_KINDS[$facts['kind_code']] || ! ($docData['upload'] ?? null) instanceof \CodeIgniter\HTTP\Files\UploadedFile || isset($docData['external_reference']) || isset($docData['file_id']) || isset($docData['image_id'])) {
                throw new InvalidArgumentException('A matching verified binary supporting document is required.');
            }
            $doc = null;
        }
        $checksum = $doc['content_checksum'] ?? $docData['descriptor']['checksum'] ?? null;
        $duplicates = $this->duplicates($c, $v, $j, $facts, $checksum, (int) ($old['id'] ?? 0));
        if ($duplicates['candidates'] !== []) {
            $this->fingerprint($duplicates['fingerprint'], $data['duplicate_review_fingerprint'] ?? null);
            self::confirmed($data['duplicate_review_confirmed'] ?? null);
            VehicleDamageRepairDocumentService::text($data['duplicate_review_reason'] ?? null, 2000, true);
        }
        $doc ??= $this->sources->documents->attach($c, $v, $j, $docData, $actor, $now);
        $this->verifyDocument($c, $v, $j, (int) $doc['id'], $facts['kind_code']);
        if ($old !== null) {
            $this->costs->void($c, $j, (int) $old['id'], $actor, $reason, $now);
        }
        $id = $this->costs->insert($facts + ['company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'repair_document_id' => (int) $doc['id'], 'replacement_of_cost_entry_id' => $old['id'] ?? null, 'status_code' => 'recorded', 'created_by' => $actor, 'created_at' => $now, 'voided_at' => null, 'voided_by' => null, 'void_reason' => null]);
        $event = $old !== null ? 'repair_cost_entry_replaced' : (in_array($facts['kind_code'], ['invoice', 'invoice_credit'], true) ? 'repair_charge_recorded' : 'repair_payment_recorded');
        return [in_array($facts['kind_code'], ['invoice', 'invoice_credit'], true) ? self::clearFinalization($job) : $job, $event, ['cost_entry_id' => $id, 'document_id' => (int) $doc['id'], 'duplicate_review' => $duplicates]];
    }

    public function verifyDocument(int $c, int $v, int $j, int $id, string $kind): array
    {
        $doc = $this->sources->documents->documents->document($c, $v, $j, $id);
        if ($doc === null || $doc['kind_code'] !== Policy::DOCUMENT_KINDS[$kind] || $doc['archived_at'] !== null || $doc['external_reference'] !== null
            || ((int) ($doc['file_id'] !== null) + (int) ($doc['image_id'] !== null)) !== 1
            || $this->sources->documents->storage->resolve($c, $doc, $this->sources->documents->documents->metadata($doc)) === null) {
            throw new InvalidArgumentException('Supporting document must be active, matching, same-job verified binary evidence.');
        }
        return $doc;
    }

    private function facts(array $job, array $data): array
    {
        if (isset($data['file_id']) || isset($data['image_id'])) {
            throw new InvalidArgumentException('Raw file/image IDs do not authorize cost sources.');
        }
        $kind = $data['kind_code'] ?? '';
        if (! is_string($kind) || ! isset(Policy::KINDS[$kind])) {
            throw new InvalidArgumentException('Choose a supported vendor-side cost kind.');
        }
        $amount = RepairEstimateMoney::normalize($data['amount'] ?? null);
        if ($amount === '0.00') {
            if ($kind !== 'invoice') {
                throw new InvalidArgumentException('Credits, payments, and refunds must be positive.');
            }
            self::confirmed($data['verified_zero_confirmed'] ?? null);
        }
        if ($kind === 'invoice') {
            self::confirmed($data['performed_work_confirmed'] ?? null);
        }
        if (($data['currency'] ?? '') !== 'USD') {
            throw new InvalidArgumentException('Only USD cost facts are supported.');
        }
        $date = VehicleDamageRepairDocumentService::text($data['occurred_on'] ?? null, 10, true);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Occurrence must be a valid YYYY-MM-DD date.');
        }
        $vendor = (int) ($data['vendor_company_id'] ?? 0) ?: null;
        if ($vendor !== null && ! array_any($this->work->vendors((int) $job['company_id']), fn (array $v): bool => (int) $v['id'] === $vendor)) {
            throw new InvalidArgumentException('Vendor identity is not eligible for this company.');
        }
        $snapshot = VehicleDamageRepairDocumentService::text($data['vendor_snapshot'] ?? null, 190, true);
        $facts = ['kind_code' => $kind, 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => $date, 'vendor_company_id' => $vendor, 'vendor_snapshot' => $snapshot,
            'vendor_reference' => VehicleDamageRepairDocumentService::text($data['vendor_reference'] ?? null, 120), 'note' => VehicleDamageRepairDocumentService::text($data['note'] ?? null, 2000),
            'related_cost_entry_id' => (int) ($data['related_cost_entry_id'] ?? 0) ?: null];
        if ((! empty($job['vendor_company_id']) || ! empty($job['vendor_snapshot'])) && ! self::sameVendor($job, $facts)) {
            self::confirmed($data['vendor_mismatch_confirmed'] ?? null);
            VehicleDamageRepairDocumentService::text($data['vendor_mismatch_reason'] ?? null, 2000, true);
        }
        return $facts;
    }

    public static function sameVendor(array $a, array $b): bool
    {
        if (! empty($a['vendor_company_id']) && ! empty($b['vendor_company_id'])) {
            return (int) $a['vendor_company_id'] === (int) $b['vendor_company_id'];
        }
        return mb_convert_case(trim((string) $a['vendor_snapshot']), MB_CASE_FOLD, 'UTF-8') === mb_convert_case(trim((string) $b['vendor_snapshot']), MB_CASE_FOLD, 'UTF-8');
    }

    public function validateRelations(array $rows, array $row): void
    {
        if ($row['kind_code'] === 'invoice') {
            if ($row['related_cost_entry_id'] !== null) {
                throw new InvalidArgumentException('Invoice cannot reference a parent cost fact.');
            }
            Costs::head($rows, (int) $row['id']);
            return;
        }
        if ($row['related_cost_entry_id'] === null) {
            if ($row['kind_code'] !== 'payment') {
                throw new InvalidArgumentException('Credit/refund requires its original parent.');
            }
            return;
        }
        $parent = Costs::head($rows, (int) $row['related_cost_entry_id'], $row['kind_code'] === 'payment');
        if ((int) $parent['id'] === (int) $row['id'] || $parent['kind_code'] !== ($row['kind_code'] === 'payment_refund' ? 'payment' : 'invoice') || $row['currency'] !== $parent['currency'] || ! self::sameVendor($row, $parent)) {
            throw new InvalidArgumentException('Related fact must have the matching parent kind, vendor and currency.');
        }
        if ($row['kind_code'] === 'payment') {
            return; // Informational invoice reference, no payment allocation or invoice cap.
        }
        if ($row['occurred_on'] < $parent['occurred_on']) {
            throw new InvalidArgumentException('Credit/refund date precedes its current parent.');
        }
        $this->remaining($rows, $parent);
    }

    private function remaining(array $rows, array $parent): string
    {
        $reductions = '0.00';
        $kind = $parent['kind_code'] === 'invoice' ? 'invoice_credit' : ($parent['kind_code'] === 'payment' ? 'payment_refund' : null);
        if ($kind === null) {
            return $parent['amount'];
        }
        foreach ($rows as $row) {
            if ($row['kind_code'] === $kind && $row['status_code'] === 'recorded' && (int) Costs::head($rows, (int) $row['related_cost_entry_id'])['id'] === (int) $parent['id']) {
                $reductions = RepairCostMoney::add($reductions, $row['amount']);
            }
        }
        return RepairCostMoney::subtract($parent['amount'], $reductions);
    }

    private function owned(array $rows, int $id): array
    {
        return array_column($rows, null, 'id')[$id] ?? throw new InvalidArgumentException('Cost entry not found in this work context.');
    }

    private static function confirmed(mixed $value): void
    {
        if ($value !== '1' && $value !== 1) {
            throw new InvalidArgumentException('Explicit confirmation is required.');
        }
    }

    private function fingerprint(string $actual, mixed $expected): void
    {
        if (! is_string($expected) || ! hash_equals($actual, $expected)) {
            throw new InvalidArgumentException('Cost, source or review state changed. Reload and review before saving.');
        }
    }

    /** Candidates are warnings only. Every query is scoped, bounded, and read-only. */
    public function duplicates(int $c, int $v, int $j, array $facts, ?string $checksum, int $except = 0): array
    {
        $candidates = [];
        $costs = $this->db->table(Costs::TABLE . ' e')->select('e.*, d.content_checksum')->join(Estimates::DOCUMENTS . ' d', 'd.id=e.repair_document_id AND d.company_id=e.company_id AND d.vehicle_damage_repair_job_id=e.vehicle_damage_repair_job_id')
            ->where('e.company_id', $c)->where('e.id !=', $except)->where('e.status_code', 'recorded')->groupStart()->groupStart()->where('e.amount', $facts['amount'])->where('e.occurred_on', $facts['occurred_on'])->groupEnd();
        if ($checksum !== null) {
            $costs->orWhere('d.content_checksum', $checksum);
        }
        if (! empty($facts['vendor_reference'])) {
            $costs->orWhere('e.vendor_reference', $facts['vendor_reference']);
        }
        foreach ($costs->groupEnd()->orderBy('e.id')->limit(Policy::MAX_DUPLICATES)->get()->getResultArray() as $row) {
            $candidates[] = ['domain' => 'repair_cost', 'id' => (int) $row['id'], 'job_id' => (int) $row['vehicle_damage_repair_job_id'], 'amount' => $row['amount'], 'occurred_on' => $row['occurred_on'], 'vendor' => $row['vendor_snapshot'], 'reference' => $row['vendor_reference'], 'checksum' => $row['content_checksum']];
        }
        $expenses = $this->db->table('operating_expenses')->where('company_id', $c)->where('status_code', 'recorded')->groupStart()->groupStart()->where('amount', $facts['amount'])->where('expense_date', $facts['occurred_on'])->groupEnd();
        if (! empty($facts['vendor_reference'])) {
            $expenses->orWhere('payment_reference', $facts['vendor_reference']);
        }
        foreach ($expenses->groupEnd()->orderBy('id')->limit(Policy::MAX_DUPLICATES)->get()->getResultArray() as $row) {
            $candidates[] = ['domain' => 'operating_expense', 'id' => (int) $row['id'], 'amount' => $row['amount'], 'occurred_on' => $row['expense_date'], 'vendor' => $row['vendor'], 'reference' => $row['payment_reference']];
        }
        $receipts = $this->db->table('operating_expense_receipts r')->select('r.*, f.checksum')->join('files f', 'f.id=r.file_id')->where('r.company_id', $c)->groupStart()->groupStart()->where('r.observed_amount', $facts['amount'])->where('r.document_date', $facts['occurred_on'])->groupEnd();
        if ($checksum !== null) {
            $receipts->orWhere('f.checksum', $checksum);
        }
        foreach ($receipts->groupEnd()->orderBy('r.id')->limit(Policy::MAX_DUPLICATES)->get()->getResultArray() as $row) {
            $candidates[] = ['domain' => 'expense_receipt', 'id' => (int) $row['id'], 'amount' => $row['observed_amount'], 'occurred_on' => $row['document_date'], 'vendor' => $row['vendor'], 'checksum' => $row['checksum']];
        }
        foreach ($this->db->table('maintenance_logs m')->select('m.*')->join('fleet_vehicles v', 'v.id=m.fleet_vehicle_id')->where('v.company_id', $c)->where('m.deleted_at', null)->where('m.total_amount', $facts['amount'])->where('m.service_on', $facts['occurred_on'])->orderBy('m.id')->limit(Policy::MAX_DUPLICATES)->get()->getResultArray() as $row) {
            $candidates[] = ['domain' => 'maintenance', 'id' => (int) $row['id'], 'amount' => $row['total_amount'], 'occurred_on' => $row['service_on'], 'vendor_id' => $row['vendor_company_id'] ?? null];
        }
        return ['candidates' => $candidates, 'fingerprint' => Estimates::digest($candidates)];
    }
}
