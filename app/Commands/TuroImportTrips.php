<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Throwable;

class TuroImportTrips extends BaseCommand
{
    protected $group = 'Turo';
    protected $name = 'turo:import:trips';
    protected $description = 'Imports a Turo trips CSV into raw, normalized, and month allocation tables.';
    protected $usage = 'turo:import:trips <file> [--user USER_ID]';
    protected $arguments = [
        'file' => 'Path to the Turo trips CSV file.',
    ];
    protected $options = [
        '--user' => 'Optional Shield user id to attach to import audit records.',
    ];

    public function run(array $params): int
    {
        $filePath = $params[0] ?? null;

        if ($filePath === null) {
            CLI::error('Missing required CSV file path.');
            CLI::write('Usage: php spark ' . $this->usage);

            return EXIT_ERROR;
        }

        $actorUserId = CLI::getOption('user');
        $actorUserId = $actorUserId === null ? null : (int) $actorUserId;

        try {
            $result = Services::turoTripImportService()->import($filePath, $actorUserId);
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Turo trips import complete.', 'green');
        CLI::write('Batch ID: ' . $result->batchId);
        CLI::write('Rows read: ' . $result->rowsRead);
        CLI::write('Raw rows created: ' . $result->rawRowsCreated);
        CLI::write('Trips normalized: ' . $result->tripsNormalized);
        CLI::write('Allocation rows created: ' . $result->allocationRowsCreated);
        CLI::write('Row issues: ' . $result->errorCount);

        return EXIT_SUCCESS;
    }
}
