<?php

declare(strict_types=1);

namespace FleetOS\Deployment;

use LogicException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/SqlAllowlist.php';

/** Single-use ordered DDL plan guard. Does not connect, retry or roll back. */
final class SqlExecutionGuard
{
    /** @var array<string, mixed> */
    private array $report = [
        'state' => 'not_started',
        'completed_statement_numbers' => [],
        'attempted_statement_number' => null,
        'writes_must_remain_suspended' => true,
    ];
    private bool $started = false;
    /** @var array<mixed> */
    private readonly array $manifest;

    /** @param array<mixed> $manifest */
    public function __construct(array $manifest)
    {
        $this->manifest = SqlAllowlist::generate(SqlAllowlist::validate($manifest));
    }

    /** The executor must execute only its supplied SQL, once, and return true on acknowledgement.
     *  @param array<mixed> $actual
     *  @param callable(string, int): bool $executor
     */
    public function execute(array $actual, callable $executor): void
    {
        if ($this->started) {
            throw new LogicException('SQL guard is single-use; inspect ledger and DDL before explicit recovery authorization.');
        }
        $this->started = true;
        try {
            $actual = SqlAllowlist::statementList($actual, true);
            $this->report['comparison'] = SqlAllowlist::compare($this->manifest, $actual);
            if (!$this->report['comparison']['passed']) {
                $this->report['state'] = 'rejected_before_execution';
                throw new RuntimeException();
            }
            $this->report['state'] = 'executing';
            foreach ($actual as $index => $sql) {
                $this->report['attempted_statement_number'] = $index + 1;
                if ($executor($sql, $index + 1) !== true) {
                    throw new RuntimeException();
                }
                $this->report['completed_statement_numbers'][] = $index + 1;
            }
            $this->report['state'] = 'completed';
            // Other deployment gates, not this guard, decide when to restore access.
        } catch (Throwable $error) {
            if ($this->report['state'] === 'executing') {
                $this->report['state'] = 'interrupted_actual_state_requires_inspection';
            } elseif ($this->report['state'] !== 'rejected_before_execution') {
                $this->report['state'] = 'rejected_before_execution';
            }
            $this->report['error_class'] = $error::class;
            throw new RuntimeException('KEEP WRITES SUSPENDED: SQL plan stopped; inspect report, ledger and DDL. No retry or rollback.');
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->report;
    }
}
