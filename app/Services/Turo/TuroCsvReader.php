<?php

namespace App\Services\Turo;

use App\DTOs\Turo\CsvRowResult;
use Generator;
use RuntimeException;
use SplFileObject;

class TuroCsvReader
{
    /** @return array<int, string> */
    public function headers(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new RuntimeException("CSV file is not readable: {$filePath}");
        }

        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        foreach ($file as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            return $this->normalizeHeaders($row);
        }

        return [];
    }

    /** @return Generator<int, CsvRowResult> */
    public function read(string $filePath): Generator
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new RuntimeException("CSV file is not readable: {$filePath}");
        }

        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = null;

        foreach ($file as $index => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            $payload = [];
            foreach ($headers as $columnIndex => $header) {
                $payload[$header] = isset($row[$columnIndex]) ? $this->cleanValue($row[$columnIndex]) : null;
            }

            yield new CsvRowResult($index + 1, $payload);
        }
    }

    /** @param array<int, string|null> $headers */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            $header = strtolower(trim((string) $header));
            $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';
            $header = trim($header, '_');
            $normalized[] = $header === '' ? 'column_' . count($normalized) : $header;
        }

        return $normalized;
    }

    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
