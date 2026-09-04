<?php

namespace App\Commands;

use App\Services\Fleet\ScheduledLocationBackfillService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class BackfillScheduledMovementLocations extends BaseCommand
{
    protected $group = 'Fleet';
    protected $name = 'fleet:backfill:movement-locations';
    protected $description = 'Backfills scheduled movement locations from retained Turo trip payloads; dry-run by default.';
    protected $usage = 'fleet:backfill:movement-locations [--apply]';
    protected $options = ['--apply' => 'Persist idempotent scheduled-location upserts.'];

    public function run(array $params): int
    {
        $apply = CLI::getOption('apply') !== null;
        $summary = (new ScheduledLocationBackfillService())->run($apply);

        CLI::write($apply ? 'Scheduled location backfill applied.' : 'Scheduled location backfill dry-run. No rows were written.', $apply ? 'green' : 'yellow');
        CLI::write('Trips scanned: ' . $summary['trips_scanned']);
        CLI::write('Locations found: ' . $summary['locations_found']);
        CLI::write('Unknown classifications: ' . $summary['unknown']);
        CLI::write('Rows requiring upsert: ' . $summary['would_upsert']);
        CLI::write('Rows upserted: ' . $summary['upserted']);
        CLI::write('Invalid payloads: ' . $summary['invalid_payloads']);

        return $summary['invalid_payloads'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
