<?php

/** Loopback-only synthetic movement browser harness; never an application entry point. */
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
foreach (['STDIN', 'STDOUT', 'STDERR'] as $stream) {
    if (! defined($stream)) {
        define($stream, fopen('php://temp', 'w+'));
    }
}
$root = dirname(__DIR__, 2);
$directory = realpath((string) getenv('HNL_BROWSER_DIR'));
$build = realpath($root . '/build');
if ($directory === false || $build === false || ! str_starts_with(strtolower($directory), strtolower($build . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Synthetic browser files must remain in build.');
}
$scenario = $_COOKIE['hnl_synthetic_fixture'] ?? (getenv('HNL_BROWSER_WIDTH') ?: '390');
error_log('Synthetic HNL start: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' / fixture ' . $scenario);
if (! in_array($scenario, ['390', '1280', '1920'], true)) {
    http_response_code(400);
    exit;
}
chdir($root);
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . '/public/');
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$database = new \Config\Database();
$database->tests['database'] = $directory . '/synthetic-' . $scenario . '.sqlite';
$database->tests['DBPrefix'] = '';
\CodeIgniter\Config\Factories::injectMock('config', 'Database', $database);
$db = \Config\Database::connect('tests');
if (! $db->tableExists('trip_movement_checklists')) {
    error_log('Synthetic HNL migrating fixture ' . $scenario);
    (new \CodeIgniter\Database\MigrationRunner(new \Config\Migrations(), $db))->setNamespace('App')->latest();
    error_log('Synthetic HNL seeding fixture ' . $scenario);
    \Tests\Support\HnlStagingChecklistFixture::seed($db, $scenario === '1280' ? 'gasoline' : 'electric');
}
error_log('Synthetic HNL database ready ' . $scenario);
\Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return false;
    }

    public function user(): \CodeIgniter\Shield\Entities\User
    {
        return new class (['id' => 7, 'username' => 'synthetic-hnl-operator']) extends \CodeIgniter\Shield\Entities\User {
            public function getEmail(): string
            {
                return 'synthetic-hnl@example.test';
            }
        };
    }
});
$sessionConfiguration = new \Config\Session();
$sessionConfiguration->savePath = $directory . '/sessions';
$sessionConfiguration->cookieName = 'hnl_synthetic_session';
if (! is_dir($sessionConfiguration->savePath)) {
    mkdir($sessionConfiguration->savePath);
}
$driver = new \CodeIgniter\Session\Handlers\FileHandler($sessionConfiguration, '127.0.0.1');
$driver->setLogger(\CodeIgniter\Config\Services::logger());
$session = new class ($driver, $sessionConfiguration) extends \CodeIgniter\Session\Session {
    protected function startSession(): void
    {
        session_start();
    }
};
$session->setLogger(\CodeIgniter\Config\Services::logger());
$session->start();
error_log('Synthetic HNL session ready ' . $scenario);
\CodeIgniter\Config\Services::injectMock('session', $session);
$security = new \Config\Security();
$security->csrfProtection = 'session';
\CodeIgniter\Config\Services::injectMock('security', new \CodeIgniter\Security\Security($security));
$app = new \Config\App();
$base = (string) getenv('HNL_BROWSER_BASEURL');
if (! preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}/$~D', $base)) {
    throw new RuntimeException('Browser origin must be explicit loopback.');
}
$app->baseURL = $base;
$app->indexPage = '';
\CodeIgniter\Config\Factories::injectMock('config', 'App', $app);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $_SERVER['REQUEST_URI']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod($_SERVER['REQUEST_METHOD']);
\CodeIgniter\Config\Services::injectMock('request', $request);
\CodeIgniter\Config\Services::injectMock('uri', $request->getUri());
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
error_log('Synthetic HNL request: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' / fixture ' . $scenario);
try {
    if ($request->is('post')) {
        \CodeIgniter\Config\Services::security()->verify($request);
    }
    $controller = new \App\Controllers\TripMovementChecklists();
    $controller->initController($request, \CodeIgniter\Config\Services::response(), \CodeIgniter\Config\Services::logger());
    if ($path === '/synthetic-state' && $request->is('get')) {
        header('Content-Type: application/json');
        $result = json_encode(['events' => $db->table('trip_movement_events')->orderBy('id')->get()->getResultArray(), 'assessments' => $db->table('movement_assessments')->get()->getResultArray(), 'airport_audits' => $db->table('airport_movement_audits')->countAllResults(), 'custody' => \Config\Services::currentVehicleCustodyService()->resolve(10), 'position' => \Config\Services::currentVehicleLocationService()->resolve(10), 'readiness' => \Config\Services::movementReadinessReadService()->forCompany(1, [100, 102, 103])], JSON_THROW_ON_ERROR);
    } elseif ($path === '/operations/checklists/102' && $request->is('get')) {
        $result = $controller->show(102);
    } elseif ($path === '/operations/checklists/102/stage-at-hnl' && $request->is('post')) {
        $result = $controller->stageAtHnl(102);
    } else {
        http_response_code(405);
        exit('Synthetic route or method unavailable.');
    }
    if ($result instanceof \CodeIgniter\HTTP\ResponseInterface) {
        $result->send();
    } else {
        header('Content-Length: ' . strlen($result));
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        for ($offset = 0, $length = strlen($result); $offset < $length; $offset += 8192) {
            echo substr($result, $offset, 8192);
            flush();
            usleep(1000);
        }
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo htmlspecialchars($exception::class . ': ' . $exception->getMessage());
}
