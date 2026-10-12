<?php

namespace App\Database;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Exact additive footprint; inspecting or generating its plan never writes. */
final class VehicleDamageOccurrenceProvenanceSchema
{
    public const CHECK = 'damage_basis_snapshot_ck';
    public const INDEXES = [
        'damage_membership_event_idx' => ['vehicle_damage_incident_items', ['condition_event_id']],
        'damage_evidence_occurrence_idx' => ['vehicle_damage_item_evidence', ['vehicle_damage_incident_item_id', 'company_id', 'id']],
        'damage_membership_latest_idx' => ['vehicle_damage_incident_items', ['company_id', 'vehicle_damage_item_id', 'effect_code', 'id']],
        'damage_wheel_episode_idx' => ['vehicle_damage_items', ['company_id', 'fleet_vehicle_id', 'damage_type_code', 'panel_code', 'current_condition_item_id', 'status_code', 'id']],
        'damage_event_latest_idx' => ['vehicle_damage_item_events', ['company_id', 'vehicle_damage_item_id', 'id']],
    ];
    public const REFERENCES = [
        'damage_membership_event_fk' => ['vehicle_damage_incident_items', 'condition_event_id', 'vehicle_damage_item_events'],
        'damage_evidence_occurrence_fk' => ['vehicle_damage_item_evidence', 'vehicle_damage_incident_item_id', 'vehicle_damage_incident_items'],
    ];

    /** Pure SQL generation, including for an offline non-connected connection. @return list<string> */
    public static function statements(BaseConnection $db): array
    {
        $sqlite = $db->getPlatform() === 'SQLite3';
        if (! $sqlite && $db->getPlatform() !== 'MySQLi') {
            throw new RuntimeException('Occurrence provenance requires SQLite or MariaDB.');
        }
        $name = static fn (string $table): string => $db->escapeIdentifiers($db->prefixTable($table));
        $members = $name('vehicle_damage_incident_items');
        $events = $name('vehicle_damage_item_events');
        $evidence = $name('vehicle_damage_item_evidence');
        $check = 'basis_snapshot IS NULL OR (json_valid(basis_snapshot) AND ' . ($sqlite ? 'length(CAST(basis_snapshot AS BLOB))' : 'octet_length(basis_snapshot)') . ' <= 32768)';
        $plan = $sqlite ? [
            "ALTER TABLE {$members} ADD COLUMN condition_event_id INTEGER NULL REFERENCES {$events}(id) ON UPDATE RESTRICT ON DELETE RESTRICT",
            "ALTER TABLE {$members} ADD COLUMN basis_snapshot TEXT NULL CONSTRAINT " . self::CHECK . " CHECK ({$check})",
            "ALTER TABLE {$evidence} ADD COLUMN vehicle_damage_incident_item_id INTEGER NULL REFERENCES {$members}(id) ON UPDATE RESTRICT ON DELETE RESTRICT",
        ] : [
            "ALTER TABLE {$members} ADD COLUMN condition_event_id BIGINT UNSIGNED NULL, ADD CONSTRAINT damage_membership_event_fk FOREIGN KEY (condition_event_id) REFERENCES {$events}(id) ON UPDATE RESTRICT ON DELETE RESTRICT",
            "ALTER TABLE {$members} ADD COLUMN basis_snapshot LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD CONSTRAINT " . self::CHECK . " CHECK ({$check})",
            "ALTER TABLE {$evidence} ADD COLUMN vehicle_damage_incident_item_id BIGINT UNSIGNED NULL, ADD CONSTRAINT damage_evidence_occurrence_fk FOREIGN KEY (vehicle_damage_incident_item_id) REFERENCES {$members}(id) ON UPDATE RESTRICT ON DELETE RESTRICT",
        ];
        foreach (self::INDEXES as $index => [$table, $fields]) {
            $plan[] = 'CREATE INDEX ' . $db->escapeIdentifiers($index) . ' ON ' . $name($table) . ' (' . implode(',', $fields) . ')';
        }
        return $plan;
    }

    public static function ready(BaseConnection $db): bool
    {
        return self::matchesStage($db, 8);
    }

    public static function requireReady(BaseConnection $db): void
    {
        if (! self::ready($db)) {
            throw new RuntimeException('Occurrence provenance schema is incomplete. Inspect migration 000036 before enabling provenance writes.');
        }
    }

