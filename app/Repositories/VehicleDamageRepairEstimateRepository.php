<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class VehicleDamageRepairEstimateRepository
{
    public const ESTIMATES = 'vehicle_damage_repair_estimates';
    public const SCOPE = 'vehicle_damage_repair_estimate_items';
    public const DOCUMENTS = 'vehicle_damage_repair_documents';
    public const FIELDS = [
        self::ESTIMATES => 'id company_id vehicle_damage_repair_job_id quote_series_key revision_number previous_estimate_id superseded_by_estimate_id amount currency status_code recording_mode vendor_company_id vendor_snapshot quote_date expires_at expires_on vendor_quote_reference note historical_recording_reason created_by updated_by created_at updated_at',
        self::SCOPE => 'id company_id vehicle_damage_repair_job_id vehicle_damage_repair_estimate_id vehicle_damage_repair_job_item_id condition_snapshot created_by created_at',
        self::DOCUMENTS => 'id company_id vehicle_damage_repair_job_id vehicle_damage_repair_estimate_id vehicle_damage_repair_job_item_id kind_code file_id image_id external_reference label note content_checksum content_mime_type content_size_bytes content_original_filename archived_at archived_by archive_reason created_by updated_by created_at updated_at',
    ];
    public const INDEXES = [
        self::ESTIMATES => [
            'b22_est_company_id_uq' => ['UNIQUE', ['company_id', 'id']],
            'b22_est_context_id_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'id']],
            'b22_est_series_revision_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'quote_series_key', 'revision_number']],
            'b22_est_job_status_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'status_code', 'id']],
            'b22_est_vendor_idx' => ['INDEX', ['vendor_company_id']],
            'b22_est_previous_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'previous_estimate_id']],
            'b22_est_replacement_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'superseded_by_estimate_id']],
        ],
        self::SCOPE => [
            'b22_scope_est_member_uq' => ['UNIQUE', ['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id']],
            'b22_scope_est_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_estimate_id', 'id']],
            'b22_scope_member_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id', 'id']],
        ],
        self::DOCUMENTS => [
            'b22_doc_company_id_uq' => ['UNIQUE', ['company_id', 'id']],
            'b22_doc_history_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'archived_at', 'id']],
            'b22_doc_estimate_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_estimate_id', 'id']],
            'b22_doc_member_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'vehicle_damage_repair_job_item_id', 'id']],
            'b22_doc_scope_idx' => ['INDEX', ['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id']],
            'b22_doc_file_id_idx' => ['INDEX', ['file_id']], 'b22_doc_image_id_idx' => ['INDEX', ['image_id']],
        ],
        'vehicle_damage_repair_job_items' => ['b22_member_context_uq' => ['UNIQUE', ['company_id', 'vehicle_damage_repair_job_id', 'id']]],
        'vehicle_damage_repair_jobs' => [
            'b22_job_accepted_idx' => ['INDEX', ['accepted_estimate_id']],
            'b22_job_accepted_context_idx' => ['INDEX', ['company_id', 'id', 'accepted_estimate_id']],
        ],
    ];

    private BaseConnection $db;
    private VehicleDamageRepairRepository $work;

    public function __construct(?BaseConnection $db = null, private readonly bool $locking = false)
    {
        $this->db = $db ?? Database::connect();
        $this->work = new VehicleDamageRepairRepository($this->db);
    }

    public function ready(): bool
    {
        if (! $this->work->ready() || ! $this->db->fieldExists('accepted_estimate_id', 'vehicle_damage_repair_jobs')) {
            return false;
        }
        foreach (self::FIELDS as $table => $fields) {
            if (! $this->db->tableExists($table) || array_diff(explode(' ', $fields), $this->db->getFieldNames($table)) !== []) {
                return false;
            }
        }
        foreach (self::INDEXES as $table => $requirements) {
            $indexes = $this->db->getIndexData($table);
            foreach ($requirements as $name => [$type, $fields]) {
                if (! isset($indexes[$name]) || strtoupper($indexes[$name]->type) !== $type || $indexes[$name]->fields !== $fields) {
                    return false;
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $requirements) {
            $actual = $this->db->getForeignKeyData($table);
            foreach ($requirements as [$columns, $target, $fields]) {
                if (! array_any($actual, fn ($key): bool => $key->column_name === $columns && $key->foreign_table_name === $this->db->prefixTable($target) && $key->foreign_column_name === $fields && strtoupper($key->on_update) === 'RESTRICT' && strtoupper($key->on_delete) === 'RESTRICT')) {
                    return false;
                }
            }
        }
        if ($this->db->getPlatform() === 'SQLite3') {
            foreach (['insert', 'update'] as $suffix) {
                $name = $this->db->prefixTable('b22_job_accepted_' . $suffix);
                $row = $this->db->query('SELECT sql FROM sqlite_master WHERE type=\'trigger\' AND name=?', [$name])->getRowArray();
                $normalize = static fn (string $sql): string => strtolower(preg_replace('/[\s`"\[\]]+/', '', $sql) ?? '');
                $j = $this->db->prefixTable('vehicle_damage_repair_jobs');
                $e = $this->db->prefixTable(self::ESTIMATES);
                $expected = "CREATE TRIGGER $name BEFORE $suffix ON $j WHEN NEW.accepted_estimate_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $e WHERE id=NEW.accepted_estimate_id AND company_id=NEW.company_id AND vehicle_damage_repair_job_id=NEW.id) BEGIN SELECT RAISE(ABORT, 'Accepted estimate must belong to this company and job'); END";
                if ($row === null || $normalize($row['sql']) !== $normalize($expected)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function foreignKeys(): array
    {
        $context = ['company_id', 'vehicle_damage_repair_job_id'];
        $keys = [
            self::ESTIMATES => [[['company_id'], 'companies', ['id']], [$context, 'vehicle_damage_repair_jobs', ['company_id', 'id']], [['vendor_company_id'], 'companies', ['id']]],
            self::SCOPE => [[['company_id'], 'companies', ['id']], [[...$context, 'vehicle_damage_repair_estimate_id'], self::ESTIMATES, [...$context, 'id']], [[...$context, 'vehicle_damage_repair_job_item_id'], 'vehicle_damage_repair_job_items', [...$context, 'id']]],
            self::DOCUMENTS => [
                [['company_id'], 'companies', ['id']], [$context, 'vehicle_damage_repair_jobs', ['company_id', 'id']],
                [[...$context, 'vehicle_damage_repair_estimate_id'], self::ESTIMATES, [...$context, 'id']],
                [[...$context, 'vehicle_damage_repair_job_item_id'], 'vehicle_damage_repair_job_items', [...$context, 'id']],
                [['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id'], self::SCOPE, ['vehicle_damage_repair_estimate_id', 'vehicle_damage_repair_job_item_id']],
                [['file_id'], 'files', ['id']], [['image_id'], 'images', ['id']],
            ],
        ];
        foreach (['previous_estimate_id', 'superseded_by_estimate_id'] as $column) {
            $keys[self::ESTIMATES][] = [[...$context, $column], self::ESTIMATES, [...$context, 'id']];
        }
        $keys['vehicle_damage_repair_jobs'] = $this->db->getPlatform() === 'SQLite3'
            ? [[['accepted_estimate_id'], self::ESTIMATES, ['id']]]
            : [[['company_id', 'id', 'accepted_estimate_id'], self::ESTIMATES, [...$context, 'id']]];
        return $keys;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Estimate/document schema is incomplete. Apply the approved B2.2 migration before enabling these commands.');
        }
    }

    public function estimates(int $company, int $vehicle, int $job, bool $lock = false): array
    {
        if ($this->work->job($company, $vehicle, $job) === null) {
            return [];
        }
        return $this->rows($this->db->table(self::ESTIMATES)->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->orderBy('id')->getCompiledSelect(), $lock);
    }

    public function estimate(int $company, int $vehicle, int $job, int $id): ?array
    {
        foreach ($this->estimates($company, $vehicle, $job) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function scope(int $company, int $job, ?int $estimate = null, bool $lock = false): array
    {
        $owned = $this->db->table('vehicle_damage_repair_jobs jobs')->select('jobs.id')->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')->where('jobs.company_id', $company)->where('jobs.id', $job)->get()->getRowArray();
        if ($owned === null) {
            return [];
        }
        $builder = $this->db->table(self::SCOPE)->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->orderBy('id');
        if ($estimate !== null) {
            $builder->where('vehicle_damage_repair_estimate_id', $estimate);
        }
        return $this->rows($builder->getCompiledSelect(), $lock);
    }

    public function fingerprint(int $company, int $vehicle, int $job, int $estimate): string
    {
        $row = $this->estimate($company, $vehicle, $job, $estimate);
        if ($row === null) {
            throw new RuntimeException('Estimate not found in this work context.');
        }
        $scope = $this->scope($company, $job, $estimate);
        $ids = array_column($scope, 'vehicle_damage_repair_job_item_id');
        $members = array_values(array_filter($this->work->members($company, $vehicle, $job, $this->locking), fn ($m) => in_array($m['id'], $ids, false)));
        $documents = new VehicleDamageRepairDocumentRepository($this->db, $this->locking);
        $sources = array_values(array_filter($documents->documents($company, $vehicle, $job), fn ($d) => (int) $d['vehicle_damage_repair_estimate_id'] === $estimate));
        $sourceState = array_map(fn ($d) => ['document' => $d, 'metadata' => $documents->metadata($d)], $sources);
        $conditions = [];
        foreach ($members as $member) {
            $condition = $this->work->condition($company, $vehicle, (int) $member['vehicle_damage_item_id'], $this->locking);
            $conditions[] = $condition === null ? null : $this->work->conditionFingerprint($condition, $this->locking);
        }
        return self::digest([$row, $scope, $members, $conditions, $sourceState, $this->latestRelatedEvent($company, $vehicle, $job, 'estimate_ids', $estimate)]);
    }

    public function latestRelatedEvent(int $company, int $vehicle, int $job, string $field, int $id): int
    {
        foreach (array_reverse($this->work->events($company, $vehicle, $job)) as $event) {
            $after = json_decode($event['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (in_array($id, $after['b22'][$field] ?? [], true)) {
                return (int) $event['id'];
            }
        }
        return 0;
    }

    public static function digest(array $state): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                $value = array_map($normalize, $value);
                if (! array_is_list($value)) {
                    ksort($value);
                }
                return $value;
            }
            return $value === null ? null : (string) $value;
        };
        return hash('sha256', json_encode($normalize($state), JSON_THROW_ON_ERROR));
    }

    public function insertEstimate(array $values): int
    {
        return $this->insert(self::ESTIMATES, $values);
    }
    public function insertScope(array $values): int
    {
        return $this->insert(self::SCOPE, $values);
    }

    public function disposition(int $company, int $job, int $id, array $values): void
    {
        if (array_diff(array_keys($values), ['status_code', 'superseded_by_estimate_id', 'updated_by', 'updated_at']) !== []) {
            throw new RuntimeException('Quoted facts are immutable. Record a new revision.');
        }
        if (! $this->db->table(self::ESTIMATES)->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->where('id', $id)->update($values)) {
            throw new RuntimeException('Estimate disposition could not be recorded.');
        }
    }

    private function insert(string $table, array $values): int
    {
        if (! $this->db->table($table)->insert($values)) {
            throw new RuntimeException('Estimate provenance could not be recorded.');
        }
        return (int) $this->db->insertID();
    }

    private function rows(string $sql, bool $lock): array
    {
        $result = $this->db->query($sql . (($lock || $this->locking) && $this->db->getPlatform() !== 'SQLite3' ? ' FOR UPDATE' : ''));
        if ($result === false) {
            throw new RuntimeException('Estimate state is locked. Reload and retry.');
        }
        return $result->getResultArray();
    }
}
