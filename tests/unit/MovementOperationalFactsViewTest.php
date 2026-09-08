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
        $this->assertStringNotContainsString('Record Guest Handoff', $html);
        $this->assertStringContainsString('Correct pickup', $html);
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
        $this->assertStringNotContainsString('Record Actual Return', $html);
        $this->assertStringContainsString('Use the return fact actions above', $html);
    }

    public function testTripFactsRenderAuthoritativePickupAndReturnWithExplicitCorrectionTargets(): void
    {
        $pickup = $this->facts(['event_id' => 11, 'assessment_id' => 12]);
        $return = $this->facts([
            'event_id' => 21,
            'assessment_id' => 22,
            'event_title' => 'Actual return recorded',
            'occurred_at_label' => 'Sep 5, 2026 6:39 PM',
            'location_label' => 'Current location',
            'location_class_label' => 'Home',
            'location_detail_value' => null,
            'cleanliness_label' => 'Dirty',
            'energy_value' => '87%',
        ]);

        $html = $this->render('return', $return, false, [], ['tripFacts' => ['pickup' => $pickup, 'return' => $return]]);

        $this->assertStringContainsString('Trip facts', $html);
        $this->assertStringContainsString('>Pickup<', $html);
        $this->assertStringContainsString('>Return<', $html);
        $this->assertStringContainsString('Sep 3, 2026 8:05 AM', $html);
        $this->assertStringContainsString('Sep 5, 2026 6:39 PM', $html);
        $this->assertStringContainsString('?correct=1&amp;fact=pickup', $html);
        $this->assertStringContainsString('?correct=1&amp;fact=return', $html);
        $this->assertStringContainsString('?repair=1&amp;fact=pickup', $html);
        $this->assertStringContainsString('?repair=1&amp;fact=return', $html);
    }

    public function testTripFactsShowMissingSideWithoutDuplicatingLatestFactPanel(): void
    {
        $pickup = $this->facts();

        $html = $this->render('pickup', $pickup, false, [], ['tripFacts' => ['pickup' => $pickup, 'return' => null]]);

        $this->assertStringContainsString('>Return<', $html);
        $this->assertStringContainsString('Not recorded', $html);
        $this->assertStringNotContainsString('Latest saved facts', $html);
    }

    public function testStagedPickupShowsSeparateConfirmationAndRepairActions(): void
    {
        $facts = $this->facts(['event_code' => 'vehicle_staged', 'event_title' => 'Staged for pickup', 'location_label' => 'Staging location']);
        $html = $this->render('pickup', $facts, false, [], ['isStagedPickup' => true]);

        $this->assertStringContainsString('Staged for pickup', $html);
        $this->assertStringContainsString('Confirm Guest Pickup', $html);
        $this->assertStringContainsString('Recorded on wrong trip', $html);
        $this->assertStringNotContainsString('Record Guest Handoff', $html);
    }

    public function testConfirmedHandoffStillOffersWrongTripRepair(): void
    {
        $html = $this->render('pickup', $this->facts(), false, [], ['isPickupConfirmed' => true]);

        $this->assertStringContainsString('Guest pickup confirmed', $html);
        $this->assertStringContainsString('Recorded on wrong trip', $html);
        $this->assertStringContainsString('/operations/checklists/4?repair=1', $html);
    }

    public function testConfirmedHandoffRepairModeShowsFromToPreview(): void
    {
        $candidate = ['id' => 90, 'turo_trip_id' => 900090, 'guest_name' => 'Prior Guest', 'starts_at' => '2026-10-02 08:00:00', 'trip_status_code' => 'booked'];
        $html = $this->render('pickup', $this->facts(), false, [], [
            'isPickupConfirmed' => true,
            'repairingFacts' => true,
            'repairCandidates' => [$candidate],
        ]);

        $this->assertStringContainsString('data-repair-target-select', $html);
        $this->assertStringContainsString('<span>From</span><strong>Guest · Trip 100</strong>', $html);
        $this->assertStringContainsString('<span>To</span><strong data-repair-preview-target>Choose a nearby trip</strong>', $html);
        $this->assertStringContainsString('Prior Guest · Trip 900090', $html);
        $this->assertStringNotContainsString('<strong>Guest pickup confirmed</strong>', $html);
    }

    public function testRepairModeShowsActiveHandoffConflictWithoutOfferingTarget(): void
    {
        $conflict = ['id' => 90, 'turo_trip_id' => 900090, 'guest_name' => 'Historical Guest', 'conflict_label' => 'an active guest handoff'];
        $html = $this->render('pickup', $this->facts(), false, [], [
            'repairingFacts' => true,
            'repairCandidates' => [],
            'repairConflicts' => [$conflict],
        ]);

        $this->assertStringContainsString('Conflicting movement facts', $html);
        $this->assertStringContainsString('Historical Guest · Trip 900090 already has an active guest handoff.', $html);
        $this->assertStringNotContainsString('<option value="90"', $html);
    }

    public function testTripContextLabelsAndLinksTheSelectedReservation(): void
    {
        $trip = ['id' => 100, 'turo_trip_id' => 900100, 'guest_name' => 'Guest', 'starts_at' => '2026-10-06 21:30:00', 'ends_at' => '2026-10-12 06:00:00', 'pickup_location_class' => 'airport_hnl', 'return_location_class' => 'home', 'trip_status_code' => 'booked'];
        $previous = array_merge($trip, ['id' => 90, 'turo_trip_id' => 900090, 'guest_name' => 'Previous Guest', 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-02 08:00:00', 'movement_href' => '/operations/checklists/490']);
        $next = array_merge($trip, ['id' => 110, 'turo_trip_id' => 900110, 'guest_name' => 'Next Guest', 'starts_at' => '2026-10-13 08:00:00', 'ends_at' => '2026-10-14 08:00:00', 'movement_href' => '/operations/checklists/510']);
        $html = $this->render('pickup', $this->facts(), false, [], ['tripContext' => ['previous' => $previous, 'current' => $trip, 'next' => $next]]);

        $this->assertStringContainsString('Selected trip', $html);
        $this->assertStringContainsString('Trip 900100', $html);
        $this->assertStringContainsString('Pickup: Airport Hnl', $html);
        $this->assertStringContainsString('Return: Home', $html);
        $this->assertStringContainsString('/operations/vehicles/10/trip-history?trip=100', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;490" aria-label="Open previous&#x20;trip movement"', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;510" aria-label="Open next&#x20;trip movement"', $html);
        $this->assertStringContainsString('trip-context-item is-current', $html);
    }

    public function testDirectHandoffDefaultsEditableTimeWithoutWritingOrPrematureConfirmation(): void
    {
        $html = $this->render('pickup', $this->facts(), false, ['occurred_at' => '2026-09-07T16:27'], ['tripFacts' => ['pickup' => null, 'return' => null]]);

        $this->assertStringContainsString('id="handoff-entry"', $html);
        $this->assertStringContainsString('type="date" name="occurred_on" required value="2026-09-07"', $html);
        $this->assertStringContainsString('type="time" name="occurred_time" required step="60" value="16&#x3A;27"', $html);
        $this->assertStringContainsString('type="hidden" name="occurred_at" value="2026-09-07T16&#x3A;27"', $html);
        $this->assertStringContainsString('data-local-datetime', $html);
        $this->assertStringContainsString('Charge/Fuel percent', $html);
        $this->assertStringContainsString('Record Guest Handoff', $html);
        $this->assertStringNotContainsString('name="confirm_early_handoff"', $html);
    }

    public function testEarlyWarningRetainsSubmittedTimeSelectedTripAndRequiresConfirmation(): void
    {
        $trip = ['id' => 100, 'turo_trip_id' => 900100, 'guest_name' => 'Selected Guest', 'starts_at' => '2026-10-06 21:30:00', 'ends_at' => '2026-10-12 06:00:00', 'pickup_location_class' => 'home', 'return_location_class' => 'home', 'trip_status_code' => 'booked'];
        $warning = 'This reservation does not begin until Oct 6, 2026 at 9:30 PM. The handoff time entered is more than 2 hours early. Are you sure this is the correct reservation?';
        $html = $this->render('pickup', $this->facts(), false, ['occurred_at' => '2026-10-05T08:05', 'location_class' => 'home'], [
            'tripFacts' => ['pickup' => null, 'return' => null],
            'tripContext' => ['previous' => null, 'current' => $trip, 'next' => null],
            'isEarlyHandoffWarning' => true,
            'error' => $warning,
        ]);

        $this->assertStringContainsString($warning, $html);
        $this->assertStringContainsString('Selected trip', $html);
        $this->assertStringContainsString('Selected Guest', $html);
        $this->assertStringContainsString('name="occurred_on" required value="2026-10-05"', $html);
        $this->assertStringContainsString('name="occurred_time" required step="60" value="08&#x3A;05"', $html);
        $this->assertStringContainsString('name="occurred_at" value="2026-10-05T08&#x3A;05"', $html);
        $this->assertStringContainsString('name="confirm_early_handoff" value="1" required', $html);
    }

    public function testVehicleHistoryEmphasizesSelectedTripWithLocations(): void
    {
        $html = CoreServices::renderer()->setData([
            'assets' => ['css' => null, 'js' => null],
            'vehicle' => ['id' => 10, 'fleet_code' => 'EV-10'],
            'selectedTripId' => 100,
            'trips' => [
                ['id' => 100, 'turo_trip_id' => 900100, 'guest_name' => 'Guest', 'starts_at' => '2026-10-06 21:30:00', 'ends_at' => '2026-10-12 06:00:00', 'pickup_location_class' => 'airport_hnl', 'return_location_class' => 'home', 'trip_status_code' => 'booked', 'movement_href' => '/operations/checklists/500'],
                ['id' => 90, 'turo_trip_id' => 900090, 'guest_name' => 'Canceled Guest', 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-02 08:00:00', 'pickup_location_class' => 'home', 'return_location_class' => 'home', 'trip_status_code' => 'canceled_zero_payout', 'movement_href' => null],
            ],
        ])->render('trip_movement_checklists/history');

        $this->assertStringContainsString('trip-history-row is-selected is-linked', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;500" aria-label="Open movement for trip 900100"', $html);
        $this->assertStringContainsString('<strong>Selected trip</strong>', $html);
        $this->assertStringContainsString('Pickup: Airport Hnl', $html);
        $this->assertStringContainsString('Return: Home', $html);
        $this->assertStringContainsString('trip-history-row is-canceled', $html);
        $this->assertStringContainsString('Canceled Zero Payout', $html);
        $this->assertStringContainsString('No movement record', $html);
        $this->assertStringContainsString('href="/fleet/vehicles/10"', $html);
        $this->assertStringContainsString('href="/"', $html);
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
    private function render(string $movementType, array $latestFacts, bool $correcting = false, array $formData = [], array $extra = []): string
    {
        return CoreServices::renderer()->setData(array_merge([
            'assets' => ['css' => null, 'js' => null],
            'checklist' => ['exists' => true, 'id' => 4, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'fleet_code' => 'EV-10', 'movement_type' => $movementType, 'scheduled_at' => '2026-09-03 08:00:00', 'guest_name' => 'Guest', 'readiness_status' => 'ready', 'progress' => ['required_complete_count' => 1, 'required_count' => 1, 'required_remaining_count' => 0], 'items' => [], 'vehicle_disposition' => 'available', 'completed_at' => null],
            'latestFacts' => $latestFacts,
            'correctingFacts' => $correcting,
            'factFormData' => $formData,
            'notice' => null,
            'error' => null,
        ], $extra))->render('trip_movement_checklists/show');
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
