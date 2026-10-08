<?php

declare(strict_types=1);

use FleetOS\Deployment\SqlAllowlist;

require_once __DIR__ . '/SqlAllowlist.php';

// Offline only: never bootstrap the application or open a database connection.
try {
    if (PHP_SAPI !== 'cli' || !isset($argv)
        || !(($argv[1] ?? '') === 'generate' && count($argv) === 3
            || ($argv[1] ?? '') === 'verify' && count($argv) === 4)) {
        throw new InvalidArgumentException('Usage: php sql-allowlist.php generate statements.json | verify manifest.json statements.json');
    }
    $readJson = static function (string $path): array {
        if (!is_file($path)) {
            throw new InvalidArgumentException('SQL input must be a local regular file.');
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException('Cannot read SQL input file.');
        }
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('SQL input must be a JSON array or object.');
        }

        return $data;
    };
    $result = $argv[1] === 'generate'
        ? SqlAllowlist::generate($readJson($argv[2]))
        : SqlAllowlist::compare($readJson($argv[2]), $readJson($argv[3]));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit($argv[1] === 'generate' || $result['passed'] ? 0 : 2);
} catch (Throwable $error) {
    // JSON parse errors and input paths may contain sensitive data; omit details.
    fwrite(STDERR, 'SQL allowlist refused input (' . $error::class . "). No SQL executed.\n");
    exit(2);
}
