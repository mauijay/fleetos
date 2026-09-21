<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class TripCommitmentNavigationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('auth', new TripCommitmentNavigationViewTestAuth());
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testMovementBackLinkUsesExactChecklistUrl(): void
    {
        $html = $this->render([
            'label' => 'Back to movement workflow',
            'href' => '/operations/checklists/41',
        ]);

        $this->assertStringContainsString('Back to movement workflow', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;41"', $html);
        $this->assertStringNotContainsString('Back to vehicle trip history', $html);
    }

    public function testHistoryBackLinkFocusesTheNormalizedTrip(): void
    {
        $html = $this->render([
            'label' => 'Back to vehicle trip history',
            'href' => '/operations/vehicles/9/trip-history?trip=101',
        ]);

        $this->assertStringContainsString('Back to vehicle trip history', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;vehicles&#x2F;9&#x2F;trip-history&#x3F;trip&#x3D;101"', $html);
        $this->assertStringNotContainsString('Back to movement workflow', $html);
    }

    /** @param array{label:string,href:string} $backLink */
    private function render(array $backLink): string
    {
        return CoreServices::renderer()->setData([
            'assets' => ['css' => null, 'js' => null],
            'navigation' => [],
            'workspace' => [
                'trip' => [
                    'id' => 101,
                    'trip_status_code' => 'booked',
                    'turo_reservation_id' => 'LOCAL-NAV-101',
                    'turo_trip_id' => 'LOCAL-NAV-101',
                    'display_name' => 'Synthetic vehicle',
                    'fleet_code' => 'LOCAL-NAV-VEHICLE',
                    'fleet_vehicle_id' => 9,
                    'starts_at' => '2026-11-11 17:00:00',
                    'ends_at' => '2026-11-15 22:00:00',
                    'pickup_location_class' => 'home',
                    'pickup_location_source_text' => 'Home',
                    'return_location_class' => 'home',
                    'return_location_source_text' => 'Home',
                ],
                'trip_is_operational' => true,
                'active' => [],
                'preserved' => [],
                'history' => [],
                'audits' => [],
                'categories' => [],
                'phases' => [],
                'handling_modes' => [],
                'energy_comparisons' => [],
            ],
            'backLink' => $backLink,
            'editing' => null,
            'formData' => [],
            'success' => null,
            'error' => null,
        ])->render('trip_commitments/index');
    }
}

final class TripCommitmentNavigationViewTestAuth extends Auth
{
    public function __construct()
    {
        parent::__construct(new AuthConfig());
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return false;
    }

    public function user(): ?User
    {
        return null;
    }
}
