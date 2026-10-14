<?php

namespace App\Commands;

use App\Services\Fleet\HistoricalMissingHandoffRepairService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use InvalidArgumentException;

/** Trusted server CLI only; no route or ordinary checklist exposure. */
final class RepairHistoricalMissingHandoff extends BaseCommand
{
    protected $group = 'Fleet';
    protected $name = 'fleet:repair:missing-handoff';
    protected $description = 'historical_missing_handoff.v1: preview or apply one missing handoff, preserving an existing recovery.';
    protected $usage = 'fleet:repair:missing-handoff --request <private.json> --actor <superadmin-id> [--apply --confirm <preview-sha256>]';
    protected $options = [
        '--request' => 'Private JSON request, at most 64 KiB.',
        '--actor' => 'Existing active superadmin actor; checked server-side.',
        '--apply' => 'Explicitly apply; default is SELECT-only preview.',
        '--confirm' => 'Exact preview hash required for apply.',
    ];

    public function run(array $params): int
    {
        try {
            if (PHP_SAPI !== 'cli') {
                throw new InvalidArgumentException('Historical handoff repair is trusted server CLI only.');
            }
            $path = CLI::getOption('request');
            $actorValue = CLI::getOption('actor');
            $actor = is_string($actorValue) ? filter_var($actorValue, FILTER_VALIDATE_INT) : false;
            if (! is_string($path) || ! is_file($path) || filesize($path) > 65536 || $actor === false || $actor < 1) {
                throw new InvalidArgumentException('Provide a bounded private request file and superadmin actor.');
            }
            $resolved = realpath($path);
            $public = realpath(defined('FCPATH') ? constant('FCPATH') : dirname(__DIR__, 2) . '/public');
            if ($resolved === false || ($public !== false && (strcasecmp($resolved, $public) === 0 || str_starts_with(strtolower($resolved), strtolower($public . DIRECTORY_SEPARATOR)))) || (DIRECTORY_SEPARATOR === '/' && (fileperms($resolved) & 0077) !== 0)) {
                throw new InvalidArgumentException('Request must be outside the public directory with private filesystem permissions.');
            }
            $json = file_get_contents($resolved, false, null, 0, 65537);
            if ($json === false || strlen($json) > 65536) {
                throw new InvalidArgumentException('Request file is unreadable or exceeds 64 KiB.');
            }
            $request = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($request) || array_is_list($request)) {
                throw new InvalidArgumentException('Request must be a JSON object.');
            }
            $service = new HistoricalMissingHandoffRepairService(Database::connect());
            $result = CLI::getOption('apply') === null
                ? $service->preview($request, $actor)
                : $service->apply($request, $actor, (string) CLI::getOption('confirm'));
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return EXIT_SUCCESS;
        } catch (\Throwable $exception) {
            CLI::error(HistoricalMissingHandoffRepairService::OPERATION . ': ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
