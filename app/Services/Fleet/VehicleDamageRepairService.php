<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\VehicleDamage;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** One transaction owns each aggregate command, including correlated physical outcomes. */
class VehicleDamageRepairService
{
    private BaseConnection $db;
    private VehicleDamageRepairRepository $repairs;
    private VehicleDamageRepository $damage;
    private VehicleDamageService $conditions;
    private AuditLogRepository $audits;
    private ?string $savepoint = null;
    private ?\Closure $commandClock;

    public function __construct(?BaseConnection $db = null, ?VehicleDamageRepairRepository $repository = null, ?VehicleDamageService $conditions = null, ?AuditLogRepository $audits = null, ?\Closure $commandClock = null)
    {
        $this->commandClock = $commandClock;
        $this->db = $db ?? Database::connect();
        $this->repairs = $repository ?? new VehicleDamageRepairRepository($this->db);
        $this->damage = new VehicleDamageRepository($this->db);
        $this->conditions = $conditions ?? new VehicleDamageService($this->db);
        $this->audits = $audits ?? new AuditLogRepository($this->db);
    }

    public static function commandKey(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    /** @return array{work:bool,estimates_documents:bool} */
    public function readiness(): array
    {
        return ['work' => $this->repairs->ready(), 'estimates_documents' => (new \App\Repositories\VehicleDamageRepairEstimateRepository($this->db))->ready()];
    }

    public function conditionPreview(int $company, int $vehicle, int $selected): array
    {
        $this->repairs->requireReady();
        if ($this->damage->vehicle($company, $vehicle) === null) {
            throw new InvalidArgumentException('Vehicle not found.');
        }
        $original = $this->damage->item($company, $vehicle, $selected);
        $canonical = $this->damage->canonicalItem($company, $vehicle, $selected);
        if ($original === null || $canonical === null) {
            throw new InvalidArgumentException('Condition not found for this vehicle.');
        }
        return ['original' => $original, 'canonical' => $canonical, 'expected_condition_state' => $this->repairs->conditionFingerprint($canonical)];
    }

    public function createJob(int $c, int $v, array $d, int $a): array
    {
        return $this->run('create', $c, $v, 0, $d, $a);
    }
    public function correctJobDetails(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('details', $c, $v, $j, $d, $a);
    }
    public function addCondition(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('add', $c, $v, $j, $d, $a);
    }
    public function withdrawCondition(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('withdraw', $c, $v, $j, $d, $a);
    }
    public function schedule(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('schedule', $c, $v, $j, $d, $a);
    }
    public function start(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('start', $c, $v, $j, $d, $a);
    }
    public function defer(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('defer', $c, $v, $j, $d, $a);
    }
    public function resume(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('resume', $c, $v, $j, $d, $a);
    }
    public function cancel(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('cancel', $c, $v, $j, $d, $a);
    }
    public function complete(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('complete', $c, $v, $j, $d, $a);
    }
    public function recordMembershipResult(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('result', $c, $v, $j, $d, $a);
    }
    public function reopenJob(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('reopen_job', $c, $v, $j, $d, $a);
    }
    public function confirmConditionRepaired(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('confirm', $c, $v, $j, $d, $a);
    }

    public function createEstimate(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_create', $c, $v, $j, $d, $a);
    }
    public function createRevision(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_revision', $c, $v, $j, $d, $a);
    }
    public function acceptEstimate(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_accept', $c, $v, $j, $d, $a);
    }
    public function rejectEstimate(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_reject', $c, $v, $j, $d, $a);
    }
    public function withdrawEstimate(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_withdraw', $c, $v, $j, $d, $a);
    }
    public function attachDocument(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_attach', $c, $v, $j, $d, $a);
    }
    public function archiveDocument(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b22_archive', $c, $v, $j, $d, $a);
    }

    public function recordCostEntry(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b23_record', $c, $v, $j, $d, $a);
    }
    public function voidCostEntry(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b23_void', $c, $v, $j, $d, $a);
    }
    public function replaceCostEntry(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b23_replace', $c, $v, $j, $d, $a);
    }
    public function finalizeRepairCost(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b23_finalize', $c, $v, $j, $d, $a);
    }
    public function invalidateCostFinalization(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b23_invalidate', $c, $v, $j, $d, $a);
    }

    public function recordRecovery(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b31_record', $c, $v, $j, $d, $a);
    }
    public function voidRecovery(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b31_void', $c, $v, $j, $d, $a);
    }
    public function replaceRecovery(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b31_replace', $c, $v, $j, $d, $a);
    }
    public function finalizeRecovery(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b31_finalize', $c, $v, $j, $d, $a);
    }
    public function invalidateRecoveryFinalization(int $c, int $v, int $j, array $d, int $a): array
    {
        return $this->run('b31_invalidate', $c, $v, $j, $d, $a);
    }

    /** Called by the explicit damage command; never by the generic status transition. */
    public function reopenCondition(int $c, int $v, int $condition, array $d, int $a): array
    {
        $d['condition_id'] = $condition;
        return $this->run('reopen_condition', $c, $v, (int) ($d['job_id'] ?? 0), $d, $a);
    }

    private function run(string $action, int $company, int $vehicle, int $jobId, array $data, int $actor, bool $recoverCollision = true): array
    {
        $begun = false;
        $committing = false;
        $b22 = str_starts_with($action, 'b22_') ? new VehicleDamageRepairEstimateService($this->db) : null;
        $b23 = str_starts_with($action, 'b23_') ? new VehicleDamageRepairCostService($this->db) : null;
        $b31 = str_starts_with($action, 'b31_') ? new VehicleDamageRepairRecoveryService($this->db) : null;
        $shared = $b31 !== null ? $b31->sources : ($b23 !== null ? $b23->sources : $b22);
        $costRepository = new \App\Repositories\VehicleDamageRepairCostRepository($this->db);
        $recoveryRepository = new \App\Repositories\VehicleDamageRepairRecoveryRepository($this->db);
        $extraReceipt = [];
        $sourceJobs = [$jobId => $vehicle];
        try {
            $this->repairs->requireReady();
            if ($b22 !== null) {
                $b22->estimates->requireReady();
                if (isset($data['document'])) {
                    if (! is_array($data['document'])) {
                        throw new InvalidArgumentException('Choose a valid document source.');
                    }
                    $data['document'] = $b22->documents->normalizeSource($data['document'], $company, $vehicle);
                    if (! empty($data['document']['source_document_id'])) {
                        $sourceJob = (int) ($data['document']['source_job_id'] ?? 0);
                        $sourceVehicle = (int) ($data['document']['source_vehicle_id'] ?? 0) ?: $vehicle;
                        if ($sourceJob < 1 || ($sourceJob === $jobId && $sourceVehicle !== $vehicle)) {
                            throw new InvalidArgumentException('Choose an explicit owned source job.');
                        }
                        $sourceJobs[$sourceJob] = $sourceVehicle;
                    }
                }
            }
            if ($b23 !== null) {
                $b23->costs->requireReady();
                $data = $b23->normalize($data, $company, $vehicle, $jobId);
            }
            if ($b31 !== null) {
                $b31->recoveries->requireReady();
                $data = $b31->normalize($data, $company, $vehicle, $jobId);
            }
            if ($shared !== null && $recoveryRepository->present()) {
                $recoveryRepository->requireReady();
            }
            if (($action === 'reopen_job' || $b22 !== null) && $costRepository->present()) {
                $costRepository->requireReady();
            }
            if ($actor < 1) {
                throw new InvalidArgumentException('An authenticated operator is required.');
            }
            $key = strtolower($this->text($data['command_key'] ?? '', 36, true));
            if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $key)) {
                throw new InvalidArgumentException('Supply a valid command UUID.');
            }
            $hash = hash('sha256', json_encode([$action, $company, $vehicle, $jobId, $actor, ($b31 !== null ? $b31::semantic($data) : ($b23 !== null ? $b23::semantic($data) : ($b22 === null ? $this->semantic($data) : $this->estimateSemantic($data))))], JSON_THROW_ON_ERROR));
            if (($b23 !== null || $b31 !== null) && $this->db->getPlatform() === 'SQLite3' && $this->db->transDepth > 0) {
                throw new InvalidArgumentException('Repair cost commands require ownership of the outer transaction.');
            }
            if (($b23 !== null || $b31 !== null) && $this->db->getPlatform() !== 'SQLite3' && $this->db->transDepth === 0) {
                if ((int) $this->db->query('SELECT @@in_transaction AS active')->getRowArray()['active'] === 1) {
                    throw new InvalidArgumentException('Repair cost commands require ownership of the outer transaction.');
                }
                // Vehicle/job locks serialize aggregate writes. Statement-current
                // reads avoid stale cross-job duplicate warnings after metadata
                // waits and unnecessary RR gap locks on neighboring aggregates.
                // This applies only to the next transaction, not session defaults.
                if ($this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
                    throw new RuntimeException('Repair cost command could not establish current reads.');
                }
            }
            $this->begin();
            $begun = true;
            $vehicles = array_values(array_unique(array_values($sourceJobs)));
            sort($vehicles, SORT_NUMERIC);
            foreach ($vehicles as $ownedVehicle) {
                $this->damage->lockVehicle($company, $ownedVehicle);
            }
            ksort($sourceJobs, SORT_NUMERIC);
            if ($b22 !== null) {
                foreach ($sourceJobs as $ownedJob => $ownedVehicle) {
                    if ($this->repairs->job($company, $ownedVehicle, $ownedJob, true) === null) {
                        throw new InvalidArgumentException('Source job not found for this company and vehicle.');
                    }
                }
            }
            $job = $jobId > 0 ? $this->repairs->job($company, $vehicle, $jobId, true) : null;
            if ($jobId > 0 && $job === null) {
                throw new InvalidArgumentException('Work job not found for this vehicle and company.');
            }
            if ($action !== 'reopen_condition' || $jobId > 0) {
                $prior = $this->repairs->replay($company, $key);
                if ($prior !== null) {
                    if ($this->repairs->job($company, $vehicle, (int) $prior['vehicle_damage_repair_job_id']) === null || ($jobId > 0 && $jobId !== (int) $prior['vehicle_damage_repair_job_id'])) {
                        throw new InvalidArgumentException('Command identity belongs to another work context.');
                    }
                    if (! hash_equals($prior['command_payload_hash'], $hash)) {
                        throw new InvalidArgumentException('Command key was already used with a different payload.');
                    }
                    $receipt = json_decode($prior['after_json'], true, 512, JSON_THROW_ON_ERROR)['receipt'];
                    $this->commit();
                    return $receipt + ['replayed' => true, 'event_id' => (int) $prior['id']];
                }
            }
            if (($b23 !== null || $b31 !== null) && $this->db->transDepth > 1) {
                throw new InvalidArgumentException('Repair cost commands require ownership of the outer transaction.');
            }
            if ($b22 !== null && ($data['document']['upload'] ?? null) instanceof \CodeIgniter\HTTP\Files\UploadedFile && $this->db->transDepth > 1) {
                throw new InvalidArgumentException('New repair binaries require ownership of the outer transaction.');
            }
            if ($shared !== null) {
                $shared->documents->prepareUpload($company, $data['document'] ?? []);
            }
            $members = $job === null ? [] : $this->repairs->members($company, $vehicle, $jobId);
            $ids = array_map(static fn (array $m): int => (int) $m['vehicle_damage_item_id'], $members);
            $selections = $action === 'create' ? ($data['conditions'] ?? []) : ($action === 'add' ? [$data['condition'] ?? []] : []);
            if (! is_array($selections)) {
                throw new InvalidArgumentException('Choose condition scope.');
            }
            foreach ($selections as $selection) {
                if (! is_array($selection)) {
                    throw new InvalidArgumentException('Choose a valid condition.');
                }
                $ids[] = $this->positive($selection['selected_item_id'] ?? null);
                $ids[] = $this->positive($selection['canonical_item_id'] ?? null);
            }
            if ($action === 'reopen_condition') {
                $ids[] = $this->positive($data['condition_id'] ?? null);
            }
            $ids = array_unique($ids);
            sort($ids, SORT_NUMERIC);
            $locked = [];
            foreach ($ids as $id) {
                $this->damage->lockItem($company, $vehicle, $id);
                $locked[$id] = $this->repairs->condition($company, $vehicle, $id, true);
            }
            $members = $job === null ? [] : $this->repairs->members($company, $vehicle, $jobId, true);
            if ($action === 'reopen_condition' && $jobId === 0) {
                return $this->standaloneReopen($company, $vehicle, $locked[(int) $data['condition_id']], $data, $actor, $key, $hash);
            }
            if ($job !== null && (int) ($data['expected_version'] ?? 0) !== (int) $job['version']) {
                throw new InvalidArgumentException('Work changed since this form was opened. Reload and review the latest history.');
            }
            if ($shared !== null) {
                $shared->lock(array_keys($sourceJobs), $company);
                if ($costRepository->present()) {
                    $costRepository->requireReady();
                    $costRepository->lock(array_keys($sourceJobs), $company);
                }
                if ($b31 !== null) {
                    $b31->lockClaim($company, $vehicle, $jobId, $data);
                }
                if ($recoveryRepository->present()) {
                    $recoveryRepository->lock(array_keys($sourceJobs), $company);
                }
                $shared->lockMetadata(array_keys($sourceJobs), $company);
            }
            $before = $job === null ? null : ['job' => $job, 'members' => $members];
            if ($shared !== null) {
                $before['b22'] = $shared->snapshot($company, $vehicle, $jobId);
            }
            if ($b23 !== null) {
                $before['b23'] = $b23->snapshot($company, $vehicle, $jobId);
            }
            if ($b31 !== null) {
                $before['b31'] = $b31->snapshot($company, $vehicle, $jobId, $action === 'b31_invalidate');
            }
            $now = $this->commandClock === null ? date('Y-m-d H:i:s') : ($this->commandClock)()->format('Y-m-d H:i:s');
            $event = 'job_created';
            $occurred = null;
            $physical = null;
            $memberRef = null;
            $conditionRef = null;

            if ($action === 'create') {
                if ($selections === []) {
                    throw new InvalidArgumentException('Select at least one canonical condition.');
                }
                $historical = ($data['recording_mode'] ?? 'planned') === 'historical_mitigation';
                if (! in_array($data['recording_mode'] ?? 'planned', ['planned', 'historical_mitigation'], true)) {
                    throw new InvalidArgumentException('Choose a valid recording mode.');
                }
                $values = $this->details($company, $data);
                if ($historical) {
                    if ($values['intent_code'] !== 'mitigation' || (string) ($data['performed_confirmed'] ?? '') !== '1') {
                        throw new InvalidArgumentException('Explicitly confirm performed temporary mitigation.');
                    }
                    $this->text($data['recording_reason'] ?? '', 2000, true);
                    $values['completion_note'] = $this->text($data['completion_note'] ?? '', 2000, true);
                    $occurred = $this->time($data['performed_at'] ?? null, false, true);
                    $event = 'historical_mitigation_recorded';
                }
                $values += ['company_id' => $company, 'fleet_vehicle_id' => $vehicle, 'status_code' => $historical ? 'completed' : 'planned', 'scheduled_at' => null, 'started_at' => null, 'completed_at' => $occurred, 'completion_note' => null, 'version' => 1, 'creation_command_key' => $key, 'creation_command_payload_hash' => $hash, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now];
                $jobId = $this->repairs->insertJob($values);
                $job = $this->repairs->job($company, $vehicle, $jobId, true);
                $seen = [];
                foreach ($selections as $selection) {
                    $condition = $this->selection($selection, $locked);
                    $id = (int) $condition['id'];
                    if (isset($seen[$id])) {
                        throw new InvalidArgumentException('Select each canonical condition once.');
                    }
                    $seen[$id] = true;
                    if ($occurred !== null && $occurred < $condition['discovered_at']) {
                        throw new InvalidArgumentException('Work time precedes the recorded discovery. Review the chronology.');
                    }
                    $this->repairs->insertMember(['company_id' => $company, 'vehicle_damage_repair_job_id' => $jobId, 'vehicle_damage_item_id' => $id, 'result_code' => $historical ? 'mitigated' : 'unassessed', 'note' => $this->text($selection['note'] ?? null, 2000), 'occurred_at' => $occurred, 'withdrawn_at' => null, 'withdrawn_by' => null, 'withdrawal_reason' => null, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now]);
                }
            } else {
                if ($b31 !== null) {
                    [$job, $event, $extraReceipt] = $b31->apply($action, $company, $vehicle, $job, $data, $actor, $now);
                } elseif ($b23 !== null) {
                    [$job, $event, $extraReceipt] = $b23->apply($action, $company, $vehicle, $job, $data, $actor, $now);
                } elseif ($b22 !== null) {
                    [$job, $event, $extraReceipt] = $b22->apply($action, $company, $vehicle, $job, $members, $locked, $data, $actor, $now);
                } else {
                    [$job, $event, $occurred, $physical, $memberRef, $conditionRef] = $this->apply($action, $company, $vehicle, $job, $members, $locked, $data, $actor, $now);
                }
                $afterMembers = $this->repairs->members($company, $vehicle, $jobId, true);
                if ($action === 'reopen_job' && $costRepository->present()) {
                    $job = VehicleDamageRepairCostService::clearFinalization($job);
                }
                if ($b31 === null && $b23 === null && $b22 === null && $job === $before['job'] && $afterMembers === $before['members'] && $physical === null) {
                    throw new InvalidArgumentException('No change to record.');
                }
                $job['version'] = (int) $job['version'] + 1;
                $job['updated_by'] = $actor;
                $job['updated_at'] = $now;
                $this->repairs->updateJob($company, $jobId, $job);
            }
            $job = $this->repairs->job($company, $vehicle, $jobId, true);
            $members = $this->repairs->members($company, $vehicle, $jobId, true);
            if ($shared !== null && isset($data['document']['descriptor'])) {
                $extraReceipt['source_descriptor'] = $data['document']['descriptor'];
            }
            $receipt = ['success' => true, 'id' => $jobId, 'version' => (int) $job['version'], 'errors' => []] + $extraReceipt;
            $after = ['job' => $job, 'members' => $members, 'receipt' => $receipt];
            if ($shared !== null) {
                $after['b22'] = $shared->snapshot($company, $vehicle, $jobId);
                foreach (['estimates' => 'estimate_ids', 'documents' => 'document_ids'] as $field => $idsField) {
                    $old = array_column($before['b22'][$field], null, 'id');
                    $after['b22'][$idsField] = [];
                    foreach ($after['b22'][$field] as $row) {
                        if (($old[$row['id']] ?? null) !== $row) {
                            $after['b22'][$idsField][] = (int) $row['id'];
                            if ($field === 'documents' && $row['vehicle_damage_repair_estimate_id'] !== null) {
                                $after['b22']['estimate_ids'][] = (int) $row['vehicle_damage_repair_estimate_id'];
                            }
                        }
                    }
                }
            }
            if ($b23 !== null) {
                $after['b23'] = $b23->snapshot($company, $vehicle, $jobId);
                $oldEntries = array_column($before['b23']['entries'], null, 'id');
                $after['b23']['entry_ids'] = [];
                foreach ($after['b23']['entries'] as $row) {
                    if (($oldEntries[$row['id']] ?? null) !== $row) {
                        $after['b23']['entry_ids'][] = (int) $row['id'];
                    }
                }
            }
            if ($b31 !== null) {
                $after['b31'] = $b31->snapshot($company, $vehicle, $jobId, $action === 'b31_invalidate');
                $oldEntries = array_column($before['b31']['entries'], null, 'id');
                $after['b31']['entry_ids'] = [];
                foreach ($after['b31']['entries'] as $row) {
                    if (($oldEntries[$row['id']] ?? null) !== $row) {
                        $after['b31']['entry_ids'][] = (int) $row['id'];
                    }
                }
            }
            if ($physical !== null) {
                $before['condition'] = $physical['before'];
                $after['condition'] = array_merge($physical['before'], $physical['values']);
            }
            $eventReason = $b22 === null
                ? ($data['reason'] ?? $data['recording_reason'] ?? $data['inspection_note'] ?? $data['note'] ?? $data['completion_note'] ?? null)
                : ($data['reason'] ?? $data['note'] ?? null);
            $eventId = $this->repairs->insertEvent(['company_id' => $company, 'vehicle_damage_repair_job_id' => $jobId, 'vehicle_damage_repair_job_item_id' => $memberRef, 'vehicle_damage_item_id' => $conditionRef, 'event_code' => $event, 'job_version' => (int) $job['version'], 'actor_user_id' => $actor, 'recorded_at' => $now, 'occurred_at' => $occurred, 'reason_category_code' => $this->text($data['reason_category_code'] ?? null, 40), 'reason' => $this->text($eventReason, 2000), 'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_json' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => $key, 'command_payload_hash' => $hash]);
            if ($physical !== null) {
                $this->conditions->writeRepairStateInTransaction($physical['before'], $physical['values'], $physical['event'], $physical['note'], $actor, $occurred, $eventId, ['reason_category_code' => $data['reason_category_code'] ?? null]);
            }
            $this->audit('vehicle_damage_repair_jobs', $jobId, $before['job'] ?? null, $job, $actor);
            $oldMembers = array_column($before['members'] ?? [], null, 'id');
            foreach ($members as $member) {
                $old = $oldMembers[$member['id']] ?? null;
                if ($old !== $member) {
                    $this->audit('vehicle_damage_repair_job_items', (int) $member['id'], $old, $member, $actor);
                }
            }
            if ($shared !== null) {
                foreach (['estimates' => \App\Repositories\VehicleDamageRepairEstimateRepository::ESTIMATES, 'scope' => \App\Repositories\VehicleDamageRepairEstimateRepository::SCOPE, 'documents' => \App\Repositories\VehicleDamageRepairEstimateRepository::DOCUMENTS] as $field => $table) {
                    $old = array_column($before['b22'][$field], null, 'id');
                    foreach ($after['b22'][$field] as $row) {
                        if (($old[$row['id']] ?? null) !== $row) {
                            $this->audit($table, (int) $row['id'], $old[$row['id']] ?? null, $row, $actor);
                        }
                    }
                }
            }
            if ($b23 !== null) {
                $old = array_column($before['b23']['entries'], null, 'id');
                foreach ($after['b23']['entries'] as $row) {
                    if (($old[$row['id']] ?? null) !== $row) {
                        $this->audit(\App\Repositories\VehicleDamageRepairCostRepository::TABLE, (int) $row['id'], $old[$row['id']] ?? null, $row, $actor);
                    }
                }
            }
            if ($b31 !== null) {
                $old = array_column($before['b31']['entries'], null, 'id');
                foreach ($after['b31']['entries'] as $row) {
                    if (($old[$row['id']] ?? null) !== $row) {
                        $this->audit(\App\Repositories\VehicleDamageRepairRecoveryRepository::TABLE, (int) $row['id'], $old[$row['id']] ?? null, $row, $actor);
                    }
                }
            }
            $committing = true;
            $this->commit();
            return $receipt + ['replayed' => false, 'event_id' => $eventId];
        } catch (Throwable $exception) {
            $duplicate = (int) ($this->db->error()['code'] ?? 0) === 1062;
            if ($begun) {
                try {
                    $rolledBack = $this->rollback();
                } catch (Throwable) {
                    $rolledBack = false;
                }
                if ($shared !== null && ! $committing && $rolledBack) {
                    $shared->documents->discardAfterRollback();
                }
            }
            if ($duplicate && $recoverCollision) {
                // Re-enter through the owned context and locking replay lookup after rollback.
                return $this->run($action, $company, $vehicle, $jobId, $data, $actor, false);
            }
            if (($b23 !== null || $b31 !== null) && $committing) {
                $monetary = $b31 ?? $b23;
                return ['success' => false, 'uncertain' => true, 'retry_payload' => $monetary::semantic($data) + ['command_key' => $key], 'errors' => ['work' => 'Commit outcome is uncertain. Preserve the source and retry the same command key and frozen descriptor to recover the committed receipt.']];
            }
            return ['success' => false, 'errors' => ['work' => $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException ? $exception->getMessage() : 'Work could not be saved. Reload and retry.']];
        }
    }

    /** Separate normalizer leaves all accepted B2.1 hashes unchanged. */
    private function estimateSemantic(array $data): array
    {
        $allowed = explode(' ', 'expected_version quote_series_key previous_estimate_id amount currency recording_mode vendor_company_id vendor_snapshot quote_date expires_at expires_on vendor_quote_reference note historical_recording_reason amount_confirmed currency_confirmed vendor_confirmed date_confirmed scope_confirmed supersede_previous_confirmed scope_membership_ids estimate_id expected_estimate_state confirmed previous_accepted_estimate_id expected_previous_accepted_state reason reason_category_code document_id expected_document_state document');
        $data = array_intersect_key($data, array_flip($allowed));
        if (isset($data['document'])) {
            $data['document'] = array_intersect_key($data['document'], array_flip(explode(' ', 'kind_code estimate_id membership_id label note external_reference source_document_id source_job_id source_vehicle_id descriptor')));
        }
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                $value = array_map($normalize, $value);
                if (! array_is_list($value)) {
                    ksort($value);
                }
                return $value;
            }
            return $value === null ? null : trim((string) $value);
        };
        if (isset($data['scope_membership_ids']) && is_array($data['scope_membership_ids'])) {
            sort($data['scope_membership_ids'], SORT_NUMERIC);
        }
        if (isset($data['amount'])) {
            $data['amount'] = RepairEstimateMoney::normalize($data['amount']);
        }
        return $normalize($data);
    }

    private function apply(string $action, int $company, int $vehicle, array $job, array $members, array $locked, array $data, int $actor, string $now): array
    {
        $status = $job['status_code'];
        $active = array_values(array_filter($members, static fn (array $m): bool => $m['withdrawn_at'] === null));
        $event = '';
        $occurred = null;
        $physical = null;
        $memberRef = null;
        $conditionRef = null;
        if ($action === 'details') {
            $this->text($data['reason'] ?? '', 2000, true);
            $details = $this->details($company, $data);
            if (($details['intent_code'] !== $job['intent_code'] || $details['category_code'] !== $job['category_code']) && ($job['started_at'] !== null || $status === 'completed' || $this->repairs->hasPerformedWork($company, $vehicle, (int) $job['id']))) {
                throw new InvalidArgumentException('Performed work intent/category cannot be rewritten.');
            }
            $job = array_merge($job, $details);
            $event = 'job_details_corrected';
        } elseif ($action === 'add') {
            $this->nonterminal($status);
            $this->text($data['reason'] ?? '', 2000, true);
            $condition = $this->selection($data['condition'] ?? [], $locked);
            $conditionRef = (int) $condition['id'];
            $existing = array_values(array_filter($members, static fn (array $m): bool => (int) $m['vehicle_damage_item_id'] === $conditionRef));
            if ($existing !== []) {
                if ($existing[0]['withdrawn_at'] === null) {
                    throw new InvalidArgumentException('No change to record: condition is already in this job.');
                }
                $memberRef = (int) $existing[0]['id'];
                $this->memberUpdate($company, $existing[0], ['withdrawn_at' => null, 'withdrawn_by' => null, 'withdrawal_reason' => null], $actor, $now);
                $event = 'condition_reactivated';
            } else {
                $memberRef = $this->repairs->insertMember(['company_id' => $company, 'vehicle_damage_repair_job_id' => $job['id'], 'vehicle_damage_item_id' => $conditionRef, 'result_code' => 'unassessed', 'note' => $this->text($data['condition']['note'] ?? null, 2000), 'occurred_at' => null, 'withdrawn_at' => null, 'withdrawn_by' => null, 'withdrawal_reason' => null, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now]);
                $event = 'condition_added';
            }
        } elseif (in_array($action, ['withdraw', 'result', 'confirm', 'reopen_condition'], true)) {
            $member = $this->member($action === 'reopen_condition' ? $members : $active, $this->positive($data['membership_id'] ?? null));
            $memberRef = (int) $member['id'];
            $conditionRef = (int) $member['vehicle_damage_item_id'];
            $condition = $locked[$conditionRef];
            if ($action === 'withdraw') {
                $this->nonterminal($status);
                if (count($active) < 2) {
                    throw new InvalidArgumentException('Keep at least one active condition or cancel the job.');
                }
                $this->memberUpdate($company, $member, ['withdrawn_at' => $now, 'withdrawn_by' => $actor, 'withdrawal_reason' => $this->text($data['reason'] ?? '', 2000, true)], $actor, $now);
                $event = 'condition_withdrawn';
            } elseif ($action === 'result') {
                if ($job['started_at'] === null && $status !== 'completed') {
                    throw new InvalidArgumentException('Record performed work before assessing its result.');
                }
                if (in_array($status, ['cancelled', 'completed'], true)) {
                    $this->text($data['reason'] ?? '', 2000, true);
                }
                $this->checkFingerprint($condition, $data['expected_condition_state'] ?? '');
                $values = $this->resultValues($job, $member, $condition, $data);
                $this->memberUpdate($company, $member, $values, $actor, $now);
                $event = 'condition_result_recorded';
                $occurred = $values['occurred_at'];
            } elseif ($action === 'confirm') {
                if ($job['intent_code'] !== 'repair' || $job['started_at'] === null || ! in_array($status, ['in_progress', 'deferred', 'completed'], true)) {
                    throw new InvalidArgumentException('Confirmation requires a repair job with actual started work.');
                }
                $this->current($condition);
                $this->checkFingerprint($condition, $data['expected_condition_state'] ?? '');
                $this->confirmed($data['confirmed'] ?? null);
                $occurred = $this->time($data['inspected_at'] ?? null, true, true);
                if ($occurred < $job['started_at'] || $occurred < $condition['discovered_at']) {
                    throw new InvalidArgumentException('Inspection precedes the recorded work or discovery.');
                }
                $note = $this->text($data['inspection_note'] ?? '', 2000, true);
                $this->memberUpdate($company, $member, ['result_code' => 'repaired', 'note' => $note, 'occurred_at' => $occurred], $actor, $now);
                $physical = ['before' => $condition, 'values' => ['status_code' => 'repaired', 'resolved_by' => $actor, 'resolved_at' => $now, 'resolution_note' => $note, 'updated_by' => $actor, 'updated_at' => $now], 'event' => 'repaired', 'note' => $note];
                $event = 'condition_repair_confirmed';
            } else {
                if ((int) ($data['condition_id'] ?? 0) !== $conditionRef || $job['intent_code'] !== 'repair') {
                    throw new InvalidArgumentException('Choose the exact repair condition and work context.');
                }
                $physical = $this->reopenPhysical($condition, $data, $actor, $now, (int) $job['id'], $memberRef);
                $occurred = $physical['occurred_at'];
                $result = match ($data['reason_category_code']) {
                    'repair_failure' => 'failed', 'residual_damage' => 'partially_repaired', default => 'unassessed'
                };
                $this->memberUpdate($company, $member, ['result_code' => $result, 'note' => $physical['note'], 'occurred_at' => $occurred], $actor, $now);
                $event = 'condition_reopened';
            }
        } elseif ($action === 'complete') {
            if (! in_array($status, ['in_progress', 'deferred'], true) || $job['started_at'] === null) {
                throw new InvalidArgumentException('Complete only work that actually started.');
            }
            $outcomes = $data['outcomes'] ?? [];
            if (! is_array($outcomes) || count($outcomes) !== count($active)) {
                throw new InvalidArgumentException('Assess every active membership, with no omitted or extra conditions.');
            }
            $seen = [];
            foreach ($outcomes as $outcome) {
                if (! is_array($outcome)) {
                    throw new InvalidArgumentException('Supply valid outcomes.');
                }
                $member = $this->member($active, $this->positive($outcome['membership_id'] ?? null));
                if (isset($seen[$member['id']])) {
                    throw new InvalidArgumentException('Assess each active membership once.');
                }
                $seen[$member['id']] = true;
                $condition = $locked[(int) $member['vehicle_damage_item_id']];
                $this->checkFingerprint($condition, $outcome['expected_condition_state'] ?? '');
                $this->memberUpdate($company, $member, $this->resultValues($job, $member, $condition, $outcome), $actor, $now);
            }
            $occurred = $this->time($data['completed_at'] ?? null, false, true);
            if ($occurred === null && (string) ($data['completion_time_unknown'] ?? '') !== '1') {
                throw new InvalidArgumentException('Supply completion time or explicitly confirm that it is unknown.');
            }
            if ($occurred !== null && $occurred < $job['started_at']) {
                throw new InvalidArgumentException('Completion cannot precede started work.');
            }
            $job['status_code'] = 'completed';
            $job['completed_at'] = $occurred;
            $job['completion_note'] = $this->text($data['completion_note'] ?? '', 2000, true);
            $event = 'job_completed';
        } else {
            $next = match ($action) {
                'schedule' => 'scheduled', 'start' => 'in_progress', 'defer' => 'deferred', 'cancel' => 'cancelled', 'resume' => $data['target_status'] ?? '', 'reopen_job' => 'planned', default => ''
            };
            $allowed = ['planned' => ['scheduled', 'in_progress', 'deferred', 'cancelled'], 'scheduled' => ['scheduled', 'in_progress', 'deferred', 'cancelled'], 'in_progress' => ['deferred', 'cancelled'], 'deferred' => ['planned', 'scheduled', 'in_progress', 'cancelled'], 'completed' => ['planned'], 'cancelled' => ['planned']];
            if (! in_array($next, $allowed[$status] ?? [], true) || ($action === 'resume' && $status !== 'deferred') || ($action === 'reopen_job' && ! in_array($status, ['completed', 'cancelled'], true)) || ($action !== 'reopen_job' && in_array($status, ['completed', 'cancelled'], true))) {
                throw new InvalidArgumentException('Choose a valid next work status.');
            }
            if (in_array($action, ['defer', 'resume', 'cancel', 'reopen_job'], true)) {
                $this->text($data['reason'] ?? '', 2000, true);
            }
            if (in_array($action, ['start', 'resume', 'reopen_job'], true) && in_array($next, ['planned', 'scheduled', 'in_progress'], true)) {
                if (! array_any($active, static fn (array $m): bool => isset($locked[(int) $m['vehicle_damage_item_id']]) && in_array($locked[(int) $m['vehicle_damage_item_id']]['status_code'], ['open', 'accepted_unrepaired'], true))) {
                    throw new InvalidArgumentException('Continuing work requires an eligible current member condition.');
                }
            }
            if ($next === 'scheduled') {
                $job['scheduled_at'] = $this->time($data['scheduled_at'] ?? null, true);
            }
            if ($next === 'in_progress' && $job['started_at'] === null) {
                $job['started_at'] = $this->time($data['started_at'] ?? null, true, true);
                foreach ($active as $m) {
                    if ($job['started_at'] < $locked[(int) $m['vehicle_damage_item_id']]['discovered_at']) {
                        throw new InvalidArgumentException('Work start precedes recorded discovery.');
                    }
                }
                $occurred = $job['started_at'];
            }
            if ($action === 'resume' && $next === 'planned') {
                $job['scheduled_at'] = null;
            }
            if ($action === 'reopen_job') {
                $this->confirmed($data['same_work_order_confirmed'] ?? null);
                if (! isset(VehicleDamage::WORK_REOPEN_REASONS[$data['reason_category_code'] ?? '']) || ($data['reason_category_code'] === 'incorrect_cancellation' && $status !== 'cancelled')) {
                    throw new InvalidArgumentException('Choose a valid same-order reopening reason.');
                }
                foreach (['scheduled_at', 'started_at', 'completed_at', 'completion_note'] as $field) {
                    $job[$field] = null;
                }
            }
            $event = match ($action) {
                'schedule' => $status === 'scheduled' ? 'job_rescheduled' : 'job_scheduled', 'start' => 'job_started', 'defer' => 'job_deferred', 'resume' => 'job_resumed', 'cancel' => 'job_cancelled', 'reopen_job' => 'job_reopened', default => throw new InvalidArgumentException('Unknown work command.')
            };
            $job['status_code'] = $next;
        }
        return [$job, $event, $occurred, $physical, $memberRef, $conditionRef];
    }

    private function standaloneReopen(int $company, int $vehicle, array $condition, array $data, int $actor, string $key, string $hash): array
    {
        $rows = $this->repairs->standaloneReceipts($company, $vehicle, (int) $condition['id'], $key);
        if (count($rows) > 1) {
            throw new RuntimeException('Duplicate stored work receipts require audit review.');
        }
        foreach ($rows as $row) {
            $command = json_decode($row['new_values'] ?? '{}', true, 512, JSON_THROW_ON_ERROR)['b21_command'] ?? null;
            if (! is_array($command) || ! is_string($command['command_payload_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $command['command_payload_hash'])
                || ! is_array($command['receipt'] ?? null) || ($command['receipt']['success'] ?? null) !== true
                || (int) ($command['receipt']['id'] ?? 0) !== (int) $condition['id'] || ($command['receipt']['errors'] ?? null) !== []) {
                throw new RuntimeException('Stored work receipt is invalid. Review audit history before retrying.');
            }
            if ($command['command_key'] === $key) {
                if (! hash_equals($command['command_payload_hash'], $hash)) {
                    throw new InvalidArgumentException('Command key was already used with a different payload.');
                }
                $this->commit();
                return $command['receipt'] + ['replayed' => true];
            }
        }
        $physical = $this->reopenPhysical($condition, $data, $actor, date('Y-m-d H:i:s'), 0, 0);
        $receipt = ['success' => true, 'id' => (int) $condition['id'], 'errors' => []];
        $this->conditions->writeRepairStateInTransaction($condition, $physical['values'], 'repair_reopened', $physical['note'], $actor, $physical['occurred_at'], null, ['reason_category_code' => $data['reason_category_code'], 'b21_command' => ['command_key' => $key, 'command_payload_hash' => $hash, 'receipt' => $receipt]]);
        $this->commit();
        return $receipt + ['replayed' => false];
    }

    private function reopenPhysical(array $condition, array $data, int $actor, string $now, int $job, int $member): array
    {
        if (($condition['current_condition_item_id'] ?? null) !== null || $condition['status_code'] !== 'repaired') {
            throw new InvalidArgumentException('Only a repaired canonical condition can be reopened. New damage requires a new incident.');
        }
        $this->checkFingerprint($condition, $data['expected_condition_state'] ?? '');
        $this->confirmed($data['confirmed'] ?? null);
        if (! isset(VehicleDamage::REOPEN_REASONS[$data['reason_category_code'] ?? ''])) {
            throw new InvalidArgumentException('Choose repair failure, residual damage, or incorrect repair confirmation. New damage requires a new incident.');
        }
        $note = $this->text($data['note'] ?? '', 2000, true);
        $occurred = $this->time($data['observed_at'] ?? null, true, true);
        $latest = $this->repairs->latestConditionEvent((int) $condition['company_id'], (int) $condition['id'], 'repaired', true);
        if ($latest !== null && $occurred < $latest['occurred_at']) {
            throw new InvalidArgumentException('Reopening observation precedes the recorded repair.');
        }
        if (! empty($latest['repair_job_event_id'])) {
            $reference = $this->db->table('vehicle_damage_repair_job_events')->where('company_id', $condition['company_id'])->where('id', $latest['repair_job_event_id'])->get()->getRowArray();
            if ($reference === null || (int) $reference['vehicle_damage_repair_job_id'] !== $job || (int) $reference['vehicle_damage_repair_job_item_id'] !== $member) {
                throw new InvalidArgumentException('Select the exact B2 repair job and membership from the latest repair confirmation.');
            }
        }
        return ['before' => $condition, 'values' => ['status_code' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null, 'updated_by' => $actor, 'updated_at' => $now], 'event' => 'repair_reopened', 'note' => $note, 'occurred_at' => $occurred];
    }

    private function selection(array $selection, array $locked): array
    {
        $original = $locked[$this->positive($selection['selected_item_id'] ?? null)] ?? null;
        $id = $this->positive($selection['canonical_item_id'] ?? null);
        $canonical = $locked[$id] ?? null;
        if ($original === null || $canonical === null || (int) ($original['current_condition_item_id'] ?? $original['id']) !== $id) {
            throw new InvalidArgumentException('Canonical selection changed. Reload and explicitly confirm the displayed condition.');
        }
        $this->confirmed($selection['canonical_confirmed'] ?? null);
        $this->current($canonical);
        $this->checkFingerprint($canonical, $selection['expected_condition_state'] ?? '');
        return $canonical;
    }

    private function resultValues(array $job, array $member, array $condition, array $data): array
    {
        $result = $data['result_code'] ?? '';
        if (! isset(VehicleDamage::WORK_RESULTS[$result]) || ($job['intent_code'] === 'mitigation' && ! in_array($result, ['unassessed', 'unchanged', 'mitigated', 'failed'], true))) {
            throw new InvalidArgumentException('Choose a valid result for this work intent.');
        }
        if ($result === 'repaired') {
            if ($member['result_code'] !== 'repaired' || $condition['status_code'] !== 'repaired') {
                throw new InvalidArgumentException('Use explicit inspection confirmation to mark a condition repaired.');
            }
            return ['result_code' => 'repaired', 'note' => $member['note'], 'occurred_at' => $member['occurred_at']];
        }
        if ($member['result_code'] === 'repaired') {
            throw new InvalidArgumentException('Reopen the repaired condition explicitly before changing its confirmed outcome.');
        }
        $note = $this->text($data['note'] ?? '', 2000, true);
        $occurred = $this->time($data['occurred_at'] ?? null, false, true);
        if ($occurred !== null && ($occurred < $condition['discovered_at'] || ($job['started_at'] !== null && $occurred < $job['started_at']))) {
            throw new InvalidArgumentException('Result time precedes work or discovery.');
        }
        return ['result_code' => $result, 'note' => $note, 'occurred_at' => $occurred];
    }

    private function details(int $company, array $data): array
    {
        if (! isset(VehicleDamage::WORK_INTENTS[$data['intent_code'] ?? ''], VehicleDamage::WORK_CATEGORIES[$data['category_code'] ?? ''])) {
            throw new InvalidArgumentException('Choose valid work intent and category.');
        }
        $vendor = empty($data['vendor_company_id']) ? null : $this->positive($data['vendor_company_id']);
        $snapshot = $this->text($data['vendor_snapshot'] ?? null, 190);
        if ($vendor !== null) {
            $allowed = array_column($this->repairs->vendors($company), 'name', 'id');
            if (! isset($allowed[$vendor])) {
                throw new InvalidArgumentException('Vendor must already have an owned business relationship. Use a text snapshot instead.');
            }
            $snapshot ??= $allowed[$vendor];
        }
        return ['intent_code' => $data['intent_code'], 'category_code' => $data['category_code'], 'summary' => $this->text($data['summary'] ?? '', 190, true), 'vendor_company_id' => $vendor, 'vendor_snapshot' => $snapshot, 'vendor_order_reference' => $this->text($data['vendor_order_reference'] ?? null, 120)];
    }

    private function member(array $members, int $id): array
    {
        foreach ($members as $member) {
            if ((int) $member['id'] === $id) {
                return $member;
            }
        }
        throw new InvalidArgumentException('Active membership not found in this work job.');
    }

    private function memberUpdate(int $company, array $old, array $values, int $actor, string $now): void
    {
        foreach ($values as $field => $value) {
            if ($old[$field] !== $value) {
                $this->repairs->updateMember($company, (int) $old['id'], $values + ['updated_by' => $actor, 'updated_at' => $now]);
                return;
            }
        }
    }

    private function current(array $condition): void
    {
        if (($condition['current_condition_item_id'] ?? null) !== null || ! in_array($condition['status_code'], ['open', 'accepted_unrepaired'], true)) {
            throw new InvalidArgumentException('Choose a current canonical condition. Reopen residual damage explicitly or record a new incident.');
        }
    }

    private function checkFingerprint(array $condition, mixed $expected): void
    {
        if (! is_string($expected) || ! hash_equals($this->repairs->conditionFingerprint($condition, true), $expected)) {
            throw new InvalidArgumentException('Condition changed since preview. Reload and confirm again.');
        }
    }

    private function nonterminal(string $status): void
    {
        if (in_array($status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Reopen the work job before changing its scope.');
        }
    }

    private function confirmed(mixed $value): void
    {
        if ((string) $value !== '1') {
            throw new InvalidArgumentException('Explicit operator confirmation is required.');
        }
    }

    private function text(mixed $value, int $max, bool $required = false): ?string
    {
        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException('Text fields must contain text.');
        }
        $text = trim((string) $value);
        if (($required && $text === '') || mb_strlen($text) > $max) {
            throw new InvalidArgumentException('Supply required text within ' . $max . ' characters.');
        }
        return $text === '' ? null : $text;
    }

    private function positive(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Choose a positive record identifier.');
        }
        return (int) $value;
    }

    private function time(mixed $value, bool $required = false, bool $past = false): ?string
    {
        $value = $this->text($value, 30, $required);
        if ($value === null) {
            return null;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                $formatted = $date->format('Y-m-d H:i:s');
                if ($past && $formatted > date('Y-m-d H:i:s')) {
                    throw new InvalidArgumentException('Performed work and observations cannot be in the future.');
                }
                return $formatted;
            }
        }
        throw new InvalidArgumentException('Supply a valid local date and time.');
    }

    private function semantic(array $data): array
    {
        $allowed = ['expected_version', 'recording_mode', 'intent_code', 'category_code', 'summary', 'vendor_company_id', 'vendor_snapshot', 'vendor_order_reference', 'scheduled_at', 'started_at', 'completed_at', 'performed_at', 'inspected_at', 'observed_at', 'occurred_at', 'completion_note', 'completion_time_unknown', 'recording_reason', 'performed_confirmed', 'conditions', 'condition', 'outcomes', 'membership_id', 'condition_id', 'job_id', 'expected_condition_state', 'confirmed', 'inspection_note', 'reason', 'note', 'reason_category_code', 'target_status', 'same_work_order_confirmed', 'result_code'];
        $data = array_intersect_key($data, array_flip($allowed));
        $normalize = function (mixed $value, string $key = '') use (&$normalize): mixed {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $normalize($v, (string) $k);
                }
                if (array_is_list($value)) {
                    usort($value, static fn ($a, $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
                } else {
                    $value = array_filter($value, static fn (mixed $v): bool => $v !== null);
                    ksort($value);
                }
                return $value;
            }
            if ($value === null) {
                return null;
            }
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('Invalid command payload.');
            }
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/(_id|_confirmed|_unknown)$/', $key) || in_array($key, ['expected_version', 'confirmed'], true)) {
                return ctype_digit($value) ? (int) $value : $value;
            }
            if (str_ends_with($key, '_at')) {
                return $this->time($value);
            }
            return $value;
        };
        return $normalize($data);
    }

    private function audit(string $table, int $id, ?array $before, array $after, int $actor): void
    {
        $this->audits->record($actor, (new LookupRepository($this->db))->valueId('audit_action', $before === null ? 'created' : 'updated'), $table, $id, $before, $after);
    }

    private function begin(): void
    {
        $this->savepoint = null;
        if ($this->db->getPlatform() === 'SQLite3') {
            if ($this->db->query('BEGIN IMMEDIATE') === false) {
                throw new RuntimeException('Work state is busy. Reload and retry.');
            }
        } elseif (! $this->db->transBegin()) {
            throw new RuntimeException('Work transaction could not start.');
        } elseif ($this->db->transDepth > 1) {
            $this->savepoint = 'b21_' . bin2hex(random_bytes(8));
            if ($this->db->query('SAVEPOINT ' . $this->savepoint) === false) {
                $this->db->transRollback();
                throw new RuntimeException('Work transaction could not establish rollback ownership.');
            }
        }
    }
    private function commit(): void
    {
        if ($this->db->getPlatform() === 'SQLite3') {
            if ($this->db->query('COMMIT') === false) {
                throw new RuntimeException('Work transaction could not commit.');
            }
        } else {
            if (! $this->db->transStatus() || ($this->savepoint !== null && $this->db->query('RELEASE SAVEPOINT ' . $this->savepoint) === false) || ! $this->db->transCommit()) {
                throw new RuntimeException('Work transaction could not commit.');
            }
            $this->savepoint = null;
        }
    }
    private function rollback(): bool
    {
        if ($this->db->getPlatform() === 'SQLite3') {
            return $this->db->query('ROLLBACK') !== false;
        } else {
            if ($this->savepoint !== null && $this->db->transDepth > 1) {
                if ($this->db->query('ROLLBACK TO SAVEPOINT ' . $this->savepoint) === false
                    || $this->db->query('RELEASE SAVEPOINT ' . $this->savepoint) === false) {
                    return false;
                }
            }
            $rolledBack = $this->db->transRollback();
            if ($rolledBack && $this->db->transDepth === 0) {
                // Preserve failure when a caller still owns an outer transaction.
                $this->db->resetTransStatus();
            }
            $this->savepoint = null;
            return $rolledBack;
        }
    }
}
