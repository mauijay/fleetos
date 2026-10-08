<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Repositories\VehicleDamageRepairRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;

/** Quote decisions run inside VehicleDamageRepairService's aggregate transaction. */
class VehicleDamageRepairEstimateService
{
    public readonly Estimates $estimates;
    public readonly VehicleDamageRepairDocumentService $documents;
    private VehicleDamageRepairRepository $work;
    private array $lockedDocuments = [];

    public function __construct(private readonly BaseConnection $db)
    {
        $this->estimates = new Estimates($db, true);
        $this->documents = new VehicleDamageRepairDocumentService($db);
        $this->work = new VehicleDamageRepairRepository($db);
    }

    /** Lock pre-authorized job contexts across each table in global ID order. */
    public function lock(array $jobs, int $company): void
    {
        foreach ([Estimates::ESTIMATES, Estimates::SCOPE, Estimates::DOCUMENTS] as $table) {
            $sql = $this->db->table($table)->where('company_id', $company)->whereIn('vehicle_damage_repair_job_id', $jobs)->orderBy('id')->getCompiledSelect();
            $result = $this->db->query($sql . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'));
            if ($result === false) {
                throw new \RuntimeException('Estimate/document state is busy. Reload and retry.');
            }
            if ($table === Estimates::DOCUMENTS) {
                $this->lockedDocuments = $result->getResultArray();
            }
        }
    }

    /** Called only after aggregate rows, including any B2.3 costs, are locked. */
    public function lockMetadata(array $jobs, int $company): void
    {
        $docs = $this->lockedDocuments;
        foreach (['file_id', 'image_id'] as $key) {
            $ids = array_values(array_unique(array_filter(array_map('intval', array_column($docs, $key)))));
            if ($key === 'file_id' && $this->documents->uploadCandidateId > 0) {
                $ids[] = $this->documents->uploadCandidateId;
                $ids = array_values(array_unique($ids));
            }
            sort($ids);
            foreach ($ids as $id) {
                $doc = [$key => $id, $key === 'file_id' ? 'image_id' : 'file_id' => null];
                $this->documents->documents->metadata($doc, true);
            }
        }
    }

    public function snapshot(int $c, int $v, int $j): array
    {
        return ['estimates' => $this->estimates->estimates($c, $v, $j), 'scope' => $this->estimates->scope($c, $j),
            'documents' => $this->documents->documents->documents($c, $v, $j)];
    }

    public function apply(string $action, int $c, int $v, array $job, array $members, array $conditions, array $data, int $actor, string $now): array
    {
        $j = (int) $job['id'];
        $this->invariant($job, $this->estimates->estimates($c, $v, $j));
        $receipt = [];
        $event = '';
        if (in_array($action, ['b22_create', 'b22_revision'], true)) {
            $previous = null;
            $series = strtolower((string) ($data['quote_series_key'] ?? ''));
            if (! preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $series)) {
                throw new InvalidArgumentException('Supply an explicit quote series UUID.');
            }
            $lineage = array_values(array_filter($this->estimates->estimates($c, $v, $j), fn ($e) => $e['quote_series_key'] === $series));
            if ($action === 'b22_revision') {
                $previous = $this->owned($c, $v, $j, (int) ($data['previous_estimate_id'] ?? 0));
                $this->fingerprint($c, $v, $j, $previous, $data['expected_estimate_state'] ?? '');
                if ($previous['quote_series_key'] !== $series || $lineage === [] || (int) end($lineage)['id'] !== (int) $previous['id']) {
                    throw new InvalidArgumentException('Revision must continue the latest estimate in the same explicit series.');
                }
            } elseif ($lineage !== [] || ! empty($data['previous_estimate_id'])) {
                throw new InvalidArgumentException('Existing series requires a new revision of its latest estimate.');
            }
            $facts = $this->facts($job, $data);
            $scope = $data['scope_membership_ids'] ?? [];
            if (! is_array($scope) || $scope === []) {
                throw new InvalidArgumentException('Choose at least one scoped membership.');
            }
            $ids = array_map(static function (mixed $id): int {
                if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || (int) $id < 1) {
                    throw new InvalidArgumentException('Scope must contain positive membership IDs.');
                }
                return (int) $id;
            }, $scope);
            if (count(array_unique($ids)) !== count($ids) || in_array(0, $ids, true)) {
                throw new InvalidArgumentException('Select each valid membership once.');
            }
            sort($ids);
            $snapshots = [];
            foreach ($ids as $id) {
                $member = $this->member($members, $id);
                $condition = $conditions[(int) $member['vehicle_damage_item_id']] ?? null;
                $this->current($member, $condition);
                $snapshots[] = ['job_membership_id' => $id, 'canonical_condition_id' => (int) $condition['id'],
                    'description' => $condition['description'], 'zone' => $condition['zone_code'], 'panel' => $condition['panel_code'],
                    'damage_type' => $condition['damage_type_code'], 'severity' => $condition['severity_code'], 'physical_status' => $condition['status_code']];
            }
            $values = $facts + ['company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'quote_series_key' => $series,
                'revision_number' => $previous === null ? 1 : (int) $previous['revision_number'] + 1,
                'previous_estimate_id' => $previous['id'] ?? null, 'superseded_by_estimate_id' => null, 'status_code' => 'received',
                'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now];
            $id = $this->estimates->insertEstimate($values);
            foreach ($snapshots as $snapshot) {
                $this->estimates->insertScope(['company_id' => $c, 'vehicle_damage_repair_job_id' => $j,
                    'vehicle_damage_repair_estimate_id' => $id, 'vehicle_damage_repair_job_item_id' => $snapshot['job_membership_id'],
                    'condition_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_by' => $actor, 'created_at' => $now]);
            }
            if ($previous !== null && $previous['status_code'] === 'received') {
                self::confirmed($data['supersede_previous_confirmed'] ?? null);
                $this->estimates->disposition($c, $j, (int) $previous['id'], ['status_code' => 'superseded', 'superseded_by_estimate_id' => $id, 'updated_by' => $actor, 'updated_at' => $now]);
            }
            if (! empty($data['document'])) {
                if (! is_array($data['document']) || ($facts['recording_mode'] === 'current_quote' && ($data['document']['kind_code'] ?? '') !== 'estimate')) {
                    throw new InvalidArgumentException('Current quotes require an estimate source document.');
                }
                $doc = $this->documents->attach($c, $v, $j, $data['document'], $actor, $now, $id);
                $receipt['document_id'] = (int) $doc['id'];
            }
            if ($facts['recording_mode'] === 'current_quote' && ! $this->supported($c, $v, $j, $id)) {
                throw new InvalidArgumentException('Current quote requires a verified active binary estimate source in the same command.');
            }
            $receipt['estimate_id'] = $id;
            $event = $previous === null ? 'estimate_received' : 'estimate_revised';
        } elseif (in_array($action, ['b22_accept', 'b22_reject', 'b22_withdraw'], true)) {
            $estimate = $this->owned($c, $v, $j, (int) ($data['estimate_id'] ?? 0));
            $this->fingerprint($c, $v, $j, $estimate, $data['expected_estimate_state'] ?? '');
            self::confirmed($data['confirmed'] ?? null);
            if ($action === 'b22_accept') {
                $latest = array_values(array_filter($this->estimates->estimates($c, $v, $j), fn ($e) => $e['quote_series_key'] === $estimate['quote_series_key']));
                if ($job['intent_code'] !== 'repair' || ! in_array($job['status_code'], ['planned', 'scheduled', 'in_progress', 'deferred'], true)
                    || $estimate['status_code'] !== 'received' || $estimate['recording_mode'] !== 'current_quote'
                    || (int) end($latest)['id'] !== (int) $estimate['id']) {
                    throw new InvalidArgumentException('Acceptance requires a live repair job and its latest received authoritative quote.');
                }
                RepairEstimateMoney::normalize($estimate['amount']);
                if ($estimate['currency'] !== 'USD' || empty($estimate['vendor_snapshot']) || empty($estimate['quote_date'])
                    || self::validity($estimate, new DateTimeImmutable($now)) === 'expired' || ! $this->supported($c, $v, $j, (int) $estimate['id'])) {
                    throw new InvalidArgumentException('Quote is expired, incomplete, or missing an active verified estimate source.');
                }
                $quotedScope = $this->estimates->scope($c, $j, (int) $estimate['id']);
                if ($quotedScope === []) {
                    throw new InvalidArgumentException('Acceptance requires a nonempty frozen quote scope.');
                }
                foreach ($quotedScope as $scope) {
                    $m = $this->member($members, (int) $scope['vehicle_damage_repair_job_item_id']);
                    $this->current($m, $conditions[(int) $m['vehicle_damage_item_id']] ?? null);
                }
                $prior = (int) ($job['accepted_estimate_id'] ?? 0);
                if ($prior > 0) {
                    $old = $this->owned($c, $v, $j, $prior);
                    if ((int) ($data['previous_accepted_estimate_id'] ?? 0) !== $prior) {
                        throw new InvalidArgumentException('Review the current accepted estimate before replacing it.');
                    }
                    $this->fingerprint($c, $v, $j, $old, $data['expected_previous_accepted_state'] ?? '');
                    VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
                    $same = $old['quote_series_key'] === $estimate['quote_series_key'];
                    $this->estimates->disposition($c, $j, $prior, ['status_code' => $same ? 'superseded' : 'withdrawn',
                        'superseded_by_estimate_id' => $same ? $estimate['id'] : null, 'updated_by' => $actor, 'updated_at' => $now]);
                } elseif (! empty($data['previous_accepted_estimate_id'])) {
                    throw new InvalidArgumentException('Accepted estimate context has changed.');
                }
                $job['accepted_estimate_id'] = (int) $estimate['id'];
                $status = 'accepted';
                $event = 'estimate_accepted';
            } else {
                VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
                if (! in_array($estimate['status_code'], $action === 'b22_withdraw' ? ['received', 'accepted'] : ['received'], true)) {
                    throw new InvalidArgumentException('Estimate disposition is terminal or unchanged.');
                }
                if ($estimate['status_code'] === 'accepted') {
                    $job['accepted_estimate_id'] = null;
                }
                $status = $action === 'b22_reject' ? 'rejected' : 'withdrawn';
                $event = 'estimate_' . $status;
            }
            $this->estimates->disposition($c, $j, (int) $estimate['id'], ['status_code' => $status, 'updated_by' => $actor, 'updated_at' => $now]);
            $receipt['estimate_id'] = (int) $estimate['id'];
        } elseif ($action === 'b22_attach') {
            $doc = $this->documents->attach($c, $v, $j, $data['document'] ?? [], $actor, $now);
            $receipt['document_id'] = (int) $doc['id'];
            $event = 'document_attached';
        } elseif ($action === 'b22_archive') {
            $id = (int) ($data['document_id'] ?? 0);
            (new \App\Repositories\VehicleDamageRepairCostRepository($this->db))->archiveGuard($c, $j, $id);
            (new \App\Repositories\VehicleDamageRepairRecoveryRepository($this->db))->archiveGuard($c, $j, $id);
            $doc = $this->documents->documents->document($c, $v, $j, $id);
            if ($doc === null || $doc['archived_at'] !== null) {
                throw new InvalidArgumentException('Document is unavailable or already archived.');
            }
            if (! hash_equals($this->documents->documents->fingerprint($c, $v, $j, $id), (string) ($data['expected_document_state'] ?? ''))) {
                throw new InvalidArgumentException('Document changed. Reload and review before archiving.');
            }
            self::confirmed($data['confirmed'] ?? null);
            $reason = VehicleDamageRepairDocumentService::text($data['reason'] ?? null, 2000, true);
            $this->documents->documents->archive($c, $j, $id, $actor, $reason, $now);
            $receipt['document_id'] = $id;
            $event = 'document_archived';
        } else {
            throw new InvalidArgumentException('Unsupported estimate/document command.');
        }
        $this->invariant($job, $this->estimates->estimates($c, $v, $j));
        return [$job, $event, $receipt];
    }

