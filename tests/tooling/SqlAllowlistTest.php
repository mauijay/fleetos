<?php

declare(strict_types=1);

namespace Tests\Tooling;

use FleetOS\Deployment\SqlAllowlist;
use FleetOS\Deployment\SqlExecutionGuard;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../tools/deployment/SqlExecutionGuard.php';

final class SqlAllowlistTest extends TestCase
{
    public function testIncidentLineEndingIdentity(): void
    {
        $expected = "CREATE TABLE synthetic (\r\n id INT\r\n);";
        $actual = "CREATE TABLE synthetic (\n id INT\n);";
        // The pre-fix authorization rejected these raw hashes.
        $this->assertNotSame(hash('sha256', $expected), hash('sha256', $actual));
        $this->assertSame(SqlAllowlist::canonicalize($expected), SqlAllowlist::canonicalize($actual));
        $this->assertTrue(SqlAllowlist::compare(SqlAllowlist::generate([$expected]), [$actual])['passed']);
    }

    public function testIncidentPrefixWouldPreviouslyApplyBeforeFifthStatementRejected(): void
    {
        $actual = self::plan();
        $expected = array_map(static fn (string $sql): string => str_replace("\n", "\r\n", $sql), $actual);
        $oldAllowed = array_map(static fn (string $sql): string => hash('sha256', $sql), $expected);
        $oldApplied = [];
        foreach ($actual as $index => $sql) {
            if (!in_array(hash('sha256', $sql), $oldAllowed, true)) {
                $this->assertSame(5, $index + 1);
                break;
            }
            $oldApplied[] = $index + 1;
        }
        $this->assertSame([1, 2, 3, 4], $oldApplied);
        $comparison = SqlAllowlist::compare(SqlAllowlist::generate($expected), $actual);
        $this->assertTrue($comparison['passed']);
        $this->assertTrue($comparison['statements'][4]['line_ending_only_difference']);
    }