    /** Reject any unexpected partial footprint; migration never resumes an interrupted plan. */
    public static function matchesStage(BaseConnection $db, int $completed): bool
    {
        if ($completed < 0 || $completed > 8 || ! in_array($db->getPlatform(), ['SQLite3', 'MySQLi'], true)) {
            return false;
        }
        $db->resetDataCache();
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_incident_items', 'vehicle_damage_item_evidence'] as $table) {
            if (! $db->tableExists($table)) {
                return false;
            }
        }
        foreach ([
            'vehicle_damage_items' => ['id', 'company_id', 'fleet_vehicle_id', 'damage_type_code', 'panel_code', 'current_condition_item_id', 'status_code'],
            'vehicle_damage_item_events' => ['id', 'company_id', 'vehicle_damage_item_id'],
            'vehicle_damage_incident_items' => ['id', 'company_id', 'vehicle_damage_item_id', 'effect_code'],
            'vehicle_damage_item_evidence' => ['id', 'company_id', 'vehicle_damage_item_id'],
        ] as $table => $fields) {
            if (array_diff($fields, $db->getFieldNames($table)) !== []) {
                return false;
            }
            if ($db->getPlatform() === 'MySQLi') {
                $parent = $db->query('SELECT columns_info.COLUMN_TYPE,tables_info.ENGINE FROM information_schema.COLUMNS columns_info JOIN information_schema.TABLES tables_info ON tables_info.TABLE_SCHEMA=columns_info.TABLE_SCHEMA AND tables_info.TABLE_NAME=columns_info.TABLE_NAME WHERE columns_info.TABLE_SCHEMA=DATABASE() AND columns_info.TABLE_NAME=? AND columns_info.COLUMN_NAME=\'id\'', [$db->prefixTable($table)])->getRowArray();
                if ($parent === null || $parent['ENGINE'] !== 'InnoDB' || preg_match('/^bigint(?:\(\d+\))? unsigned$/D', $parent['COLUMN_TYPE']) !== 1) {
                    return false;
                }
            }
        }
        $sqlite = $db->getPlatform() === 'SQLite3';
        foreach ([['vehicle_damage_incident_items', 'condition_event_id', 1], ['vehicle_damage_incident_items', 'basis_snapshot', 2], ['vehicle_damage_item_evidence', 'vehicle_damage_incident_item_id', 3]] as [$table, $column, $step]) {
            $fields = array_column($db->getFieldData($table), null, 'name');
            if (isset($fields[$column]) !== ($completed >= $step)) {
                return false;
            }
            if (isset($fields[$column])) {
                $field = $fields[$column];
                $type = $column === 'basis_snapshot' ? ($sqlite ? 'TEXT' : 'LONGTEXT') : ($sqlite ? 'INTEGER' : 'BIGINT');
                if (strtoupper($field->type) !== $type || ! $field->nullable || $field->default !== null) {
                    return false;
                }
                if (! $sqlite && ! self::mariaColumnMatches($db, $table, $column)) {
                    return false;
                }
            }
        }
        foreach (self::REFERENCES as $name => [$table, $column, $target]) {
            $expected = $completed >= ($column === 'condition_event_id' ? 1 : 3);
            $keys = array_values(array_filter($db->getForeignKeyData($table), static fn ($key): bool => in_array($column, $key->column_name, true)));
            if (count($keys) !== ($expected ? 1 : 0)) {
                return false;
            }
            if ($expected) {
                $key = $keys[0];
                if ($key->column_name !== [$column] || $key->foreign_table_name !== $db->prefixTable($target) || $key->foreign_column_name !== ['id']
                    || strtoupper($key->on_update) !== 'RESTRICT' || strtoupper($key->on_delete) !== 'RESTRICT' || (! $sqlite && $key->constraint_name !== $name)) {
                    return false;
                }
            }
        }
        $step = 4;
        foreach (self::INDEXES as $name => [$table, $fields]) {
            $indexes = $db->getIndexData($table);
            if (isset($indexes[$name]) !== ($completed >= $step++)) {
                return false;
            }
            if (isset($indexes[$name]) && ($indexes[$name]->fields !== $fields || strtoupper($indexes[$name]->type) !== 'INDEX')) {
                return false;
            }
        }
        if ($sqlite) {
            $ddl = $db->query('SELECT sql FROM sqlite_master WHERE type=\'table\' AND name=?', [$db->prefixTable('vehicle_damage_incident_items')])->getRowArray()['sql'];
            $clause = strtolower(preg_replace('/\s+/', '', $ddl));
            $present = str_contains($clause, self::CHECK);
            if ($present !== ($completed >= 2)) {
                return false;
            }
            return ! $present || str_contains($clause, 'check(basis_snapshotisnullor(json_valid(basis_snapshot)andlength(cast(basis_snapshotasblob))<=32768))');
        }
        $row = $db->query('SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$db->prefixTable('vehicle_damage_incident_items'), self::CHECK])->getRowArray();
        if (($row !== null) !== ($completed >= 2)) {
            return false;
        }
        $clause = strtolower(str_replace(['`', ' ', "\n", "\r", "\t"], '', $row['CHECK_CLAUSE'] ?? ''));
        return $row === null || $clause === 'basis_snapshotisnullorjson_valid(basis_snapshot)andoctet_length(basis_snapshot)<=32768'
            || $clause === 'basis_snapshotisnullor(json_valid(basis_snapshot)andoctet_length(basis_snapshot)<=32768)';
    }

    private static function mariaColumnMatches(BaseConnection $db, string $table, string $column): bool
    {
        $actual = $db->query('SELECT COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$db->prefixTable($table), $column])->getRowArray();
        if ($column === 'basis_snapshot') {
            return $actual['CHARACTER_SET_NAME'] === 'utf8mb4' && $actual['COLLATION_NAME'] === 'utf8mb4_bin';
        }
        return preg_match('/^bigint(?:\(\d+\))? unsigned$/D', $actual['COLUMN_TYPE']) === 1;
    }
}
