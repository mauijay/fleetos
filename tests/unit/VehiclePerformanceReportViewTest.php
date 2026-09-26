<?php

use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehiclePerformanceReportViewTest extends CIUnitTestCase
{
    public function testViewContainsExactColumnsTotalsNeutralNullAndNoDatePicker(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/vehicle_performance_reports/index.php');

        foreach (['Vehicle', 'In Service Date', 'MTD Earnings', 'YTD Earnings', 'Lifetime Earnings'] as $column) {
            $this->assertStringContainsString($column, $view);
        }
        $this->assertStringContainsString('Fleet total', $view);
        $this->assertStringContainsString('Unallocated / Unmatched', $view);
        $this->assertStringContainsString("\$value === null ? '—'", $view);
        $this->assertStringContainsString('number_format(abs($value), 2)', $view);
        $this->assertStringContainsString('/fleet/vehicles/<?= (int) $row[\'fleet_vehicle_id\'] ?>', $view);
        $this->assertStringNotContainsString('type="date"', $view);
        $this->assertStringNotContainsString('<form', $view);
        foreach (['profit', 'ROI', 'utilization', 'trip count', 'ADR', 'forecast'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $view);
        }
    }

    public function testSortingLinksAreAllowlistedByTheServiceAndUseOnlyKnownKeys(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/vehicle_performance_reports/index.php');
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/VehiclePerformanceReportService.php');

        foreach (['vehicle', 'in_service_date', 'mtd', 'ytd', 'lifetime'] as $sort) {
            $this->assertStringContainsString("\$query('{$sort}'", $view);
            $this->assertStringContainsString("'{$sort}'", $service);
        }
        $this->assertStringContainsString("private const SORTS = ['vehicle', 'in_service_date', 'mtd', 'ytd', 'lifetime'];", $service);
    }

    public function testRouteIsSessionAndAdminProtectedAndNavigationExposesReport(): void
    {
        $routes = CoreServices::routes();
        $routes->loadRoutes();
        $filters = (new FilterCollector())->get('GET', 'reports/vehicle-performance')['before'];

        $this->assertContains('session', $filters);
        $this->assertContains('permission:admin.access', $filters);

        $navigation = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/FleetCommandCenterViewModelService.php');
        $this->assertStringContainsString("['label' => 'Reports', 'href' => '/reports/vehicle-performance'", $navigation);
    }

    public function testResponsiveTableStacksWithoutHorizontalScrollingAtFleetMobileBreakpoints(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/vehicle_performance_reports/index.php');

        $this->assertMatchesRegularExpression('/@media \(max-width: 760px\).*?\.vehicle-performance-scroll\s*\{[^}]*overflow: visible;/s', $css);
        $this->assertMatchesRegularExpression('/\.vehicle-performance-table\s*\{[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.vehicle-performance-table th,.*?grid-template-columns: minmax\(108px, 0\.8fr\) minmax\(0, 1fr\);/s', $css);
        $this->assertStringContainsString('data-label="MTD Earnings"', $view);
        $this->assertStringContainsString('data-label="Lifetime Earnings"', $view);
    }

    public function testImplementationUsesTwoReadQueriesAndNoMutationOrSchemaPath(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/VehiclePerformanceReportService.php');
        $repository = file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/TuroNormalizedTransactionRepository.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/VehiclePerformanceReports.php');

        $this->assertStringContainsString("'total_query_count' => 2", $service);
        $this->assertStringContainsString("->where('txn.event_class', 'operating_revenue')", $repository);
        $this->assertStringContainsString("->where('txn.transaction_date <', \$toDateExclusive)", $repository);
        $this->assertStringNotContainsString('insert(', $controller . $service);
        $this->assertStringNotContainsString('update(', $controller . $service);
        $this->assertStringNotContainsString('delete(', $controller . $service);
    }
}
