<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Throwable;

class TeslaAnalyzeChargingSources extends BaseCommand
{
    protected $group = 'Tesla';
    protected $name = 'tesla:charging:analyze-sources';
    protected $description = 'Compares Tesla CSV source identities with production-equivalent fingerprints without database writes.';
    protected $usage = 'tesla:charging:analyze-sources <first.csv> [<next.csv> ...] --company COMPANY_ID';
    protected $arguments = [
        'files' => 'One or more Tesla charging CSV files in chronological snapshot order.',
    ];
    protected $options = [
        '--company' => 'Required FleetOS company id used by the deployed fingerprint identity.',
    ];

    public function run(array $params): int
    {
        $companyOption = CLI::getOption('company');
        $companyId = (int) ($companyOption ?? 0);
        $files = array_values($params);
        if (count($files) > 1 && $companyOption !== null && (string) end($files) === (string) $companyOption) {
            array_pop($files);
        }
        if ($files === [] || $companyId < 1) {
            CLI::error('At least one CSV and --company COMPANY_ID are required.');

            return EXIT_ERROR;
        }

        try {
            $paths = array_map($this->absolutePath(...), $files);
            $result = Services::teslaChargingSourceAnalyzer()->analyze($paths, $companyId);
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }
    }

    private function absolutePath(string $path): string
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1
            ? $path
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
