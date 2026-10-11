<?php

// Synthetic independent-process contention worker; never accepts a remote server.
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalPairedMovementRepairService;
use App\Services\Fleet\MovementEventService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Config\Database;

$input = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$run = static function () use ($input): array {
    $configuration = $input['configuration'];
    $expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
    $build = realpath(dirname(__DIR__, 2) . '/build');
    if ($expected === false || $build === false || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR)) || $configuration['hostname'] !== '127.0.0.1' || $configuration['port'] < 1024 || ! preg_match('/^b21_synthetic_[a-f0-9]{16}$/D', $configuration['database'])) {
        throw new RuntimeException('Refusing non-disposable contention database.');
    }
    $db = Database::connect($configuration, false);
    $server = $db->query('SELECT @@datadir AS directory, VERSION() AS version')->getRowArray();
    if (strtolower((string) realpath($server['directory'])) !== strtolower($expected) || ! str_contains($server['version'], 'MariaDB')) {
        throw new RuntimeException('Refusing non-disposable server.');
    }
    $db->query('SET SESSION innodb_lock_wait_timeout=1');
    $timedOut = false;
    $observer = static function (Query $query) use (&$timedOut, $db): void {
        if ($query->db === $db && ($db->error()['code'] ?? 0) === 1205) {
            $timedOut = true;
        }
    };
    Events::on('DBQuery', $observer);
    try {
        $events = new MovementEventService(new OperationalFactsRepository($db));
        $result = match ($input['operation']) {
            'repair' => (new HistoricalPairedMovementRepairService($db))->apply($input['request'], 7, $input['confirmation']),
            'handoff' => ['id' => $events->record(10, 3002, 'actual_handoff', 'pickup', '2025-10-10 22:00:00', 'other_delivery', 'Synthetic later lodge', 'operator', 7)],
            'correction' => ['id' => $events->correct($input['event_id'], ['location_detail' => 'Synthetic corrected airport'], 7, 'Synthetic concurrency correction')],
            default => throw new RuntimeException('Unsupported synthetic contention operation.'),
        };
        return ['success' => true, 'result' => $result];
    } catch (Throwable $exception) {
        return ['success' => false, 'lock_timeout' => $timedOut || str_contains($exception->getMessage(), 'Lock wait timeout'), 'error' => $exception->getMessage()];
    } finally {
        Events::removeListener('DBQuery', $observer);
        $db->close();
    }
};
echo json_encode($run(), JSON_THROW_ON_ERROR) . PHP_EOL;
flush();
if (trim((string) fgets(STDIN)) === 'retry') {
    echo json_encode($run(), JSON_THROW_ON_ERROR) . PHP_EOL;
}
