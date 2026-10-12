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
}
if ($path === '/synthetic-wheel-counts') {
    header('Content-Type: application/json');
    $counts = [];
    foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_incidents', 'vehicle_damage_incident_items', 'vehicle_damage_item_evidence', 'audit_logs', 'files'] as $table) {
        $counts[$table] = $db->table($table)->countAllResults();
    }
    echo json_encode($counts);
    exit;
}
foreach ([
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
    $controller = new \App\Controllers\VehicleWheelDamage();
    $controller->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    if ($path === '/fleet/vehicles/10/wheel-damage/new' && ! $request->is('post')) {
        $result = $controller->entry(10);
    } elseif ($request->is('post') && preg_match('~^/fleet/vehicles/10/wheel-damage/(new|worsening|unchanged)$~', $path, $match)) {
        $result = $controller->command(10, $match[1]);
    } elseif (preg_match('~^/fleet/vehicles/10/wheel-damage/(\d+)/occurrences/(\d+)(?:/photos(?:/(\d+))?)?$~', $path, $match)) {
        if ($request->is('post')) {
            $result = $controller->attachPhotos(10, (int) $match[1], (int) $match[2]);
        } elseif (! empty($match[3])) {
            $result = $controller->viewPhoto(10, (int) $match[1], (int) $match[2], (int) $match[3]);
        } else {
            $result = $controller->occurrence(10, (int) $match[1], (int) $match[2]);
        }
    } else {
        http_response_code(404);
        exit('Synthetic wheel route not found.');
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
