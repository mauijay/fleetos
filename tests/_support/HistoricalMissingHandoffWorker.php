<?php

// Independent synthetic contender, restricted to this worktree's disposable server.
if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    throw new RuntimeException('Contention worker is CLI-only.');
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingHandoffRepairService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use Config\Database;
use Tests\Support\HistoricalMissingHandoffFixture as Fixture;

$input = json_decode((string) fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
$configuration = $input['configuration'];
$expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
$build = realpath(dirname(__DIR__, 2) . '/build');
if ($expected === false || $build === false || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR)) || $configuration['hostname'] !== '127.0.0.1' || $configuration['port'] < 1024 || $configuration['DBDriver'] !== 'MySQLi' || ! preg_match('/^b21_synthetic_[a-f0-9]{16}$/D', $configuration['database'])) {
    throw new RuntimeException('Refusing non-disposable contention database.');
}
$db = Database::connect($configuration, false);
$server = $db->query('SELECT @@datadir AS directory, VERSION() AS version, CONNECTION_ID() AS thread')->getRowArray();
if (strtolower((string) realpath($server['directory'])) !== strtolower($expected) || ! str_contains($server['version'], 'MariaDB')) {
    throw new RuntimeException('Refusing non-disposable server.');
}
$db->query('SET SESSION innodb_lock_wait_timeout=15');
echo json_encode(['thread' => (int) $server['thread']], JSON_THROW_ON_ERROR) . PHP_EOL;
flush();
try {
    $events = new MovementEventService(new OperationalFactsRepository($db));
    $result = match ($input['operation']) {
        'apply' => (new HistoricalMissingHandoffRepairService($db))->apply($input['request'], Fixture::ACTOR, $input['confirmation']),
        'ordinary' => ['recorded' => (new MovementOperationalFactService($db, $events))->recordCurrentPositionForVehicle(Fixture::COMPANY, Fixture::VEHICLE, ['occurred_at' => date('Y-m-d H:i:s'), 'location_class' => 'home', 'location_detail' => 'Synthetic concurrent ordinary position'], Fixture::ACTOR)],
        'correction' => ['id' => $events->correct($input['request']['recovery_event_id'], ['location_detail' => 'Synthetic concurrent recovery correction'], Fixture::ACTOR, 'Synthetic concurrency review')],
        'position' => ['id' => $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, '2024-03-18 09:00:00', 'home', 'Synthetic concurrent later depot', 'synthetic_operator', Fixture::ACTOR)],
        default => throw new RuntimeException('Unsupported synthetic contention operation.'),
    };
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $db->close();
}
