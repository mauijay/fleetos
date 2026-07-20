<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroVehicleMappingIssueRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicleUnmatchedIssues(array $filters = []): array
    {
        $builder = $this->db->table('turo_import_errors errors')
            ->select('errors.id, errors.turo_import_batch_id, errors.raw_table, errors.raw_row_id, errors.row_number, errors.error_code, errors.message, errors.raw_payload, errors.created_at, errors.resolved_at, errors.reconciliation_status, errors.reconciliation_result_code, errors.reconciliation_message, errors.reconciled_at, errors.reconciled_trip_id')
            ->select('batches.source_filename, batches.started_at')
            ->join('turo_import_batches batches', 'batches.id = errors.turo_import_batch_id', 'left')
            ->where('errors.error_code', 'vehicle_unmatched');

        if (($filters['status'] ?? 'unmapped') === 'unmapped') {
            $builder->where('errors.resolved_at', null);
        } elseif (($filters['status'] ?? '') === 'mapped') {
            $builder->where('errors.resolved_at IS NOT NULL');
        }

        if ((int) ($filters['batch_id'] ?? 0) > 0) {
            $builder->where('errors.turo_import_batch_id', (int) $filters['batch_id']);
        }

        if (($filters['from'] ?? '') !== '') {
            $builder->where('errors.created_at >=', (string) $filters['from'] . ' 00:00:00');
        }

        if (($filters['to'] ?? '') !== '') {
            $builder->where('errors.created_at <=', (string) $filters['to'] . ' 23:59:59');
        }

        return $builder->orderBy('errors.created_at', 'DESC')->get()->getResultArray();
    }

    public function resolveRelated(string $turoVehicleId, ?string $note = null): int
    {
        $issues = $this->vehicleUnmatchedIssues(['status' => 'unmapped']);
        $ids = [];

        foreach ($issues as $issue) {
            $payload = json_decode((string) ($issue['raw_payload'] ?? ''), true);
            if (is_array($payload) && in_array(trim($turoVehicleId), $this->vehicleIds($payload), true)) {
                $ids[] = (int) $issue['id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        $this->db->table('turo_import_errors')
            ->whereIn('id', $ids)
            ->update([
                'resolved_at' => date('Y-m-d H:i:s'),
                'resolution_note' => $note === null || trim($note) === '' ? 'Resolved after Turo vehicle mapping was saved.' : trim($note),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->db->affectedRows();
    }

    /** @return array<int, string> */
    private function vehicleIds(array $payload): array
    {
        $ids = [];
        foreach (['vehicle_id', 'turo_vehicle_id', 'car_id'] as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $ids[] = trim((string) $payload[$key]);
            }
        }

        return $ids;
    }
}
