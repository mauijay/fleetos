<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class VehicleDamageRepairRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function ready(): bool
    {
        $fields = [
            'vehicle_damage_repair_jobs' => 'id company_id fleet_vehicle_id intent_code category_code summary status_code vendor_company_id vendor_snapshot vendor_order_reference scheduled_at started_at completed_at completion_note version creation_command_key creation_command_payload_hash created_by updated_by created_at updated_at',
            'vehicle_damage_repair_job_items' => 'id company_id vehicle_damage_repair_job_id vehicle_damage_item_id result_code note occurred_at withdrawn_at withdrawn_by withdrawal_reason created_by updated_by created_at updated_at',
            'vehicle_damage_repair_job_events' => 'id company_id vehicle_damage_repair_job_id vehicle_damage_repair_job_item_id vehicle_damage_item_id event_code job_version actor_user_id recorded_at occurred_at reason_category_code reason before_json after_json command_key command_payload_hash',
            'vehicle_damage_item_events' => 'repair_job_event_id',
        ];
        foreach ($fields as $table => $required) {
            if (! $this->db->tableExists($table) || array_diff(explode(' ', $required), $this->db->getFieldNames($table)) !== []) {
                return false;
            }
        }
        $requiredIndexes = [
            'vehicle_damage_repair_jobs' => [
                'repair_jobs_company_id_uq' => ['UNIQUE', ['company_id', 'id']],
                'repair_jobs_creation_command_uq' => ['UNIQUE', ['company_id', 'creation_command_key']],
                'repair_jobs_vehicle_status_idx' => ['INDEX', ['company_id', 'fleet_vehicle_id', 'status_code', 'id']],
                'repair_jobs_vendor_idx' => ['INDEX', ['vendor_company_id']],
            ],
            'vehicle_damage_repair_job_items' => [
                'repair_items_job_condition_uq' => ['UNIQUE', ['vehicle_damage_repair_job_id', 'vehicle_damage_item_id']],
                'repair_items_company_id_uq' => ['UNIQUE', ['company_id', 'id']],
                'repair_items_condition_idx' => ['INDEX', ['company_id', 'vehicle_damage_item_id', 'vehicle_damage_repair_job_id']],
                'repair_items_job_active_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'withdrawn_at', 'id']],
            ],
            'vehicle_damage_repair_job_events' => [
                'repair_events_job_version_uq' => ['UNIQUE', ['vehicle_damage_repair_job_id', 'job_version']],
                'repair_events_command_uq' => ['UNIQUE', ['company_id', 'command_key']],
                'repair_events_history_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_id', 'id']],
                'repair_events_condition_idx' => ['INDEX', ['company_id', 'vehicle_damage_item_id', 'id']],
                'repair_events_member_idx' => ['INDEX', ['company_id', 'vehicle_damage_repair_job_item_id', 'id']],
            ],
            'vehicle_damage_item_events' => ['damage_event_repair_job_event_idx' => ['INDEX', ['repair_job_event_id']]],
        ];
        foreach ($requiredIndexes as $table => $required) {
            $indexes = $this->db->getIndexData($table);
            foreach ($required as $name => [$type, $columns]) {
                if (! isset($indexes[$name]) || strtoupper($indexes[$name]->type) !== $type || $indexes[$name]->fields !== $columns) {
                    return false;
                }
            }
        }
        $requiredForeignKeys = [
            'vehicle_damage_repair_jobs' => [
                [['company_id'], 'companies', ['id']],
                [['fleet_vehicle_id'], 'fleet_vehicles', ['id']],
                [['vendor_company_id'], 'companies', ['id']],
            ],
            'vehicle_damage_repair_job_items' => [
                [['company_id'], 'companies', ['id']],
                [['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id']],
                [['vehicle_damage_item_id'], 'vehicle_damage_items', ['id']],
            ],
            'vehicle_damage_repair_job_events' => [
                [['company_id'], 'companies', ['id']],
                [['company_id', 'vehicle_damage_repair_job_id'], 'vehicle_damage_repair_jobs', ['company_id', 'id']],
                [['company_id', 'vehicle_damage_repair_job_item_id'], 'vehicle_damage_repair_job_items', ['company_id', 'id']],
                [['vehicle_damage_item_id'], 'vehicle_damage_items', ['id']],
            ],
            'vehicle_damage_item_events' => [[['repair_job_event_id'], 'vehicle_damage_repair_job_events', ['id']]],
        ];
        foreach ($requiredForeignKeys as $table => $required) {
            $foreignKeys = $this->db->getForeignKeyData($table);
            foreach ($required as [$columns, $target, $targetColumns]) {
                if (! array_any($foreignKeys, fn ($key): bool => $key->column_name === $columns
                    && $key->foreign_table_name === $this->db->prefixTable($target)
                    && $key->foreign_column_name === $targetColumns
                    && strtoupper($key->on_delete) === 'RESTRICT' && strtoupper($key->on_update) === 'CASCADE')) {
                    return false;
                }
            }
        }
        return true;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Repair/work schema is incomplete. Apply the approved B2.1 migration before enabling writes.');
        }
    }

    public function job(int $company, int $vehicle, int $id, bool $lock = false): ?array
    {
        $builder = $this->db->table('vehicle_damage_repair_jobs jobs')->select('jobs.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('jobs.company_id', $company)->where('jobs.fleet_vehicle_id', $vehicle)->where('jobs.id', $id);
        return $this->rows($builder->getCompiledSelect(), $lock)[0] ?? null;
    }

    public function members(int $company, int $vehicle, int $job, bool $lock = false): array
    {
        if ($lock) {
            if ($this->job($company, $vehicle, $job, true) === null) {
                return [];
            }
            // The owned vehicle/job has already been locked. Do not let a joined
            // locking scan acquire another aggregate's membership or parent rows.
            $table = $this->db->escapeIdentifiers($this->db->prefixTable('vehicle_damage_repair_job_items'));
            $index = $this->db->getPlatform() === 'SQLite3' ? '' : ' FORCE INDEX (repair_items_job_active_idx)';
            return $this->rows('SELECT * FROM ' . $table . $index . ' WHERE company_id = ' . $company
                . ' AND vehicle_damage_repair_job_id = ' . $job . ' ORDER BY id', true);
        }
        $builder = $this->db->table('vehicle_damage_repair_job_items members')->select('members.*')
            ->join('vehicle_damage_repair_jobs jobs', 'jobs.id = members.vehicle_damage_repair_job_id AND jobs.company_id = members.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('members.company_id', $company)->where('members.vehicle_damage_repair_job_id', $job)
            ->where('jobs.fleet_vehicle_id', $vehicle)->where('jobs.id', $job)->orderBy('members.id');
        return $this->rows($builder->getCompiledSelect(), $lock);
    }

    public function events(int $company, int $vehicle, int $job): array
    {
        if ($this->job($company, $vehicle, $job) === null) {
            return [];
        }
        return $this->db->table('vehicle_damage_repair_job_events')->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)->orderBy('id')->get()->getResultArray();
    }

    public function replay(int $company, string $key): ?array
    {
        $sql = $this->db->table('vehicle_damage_repair_job_events')->where('company_id', $company)->where('command_key', $key)->getCompiledSelect();
        return $this->rows($sql, true)[0] ?? null;
    }

    public function hasPerformedWork(int $company, int $vehicle, int $job): bool
    {
        if ($this->job($company, $vehicle, $job) === null) {
            return false;
        }
        $sql = $this->db->table('vehicle_damage_repair_job_events')->select('id')->where('company_id', $company)->where('vehicle_damage_repair_job_id', $job)
            ->groupStart()->whereIn('event_code', ['job_started', 'historical_mitigation_recorded'])
            ->orGroupStart()->where('event_code', 'job_resumed')->where('occurred_at IS NOT NULL', null, false)->groupEnd()->groupEnd()->limit(1)->getCompiledSelect();
        return $this->rows($sql, true) !== [];
    }

    public function event(int $company, int $vehicle, int $id): ?array
    {
        return $this->db->table('vehicle_damage_repair_job_events events')->select('events.*')
            ->join('vehicle_damage_repair_jobs jobs', 'jobs.id = events.vehicle_damage_repair_job_id AND jobs.company_id = events.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('events.company_id', $company)->where('jobs.fleet_vehicle_id', $vehicle)->where('events.id', $id)->get()->getRowArray();
    }

    public function condition(int $company, int $vehicle, int $id, bool $lock = false): ?array
    {
        $sql = $this->db->table('vehicle_damage_items')->where('company_id', $company)->where('fleet_vehicle_id', $vehicle)->where('id', $id)->getCompiledSelect();
        return $this->rows($sql, $lock)[0] ?? null;
    }

    public function latestConditionEvent(int $company, int $condition, ?string $code = null, bool $lock = false): ?array
    {
        $builder = $this->db->table('vehicle_damage_item_events')->where('company_id', $company)->where('vehicle_damage_item_id', $condition);
        if ($code !== null) {
            $builder->where('event_code', $code);
        }
        return $this->rows($builder->orderBy('id', 'DESC')->limit(1)->getCompiledSelect(), $lock)[0] ?? null;
    }

    public function conditionFingerprint(array $condition, bool $lock = false): string
    {
        return $this->fingerprint($condition, (string) ($this->latestConditionEvent((int) $condition['company_id'], (int) $condition['id'], null, $lock)['id'] ?? ''));
    }

    public function fingerprints(int $company, array $conditions): array
    {
        if ($conditions === []) {
            return [];
        }
        $events = $this->db->table('vehicle_damage_item_events')->select('vehicle_damage_item_id, MAX(id) AS latest_id')->where('company_id', $company)
            ->whereIn('vehicle_damage_item_id', array_column($conditions, 'id'))->groupBy('vehicle_damage_item_id')->get()->getResultArray();
        $latest = array_column($events, 'latest_id', 'vehicle_damage_item_id');
        $result = [];
        foreach ($conditions as $condition) {
            $result[$condition['id']] = $this->fingerprint($condition, (string) ($latest[$condition['id']] ?? ''));
        }
        return $result;
    }

    private function fingerprint(array $condition, string $latest): string
    {
        $fields = ['id', 'company_id', 'fleet_vehicle_id', 'current_condition_item_id', 'zone_code', 'panel_code', 'damage_type_code', 'description', 'severity_code', 'status_code', 'discovered_at', 'resolved_by', 'resolved_at', 'resolution_note', 'updated_by', 'updated_at'];
        $state = [];
        foreach ($fields as $field) {
            $state[$field] = isset($condition[$field]) ? (string) $condition[$field] : null;
        }
        $state['latest_event_id'] = $latest;
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function hasAnyMembershipForCondition(int $company, int $vehicle, int $condition): bool
    {
        $this->requireReady();
        $sql = $this->db->table('vehicle_damage_repair_job_items members')->select('members.id')
            ->join('vehicle_damage_repair_jobs jobs', 'jobs.id = members.vehicle_damage_repair_job_id AND jobs.company_id = members.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('members.company_id', $company)->where('jobs.fleet_vehicle_id', $vehicle)->where('members.vehicle_damage_item_id', $condition)->limit(1)->getCompiledSelect();
        return $this->rows($sql, true) !== [];
    }

    public function workspace(int $company, int $vehicle): array
    {
        if (! $this->ready()) {
            return ['ready' => false, 'jobs' => [], 'members' => []];
        }
        $jobs = $this->db->table('vehicle_damage_repair_jobs jobs')->select('jobs.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('jobs.company_id', $company)->where('jobs.fleet_vehicle_id', $vehicle)->orderBy('jobs.id', 'DESC')->get()->getResultArray();
        $members = $this->db->table('vehicle_damage_repair_job_items members')->select('members.*')
            ->join('vehicle_damage_repair_jobs jobs', 'jobs.id = members.vehicle_damage_repair_job_id AND jobs.company_id = members.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id AND vehicles.deleted_at IS NULL')
            ->where('members.company_id', $company)->where('jobs.fleet_vehicle_id', $vehicle)->orderBy('members.id')->get()->getResultArray();
        return ['ready' => true, 'jobs' => $jobs, 'members' => $members];
    }

    public function vendors(int $company): array
    {
        $maintenance = $this->db->table('maintenance_logs maintenance')->select('maintenance.vendor_company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = maintenance.fleet_vehicle_id')->where('vehicles.company_id', $company)
            ->where('vehicles.deleted_at', null)->where('maintenance.deleted_at', null)->where('maintenance.vendor_company_id IS NOT NULL', null, false)->getCompiledSelect();
        $jobs = $this->db->table('vehicle_damage_repair_jobs jobs')->select('jobs.vendor_company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = jobs.fleet_vehicle_id AND vehicles.company_id = jobs.company_id')->where('jobs.company_id', $company)->where('vehicles.deleted_at', null)
            ->where('jobs.vendor_company_id IS NOT NULL', null, false)->getCompiledSelect();
        return $this->db->table('companies vendor')->select('vendor.id, vendor.name')
            ->join('lookup_values type', 'type.id = vendor.company_type_lookup_value_id')->join('lookup_types types', 'types.id = type.lookup_type_id')
            ->where('types.code', 'company_type')->where('type.code', 'vendor')->where('vendor.is_active', true)->where('vendor.deleted_at', null)
            ->where('vendor.id IN (' . $maintenance . ' UNION ' . $jobs . ')', null, false)->orderBy('vendor.name')->get()->getResultArray();
    }

    public function standaloneReceipts(int $company, int $vehicle, int $condition, string $key): array
    {
        $extract = "JSON_EXTRACT(audit.new_values, '$.b21_command.command_key')";
        if ($this->db->getPlatform() !== 'SQLite3') {
            $extract = 'JSON_UNQUOTE(' . $extract . ')';
        }
        $sql = $this->db->table('audit_logs audit')->select('audit.new_values')
            ->join('vehicle_damage_items damage', "damage.id = audit.record_id AND audit.table_name = 'vehicle_damage_items'")
            ->where('damage.company_id', $company)->where('damage.fleet_vehicle_id', $vehicle)->where('damage.id', $condition)
            ->where('CASE WHEN JSON_VALID(audit.new_values) THEN ' . $extract . ' ELSE NULL END = ' . $this->db->escape($key), null, false)
            ->orderBy('audit.id')->limit(2)->getCompiledSelect();
        return $this->rows($sql, true);
    }

    public function insertJob(array $values): int
    {
        return $this->insert('vehicle_damage_repair_jobs', $values);
    }
    public function insertMember(array $values): int
    {
        return $this->insert('vehicle_damage_repair_job_items', $values);
    }
    public function insertEvent(array $values): int
    {
        return $this->insert('vehicle_damage_repair_job_events', $values);
    }
    public function updateJob(int $company, int $id, array $values): void
    {
        $this->update('vehicle_damage_repair_jobs', $company, $id, $values);
    }
    public function updateMember(int $company, int $id, array $values): void
    {
        $this->update('vehicle_damage_repair_job_items', $company, $id, $values);
    }

    private function rows(string $sql, bool $lock): array
    {
        $result = $this->db->query($sql . ($lock && $this->db->getPlatform() !== 'SQLite3' ? ' FOR UPDATE' : ''));
        if ($result === false) {
            throw new RuntimeException('Work state is locked. Reload and retry.');
        }
        return $result->getResultArray();
    }

    private function insert(string $table, array $values): int
    {
        if (! $this->db->table($table)->insert($values)) {
            throw new RuntimeException('Work history could not be recorded.');
        }
        return (int) $this->db->insertID();
    }

    private function update(string $table, int $company, int $id, array $values): void
    {
        if (! $this->db->table($table)->where('company_id', $company)->where('id', $id)->update($values)) {
            throw new RuntimeException('Work update failed.');
        }
    }
}
