<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use RuntimeException;
use Throwable;

class TuroUpdateEvCharging extends BaseCommand
{
    protected $group = 'Turo';
    protected $name = 'turo:update:ev-charging';
    protected $description = 'Safely updates only normalized On-trip and Post-trip EV charging amounts from a Turo CSV.';
    protected $usage = 'turo:update:ev-charging <file> --company COMPANY_ID [--user USER_ID] [--dry-run|--write] [--report FILE]';
    protected $arguments = [
        'file' => 'Path to the Turo trip earnings CSV file.',
    ];
    protected $options = [
        '--company' => 'Required FleetOS company id.',
        '--user' => 'Operator user id; required in write mode.',
        '--dry-run' => 'Plan and report without database writes (default).',
        '--write' => 'Apply the guarded, atomic EV-only update.',
        '--report' => 'Optional new JSON report path. Existing files are not overwritten.',
    ];

    public function run(array $params): int
    {
        $filePath = isset($params[0]) ? $this->absolutePath($params[0]) : null;
        $companyId = (int) (CLI::getOption('company') ?? 0);
        $actorOption = CLI::getOption('user');
        $actorUserId = $actorOption === null ? null : (int) $actorOption;
        $write = CLI::getOption('write') !== null;
        if ($filePath === null || $companyId < 1) {
            CLI::error('A CSV file and --company COMPANY_ID are required.');

            return EXIT_ERROR;
        }
        if ($write && CLI::getOption('dry-run') !== null) {
            CLI::error('Choose either --dry-run or --write, not both.');

            return EXIT_ERROR;
        }

        if ($write) {
            CLI::write('WRITE MODE: guarded EV charging update requested.', 'yellow');
            CLI::write('Company: ' . $companyId . ' | Source: ' . basename($filePath));
            CLI::write('Allowed fields: on_trip_ev_charging_amount, post_trip_ev_charging_amount');
        }

        try {
            $report = Services::turoEvChargingUpdateService()->execute($filePath, $companyId, $actorUserId, ! $write);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $reportPath = CLI::getOption('report');
            if (is_string($reportPath) && trim($reportPath) !== '') {
                $this->writeNewReport($this->absolutePath($reportPath), $json);
            }
            CLI::write($json);

            return $report['status'] === 'blocked' ? EXIT_ERROR : EXIT_SUCCESS;
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

    private function writeNewReport(string $path, string $contents): void
    {
        if (file_exists($path)) {
            throw new RuntimeException("Report file already exists: {$path}");
        }
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write report file: {$path}");
        }
    }
}
