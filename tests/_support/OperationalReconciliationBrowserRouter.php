<?php

/** CLI-only synthetic loopback renderer. No production configuration or public entry point. */
if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['--seed', '--render'], true)) {
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = realpath((string) getenv('RECONCILIATION_BROWSER_DIR'));
$build = realpath($root . '/build');
if ($directory === false || $build === false || ! str_starts_with(strtolower($directory), strtolower($build . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Synthetic browser files must remain in this worktree build directory.');
}
$scenario = $argv[2] ?? '';
if (! in_array($scenario, ['p0', 'p1', 'checklist', 'historical', 'healthy', 'pagination'], true)) {
    exit(1);
}
chdir($root);
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . '/public/');
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = new \Config\Database();
$config->tests['database'] = $directory . '/synthetic-' . $scenario . '.sqlite';
$config->tests['DBPrefix'] = '';
\CodeIgniter\Config\Factories::injectMock('config', 'Database', $config);
$db = \Config\Database::connect('tests');
if ($argv[1] === '--seed') {
    if (is_file($config->tests['database']) && $db->tableExists('fleet_vehicles')) {
        throw new RuntimeException('Seed requires a fresh synthetic database.');
    }
    $memory = \Tests\Support\GuestCommitmentProjectionFixture::sqlite();
    \Tests\Support\OperationalReconciliationUiFixture::configure($memory, $scenario);
    (new \CodeIgniter\Database\MigrationRunner(new \Config\Migrations(), $memory))->setNamespace('CodeIgniter\\Shield')->latest();
    $db->initialize();
    $memory->connID->backup($db->connID);
    $memory->close();
    file_put_contents($directory . '/' . $scenario . '-before.json', json_encode(\Tests\Support\OperationalReconciliationFixture::fingerprint($db), JSON_THROW_ON_ERROR));
    exit(0);
}
$uri = $argv[3] ?? '/operations/reconciliation';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
\Config\Services::injectMock('auth', new \Tests\Support\ReconciliationUiAuth());
$app = new \Config\App();
$app->baseURL = (string) getenv('RECONCILIATION_BROWSER_BASEURL');
$app->indexPage = '';
\CodeIgniter\Config\Factories::injectMock('config', 'App', $app);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $uri), null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod('GET');
$request->setGlobal('get', $_GET);
\CodeIgniter\Config\Services::injectMock('request', $request);
\CodeIgniter\Config\Services::injectMock('uri', $request->getUri());
$path = parse_url($uri, PHP_URL_PATH);
if ($path === '/synthetic-state') {
    echo json_encode(['status' => 200, 'body' => json_encode(\Tests\Support\OperationalReconciliationFixture::fingerprint($db), JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
    exit;
}
try {
    $controller = null;
    $render = null;
    if ($path === '/operations/reconciliation') {
        $controller = new \App\Controllers\OperationalReconciliation();
        $render = static fn () => $controller->index();
    } elseif ($path === '/') {
        $controller = new \App\Controllers\Home();
        $render = static fn () => $controller->index();
    } elseif (preg_match('~^/operations/vehicles/(\d+)/trip-history$~D', $path, $matches)) {
        $controller = new \App\Controllers\TripMovementChecklists();
        $render = static fn () => $controller->vehicleTripHistory((int) $matches[1]);
    } elseif (preg_match('~^/operations/checklists/(\d+)$~D', $path, $matches)) {
        $controller = new \App\Controllers\TripMovementChecklists();
        $render = static fn () => $controller->show((int) $matches[1]);
    } elseif (preg_match('~^/fleet/vehicles/(\d+)$~D', $path, $matches)) {
        $controller = new \App\Controllers\VehicleCapital();
        $render = static fn () => $controller->show((int) $matches[1]);
    } elseif (preg_match('~^/operations/trips/(\d+)/commitments$~D', $path, $matches)) {
        $controller = new \App\Controllers\TripCommitments();
        $render = static fn () => $controller->index((int) $matches[1]);
    } elseif ($path === '/turo/import-issues') {
        $controller = new \App\Controllers\TuroImportIssues();
        $render = static fn () => $controller->index();
    }
    if ($controller === null) {
        echo json_encode(['status' => 404, 'body' => 'Synthetic route not found.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $response = \CodeIgniter\Config\Services::response();
    $controller->initController($request, $response, \CodeIgniter\Config\Services::logger());
    $result = $render();
    echo json_encode(['status' => $result instanceof \CodeIgniter\HTTP\ResponseInterface ? $result->getStatusCode() : 200, 'body' => $result instanceof \CodeIgniter\HTTP\ResponseInterface ? $result->getBody() : $result], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    error_log((string) $exception);
    echo json_encode(['status' => 500, 'body' => 'Synthetic review failed. Inspect the local validation log.'], JSON_THROW_ON_ERROR);
}
