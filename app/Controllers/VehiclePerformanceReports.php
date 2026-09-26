<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class VehiclePerformanceReports extends BaseController
{
    public function index(): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Pacific/Honolulu'));
        $report = Services::vehiclePerformanceReportService()->report(
            $this->activeCompanyId(),
            $today->format('Y-m-d'),
            $this->queryValue('sort'),
            $this->queryValue('direction'),
        );

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'report' => $report,
        ])->render('vehicle_performance_reports/index');
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
            throw new RuntimeException('Vehicle Performance requires exactly one active fleet company context.');
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
            ['label' => 'Reports', 'href' => '/reports/vehicle-performance', 'active' => 'true'],
        ];
    }
}
