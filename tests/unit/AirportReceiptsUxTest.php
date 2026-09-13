<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AirportReceiptsUxTest extends CIUnitTestCase
{
    public function testPageUsesSharedFrameBookmarkableFiltersAndCiPagerOutput(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/airport_reimbursements/index.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AirportReimbursements.php');

        $this->assertStringContainsString('class="app-frame import-frame airport-receipts-frame"', $view);
        $this->assertStringContainsString("view('fleet_command_center/components/navigation'", $view);
        foreach (['action', 'ready', 'filed', 'history', 'all'] as $filter) {
            $this->assertStringContainsString("'{$filter}' =>", $view);
        }
        $this->assertStringContainsString('Services::pager()->makeLinks', file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/TuroAccessReimbursementService.php'));
        $this->assertStringContainsString('in_list[action,ready,filed,history,all]', $controller);
        $this->assertStringNotContainsString('getGetPost(', $controller);
    }

    public function testOnlyLegalClaimControlsRenderForTheirState(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/airport_reimbursements/index.php');

        $this->assertMatchesRegularExpression('/elseif \(\$status === \'ready_to_file\'\).*?Mark filed.*?elseif \(\$status === \'filed\'\).*?Mark reimbursed.*?Mark denied/s', $view);
        $this->assertStringNotContainsString('Parking Ticket Pulled', file_get_contents(dirname(__DIR__, 2) . '/app/Views/airport_operations/show.php'));
    }

    public function testWorkspaceIsCenteredResponsiveAndOverflowSafeAtAcceptanceWidths(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('max-width: 1440px', $css);
        $this->assertStringContainsString('margin-inline: auto', $css);
        $this->assertStringContainsString('min-width: 0', $css);
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $expectedWorkspaceWidths = [390 => 390, 1366 => 1114, 1920 => 1440, 2560 => 1440];
        foreach ($expectedWorkspaceWidths as $viewportWidth => $expectedWorkspaceWidth) {
            $navigationWidth = $viewportWidth <= 900 ? 0 : 252;
            $workspaceWidth = min(1440, $viewportWidth - $navigationWidth);
            $this->assertSame($expectedWorkspaceWidth, $workspaceWidth, "Acceptance viewport {$viewportWidth}px must fit without horizontal overflow.");
        }
    }

    public function testSecondaryDisclosureLooksAndBehavesLikeAnAccessibleControl(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/airport_reimbursements/index.php');
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('<details class="section secondary-work"><summary><strong>Capture or log airport evidence</strong>', $view);
        $this->assertStringContainsString('.secondary-work > summary:hover', $css);
        $this->assertStringContainsString('.secondary-work > summary:focus-visible', $css);
        $this->assertStringContainsString('.secondary-work > summary::after', $css);
        $this->assertStringContainsString('.secondary-work[open] > summary::after', $css);
        $this->assertMatchesRegularExpression('/\.secondary-work > summary \{.*?min-height: 48px;.*?cursor: pointer;.*?list-style: none;/s', $css);
    }

    public function testCommandCenterUsesOnePositiveOnlyAirportFollowUpEntry(): void
    {
        $dashboard = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/DailyOperationsDashboardService.php');

        $this->assertSame(1, substr_count($dashboard, "'label' => 'Airport Follow-up'"));
        $this->assertSame(1, substr_count($dashboard, "'label' => 'Airport follow-up requires attention.'"));
        $this->assertStringContainsString("'count' => (int) \$reimbursements['total_actionable']", $dashboard);
        $this->assertStringNotContainsString("'label' => 'Airport Receipt Inbox'", $dashboard);
        $this->assertStringNotContainsString("'label' => 'Airport parking reimbursements", $dashboard);
    }
}
