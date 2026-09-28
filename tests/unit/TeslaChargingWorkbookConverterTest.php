<?php

use App\Services\Tesla\TeslaChargingSourceAnalyzer;
use App\Services\Tesla\TeslaChargingWorkbookConverter;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TeslaChargingWorkbookConverterTest extends CIUnitTestCase
{
    private const HEADERS = [
        'ChargeStartDateTime', 'Name', 'Vin', 'Model', 'Country', 'SiteLocationName', 'Description',
        'QuantityBase', 'QuantityTier1', 'QuantityTier2', 'QuantityTier3', 'QuantityTier4', 'InvoiceNumber',
        'UnitCostBase', 'UnitCostTier1', 'UnitCostTier2', 'UnitCostTier3', 'UnitCostTier4',
        'VAT', 'Total Exc. VAT', 'Total Inc. VAT', 'Status', 'Invoice',
    ];

    public function testHeadersRowsOffsetsAndUtf8ArePreserved(): void
    {
        $xlsx = $this->workbook(self::HEADERS, [
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '9.3199999999999992', 'Synthetic café'),
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CONGESTION : NO_CHARGE', '0', 'Synthetic café'),
        ]);
        $csv = $this->outputPath();

        $result = (new TeslaChargingWorkbookConverter())->convert($xlsx, $csv);
        $rows = $this->csvRows($csv);

        $this->assertSame(self::HEADERS, $rows[0]);
        $this->assertSame(23, $result['column_count']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame('2026-09-24T17:15:51-10:00', $rows[1][0]);
        $this->assertSame('Synthetic café', $rows[1][1]);
        $this->assertSame(3, count($rows));
    }

    public function testMoneyUsesDecimalSafeTwoPlaceSerializationAndPreservesZero(): void
    {
        $xlsx = $this->workbook(self::HEADERS, [
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.1199999999999992'),
            $this->row('2026-09-24T18:15:51-10:00', 'SYNTH-INVOICE-2', 'CHARGING : NO_CHARGE', '0'),
            $this->row('2026-09-24T19:15:51-10:00', 'SYNTH-INVOICE-3', 'CHARGING : PAYMENT', '7.0000000000000007E-2'),
        ]);
        $csv = $this->outputPath();

        $result = (new TeslaChargingWorkbookConverter())->convert($xlsx, $csv);
        $rows = $this->csvRows($csv);

        $this->assertSame('8.12', $rows[1][20]);
        $this->assertSame('0.00', $rows[2][20]);
        $this->assertSame('0.07', $rows[3][20]);
        $this->assertSame('8.19', $result['positive_source_total']);
    }

    public function testMultipleInvoiceLinesRemainDistinctAndSessionCountIsStable(): void
    {
        $xlsx = $this->workbook(self::HEADERS, [
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.12'),
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CONGESTION : PAYMENT', '1.00'),
        ]);
        $csv = $this->outputPath();

        $result = (new TeslaChargingWorkbookConverter())->convert($xlsx, $csv);
        $rows = $this->csvRows($csv);

        $this->assertSame(2, $result['row_count']);
        $this->assertSame(1, $result['unique_session_count']);
        $this->assertSame(['CHARGING : PAYMENT', 'CONGESTION : PAYMENT'], [$rows[1][6], $rows[2][6]]);
    }

    public function testRepeatedConversionIsByteIdentical(): void
    {
        $xlsx = $this->workbook(self::HEADERS, [$this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.12')]);
        $first = $this->outputPath();
        $second = $this->outputPath();
        $converter = new TeslaChargingWorkbookConverter();

        $firstResult = $converter->convert($xlsx, $first);
        $secondResult = $converter->convert($xlsx, $second);

        $this->assertSame(file_get_contents($first), file_get_contents($second));
        $this->assertSame($firstResult['output_sha256'], $secondResult['output_sha256']);
    }

    public function testUnknownFutureColumnIsPreservedInsteadOfDropped(): void
    {
        $headers = [...self::HEADERS, 'FutureSyntheticField'];
        $row = [...$this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.12'), 'preserved-value'];
        $xlsx = $this->workbook($headers, [$row]);
        $csv = $this->outputPath();

        $result = (new TeslaChargingWorkbookConverter())->convert($xlsx, $csv);
        $rows = $this->csvRows($csv);

        $this->assertSame(24, $result['column_count']);
        $this->assertSame('FutureSyntheticField', $rows[0][23]);
        $this->assertSame('preserved-value', $rows[1][23]);
    }

    public function testReadOnlyAnalyzerUsesDeployedLineAndSessionIdentityAcrossFiles(): void
    {
        $first = $this->teslaCsv([
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.12'),
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CONGESTION : PAYMENT', '1.00'),
            $this->row('2026-09-25T17:15:51-10:00', 'SYNTH-INVOICE-2', 'CHARGING : PAYMENT', '4.00'),
        ]);
        $second = $this->teslaCsv([
            $this->row('2026-09-24T17:15:51-10:00', 'SYNTH-INVOICE-1', 'CHARGING : PAYMENT', '8.12'),
            $this->row('2026-09-26T17:15:51-10:00', 'SYNTH-INVOICE-3', 'CHARGING : PAYMENT', '2.50'),
        ]);

        $report = (new TeslaChargingSourceAnalyzer())->analyze([$first, $second], 1);

        $this->assertSame(3, $report['sources'][0]['unique_line_identities']);
        $this->assertSame(2, $report['sources'][0]['session_identities']);
        $this->assertSame(1, $report['sources'][1]['duplicates_against_prior']);
        $this->assertSame(1, $report['sources'][1]['new_lines']);
        $this->assertSame(1, $report['sources'][1]['new_sessions']);
        $this->assertSame(4, $report['combined_unique_lines']);
        $this->assertSame(3, $report['combined_sessions']);
        $this->assertSame('15.62', $report['combined_positive_cost']);
    }

    /** @return list<string> */
    private function row(string $timestamp, string $invoice, string $description, string $total, string $name = 'Synthetic Operator'): array
    {
        return [
            $timestamp,
            $name,
            'SYNTHVIN000000001',
            'synthetic-model',
            'US',
            'Synthetic Site',
            $description,
            '10.0000 kwh',
            'N/A',
            'N/A',
            'N/A',
            'N/A',
            $invoice,
            '0.50/kwh',
            'N/A',
            'N/A',
            'N/A',
            'N/A',
            '0',
            $total,
            $total,
            'PAID',
            'https://example.invalid/synthetic-invoice',
        ];
    }

    /** @param list<string> $headers @param list<list<string>> $rows */
    private function workbook(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tesla_xlsx_');
        @unlink($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="in" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $xml .= $this->xmlRow(1, $headers, []);
        foreach ($rows as $index => $row) {
            $xml .= $this->xmlRow($index + 2, $row, [18, 19, 20]);
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return $path;
    }

    /** @param list<string> $values @param list<int> $numericIndexes */
    private function xmlRow(int $rowNumber, array $values, array $numericIndexes): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($values as $index => $value) {
            $reference = $this->columnName($index) . $rowNumber;
            if (in_array($index, $numericIndexes, true)) {
                $xml .= '<c r="' . $reference . '"><v>' . $this->xml($value) . '</v></c>';
            } else {
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t>' . $this->xml($value) . '</t></is></c>';
            }
        }

        return $xml . '</row>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + (($number - 1) % 26)) . $name;
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function outputPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tesla_csv_');
        @unlink($path);

        return $path;
    }

    /** @return list<list<string>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function teslaCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tesla_source_');
        $handle = fopen($path, 'wb');
        fputcsv($handle, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }
}
