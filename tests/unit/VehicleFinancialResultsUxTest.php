<?php

use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleFinancialResultsUxTest extends CIUnitTestCase
{
    public function testReportRendersApprovedTerminologyReconciliationSortingAndVehicleDrillDown(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/app/Views/vehicle_financial_results/index.php');

        foreach (['Vehicle Financial Results', 'Attributable Realized Operating Revenue', 'Attributable Realized Recoveries', 'Attributable Recorded Operating Costs', 'Attributable Net Realized Operating Result', 'Vehicle-attributable Costs', 'Fleet-wide / Unallocated Costs'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }
        $this->assertStringContainsString("<article class=\"cost-summary-card attributable-total\"><span>Vehicle-attributable Costs</span><strong><?= esc(\$money((float) \$report['reconciliation']['vehicle_costs'])) ?></strong></article>", $html);
        $this->assertStringContainsString('/reports/vehicle-financial-results/<?= (int) $vehicle[\'fleet_vehicle_id\'] ?>?', $html);
        $this->assertStringContainsString('$query(\'costs\')', $html);
        $this->assertStringContainsString('$query(\'net\')', $html);
        $this->assertStringNotContainsString('Vehicle Profit', $html);
        $this->assertStringNotContainsString('ROI', $html);
        $this->assertStringNotContainsString('Net Income', $html);
    }

    public function testRoutesAreSessionAndAdminProtectedAndControllerUsesGenericNotFound(): void
    {
        $routes = CoreServices::routes();
        $routes->loadRoutes();
        $collector = new FilterCollector();
        foreach (['reports/vehicle-financial-results', 'reports/vehicle-financial-results/99'] as $uri) {
            $filters = $collector->get('GET', $uri)['before'];
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
        }

        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/VehicleFinancialResults.php');
        $this->assertStringContainsString('throw PageNotFoundException::forPageNotFound()', $controller);
        $this->assertStringNotContainsString("getGet('company_id')", $controller);
    }

    public function testResponsiveTableAndCommandCenterIntegrationArePresentWithoutChangingExpenseHub(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');
        $commandCenter = file_get_contents(dirname(__DIR__, 2) . '/app/Views/fleet_command_center/index.php');
        $expenses = file_get_contents(dirname(__DIR__, 2) . '/app/Views/operating_expenses/index.php');

        $this->assertMatchesRegularExpression('/\.financial-table-scroll\s*\{[^}]*overflow-x: auto;/s', $css);
        $this->assertMatchesRegularExpression('/\.financial-results-table \.numeric,.*?text-align: right;.*?white-space: nowrap;/s', $css);
        $this->assertMatchesRegularExpression('/\.financial-report-summary\s*\{[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/s', $css);
        $this->assertMatchesRegularExpression('/\.financial-report-summary \.cost-summary-card\s*\{[^}]*border-top-width: 3px;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 760px\).*?\.financial-report-summary,.*?grid-template-columns: 1fr;/s', $css);
        $this->assertStringContainsString('View vehicle financial results', $commandCenter);
        $this->assertStringNotContainsString('Vehicle Financial Results', $expenses);
    }
}
