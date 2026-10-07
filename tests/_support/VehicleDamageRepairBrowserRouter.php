<?php

/** Local synthetic browser harness. Never use this router as an application entry point. */
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
// MigrationRunner's test-environment history formatter initializes CLI streams.
// cli-server does not define these CLI constants; keep fixture output off the HTTP body.
foreach (['STDIN', 'STDOUT', 'STDERR'] as $stream) {
    if (! defined($stream)) {
        define($stream, fopen('php://temp', 'w+'));
    }
}
$root = dirname(__DIR__, 2);
$directory = realpath((string) getenv('B21_BROWSER_DIR'));
if ($directory === false || ! str_starts_with(strtolower($directory), strtolower(realpath($root . '/build') . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Browser fixtures must remain in the ignored build directory.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') && is_file($root . '/public' . $path)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript'));
    header('Content-Length: ' . filesize($root . '/public' . $path));
    header('Connection: close');
    readfile($root . '/public' . $path);
    exit;
}
// The built-in server may change cwd between static requests and POST redirects.
chdir($root);
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . '/public/');
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$configuration = (new \Config\Database())->tests;
$configuration['database'] = $directory . '/synthetic.sqlite';
$configuration['DBPrefix'] = '';
$db = \Config\Database::connect($configuration, false);
if (! $db->tableExists('vehicle_damage_repair_jobs')) {
    $runner = (new \CodeIgniter\Database\MigrationRunner(new \Config\Migrations(), $db))->setNamespace('App');
    $runner->latest();
}
if ($db->table('companies')->countAllResults() === 0) {
    \Tests\Support\VehicleDamageDatabaseFixture::seed($db);
    \Tests\Support\VehicleDamageRepairDatabaseFixture::condition($db);
    \Tests\Support\VehicleDamageRepairDatabaseFixture::condition($db);
    $legacy = \Tests\Support\VehicleDamageRepairDatabaseFixture::condition($db);
    $conditionRepository = new \App\Repositories\VehicleDamageRepository($db);
    $db->transBegin();
    $conditionRepository->lockVehicle(1, 10);
    $conditionRepository->lockItem(1, 10, $legacy);
    (new \App\Services\Fleet\VehicleDamageService($db))->writeRepairStateInTransaction($conditionRepository->item(1, 10, $legacy), ['status_code' => 'repaired', 'resolved_at' => '2026-10-01 09:00:00', 'resolved_by' => 7, 'resolution_note' => 'Synthetic legacy repair', 'updated_at' => '2026-10-01 09:00:00', 'updated_by' => 7], 'repaired', 'Synthetic legacy repair', 7, '2026-10-01 09:00:00', null);
    $db->transCommit();
}
$items = new \App\Repositories\VehicleDamageRepository($db);
$conditions = new \App\Services\Fleet\VehicleDamageService($db);
$repairs = new \App\Repositories\VehicleDamageRepairRepository($db);
$read = new \App\Services\Fleet\VehicleDamageReadService($conditions, $items, $repairs);
foreach (['vehicleDamageRepository' => $items, 'vehicleDamageService' => $conditions, 'vehicleDamageRepairRepository' => $repairs, 'vehicleDamageRepairCostService' => new \App\Services\Fleet\VehicleDamageRepairCostService($db), 'vehicleDamageRepairCostReadService' => new \App\Services\Fleet\VehicleDamageRepairCostReadService($db), 'vehicleDamageRepairService' => new \App\Services\Fleet\VehicleDamageRepairService($db), 'vehicleDamageRepairEstimateRepository' => new \App\Repositories\VehicleDamageRepairEstimateRepository($db), 'vehicleDamageRepairDocumentRepository' => new \App\Repositories\VehicleDamageRepairDocumentRepository($db), 'repairDocumentStorageService' => new \App\Services\Files\RepairDocumentStorageService($db), 'vehicleDamageReadService' => $read] as $name => $instance) {
    \Config\Services::injectMock($name, $instance);
}
\Config\Services::injectMock('operationalFactsRepository', new class ($db) extends \App\Repositories\OperationalFactsRepository {
    public function activeFleetCompanyIds(?string $asOfDate = null): array
    {
        return [1];
    }
});
\CodeIgniter\Shield\Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
    public function user(): \CodeIgniter\Shield\Entities\User
    {
        return new \CodeIgniter\Shield\Entities\User(['id' => 7, 'username' => 'synthetic-work-operator']);
    }
});
$sessionConfiguration = new \Config\Session();
$sessionConfiguration->savePath = $directory . '/sessions';
$sessionConfiguration->cookieName = 'b21_synthetic_session';
if (! is_dir($sessionConfiguration->savePath)) {
    mkdir($sessionConfiguration->savePath);
}
$driver = new \CodeIgniter\Session\Handlers\FileHandler($sessionConfiguration, '127.0.0.1');
$driver->setLogger(\CodeIgniter\Config\Services::logger());
$session = new class ($driver, $sessionConfiguration) extends \CodeIgniter\Session\Session {
    // CI's testing startSession discards $_SESSION; browser requests need real local sessions.
    protected function startSession(): void
    {
        session_start();
    }
};
$session->setLogger(\CodeIgniter\Config\Services::logger());
$session->start();
\CodeIgniter\Config\Services::injectMock('session', $session);
$securityConfiguration = new \Config\Security();
$securityConfiguration->csrfProtection = 'session';
\CodeIgniter\Config\Services::injectMock('security', new \CodeIgniter\Security\Security($securityConfiguration));
$appConfiguration = new \Config\App();
$browserBase = (string) getenv('B21_BROWSER_BASEURL');
if (! preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}/$~D', $browserBase)) {
    throw new RuntimeException('Browser base must be an explicit loopback address.');
}
$appConfiguration->baseURL = $browserBase;
$appConfiguration->indexPage = '';
\CodeIgniter\Config\Factories::injectMock('config', 'App', $appConfiguration);
$request = new \CodeIgniter\HTTP\IncomingRequest($appConfiguration, new \CodeIgniter\HTTP\SiteURI($appConfiguration, $_SERVER['REQUEST_URI']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod($_SERVER['REQUEST_METHOD']);
\CodeIgniter\Config\Services::injectMock('request', $request);
\CodeIgniter\Config\Services::injectMock('uri', $request->getUri());
try {
    if ($request->is('post')) {
        \CodeIgniter\Config\Services::security()->verify($request);
        if ($request->getPost('synthetic_lost_ack') === '1') {
            // Only this loopback synthetic router can inject a real post-COMMIT
            // fault plus one unavailable recovery; the next HTTP request is normal.
            $injected = false;
            \CodeIgniter\Events\Events::on('DBQuery', static function (\CodeIgniter\Database\Query $query) use (&$injected): void {
                if (! $injected && trim(strtoupper($query->getQuery())) === 'COMMIT') {
                    $injected = true;
                    throw new RuntimeException('Synthetic browser acknowledgement lost AFTER COMMIT');
                }
            });
            \Config\Services::injectMock('vehicleDamageRepairService', new class ($db) extends \App\Services\Fleet\VehicleDamageRepairService {
                private int $calls = 0;
                public function recordCostEntry(int $c, int $v, int $j, array $d, int $a): array
                {
                    return ++$this->calls === 1 ? parent::recordCostEntry($c, $v, $j, $d, $a) : ['success' => false, 'errors' => ['work' => 'Synthetic recovery temporarily unavailable']];
                }
            });
        }
    }
    $controller = new \App\Controllers\VehicleDamageRepairs();
    $controller->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    if ($path === '/fleet/vehicles/10/damage-repairs') {
        $result = $request->is('post') ? $controller->create(10) : $controller->index(10);
    } elseif ($path === '/fleet/vehicles/10/damage-repairs/new') {
        $result = $controller->new(10);
    } elseif (preg_match('~^/fleet/vehicles/10/damage-repairs/(\d+)/estimates(?:/(new)|/(\d+)/(revision|accept|reject|withdraw))?$~', $path, $match)) {
        if (($match[2] ?? '') === 'new') {
            $result = $controller->estimateForm(10, (int) $match[1]);
        } elseif (! empty($match[3])) {
            $method = ($match[4] === 'revision') ? ($request->is('post') ? 'createRevision' : 'estimateForm') : ['accept' => 'acceptEstimate', 'reject' => 'rejectEstimate', 'withdraw' => 'withdrawEstimate'][$match[4]];
            $result = $controller->{$method}(10, (int) $match[1], (int) $match[3]);
        } else {
            $result = $controller->createEstimate(10, (int) $match[1]);
        }
    } elseif (preg_match('~^/fleet/vehicles/10/damage-repairs/(\d+)/costs(?:/(new|review|finalize|invalidate)|/(\d+)/(replacement|replace|void))?$~', $path, $match)) {
        if (! empty($match[3])) {
            $method = ['replacement' => 'costForm', 'replace' => 'replaceCost', 'void' => 'voidCost'][$match[4]];
            $result = $controller->{$method}(10, (int) $match[1], (int) $match[3]);
        } else {
            $method = ['' => 'recordCost', 'new' => 'costForm', 'review' => 'reviewCost', 'finalize' => 'finalizeCost', 'invalidate' => 'invalidateCost'][$match[2] ?? ''];
            $result = $controller->{$method}(10, (int) $match[1]);
        }
    } elseif (preg_match('~^/fleet/vehicles/10/damage-repairs/(\d+)/documents(?:/(new)|/(\d+)/(archive|download))?$~', $path, $match)) {
        if (($match[2] ?? '') === 'new') {
            $result = $controller->documentForm(10, (int) $match[1]);
        } elseif (! empty($match[3])) {
            $result = $controller->{$match[4] === 'archive' ? 'archiveDocument' : 'downloadDocument'}(10, (int) $match[1], (int) $match[3]);
        } else {
            $result = $controller->attachDocument(10, (int) $match[1]);
        }
    } elseif (preg_match('~^/fleet/vehicles/10/damage-repairs/(\d+)(?:/(details|conditions|schedule|start|defer|resume|cancel|complete|reopen))?$~', $path, $match)) {
        $method = ['' => 'show', 'details' => 'correctDetails', 'conditions' => 'addCondition', 'reopen' => 'reopenJob', 'schedule' => 'schedule', 'start' => 'start', 'defer' => 'defer', 'resume' => 'resume', 'cancel' => 'cancel', 'complete' => 'complete'][$match[2] ?? ''];
        $result = $controller->{$method}(10, (int) $match[1]);
    } elseif (preg_match('~^/fleet/vehicles/10/damage-repairs/(\d+)/conditions/(\d+)/(withdraw|result|confirm-repair)$~', $path, $match)) {
        $method = ['withdraw' => 'withdrawCondition', 'result' => 'recordResult', 'confirm-repair' => $request->is('post') ? 'confirmRepair' : 'repairPreview'][$match[3]];
        $result = $controller->{$method}(10, (int) $match[1], (int) $match[2]);
    } elseif (preg_match('~^/fleet/vehicles/10/damage/(\d+)/reopen$~', $path, $match)) {
        $result = $request->is('post') ? $controller->reopenCondition(10, (int) $match[1]) : $controller->reopenConditionPreview(10, (int) $match[1]);
    } elseif ($path === '/fleet/vehicles/10' || $path === '/synthetic-checklist') {
        $assets = \Config\Services::assetManifestService()->appAssets();
        $data = ['vehicle' => $items->vehicle(1, 10), 'vehicleDamage' => $read->workspace(1, 10), 'damageIncidents' => [], 'notice' => null, 'errors' => [], 'form' => null, 'formData' => [], 'checklist' => ['id' => 321, 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 102]];
        $result = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/' . $assets['css'] . '"></head><body class="fleet-shell"><main class="command-main import-main">' . \CodeIgniter\Config\Services::renderer()->setData($data)->render($path === '/synthetic-checklist' ? 'trip_movement_checklists/_known_damage' : 'fleet_vehicles/components/vehicle_damage') . '</main></body></html>';
    } else {
        http_response_code(404);
        exit('Synthetic route not found.');
    }
    if ($result instanceof \CodeIgniter\HTTP\ResponseInterface) {
        $result->send();
    } else {
        header('Content-Length: ' . strlen($result));
        session_write_close();
        // The Windows development SAPI can reset a large buffered close-only response.
        // Keep this synthetic transport bounded; application rendering is unchanged.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        for ($offset = 0, $length = strlen($result); $offset < $length; $offset += 8192) {
            echo substr($result, $offset, 8192);
            flush();
            usleep(1000);
        }
    }
} catch (\CodeIgniter\Exceptions\PageNotFoundException) {
    http_response_code(404);
    echo 'Not found.';
} catch (Throwable $exception) {
    http_response_code(500);
    echo htmlspecialchars($exception::class . ': ' . $exception->getMessage());
}
