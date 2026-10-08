<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRecoveryRepository as Recoveries;
use App\Repositories\VehicleDamageRepairRepository;
use CodeIgniter\Database\BaseConnection;
use Config\VehicleDamageRepairRecoveries as Policy;
use DateTimeImmutable;
use InvalidArgumentException;

/** The existing aggregate runner owns the transaction, binary lifetime, event, audits and version. */
class VehicleDamageRepairRecoveryService
{
    public readonly Recoveries $recoveries;
    public readonly VehicleDamageRepairEstimateService $sources;
    private VehicleDamageRepairRepository $work;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->recoveries = new Recoveries($db);
        $this->sources = new VehicleDamageRepairEstimateService($db);
        $this->work = new VehicleDamageRepairRepository($db);
    }

    public function normalize(array $data, int $c, int $v, int $j): array
    {
        foreach (['expected_version', 'repair_document_id', 'recovery_entry_id', 'related_recovery_entry_id', 'damage_claim_id', 'turo_transaction_normalized_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== '' && ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1)) {
                throw new InvalidArgumentException('Recovery identities and versions must be positive scalar integers.');
            }
        }
        if (isset($data['document'])) {
            if (! is_array($data['document']) || array_diff(array_keys($data['document']), ['kind_code', 'label', 'note', 'descriptor', 'upload']) !== []) {
                throw new InvalidArgumentException('Choose a new binary or an existing same-job repair document.');
            }
            foreach (['kind_code', 'label', 'note'] as $field) {
                if (isset($data['document'][$field]) && ! is_string($data['document'][$field])) {
                    throw new InvalidArgumentException('Recovery document fields must contain text.');
                }
            }
            if (array_key_exists('descriptor', $data['document']) && (! is_array($data['document']['descriptor'])
                || array_any($data['document']['descriptor'], static fn (mixed $value): bool => ! is_string($value) && ! is_int($value))
                || (isset($data['document']['descriptor']['checksum']) && ! is_string($data['document']['descriptor']['checksum'])))) {
                throw new InvalidArgumentException('Choose a valid recovery upload descriptor.');
            }
            if (isset($data['document']['descriptor']['size_bytes']) && is_string($data['document']['descriptor']['size_bytes']) && ctype_digit($data['document']['descriptor']['size_bytes'])) {
                $data['document']['descriptor']['size_bytes'] = (int) $data['document']['descriptor']['size_bytes'];
            }
            $data['document'] = $this->sources->documents->normalizeSource($data['document'], $c, $v);
        }
        foreach (self::semantic($data) as $field => $value) {
            if ($field !== 'document' && is_array($value)) {
                throw new InvalidArgumentException('Recovery facts must contain scalar values.');
            }
        }
        return $data;
    }

    /** An independent namespace keeps every accepted B2 command identity unchanged. */
    public static function semantic(array $data): array
    {
        $fields = explode(' ', 'expected_version kind_code authority_code source_type amount currency occurred_on payer_snapshot source_namespace source_reference source_details damage_claim_id claim_context_confirmed repair_document_id expected_document_state related_recovery_entry_id recovery_entry_id expected_entry_state confirmed received_confirmed returned_confirmed whole_job_confirmed outside_turo_confirmed not_cost_reduction_confirmed not_duplicate_confirmed note reason expected_ledger_state expected_finalization_state completeness_confirmed no_recovery_confirmed scope_review_confirmed duplicate_review_fingerprint duplicate_review_confirmed duplicate_review_reason turo_transaction_normalized_id expected_source_state document');
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
                throw new InvalidArgumentException('Recovery fields must contain scalar values.');
            }
            return $value === null ? null : trim((string) $value);
        };
        return $normalize($data);
    }

    /** Claims precede recovery entries and file/image metadata. Turo phases stay unavailable. */
    public function lockClaim(int $c, int $v, int $j, array $data): void
    {
        if (empty($data['damage_claim_id'])) {
            return;
        }
        $sql = $this->db->table('damage_claims')->where('id', (int) $data['damage_claim_id'])->where('fleet_vehicle_id', $v)->where('deleted_at', null)->orderBy('id')->getCompiledSelect();
        $claim = $this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'))->getRowArray();
        if ($claim === null || (int) $claim['fleet_vehicle_id'] !== $v || $claim['deleted_at'] !== null || $this->work->job($c, $v, $j) === null) {
            throw new InvalidArgumentException('Claim is not owned by this vehicle/job context.');
        }
        $context = $this->db->table('vehicle_damage_repair_job_items m')->join('vehicle_damage_items d', 'd.id=m.vehicle_damage_item_id AND d.company_id=m.company_id')
            ->where('m.company_id', $c)->where('m.vehicle_damage_repair_job_id', $j)->where('d.damage_claim_id', (int) $claim['id'])->limit(1)->get()->getRowArray();
        if ($context === null) {
            throw new InvalidArgumentException('Claim association needs an explicit retained damage-condition relationship, not vehicle/date inference.');
        }
        self::confirmed($data['claim_context_confirmed'] ?? null);
    }

    public function claimOptions(int $c, int $v, int $j): array
    {
        if ($this->work->job($c, $v, $j) === null) {
            throw new InvalidArgumentException('Repair job not found in this context.');
        }
        return $this->db->table('damage_claims cl')->distinct()->select('cl.id,cl.claim_number')
            ->join('vehicle_damage_items d', 'd.damage_claim_id=cl.id')->join('vehicle_damage_repair_job_items m', 'm.vehicle_damage_item_id=d.id AND m.company_id=d.company_id')
            ->where('m.company_id', $c)->where('m.vehicle_damage_repair_job_id', $j)->where('cl.fleet_vehicle_id', $v)->where('cl.deleted_at', null)->orderBy('cl.id')->limit(Policy::MAX_ENTRIES)->get()->getResultArray();
    }

    public function snapshot(int $c, int $v, int $j, bool $allowInvalidTotals = false): array
    {
        $rows = $this->recoveries->entries($c, $v, $j);
        try {
            return ['entries' => $rows, 'totals' => Recoveries::totals($rows)];
        } catch (InvalidArgumentException $exception) {
            if (! $allowInvalidTotals) {
                throw $exception;
            }
            // Standalone invalidation retains corrupt history without asserting an economic total.
            return ['entries' => $rows, 'totals' => ['net' => null, 'gross' => null], 'source_review_required' => true];
        }
    }

    public function entryFingerprint(int $c, int $v, int $j, int $id): string
    {
        $rows = $this->recoveries->entries($c, $v, $j);
        $row = $this->owned($rows, $id);
        Recoveries::head($rows, $id, true);
        return Estimates::digest([$row, $rows, $this->sources->documents->documents->fingerprint($c, $v, $j, (int) $row['repair_document_id'])]);
    }

    /** Durable content state deliberately excludes job version and unrelated job events. */
    public function ledger(int $c, int $v, int $j): array
    {
        $rows = $this->recoveries->entries($c, $v, $j);
        $frozen = [];
        foreach ($rows as $row) {
            Recoveries::head($rows, (int) $row['id'], true);
            if ($row['status_code'] !== 'recorded') {
                continue;
            }
            $this->validateRelations($rows, $row);
            $this->verifyDocument($c, $v, $j, (int) $row['repair_document_id'], $row['kind_code']);
            if (! hash_equals(self::sourceIdentity($row['source_namespace'], $row['source_reference']), $row['source_identity_key'])
                || RepairEstimateMoney::normalize($row['amount']) !== $row['amount'] || $row['amount'] === '0.00') {
                throw new InvalidArgumentException('Recovery source identity or amount needs review.');
            }
            $snapshot = json_decode($row['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['authority_version'] ?? null) !== 'b31-external-v1' || ! array_all(['money_confirmed', 'whole_job_confirmed', 'outside_turo_confirmed', 'not_cost_reduction_confirmed', 'not_duplicate_confirmed'], fn (string $field): bool => ($snapshot[$field] ?? null) === true)) {
                throw new InvalidArgumentException('Recovery scope/source confirmation needs review.');
            }
            $validated = $this->facts(array_intersect_key($row, array_flip(explode(' ', 'kind_code authority_code source_type amount currency occurred_on payer_snapshot source_namespace source_reference damage_claim_id related_recovery_entry_id note'))) + [
                'source_details' => $snapshot['details'] ?? null, 'received_confirmed' => '1', 'returned_confirmed' => '1', 'whole_job_confirmed' => '1', 'outside_turo_confirmed' => '1', 'not_cost_reduction_confirmed' => '1', 'not_duplicate_confirmed' => '1',
            ]);
            foreach (['payer' => 'payer_snapshot', 'source_type' => 'source_type', 'namespace' => 'source_namespace', 'reference' => 'source_reference'] as $snapshotField => $field) {
                if (($snapshot[$snapshotField] ?? null) !== $validated[$field]) {
                    throw new InvalidArgumentException('Recovery provenance does not match its immutable monetary facts.');
                }
            }
            if ($row['damage_claim_id'] !== null && (($snapshot['claim_context_confirmed'] ?? null) !== true || ! array_any($this->claimOptions($c, $v, $j), fn (array $claim): bool => (int) $claim['id'] === (int) $row['damage_claim_id']))) {
                throw new InvalidArgumentException('Recovery claim context needs review.');
            }
            $docState = $this->sources->documents->documents->fingerprint($c, $v, $j, (int) $row['repair_document_id']);
            $frozen[] = ['id' => (int) $row['id'], 'fingerprint' => $this->entryFingerprint($c, $v, $j, (int) $row['id']), 'document_fingerprint' => $docState];
        }
        return ['entries' => $frozen, 'net' => Recoveries::totals($rows)['net'] ?? '0.00', 'currency' => 'USD'];
    }

    public function ledgerFingerprint(int $c, int $v, int $j): string
    {
        return Estimates::digest($this->ledger($c, $v, $j));
    }

    public function finalizationFingerprint(int $c, int $v, int $j): string
    {
        $job = $this->work->job($c, $v, $j);
        $rows = $this->recoveries->entries($c, $v, $j);
        $sources = [];
        foreach ($rows as $row) {
            try {
                $sources[] = $this->sources->documents->documents->fingerprint($c, $v, $j, (int) $row['repair_document_id']);
            } catch (\Throwable) {
                $sources[] = 'unavailable';
            }
        }
        // An operator must still be able to invalidate completeness when evidence/lineage is stale.
        return Estimates::digest([$rows, $sources, $job['recovery_finalized_at'], $job['recovery_finalized_by'], $job['recovery_finalization_note']]);
    }

    public static function clearFinalization(array $job): array
    {
        foreach (['recovery_finalized_at', 'recovery_finalized_by', 'recovery_finalization_note'] as $field) {
            $job[$field] = null;
        }
        return $job;
    }

    public function apply(string $action, int $c, int $v, array $job, array $data, int $actor, string $now): array
    {
        $j = (int) $job['id'];
        $rows = $this->recoveries->entries($c, $v, $j);
        self::confirmed($data['confirmed'] ?? null);
        if ($action === 'b31_finalize') {
            if ($job['recovery_finalized_at'] !== null) {
                throw new InvalidArgumentException('Recovery is already finalized.');
            }
            $ledger = $this->ledger($c, $v, $j);
            $this->fingerprint(Estimates::digest($ledger), $data['expected_ledger_state'] ?? null);
            self::confirmed($data['completeness_confirmed'] ?? null);
            if ($ledger['entries'] === []) {
                self::confirmed($data['no_recovery_confirmed'] ?? null);
            }
            $review = $this->ledgerDuplicates($c, $v, $j);
            $this->review($review, $data);
            $cost = Costs::totals((new Costs($this->db))->entries($c, $v, $j))['invoiced'];
            if ($cost !== null && RepairCostMoney::compare($ledger['net'], $cost) > 0) {
                self::confirmed($data['scope_review_confirmed'] ?? null);
            }
            $job['recovery_finalized_at'] = $now;
            $job['recovery_finalized_by'] = $actor;
            $job['recovery_finalization_note'] = VehicleDamageRepairDocumentService::text($data['note'] ?? null, 2000, true);
            return [$job, 'repair_recovery_finalized', ['finalization' => $ledger + ['ledger_fingerprint' => Estimates::digest($ledger), 'duplicate_review' => $review, 'actor' => $actor, 'recorded_at' => $now, 'note' => $job['recovery_finalization_note'], 'scope_review_confirmed' => in_array($data['scope_review_confirmed'] ?? null, ['1', 1], true), 'no_recovery_confirmed' => in_array($data['no_recovery_confirmed'] ?? null, ['1', 1], true), 'completeness_confirmed' => true]]];
        }
        if ($action === 'b31_invalidate') {
            if ($job['recovery_finalized_at'] === null) {
                throw new InvalidArgumentException('Recovery is not finalized.');
            }
            $this->fingerprint($this->finalizationFingerprint($c, $v, $j), $data['expected_finalization_state'] ?? null);
            VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
            return [self::clearFinalization($job), 'repair_recovery_finalization_invalidated', []];
        }
        $old = null;
        if (in_array($action, ['b31_void', 'b31_replace'], true)) {
            $old = $this->owned($rows, (int) ($data['recovery_entry_id'] ?? 0));
            if ($old['status_code'] !== 'recorded' || (int) Recoveries::head($rows, (int) $old['id'])['id'] !== (int) $old['id']) {
                throw new InvalidArgumentException('Correction requires the current recorded recovery head.');
            }
            $this->fingerprint($this->entryFingerprint($c, $v, $j, (int) $old['id']), $data['expected_entry_state'] ?? null);
            $reason = VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
            if ($action === 'b31_void') {
                foreach ($rows as $dependent) {
                    if ($dependent['status_code'] === 'recorded' && $dependent['kind_code'] === 'recovery_reversal' && (int) Recoveries::head($rows, (int) $dependent['related_recovery_entry_id'])['id'] === (int) $old['id']) {
                        throw new InvalidArgumentException('Correct active reversals before voiding their receipt.');
                    }
                }
                $this->recoveries->void($c, $j, (int) $old['id'], $actor, $reason, $now);
                return [self::clearFinalization($job), 'repair_recovery_voided', ['recovery_entry_id' => (int) $old['id']]];
            }
        } elseif ($action !== 'b31_record') {
            throw new InvalidArgumentException('Unsupported recovery command.');
        }
        $facts = $this->facts($data);
        if ($old !== null) {
            foreach (['kind_code', 'source_type', 'source_identity_key', 'authority_code', 'related_recovery_entry_id'] as $field) {
                if ((string) ($facts[$field] ?? '') !== (string) ($old[$field] ?? '')) {
                    throw new InvalidArgumentException('Replacement must retain kind, source identity, authority and original receipt.');
                }
            }
            $rows = array_map(function (array $row) use ($old): array {
                if ((int) $row['id'] === (int) $old['id']) {
                    $row['status_code'] = 'voided';
                }
                return $row;
            }, $rows);
        } elseif ($this->db->table(Recoveries::TABLE)->where('company_id', $c)->where('source_root_key', $facts['source_identity_key'])->limit(1)->get()->getRowArray() !== null) {
            throw new InvalidArgumentException('This source identity is permanently reserved by another recovery lineage.');
        }
        $facts['source_root_key'] = $old === null ? $facts['source_identity_key'] : null;
        $candidate = $facts + ['id' => max([0, ...array_map('intval', array_column($rows, 'id'))]) + 1, 'company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'replacement_of_recovery_entry_id' => $old['id'] ?? null, 'status_code' => 'recorded'];
        $projected = [...$rows, $candidate];
        foreach ($projected as $row) {
            Recoveries::head($projected, (int) $row['id'], true);
            if ($row['status_code'] === 'recorded') {
                $this->validateRelations($projected, $row);
            }
        }
        $docData = $data['document'] ?? [];
        if (! empty($data['repair_document_id'])) {
            if ($docData !== []) {
                throw new InvalidArgumentException('Choose exactly one existing document or new upload.');
            }
            $doc = $this->verifyDocument($c, $v, $j, (int) $data['repair_document_id'], $facts['kind_code']);
            $this->fingerprint($this->sources->documents->documents->fingerprint($c, $v, $j, (int) $doc['id']), $data['expected_document_state'] ?? null);
            if ($old !== null && (int) $doc['id'] === (int) $old['repair_document_id'] && array_all(['amount', 'occurred_on', 'payer_snapshot', 'damage_claim_id', 'note'], fn (string $f): bool => (string) ($facts[$f] ?? '') === (string) ($old[$f] ?? ''))
                && json_decode($facts['source_snapshot'], true, 512, JSON_THROW_ON_ERROR)['details'] === json_decode($old['source_snapshot'], true, 512, JSON_THROW_ON_ERROR)['details']) {
                throw new InvalidArgumentException('No change to replace.');
            }
        } else {
            if (($docData['kind_code'] ?? null) !== Policy::DOCUMENT_KINDS[$facts['kind_code']] || ! ($docData['upload'] ?? null) instanceof \CodeIgniter\HTTP\Files\UploadedFile || isset($docData['external_reference']) || isset($docData['file_id']) || isset($docData['image_id'])) {
                throw new InvalidArgumentException('A matching verified private binary is required.');
            }
            $doc = null;
        }
        $duplicates = $this->duplicates($c, $facts, $doc['content_checksum'] ?? $docData['descriptor']['checksum'] ?? null, (int) ($old['id'] ?? 0));
        $this->review($duplicates, $data);
        $snapshot = json_decode($facts['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $snapshot['duplicate_review'] = $duplicates;
        $snapshot['duplicate_review_reason'] = VehicleDamageRepairDocumentService::text($data['duplicate_review_reason'] ?? null, 2000);
        $facts['source_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $doc ??= $this->sources->documents->attach($c, $v, $j, $docData, $actor, $now);
        $this->verifyDocument($c, $v, $j, (int) $doc['id'], $facts['kind_code']);
        if ($old !== null) {
            $this->recoveries->void($c, $j, (int) $old['id'], $actor, $reason, $now);
        }
        $id = $this->recoveries->insert($facts + ['company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'repair_document_id' => (int) $doc['id'], 'replacement_of_recovery_entry_id' => $old['id'] ?? null, 'status_code' => 'recorded', 'created_by' => $actor, 'created_at' => $now, 'voided_at' => null, 'voided_by' => null, 'void_reason' => null]);
        return [self::clearFinalization($job), $old !== null ? 'repair_recovery_replaced' : ($facts['kind_code'] === 'recovery' ? 'repair_recovery_recorded' : 'repair_recovery_reversed'), ['recovery_entry_id' => $id, 'document_id' => (int) $doc['id'], 'duplicate_review' => $duplicates]];
    }

    public function verifyDocument(int $c, int $v, int $j, int $id, string $kind): array
    {
        $doc = $this->sources->documents->documents->document($c, $v, $j, $id);
        if ($doc === null || $doc['kind_code'] !== Policy::DOCUMENT_KINDS[$kind] || $doc['archived_at'] !== null || $doc['external_reference'] !== null
            || ((int) ($doc['file_id'] !== null) + (int) ($doc['image_id'] !== null)) !== 1
            || $this->sources->documents->storage->resolve($c, $doc, $this->sources->documents->documents->metadata($doc)) === null) {
            throw new InvalidArgumentException('Recovery evidence must be active, matching, same-job verified private binary content.');
        }
        return $doc;
    }

    public function facts(array $data): array
    {
        if (($data['authority_code'] ?? '') === 'turo_transaction' || ($data['source_type'] ?? '') === 'turo_reimbursement' || ! empty($data['turo_transaction_normalized_id'])) {
            VehicleDamageRepairRecoveryTuroSource::recognize([], []);
        }
        if (($data['authority_code'] ?? '') !== 'external_receipt' || ! is_string($data['source_type'] ?? null) || ! isset(Policy::SOURCES[$data['source_type']]) || ! is_string($data['kind_code'] ?? null) || ! isset(Policy::KINDS[$data['kind_code']])) {
            throw new InvalidArgumentException('Choose an external receipt source and supported recovery kind.');
        }
        foreach (['file_id', 'image_id', 'external_reference', 'turo_transaction_raw_id', 'turo_source_root_id', 'source_identity_key', 'source_root_key', 'recognized_source_fingerprint', 'source_snapshot'] as $raw) {
            if (isset($data[$raw])) {
                throw new InvalidArgumentException('Raw source IDs or caller-generated authority cannot authorize a recovery.');
            }
        }
        $amount = RepairEstimateMoney::normalize($data['amount'] ?? null);
        if ($amount === '0.00' || ($data['currency'] ?? '') !== 'USD') {
            throw new InvalidArgumentException('External recovery requires a positive canonical USD amount.');
        }
        $date = VehicleDamageRepairDocumentService::text($data['occurred_on'] ?? null, 10, true);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            throw new InvalidArgumentException('Actual receipt/reversal date must be a valid nonfuture YYYY-MM-DD date.');
        }
        foreach (['whole_job_confirmed', 'outside_turo_confirmed', 'not_cost_reduction_confirmed', 'not_duplicate_confirmed', $data['kind_code'] === 'recovery' ? 'received_confirmed' : 'returned_confirmed'] as $confirmation) {
            self::confirmed($data[$confirmation] ?? null);
        }
        $payer = VehicleDamageRepairDocumentService::text($data['payer_snapshot'] ?? null, 190, true);
        $namespace = VehicleDamageRepairDocumentService::text($data['source_namespace'] ?? null, 120, true);
        $reference = VehicleDamageRepairDocumentService::text($data['source_reference'] ?? null, 120, true);
        $details = VehicleDamageRepairDocumentService::text($data['source_details'] ?? null, 2000, true);
        if (! preg_match('/^(bank_transfer|check|cash_receipt|payment_processor):[a-z0-9][a-z0-9_-]{1,79}$/D', $namespace) || preg_match('/turo/i', $namespace . ' ' . $payer)) {
            throw new InvalidArgumentException('Use an identified external payment namespace and payer. Turo receipts cannot be disguised as external recovery.');
        }
        return ['kind_code' => $data['kind_code'], 'authority_code' => 'external_receipt', 'source_type' => $data['source_type'], 'amount' => $amount, 'currency' => 'USD', 'occurred_on' => $date,
            'payer_snapshot' => $payer, 'source_namespace' => $namespace, 'source_reference' => $reference, 'source_identity_key' => self::sourceIdentity($namespace, $reference),
            'turo_transaction_normalized_id' => null, 'turo_transaction_raw_id' => null, 'turo_source_root_id' => null, 'recognized_source_fingerprint' => null,
            'damage_claim_id' => (int) ($data['damage_claim_id'] ?? 0) ?: null, 'related_recovery_entry_id' => (int) ($data['related_recovery_entry_id'] ?? 0) ?: null,
            'note' => VehicleDamageRepairDocumentService::text($data['note'] ?? null, 2000),
            'source_snapshot' => json_encode(['authority_version' => 'b31-external-v1', 'payer' => $payer, 'source_type' => $data['source_type'], 'namespace' => $namespace, 'reference' => $reference, 'details' => $details,
                'money_confirmed' => true, 'whole_job_confirmed' => true, 'outside_turo_confirmed' => true, 'not_cost_reduction_confirmed' => true, 'not_duplicate_confirmed' => true, 'claim_context_confirmed' => in_array($data['claim_context_confirmed'] ?? null, ['1', 1], true)], JSON_THROW_ON_ERROR)];
    }

    public static function sourceIdentity(string $namespace, string $reference): string
    {
        return Estimates::digest(['b31-receipt-identity-v1', $namespace, $reference]);
    }

    public function validateRelations(array $rows, array $row): void
    {
        if ($row['authority_code'] !== 'external_receipt') {
            VehicleDamageRepairRecoveryTuroSource::recognize([], []);
        }
        Recoveries::head($rows, (int) $row['id']);
        if ($row['kind_code'] === 'recovery') {
            if ($row['related_recovery_entry_id'] !== null) {
                throw new InvalidArgumentException('Recovery receipt cannot reference another receipt.');
            }
            return;
        }
        $parent = Recoveries::head($rows, (int) ($row['related_recovery_entry_id'] ?? 0));
        if ($parent['kind_code'] !== 'recovery' || $parent['currency'] !== $row['currency'] || $parent['source_type'] !== $row['source_type']
            || $parent['source_namespace'] !== $row['source_namespace'] || mb_convert_case($parent['payer_snapshot'], MB_CASE_FOLD) !== mb_convert_case($row['payer_snapshot'], MB_CASE_FOLD)
            || $row['occurred_on'] < $parent['occurred_on']) {
            throw new InvalidArgumentException('Reversal must have compatible payer/source/currency and follow its owned receipt.');
        }
        $sum = '0.00';
        foreach ($rows as $reversal) {
            if ($reversal['status_code'] === 'recorded' && $reversal['kind_code'] === 'recovery_reversal' && (int) Recoveries::head($rows, (int) $reversal['related_recovery_entry_id'])['id'] === (int) $parent['id']) {
                $sum = RepairCostMoney::add($sum, $reversal['amount']);
            }
        }
        if (RepairCostMoney::compare($sum, $parent['amount']) > 0) {
            throw new InvalidArgumentException('Active reversals exceed the receipt amount.');
        }
    }

    /** Read-only, bounded candidate warnings. Root identity is separately enforced by a database unique key. */
    public function duplicates(int $c, array $facts, ?string $checksum, int $except = 0): array
    {
        $query = $this->db->table(Recoveries::TABLE . ' e')->select('e.id,e.vehicle_damage_repair_job_id,e.kind_code,e.amount,e.occurred_on,e.payer_snapshot,e.source_reference,e.source_identity_key,d.content_checksum')
            ->join(Estimates::DOCUMENTS . ' d', 'd.id=e.repair_document_id AND d.company_id=e.company_id AND d.vehicle_damage_repair_job_id=e.vehicle_damage_repair_job_id')
            ->where('e.company_id', $c)->where('e.status_code', 'recorded')->where('e.kind_code', $facts['kind_code'])->where('e.id !=', $except)->where('e.source_identity_key !=', $facts['source_identity_key'])
            ->groupStart()->groupStart()->where('e.amount', $facts['amount'])->where('e.occurred_on', $facts['occurred_on'])->groupEnd()->orWhere('e.source_reference', $facts['source_reference'])->orWhere('e.payer_snapshot', $facts['payer_snapshot']);
        if ($checksum !== null) {
            $query->orWhere('d.content_checksum', $checksum);
        }
        if (! empty($facts['damage_claim_id'])) {
            $query->orWhere('e.damage_claim_id', $facts['damage_claim_id']);
        }
        if (mb_strlen($facts['source_reference']) >= 6) {
            $query->orLike('e.source_reference', $facts['source_reference'], 'both');
        }
        $candidates = $query->groupEnd()->orderBy('e.id')->limit(Policy::MAX_DUPLICATES + 1)->get()->getResultArray();
        if (count($candidates) > Policy::MAX_DUPLICATES) {
            throw new InvalidArgumentException('Too many possible recovery duplicates for bounded review.');
        }
        return ['candidates' => $candidates, 'fingerprint' => Estimates::digest($candidates)];
    }

    public function ledgerDuplicates(int $c, int $v, int $j): array
    {
        $reviews = [];
        foreach ($this->recoveries->entries($c, $v, $j) as $row) {
            if ($row['status_code'] === 'recorded') {
                $doc = $this->sources->documents->documents->document($c, $v, $j, (int) $row['repair_document_id']);
                $review = $this->duplicates($c, $row, $doc['content_checksum'] ?? null, (int) $row['id']);
                if ($review['candidates'] !== []) {
                    $reviews[] = ['entry_id' => (int) $row['id'], 'review' => $review];
                }
            }
        }
        return ['candidates' => $reviews, 'fingerprint' => Estimates::digest($reviews)];
    }

    private function review(array $review, array $data): void
    {
        if ($review['candidates'] !== []) {
            $this->fingerprint($review['fingerprint'], $data['duplicate_review_fingerprint'] ?? null);
            self::confirmed($data['duplicate_review_confirmed'] ?? null);
            VehicleDamageRepairDocumentService::text($data['duplicate_review_reason'] ?? null, 2000, true);
        }
    }

    private function owned(array $rows, int $id): array
    {
        return array_column($rows, null, 'id')[$id] ?? throw new InvalidArgumentException('Recovery entry not found in this job.');
    }

    private static function confirmed(mixed $value): void
    {
        if ($value !== '1' && $value !== 1) {
            throw new InvalidArgumentException('Explicit recovery confirmation is required.');
        }
    }

    private function fingerprint(string $actual, mixed $expected): void
    {
        if (! is_string($expected) || ! hash_equals($actual, $expected)) {
            throw new InvalidArgumentException('Recovery, evidence or review state changed. Reload and review before saving.');
        }
    }
}