    private function facts(array $job, array $data): array
    {
        $text = VehicleDamageRepairDocumentService::text(...);
        $mode = (string) ($data['recording_mode'] ?? '');
        if (! in_array($mode, ['current_quote', 'historical_incomplete'], true)) {
            throw new InvalidArgumentException('Choose an explicit quote recording mode.');
        }
        self::confirmed($data['amount_confirmed'] ?? null);
        self::confirmed($data['currency_confirmed'] ?? null);
        $amount = RepairEstimateMoney::normalize($data['amount'] ?? null);
        if (($data['currency'] ?? '') !== 'USD') {
            throw new InvalidArgumentException('Only explicitly confirmed USD quotes are supported.');
        }
        $vendor = (int) ($data['vendor_company_id'] ?? 0) ?: null;
        if ($vendor !== null && ! array_any($this->work->vendors((int) $job['company_id']), fn ($v) => (int) $v['id'] === $vendor)) {
            throw new InvalidArgumentException('Vendor identity is not eligible for this company.');
        }
        $vendorText = $text($data['vendor_snapshot'] ?? null, 190, $mode === 'current_quote');
        $date = self::date($data['quote_date'] ?? null);
        $expiresOn = self::date($data['expires_on'] ?? null);
        $expiresAt = $text($data['expires_at'] ?? null, 19);
        if ($expiresAt !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAt);
            if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $expiresAt) {
                throw new InvalidArgumentException('Expiry instant must use YYYY-MM-DD HH:MM:SS.');
            }
        }
        if ($expiresOn !== null && $expiresAt !== null) {
            throw new InvalidArgumentException('Choose either an expiry date or an exact instant.');
        }
        if ($mode === 'current_quote') {
            if ($job['intent_code'] !== 'repair' || $date === null) {
                throw new InvalidArgumentException('Current quotes require a repair-intent job and authoritative quote date.');
            }
            foreach (['vendor_confirmed', 'date_confirmed', 'scope_confirmed'] as $confirm) {
                self::confirmed($data[$confirm] ?? null);
            }
        }
        return ['amount' => $amount, 'currency' => 'USD', 'recording_mode' => $mode, 'vendor_company_id' => $vendor,
            'vendor_snapshot' => $vendorText, 'quote_date' => $date, 'expires_at' => $expiresAt, 'expires_on' => $expiresOn,
            'vendor_quote_reference' => $text($data['vendor_quote_reference'] ?? null, 120), 'note' => $text($data['note'] ?? null, 2000),
            'historical_recording_reason' => $mode === 'historical_incomplete' ? $text($data['historical_recording_reason'] ?? null, 2000, true) : null];
    }

    public static function validity(array $estimate, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();
        if ($estimate['expires_at'] !== null) {
            return $now >= new DateTimeImmutable($estimate['expires_at']) ? 'expired' : 'valid';
        }
        if ($estimate['expires_on'] !== null) {
            return $now->format('Y-m-d') > $estimate['expires_on'] ? 'expired' : 'valid';
        }
        return 'unknown';
    }

    private static function date(mixed $input): ?string
    {
        $value = VehicleDamageRepairDocumentService::text($input, 10);
        if ($value === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Quote and expiry dates must be valid YYYY-MM-DD dates.');
        }
        return $value;
    }

    private function supported(int $c, int $v, int $j, int $estimate): bool
    {
        foreach ($this->documents->documents->documents($c, $v, $j) as $doc) {
            if ((int) $doc['vehicle_damage_repair_estimate_id'] === $estimate && $doc['kind_code'] === 'estimate' && $doc['archived_at'] === null
                && $this->documents->storage->resolve($c, $doc, $this->documents->documents->metadata($doc, true)) !== null) {
                return true;
            }
        }
        return false;
    }

    private function owned(int $c, int $v, int $j, int $id): array
    {
        return $this->estimates->estimate($c, $v, $j, $id) ?? throw new InvalidArgumentException('Estimate not found in this work context.');
    }

    private function fingerprint(int $c, int $v, int $j, array $e, mixed $expected): void
    {
        if (! is_string($expected) || ! hash_equals($this->estimates->fingerprint($c, $v, $j, (int) $e['id']), $expected)) {
            throw new InvalidArgumentException('Estimate changed. Reload and review its current scope and sources.');
        }
    }

    private function member(array $members, int $id): array
    {
        foreach ($members as $member) {
            if ((int) $member['id'] === $id) {
                return $member;
            }
        }
        throw new InvalidArgumentException('Membership not found in this work context.');
    }

    private function current(array $m, ?array $condition): void
    {
        if ($m['withdrawn_at'] !== null || $condition === null || $condition['current_condition_item_id'] !== null) {
            throw new InvalidArgumentException('Quoted membership is no longer active and current.');
        }
    }

    private function invariant(array $job, array $estimates): void
    {
        $accepted = array_values(array_filter($estimates, fn ($e) => $e['status_code'] === 'accepted'));
        $pointer = (int) ($job['accepted_estimate_id'] ?? 0);
        if (($pointer === 0 && $accepted !== []) || ($pointer > 0 && (count($accepted) !== 1 || (int) $accepted[0]['id'] !== $pointer))) {
            throw new InvalidArgumentException('Accepted estimate authority is inconsistent. Repair requires explicit administrative review.');
        }
    }

    private static function confirmed(mixed $value): void
    {
        if ((string) $value !== '1') {
            throw new InvalidArgumentException('Explicit source or decision confirmation is required.');
        }
    }
}
