<?php

namespace App\Repositories;

use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Authorization\PermissionMatcher;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

/** Bounded reads and existing-table locks for the privileged repair transaction. */
class HistoricalMovementRepairRepository
{
    public function __construct(public readonly BaseConnection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table, array $where, bool $lock = false, int $limit = 5000): array
    {
        $sql = $this->db->table($table)->where($where)->orderBy('id')->limit($limit + 1)->getCompiledSelect();
        return $this->readRows($sql, $table, $lock, $limit);
    }

    private function readRows(string $sql, string $table, bool $lock, int $limit): array
    {
        if ($lock && $this->db->DBDriver === 'MySQLi') {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->db->query($sql)->getResultArray();
        if (count($rows) > $limit) {
            throw new RuntimeException('Repair inspection limit exceeded: ' . $table);
        }
        return $rows;
    }

    private function tripRows(string $table, array $tripIds, bool $lock = false): array
    {
        if ($tripIds === []) {
            return [];
        }
        $sql = $this->db->table($table)->whereIn('turo_trip_normalized_id', $tripIds)->orderBy('id')->limit(5001)->getCompiledSelect();
        return $this->readRows($sql, $table, $lock, 5000);
    }

    public function authorize(int $actor, bool $lock): void
    {
        $authConfig = Factories::config('Auth');
        $groupConfig = Factories::config('AuthGroups');
        $settingsConfig = Factories::config('Settings');
        if (! $authConfig instanceof \CodeIgniter\Shield\Config\Auth || ! $groupConfig instanceof \CodeIgniter\Shield\Config\AuthGroups || ! $settingsConfig instanceof \CodeIgniter\Settings\Config\Settings) {
            throw new RuntimeException('Existing Shield/Settings configuration is required.');
        }
        $tables = $authConfig->tables;
        $row = $this->rows($tables['users'], ['id' => $actor], $lock, 1)[0] ?? null;
        if ($row === null || ! ($row['active'] ?? false) || ($row['deleted_at'] ?? null) !== null) {
            throw new RuntimeException('An active superadmin repair actor is required.');
        }
        $user = new User($row);
        $user->setGroupsCache(array_column($this->rows($tables['groups_users'], ['user_id' => $actor], $lock, 100), 'group'));
        $permissions = array_column($this->rows($tables['permissions_users'], ['user_id' => $actor], $lock, 100), 'permission');
        // Read the existing Shield matrix on this same connection; User::can()
        // would load Settings through a separate, globally configured connection.
        $matrix = $groupConfig->matrix;
        if ($settingsConfig->handlers !== ['database'] || ($settingsConfig->database['group'] ?? null) !== null) {
            throw new RuntimeException('Repair authorization requires the existing same-database Settings authority.');
        }
        $settingsTable = $settingsConfig->database['table'];
        if ($this->db->tableExists($settingsTable)) {
            $settings = $this->rows($settingsTable, ['class' => 'Config\\AuthGroups', 'key' => 'matrix', 'context' => null], $lock, 1);
            if ($settings !== []) {
                if (($settings[0]['type'] ?? null) !== 'array') {
                    throw new RuntimeException('Invalid Shield permission matrix type.');
                }
                $matrix = unserialize($settings[0]['value'], ['allowed_classes' => false]);
                if (! is_array($matrix)) {
                    throw new RuntimeException('Invalid Shield permission matrix.');
                }
            }
        }
        if ($user->isBanned() || ! $user->inGroup('superadmin') || (! PermissionMatcher::matches('admin.access', $permissions) && ! PermissionMatcher::matches('admin.access', $matrix['superadmin'] ?? []))) {
            throw new RuntimeException('Superadmin repair permission is required.');
        }
    }

    public function requireTransactionalTables(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        $tables = ['companies', 'fleet_vehicles', 'turo_trips_normalized', 'trip_movement_checklists', 'trip_movement_checklist_items', 'trip_movement_events', 'movement_assessments', 'vehicle_positioning_plans', 'vehicle_operational_profiles', 'vehicle_operational_capabilities', 'scheduled_movement_locations', 'airport_movement_workflows', 'fleet_trip_commitments', 'operational_fact_audits'];
        $auth = Factories::config('Auth');
        if (! $auth instanceof \CodeIgniter\Shield\Config\Auth) {
            throw new RuntimeException('Existing Shield configuration is required.');
        }
        foreach (['users', 'groups_users', 'permissions_users'] as $name) {
            $tables[] = $auth->tables[$name];
        }
        $settings = Factories::config('Settings');
        if ($settings instanceof \CodeIgniter\Settings\Config\Settings && $this->db->tableExists($settings->database['table'])) {
            $tables[] = $settings->database['table'];
        }
        $tables = array_map($this->db->prefixTable(...), $tables);
        $rows = $this->db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')', $tables)->getResultArray();
        if (count($rows) !== count($tables) || array_filter($rows, static fn (array $row): bool => $row['ENGINE'] !== 'InnoDB')) {
            throw new RuntimeException('Repair requires existing InnoDB transaction/lock authority for all affected tables.');
        }
    }

