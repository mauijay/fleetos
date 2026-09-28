<?php

namespace App\Services\Tesla;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class TeslaChargingWorkbookConverter
{
    private const CURRENCY_HEADERS = ['VAT', 'Total Exc. VAT', 'Total Inc. VAT'];

    /** @return array<string, mixed> */
    public function convert(string $inputPath, string $outputPath, string $sheetName = 'in', bool $overwrite = false): array
    {
        if (! is_file($inputPath) || ! is_readable($inputPath)) {
            throw new RuntimeException("XLSX file is not readable: {$inputPath}");
        }
        if (is_file($outputPath) && ! $overwrite) {
            throw new RuntimeException("Output CSV already exists: {$outputPath}");
        }
        if (realpath($inputPath) !== false && realpath($inputPath) === realpath($outputPath)) {
            throw new RuntimeException('Input and output paths must differ.');
        }

        $zip = new ZipArchive();
        if ($zip->open($inputPath) !== true) {
            throw new RuntimeException("Unable to open XLSX workbook: {$inputPath}");
        }
        try {
            $worksheetPath = $this->worksheetPath($zip, $sheetName);
            $sharedStrings = $this->sharedStrings($zip);
            $rows = $this->worksheetRows($zip, $worksheetPath, $sharedStrings);
        } finally {
            $zip->close();
        }
        if ($rows === []) {
            throw new RuntimeException("Worksheet {$sheetName} is empty.");
        }

        $headers = array_shift($rows);
        if ($headers === [] || in_array('', $headers, true)) {
            throw new RuntimeException('Worksheet headers must be non-empty.');
        }
        $columnCount = count($headers);
        $currencyIndexes = [];
        foreach ($headers as $index => $header) {
            if (in_array($header, self::CURRENCY_HEADERS, true)) {
                $currencyIndexes[$index] = true;
            }
        }

        $normalizedRows = [];
        $positiveCents = 0;
        $vins = [];
        $sessions = [];
        $vinIndex = array_search('Vin', $headers, true);
        $invoiceIndex = array_search('InvoiceNumber', $headers, true);
        $timestampIndex = array_search('ChargeStartDateTime', $headers, true);
        $siteIndex = array_search('SiteLocationName', $headers, true);
        $totalIndex = array_search('Total Inc. VAT', $headers, true);
        foreach ($rows as $rowNumber => $row) {
            if (count($row) > $columnCount) {
                throw new RuntimeException('Worksheet contains a value beyond the final header column at data row ' . ($rowNumber + 2) . '.');
            }
            $row = array_pad($row, $columnCount, '');
            foreach ($currencyIndexes as $index => $_) {
                if ($row[$index] !== '') {
                    $row[$index] = $this->decimalToTwoPlaces($row[$index]);
                }
            }
            if ($vinIndex !== false && trim($row[$vinIndex]) !== '') {
                $vins[strtoupper(trim($row[$vinIndex]))] = true;
            }
            if ($invoiceIndex !== false && $timestampIndex !== false && $siteIndex !== false && $vinIndex !== false) {
                $sessions[implode('|', [
                    strtoupper(trim($row[$vinIndex])),
                    trim($row[$invoiceIndex]),
                    trim($row[$timestampIndex]),
                    trim($row[$siteIndex]),
                ])] = true;
            }
            if ($totalIndex !== false && $row[$totalIndex] !== '') {
                $cents = $this->decimalCents($row[$totalIndex]);
                if ($cents > 0) {
                    $positiveCents += $cents;
                }
            }
            $normalizedRows[] = $row;
        }

        $directory = dirname($outputPath);
        if (! is_dir($directory)) {
            throw new RuntimeException("Output directory does not exist: {$directory}");
        }
        $temporary = tempnam($directory, '.tesla_csv_');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary output file.');
        }
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);
            throw new RuntimeException('Unable to open the temporary output file.');
        }
        try {
            $this->writeCsvRow($handle, $headers);
            foreach ($normalizedRows as $row) {
                $this->writeCsvRow($handle, $row);
            }
        } finally {
            fclose($handle);
        }
        if (is_file($outputPath) && ! @unlink($outputPath)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to replace output CSV: {$outputPath}");
        }
        if (! @rename($temporary, $outputPath)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to finalize output CSV: {$outputPath}");
        }

        $inputHash = hash_file('sha256', $inputPath);
        $outputHash = hash_file('sha256', $outputPath);
        if (! is_string($inputHash) || ! is_string($outputHash)) {
            throw new RuntimeException('Unable to hash conversion artifacts.');
        }

        return [
            'input_filename' => basename($inputPath),
            'input_sha256' => $inputHash,
            'output_filename' => basename($outputPath),
            'output_sha256' => $outputHash,
            'sheet' => $sheetName,
            'row_count' => count($normalizedRows),
            'column_count' => $columnCount,
            'positive_source_total' => $this->decimalFromCents($positiveCents),
            'unique_vin_count' => count($vins),
            'unique_session_count' => count($sessions),
        ];
    }

    private function worksheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbook = $this->xmlFromZip($zip, 'xl/workbook.xml');
        $workbookXPath = $this->xpath($workbook);
        $sheet = $workbookXPath->query('//x:sheets/x:sheet[@name=' . $this->xpathLiteral($sheetName) . ']')->item(0);
        if (! $sheet instanceof DOMElement) {
            throw new RuntimeException("Worksheet {$sheetName} was not found.");
        }
        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $relationships = $this->xmlFromZip($zip, 'xl/_rels/workbook.xml.rels');
        $relationshipXPath = new DOMXPath($relationships);
        $relationshipXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = $relationshipXPath->query('//r:Relationship[@Id=' . $this->xpathLiteral($relationshipId) . ']')->item(0);
        if (! $relationship instanceof DOMElement) {
            throw new RuntimeException("Worksheet relationship for {$sheetName} was not found.");
        }
        $target = str_replace('\\', '/', $relationship->getAttribute('Target'));

        return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $document = $this->xmlFromZip($zip, 'xl/sharedStrings.xml');
        $xpath = $this->xpath($document);
        $result = [];
        foreach ($xpath->query('//x:sst/x:si') as $item) {
            $text = '';
            foreach ($xpath->query('.//x:t', $item) as $textNode) {
                $text .= $textNode->textContent;
            }
            $result[] = $text;
        }

        return $result;
    }

    /** @param list<string> $sharedStrings @return list<list<string>> */
    private function worksheetRows(ZipArchive $zip, string $worksheetPath, array $sharedStrings): array
    {
        $document = $this->xmlFromZip($zip, $worksheetPath);
        $xpath = $this->xpath($document);
        $rows = [];
        foreach ($xpath->query('//x:sheetData/x:row') as $rowElement) {
            $row = [];
            foreach ($xpath->query('./x:c', $rowElement) as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $reference = $cell->getAttribute('r');
                $index = $this->columnIndex($reference);
                $type = $cell->getAttribute('t');
                $valueNode = $xpath->query('./x:v', $cell)->item(0);
                $value = $valueNode instanceof \DOMNode ? $valueNode->textContent : '';
                if ($type === 's' && $value !== '') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($xpath->query('.//x:is//x:t', $cell) as $textNode) {
                        $value .= $textNode->textContent;
                    }
                }
                $row[$index] = $value;
            }
            if ($row !== []) {
                $max = max(array_keys($row));
                $dense = array_fill(0, $max + 1, '');
                foreach ($row as $index => $value) {
                    $dense[$index] = $value;
                }
                $rows[] = $dense;
            } else {
                $rows[] = [];
            }
        }

        return $rows;
    }

    private function xmlFromZip(ZipArchive $zip, string $path): DOMDocument
    {
        $xml = $zip->getFromName($path);
        if (! is_string($xml)) {
            throw new RuntimeException("XLSX member is missing: {$path}");
        }
        $document = new DOMDocument();
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException("XLSX member is invalid XML: {$path}");
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xpath;
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"' . str_replace('"', '&quot;', $value) . '"';
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
            throw new RuntimeException("Invalid XLSX cell reference: {$reference}");
        }
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function decimalToTwoPlaces(string $value): string
    {
        $value = $this->expandScientificDecimal(trim($value));
        if (! str_contains($value, '.')) {
            $value .= '.';
        }
        if (! preg_match('/^([+-]?)(\d+)\.(\d*)$/', $value, $matches)) {
            throw new RuntimeException("Currency cell is not a decimal value: {$value}");
        }
        $sign = $matches[1] === '-' ? '-' : '';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[3], 3, '0');
        $hundredths = (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $hundredths++;
        }
        if ($hundredths === 100) {
            $whole = (string) ((int) $whole + 1);
            $hundredths = 0;
        }

        return $sign . $whole . '.' . str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT);
    }

    private function expandScientificDecimal(string $value): string
    {
        if (! str_contains(strtolower($value), 'e')) {
            return $value;
        }
        if (! preg_match('/^([+-]?)(\d+(?:\.\d*)?)[eE]([+-]?\d+)$/', $value, $matches)) {
            throw new RuntimeException("Currency cell is not a decimal value: {$value}");
        }
        $sign = $matches[1];
        $mantissa = $matches[2];
        $decimalPoint = strpos($mantissa, '.');
        $digits = str_replace('.', '', $mantissa);
        $decimalPosition = ($decimalPoint === false ? strlen($mantissa) : $decimalPoint) + (int) $matches[3];
        if ($decimalPosition <= 0) {
            return $sign . '0.' . str_repeat('0', -$decimalPosition) . $digits;
        }
        if ($decimalPosition >= strlen($digits)) {
            return $sign . $digits . str_repeat('0', $decimalPosition - strlen($digits));
        }

        return $sign . substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
    }

    private function decimalCents(string $value): int
    {
        if (! preg_match('/^(-?)(\d+)\.(\d{2})$/', $value, $matches)) {
            throw new RuntimeException("Normalized currency cell is invalid: {$value}");
        }

        $cents = ((int) $matches[2] * 100) + (int) $matches[3];

        return $matches[1] === '-' ? -$cents : $cents;
    }

    private function decimalFromCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** @param resource $handle @param list<string> $row */
    private function writeCsvRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '', "\n") === false) {
            throw new RuntimeException('Unable to write the converted CSV.');
        }
    }
}
