<?php

// CLI-only contender for explicitly verified disposable local MariaDB fixtures.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$input = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$configuration = $input['configuration'];
$build = realpath(dirname(__DIR__, 2) . '/build');
$expected = realpath((string) getenv('B21_MARIADB_DATADIR'));
if ($build === false || $expected === false || ! str_starts_with(strtolower($expected), strtolower($build . DIRECTORY_SEPARATOR))
    || $configuration['hostname'] !== '127.0.0.1' || $configuration['port'] < 1024
    || ! preg_match('/^b21_synthetic_[a-f0-9]{16}$/D', $configuration['database'])) {
    throw new RuntimeException('Contender requires a disposable local database.');
}
$db = \Config\Database::connect($configuration, false);
$directory = $db->query('SELECT @@datadir AS directory')->getRowArray();
if (strtolower((string) realpath($directory['directory'])) !== strtolower($expected)) {
    throw new RuntimeException('Contender database directory mismatch.');
}
$db->query('SET SESSION innodb_lock_wait_timeout = 1');
$work = \Tests\Support\GuestCommitmentProjectionFixture::fulfillments($db);
$attempt = static function () use (&$work, $input): array {
    try {
        match ($input['operation']) {
            'complete' => $work->complete(1, 100, $input['id'], 7),
            'reopen' => $work->reopen(1, 100, $input['id'], 7),
            'reconcile' => $work->reconcileForTrip(1, 100, 7),
            'reactivate' => $work->reconcileSelectionIds(1, [$input['selection_id']], [$input['selection_id']], 7),
            default => throw new RuntimeException('Unknown synthetic operation.'),
        };
        return ['success' => true];
    } catch (Throwable $exception) {
        return ['success' => false, 'message' => $exception->getMessage()];
    }
};
$first = $attempt();
echo json_encode(['blocked' => ! $first['success'] && str_contains(strtolower($first['message']), 'lock wait timeout'), 'attempt' => $first], JSON_THROW_ON_ERROR) . PHP_EOL;
if (trim((string) fgets(STDIN)) !== 'retry') {
    exit(1);
}
$db->close();
$db = \Config\Database::connect($configuration, false);
$db->query('SET SESSION innodb_lock_wait_timeout = 1');
$work = \Tests\Support\GuestCommitmentProjectionFixture::fulfillments($db);
echo json_encode($attempt(), JSON_THROW_ON_ERROR) . PHP_EOL;
$db->close();
