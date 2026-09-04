<?php

use App\Controllers\MovementLocationAliases;
use App\Services\Fleet\MovementLocationAliasService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class MovementLocationAliasAdministrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Services::injectMock('auth', new MovementLocationAliasAdministrationTestAuth(null));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testRoutesRequireAdminPermissionAndWriteRequiresCsrf(): void
    {
        $routes = CoreServices::routes();
        $routes->loadRoutes();

        $readFilters = (array) ($routes->getRoutesOptions('operations/movement-locations', 'GET')['filter'] ?? []);
        $this->assertContains('session', $readFilters);
        $this->assertContains('permission:admin.access', $readFilters);
        $writeFilters = (array) ($routes->getRoutesOptions('operations/movement-locations', 'POST')['filter'] ?? []);
        $this->assertContains('session', $writeFilters);
        $this->assertContains('permission:admin.access', $writeFilters);
        $this->assertContains('csrf', $writeFilters);
    }

    public function testSaveUsesVerifiedQueueCompanyAndAuthenticatedActor(): void
    {
        $service = new MovementLocationAliasAdministrationTestService();
        Services::injectMock('movementLocationAliasService', $service);
        Services::injectMock('auth', new MovementLocationAliasAdministrationTestAuth(42));

        $this->controller(['company_id' => '7', 'source_text' => 'HNL Delivery', 'location_class' => 'airport_hnl', 'note' => 'Confirmed.'])->save();

        $this->assertSame([7, 'HNL Delivery', 'airport_hnl', 'Confirmed.', 42], $service->saved);
        $this->assertSame('Location alias saved. Matching scheduled movements were reclassified.', CoreServices::session()->getFlashdata('movement_location_alias_notice'));
    }

    public function testSaveRejectsCrossCompanyOrStaleSourceSelection(): void
    {
        $service = new MovementLocationAliasAdministrationTestService();
        Services::injectMock('movementLocationAliasService', $service);
        Services::injectMock('auth', new MovementLocationAliasAdministrationTestAuth(42));

        $this->controller(['company_id' => '8', 'source_text' => 'HNL Delivery', 'location_class' => 'home'])->save();

        $this->assertNull($service->saved);
        $this->assertSame('Choose a location from the current unknown-location queue.', CoreServices::session()->getFlashdata('movement_location_alias_error'));
    }

    public function testSaveRequiresAuthenticatedActor(): void
    {
        $service = new MovementLocationAliasAdministrationTestService();
        Services::injectMock('movementLocationAliasService', $service);
        Services::injectMock('auth', new MovementLocationAliasAdministrationTestAuth(null));

        $this->controller(['company_id' => '7', 'source_text' => 'HNL Delivery', 'location_class' => 'home'])->save();

        $this->assertNull($service->saved);
        $this->assertSame('An authenticated operator is required to save a location alias.', CoreServices::session()->getFlashdata('movement_location_alias_error'));
    }

    public function testViewShowsQueueFactsAllowedClassesAndCsrf(): void
    {
        $html = CoreServices::renderer()->setData([
            'assets' => ['css' => null, 'js' => null],
            'navigation' => [['label' => 'Location Aliases', 'href' => '/operations/movement-locations', 'active' => 'true']],
            'sources' => [['company_id' => 7, 'company_name' => '808biz, Inc.', 'source_text' => 'HNL Delivery', 'location_class' => 'unknown', 'occurrence_count' => 3, 'next_occurrence' => '2026-09-10 10:00:00']],
            'notice' => null,
            'error' => null,
        ])->render('movement_location_aliases/index');

        $this->assertStringContainsString('HNL Delivery', $html);
        $this->assertStringContainsString('808biz, Inc.', $html);
        $this->assertStringContainsString('<dd>3</dd>', $html);
        $this->assertStringContainsString('2026-09-10 10:00:00', $html);
        $this->assertStringContainsString('name="' . (new \Config\Security())->tokenName . '"', $html);
        foreach (['home', 'airport_hnl', 'waikiki_hotel', 'other_delivery'] as $locationClass) {
            $this->assertStringContainsString('value="' . $locationClass . '"', $html);
        }
    }

    /** @param array<string, string> $post */
    private function controller(array $post): MovementLocationAliases
    {
        $request = $this->createStub(\CodeIgniter\HTTP\IncomingRequest::class);
        $request->method('getPost')->willReturnCallback(static fn (string $key): ?string => $post[$key] ?? null);
        $controller = new MovementLocationAliases();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());

        return $controller;
    }
}

final class MovementLocationAliasAdministrationTestService extends MovementLocationAliasService
{
    /** @var array{int, string, string, string|null, int}|null */
    public ?array $saved = null;

    public function unknownSources(): array
    {
        return [['company_id' => 7, 'source_text' => 'HNL Delivery', 'location_class' => 'unknown', 'occurrence_count' => 3, 'next_occurrence' => '2026-09-10 10:00:00']];
    }

    public function save(int $companyId, string $sourceText, string $locationClass, ?string $note, int $actorUserId): int
    {
        $this->saved = [$companyId, $sourceText, $locationClass, $note, $actorUserId];

        return 9;
    }
}

final class MovementLocationAliasAdministrationTestAuth extends Auth
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
