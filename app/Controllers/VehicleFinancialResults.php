<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class VehicleFinancialResults extends BaseController
{
    public function index(): string
    {
        $period = $this->period();
        $report = Services::vehicleFinancialSummaryService()->period(
            $this->activeCompanyId(),
            $period['from'],
            $period['to_exclusive'],
            $this->queryValue('sort'),
            $this->queryValue('direction'),
        );

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'period' => $period,
            'report' => $report,
        ])->render('vehicle_financial_results/index');
    }

    public function show(int $vehicleId): string
    {
        $period = $this->period();
        $report = Services::vehicleFinancialSummaryService()->period(
            $this->activeCompanyId(),
            $period['from'],
            $period['to_exclusive'],
        );
        $vehicle = null;
        foreach ($report['vehicles'] as $candidate) {
            if ((int) $candidate['fleet_vehicle_id'] === $vehicleId) {
                $vehicle = $candidate;
                break;
            }
        }
        if ($vehicle === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'period' => $period,
            'vehicle' => $vehicle,
        ])->render('vehicle_financial_results/show');
    }

    /** @return array{preset:string,from:string,to_inclusive:string,to_exclusive:string,label:string} */
    private function period(): array
    {
        $timezone = new DateTimeZone('Pacific/Honolulu');
        $today = new DateTimeImmutable('today', $timezone);
        $preset = $this->queryValue('period');
        if ($preset === 'previous') {
            $from = $today->modify('first day of previous month');
            $toExclusive = $today->modify('first day of this month');
        } elseif ($preset === 'custom') {
            $from = $this->date($this->queryValue('from'));
            $toInclusive = $this->date($this->queryValue('to'));
            if ($from === null || $toInclusive === null || $toInclusive < $from) {
                $preset = 'current';
                $from = $today->modify('first day of this month');
                $toExclusive = $from->modify('first day of next month');
            } else {
                $toExclusive = $toInclusive->modify('+1 day');
            }
        } else {
            $preset = 'current';
            $from = $today->modify('first day of this month');
            $toExclusive = $from->modify('first day of next month');
        }

        $toInclusive = $toExclusive->modify('-1 day');

        return [
            'preset' => $preset,
            'from' => $from->format('Y-m-d'),
            'to_inclusive' => $toInclusive->format('Y-m-d'),
            'to_exclusive' => $toExclusive->format('Y-m-d'),
            'label' => $from->format('M j, Y') . ' – ' . $toInclusive->format('M j, Y'),
        ];
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Pacific/Honolulu'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function queryValue(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(
            (new DateTimeImmutable('now', new DateTimeZone('Pacific/Honolulu')))->format('Y-m-d'),
        );
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Vehicle Financial Results requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Expenses & Receipts', 'href' => '/operations/expenses?view=needs_attention', 'active' => 'false'],
            ['label' => 'Airport Receipts', 'href' => '/operations/airport/reimbursements', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '/reports/vehicle-financial-results', 'active' => 'true'],
        ];
    }
}
