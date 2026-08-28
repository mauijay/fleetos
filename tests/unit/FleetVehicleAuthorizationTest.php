<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Filters\PermissionFilter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth as AuthConfig;
use Config\Services;

/** @internal */
final class FleetVehicleAuthorizationTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testAdminPermissionAllowsAccessAndNonAdminIsDenied(): void
    {
        $filter = new FleetVehicleTestPermissionFilter();
        Services::injectMock('auth', new FleetVehicleTestAuth(true));
        $this->assertNull($filter->before(CoreServices::request(), ['admin.access']));

        Services::injectMock('auth', new FleetVehicleTestAuth(false));
        $deniedFilter = new FleetVehicleTestPermissionFilter();
        $this->assertInstanceOf(RedirectResponse::class, $deniedFilter->before(CoreServices::request(), ['admin.access']));
    }

    public function testAdministrativeWritesRequirePermissionAndCsrfFilters(): void
    {
        $routes = CoreServices::routes();
        $routes->loadRoutes();
        foreach (['turo/imports', 'turo/earnings-imports', 'turo/vehicle-matches/map', 'turo/vehicle-matches/reprocess', 'fleet/vehicles', 'fleet/vehicles/([0-9]+)'] as $route) {
            $filters = $routes->getRoutesOptions($route, 'POST')['filter'] ?? [];
            $this->assertContains('permission:admin.access', (array) $filters, $route);
            $this->assertContains('csrf', (array) $filters, $route);
        }
    }
}

final class FleetVehicleTestAuth extends Auth
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

final class FleetVehicleTestPermissionFilter extends PermissionFilter
{
    protected function redirectToDeniedUrl(): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/');
    }
}
