<?php

namespace Tests\Support;

use CodeIgniter\Database\MySQLi\Connection;
use RuntimeException;

/** Exercise equivalent existing access paths without relying on MariaDB's chosen plan. */
final class HistoricalPairPlanConnection extends Connection
{
    /** @var list<string> */
    public array $readPlans = [];
    private bool $repairFactInserted = false;

    private function __construct(array $configuration)
    {
        parent::__construct($configuration);
    }

    public static function replaceFixtureConnection(VehicleDamageRepairMariaDbFixture $fixture): self
    {
        $configuration = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        $expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
        $build = realpath(dirname(__DIR__, 2) . '/build');
        $database = $fixture->db->database;
        if (! is_array($configuration) || $expected === false || $build === false
            || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR))
            || ($configuration['hostname'] ?? '') !== '127.0.0.1' || (int) ($configuration['port'] ?? 0) < 1024
            || ($configuration['DBDriver'] ?? '') !== 'MySQLi' || $fixture->db->DBPrefix !== ''
            || ! preg_match('/^b21_synthetic_[a-f0-9]{16}$/D', $database) || $fixture->db->transDepth !== 0) {
            throw new RuntimeException('Plan comparison requires an idle disposable local fixture.');
        }
        $configuration['database'] = $database;
        $configuration['DBPrefix'] = '';
        $connection = new self($configuration);
        try {
            $server = $connection->query('SELECT @@datadir AS directory, VERSION() AS version')->getRowArray();
            if (strtolower((string) realpath($server['directory'])) !== strtolower($expected) || ! str_contains($server['version'], 'MariaDB')) {
                throw new RuntimeException('Refusing a server outside the disposable MariaDB directory.');
            }
            $connection->query('SET SESSION innodb_lock_wait_timeout = 1');
        } catch (\Throwable $exception) {
            $connection->close();
            throw $exception;
        }
        $fixture->db->close();
        $fixture->db = $connection;
        return $connection;
    }

    public function simpleQuery(string $sql)
    {
        $historicalInsert = str_starts_with($sql, 'INSERT INTO `trip_movement_events`') && str_contains($sql, "'historical_custody_repair'");
        if (str_starts_with($sql, 'SELECT `trips`.*') && str_contains($sql, '`turo_trips_normalized` `trips`')) {
            $index = $this->repairFactInserted ? 'fleet_vehicle_id_starts_at_ends_at' : 'PRIMARY';
            $sql = str_replace('`turo_trips_normalized` `trips`', '`turo_trips_normalized` `trips` FORCE INDEX (`' . $index . '`)', $sql);
            $this->readPlans[] = $index;
        }
        $result = parent::simpleQuery($sql);
        if ($historicalInsert && $result !== false) {
            $this->repairFactInserted = true;
        }
        return $result;
    }
}

/** Driver-derived result and builder names must match the disposable connection. */
class HistoricalPairPlanResult extends \CodeIgniter\Database\MySQLi\Result
{
}

class HistoricalPairPlanBuilder extends \CodeIgniter\Database\MySQLi\Builder
{
}
