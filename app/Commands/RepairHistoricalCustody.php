<?php

namespace App\Commands;

use App\Services\Fleet\HistoricalPairedMovementRepairService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use InvalidArgumentException;

/** Trusted server CLI only; no route or ordinary checklist exposure. */
final class RepairHistoricalCustody extends BaseCommand
{
    protected $group = 'Fleet';
    protected $name = 'fleet:repair:historical-custody';
    protected $description = 'Historical custody repair: preview or atomically apply an audited closed interval.';
    protected $usage = 'fleet:repair:historical-custody --request <private.json> --actor <superadmin-id> [--apply --confirm <preview-sha256>]';
    protected $options = [
        '--request' => 'Private JSON request, at most 64 KiB.',
        '--actor' => 'Existing active superadmin actor; checked server-side.',
        '--apply' => 'Explicitly apply; default is SELECT-only preview.',
        '--confirm' => 'Exact preview hash required for apply.',
    ];

    public function run(array $params): int
    {
        try {
            $path = CLI::getOption('request');
            $actor = filter_var(CLI::getOption('actor'), FILTER_VALIDATE_INT);
            if (! is_string($path) || ! is_file($path) || filesize($path) > 65536 || $actor === false || $actor < 1) {
                throw new InvalidArgumentException('Provide a bounded private request file and superadmin actor.');
            }
            $json = file_get_contents($path, false, null, 0, 65537);
            if ($json === false || strlen($json) > 65536) {
                throw new InvalidArgumentException('Request file is unreadable or exceeds 64 KiB.');
            }
            $request = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($request) || array_is_list($request)) {
                throw new InvalidArgumentException('Request must be a JSON object.');
            }
            $service = new HistoricalPairedMovementRepairService(Database::connect());
            $result = CLI::getOption('apply') === null
                ? $service->preview($request, $actor)
                : $service->apply($request, $actor, (string) CLI::getOption('confirm'));
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return EXIT_SUCCESS;
        } catch (\Throwable $exception) {
            CLI::error($exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
