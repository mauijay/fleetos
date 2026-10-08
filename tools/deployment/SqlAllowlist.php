<?php

declare(strict_types=1);

namespace FleetOS\Deployment;

use InvalidArgumentException;

/** Offline, byte-strict SQL identity with one shared line-ending contract. */
final class SqlAllowlist
{
    public const CONTRACT = 'sql-line-endings-v1';

    public static function canonicalize(string $sql): string
    {
        return str_replace(["\r\n", "\r"], "\n", $sql);
    }

    /** @return array{number: int, sql: string, raw_sha256: string, canonical_sha256: string} */
    private static function entry(int $number, string $sql): array
    {
        return [
            'number' => $number,
            'sql' => $sql,
            'raw_sha256' => hash('sha256', $sql),
            'canonical_sha256' => hash('sha256', self::canonicalize($sql)),
        ];
    }

    /** @param array<mixed> $statements
     *  @return list<string>
     */
    public static function statementList(array $statements, bool $allowEmpty = false): array
    {
        if (!array_is_list($statements) || (!$allowEmpty && $statements === [])) {
            throw new InvalidArgumentException('A nonempty ordered SQL statement list is required.');
        }
        $snapshot = [];
        foreach ($statements as $sql) {
            if (!is_string($sql) || $sql === '') {
                throw new InvalidArgumentException('Each SQL statement must be a nonempty string.');
            }
            // Copy string values to detach any caller-owned array references.
            $snapshot[] = $sql;
        }

        return $snapshot;
    }

    /** @param array<mixed> $statements
     *  @return array{contract: string, statement_count: int, statements: list<array{number: int, sql: string, raw_sha256: string, canonical_sha256: string}>}
     */
    public static function generate(array $statements): array
    {
        $entries = [];
        foreach (self::statementList($statements) as $index => $sql) {
            $entries[] = self::entry($index + 1, $sql);
        }

        return ['contract' => self::CONTRACT, 'statement_count' => count($entries), 'statements' => $entries];
    }

    /** @param array<mixed> $manifest
     *  @return list<string>
     */
    public static function validate(array $manifest): array
    {
        if (($manifest['contract'] ?? null) !== self::CONTRACT
            || !isset($manifest['statements']) || !is_array($manifest['statements'])
            || !array_is_list($manifest['statements']) || $manifest['statements'] === []
            || ($manifest['statement_count'] ?? null) !== count($manifest['statements'])) {
            throw new InvalidArgumentException('Invalid SQL allowlist contract or statement count.');
        }
        $sql = [];
        foreach ($manifest['statements'] as $index => $entry) {
            if (!is_array($entry) || !isset($entry['sql']) || !is_string($entry['sql']) || $entry['sql'] === '') {
                throw new InvalidArgumentException('Invalid SQL allowlist statement ' . ($index + 1) . '.');
            }
            foreach (self::entry($index + 1, $entry['sql']) as $key => $value) {
                if (($entry[$key] ?? null) !== $value) {
                    throw new InvalidArgumentException('Invalid SQL allowlist identity at statement ' . ($index + 1) . '.');
                }
            }
            $sql[] = $entry['sql'];
        }

        return $sql;
    }

    /** Full ordered comparison before execution; reports hashes, never SQL.
     *  @param array<mixed> $manifest
     *  @param array<mixed> $actual
     *  @return array{passed: bool, expected_count: int, actual_count: int, statements: list<array<string, bool|int|string|null>>}
     */
    public static function compare(array $manifest, array $actual): array
    {
        $expected = self::validate($manifest);
        $actual = self::statementList($actual, true);
        $report = ['passed' => count($expected) === count($actual), 'expected_count' => count($expected), 'actual_count' => count($actual), 'statements' => []];
        for ($index = 0; $index < max(count($expected), count($actual)); $index++) {
            $left = isset($expected[$index]) ? self::entry($index + 1, $expected[$index]) : null;
            $right = isset($actual[$index]) ? self::entry($index + 1, $actual[$index]) : null;
            $equal = $left !== null && $right !== null
                && hash_equals($left['canonical_sha256'], $right['canonical_sha256'])
                && self::canonicalize($left['sql']) === self::canonicalize($right['sql']);
            $rawEqual = $left !== null && $right !== null && $left['sql'] === $right['sql'];
            $report['statements'][] = [
                'number' => $index + 1,
                'raw_expected_sha256' => $left['raw_sha256'] ?? null,
                'raw_actual_sha256' => $right['raw_sha256'] ?? null,
                'canonical_expected_sha256' => $left['canonical_sha256'] ?? null,
                'canonical_actual_sha256' => $right['canonical_sha256'] ?? null,
                'canonical_equal' => $equal,
                'line_ending_only_difference' => $equal && !$rawEqual,
            ];
            $report['passed'] = $report['passed'] && $equal;
        }

        return $report;
    }
}
