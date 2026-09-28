<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Throwable;

class TeslaConvertChargingWorkbook extends BaseCommand
{
    protected $group = 'Tesla';
    protected $name = 'tesla:charging:xlsx-to-csv';
    protected $description = 'Converts a Tesla charging XLSX worksheet to deterministic importer-compatible CSV without importing it.';
    protected $usage = 'tesla:charging:xlsx-to-csv <input.xlsx> <output.csv> [--sheet in] [--force]';
    protected $arguments = [
        'input' => 'Input Tesla XLSX workbook.',
        'output' => 'Output UTF-8 CSV path.',
    ];
    protected $options = [
        '--sheet' => 'Worksheet name (default: in).',
        '--force' => 'Replace an existing output CSV.',
    ];

    public function run(array $params): int
    {
        $input = isset($params[0]) ? $this->absolutePath($params[0]) : null;
        $output = isset($params[1]) ? $this->absolutePath($params[1]) : null;
        if ($input === null || $output === null) {
            CLI::error('Input XLSX and output CSV paths are required.');

            return EXIT_ERROR;
        }

        try {
            $result = Services::teslaChargingWorkbookConverter()->convert(
                $input,
                $output,
                (string) (CLI::getOption('sheet') ?? 'in'),
                CLI::getOption('force') !== null,
            );
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
