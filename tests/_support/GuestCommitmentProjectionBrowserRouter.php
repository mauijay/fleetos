<?php

/** Loopback-only synthetic UI harness, never an application entry point. */
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = realpath((string) getenv('COMMITMENT_BROWSER_DIR'));
$build = realpath($root . '/build');
if ($directory === false || $build === false || ! str_starts_with(strtolower($directory), strtolower($build . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Synthetic browser files must remain in build.');
}
foreach (['STDIN', 'STDOUT', 'STDERR'] as $stream) {
    if (! defined($stream)) {
        define($stream, fopen('php://temp', 'w+'));
    }
}
chdir($root);
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$scenario = $_GET['scenario'] ?? $_COOKIE['commitment_synthetic_scenario'] ?? 'mixed';
if (! in_array($scenario, ['empty', 'unverified', 'purchased', 'manual', 'mixed', 'unmapped', 'stale', 'quantity', 'completed', 'overlap', 'logistics', 'prepaid', 'feature', 'disabled', 'long'], true)) {
    http_response_code(400);
    exit;
}
$config = new \Config\Database();
$config->tests['database'] = $directory . '/synthetic-' . $scenario . '.sqlite';
$initialize = ! is_file($config->tests['database']);
$baseDatabase = $directory . '/synthetic-base.sqlite';
if ($initialize && is_file($baseDatabase)) {
    copy($baseDatabase, $config->tests['database']);
}
$config->tests['DBPrefix'] = '';
\CodeIgniter\Config\Factories::injectMock('config', 'Database', $config);
$db = \Config\Database::connect('tests');
error_log('Synthetic commitments: database connected ' . $scenario);
$fixture = \Tests\Support\GuestCommitmentProjectionFixture::class;
if ($initialize) {
    if (! $db->tableExists('fleet_extras')) {
        (new \CodeIgniter\Database\MigrationRunner(new \Config\Migrations(), $db))->setNamespace('App')->latest();
        \Tests\Support\VehicleDamageDatabaseFixture::seed($db);
        $fixture::seed($db);
        $copy = new \SQLite3($baseDatabase);
        $db->connID->backup($copy);
        $copy->close();
    }
    error_log('Synthetic commitments: base fixture ready');
    if (in_array($scenario, ['empty', 'manual', 'unverified'], true)) {
        $fixture::snapshot($db, [], '2030-01-02 19:00:00', true, true);
    }
    if ($scenario === 'unverified') {
        $db->table('turo_extra_reservation_snapshots')->update(['snapshot_complete' => 0]);
    }
    if (in_array($scenario, ['manual', 'mixed', 'overlap', 'long'], true)) {
        $fixture::manual($db, ['fleet_extra_id' => $scenario === 'overlap' ? 301 : null]);
    }
    if ($scenario === 'overlap') {
        $fixture::snapshot($db, [$fixture::item(), $fixture::item(['reservation_state_extra_id' => '910002'])], '2030-01-02 19:00:00', true, true);
    }
    if ($scenario === 'unmapped') {
        $db->table('fleet_extra_source_mappings')->emptyTable();
    }
    if ($scenario === 'stale') {
        $db->table('turo_extra_reservation_snapshots')->update(['observed_at' => '2029-12-30 18:00:00']);
    }
    if ($scenario === 'quantity' || $scenario === 'long') {
        $fixture::snapshot($db, [$fixture::item(['quantity' => null, 'label' => $scenario === 'long' ? str_repeat('Synthetic-long-label-', 9) : 'Synthetic source gear'])], '2030-01-02 19:00:00', true, true);
    }
    if ($scenario === 'long') {
        $db->table('fleet_extras')->where('id', 301)->update(['display_name' => str_repeat('Synthetic-long-name-', 9)]);
    }
    if ($scenario === 'disabled') {
        $db->table('fleet_extras')->where('id', 301)->update(['active' => 0]);
    }
    if (in_array($scenario, ['logistics', 'prepaid', 'feature'], true)) {
        $db->table('fleet_extras')->where('id', 301)->update([
            'display_name' => match ($scenario) {
                'logistics' => 'Synthetic one-way option', 'prepaid' => 'Synthetic prepaid recharge', default => 'Synthetic FSD Upgrade'
            },
            'fulfillment_type' => match ($scenario) {
                'logistics' => 'logistics', 'prepaid' => 'informational', default => 'configure'
            },
            'fulfillment_phase' => $scenario === 'logistics' ? 'return' : ($scenario === 'prepaid' ? 'entire_trip' : 'preparation'),
            'requires_operator_confirmation' => $scenario === 'prepaid' ? 0 : 1, 'readiness_blocking' => $scenario === 'prepaid' ? 0 : 1,
            'default_action_label' => $scenario === 'prepaid' ? null : ($scenario === 'feature' ? 'Activation verification required' : 'Review planned return logistics'),
        ]);
        if ($scenario === 'logistics') {
            $db->table('scheduled_movement_locations')->insert(['turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10, 'movement_type' => 'return', 'location_class' => 'home', 'source_text' => 'Synthetic planned return location']);
        }
    }
    $fixture::fulfillments($db)->reconcileForTrip(1, 100);
    error_log('Synthetic commitments: scenario ready');
    if ($scenario === 'completed') {
        $id = $fixture::service($db)->forTrip(1, 100, $fixture::clock())['purchased'][0]['fulfillment_id'];
        $fixture::fulfillments($db)->complete(1, 100, (int) $id, 7);
    }
}
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
        return new class (['id' => 7, 'username' => 'synthetic-operator']) extends \CodeIgniter\Shield\Entities\User {
            public function getEmail(): string
            {
                return 'synthetic-operator@example.test';
            }
        };
    }
});
$sessionConfig = new \Config\Session();
$sessionConfig->savePath = $directory . '/sessions';
$sessionConfig->cookieName = 'commitment_synthetic_session';
if (! is_dir($sessionConfig->savePath)) {
    mkdir($sessionConfig->savePath);
}
$driver = new \CodeIgniter\Session\Handlers\FileHandler($sessionConfig, '127.0.0.1');
$driver->setLogger(\CodeIgniter\Config\Services::logger());
$session = new class ($driver, $sessionConfig) extends \CodeIgniter\Session\Session {
    protected function startSession(): void
    {
        session_start();
    }
};
$session->setLogger(\CodeIgniter\Config\Services::logger());
$session->start();
\CodeIgniter\Config\Services::injectMock('session', $session);
$security = new \Config\Security();
$security->csrfProtection = 'session';
\CodeIgniter\Config\Services::injectMock('security', new \CodeIgniter\Security\Security($security));
$app = new \Config\App();
$app->baseURL = (string) getenv('COMMITMENT_BROWSER_BASEURL');
$app->indexPage = '';
\CodeIgniter\Config\Factories::injectMock('config', 'App', $app);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $_SERVER['REQUEST_URI']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod($_SERVER['REQUEST_METHOD']);
\CodeIgniter\Config\Services::injectMock('request', $request);
\CodeIgniter\Config\Services::injectMock('uri', $request->getUri());
error_log('Synthetic commitments: session ready');
$manual = new \App\Services\Fleet\TripCommitmentService(new \App\Repositories\TripCommitmentRepository($db));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \CodeIgniter\Config\Services::security()->verify(\CodeIgniter\Config\Services::request());
    if (preg_match('~/extra-fulfillments/(\d+)/complete$~', $_SERVER['REQUEST_URI'], $match)) {
        $fixture::fulfillments($db)->complete(1, 100, (int) $match[1], 7);
    } elseif (preg_match('~/commitments/(\d+)/complete$~', $_SERVER['REQUEST_URI'], $match)) {
        $manual->complete(1, 100, (int) $match[1], 7);
    } else {
        http_response_code(404);
        exit;
    }
    header('Location: /synthetic?scenario=' . rawurlencode($scenario), true, 303);
    exit;
}
$projection = $fixture::service($db)->forTrip(1, 100, $fixture::clock());
error_log('Synthetic commitments: projection ready');
echo \CodeIgniter\Config\Services::renderer()->setData([
    'assets' => \Config\Services::assetManifestService()->appAssets(), 'navigation' => [],
    'workspace' => $manual->workspace(1, 100), 'projection' => $projection,
    'backLink' => ['label' => 'Synthetic movement workflow', 'href' => '/synthetic?scenario=mixed'],
    'editing' => null, 'formData' => [], 'success' => null, 'error' => null,
])->render('trip_commitments/index');
