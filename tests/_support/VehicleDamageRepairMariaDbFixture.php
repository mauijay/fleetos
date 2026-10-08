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

// Direct worker invocation needs the driver autoloader before the fault classes below.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
}

/** Disposable local MariaDB only. Opt in with B21_MARIADB_CONFIG and B21_MARIADB_DATADIR. */
final class VehicleDamageRepairMariaDbFixture
{
    public BaseConnection $db;
    private BaseConnection $admin;
    private array $configuration;

    public function __construct(bool $upgrade = false, string $prefix = '', int $through = 31, string $collation = 'utf8mb4_general_ci', bool $seed = true)
    {
        $charset = match ($collation) {
            'utf8mb4_general_ci' => 'utf8mb4',
            'utf8_general_ci' => 'utf8',
            'latin1_swedish_ci' => 'latin1',
            default => throw new RuntimeException('Unsupported disposable database collation.'),
        };
        $this->configuration = json_decode((string) getenv('B21_MARIADB_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
        // Build the accepted baseline through its real migrations. Its generated
        // 64-character FK name prevents fresh nonempty prefixes; preserve those
        // constraints while renaming the isolated baseline tables for B2 upgrade tests.
        $this->configuration['DBPrefix'] = '';
        $this->configuration['database'] = '';
        $this->admin = self::connect($this->configuration);
        $this->configuration['database'] = 'b21_synthetic_' . bin2hex(random_bytes(8));
        $this->admin->query('CREATE DATABASE ' . $this->configuration['database'] . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation);
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
                    if (preg_match('/^\d{4}-\d{2}-\d{2}-(\d+)_/', basename($path), $number) && (int) $number[1] <= $through) {
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
            if ($seed) {
                VehicleDamageDatabaseFixture::seed($this->db);
            }
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    private static function connect(array $configuration, bool $loseCommitAcknowledgement = false): BaseConnection
    {
        $expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
        $build = realpath(dirname(__DIR__, 2) . '/build');
        if ($expected === false || $build === false || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR))
            || ($configuration['hostname'] ?? '') !== '127.0.0.1' || (int) ($configuration['port'] ?? 0) < 1024
            || ($configuration['DBDriver'] ?? '') !== 'MySQLi'
            || ! preg_match('/^(b21_synthetic_[a-f0-9]{16})?$/D', $configuration['database'] ?? '')) {
            throw new RuntimeException('MariaDB contention fixtures require an explicit disposable local database.');
        }
        $db = $loseCommitAcknowledgement ? new B32LostAckConnection($configuration) : Database::connect($configuration, false);
        $server = $db->query('SELECT @@datadir AS directory, VERSION() AS version')->getRowArray();
        if (strtolower((string) realpath($server['directory'])) !== strtolower($expected) || ! str_contains($server['version'], 'MariaDB')) {
            throw new RuntimeException('Refusing to touch a server outside the disposable MariaDB directory.');
        }
        $db->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $db;
    }

    /** Start a separate PHP process/connection while the parent owns an uncommitted vehicle lock. */
    public function contend(string $operation, array $arguments, ?\Closure $beforeRelease = null, ?\Closure $afterRelease = null): array
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
            if ($afterRelease !== null) {
                $afterRelease();
            }
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

    /** Observe a live InnoDB wait, then let the canonical winner commit without retrying the contender. */
    public function contendUntilCommit(string $operation, array $arguments, \Closure $winner, string $waitingTable = 'fleet_vehicles', string $waitingStatement = 'FOR UPDATE'): array
    {
        $process = null;
        $pipes = [];
        $blocked = false;
        $engine = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($waitingTable)])->getRowArray()['ENGINE'];
        if ($engine !== 'InnoDB') {
            throw new RuntimeException('Live contention requires actual InnoDB rows.');
        }
        try {
            $receipt = $winner(function () use ($operation, $arguments, $waitingTable, $waitingStatement, &$process, &$pipes, &$blocked): \DateTimeImmutable {
                $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Could not start the live-wait contender.');
                }
                fwrite($pipes[0], json_encode(['configuration' => $this->configuration, 'operation' => $operation, 'arguments' => $arguments, 'live_wait' => true], JSON_THROW_ON_ERROR) . PHP_EOL);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 15);
                $thread = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['thread'];
                // Readiness inspects real FK/check metadata before acquiring a row
                // lock. Give that work time; the lock itself still has a 15s limit.
                $deadline = microtime(true) + 45;
                do {
                    // The winner holds this indexed row. Observing the
                    // contender still executing its FOR UPDATE after one second
                    // proves a live wait without relying on cached InnoDB views.
                    $wait = $this->admin->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=? AND COMMAND=\'Query\' AND TIME>=1', [$thread])->getRowArray();
                    if ($wait !== null && str_contains($wait['INFO'] ?? '', '`' . $this->db->prefixTable($waitingTable) . '`') && str_contains($wait['INFO'] ?? '', $waitingStatement)) {
                        $blocked = true;
                        break;
                    }
                    if (! proc_get_status($process)['running']) {
                        throw new RuntimeException('Contender ' . $thread . ' exited before an observed lock wait: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
                    }
                    usleep(50000);
                } while (microtime(true) < $deadline);
                if (! $blocked) {
                    $state = $this->admin->query('SHOW PROCESSLIST')->getResultArray();
                    throw new RuntimeException('The contender did not enter an observable InnoDB lock wait: ' . json_encode($state));
                }
                return new \DateTimeImmutable();
            });
            if (! ($receipt['success'] ?? false)) {
                throw new RuntimeException('The canonical winner failed: ' . json_encode($receipt));
            }
            $result = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0 || $errors !== '' || $result['lock_timeout']) {
                throw new RuntimeException('The live-wait contender did not resume cleanly: ' . $errors);
            }
            return ['blocked' => $blocked, 'result' => $result['receipt'], 'locks' => $result['locks'], 'winner' => $receipt, 'snapshot_primed' => $result['snapshot_primed']];
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
            if ($request['operation'] === 'b32_expense_edit') {
                try {
                    (new \App\Repositories\OperatingExpenseRepository($connection))->updateExpense(...$request['arguments']);
                    return ['success' => true];
                } catch (\Throwable $exception) {
                    return ['success' => false, 'errors' => ['source' => $exception->getMessage()]];
                }
            }
            if ($request['operation'] === 'link') {
                return (new VehicleDamageIncidentService($connection))->linkHistorical(...$request['arguments']);
            }
            if ($request['operation'] === 'reopenCondition') {
                return (new \App\Services\Fleet\VehicleDamageService($connection))->reopenRepairedCondition(...$request['arguments']);
            }
            $methods = ['reconcileRepairCostToExpense', 'invalidateFinancialReconciliation', 'replaceFinancialReconciliation', 'createJob', 'complete', 'recordMembershipResult', 'confirmConditionRepaired', 'addCondition', 'withdrawCondition', 'createEstimate', 'createRevision', 'acceptEstimate', 'rejectEstimate', 'withdrawEstimate', 'attachDocument', 'archiveDocument', 'recordCostEntry', 'voidCostEntry', 'replaceCostEntry', 'finalizeRepairCost', 'invalidateCostFinalization', 'recordRecovery', 'voidRecovery', 'replaceRecovery', 'finalizeRecovery', 'invalidateRecoveryFinalization'];
            if (! in_array($request['operation'], $methods, true)) {
                throw new RuntimeException('Unknown contention operation.');
            }
            return (new \App\Services\Fleet\VehicleDamageRepairService($connection))->{$request['operation']}(...$request['arguments']);
        };
        if (! empty($request['live_wait'])) {
            $db->query('SET SESSION innodb_lock_wait_timeout = 15');
            $snapshotPrimed = $request['operation'] === 'archiveDocument';
            if ($snapshotPrimed) {
                $db->transBegin();
                // B2.2 still supports caller-owned transactions. Establish an old RR
                // snapshot before waiting; archive authority must use current reads.
                $db->table('vehicle_damage_repair_cost_entries')->get()->getResultArray();
                $db->table('vehicle_damage_repair_documents')->get()->getResultArray();
            }
            // Bypass framework output buffering so the parent sees this barrier
            // while the worker is still blocked inside its service call.
            fwrite(STDOUT, json_encode(['thread' => (int) $db->query('SELECT CONNECTION_ID() AS thread')->getRowArray()['thread']], JSON_THROW_ON_ERROR) . PHP_EOL);
            fflush(STDOUT);
            $receipt = $operation($db);
            if ($snapshotPrimed) {
                $receipt['success'] ? $db->transCommit() : $db->transRollback();
            }
            fwrite(STDOUT, json_encode(['receipt' => $receipt, 'locks' => $lockTrace, 'lock_timeout' => $lockTimeout, 'snapshot_primed' => $snapshotPrimed], JSON_THROW_ON_ERROR) . PHP_EOL);
            fflush(STDOUT);
            $db->close();
            return;
        }
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

    public function independent(bool $loseCommitAcknowledgement = false): BaseConnection
    {
        return self::connect($this->configuration, $loseCommitAcknowledgement);
    }
}

/** Driver-derived builder/result names require named classes. Local fault injection only. */
class B32LostAckConnection extends \CodeIgniter\Database\MySQLi\Connection
{
    private bool $loseNextAcknowledgement = true;

    protected function _transCommit(): bool
    {
        $committed = parent::_transCommit();
        if ($committed && $this->loseNextAcknowledgement) {
            $this->loseNextAcknowledgement = false;
            throw new RuntimeException('Synthetic MariaDB acknowledgement lost after actual COMMIT');
        }
        return $committed;
    }
}

class B32LostAckResult extends \CodeIgniter\Database\MySQLi\Result
{
}

class B32LostAckBuilder extends \CodeIgniter\Database\MySQLi\Builder
{
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    VehicleDamageRepairMariaDbFixture::worker();
}
