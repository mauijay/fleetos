<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;
use Config\Database;

class TuroImportErrorRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function create(array $data): int
    {
        if (isset($data['raw_payload']) && is_array($data['raw_payload'])) {
            $data['raw_payload'] = json_encode($data['raw_payload'], JSON_THROW_ON_ERROR);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('turo_import_errors')->insert(array_merge($data, [
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ]));

        return (int) $this->db->insertID();
    }

    /** @return array<int, array<string, mixed>> */
    public function issues(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->filteredBuilder($filters)
            ->select('errors.*')
            ->select('severity.code AS severity_code, severity.name AS severity_name')
            ->select('batches.source_filename, batches.started_at, batches.completed_at, batches.row_count')
            ->orderBy('errors.resolved_at IS NULL', 'DESC', false)
            ->orderBy('errors.created_at', 'DESC')
            ->orderBy('errors.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();
    }

    public function issueCount(array $filters = []): int
    {
        return $this->filteredBuilder($filters)->countAllResults();
    }

    /** @return array<string, int> */
    public function unresolvedCountsBySeverity(): array
    {
        $rows = $this->db->table('turo_import_errors errors')
            ->select('severity.code AS severity_code, COUNT(*) AS issue_count')
            ->join('lookup_values severity', 'severity.id = errors.severity_lookup_value_id', 'left')
            ->where('errors.resolved_at', null)
            ->groupBy('severity.code')
            ->get()
            ->getResultArray();

        $counts = ['error' => 0, 'warning' => 0];

        foreach ($rows as $row) {
            $severity = (string) ($row['severity_code'] ?? 'error');
            if (array_key_exists($severity, $counts)) {
                $counts[$severity] += (int) $row['issue_count'];
            }
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> */
    public function batchesWithIssues(): array
    {
        return $this->db->table('turo_import_errors errors')
            ->select('batches.id, batches.source_filename, batches.started_at, COUNT(errors.id) AS issue_count')
            ->join('turo_import_batches batches', 'batches.id = errors.turo_import_batch_id')
            ->groupBy('batches.id, batches.source_filename, batches.started_at')
            ->orderBy('batches.started_at', 'DESC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, string> */
    public function issueCategories(): array
    {
        $rows = $this->db->table('turo_import_errors')
            ->select('error_code')
            ->groupBy('error_code')
            ->orderBy('error_code', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): string => (string) $row['error_code'], $rows);
    }

    public function resolve(int $id, ?string $note = null): bool
    {
        return $this->updateResolution($id, [
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolution_note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);
    }

    public function reopen(int $id, ?string $note = null): bool
    {
        return $this->updateResolution($id, [
            'resolved_at' => null,
            'resolution_note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);
    }

    public function recordReprocessAttempt(int $issueId, ?string $turoVehicleId, string $resultCode, string $message, ?int $tripId = null, ?int $actorUserId = null): void
    {
        $this->db->table('turo_import_reprocess_attempts')->insert([
            'turo_import_error_id' => $issueId,
            'turo_vehicle_id' => $turoVehicleId,
            'result_code' => $resultCode,
            'message' => $message,
            'turo_trip_normalized_id' => $tripId,
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('turo_import_errors')
            ->where('id', $issueId)
            ->set('last_reprocess_attempt_at', date('Y-m-d H:i:s'))
            ->set('reprocess_attempt_count', 'reprocess_attempt_count + 1', false)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->update();
    }

    public function markReconciled(int $issueId, string $resultCode, string $message, ?int $tripId = null, ?string $note = null): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('turo_import_errors')
            ->where('id', $issueId)
            ->update([
                'resolved_at' => $now,
                'resolution_note' => $note === null || trim($note) === '' ? $message : trim($note),
                'reconciliation_status' => 'reconciled',
                'reconciliation_result_code' => $resultCode,
                'reconciliation_message' => $message,
                'reconciled_at' => $now,
                'reconciled_trip_id' => $tripId,
                'updated_at' => $now,
            ]);

        return $this->db->affectedRows() > 0;
    }

    public function markReconciliationBlocked(int $issueId, string $resultCode, string $message): bool
    {
        $this->db->table('turo_import_errors')
            ->where('id', $issueId)
            ->update([
                'reconciliation_status' => in_array($resultCode, ['reprocessing_failed'], true) ? 'failed' : 'blocked',
                'reconciliation_result_code' => $resultCode,
                'reconciliation_message' => $message,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->db->affectedRows() > 0;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('turo_import_errors errors')
            ->select('errors.*')
            ->select('severity.code AS severity_code, severity.name AS severity_name')
            ->select('batches.source_filename, batches.started_at, batches.completed_at, batches.row_count')
            ->join('lookup_values severity', 'severity.id = errors.severity_lookup_value_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = errors.turo_import_batch_id')
            ->where('errors.id', $id)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    private function filteredBuilder(array $filters): BaseBuilder
    {
        $builder = $this->db->table('turo_import_errors errors')
            ->join('lookup_values severity', 'severity.id = errors.severity_lookup_value_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = errors.turo_import_batch_id');

        if (($filters['status'] ?? 'unresolved') === 'resolved') {
            $builder->where('errors.resolved_at IS NOT NULL');
        } elseif (($filters['status'] ?? 'unresolved') !== 'all') {
            $builder->where('errors.resolved_at', null);
        }

        if (in_array($filters['severity'] ?? '', ['error', 'warning'], true)) {
            $builder->where('severity.code', $filters['severity']);
        }

        if ((int) ($filters['batch_id'] ?? 0) > 0) {
            $builder->where('errors.turo_import_batch_id', (int) $filters['batch_id']);
        }

        if (($filters['category'] ?? '') !== '') {
            $builder->where('errors.error_code', (string) $filters['category']);
        }

        if (($filters['vehicle'] ?? '') !== '') {
            $vehicle = trim((string) $filters['vehicle']);
            $builder->groupStart()
                ->like('errors.raw_payload', $vehicle)
                ->orLike('errors.message', $vehicle)
                ->groupEnd();
        }

        if (($filters['from'] ?? '') !== '') {
            $builder->where('errors.created_at >=', (string) $filters['from'] . ' 00:00:00');
        }

        if (($filters['to'] ?? '') !== '') {
            $builder->where('errors.created_at <=', (string) $filters['to'] . ' 23:59:59');
        }

        return $builder;
    }

    private function updateResolution(int $id, array $data): bool
    {
        $this->db->table('turo_import_errors')
            ->where('id', $id)
            ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));

        return $this->db->affectedRows() > 0;
    }
}
