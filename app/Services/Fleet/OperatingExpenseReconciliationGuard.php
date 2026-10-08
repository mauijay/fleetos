<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Owns the outer source transaction. All ancestors are discovered before lower locks. */
class OperatingExpenseReconciliationGuard
{
    public function __construct(private readonly BaseConnection $db, private readonly ?\Closure $lockedCheckpoint = null)
    {
    }

    public function run(array $context, callable $callback): mixed
    {
        $records = new Records($this->db);
        $records->requireReady();
        if ($this->db->transDepth !== 0 || ($this->db->getPlatform() !== 'SQLite3' && (int) $this->db->query('SELECT @@in_transaction AS active')->getRowArray()['active'] !== 0)) {
            throw new RuntimeException('Expense reconciliation guard requires the outer transaction before metadata/source locks.');
        }
        $c = (int) ($context['company_id'] ?? 0);
        $actor = (int) ($context['actor'] ?? 0);
        if ($actor < 1 || $c < 1) {
            throw new InvalidArgumentException('An owned source and authenticated operator are required.');
        }
        $expenseId = (int) ($context['expense_id'] ?? 0);
        $receiptId = (int) ($context['receipt_id'] ?? 0);
        $receipt = $receiptId === 0 ? null : $this->db->table('operating_expense_receipts')->where(['company_id' => $c, 'id' => $receiptId])->get()->getRowArray();
        if ($receipt !== null && $receipt['operating_expense_id'] !== null && $expenseId !== 0 && $expenseId !== (int) $receipt['operating_expense_id']) {
            throw new RuntimeException('Attached receipt ownership is retained; reassignment is unavailable.');
        }
        $expenseId = $expenseId ?: (int) ($receipt['operating_expense_id'] ?? 0);
        $old = $expenseId === 0 ? null : $this->db->table('operating_expenses')->where(['company_id' => $c, 'id' => $expenseId])->get()->getRowArray();
        $vehicles = array_filter(array_unique([(int) ($old['fleet_vehicle_id'] ?? 0), (int) ($context['new_vehicle_id'] ?? 0)]));
        sort($vehicles, SORT_NUMERIC);
        $planned = $expenseId === 0 ? [] : $this->db->table(Records::TABLE)->where(['company_id' => $c, 'operating_expense_id' => $expenseId, 'status_code' => 'active'])->get()->getResultArray();
        $jobs = array_column($planned, 'vehicle_damage_repair_job_id');
        sort($jobs, SORT_NUMERIC);
        if ($this->db->getPlatform() !== 'SQLite3' && $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
            throw new RuntimeException('Expense guard could not establish current reads.');
        }
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Expense guard could not begin its outer transaction.');
        }
        try {
            foreach ($vehicles as $v) {
                (new VehicleDamageRepository($this->db))->lockVehicle($c, $v);
            }
            // Re-discover active jobs under vehicle lock BEFORE acquiring any lower row.
            $current = $expenseId === 0 ? [] : $this->db->table(Records::TABLE)->where(['company_id' => $c, 'operating_expense_id' => $expenseId, 'status_code' => 'active'])->get()->getResultArray();
            foreach ($current as $row) {
                if (! in_array((int) $row['fleet_vehicle_id'], $vehicles, true)) {
                    throw new RuntimeException('Source ownership changed outside the discovered vehicle plan. Reload and retry.');
                }
            }
            $jobs = array_unique([...$jobs, ...array_column($current, 'vehicle_damage_repair_job_id')]);
            sort($jobs, SORT_NUMERIC);
            $work = new VehicleDamageRepairRepository($this->db);
            $lockedJobs = [];
            foreach ($jobs as $j) {
                $v = (int) (($current[0] ?? $planned[0])['fleet_vehicle_id']);
                $lockedJobs[$j] = $work->job($c, $v, (int) $j, true) ?? throw new RuntimeException('Source job changed before locking.');
            }
            $helper = new VehicleDamageFinancialReconciliationService($this->db);
            if ($jobs !== []) {
                $conditions = array_unique(array_column($this->db->table('vehicle_damage_repair_job_items')->whereIn('vehicle_damage_repair_job_id', $jobs)->get()->getResultArray(), 'vehicle_damage_item_id'));
                sort($conditions, SORT_NUMERIC);
                foreach ($conditions as $id) {
                    (new VehicleDamageRepository($this->db))->lockItem($c, (int) ($current[0] ?? $planned[0])['fleet_vehicle_id'], (int) $id);
                }
                foreach ($lockedJobs as $j => $job) {
                    $work->members($c, (int) $job['fleet_vehicle_id'], (int) $j, true);
                }
                $helper->sources->lock($jobs, $c);
                $helper->cost->costs->lock($jobs, $c);
                $recovery = new \App\Repositories\VehicleDamageRepairRecoveryRepository($this->db);
                if ($recovery->present()) {
                    $recovery->requireReady();
                    $recovery->lock($jobs, $c);
                }
            }
            if ($expenseId !== 0) {
                $source = $records->lockingRows('operating_expenses', ['company_id' => $c, 'id' => $expenseId])[0] ?? throw new InvalidArgumentException('Operating expense not found.');
                if ((int) ($source['fleet_vehicle_id'] ?? 0) !== (int) ($old['fleet_vehicle_id'] ?? 0)) {
                    throw new RuntimeException('Source assignment changed. Reload and retry.');
                }
                $records->lockingRows('operating_expense_receipts', ['company_id' => $c, 'operating_expense_id' => $expenseId]);
            }
            if ($receiptId !== 0) {
                $lockedReceipt = $records->lockingRows('operating_expense_receipts', ['company_id' => $c, 'id' => $receiptId])[0] ?? throw new InvalidArgumentException('Receipt not found.');
                if ((int) ($lockedReceipt['operating_expense_id'] ?? 0) !== (int) ($receipt['operating_expense_id'] ?? 0)) {
                    throw new RuntimeException('Receipt assignment changed. Reload and retry.');
                }
            }
            $active = $expenseId === 0 ? [] : $records->lockingRows(Records::TABLE, ['company_id' => $c, 'operating_expense_id' => $expenseId, 'status_code' => 'active']);
            foreach ($active as $row) {
                if (! isset($lockedJobs[$row['vehicle_damage_repair_job_id']])) {
                    throw new RuntimeException('Undiscovered source ancestor. Reload and retry.');
                }
            }
            $ids = $expenseId === 0 ? [] : array_column($this->db->table('operating_expense_receipts')->where(['company_id' => $c, 'operating_expense_id' => $expenseId])->get()->getResultArray(), 'file_id');
            $helper->sources->lockMetadata($jobs, $c, $ids);
            $before = $helper->expenses($c, $expenseId === 0 ? [] : [$expenseId]);
            if ($this->lockedCheckpoint !== null) {
                ($this->lockedCheckpoint)();
            }
            $result = $callback();
            $after = $helper->expenses($c, $expenseId === 0 ? [] : [$expenseId]);
            if (($before[$expenseId]['fingerprint'] ?? null) !== ($after[$expenseId]['fingerprint'] ?? null)) {
                $now = date('Y-m-d H:i:s');
                foreach ($active as $row) {
                    $j = (int) $row['vehicle_damage_repair_job_id'];
                    $job = $lockedJobs[$j];
                    $records->invalidate($row, $actor, 'Operating expense identity, reportability or evidence changed.', $now);
                    $newRow = $this->db->table(Records::TABLE)->where('id', $row['id'])->get()->getRowArray();
                    $newJob = array_merge($job, ['version' => (int) $job['version'] + 1, 'updated_by' => $actor, 'updated_at' => $now]);
                    $work->updateJob($c, $j, $newJob);
                    $work->insertEvent(['company_id' => $c, 'vehicle_damage_repair_job_id' => $j, 'event_code' => 'repair_financial_reconciliation_invalidated', 'job_version' => $newJob['version'], 'actor_user_id' => $actor, 'recorded_at' => $now, 'reason' => $newRow['invalidation_reason'],
                        'before_json' => json_encode(['job' => $job, 'reconciliation' => $row], JSON_THROW_ON_ERROR), 'after_json' => json_encode(['job' => $newJob, 'members' => $work->members($c, (int) $job['fleet_vehicle_id'], $j), 'reconciliation' => $newRow], JSON_THROW_ON_ERROR), 'command_key' => VehicleDamageRepairService::commandKey(), 'command_payload_hash' => hash('sha256', json_encode([$row['id'], $before, $after], JSON_THROW_ON_ERROR))]);
                    $action = (new LookupRepository($this->db))->valueId('audit_action', 'updated');
                    $audit = new AuditLogRepository($this->db);
                    $audit->record($actor, $action, Records::TABLE, (int) $row['id'], $row, $newRow);
                    $audit->record($actor, $action, 'vehicle_damage_repair_jobs', $j, $job, $newJob);
                }
            }
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Expense guarded transaction failed.');
            }
            return $result;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
