<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FleetUiFoundationTest extends CIUnitTestCase
{
    public function testSharedDangerAlertRendersSemanticHeadingAndValidationList(): void
    {
        $html = CoreServices::renderer()->setData([
            'type' => 'danger',
            'heading' => 'Please review',
            'messages' => ['Explain the business purpose for an Other operating expense.'],
            'list' => true,
        ])->render('components/alert');

        $this->assertStringContainsString('class="alert alert-danger"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('<strong>Please review</strong>', $html);
        $this->assertStringContainsString('<li>Explain the business purpose for an Other operating expense.</li>', $html);
    }

    public function testExpenseValidationUsesSharedAlertBeforeWorkflowAndInvalidFieldSemantics(): void
    {
        $html = CoreServices::renderer()->setData([
            'notice' => null,
            'warning' => null,
            'warningHeading' => 'Possible duplicate',
            'errors' => ['business_purpose' => 'Explain the business purpose for an Other operating expense.'],
        ])->render('operating_expenses/components/feedback');
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/operating_expenses/index.php');
        $alertPosition = strpos($view, "view('operating_expenses/components/feedback'");
        $workflowPosition = strpos($view, '<section class="section operator-work"');

        $this->assertIsInt($alertPosition);
        $this->assertIsInt($workflowPosition);
        $this->assertLessThan((int) $workflowPosition, (int) $alertPosition);
        $this->assertStringContainsString('<strong>Please review</strong>', $html);
        $this->assertStringContainsString('<li>Explain the business purpose for an Other operating expense.</li>', $html);
        $this->assertStringContainsString('aria-invalid="true"', $view);
        $this->assertStringContainsString('aria-describedby="new-expense-business-purpose-help', $view);
        $this->assertStringContainsString('class="field-error"', $view);
        $this->assertStringContainsString("(\$warning || \$errors !== []) && \$pendingReceiptId === 0 ? ' open' : ''", $view);
    }

    public function testGlobalDarkControlsCoverNativeSelectOptionsAndAccessibilityStates(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        foreach (['--control-bg:', '--control-text:', '--control-border:', '--danger-bg:', '--danger-border:', '--danger-text:'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        $this->assertMatchesRegularExpression('/select,\s*select option,\s*select optgroup\s*\{.*?color-scheme: dark;.*?background-color: var\(--control-bg\);.*?color: var\(--control-text\);/s', $css);
        $this->assertStringContainsString('select option:disabled', $css);
        $this->assertStringContainsString('input:not([type="checkbox"]):not([type="radio"])[aria-invalid="true"]', $css);
        $this->assertStringContainsString('input:-webkit-autofill', $css);
        $this->assertStringContainsString('.field-error', $css);
    }

    public function testNoBootstrapOrThirdPartyUiDependencyWasIntroduced(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/composer.json',
            dirname(__DIR__, 2) . '/package.json',
            dirname(__DIR__, 2) . '/resources/css/app.css',
        ];
        foreach ($files as $file) {
            $this->assertStringNotContainsString('bootstrap', strtolower((string) file_get_contents($file)), $file);
        }
    }
}
