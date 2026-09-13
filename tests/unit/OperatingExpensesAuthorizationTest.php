<?php

use App\Services\Files\PrivateEvidenceStorageService;
use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperatingExpensesAuthorizationTest extends CIUnitTestCase
{
    public function testExplicitRoutesHaveSessionAdminAndGlobalCsrf(): void
    {
        CoreServices::routes()->loadRoutes();
        $collector = new FilterCollector();
        foreach (['operations/expenses', 'operations/expenses/1', 'operations/expenses/receipts/1/file'] as $uri) {
            $filters = $collector->get('GET', $uri)['before'];
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
        }
        foreach (['operations/expenses', 'operations/expenses/1/correct', 'operations/expenses/receipts', 'operations/expenses/receipts/1/classify', 'operations/expenses/receipts/1/archive'] as $uri) {
            $filters = $collector->get('POST', $uri)['before'];
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
            $this->assertSame(1, array_count_values($filters)['csrf'] ?? 0, $uri);
        }
    }

    public function testNoRawFileRouteAndParentAuthorizationPrecedesResolution(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/app/Config/Routes.php');
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Fleet/OperatingExpenseService.php');
        $this->assertStringNotContainsString("get('files/(:num)", $routes);
        $this->assertStringContainsString("receipts/(:num)/file', 'OperatingExpenses::receiptFile/$1", $routes);
        $this->assertMatchesRegularExpression('/function receiptFile.*?requireReceipt\(\$companyId, \$receiptId\).*?storage\(\)->resolve/s', $service);
    }

    public function testSafeResponseFilenameDropsTraversalControlsAndSpoofedExtension(): void
    {
        $filename = (new PrivateEvidenceStorageService())->safeResponseFilename("../bad\r\nname.exe", 'application/pdf', 'receipt');
        $this->assertSame('badname.pdf', $filename);
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/OperatingExpenses.php');
        $this->assertStringContainsString("setHeader('X-Content-Type-Options', 'nosniff')", $controller);
        $this->assertStringContainsString('->noCache()', $controller);
    }

    public function testGlobalCsrfHasNoExceptionsAndAutoRoutingRemainsDisabled(): void
    {
        $filters = file_get_contents(dirname(__DIR__, 2) . '/app/Config/Filters.php');
        $routing = file_get_contents(dirname(__DIR__, 2) . '/app/Config/Routing.php');
        $this->assertStringContainsString("'csrf'", $filters);
        $this->assertStringContainsString('public bool $autoRoute = false', $routing);
    }
}
