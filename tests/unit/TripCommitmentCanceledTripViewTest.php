<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class TripCommitmentCanceledTripViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('auth', new TripCommitmentCanceledTripViewTestAuth());
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testCanceledTripRendersPreservedCommitmentWithoutOperationalActionsOrCreateForm(): void
    {
        $html = CoreServices::renderer()->setData([
            'assets' => ['css' => null, 'js' => null],
            'navigation' => [],
            'workspace' => $this->workspace(),
            'backLink' => [
                'label' => 'Back to vehicle trip history',
                'href' => '/operations/vehicles/40/trip-history?trip=324',
            ],
            'editing' => null,
            'formData' => [],
            'success' => null,
            'error' => null,
        ])->render('trip_commitments/index');

        $this->assertStringContainsString('Trip canceled', $html);
        $this->assertStringContainsString('Guest commitments are preserved for history.', $html);
        $this->assertStringContainsString('No preparation is required.', $html);
        $this->assertStringContainsString('Commitments preserved from canceled trip', $html);
        $this->assertStringContainsString('Synthetic cooler setup', $html);
        $this->assertStringContainsString('Original handling: Complete a task', $html);
        $this->assertStringContainsString('Required before dispatch', $html);
        $this->assertStringContainsString('Audit history', $html);
        $this->assertStringNotContainsString('Active commitments', $html);
        $this->assertStringNotContainsString('>Complete</button>', $html);
        $this->assertStringNotContainsString('>Acknowledge</button>', $html);
        $this->assertStringNotContainsString('Add Guest Commitment', $html);
        $this->assertStringNotContainsString('data-commitment-form', $html);
        $this->assertStringNotContainsString('>Edit</a>', $html);
        $this->assertStringNotContainsString('Mark not applicable', $html);
        $this->assertStringContainsString('Back to vehicle trip history', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;vehicles&#x2F;40&#x2F;trip-history&#x3F;trip&#x3D;324"', $html);
    }

    /** @return array<string, mixed> */
    private function workspace(): array
    {
        $commitment = [
            'id' => 10,
            'category' => 'guest_amenity',
            'category_label' => 'Guest amenity',
            'instruction' => 'Synthetic cooler setup',
            'applies_during' => 'preparation',
            'phase_label' => 'Preparation',
            'handling_mode' => 'task',
            'handling_label' => 'Complete a task',
            'required_before_dispatch' => 1,
            'energy_rule_summary' => null,
            'arranged_at' => null,
            'created_at' => '2026-09-20 09:00:00',
            'updated_at' => '2026-09-20 09:00:00',
            'state' => 'active',
        ];

        return [
            'trip' => [
                'id' => 324,
                'trip_status_code' => 'canceled_zero_payout',
                'turo_reservation_id' => 'LOCAL-COMMIT-CANCELED',
                'turo_trip_id' => 'LOCAL-COMMIT-CANCELED',
                'display_name' => 'Synthetic canceled vehicle',
                'fleet_code' => 'LOCAL-COMMIT-CANCELED',
                'fleet_vehicle_id' => 40,
                'starts_at' => '2026-09-20 10:00:00',
                'ends_at' => '2026-09-21 10:00:00',
                'pickup_location_class' => 'home',
                'pickup_location_source_text' => 'Home',
                'return_location_class' => 'home',
                'return_location_source_text' => 'Home',
            ],
            'trip_is_operational' => false,
            'active' => [],
            'preserved' => [$commitment],
            'history' => [],
            'audits' => [[
                'action' => 'created',
                'commitment_id' => 10,
                'created_at' => '2026-09-20 09:00:00',
                'actor_user_id' => 7,
            ]],
            'categories' => [],
            'phases' => [],
            'handling_modes' => [],
            'energy_comparisons' => [],
        ];
    }
}

final class TripCommitmentCanceledTripViewTestAuth extends Auth
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
