<?php

namespace App\Commands;

use App\Services\Fleet\MovementProjectionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use InvalidArgumentException;

class BackfillMovementProjections extends BaseCommand
{
    protected $group = 'Fleet';
    protected $name = 'fleet:backfill:movement-projections';
    protected $description = 'Projects legacy movement checklists and airport workflows; dry-run by default.';
    protected $usage = 'fleet:backfill:movement-projections [--date YYYY-MM-DD | --from YYYY-MM-DD --to YYYY-MM-DD | --trip ID] [--apply]';
    protected $options = [
        '--date' => 'Project movements on one date.',
        '--from' => 'Project movements on or after this date.',
        '--to' => 'Project movements through this date.',
        '--trip' => 'Project one normalized trip by ID.',
        '--apply' => 'Persist missing projections.',
    ];

    public function run(array $params): int
    {
        try {
            $apply = CLI::getOption('apply') !== null;
            $summary = $this->runProjection(\Config\Services::movementProjectionService(), $apply);
        } catch (InvalidArgumentException $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }

        CLI::write($apply ? 'Movement projection backfill applied.' : 'Movement projection dry-run. No rows were written.', $apply ? 'green' : 'yellow');
        foreach ($summary as $label => $count) {
            CLI::write(ucwords(str_replace('_', ' ', $label)) . ': ' . $count);
        }

        return $summary['errors'] === 0 && $summary['conflicts'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /** @return array<string, int> */
    private function runProjection(MovementProjectionService $service, bool $apply): array
    {
        $trip = CLI::getOption('trip');
        $date = CLI::getOption('date');
        $from = CLI::getOption('from');
        $to = CLI::getOption('to');

        if ($trip !== null) {
            return $service->projectTrip((int) $trip, $apply, 'backfill');
        }
        if ($date !== null) {
            return $service->projectDate($this->date((string) $date), $apply, 'backfill');
        }
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Provide --date, --trip, or both --from and --to.');
        }

        return $service->projectRange($this->date((string) $from), $this->date((string) $to)->modify('+1 day'), $apply, 'backfill');
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $date;
    }
}
