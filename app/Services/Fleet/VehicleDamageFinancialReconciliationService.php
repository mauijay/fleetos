<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRepository;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Commands run exclusively inside the repair aggregate; reads never repair history. */
class VehicleDamageFinancialReconciliationService
{
    public const EVENTS = ['repair_financial_reconciliation_created', 'repair_financial_reconciliation_invalidated', 'repair_financial_reconciliation_replaced'];
    public readonly Records $records;
    public readonly VehicleDamageRepairCostService $cost;
    public readonly VehicleDamageRepairEstimateService $sources;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->records = new Records($db);
        $this->cost = new VehicleDamageRepairCostService($db);
        $this->sources = $this->cost->sources;
    }

    public static function semantic(array $data): array
    {
        $allowed = explode(' ', 'expected_version cost_root_entry_id operating_expense_id reconciliation_id expected_damage_state expected_expense_state expected_reconciliation_state amount currency confirmed whole_fact_confirmed no_other_job_confirmed no_other_expense_confirmed not_partial_confirmed descriptor_review_confirmed descriptor_review_reason reason date_difference_reason');
        $result = [];
        foreach (array_intersect_key($data, array_flip($allowed)) as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Reconciliation fields must be scalar.');
            }
            $result[$key] = $value === null ? null : trim((string) $value);
        }
        if (isset($result['amount'])) {
            $result['amount'] = RepairEstimateMoney::normalize($result['amount']);
        }
        ksort($result);
        return $result;
    }

    public function normalize(array $data): array
    {
        self::semantic($data);
        foreach (['expected_version', 'cost_root_entry_id', 'operating_expense_id', 'reconciliation_id'] as $field) {
            if (isset($data[$field]) && ((! is_int($data[$field]) && (! is_string($data[$field]) || ! ctype_digit($data[$field]))) || (int) $data[$field] < 1)) {
                throw new InvalidArgumentException('Reconciliation identities must be positive integers.');
            }
        }
        return $data;
    }

    /** Validate after replay, under vehicle/job locks and before lower source phases. */
    public function assertExpenseVehicle(int $c, int $v, array $data): void
    {
        $id = (int) ($data['operating_expense_id'] ?? 0);
        $row = $this->db->table('operating_expenses')->select('id,fleet_vehicle_id')->where(['company_id' => $c, 'id' => $id])->get()->getRowArray();
        if ($row === null || (int) ($row['fleet_vehicle_id'] ?? 0) !== $v) {
            throw new InvalidArgumentException('Choose an owned expense explicitly assigned to this repair vehicle.');
        }
    }

    public static function fingerprint(array $snapshot): string
    {
        return Estimates::digest(['b32a:1', $snapshot]);
    }

    public static function state(array $row): string
    {
        return self::fingerprint($row);
    }

    /** Bulk source load: one job/entry/document/files/images query, independent of family count. */
    public function families(int $c, int $v, int $j): array
    {
        $rows = $this->cost->costs->entries($c, $v, $j);
        // The command-side document repository deliberately uses locking reads.
        // This shared read projection must remain a nonlocking MVCC GET.
        $docs = array_column($this->db->table(Estimates::DOCUMENTS)->where(['company_id' => $c, 'vehicle_damage_repair_job_id' => $j])->orderBy('id')->limit(1001)->get()->getResultArray(), null, 'id');
        if (count($docs) > 1000) {
            throw new RuntimeException('Repair evidence exceeds the supported workspace bound.');
        }
        $metadata = [];
        foreach (['files' => 'file_id', 'images' => 'image_id'] as $table => $field) {
            $ids = array_filter(array_column($docs, $field));
            $metadata[$field] = $ids === [] ? [] : array_column($this->db->table($table)->whereIn('id', $ids)->get()->getResultArray(), null, 'id');
        }
        $result = [];
        foreach ($rows as $root) {
            if ($root['kind_code'] !== 'invoice' || $root['replacement_of_cost_entry_id'] !== null) {
                continue;
            }
            try {
                $head = Costs::head($rows, (int) $root['id']);
                $family = [];
                $net = $head['amount'];
                foreach ($rows as $entry) {
                    if (! in_array($entry['kind_code'], ['invoice', 'invoice_credit'], true)) {
                        continue;
                    }
                    $current = Costs::head($rows, (int) $entry['id'], true);
                    $belongs = $entry['kind_code'] === 'invoice' ? (int) $current['id'] === (int) $head['id']
                        : (int) Costs::head($rows, (int) $entry['related_cost_entry_id'], true)['id'] === (int) $head['id'];
                    if (! $belongs) {
                        continue;
                    }
                    $doc = $docs[$entry['repair_document_id']] ?? throw new InvalidArgumentException('Invoice evidence is missing.');
                    $meta = $metadata[$doc['file_id'] !== null ? 'file_id' : 'image_id'][$doc['file_id'] ?? $doc['image_id']] ?? null;
                    $valid = $this->sources->documents->storage->resolve($c, $doc, $meta) !== null;
                    if ($entry['status_code'] === 'recorded') {
                        if (! $valid || $doc['archived_at'] !== null || $doc['kind_code'] !== \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$entry['kind_code']]) {
                            throw new InvalidArgumentException('Current invoice evidence needs review.');
                        }
                        $this->cost->validateRelations($rows, $entry);
                        if ($entry['kind_code'] === 'invoice_credit') {
                            $net = RepairCostMoney::subtract($net, $entry['amount']);
                        }
                    }
                    $family[] = self::pick($entry, 'id kind_code amount currency occurred_on vendor_company_id vendor_snapshot vendor_reference related_cost_entry_id replacement_of_cost_entry_id status_code repair_document_id') + [
                        'evidence' => self::pick($doc, 'id kind_code file_id image_id content_checksum archived_at') + ['metadata' => $meta === null ? null : self::pick($meta, 'id checksum mime_type size_bytes deleted_at'), 'valid' => $valid],
                    ];
                }
                if (RepairCostMoney::compare($net, '0.00') <= 0 || $head['currency'] !== 'USD') {
                    throw new InvalidArgumentException('A positive current USD invoice family is required.');
                }
                $snapshot = ['company_id' => $c, 'fleet_vehicle_id' => $v, 'job_id' => $j, 'root_id' => (int) $root['id'], 'head_id' => (int) $head['id'], 'entries' => $family, 'amount' => $net, 'currency' => 'USD', 'occurred_on' => $head['occurred_on'], 'vendor' => $head['vendor_snapshot'], 'reference' => $head['vendor_reference']];
                self::json($snapshot);
                $result[$root['id']] = ['snapshot' => $snapshot, 'fingerprint' => self::fingerprint($snapshot), 'issue' => null];
            } catch (Throwable) {
                $result[$root['id']] = ['snapshot' => null, 'fingerprint' => null, 'issue' => 'Current invoice family or its evidence needs review.'];
            }
        }
        return $result;
    }

    /** Frozen currency basis comes from the USD reporting contract, not an expense currency column. */
    public function expenses(int $c, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->table('operating_expenses')->where('company_id', $c)->whereIn('id', $ids)->orderBy('id')->get()->getResultArray();
        $receipts = $this->db->table('operating_expense_receipts')->where('company_id', $c)->whereIn('operating_expense_id', $ids)->orderBy('id')->limit(1001)->get()->getResultArray();
        if (count($receipts) > 1000) {
            throw new RuntimeException('Expense evidence exceeds the supported workspace bound.');
        }
        $fileIds = array_column($receipts, 'file_id');
        $files = $fileIds === [] ? [] : array_column($this->db->table('files')->whereIn('id', $fileIds)->get()->getResultArray(), null, 'id');
        $validFiles = [];
        $settings = new \Config\ExpenseReceipts();
        foreach ($files as $id => $file) {
            $metadata = [];
            foreach ($file as $field => $value) {
                $metadata['file_' . $field] = $value;
            }
            $validFiles[$id] = (new \App\Services\Files\PrivateEvidenceStorageService())->resolve($metadata, $settings->storageDirectory, $settings->allowedMimeTypes) !== null;
        }
        $result = [];
        foreach ($rows as $row) {
            $snapshot = self::pick($row, 'id company_id fleet_vehicle_id turo_trip_normalized_id amount expense_date expense_category_lookup_value_id vendor payment_method_code payment_reference business_purpose source_code status_code');
            $validAmount = true;
            try {
                $snapshot['amount'] = RepairEstimateMoney::normalize((string) $row['amount']);
            } catch (InvalidArgumentException) {
                // Unexpected out-of-contract source money is a stale read state,
                // never a broken GET or a reason to hide a committed receipt.
                $validAmount = false;
                $snapshot['amount'] = (string) $row['amount'];
            }
            $snapshot['archived'] = $row['archived_at'] !== null;
            $snapshot['currency_basis'] = 'USD:implicit_operating_expense_reporting_contract';
            $snapshot['reportable'] = $validAmount && $row['status_code'] === 'recorded' && $row['archived_at'] === null && RepairCostMoney::compare($snapshot['amount'], '0.00') > 0;
            $snapshot['evidence'] = [];
            foreach ($receipts as $receipt) {
                if ((int) $receipt['operating_expense_id'] === (int) $row['id']) {
                    $file = $files[$receipt['file_id']] ?? null;
                    $snapshot['evidence'][] = self::pick($receipt, 'id file_id classification_code document_date observed_amount vendor duplicate_of_receipt_id') + ['archived' => $receipt['archived_at'] !== null, 'file' => $file === null ? null : self::pick($file, 'id checksum mime_type size_bytes deleted_at'), 'evidence_valid' => $validFiles[$receipt['file_id']] ?? false];
                }
            }
            self::json($snapshot);
            $result[$row['id']] = ['snapshot' => $snapshot, 'fingerprint' => self::fingerprint($snapshot)];
        }
        return $result;
    }

    public function apply(string $action, int $c, int $v, array $job, array $data, int $actor, string $now): array
    {
        $j = (int) $job['id'];
        self::confirm($data['confirmed'] ?? null);
        $reason = VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
        $rows = $this->records->rows($c, $v, $j);
        $old = null;
        if ($action !== 'b32_create') {
            $old = array_column($rows, null, 'id')[(int) ($data['reconciliation_id'] ?? 0)] ?? throw new InvalidArgumentException('Reconciliation not found in this job.');
            if ($old['status_code'] !== 'active') {
                throw new InvalidArgumentException('Only an active reconciliation may be invalidated or replaced.');
            }
            self::expect(self::state($old), $data['expected_reconciliation_state'] ?? null);
        }
        if ($action === 'b32_invalidate') {
            $this->records->invalidate($old, $actor, $reason, $now);
            return [$job, 'repair_financial_reconciliation_invalidated', ['reconciliation_id' => (int) $old['id']]];
        }
        foreach (['whole_fact_confirmed', 'no_other_job_confirmed', 'no_other_expense_confirmed', 'not_partial_confirmed'] as $field) {
            self::confirm($data[$field] ?? null);
        }
        $root = (int) ($data['cost_root_entry_id'] ?? 0);
        $expenseId = (int) ($data['operating_expense_id'] ?? 0);
        $family = $this->families($c, $v, $j)[$root] ?? throw new InvalidArgumentException('Choose an owned invoice root.');
        if ($family['snapshot'] === null || $job['cost_finalized_at'] === null || ! in_array($job['status_code'], ['completed', 'cancelled'], true) || ! (new VehicleDamageRepairRepository($this->db))->hasPerformedWork($c, $v, $j)) {
            throw new InvalidArgumentException('A valid positive invoice family and cost finalization are required.');
        }
        $expense = $this->expenses($c, [$expenseId])[$expenseId] ?? throw new InvalidArgumentException('Choose an owned operating expense.');
        $cost = $family['snapshot'];
        $source = $expense['snapshot'];
        if (! $source['reportable'] || (int) $source['fleet_vehicle_id'] !== $v || $source['turo_trip_normalized_id'] !== null) {
            throw new InvalidArgumentException('Expense must be recorded, positive, nonarchived, explicitly assigned to this vehicle and not trip-linked.');
        }
        if (($data['currency'] ?? null) !== 'USD' || RepairEstimateMoney::normalize($data['amount'] ?? null) !== $cost['amount'] || $cost['amount'] !== $source['amount']) {
            throw new InvalidArgumentException('Exact complete USD amount equality is required. Partial allocations are unavailable.');
        }
        self::expect($family['fingerprint'], $data['expected_damage_state'] ?? null);
        self::expect($expense['fingerprint'], $data['expected_expense_state'] ?? null);
        $dateReason = VehicleDamageRepairDocumentService::text($data['date_difference_reason'] ?? null, 2000, $cost['occurred_on'] !== $source['expense_date']);
        $descriptorReason = null;
        if ((string) $cost['vendor'] !== (string) $source['vendor'] || (string) $cost['reference'] !== (string) $source['payment_reference']) {
            self::confirm($data['descriptor_review_confirmed'] ?? null);
            $descriptorReason = VehicleDamageRepairDocumentService::text($data['descriptor_review_reason'] ?? null, 2000, true);
        }
        foreach ($this->db->table(Records::TABLE)->where('status_code', 'active')->groupStart()->where('cost_root_entry_id', $root)->orWhere('operating_expense_id', $expenseId)->groupEnd()->get()->getResultArray() as $reserved) {
            if ($old === null || (int) $reserved['id'] !== (int) $old['id']) {
                throw new InvalidArgumentException('Invoice root or expense is already actively reconciled.');
            }
        }
        if ($old !== null) {
            if ((int) $old['cost_root_entry_id'] !== $root) {
                throw new InvalidArgumentException('Replacement must retain the same invoice root and job context.');
            }
            $this->records->invalidate($old, $actor, $reason, $now);
        }
        // Authority review is retained inside the frozen expense provenance, but excluded from its source digest.
        $expenseSnapshot = $source + ['descriptor_review_reason' => $descriptorReason, 'whole_fact_confirmed' => true, 'no_other_job_confirmed' => true, 'no_other_expense_confirmed' => true, 'not_partial_confirmed' => true];
        $id = $this->records->insert(['company_id' => $c, 'fleet_vehicle_id' => $v, 'vehicle_damage_repair_job_id' => $j, 'cost_root_entry_id' => $root, 'operating_expense_id' => $expenseId,
            'fingerprint_version' => 'b32a:1', 'damage_lineage_fingerprint' => $family['fingerprint'], 'financial_source_fingerprint' => $expense['fingerprint'], 'damage_lineage_snapshot' => self::json($cost), 'financial_source_snapshot' => self::json($expenseSnapshot),
            'amount_snapshot' => $cost['amount'], 'currency' => 'USD', 'damage_occurred_on' => $cost['occurred_on'], 'financial_occurred_on' => $source['expense_date'], 'reason' => $reason, 'date_difference_reason' => $dateReason,
            'status_code' => 'active', 'created_by' => $actor, 'created_at' => $now, 'replacement_of_reconciliation_id' => $old['id'] ?? null, 'current_cost_root_entry_id' => $root, 'current_operating_expense_id' => $expenseId]);
        return [$job, $old === null ? 'repair_financial_reconciliation_created' : 'repair_financial_reconciliation_replaced', ['reconciliation_id' => $id, 'predecessor_id' => $old['id'] ?? null]];
    }

    /** Existing B2.3 command retains its single event/version; only changed families invalidate. */
    public function invalidateChangedCosts(int $c, int $v, int $j, int $actor, string $now): array
    {
        $families = $this->families($c, $v, $j);
        $ids = [];
        foreach ($this->records->rows($c, $v, $j) as $row) {
            if ($row['status_code'] === 'active' && ($families[$row['cost_root_entry_id']]['fingerprint'] ?? null) !== $row['damage_lineage_fingerprint']) {
                $this->records->invalidate($row, $actor, 'Invoice family or evidence changed in the triggering repair command.', $now);
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    public function workspace(int $c, int $v, int $j): array
    {
        if (! $this->records->ready()) {
            return ['ready' => false, 'families' => [], 'history' => [], 'expenses' => []];
        }
        $job = (new VehicleDamageRepairRepository($this->db))->job($c, $v, $j);
        if ($job === null) {
            throw new InvalidArgumentException('Owned repair job not found.');
        }
        $families = $this->families($c, $v, $j);
        $rows = $this->records->rows($c, $v, $j);
        $candidates = $this->db->table('operating_expenses')->select('id')->where(['company_id' => $c, 'fleet_vehicle_id' => $v, 'status_code' => 'recorded', 'archived_at' => null, 'turo_trip_normalized_id' => null])->where('amount >', 0)->orderBy('expense_date', 'DESC')->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();
        $expenses = $this->expenses($c, array_unique([...array_column($candidates, 'id'), ...array_column($rows, 'operating_expense_id')]));
        $reservations = $expenses === [] ? [] : $this->db->table(Records::TABLE)->select('id,operating_expense_id')->where(['company_id' => $c, 'status_code' => 'active'])->whereIn('operating_expense_id', array_keys($expenses))->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['state'] = self::state($row);
            $row['read_status'] = $row['status_code'] === 'invalidated' ? 'invalidated' : (($families[$row['cost_root_entry_id']]['fingerprint'] ?? null) === $row['damage_lineage_fingerprint'] && ($expenses[$row['operating_expense_id']]['fingerprint'] ?? null) === $row['financial_source_fingerprint'] ? 'active' : 'stale / review required');
        }
        unset($row);
        return ['ready' => true, 'families' => $families, 'history' => $rows, 'expenses' => $expenses, 'candidate_ids' => array_column($candidates, 'id'), 'reserved_expenses' => array_column($reservations, 'id', 'operating_expense_id'), 'finalized' => $job['cost_finalized_at'] !== null];
    }

    private static function pick(array $row, string $fields): array
    {
        $result = [];
        foreach (explode(' ', $fields) as $field) {
            // Driver-independent scalar representation; NULL is distinct from empty text.
            $result[$field] = isset($row[$field]) ? (string) $row[$field] : null;
        }
        return $result;
    }

    private static function json(array $snapshot): string
    {
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new RuntimeException('Reconciliation provenance exceeds its supported bound.');
        }
        return $json;
    }

    private static function confirm(mixed $value): void
    {
        if ($value !== 1 && $value !== '1') {
            throw new InvalidArgumentException('Explicit whole-fact/review confirmation is required.');
        }
    }

    private static function expect(string $actual, mixed $expected): void
    {
        if (! is_string($expected) || ! hash_equals($actual, $expected)) {
            throw new InvalidArgumentException('Source changed since preview. Reload and review.');
        }
    }
}