    /** Synthetic structure only, including a trigger with internal semicolons.
     *  @return list<string>
     */
    private static function plan(): array
    {
        return [
            'CREATE TABLE synthetic_parent (id INT PRIMARY KEY);',
            'CREATE TABLE synthetic_owner (id INT PRIMARY KEY);',
            'CREATE TABLE synthetic_event (id INT PRIMARY KEY);',
            'ALTER TABLE synthetic_event ADD COLUMN link_id INT;',
            "CREATE TABLE synthetic_cost (\n id INT,\n name VARCHAR(64) DEFAULT 'a b',\n amount INT DEFAULT 12,\n UNIQUE INDEX ux_name (name),\n FOREIGN KEY (id) REFERENCES synthetic_parent(id) ON DELETE RESTRICT,\n CHECK (amount >= 0)\n);",
            'CREATE INDEX ix_amount ON synthetic_cost (amount);',
            'CREATE INDEX ix_owner ON synthetic_owner (id);',
            'CREATE INDEX ix_event ON synthetic_event (id);',
            "CREATE TRIGGER synthetic_guard BEFORE INSERT ON synthetic_cost FOR EACH ROW BEGIN\n SET NEW.amount = 12;\n END;",
            'CREATE INDEX ix_link ON synthetic_event (link_id);',
            'CREATE INDEX ix_cost ON synthetic_cost (id);',
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function lineEndings(): iterable
    {
        foreach (["\n" => 'LF', "\r\n" => 'CRLF', "\r" => 'CR'] as $left => $leftName) {
            foreach (["\n" => 'LF', "\r\n" => 'CRLF', "\r" => 'CR'] as $right => $rightName) {
                yield $leftName . '/' . $rightName => [$left, $right];
            }
        }
    }

    #[DataProvider('lineEndings')]
    public function testCrossPlatformFullPlanAndOriginalSqlExecution(string $left, string $right): void
    {
        $expected = array_map(static fn (string $sql): string => str_replace("\n", $left, $sql), self::plan());
        $actual = array_map(static fn (string $sql): string => str_replace("\n", $right, $sql), self::plan());
        $manifest = SqlAllowlist::generate($expected);
        $manifest = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $guard = new SqlExecutionGuard($manifest);
        $calls = [];
        $guard->execute($actual, static function (string $sql, int $number) use (&$calls, $guard): bool {
            // Entire comparison has passed before even statement 1 executes.
            self::assertTrue($guard->report()['comparison']['passed']);
            $calls[] = ['number' => $number, 'sql' => $sql];

            return true;
        });
        $this->assertSame($actual, array_column($calls, 'sql'));
        $this->assertSame(range(1, 11), array_column($calls, 'number'));
        $this->assertSame(range(1, 11), $guard->report()['completed_statement_numbers']);
        $this->assertSame('completed', $guard->report()['state']);
        $this->assertTrue($guard->report()['writes_must_remain_suspended']);
        $this->assertCount(11, $manifest['statements']);
        $this->assertSame(range(1, 11), array_column($manifest['statements'], 'number'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function strictDifferences(): iterable
    {
        yield 'table' => ['synthetic_cost', 'synthetic_changed'];
        yield 'column' => ['name VARCHAR', 'label VARCHAR'];
        yield 'type' => ['amount INT', 'amount BIGINT'];
        yield 'length' => ['VARCHAR(64)', 'VARCHAR(65)'];
        yield 'unique index' => ['UNIQUE INDEX', 'INDEX'];
        yield 'FK action' => ['ON DELETE RESTRICT', 'ON DELETE CASCADE'];
        yield 'CHECK' => ['amount >= 0', 'amount > 0'];
        yield 'removed clause' => [" CHECK (amount >= 0)\n", ''];
        yield 'added clause' => [');', ') ENGINE=InnoDB;'];
        yield 'quoted string' => ["'a b'", "'a c'"];
        yield 'numeric literal' => ['DEFAULT 12', 'DEFAULT 13'];
        yield 'semicolon removed' => [');', ')'];
        yield 'semicolon added' => [');', ');;'];
        yield 'boundary added' => [');', '); SELECT 1;'];
        yield 'literal space added' => ["'a b'", "'a  b'"];
        yield 'literal space removed' => ["'a b'", "'ab'"];
        yield 'harmless formatting' => ['VARCHAR(64)', 'VARCHAR( 64 )'];
        yield 'indentation removed' => ["\n id", "\nid"];
        yield 'tab' => ["\n id", "\n\tid"];
        yield 'case' => ['CREATE', 'create'];
        yield 'quoting' => ['synthetic_cost', '`synthetic_cost`'];
        yield 'comment' => ['CREATE TABLE', 'CREATE /* note */ TABLE'];
        yield 'blank line' => ["\n id", "\n\n id"];
        yield 'Unicode' => ["'a b'", "'a bé'"];
    }

    #[DataProvider('strictDifferences')]
    public function testMeaningfulDifferencesFailBeforeAnyExecution(string $from, string $to): void
    {
        $expected = self::plan();
        $actual = $expected;
        $actual[4] = str_replace($from, $to, $actual[4]);
        $this->assertNotSame($expected[4], $actual[4]);
        $this->assertRejectedWithoutExecution($expected, $actual, 5);
    }

    /** @param list<string> $expected
     *  @param list<string> $actual
     */
    private function assertRejectedWithoutExecution(array $expected, array $actual, int $statement): void
    {
        $guard = new SqlExecutionGuard(SqlAllowlist::generate($expected));
        $calls = 0;
        try {
            $guard->execute($actual, static function () use (&$calls): bool {
                $calls++;

                return true;
            });
            $this->fail('Unauthorized SQL reached executor.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('KEEP WRITES SUSPENDED', $error->getMessage());
        }
        $report = $guard->report();
        $this->assertSame(0, $calls);
        $this->assertSame([], $report['completed_statement_numbers']);
        $this->assertNull($report['attempted_statement_number']);
        $this->assertFalse($report['comparison']['passed']);
        $this->assertFalse($report['comparison']['statements'][$statement - 1]['canonical_equal']);
        $this->assertSame('rejected_before_execution', $report['state']);
    }

    public function testCountOrderAndStatementBoundariesAreAuthoritative(): void
    {
        $expected = self::plan();
        $extra = [...$expected, 'SELECT 123;'];
        $missing = array_slice($expected, 0, -1);
        $reordered = $expected;
        [$reordered[0], $reordered[1]] = [$reordered[1], $reordered[0]];
        $merged = [...array_slice($expected, 0, 9), $expected[9] . $expected[10]];
        $split = [...array_slice($expected, 0, 8), ...explode("\n", $expected[8]), ...array_slice($expected, 9)];
        foreach ([[$extra, 12], [$missing, 11], [$reordered, 1], [$merged, 10], [$split, 9], [[], 1]] as [$actual, $number]) {
            $this->assertRejectedWithoutExecution($expected, $actual, $number);
        }
    }

    public function testBomAndTerminalNewlineCountsRemainStrict(): void
    {
        $sql = 'SELECT 1;';
        foreach (["\xEF\xBB\xBF" . $sql, ' ' . $sql, $sql . ' ', $sql . "\n", $sql . "\r\n", $sql . "\r"] as $different) {
            $this->assertRejectedWithoutExecution([$sql], [$different], 1);
        }
        $this->assertRejectedWithoutExecution([$sql . "\n"], [$sql], 1);
        $this->assertRejectedWithoutExecution([$sql . "\n"], [$sql . "\n\n"], 1);
        $this->assertTrue(SqlAllowlist::compare(SqlAllowlist::generate([$sql . "\r\n\r\n"]), [$sql . "\n\n"])['passed']);
        $bytes = "\xEF\xBB\xBF\t SELECT 'é a  b';\r\n\rX\n ";
        $this->assertSame("\xEF\xBB\xBF\t SELECT 'é a  b';\n\nX\n ", SqlAllowlist::canonicalize($bytes));
        $this->assertSame(SqlAllowlist::canonicalize($bytes), SqlAllowlist::canonicalize(SqlAllowlist::canonicalize($bytes)));
        $this->assertNotSame(SqlAllowlist::canonicalize("SELECT 'é';"), SqlAllowlist::canonicalize("SELECT 'e\u{0301}';"));
    }

    public function testDiagnosticsContainOnlyNumberAndIdentityNotSql(): void
    {
        $expected = "SELECT 'synthetic private token';\r\n";
        $actual = str_replace("\r\n", "\n", $expected);
        $report = SqlAllowlist::compare(SqlAllowlist::generate([$expected]), [$actual]);
        $row = $report['statements'][0];
        $this->assertSame(1, $row['number']);
        $this->assertSame(hash('sha256', $expected), $row['raw_expected_sha256']);
        $this->assertSame(hash('sha256', $actual), $row['raw_actual_sha256']);
        $this->assertNotSame($row['raw_expected_sha256'], $row['raw_actual_sha256']);
        $this->assertSame($row['canonical_expected_sha256'], $row['canonical_actual_sha256']);
        $this->assertTrue($row['line_ending_only_difference']);
        $this->assertTrue($row['canonical_equal']);
        $this->assertStringNotContainsString('synthetic private token', json_encode($report, JSON_THROW_ON_ERROR));
        $mismatch = SqlAllowlist::compare(SqlAllowlist::generate([$expected]), [str_replace('token', 'changed', $actual)]);
        $this->assertFalse($mismatch['statements'][0]['canonical_equal']);
        $this->assertFalse($mismatch['statements'][0]['line_ending_only_difference']);
        $this->assertNotSame($mismatch['statements'][0]['canonical_expected_sha256'], $mismatch['statements'][0]['canonical_actual_sha256']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function corruptFields(): iterable
    {
        yield 'number' => ['number', 2];
        yield 'raw hash' => ['raw_sha256', str_repeat('0', 64)];
        yield 'canonical hash' => ['canonical_sha256', str_repeat('0', 64)];
        yield 'SQL' => ['sql', 'SELECT 2;'];
        yield 'empty SQL' => ['sql', ''];
    }

    #[DataProvider('corruptFields')]
    public function testCorruptManifestRefused(string $field, mixed $value): void
    {
        $manifest = SqlAllowlist::generate(['SELECT 1;']);
        $manifest['statements'][0][$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        new SqlExecutionGuard($manifest);
    }

    public function testContractCountAndMalformedInputFailClosed(): void
    {
        $manifest = SqlAllowlist::generate(['SELECT 1;']);
        $wrongContract = $manifest;
        $wrongContract['contract'] = 'unknown';
        $wrongCount = $manifest;
        $wrongCount['statement_count'] = 2;
        $wrongOrder = SqlAllowlist::generate(['SELECT 1;', 'SELECT 2;']);
        $wrongOrder['statements'] = array_reverse($wrongOrder['statements']);
        $legacy = [['sql' => 'SELECT 1;', 'sha256' => hash('sha256', 'SELECT 1;')]];
        foreach ([$wrongContract, $wrongCount, $wrongOrder, $legacy, []] as $bad) {
            try {
                SqlAllowlist::validate($bad);
                $this->fail('Malformed manifest accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[], ['key' => 'SELECT 1;'], [123], ['']] as $bad) {
            try {
                SqlAllowlist::generate($bad);
                $this->fail('Malformed SQL list accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function executionFailures(): iterable
    {
        yield 'lost acknowledgement' => [true];
        yield 'false result' => [false];
    }

    #[DataProvider('executionFailures')]
    public function testInterruptionReportsPartialStateAndNeverRetriesOrRollsBack(bool $throw): void
    {
        $guard = new SqlExecutionGuard(SqlAllowlist::generate(self::plan()));
        $calls = [];
        $executor = static function (string $sql, int $number) use (&$calls, $throw): bool {
            $calls[] = $number;
            if ($number === 5) {
                if ($throw) {
                    throw new RuntimeException('synthetic private connection details');
                }

                return false;
            }

            return true;
        };
        try {
            $guard->execute(self::plan(), $executor);
            $this->fail('Interrupted execution accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('private', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $report = $guard->report();
        $this->assertSame([1, 2, 3, 4, 5], $calls);
        $this->assertSame([1, 2, 3, 4], $report['completed_statement_numbers']);
        $this->assertSame(5, $report['attempted_statement_number']);
        $this->assertSame('interrupted_actual_state_requires_inspection', $report['state']);
        $this->assertTrue($report['writes_must_remain_suspended']);
        $this->assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
        try {
            $guard->execute(self::plan(), $executor);
            $this->fail('Blind retry accepted.');
        } catch (LogicException) {
            $this->assertSame($report, $guard->report());
        }
    }

    public function testCompletedOrRejectedGuardCannotBeReused(): void
    {
        foreach ([['SELECT 1;'], ['SELECT 2;']] as $actual) {
            $guard = new SqlExecutionGuard(SqlAllowlist::generate(['SELECT 1;']));
            $calls = 0;
            $executor = static function () use (&$calls): bool {
                $calls++;

                return true;
            };
            try {
                $guard->execute($actual, $executor);
            } catch (RuntimeException) {
            }
            $before = $calls;
            try {
                $guard->execute(['SELECT 1;'], $executor);
                $this->fail('Guard reused.');
            } catch (LogicException) {
                $this->assertSame($before, $calls);
            }
        }
    }

    public function testCallerReferencesCannotChangePreverifiedPlanDuringExecution(): void
    {
        $sql = 'SELECT 2;';
        $actual = ['SELECT 1;', &$sql];
        $manifest = SqlAllowlist::generate($actual);
        $manifestSql = 'SELECT 2;';
        $manifest['statements'][1]['sql'] = &$manifestSql;
        $guard = new SqlExecutionGuard($manifest);
        $manifestSql = 'SELECT unauthorized_manifest_change;';
        $executed = [];
        $guard->execute($actual, static function (string $query, int $number) use (&$sql, &$executed): bool {
            if ($number === 1) {
                $sql = 'SELECT unauthorized_execution_change;';
            }
            $executed[] = $query;

            return true;
        });
        $this->assertSame(['SELECT 1;', 'SELECT 2;'], $executed);
    }

    public function testMalformedExecutionInputNeverInvokesExecutor(): void
    {
        foreach ([[123], [''], ['key' => 'SELECT 1;']] as $invalid) {
            $guard = new SqlExecutionGuard(SqlAllowlist::generate(['SELECT 1;']));
            try {
                $guard->execute($invalid, static function (): bool {
                    self::fail('Malformed plan invoked executor.');
                });
                $this->fail('Malformed execution input accepted.');
            } catch (RuntimeException) {
                $this->assertSame('rejected_before_execution', $guard->report()['state']);
                $this->assertSame([], $guard->report()['completed_statement_numbers']);
                $this->assertNull($guard->report()['attempted_statement_number']);
            }
        }
    }
}
