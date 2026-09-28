<?php

namespace App\Services\Tesla;

use App\Services\Turo\TuroCsvReader;
use DateTimeImmutable;
use RuntimeException;

class TeslaChargingSourceAnalyzer
{
    public function __construct(
        private readonly TuroCsvReader $reader = new TuroCsvReader(),
        private readonly TeslaChargingSourceIdentityService $identity = new TeslaChargingSourceIdentityService(),
    ) {
    }

    /** @param list<string> $filePaths @return array<string, mixed> */
    public function analyze(array $filePaths, int $companyId): array
    {
        if ($companyId < 1 || $filePaths === []) {
            throw new RuntimeException('A company id and at least one Tesla CSV are required.');
        }

        $knownLines = [];
        $knownSessions = [];
        $sources = [];
        foreach ($filePaths as $filePath) {
            $priorLines = $knownLines;
            $headers = array_map($this->identity->canonicalHeader(...), $this->reader->headers($filePath));
            $missing = array_values(array_diff(TeslaChargingSourceIdentityService::REQUIRED_HEADERS, $headers));
            if ($missing !== []) {
                throw new RuntimeException(basename($filePath) . ' is missing required headers: ' . implode(', ', $missing) . '.');
            }

            $sourceLines = [];
            $sourceSessions = [];
            $duplicateAgainstPrior = 0;
            $newLines = 0;
            $newSessions = [];
            $rows = 0;
            foreach ($this->reader->read($filePath) as $row) {
                $rows++;
                $payload = $this->identity->canonicalPayload($row->row);
                $this->validatePayload($payload, $row->rowNumber);
                $fingerprints = $this->identity->fingerprints($companyId, $payload);
                $line = $fingerprints['line_fingerprint'];
                $session = $fingerprints['session_fingerprint'];
                $sourceLines[$line] = $fingerprints['amount_cents'];
                $sourceSessions[$session] = true;
                if (isset($priorLines[$line])) {
                    $duplicateAgainstPrior++;
                }
                if (! isset($knownLines[$line])) {
                    $newLines++;
                    $knownLines[$line] = $fingerprints['amount_cents'];
                    if (! isset($knownSessions[$session])) {
                        $newSessions[$session] = true;
                    }
                }
                $knownSessions[$session] = true;
            }
            $hash = hash_file('sha256', $filePath);
            if (! is_string($hash)) {
                throw new RuntimeException('Unable to fingerprint ' . basename($filePath) . '.');
            }
            $sources[] = [
                'source_filename' => basename($filePath),
                'source_sha256' => $hash,
                'source_rows' => $rows,
                'unique_line_identities' => count($sourceLines),
                'session_identities' => count($sourceSessions),
                'positive_cost' => $this->decimalFromCents(array_sum(array_filter($sourceLines, static fn (int $cents): bool => $cents > 0))),
                'duplicates_against_prior' => $duplicateAgainstPrior,
                'new_lines' => $newLines,
                'new_sessions' => count($newSessions),
            ];
        }

        return [
            'company_id' => $companyId,
            'sources' => $sources,
            'combined_unique_lines' => count($knownLines),
            'combined_sessions' => count($knownSessions),
            'combined_positive_cost' => $this->decimalFromCents(array_sum(array_filter($knownLines, static fn (int $cents): bool => $cents > 0))),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload, int $rowNumber): void
    {
        foreach (['vin', 'invoice_number', 'charge_start_date_time', 'description'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new RuntimeException("Tesla CSV row {$rowNumber} is missing {$field}.");
            }
        }
        $timestamp = trim((string) $payload['charge_start_date_time']);
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $timestamp)) {
            throw new RuntimeException("Tesla CSV row {$rowNumber} has a timestamp without an offset.");
        }
        try {
            new DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            throw new RuntimeException("Tesla CSV row {$rowNumber} has an invalid timestamp.");
        }
        if ($this->identity->moneyCents($payload['total_inc_vat'] ?? null) === null) {
            throw new RuntimeException("Tesla CSV row {$rowNumber} has an invalid Total Inc. VAT value.");
        }
    }

    private function decimalFromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }
}
