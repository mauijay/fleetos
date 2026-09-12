<?php

use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Filters\PermissionFilter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth as AuthConfig;
use Config\Services;

/** @internal */
final class AirportAuthorizationTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testAdminPermissionAllowsAndAuthenticatedNonAdminIsDenied(): void
    {
        Services::injectMock('auth', new AirportTestAuth(true));
        $this->assertNull((new AirportTestPermissionFilter())->before(CoreServices::request(), ['admin.access']));

        Services::injectMock('auth', new AirportTestAuth(false));
        $this->assertFalse((new AirportTestPermissionFilter())->allows(['admin.access']));
    }

    public function testEffectiveAirportGetAndPostFiltersIncludeSessionPermissionAndGlobalCsrf(): void
    {
        CoreServices::routes()->loadRoutes();
        $collector = new FilterCollector();

        foreach (['operations/airport', 'operations/airport/1', 'operations/airport/reimbursements', 'operations/airport/reimbursements/match/1'] as $uri) {
            $filters = $collector->get('GET', $uri)['before'];
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
        }

        foreach (['operations/airport/1/staging', 'operations/airport/1/complete', 'operations/airport/reimbursements/unmatched-receipt', 'operations/airport/reimbursements/receipts/1/match'] as $uri) {
            $filters = $collector->get('POST', $uri)['before'];
            $this->assertContains('session', $filters, $uri);
            $this->assertContains('permission:admin.access', $filters, $uri);
            $this->assertSame(1, array_count_values($filters)['csrf'] ?? 0, $uri);
        }

        $receiptStreamFilters = $collector->get('GET', 'files/receipts/1')['before'];
        $this->assertContains('session', $receiptStreamFilters);
        $this->assertNotContains('permission:admin.access', $receiptStreamFilters);
    }
}

final class AirportTestAuth extends Auth
{
    private User $testUser;

    public function __construct(bool $admin)
    {
        parent::__construct(new AuthConfig());
        $this->testUser = new class ($admin) extends User {
            public function __construct(private readonly bool $admin)
            {
                parent::__construct();
            }

            public function can(string ...$permissions): bool
            {
                return $this->admin && in_array('admin.access', $permissions, true);
            }
        };
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return true;
    }

    public function user(): User
    {
        return $this->testUser;
    }
}

final class AirportTestPermissionFilter extends PermissionFilter
{
    public function allows(array $permissions): bool
    {
        return $this->isAuthorized($permissions);
    }

    protected function redirectToDeniedUrl(): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/');
    }
}
