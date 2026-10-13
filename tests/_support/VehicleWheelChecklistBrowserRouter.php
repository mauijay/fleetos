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
$scenario = $_COOKIE['wheel_checklist_fixture'] ?? '390';
if (!in_array($scenario, ['390','1280','1920'], true)) {
    http_response_code(400);
    exit;
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
$database = new \Config\Database();
$database->tests['database'] = $directory . '/synthetic-' . $scenario . '.sqlite';
$database->tests['DBPrefix'] = '';
\CodeIgniter\Config\Factories::injectMock('config', 'Database', $database);
$db = \Config\Database::connect('tests');
if (! $db->tableExists('vehicle_damage_repair_jobs')) {
    $runner = (new \CodeIgniter\Database\MigrationRunner(new \Config\Migrations(), $db))->setNamespace('App');
    $runner->latest();
}
if ($db->table('companies')->countAllResults() === 0) {
    \Tests\Support\VehicleDamageDatabaseFixture::seed($db);
}
require __DIR__ . '/VehicleWheelChecklistBrowserSeed.php';
foreach ([
    'vehicleWheelChecklistService' => new \App\Services\Fleet\VehicleWheelChecklistService($db),
    'vehicleWheelConditionReadService' => new \App\Services\Fleet\VehicleWheelConditionReadService($db),
    'vehicleDamageRepository' => new \App\Repositories\VehicleDamageRepository($db),
    'vehicleDamageIncidentRepository' => new \App\Repositories\VehicleDamageIncidentRepository($db),
    'vehicleDamageOccurrenceRepository' => new \App\Repositories\VehicleDamageOccurrenceRepository($db),
    'vehicleWheelDamageService' => new \App\Services\Fleet\VehicleWheelDamageService($db),
    'vehicleDamageOccurrenceEvidenceService' => new \App\Services\Fleet\VehicleDamageOccurrenceEvidenceService($db),
] as $name => $instance) {
    \Config\Services::injectMock($name, $instance);
}
\Config\Services::injectMock('operationalFactsRepository', new class ($db) extends \App\Repositories\OperationalFactsRepository {
    public function activeFleetCompanyIds(?string $asOfDate = null): array
    {
        return [1];
    }
});
\CodeIgniter\Shield\Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
    public function loggedIn(): bool
    {
        return false;
    }

    public function user(): \CodeIgniter\Shield\Entities\User
    {
        return new class (['id' => 7, 'username' => 'synthetic-work-operator']) extends \CodeIgniter\Shield\Entities\User {
            public function getEmail(): ?string
            {
                return null;
            }
        };
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
    }
    $controller = new \App\Controllers\VehicleWheelHistory();
    $controller->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    $checklists = new \App\Controllers\TripMovementChecklists();
    $checklists->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    $actions = new \App\Controllers\VehicleWheelChecklist();
    $actions->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    if ($path === '/synthetic-wheel-counts' && $request->is('get')) {
        $rows = [];
        $firewall = [];
        foreach ($db->listTables() as $table) {
            if ($table === 'migrations') {
                continue;
            }
            $values = $db->table($table)->get()->getResultArray();
            $rows[$table] = ['count' => count($values),'hash' => hash('sha256', json_encode($values))];
            if (!str_starts_with($table, 'vehicle_damage_') && !in_array($table, ['audit_logs','files','images','vehicle_files','vehicle_images'], true)) {
                $firewall[$table] = $rows[$table];
            }
        }
        ksort($rows);
        ksort($firewall);
        header('Content-Type: application/json');
        echo json_encode(['rows' => $rows,'firewall' => $firewall,'readiness' => \Config\Services::movementReadinessReadService()->forCompany(1, [100,102,103,105], new \DateTimeImmutable(file_get_contents($directory.'/as-of-'.$scenario.'.txt'))),'custody' => \Config\Services::currentVehicleCustodyService()->resolve(10, new \DateTimeImmutable(file_get_contents($directory . '/as-of-' . $scenario . '.txt'))),'position' => \Config\Services::currentVehicleLocationService()->resolve(10, new \DateTimeImmutable(file_get_contents($directory . '/as-of-' . $scenario . '.txt')))]);
        exit;
    } elseif ($path === '/synthetic-concurrent-rash' && $request->is('post')) {
        $wheel = \App\Services\Fleet\VehicleWheelIdentity::normalize((string)$request->getPost('wheel'));
        $entry = \Config\Services::vehicleWheelDamageService()->entry(1, 10, $wheel);
        $result = \Config\Services::vehicleWheelDamageService()->createNew(1, 10, ['wheel_code' => $wheel,'expected_wheel_state' => $entry['state']['fingerprint'],'command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'note' => 'Concurrent synthetic rash','severity_code' => 'cosmetic','discovered_at' => date('Y-m-d H:i:s')], 7);
        header('Content-Type: application/json');
        echo json_encode($result + ['csrf' => \CodeIgniter\Config\Services::security()->getHash()]);
        exit;
    } elseif (preg_match('~^/operations/checklists/(\d+)/wheel-curb-rash/(LF|RF|RR|LR)/(new|worsening|unchanged)$~', $path, $match)) {
        $result = $request->is('post') ? $actions->command((int)$match[1], $match[2], $match[3]) : $actions->entry((int)$match[1], $match[2], $match[3]);
    } elseif (preg_match('~^/operations/checklists/(\d+)$~', $path, $match) && $request->is('get')) {
        $result = $checklists->show((int)$match[1]);
    } elseif ($request->is('get') && $path === '/fleet/vehicles/10/damage-ledger') {
        $result = $controller->ledger(10);
    } elseif ($request->is('get') && preg_match('~^/fleet/vehicles/10/wheel-history/(LF|RF|RR|LR|UNKNOWN)$~', $path, $match)) {
        $result = $controller->history(10, $match[1]);
    } elseif ($request->is('get') && preg_match('~^/fleet/vehicles/10/wheel-history/(\d+)/occurrences/(\d+)$~', $path, $match)) {
        $result = $controller->occurrence(10, (int)$match[1], (int)$match[2]);
    } elseif ($request->is('get') && preg_match('~^/fleet/vehicles/10/wheel-damage/(\d+)/occurrences/(\d+)/photos/(\d+)$~', $path, $match)) {
        $photos = new \App\Controllers\VehicleWheelDamage();
        $photos->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
        $result = $photos->viewPhoto(10, (int)$match[1], (int)$match[2], (int)$match[3]);
    } else {
        http_response_code(404);
        exit('Synthetic route unavailable.');
    }
    $created = [];
    foreach ($db->table('files')->get()->getResultArray() as $file) {
        if (str_starts_with($file['path'], 'damage-evidence/company-1/vehicle-10/')) {
            $created[] = rtrim((new \Config\Paths())->writableDirectory, '/\\').'/uploads/'.$file['path'];
        }
    }
    $manifest = $directory.'/created-files.json';
    $prior = is_file($manifest) ? json_decode(file_get_contents($manifest), true) : [];
    file_put_contents($manifest, json_encode(array_values(array_unique(array_merge($prior, $created)))));
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
} catch (\CodeIgniter\Security\Exceptions\SecurityException) {
    http_response_code(403);
    echo 'CSRF rejected.';
} catch (\CodeIgniter\Exceptions\PageNotFoundException) {
    http_response_code(404);
    echo 'Not found.';
} catch (Throwable $exception) {
    http_response_code(500);
    echo htmlspecialchars($exception::class . ': ' . $exception->getMessage());
}