    /** Lock complete vehicle ranges, including gaps: ordinary writers need not cooperate. */
    public function lockVehicle(int $company, int $vehicle): void
    {
        $this->rows('companies', ['id' => $company], true, 1);
        $this->rows('fleet_vehicles', ['id' => $vehicle, 'company_id' => $company], true, 1);
        foreach (['turo_trips_normalized', 'trip_movement_checklists', 'trip_movement_events', 'movement_assessments', 'vehicle_positioning_plans', 'vehicle_operational_profiles', 'vehicle_operational_capabilities'] as $table) {
            $this->rows($table, ['fleet_vehicle_id' => $vehicle], true, in_array($table, ['trip_movement_events', 'movement_assessments'], true) ? 5000 : 1000);
        }
        foreach ($this->rows('trip_movement_checklists', ['fleet_vehicle_id' => $vehicle], true, 1000) as $checklist) {
            $this->rows('trip_movement_checklist_items', ['trip_movement_checklist_id' => $checklist['id']], true, 1000);
        }
        $tripIds = array_column($this->rows('turo_trips_normalized', ['fleet_vehicle_id' => $vehicle], true, 1000), 'id');
        foreach (['scheduled_movement_locations', 'airport_movement_workflows', 'fleet_trip_commitments'] as $table) {
            $this->tripRows($table, $tripIds, true);
        }
        // The company lock serializes this repair with its own replays; range locks
        // above also block existing handoff/correction writers and phantom facts.
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function vehicleState(int $vehicle): array
    {
        $state = [];
        foreach (['fleet_vehicles', 'turo_trips_normalized', 'trip_movement_checklists', 'trip_movement_events', 'movement_assessments', 'vehicle_positioning_plans', 'vehicle_operational_profiles', 'vehicle_operational_capabilities'] as $table) {
            $state[$table] = $this->rows($table, [$table === 'fleet_vehicles' ? 'id' : 'fleet_vehicle_id' => $vehicle], false, in_array($table, ['trip_movement_events', 'movement_assessments'], true) ? 5000 : 1000);
        }
        $state['trip_movement_checklist_items'] = [];
        foreach ($state['trip_movement_checklists'] as $checklist) {
            array_push($state['trip_movement_checklist_items'], ...$this->rows('trip_movement_checklist_items', ['trip_movement_checklist_id' => $checklist['id']], false, 1000));
            if (count($state['trip_movement_checklist_items']) > 10000) {
                throw new RuntimeException('Repair inspection limit exceeded: checklist items.');
            }
        }
        foreach (['scheduled_movement_locations', 'airport_movement_workflows', 'fleet_trip_commitments'] as $table) {
            $state[$table] = $this->tripRows($table, array_column($state['turo_trips_normalized'], 'id'));
        }
        return $state;
    }

    /** @return array<string, mixed>|null */
    public function receipt(int $company, int $trip): ?array
    {
        $rows = $this->rows('operational_fact_audits', ['company_id' => $company, 'table_name' => 'turo_trips_normalized', 'record_id' => $trip, 'action' => 'historical_pair_applied'], false, 1);
        return isset($rows[0]) ? json_decode($rows[0]['new_values'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function audit(int $company, string $table, int $record, string $action, int $actor, array $old, array $new): void
    {
        if (! $this->db->table('operational_fact_audits')->insert([
            'company_id' => $company, 'table_name' => $table, 'record_id' => $record, 'action' => $action,
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'actor_user_id' => $actor, 'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Repair audit could not be persisted.');
        }
    }
}
