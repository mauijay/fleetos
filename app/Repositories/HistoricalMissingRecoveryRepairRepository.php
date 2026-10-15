<?php

namespace App\Repositories;

use RuntimeException;

/** Existing audit receipts and vehicle ranges; no new persistence authority. */
class HistoricalMissingRecoveryRepairRepository extends HistoricalMissingHandoffRepairRepository
{
    public const RECEIPT_ACTION = 'historical_missing_recovery_applied';

    /** Serialize the existing audit receipt range, including its empty gap. */
    public function lockReceipts(): void
    {
        $this->rows('operational_fact_audits', ['action' => self::RECEIPT_ACTION], true, 1000);
    }

    public function commandReceipt(string $uuid): ?array
    {
        $rows = $this->db->table('operational_fact_audits')->where('action', self::RECEIPT_ACTION)
            ->like('new_values', '"operation_uuid":"' . $uuid . '"')->orderBy('id')->limit(2)->get()->getResultArray();
        if (count($rows) > 1) {
            throw new RuntimeException('Ambiguous historical recovery receipt.');
        }
        if ($rows === []) {
            return null;
        }
        $receipt = json_decode($rows[0]['new_values'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($receipt) || ($receipt['operation_uuid'] ?? null) !== $uuid) {
            throw new RuntimeException('Malformed historical recovery receipt.');
        }
        return ['audit' => $rows[0], 'receipt' => $receipt];
    }


    /** Read-only all-table firewall; only the exact new artifacts may be excluded. */
    public function firewall(array $exclude = []): array
    {
        $tables = $this->db->listTables();
        sort($tables);
        $result = [];
        foreach ($tables as $table) {
            $fields = $this->db->getFieldNames($table);
            $builder = $this->db->table($table);
            if (isset($exclude[$table])) {
                $builder->whereNotIn('id', $exclude[$table]);
            }
            foreach (in_array('id', $fields, true) ? ['id'] : $fields as $field) {
                $builder->orderBy($field);
            }
            $query = $builder->get();
            $hash = hash_init('sha256');
            $count = 0;
            while ($row = $query->getUnbufferedRow('array')) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
                $count++;
            }
            $query->freeResult();
            $result[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
        }
        return $result;
    }
}
