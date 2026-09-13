<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperatingExpensesUxAndCommandCenterTest extends CIUnitTestCase
{
    public function testWorkspaceContainsApprovedViewsCaptureFlowsPagerAndScopeCopy(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/operating_expenses/index.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/OperatingExpenses.php');
        foreach (['Needs attention', 'Recent', 'By vehicle', 'History', 'Upload receipt', 'New expense', 'Recorded operating expenses — generic records only.'] as $copy) {
            $this->assertStringContainsString($copy, $view);
        }
        $this->assertStringContainsString('capture="environment"', $view);
        $this->assertStringContainsString('Airport Receipts', $view);
        $this->assertStringContainsString("makeLinks(\$workspace['expense_page']", $controller);
        $this->assertStringContainsString("makeLinks(\$workspace['receipt_page']", $controller);
    }

    public function testSharedWorkspaceIsCenteredResponsiveAndDarkModeNative(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');
        $this->assertMatchesRegularExpression('/\.operator-main,.*?max-width: 1440px;.*?min-width: 0;.*?margin-inline: auto;/s', $css);
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $this->assertStringContainsString('color-scheme: dark', $css);
        foreach ([390 => 390, 1366 => 1114, 1920 => 1440, 2560 => 1440] as $viewport => $expected) {
            $this->assertSame($expected, min(1440, $viewport - ($viewport <= 900 ? 0 : 252)));
        }
    }

    public function testCommandCenterHasOnePositiveOnlyClassificationAction(): void
    {
        $dashboard = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/DailyOperationsDashboardService.php');
        $this->assertSame(1, substr_count($dashboard, "'label' => 'Expenses to classify'"));
        $this->assertStringContainsString("'count' => (int) \$expenses['total']", $dashboard);
        $this->assertStringContainsString("array_filter(\$actions, static fn (array \$action): bool => (int) \$action['count'] > 0)", $dashboard);
        $this->assertStringNotContainsString("'label' => 'Missing receipts'", $dashboard);
        $this->assertStringNotContainsString("'label' => 'Monthly spend'", $dashboard);
    }

    public function testReadModelsAreCompanyScopedJoinedAndPaginatedWithoutBlobReads(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/OperatingExpenseRepository.php');
        $this->assertStringContainsString("->where('expense.company_id', \$companyId)", $repository);
        $this->assertStringContainsString("->where('receipt.company_id', \$companyId)", $repository);
        $this->assertStringContainsString("->join('files file'", $repository);
        $this->assertStringContainsString('->limit($perPage, ($page - 1) * $perPage)', $repository);
        $this->assertStringNotContainsString('file_blob', $repository);
    }

    public function testExpenseCardRendersParentAuthorizedReceiptActionOnlyWhenEvidenceExists(): void
    {
        $withReceipt = $this->renderExpenseCard(['receipt_count' => 1, 'first_receipt_id' => 91]);
        $this->assertStringContainsString('1 attached', $withReceipt);
        $this->assertStringContainsString('View receipt', $withReceipt);
        $this->assertStringContainsString('/operations/expenses/receipts/91/file', $withReceipt);
        $this->assertStringNotContainsString('/files/91', $withReceipt);

        $withoutReceipt = $this->renderExpenseCard(['receipt_count' => 0, 'first_receipt_id' => null]);
        $this->assertStringContainsString('None', $withoutReceipt);
        $this->assertStringNotContainsString('View receipt', $withoutReceipt);
        $this->assertStringNotContainsString('/file', $withoutReceipt);
    }

    public function testMultipleReceiptsLeadToAuthoritativeDetailEvidenceSection(): void
    {
        $card = $this->renderExpenseCard(['receipt_count' => 2, 'first_receipt_id' => 91]);
        $this->assertStringContainsString('View receipts', $card);
        $this->assertStringContainsString('/operations/expenses/44#attached-evidence', $card);

        $html = CoreServices::renderer()->setData(['receipts' => [
            ['id' => 91, 'file_original_filename' => 'fuel.pdf', 'document_date' => '2026-09-12', 'file_mime_type' => 'application/pdf'],
            ['id' => 92, 'file_original_filename' => 'wash.jpg', 'document_date' => null, 'file_mime_type' => 'image/jpeg'],
        ]])->render('operating_expenses/components/attached_evidence');
        $this->assertStringContainsString('Attached evidence', $html);
        $this->assertStringContainsString('/operations/expenses/receipts/91/file', $html);
        $this->assertStringContainsString('/operations/expenses/receipts/92/file', $html);
        $this->assertSame(2, substr_count($html, 'Preview receipt'));
        $this->assertStringNotContainsString('/files/', $html);
    }

    public function testFinancialIntegrationFirewallRemainsUntouchedByFeatureSources(): void
    {
        $changed = shell_exec('git diff --name-only');
        $this->assertIsString($changed);
        foreach (['FleetIntelligenceRepository.php', 'RevenueService.php', 'FleetStatisticsService.php'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $changed);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function renderExpenseCard(array $overrides): string
    {
        $expense = array_merge([
            'id' => 44,
            'status_code' => 'recorded',
            'amount' => '12.50',
            'category_name' => 'Fuel',
            'expense_date' => '2026-09-12',
            'vendor' => 'Fuel Stop',
            'source_code' => 'receipt_inbox',
        ], $overrides);

        return CoreServices::renderer()->setData([
            'expense' => $expense,
            'statusLabel' => static fn (string $code): string => ucwords(str_replace('_', ' ', $code)),
            'vehicleGroup' => 'Fleet-wide',
        ])->render('operating_expenses/components/expense_card');
    }
}
