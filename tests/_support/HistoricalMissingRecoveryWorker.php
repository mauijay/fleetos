<?php

// Independent synthetic contender, restricted to this worktree's disposable server.
if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    throw new RuntimeException('Contention worker is CLI-only.');
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\HistoricalMissingRecoveryRepairService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactService;
use Config\Database;
use Tests\Support\HistoricalMissingRecoveryFixture as Fixture;

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

    $facts = new OperationalFactsRepository($db);
    $events = new MovementEventService($facts);
    $operation = $input['operation'];
    if (in_array($operation, ['ordinary_recovery', 'ordinary_return', 'ordinary_recovery_snapshot'], true)) {
        if ($operation === 'ordinary_recovery_snapshot') {
            $db->transBegin();
            $db->table('trip_movement_events')->get()->getResultArray();
        }
        $checklist = (new App\Repositories\MovementChecklistRepository($db))->checklistForCompany(Fixture::COMPANY, $input['request']['return_checklist_id']);
        $data = $input['request']['recovery'] + ['confirm_recovery_location' => '1', 'energy_percent' => $input['request']['assessment']['energy_percent']];
        $writer = new MovementOperationalFactService($db, $events, new App\Services\Fleet\MovementAssessmentService($facts));
        $result = $operation === 'ordinary_return'
            ? ['recorded' => $writer->recordForChecklist($checklist + ['exists' => true], $data, Fixture::ACTOR)]
            : ['id' => $writer->recoverVehicle($checklist + ['exists' => true], $data, Fixture::ACTOR)];
    } elseif ($operation === 'extra_update') {
        $repository = new App\Repositories\FleetExtraRepository($db);
        $extra = $db->table('fleet_extras')->where('company_id', Fixture::COMPANY)->get()->getRowArray();
        $extra['display_name'] = 'Synthetic concurrent catalog update';
        $service = new App\Services\Fleet\FleetExtraService($repository, new App\Repositories\AuditLogRepository($db), new App\Repositories\LookupRepository($db), new App\Services\Fleet\TripExtraFulfillmentService(new App\Repositories\TripExtraFulfillmentRepository($db)));
        $service->updateExtra(Fixture::COMPANY, (int) $extra['id'], $extra, Fixture::ACTOR);
        $result = ['updated' => true];
    } else {
        $result = match ($operation) {
            'apply' => (new HistoricalMissingRecoveryRepairService($db))->apply($input['request'], Fixture::ACTOR, $input['confirmation']),
            'position' => ['id' => $events->record(Fixture::VEHICLE, null, 'vehicle_positioned', null, '2023-06-10 12:20:00', 'home', 'Synthetic concurrent storage', 'synthetic_operator', Fixture::ACTOR)],
            default => throw new RuntimeException('Unsupported synthetic recovery contender.'),
        };
    }
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $db->close();
}
