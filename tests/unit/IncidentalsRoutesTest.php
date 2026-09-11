<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class IncidentalsRoutesTest extends CIUnitTestCase
{
    public function testIncidentalsRoutesKeepReadsOnGetAndMutationsOnCsrfProtectedPost(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/app/Config/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("get('operations/incidentals', 'Incidentals::index'", $routes);
        $this->assertStringContainsString("post('operations/incidentals/(:num)/invoice-sent'", $routes);
        $this->assertStringContainsString("post('operations/incidentals/(:num)/no-invoice-needed'", $routes);
        $this->assertStringContainsString("post('operations/incidentals/assignments'", $routes);
        $this->assertStringContainsString("permission:admin.access', 'csrf'", $routes);
        $this->assertStringNotContainsString('operations/reimbursements', $routes);
        $this->assertStringNotContainsString('reimbursement-evidence', $routes);
    }

    public function testIncidentalsPageRendersWithTheApplicationAssetShape(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/incidentals/index.php');
        $this->assertIsString($view);
        $this->assertStringContainsString('$assets[\'css\'] !== null', $view);
        $this->assertStringContainsString('/build/', $view);
        $this->assertStringNotContainsString('foreach ($assets[\'css\']', $view);
    }

    public function testIncidentalsPageKeepsTheOperatorHierarchyAccessibleAndResponsive(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root . '/app/Views/incidentals/index.php');
        $styles = file_get_contents($root . '/resources/css/app.css');

        $this->assertIsString($view);
        $this->assertIsString($styles);
        $this->assertStringContainsString('<body class="fleet-shell">', $view);
        $this->assertStringContainsString('class="app-frame import-frame incidentals-frame"', $view);
        $this->assertStringContainsString('aria-current="page"', $view);
        $this->assertStringContainsString('class="incidental-card-context"', $view);
        $this->assertStringContainsString('class="incidental-action-panel incidental-plan-panel"', $view);
        $this->assertStringContainsString('class="incidental-action-panel incidental-completion-panel"', $view);
        $this->assertStringContainsString('class="incidental-plan-correction"', $view);
        $this->assertStringContainsString('name="effective_from"', $view);
        $this->assertStringContainsString('name="scope" value="fleet"', $view);
        $this->assertStringContainsString('action="/operations/incidentals/assignments"', $view);
        $this->assertStringContainsString('class="policy-grid"', $view);
        $this->assertStringContainsString('$policy[\'earnings_plan_code\']', $view);
        $this->assertStringNotContainsString('$policy[\'plan_code\']', $view);
        $this->assertStringContainsString('<?= csrf_field() ?>', $view);

        $this->assertStringContainsString('.incidentals-main {', $styles);
        $this->assertStringContainsString('max-width: 1440px;', $styles);
        $this->assertStringContainsString('margin-inline: auto;', $styles);
        $this->assertStringContainsString('.incidentals-main select option {', $styles);
        $this->assertStringContainsString('@media (max-width: 1100px)', $styles);
        $this->assertStringContainsString('@media (max-width: 760px)', $styles);

        $migration = file_get_contents($root . '/app/Database/Migrations/2026-09-10-000018_CreateIncidentalEarningsPlanAssignments.php');
        $this->assertIsString($migration);
        $this->assertStringContainsString("createTable('incidental_earnings_plan_assignments')", $migration);
        $this->assertStringNotContainsString("'more_earnings'", $migration);
    }

}
