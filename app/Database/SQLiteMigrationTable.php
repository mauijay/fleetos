<?php

namespace App\Database;

use CodeIgniter\Database\SQLite3\Table;

/** Preserve foreign-key targets and actions during SQLite migration rebuilds. */
class SQLiteMigrationTable extends Table
{
    public function run(): bool
    {
        $legacy = $this->db->query('PRAGMA legacy_alter_table')->getRowArray()['legacy_alter_table'];
        $foreignKeys = $this->db->query('PRAGMA foreign_keys')->getRowArray()['foreign_keys'];
        $this->db->query('PRAGMA legacy_alter_table = ON');
        try {
            return parent::run();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        } finally {
            $this->db->query('PRAGMA foreign_keys = ' . (int) $foreignKeys);
            $this->db->query('PRAGMA legacy_alter_table = ' . (int) $legacy);
        }
    }

    protected function createTable(): bool
    {
        $this->dropIndexes();
        $this->db->resetDataCache();
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$field['new_name'] ?? $name] = $field;
        }
        $this->forge->addField($fields);
        $names = array_keys($fields);
        foreach ($this->keys as $keyName => $key) {
            if (count(array_intersect($key['fields'], $names)) !== count($key['fields'])) {
                continue;
            }
            if ($key['type'] === 'primary') {
                $this->forge->addPrimaryKey($key['fields']);
            } elseif ($key['type'] === 'unique') {
                $this->forge->addUniqueKey($key['fields'], $keyName);
            } else {
                $this->forge->addKey($key['fields'], false, false, $keyName);
            }
        }
        $prefix = $this->db->getPrefix();
        foreach ($this->foreignKeys as $key) {
            $table = $key->foreign_table_name;
            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }
            $this->forge->addForeignKey($key->column_name, $table, $key->foreign_column_name, $key->on_update, $key->on_delete);
        }

        return $this->forge->createTable($this->tableName);
    }
}
