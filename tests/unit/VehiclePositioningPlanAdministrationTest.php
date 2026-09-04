<?php

use App\Controllers\VehiclePositioningPlans;
use App\Services\Fleet\VehiclePositioningPlanWorkflowService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class VehiclePositioningPlanAdministrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Services::injectMock('auth', new VehiclePositioningPlanAdministrationTestAuth(null));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testRoutesRequireAdminPermissionAndPostRequiresCsrf(): void
    {
        $routes = CoreServices::routes();
        $routes->loadRoutes();

        $route = 'fleet/vehicles/([0-9]+)/positioning-plan';
        $getFilters = (array) ($routes->getRoutesOptions($route, 'GET')['filter'] ?? []);
        $postFilters = (array) ($routes->getRoutesOptions($route, 'POST')['filter'] ?? []);
        foreach (['session', 'permission:admin.access'] as $filter) {
            $this->assertContains($filter, $getFilters);
            $this->assertContains($filter, $postFilters);
        }
        $this->assertContains('csrf', $postFilters);
        $this->assertNotContains('csrf', $getFilters);
    }

    public function testPostUsesAuthenticatedActorAndRouteVehicleOnly(): void
    {
        $workflow = new VehiclePositioningPlanAdministrationTestWorkflow();
        Services::injectMock('vehiclePositioningPlanWorkflowService', $workflow);
        Services::injectMock('auth', new VehiclePositioningPlanAdministrationTestAuth(42));

        $this->controller([
            'company_id' => '999',
            'positioning_code' => 'retrieve_home',
            'target_location_class' => 'home',
            'reason_code' => 'operator_choice',
            'note' => 'Bring it home.',
            'transportation_state' => 'confirmed',
            'expires_at' => '',
        ])->create(10);

        $this->assertSame(10, $workflow->created['vehicle_id']);
        $this->assertSame(42, $workflow->created['actor_user_id']);
        $this->assertArrayNotHasKey('company_id', $workflow->created['data']);
    }

    public function testGetRendersComputedRecommendationAndDoesNotCreatePlan(): void
    {
        $workflow = new VehiclePositioningPlanAdministrationTestWorkflow();
        Services::injectMock('vehiclePositioningPlanWorkflowService', $workflow);

        $html = $this->controller([])->show(10);

        $this->assertIsString($html);
        $this->assertStringContainsString('FleetOS recommendation', $html);
        $this->assertStringContainsString('Flexible: Hold at home', $html);
        $this->assertStringContainsString('Existing plan is stale', $html);
        $this->assertStringContainsString('Operator positioning plan', $html);
        $this->assertStringContainsString('Transportation dependency', $html);
        $this->assertStringContainsString('Sep 10, 2026 10:00 AM · Airport HNL', $html);
        $this->assertSame(1, $workflow->contextCalls);
        $this->assertNull($workflow->created);
    }

    /** @param array<string, string> $post */
    private function controller(array $post): VehiclePositioningPlans
    {
        $request = $this->createStub(\CodeIgniter\HTTP\IncomingRequest::class);
        $request->method('getPost')->willReturnCallback(static fn (string $key): ?string => $post[$key] ?? null);
        $controller = new VehiclePositioningPlans();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        return $controller;
    }
}

final class VehiclePositioningPlanAdministrationTestWorkflow extends VehiclePositioningPlanWorkflowService
{
    public int $contextCalls = 0;
    /** @var array<string, mixed>|null */
    public ?array $created = null;

    public function context(int $vehicleId, ?DateTimeImmutable $asOf = null): array
    {
        $this->contextCalls++;
        return [
            'vehicle' => ['id' => $vehicleId, 'company_id' => 1, 'fleet_code' => 'Spaceship-010', 'display_name' => 'Spaceship-010'],
            'event' => ['id' => 5],
            'nextTrip' => ['id' => 100, 'starts_at' => '2026-09-10 10:00:00', 'pickup_location_class' => 'airport_hnl'],
            'freshness' => ['is_stale' => false],
            'basis' => ['location_class' => 'home', 'basis_type' => 'actual'],
            'plan' => ['positioning_code' => 'hold_home_flexible', 'target_location_class' => 'home', 'reason_code' => 'fleet_logistics', 'transportation_state' => 'not_applicable', 'created_by' => 42, 'actor_username' => 'jlamping', 'created_at' => '2026-09-03 11:45:00', 'is_basis_stale' => true, 'note' => null],
            'recommendation' => ['code' => 'hold_home_flexible', 'label' => 'Hold Home Flexible', 'strength' => 'Flexible', 'explanation' => 'Keep the vehicle at home.'],
        ];
    }

    public function create(int $vehicleId, array $data, int $actorUserId): int
    {
        $this->created = ['vehicle_id' => $vehicleId, 'data' => $data, 'actor_user_id' => $actorUserId];
        return 99;
    }
}

final class VehiclePositioningPlanAdministrationTestAuth extends Auth
{
    public function __construct(private readonly ?int $userId)
    {
        parent::__construct(new AuthConfig());
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }
    public function loggedIn(): bool
    {
        return $this->userId !== null;
    }

    public function user(): ?User
    {
        if ($this->userId === null) {
            return null;
        }
        $user = new User();
        $user->id = $this->userId;
        return $user;
    }
}
