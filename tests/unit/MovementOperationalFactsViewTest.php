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

    public function testStructuredHnlParkingRendersWithoutAStallField(): void
    {
        $facts = $this->facts([
            'location_label' => 'Current location',
            'location_class_label' => 'Airport HNL',
            'location_detail_value' => 'International Garage L7 RF',
            'airport_garage_line' => 'International Garage · Blue',
            'airport_position_line' => 'Level 7 · Row F',
            'approved_turo_garage' => true,
        ]);
        $html = $this->render('return', $facts, true, [
            'location_class' => 'airport_hnl',
            'location_detail' => 'International Garage L7 RF',
            'airport_garage_code' => 'international',
            'airport_parking_level' => 7,
            'airport_parking_row' => 'F',
            'note' => 'Level confirmed.',
        ]);

        $this->assertStringContainsString('International Garage · Blue', $html);
        $this->assertStringContainsString('Level 7 · Row F', $html);
        $this->assertStringContainsString('name="airport_garage_code"', $html);
        $this->assertStringContainsString('name="airport_parking_level"', $html);
        $this->assertStringContainsString('name="airport_parking_row"', $html);
        $this->assertStringContainsString('value="terminal_2" data-max-level="6"', $html);
        $this->assertTrue(strpos($html, 'name="airport_parking_row"') < strpos($html, 'name="airport_garage_code"'));
        $this->assertTrue(strpos($html, 'name="airport_garage_code"') < strpos($html, 'name="airport_parking_level"'));
        $this->assertMatchesRegularExpression('/data-location-detail hidden[^>]*>Location detail<input[^>]*name="location_detail"[^>]*disabled/', $html);
        $this->assertStringContainsString('<label>Note<textarea name="note"', $html);
        $this->assertStringNotContainsString('name="parking_stall"', $html);
    }

    public function testTerminalTwoStructuredParkingRendersConsistently(): void
    {
        $html = $this->render('return', $this->facts([
            'location_label' => 'Current location',
            'location_class_label' => 'Airport HNL',
            'location_detail_value' => 'Terminal 2 Garage L4 RM',
            'airport_garage_line' => 'Terminal 2 Garage · Red',
            'airport_position_line' => 'Level 4 · Row M',
            'approved_turo_garage' => false,
        ]), true, [
            'location_class' => 'airport_hnl',
            'location_detail' => 'Terminal 2 Garage L4 RM',
            'airport_garage_code' => 'terminal_2',
            'airport_parking_level' => 4,
            'airport_parking_row' => 'M',
            'note' => 'Near the elevator.',
        ]);

        $this->assertStringContainsString('Terminal 2 Garage · Red', $html);
        $this->assertStringContainsString('Level 4 · Row M', $html);
        $this->assertMatchesRegularExpression('/value="terminal_2"[^>]*selected/', $html);
        $this->assertStringContainsString('value="4" selected', $html);
        $this->assertStringContainsString('value="M" data-garage="terminal_2" selected', $html);
        $this->assertStringContainsString('Near the elevator.', $html);
    }

    public function testNonHnlLocationRetainsEditableDetailAndNote(): void
    {
        $html = $this->render('return', $this->facts(), true, $this->facts()['form_data']);

        $this->assertMatchesRegularExpression('/<label data-location-detail >Location detail<input name="location_detail"[^>]*>/', $html);
        $this->assertStringNotContainsString('data-location-detail hidden', $html);
        $this->assertStringContainsString('<label>Note<textarea name="note"', $html);
        $this->assertStringContainsString('Ready', $html);
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
            'location_detail_value' => 'International Garage L7 RF',
            'airport_garage_line' => 'International Garage · Blue',
            'airport_position_line' => 'Level 7 · Row F',
            'form_data' => ['event_id' => 21, 'assessment_id' => 22, 'occurred_at' => '2026-09-03T09:15', 'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7 RF', 'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'F', 'cleanliness' => 'dirty', 'energy_percent' => 24, 'note' => 'Return checked.'],
        ]);

        $html = $this->render('return', $facts, true, $facts['form_data']);

        $this->assertStringContainsString('value="2026-09-03T09&#x3A;15"', $html);
        $this->assertStringContainsString('value="airport_hnl" selected', $html);
        $this->assertStringContainsString('International Garage · Blue', $html);
        $this->assertStringContainsString('Level 7 · Row F', $html);
        $this->assertMatchesRegularExpression('/value="international"[^>]*selected/', $html);
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
