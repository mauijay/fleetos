<?php

namespace App\Services\Tesla;

class TeslaChargingSourceIdentityService
{
    public const REQUIRED_HEADERS = [
        'charge_start_date_time',
        'vin',
        'invoice_number',
        'site_location_name',
        'description',
        'total_inc_vat',
        'invoice',
    ];

    private const QUANTITY_FIELDS = ['quantity_base', 'quantity_tier1', 'quantity_tier2', 'quantity_tier3', 'quantity_tier4'];
    private const UNIT_COST_FIELDS = ['unit_cost_base', 'unit_cost_tier1', 'unit_cost_tier2', 'unit_cost_tier3', 'unit_cost_tier4'];

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function canonicalPayload(array $payload): array
    {
        $canonical = [];
        foreach ($payload as $header => $value) {
            $canonical[$this->canonicalHeader((string) $header)] = $value;
        }

        return $canonical;
    }

    public function canonicalHeader(string $header): string
    {
        return match ($header) {
            'chargestartdatetime' => 'charge_start_date_time',
            'sitelocationname' => 'site_location_name',
            'invoicenumber' => 'invoice_number',
            'quantitybase' => 'quantity_base',
            'quantitytier1' => 'quantity_tier1',
            'quantitytier2' => 'quantity_tier2',
            'quantitytier3' => 'quantity_tier3',
            'quantitytier4' => 'quantity_tier4',
            'unitcostbase' => 'unit_cost_base',
            'unitcosttier1' => 'unit_cost_tier1',
            'unitcosttier2' => 'unit_cost_tier2',
            'unitcosttier3' => 'unit_cost_tier3',
            'unitcosttier4' => 'unit_cost_tier4',
            default => $header,
        };
    }

    public function moneyCents(mixed $value): ?int
    {
        $text = trim((string) $value);
        $text = str_replace([',', '$'], '', $text);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function invoiceUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    public function quantityFields(array $payload): array
    {
        return $this->sourceFields($payload, self::QUANTITY_FIELDS);
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    public function unitCostFields(array $payload): array
    {
        return $this->sourceFields($payload, self::UNIT_COST_FIELDS);
    }

    /** @param array<string, mixed> $payload @return array{line_fingerprint:string,session_fingerprint:string,amount_cents:int} */
    public function fingerprints(int $companyId, array $payload): array
    {
        $vin = strtoupper(trim((string) ($payload['vin'] ?? '')));
        $invoice = trim((string) ($payload['invoice_number'] ?? ''));
        $sourceStartedAt = trim((string) ($payload['charge_start_date_time'] ?? ''));
        $site = trim((string) ($payload['site_location_name'] ?? ''));
        $description = strtoupper(trim((string) ($payload['description'] ?? '')));
        $amountCents = $this->moneyCents($payload['total_inc_vat'] ?? null);
        if ($companyId < 1 || $vin === '' || $invoice === '' || $sourceStartedAt === '' || $description === '' || $amountCents === null) {
            throw new \InvalidArgumentException('A complete, valid Tesla source identity is required.');
        }

        $identity = [
            'company_id' => $companyId,
            'vin' => $vin,
            'invoice' => $invoice,
            'started_at' => $sourceStartedAt,
            'site' => $site,
        ];
        $line = [
            ...$identity,
            'description' => $description,
            'quantity' => $this->quantityFields($payload),
            'unit_cost' => $this->unitCostFields($payload),
            'total_inc_vat_cents' => $amountCents,
            'invoice_url' => $this->invoiceUrl($payload['invoice'] ?? null),
        ];

        return [
            'line_fingerprint' => hash('sha256', $this->canonicalJson($line)),
            'session_fingerprint' => hash('sha256', $this->canonicalJson($identity)),
            'amount_cents' => $amountCents,
        ];
    }

    /** @param array<string, mixed> $value */
    public function canonicalJson(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload @param list<string> $fields @return array<string, string> */
    private function sourceFields(array $payload, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '') {
                $result[$field] = $value;
            }
        }

        return $result;
    }
}
