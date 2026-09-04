<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class MovementOperationalFactsViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('auth', new MovementOperationalFactsViewTestAuth());
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testPickupSummaryAndActionUseHandoffLanguageAndSavedValues(): void
    {
        $html = $this->render('pickup', $this->facts(['energy_label' => 'Charge', 'energy_value' => '82%']));

        $this->assertStringContainsString('Guest handoff recorded', $html);
        $this->assertStringContainsString('Handoff location', $html);
        $this->assertStringContainsString('<dd>Waikiki Hotel<span class="movement-fact-detail">Front drive</span></dd>', $html);
        $this->assertStringNotContainsString('Current location: Waikiki Hotel', $html);
        $this->assertStringContainsString('Clean', $html);
        $this->assertStringContainsString('<dt>Charge</dt><dd>82%</dd>', $html);
        $this->assertStringContainsString('Record Guest Handoff', $html);
        $this->assertStringContainsString('Correct recorded facts', $html);
        $this->assertStringContainsString('<section class="section operational-facts">', $html);
    }

    public function testReturnSummaryUsesCurrentLocationFuelAndContextualAction(): void
    {
        $html = $this->render('return', $this->facts([
            'event_title' => 'Actual return recorded',
            'location_label' => 'Current location',
            'location_class_label' => 'Airport HNL',
            'location_detail_value' => null,
            'cleanliness_label' => 'Dirty',
            'energy_label' => 'Fuel',
            'energy_value' => '23%',
        ]));

        $this->assertStringContainsString('Actual return recorded', $html);
        $this->assertStringContainsString('Current location', $html);
        $this->assertStringContainsString('<dt>Fuel</dt><dd>23%</dd>', $html);
        $this->assertStringContainsString('Record Actual Return', $html);
    }

    public function testCorrectionModePrefillsActiveFactsAndMissingEnergyIsExplicit(): void
    {
        $facts = $this->facts(['energy_value' => 'Not captured']);
        $html = $this->render('pickup', $facts, true, $facts['form_data']);

        $this->assertStringContainsString('<dt>Charge</dt><dd>Not captured</dd>', $html);
        $this->assertStringContainsString('value="2026-09-03T08&#x3A;05"', $html);
        $this->assertStringContainsString('value="waikiki_hotel" selected', $html);
        $this->assertStringContainsString('value="clean" selected', $html);
        $this->assertStringContainsString('value="82"', $html);
        $this->assertStringContainsString('name="correction_reason"', $html);
        $this->assertStringContainsString('Save Correction', $html);
    }

    public function testReturnCorrectionModePrefillsCompleteActiveObservation(): void
    {
        $facts = $this->facts([
            'event_title' => 'Actual return recorded',
            'location_label' => 'Current location',
            'location_class_label' => 'Airport HNL',
            'location_detail_value' => 'International Garage L7',
            'form_data' => ['event_id' => 21, 'assessment_id' => 22, 'occurred_at' => '2026-09-03T09:15', 'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7', 'cleanliness' => 'dirty', 'energy_percent' => 24, 'note' => 'Return checked.'],
        ]);

        $html = $this->render('return', $facts, true, $facts['form_data']);

        $this->assertStringContainsString('value="2026-09-03T09&#x3A;15"', $html);
        $this->assertStringContainsString('value="airport_hnl" selected', $html);
        $this->assertStringContainsString('value="International&#x20;Garage&#x20;L7"', $html);
        $this->assertStringContainsString('value="dirty" selected', $html);
        $this->assertStringContainsString('value="24"', $html);
        $this->assertStringContainsString('Return checked.', $html);
    }

    public function testFailedCorrectionMergesSubmittedValuesWithCompleteActivePrefill(): void
    {
        $active = $this->facts()['form_data'];
        $merged = (new \App\Services\Fleet\MovementOperationalFactPresentationService())->mergeCorrectionFormData($active, [
            'event_id' => 11,
            'assessment_id' => 12,
            'location_class' => '',
            'location_detail' => '',
            'cleanliness' => '',
            'energy_percent' => '101',
            'note' => '',
            'correction_reason' => 'Energy typo.',
        ]);

        $this->assertSame('waikiki_hotel', $merged['location_class']);
        $this->assertSame('clean', $merged['cleanliness']);
        $this->assertSame('101', $merged['energy_percent']);
        $this->assertSame('Ready', $merged['note']);
        $this->assertSame('Energy typo.', $merged['correction_reason']);
    }

    /** @param array<string, mixed> $latestFacts @param array<string, mixed> $formData */
    private function render(string $movementType, array $latestFacts, bool $correcting = false, array $formData = []): string
    {
        return CoreServices::renderer()->setData([
            'assets' => ['css' => null, 'js' => null],
            'checklist' => ['exists' => true, 'id' => 4, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'fleet_code' => 'EV-10', 'movement_type' => $movementType, 'scheduled_at' => '2026-09-03 08:00:00', 'guest_name' => 'Guest', 'readiness_status' => 'ready', 'progress' => ['required_complete_count' => 1, 'required_count' => 1, 'required_remaining_count' => 0], 'items' => [], 'vehicle_disposition' => 'available', 'completed_at' => null],
            'latestFacts' => $latestFacts,
            'correctingFacts' => $correcting,
            'factFormData' => $formData,
            'notice' => null,
            'error' => null,
        ])->render('trip_movement_checklists/show');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function facts(array $overrides = []): array
    {
        return array_merge([
            'event_title' => 'Guest handoff recorded',
            'occurred_at_label' => 'Sep 3, 2026 8:05 AM',
            'location_label' => 'Handoff location',
            'location_class_label' => 'Waikiki Hotel',
            'location_detail_value' => 'Front drive',
            'cleanliness_label' => 'Clean',
            'energy_label' => 'Charge',
            'energy_value' => '82%',
            'source_label' => 'Manual',
            'actor_label' => 'operator',
            'form_data' => ['event_id' => 11, 'assessment_id' => 12, 'occurred_at' => '2026-09-03T08:05', 'location_class' => 'waikiki_hotel', 'location_detail' => '', 'cleanliness' => 'clean', 'energy_percent' => 82, 'note' => 'Ready'],
        ], $overrides);
    }
}

final class MovementOperationalFactsViewTestAuth extends Auth
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
