<?php

use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Settings\Config\Settings as SettingsConfig;
use CodeIgniter\Settings\Settings as SettingsService;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Authentication\AuthenticatorInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Result;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Config\Services;

/** @internal */
final class LogoutSecurityTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private LogoutSecurityTestAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();

        $settingsConfig = new SettingsConfig();
        $settingsConfig->handlers = [];
        Services::injectMock('settings', new SettingsService($settingsConfig));
        $this->authenticator = new LogoutSecurityTestAuthenticator();
        Services::injectMock('auth', new LogoutSecurityTestAuth($this->authenticator));
        CoreServices::routes()->resetRoutes();
        CoreServices::routes()->loadRoutes();
        $this->withRoutes();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testEffectiveRouteAndFiltersRequirePostCsrfAndAuthenticatedSession(): void
    {
        $routes = CoreServices::routes();

        $this->assertArrayNotHasKey('logout', $routes->getRoutes('GET', false));
        $this->assertSame(
            '\\CodeIgniter\\Shield\\Controllers\\LoginController::logoutAction',
            $routes->getRoutes('POST', false)['logout'] ?? null,
        );
        $this->assertSame('/logout', $routes->reverseRoute('logout'));
        $this->assertSame('logout', $routes->getRoutesOptions('logout', 'POST')['as'] ?? null);

        $filters = (new FilterCollector())->get('POST', 'logout')['before'];
        $this->assertSame(1, array_count_values($filters)['csrf'] ?? 0);
        $this->assertSame(1, array_count_values($filters)['session'] ?? 0);
        $this->assertNotContains('permission:admin.access', $filters);
    }

    public function testPostWithoutCsrfIsRejectedBeforeShieldLogoutHandler(): void
    {
        $this->expectException(SecurityException::class);

        try {
            $this->post('/logout');
        } finally {
            $this->assertTrue($this->authenticator->loggedIn());
            $this->assertSame(0, $this->authenticator->logoutCalls);
        }
    }

    public function testValidPostUsesShieldLogoutRedirectAndProtectsFleetOsAfterward(): void
    {
        $security = CoreServices::security();
        $originalToken = $security->getHash();
        $this->assertNotNull($originalToken);
        $this->assertTrue($this->authenticator->loggedIn());

        $response = $this->post('/logout', [$security->getTokenName() => $originalToken]);

        $response->assertRedirectTo((new AuthConfig())->logoutRedirect());
        $response->assertSessionHas('message', CoreServices::language()->getLine('Auth.successLogout'));
        $this->assertFalse($this->authenticator->loggedIn());
        $this->assertSame(1, $this->authenticator->logoutCalls);
        $this->assertNotSame($originalToken, $security->getHash());

        $protectedResponse = $this->get('/');
        $protectedResponse->assertRedirectTo('login');
        $this->assertSame(1, $this->authenticator->logoutCalls);
    }

    public function testGetLogoutIsNotRoutableAndDoesNotChangeAuthenticationState(): void
    {
        $this->expectException(PageNotFoundException::class);

        try {
            $this->get('/logout');
        } finally {
            $this->assertTrue($this->authenticator->loggedIn());
            $this->assertSame(0, $this->authenticator->logoutCalls);
        }
    }

    public function testLoggedOutUserCannotReachPostLogoutHandler(): void
    {
        $this->authenticator->logout();
        $this->authenticator->logoutCalls = 0;
        $security = CoreServices::security();
        $token = $security->getHash();
        $this->assertNotNull($token);

        $response = $this->post('/logout', [$security->getTokenName() => $token]);

        $response->assertRedirectTo('login');
        $this->assertSame(0, $this->authenticator->logoutCalls);
    }

    public function testRenderedAuthenticatedNavigationUsesAccessibleCsrfProtectedPostForm(): void
    {
        $html = CoreServices::renderer()->render('fleet_command_center/components/auth_links');
        $tokenName = CoreServices::security()->getTokenName();
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($document);

        $forms = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " auth-action-form ")]');
        $this->assertNotFalse($forms);
        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertStringEndsWith('/logout', $form->getAttribute('action'));

        $csrfFields = $xpath->query('.//input[@type="hidden" and @name="' . $tokenName . '"]', $form);
        $logoutButtons = $xpath->query('.//button[@type="submit" and contains(concat(" ", normalize-space(@class), " "), " auth-action ") and normalize-space(.)="Logout"]', $form);
        $logoutLinks = $xpath->query('//a[contains(@href, "/logout")]');
        $scripts = $xpath->query('//script');
        $this->assertNotFalse($csrfFields);
        $this->assertNotFalse($logoutButtons);
        $this->assertNotFalse($logoutLinks);
        $this->assertNotFalse($scripts);
        $this->assertCount(1, $csrfFields);
        $this->assertCount(1, $logoutButtons);
        $this->assertCount(0, $logoutLinks);
        $this->assertCount(0, $scripts);
    }
}

final class LogoutSecurityTestAuth extends Auth
{
    public function __construct(private readonly LogoutSecurityTestAuthenticator $testAuthenticator)
    {
        parent::__construct(new AuthConfig());
    }

    public function getAuthenticator(): AuthenticatorInterface
    {
        return $this->testAuthenticator;
    }

    public function loggedIn(): bool
    {
        return $this->testAuthenticator->loggedIn();
    }

    public function user(): ?User
    {
        return $this->testAuthenticator->getUser();
    }

    public function logout(): void
    {
        $this->testAuthenticator->logout();
    }
}

final class LogoutSecurityTestAuthenticator implements AuthenticatorInterface
{
    public int $logoutCalls = 0;

    private bool $loggedIn = true;

    private User $user;

    public function __construct()
    {
        $this->user = new User([
            'id' => 42,
            'email' => 'operator@example.test',
            'username' => 'operator',
            'active' => 1,
        ]);
    }

    public function attempt(array $credentials): Result
    {
        return new Result(['success' => false]);
    }

    public function check(array $credentials): Result
    {
        return new Result(['success' => false]);
    }

    public function loggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function isPending(): bool
    {
        return false;
    }

    public function login(User $user): void
    {
        $this->user = $user;
        $this->loggedIn = true;
    }

    public function loginById($userId): void
    {
        $this->user->id = $userId;
        $this->loggedIn = true;
    }

    public function logout(): void
    {
        ++$this->logoutCalls;
        $this->loggedIn = false;
    }

    public function getUser(): ?User
    {
        return $this->loggedIn ? $this->user : null;
    }

    public function recordActiveDate(): void
    {
    }
}
