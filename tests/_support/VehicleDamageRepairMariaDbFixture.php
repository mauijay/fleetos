<?php

namespace Tests\Support;

use App\Services\Fleet\VehicleDamageIncidentService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;
use Config\Migrations;
use RuntimeException;

/** Disposable local MariaDB only. Opt in with B21_MARIADB_CONFIG and B21_MARIADB_DATADIR. */
final class VehicleDamageRepairMariaDbFixture
{
    public BaseConnection $db;
    private BaseConnection $admin;
    private array $configuration;

    public function __construct(bool $upgrade = false, string $prefix = '', int $through = 31)
    {
        $this->configuration = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        // Build the accepted baseline through its real migrations. Its generated
        // 64-character FK name prevents fresh nonempty prefixes; preserve those
        // constraints while renaming the isolated baseline tables for B2 upgrade tests.
        $this->configuration['DBPrefix'] = '';
        $this->configuration['database'] = '';
        $this->admin = self::connect($this->configuration);
        $this->configuration['database'] = 'b21_synthetic_' . bin2hex(random_bytes(8));
        $this->admin->query('CREATE DATABASE ' . $this->configuration['database'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $this->db = self::connect($this->configuration);
        try {
            $runner = (new MigrationRunner(new Migrations(), $this->db))->setNamespace('App');
            if ($upgrade) {
                $paths = glob(__DIR__ . '/../../app/Database/Migrations/*.php');
                sort($paths);
                foreach ($paths as $path) {
                    if (strcmp(basename($path), '2026-10-06-000030') < 0) {
                        $runner->force($path, 'App');
                    }
                }
            } else {
                foreach (glob(__DIR__ . '/../../app/Database/Migrations/*.php') as $path) {
                    if (strcmp(basename($path), $through === 30 ? '2026-10-06-000031' : '9999') < 0) {
                        $runner->force($path, 'App');
                    }
                }
            }
            if ($prefix !== '') {
                $renames = [];
                foreach ($this->db->listTables() as $table) {
                    $renames[] = $this->db->escapeIdentifiers($table) . ' TO ' . $this->db->escapeIdentifiers($prefix . $table);
                }
                $this->db->query('RENAME TABLE ' . implode(', ', $renames));
                $this->db->setPrefix($prefix);
                $this->configuration['DBPrefix'] = $prefix;
                $this->db->resetDataCache();
            }
            VehicleDamageDatabaseFixture::seed($this->db);
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    private static function connect(array $configuration): BaseConnection
    {
        $expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
        $build = realpath(dirname(__DIR__, 2) . '/build');
        if ($expected === false || $build === false || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR))
            || ($configuration['hostname'] ?? '') !== '127.0.0.1' || (int) ($configuration['port'] ?? 0) < 1024
            || ($configuration['DBDriver'] ?? '') !== 'MySQLi'
            || ! preg_match('/^(b21_synthetic_[a-f0-9]{16})?$/D', $configuration['database'] ?? '')) {
            throw new RuntimeException('MariaDB contention fixtures require an explicit disposable local database.');
        }
        $db = Database::connect($configuration, false);
        $server = $db->query('SELECT @@datadir AS directory, VERSION() AS version')->getRowArray();
        if (strtolower((string) realpath($server['directory'])) !== strtolower($expected) || ! str_contains($server['version'], 'MariaDB')) {
            throw new RuntimeException('Refusing to touch a server outside the disposable MariaDB directory.');
        }
        $db->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $db;
    }

    /** Start a separate PHP process/connection while the parent owns an uncommitted vehicle lock. */
    public function contend(string $operation, array $arguments, ?\Closure $beforeRelease = null): array
    {
        $parentTransaction = $this->db->query('SELECT @@in_transaction AS active')->getRowArray();
        if ((int) $parentTransaction['active'] !== 1) {
            throw new RuntimeException('Contention must start inside the winner\'s uncommitted transaction.');
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the MariaDB contender.');
        }
        try {
            fwrite($pipes[0], json_encode(['configuration' => $this->configuration, 'operation' => $operation, 'arguments' => $arguments], JSON_THROW_ON_ERROR) . PHP_EOL);
            stream_set_timeout($pipes[1], 15);
            $contention = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            // The real service call must hit MariaDB's lock timeout while the winner holds its lock.
            // Release it, then retry the same preview; B2 also exercises connection reuse.
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            $this->db->transCommit();
            fwrite($pipes[0], "retry\n");
            fclose($pipes[0]);
            $resultLine = (string) fgets($pipes[1]);
            try {
                $result = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Contender did not return valid JSON after retry.', 0, $exception);
            }
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0 || $errors !== '') {
                throw new RuntimeException('MariaDB contender failed: ' . $errors);
            }

            return ['blocked' => $contention['lock_timeout'] && ! $contention['attempt']['success'], 'result' => $result['receipt'], 'locks' => $result['locks']];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public static function worker(): void
    {
        $request = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $db = self::connect($request['configuration']);
        $lockTimeout = false;
        $lockTrace = [];
        Events::on('DBQuery', static function (Query $query) use (&$lockTimeout, &$lockTrace): void {
            $lockTimeout = $lockTimeout || $query->db->error()['code'] === 1205;
            if (str_contains(strtoupper($query->getQuery()), 'FOR UPDATE')) {
                $lockTrace[] = $query->getQuery();
            }
        });
        $operation = static function (BaseConnection $connection) use ($request): array {
            if ($request['operation'] === 'link') {
                return (new VehicleDamageIncidentService($connection))->linkHistorical(...$request['arguments']);
            }
            if ($request['operation'] === 'reopenCondition') {
                return (new \App\Services\Fleet\VehicleDamageService($connection))->reopenRepairedCondition(...$request['arguments']);
            }
            $methods = ['createJob', 'complete', 'recordMembershipResult', 'confirmConditionRepaired', 'addCondition', 'withdrawCondition', 'createEstimate', 'createRevision', 'acceptEstimate', 'rejectEstimate', 'withdrawEstimate', 'attachDocument', 'archiveDocument'];
            if (! in_array($request['operation'], $methods, true)) {
                throw new RuntimeException('Unknown contention operation.');
            }
            return (new \App\Services\Fleet\VehicleDamageRepairService($connection))->{$request['operation']}(...$request['arguments']);
        };
        $attempt = $operation($db);
        echo json_encode(['lock_timeout' => $lockTimeout, 'attempt' => $attempt], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        if (trim((string) fgets(STDIN)) !== 'retry') {
            throw new RuntimeException('The winner did not release the contention barrier.');
        }
        if ($request['operation'] === 'link') {
            $db->close();
            $db = self::connect($request['configuration']);
        }
        $lockTrace = [];
        $result = $operation($db);
        echo json_encode(['receipt' => $result, 'locks' => $lockTrace], JSON_THROW_ON_ERROR) . PHP_EOL;
        $db->close();
    }

    public function close(): void
    {
        $this->db->transRollback();
        $this->db->close();
        // This random database was created by this fixture after verifying the server directory.
        $this->admin->query('DROP DATABASE ' . $this->configuration['database']);
        $this->admin->close();
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
    VehicleDamageRepairMariaDbFixture::worker();
}
