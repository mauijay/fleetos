<?php

namespace App\Database;

use App\Repositories\VehicleDamageRepairCostRepository as Costs;
use CodeIgniter\Database\BaseConnection;

/** MariaDB charset metadata; SQLite intentionally has no equivalent requirement. */
final class VehicleDamageRepairCostCharset
{
    public const CHARSET = 'utf8mb4';
    public const COLLATION = 'utf8mb4_general_ci';

    public static function correct(BaseConnection $db): bool
    {
        if ($db->getPlatform() === 'SQLite3') {
            return true;
        }
        $table = $db->query('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->prefixTable(Costs::TABLE)])->getRowArray();
        if (($table['TABLE_COLLATION'] ?? null) !== self::COLLATION) {
            return false;
        }
        $columns = $db->query('SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CHARACTER_SET_NAME IS NOT NULL', [$db->prefixTable(Costs::TABLE)])->getResultArray();
        return count($columns) === 7 && array_all($columns, static fn (array $column): bool => $column['CHARACTER_SET_NAME'] === self::CHARSET && $column['COLLATION_NAME'] === self::COLLATION);
    }
}
