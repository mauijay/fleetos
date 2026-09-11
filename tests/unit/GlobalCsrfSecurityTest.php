<?php

use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Filters;

/** @internal */
final class GlobalCsrfSecurityTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        CoreServices::superglobals()->setFilesArray([]);
    }

    protected function tearDown(): void
    {
        CoreServices::superglobals()->setFilesArray([]);
        parent::tearDown();
    }

    public function testGlobalConfigurationContainsCsrfExactlyOnceWithoutExceptionsOrMethodRules(): void
    {
        $config = new Filters();

        $this->assertSame(['csrf'], $config->globals['before']);
        $this->assertSame([], $config->methods);
        $this->assertSame([], $config->filters);
        $this->assertNotContains('csrf', $config->required['before']);

        $routes = file_get_contents(dirname(__DIR__, 2) . '/app/Config/Routes.php');
        $this->assertIsString($routes);
        $this->assertSame(0, substr_count($routes, "'csrf'"));
    }

    public function testRepresentativeUnsafeRoutesResolveGlobalCsrfAndKeepRouteAuthorization(): void
    {
        CoreServices::routes()->loadRoutes();
        $collector = new FilterCollector();

        foreach ([
            'fleet/vehicles/1',
            'operations/checklists/1/complete',
            'fleet/vehicles/1/current-position',
            'fleet/vehicles/1/current-readiness',
            'operations/incidentals/1/invoice-sent',
            'operations/incidentals/policies/1/approve',
            'operations/incidentals/assignments',
            'turo/imports',
        ] as $uri) {
            $filters = $collector->get('POST', $uri)['before'];
            $this->assertSame(1, array_count_values($filters)['csrf'] ?? 0, $uri);
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
        }

        foreach (['login', 'operations/airport/1/staging', 'operations/airport/reimbursements/unmatched-receipt'] as $uri) {
            $this->assertSame(1, array_count_values($collector->get('POST', $uri)['before'])['csrf'] ?? 0, $uri);
        }

        $this->assertContains('csrf', $collector->get('GET', 'operations/incidentals')['before']);
        $this->assertContains('csrf', $collector->get('GET', 'login')['before']);
    }

    public function testRepresentativeGetRemainsUnaffectedWithoutToken(): void
    {
        $this->withRoutes([
            ['GET', 'csrf-probe', static fn (): string => 'safe controller reached'],
        ]);

        $response = $this->get('/csrf-probe');

        $response->assertOK();
        $response->assertSee('safe controller reached');
    }

    public function testMissingTokenIsRejectedBeforeRepresentativeApplicationPost(): void
    {
        $this->withRoutes([
            ['POST', 'csrf-probe', static fn (): string => 'controller reached'],
        ]);

        $this->expectException(SecurityException::class);
        $this->post('/csrf-probe', ['value' => 'unsafe']);
    }

    public function testMissingTokenIsRejectedBeforeAirportPost(): void
    {
        $this->withRoutes([
            ['POST', 'operations/airport/1/staging', static fn (): string => 'airport controller reached'],
        ]);

        $this->expectException(SecurityException::class);
        $this->post('/operations/airport/1/staging', ['parking_row' => 'A']);
    }

    public function testValidTokenPassesTheGlobalLayerAndRegeneratesAfterPost(): void
    {
        $this->withRoutes([
            ['POST', 'csrf-probe', static fn (): string => 'controller reached'],
        ]);
        $security = CoreServices::security();
        $tokenName = $security->getTokenName();
        $token = $security->getHash();
        $this->assertNotNull($token);

        $response = $this->post('/csrf-probe', [$tokenName => $token]);

        $response->assertOK();
        $response->assertSee('controller reached');
        $this->assertNotSame($token, $security->getHash());
    }

    public function testLoginPostWithExistingFormTokenPassesTheGlobalLayer(): void
    {
        $loginView = file_get_contents(dirname(__DIR__, 2) . '/app/Views/auth/login.php');
        $this->assertIsString($loginView);
        $this->assertStringContainsString('<?= csrf_field() ?>', $loginView);

        $this->withRoutes([
            ['POST', 'login', static fn (): string => 'login controller reached'],
        ]);
        $security = CoreServices::security();
        $token = $security->getHash();
        $this->assertNotNull($token);

        $response = $this->post('/login', [
            $security->getTokenName() => $token,
            'email' => 'operator@example.test',
            'password' => 'invalid-on-purpose',
        ]);

        $response->assertOK();
        $response->assertSee('login controller reached');
    }

    public function testMultipartUploadWithValidTokenPassesTheGlobalLayerWithFileIntact(): void
    {
        CoreServices::superglobals()->setFilesArray([
            'receipt_file' => [
                'name' => 'receipt.png',
                'type' => 'image/png',
                'tmp_name' => __FILE__,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize(__FILE__),
            ],
        ]);
        $this->withRoutes([
            ['POST', 'upload-probe', static function (): string {
                $file = CoreServices::request()->getFile('receipt_file');

                return $file?->getClientName() === 'receipt.png' ? 'upload reached' : 'upload missing';
            }],
        ])->withHeaders(['Content-Type' => 'multipart/form-data; boundary=FleetOSTest']);
        $security = CoreServices::security();
        $token = $security->getHash();
        $this->assertNotNull($token);

        $response = $this->post('/upload-probe', [$security->getTokenName() => $token]);

        $response->assertOK();
        $response->assertSee('upload reached');
    }

    public function testEveryApplicationPostFormIncludesCsrfAndGetFormsRemainTokenFree(): void
    {
        $viewRoot = dirname(__DIR__, 2) . '/app/Views';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
        $postForms = 0;
        $multipartForms = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);
            preg_match_all('/<form\b(?:(?!<form\b).)*?<\/form>/is', $source, $matches);
            foreach ($matches[0] as $form) {
                if (preg_match('/\bmethod\s*=\s*["\']post["\']/i', $form) === 1) {
                    ++$postForms;
                    $this->assertStringContainsString('csrf_field()', $form, $file->getPathname());
                    if (stripos($form, 'multipart/form-data') !== false) {
                        ++$multipartForms;
                    }
                } elseif (preg_match('/\bmethod\s*=\s*["\']get["\']/i', $form) === 1) {
                    $this->assertStringNotContainsString('csrf_field()', $form, $file->getPathname());
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $postForms);
        $this->assertGreaterThanOrEqual(5, $multipartForms);
    }

    public function testAirportPostFormInventoryContainsAllTwentyCsrfFields(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            '/app/Views/airport_operations/show.php' => 9,
            '/app/Views/airport_reimbursements/index.php' => 4,
            '/app/Views/airport_reimbursements/match.php' => 7,
        ] as $path => $expected) {
            $source = file_get_contents($root . $path);
            $this->assertIsString($source);
            $this->assertSame($expected, substr_count($source, 'csrf_field()'), $path);
        }
    }
}
