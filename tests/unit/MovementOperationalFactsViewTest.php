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

    public function testReturnSummaryUsesReturnLocationFuelAndContextualAction(): void
    {
        $html = $this->render('return', $this->facts([
            'event_title' => 'Actual return recorded',
            'location_label' => 'Return location',
            'location_class_label' => 'Airport HNL',
            'location_detail_value' => null,
            'cleanliness_label' => 'Dirty',
            'energy_label' => 'Fuel',
            'energy_value' => '23%',
        ]));

        $this->assertStringContainsString('Actual return recorded', $html);
        $this->assertStringContainsString('Return location', $html);
        $this->assertStringContainsString('<dt>Fuel</dt><dd>23%</dd>', $html);
        $this->assertStringNotContainsString('Record Actual Return', $html);
        $this->assertStringContainsString('Use the return fact actions above', $html);
    }

    public function testReturnChecklistShowsUnverifiedGuestReportAndScopedActions(): void
    {
        $guestReturn = [
            'id' => 44, 'occurred_at' => '2026-09-17 18:10:00', 'source' => 'guest_reported_parked_time',
            'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'G',
            'note' => 'Guest reported the vehicle parked.',
        ];
        $html = $this->render('return', $this->facts(), false, [], [
            'guestReturn' => $guestReturn, 'guestReturnActive' => true, 'returnCompleted' => false,
        ]);

        $this->assertStringContainsString('Awaiting Recovery', $html);
        $this->assertStringContainsString('Guest-reported location', $html);
        $this->assertStringContainsString('International Garage', $html);
        $this->assertStringContainsString('Level 7', $html);
        $this->assertStringContainsString('Row G', $html);
        $this->assertStringContainsString('/operations/checklists/4/guest-return-staged/void', $html);
        $this->assertStringContainsString('Correct guest report', $html);
        $this->assertStringNotContainsString('Mark Awaiting Recovery</button>', $html);

        $empty = $this->render('return', $this->facts(), false, [], ['guestReturn' => null, 'returnCompleted' => false]);
        $this->assertStringContainsString('/operations/checklists/4/guest-return-staged', $empty);
        $this->assertStringContainsString('Mark Awaiting Recovery', $empty);

        $completedWithoutReport = $this->render('return', $this->facts(), false, [], ['guestReturn' => null, 'returnCompleted' => true]);
        $this->assertStringNotContainsString('id="guest-return-entry"', $completedWithoutReport);
        $this->assertStringNotContainsString('Mark Awaiting Recovery', $completedWithoutReport);

        $guestReturn['airport_garage_code'] = null;
        $guestReturn['airport_parking_level'] = null;
        $guestReturn['airport_parking_row'] = null;
        $unknown = $this->render('return', $this->facts(), false, [], ['guestReturn' => $guestReturn, 'guestReturnActive' => true]);
        $this->assertStringContainsString('Exact HNL level/row/garage not reported', $unknown);
    }

    public function testRecoverVehiclePrefillsGuestLocationButRequiresOperatorConfirmation(): void
    {
        $report = [
            'id' => 44, 'occurred_at' => '2026-09-17 18:10:00', 'source' => 'guest_report_received',
            'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7 RF',
            'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'F',
            'note' => "Location note: Near elevators\nGuest report: parked at HNL",
        ];
        $html = $this->render('return', $this->facts(), false, [], [
            'guestReturn' => $report, 'guestReturnActive' => true, 'canRecover' => true,
        ]);

        $this->assertStringContainsString('id="recover-vehicle-entry"', $html);
        $this->assertStringContainsString('/operations/checklists/4/recover-vehicle', $html);
        $this->assertStringContainsString('Guest reported — unverified', $html);
        $this->assertMatchesRegularExpression('/name="airport_garage_code"[^>]*>.*?<option value="international"[^>]*selected/s', $html);
        $this->assertMatchesRegularExpression('/name="airport_parking_level"[^>]*>.*?<option value="7"[^>]*selected/s', $html);
        $this->assertMatchesRegularExpression('/name="airport_parking_row"[^>]*>.*?<option value="F"[^>]*selected/s', $html);
        $this->assertStringContainsString('name="recovery_location_note" maxlength="500" value="Near elevators"', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('name="confirm_recovery_location"', $html);
        $this->assertStringContainsString('name="energy_unknown_reason"', $html);
        $this->assertStringContainsString('Vehicle Recovered', $html);
        $this->assertStringNotContainsString('name="return_photos_completed"', $html);

        $completed = $this->render('return', $this->facts(), false, [], [
            'guestReturn' => $report, 'guestReturnActive' => false, 'returnCompleted' => true, 'canRecover' => false,
        ]);
        $this->assertStringNotContainsString('/operations/checklists/4/recover-vehicle', $completed);
        $this->assertStringContainsString('Recovery is authoritative', $completed);
        $this->assertStringContainsString('Historical guest report', $completed);
        $this->assertStringNotContainsString('Correct guest report', $completed);
        $this->assertStringNotContainsString('/guest-return-staged/void', $completed);
        $this->assertStringNotContainsString('Mark Awaiting Recovery', $completed);
    }

    public function testCurrentVehiclePositionUsesCanonicalHnlOrderAndOptionalDetail(): void
    {
        $html = html_entity_decode($this->render('return', $this->facts(), false, [], [
            'currentLocation' => [
                'location_class' => 'airport_hnl',
                'location_detail' => 'International Garage L7 RG',
                'location_note' => 'Near elevators',
                'airport_garage_code' => 'international',
                'airport_parking_level' => 7,
                'airport_parking_row' => 'G',
                'operational_state' => 'parked',
                'observed_at' => '2026-09-17 18:30:00',
            ],
        ]), ENT_QUOTES | ENT_HTML5);
        $expected = (new \App\Services\Fleet\HnlGarageCatalog())->locationLabel('international', 7, 'G', 'Near elevators');

        $this->assertStringContainsString((string) $expected, $html);
        $this->assertStringNotContainsString('International Garage L7 RG', $html);
    }

    public function testRecoveryDetailDoesNotUseCanonicalParkingOrGeneralGuestReportNote(): void
    {
        $report = [
            'id' => 44, 'occurred_at' => '2026-09-17 18:10:00', 'source' => 'guest_report_received',
            'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7 RF',
            'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'F',
            'note' => null,
        ];
        $data = ['guestReturn' => $report, 'guestReturnActive' => true, 'canRecover' => true];
        $withoutNote = html_entity_decode($this->render('return', $this->facts(), false, [], $data), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="recovery_location_note" maxlength="500" value=""', $withoutNote);

        $data['guestReturn']['note'] = 'Guest report: parked at HNL; keys left inside.';
        $generalReportOnly = html_entity_decode($this->render('return', $this->facts(), false, [], $data), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="recovery_location_note" maxlength="500" value=""', $generalReportOnly);

        $data['guestReturn']['location_detail'] = 'Near side doors';
        $genuineDetail = html_entity_decode($this->render('return', $this->facts(), false, [], $data), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="recovery_location_note" maxlength="500" value="Near side doors"', $genuineDetail);
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

    public function testActiveHandoffSuppressesStaleStagedConfirmationAction(): void
    {
        $staged = $this->facts(['event_code' => 'vehicle_staged', 'event_title' => 'Staged for pickup', 'location_label' => 'Staging location']);
        $data = [
            'isStagedPickup' => true,
            'isPickupConfirmed' => true,
            'pickupConfirmedAt' => '2026-09-03 08:05:00',
            'tripFacts' => ['pickup' => $staged, 'return' => null],
            'readiness' => [
                'ready' => true,
                'blocking_remaining_count' => 0,
                'additional_actions_remaining_count' => 0,
                'readiness_phase' => 'pickup_preparation',
                'requirements' => [
                    ['code' => 'guest_handoff', 'label' => 'Guest handoff', 'phase' => 'pickup_lifecycle', 'kind' => 'derived', 'status' => 'satisfied', 'blocking' => false, 'satisfied_by' => 'movement_event', 'basis_at' => '2026-09-03 08:05:00', 'action' => null, 'allows_na' => false],
                ],
                'workflow_history' => ['historically_completed' => false, 'completed_at' => null, 'legacy_items' => []],
            ],
        ];

        $html = $this->render('pickup', $staged, false, [], $data);

        $this->assertStringContainsString('Guest pickup confirmed', $html);
        $this->assertStringContainsString('Sep 3, 2026 8:05 AM', $html);
        $this->assertMatchesRegularExpression('/is-complete[^>]*>.*Guest handoff/s', $html);
        $this->assertStringNotContainsString('Confirm Guest Pickup', $html);
    }

    public function testStagedPickupWithoutHandoffKeepsLifecyclePendingAndConfirmationAvailable(): void
    {
        $staged = $this->facts(['event_code' => 'vehicle_staged', 'event_title' => 'Staged for pickup', 'location_label' => 'Staging location']);
        $data = [
            'isStagedPickup' => true,
            'isPickupConfirmed' => false,
            'tripFacts' => ['pickup' => $staged, 'return' => null],
            'readiness' => [
                'ready' => true,
                'blocking_remaining_count' => 0,
                'additional_actions_remaining_count' => 0,
                'readiness_phase' => 'pickup_preparation',
                'requirements' => [
                    ['code' => 'guest_handoff', 'label' => 'Guest handoff', 'phase' => 'pickup_lifecycle', 'kind' => 'derived', 'status' => 'unsatisfied', 'blocking' => false, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'record_fact', 'label' => 'Record actual guest handoff'], 'allows_na' => false],
                ],
                'workflow_history' => ['historically_completed' => false, 'completed_at' => null, 'legacy_items' => []],
            ],
        ];

        $html = $this->render('pickup', $staged, false, [], $data);

        $this->assertMatchesRegularExpression('/is-pending[^>]*>.*Guest handoff/s', $html);
        $this->assertStringContainsString('Confirm Guest Pickup', $html);
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

    public function testMissingPickupFactOffersTripScopedRetroactiveHandoffWithoutPickupChecklist(): void
    {
        $html = $this->render('return', $this->facts(), false, [], [
            'tripFacts' => ['pickup' => null, 'return' => null],
            'canRecordRetroactiveHandoff' => true,
        ]);

        $this->assertStringContainsString('<h3 id="pickup-fact-heading">Not recorded</h3>', $html);
        $this->assertStringContainsString('Record pickup / handoff', $html);
        $this->assertStringContainsString('?action=record-handoff#pickup-fact-heading', $html);
        $this->assertStringNotContainsString('/operations/trips/100/actual-handoff', $html);
    }

    public function testRetroactiveHandoffFormUsesTripScopedPostAndOptionalTruthfulFields(): void
    {
        $html = $this->render('return', $this->facts(), false, [], [
            'tripFacts' => ['pickup' => null, 'return' => null],
            'canRecordRetroactiveHandoff' => true,
            'showRetroactiveHandoffForm' => true,
            'retroactiveHandoffData' => ['occurred_at' => '2026-09-18T17:00'],
        ]);

        $this->assertStringContainsString('action="/operations/trips/100/actual-handoff" method="post"', $html);
        $this->assertStringContainsString('Actual pickup time (Honolulu)', $html);
        $this->assertStringContainsString('name="occurred_at" required value="2026-09-18T17&#x3A;00"', $html);
        $this->assertStringContainsString('Handoff location (optional)', $html);
        $this->assertStringContainsString('Cleanliness (optional)', $html);
        $this->assertStringContainsString('Charge/Fuel percent (optional)', $html);
        $this->assertStringContainsString('Record Guest Handoff', $html);
        $this->assertStringNotContainsString('name="company_id"', $html);
    }

    public function testRecordedPickupFactNeverOffersRetroactiveHandoffAction(): void
    {
        $pickup = $this->facts();
        $html = $this->render('return', $pickup, false, [], [
            'tripFacts' => ['pickup' => $pickup, 'return' => null],
            'canRecordRetroactiveHandoff' => true,
        ]);

        $this->assertStringNotContainsString('Record pickup / handoff', $html);
        $this->assertStringNotContainsString('/operations/trips/100/actual-handoff', $html);
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
            'airport_location_label' => 'Level 7 · Row F · International Garage',
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

        $this->assertStringContainsString('Level 7 · Row F · International Garage', $html);
        $this->assertStringContainsString('name="airport_garage_code"', $html);
        $this->assertStringContainsString('name="airport_parking_level"', $html);
        $this->assertStringContainsString('name="airport_parking_row"', $html);
        $this->assertStringContainsString('>Row<select name="airport_parking_row"', $html);
        $this->assertStringNotContainsString('stall', strtolower($html));
        $this->assertStringContainsString('value="terminal_2" data-max-level="6"', $html);
        $this->assertTrue(strpos($html, 'name="airport_parking_level"') < strpos($html, 'name="airport_parking_row"'));
        $this->assertTrue(strpos($html, 'name="airport_parking_row"') < strpos($html, 'name="airport_garage_code"'));
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
            'airport_location_label' => 'Level 4 · Row M · Terminal 2 Garage',
            'approved_turo_garage' => false,
        ]), true, [
            'location_class' => 'airport_hnl',
            'location_detail' => 'Terminal 2 Garage L4 RM',
            'airport_garage_code' => 'terminal_2',
            'airport_parking_level' => 4,
            'airport_parking_row' => 'M',
            'note' => 'Near the elevator.',
        ]);

        $this->assertStringContainsString('Level 4 · Row M · Terminal 2 Garage', $html);
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
            'airport_location_label' => 'Level 7 · Row F · International Garage',
            'form_data' => ['event_id' => 21, 'assessment_id' => 22, 'occurred_at' => '2026-09-03T09:15', 'location_class' => 'airport_hnl', 'location_detail' => 'International Garage L7 RF', 'airport_garage_code' => 'international', 'airport_parking_level' => 7, 'airport_parking_row' => 'F', 'cleanliness' => 'dirty', 'energy_percent' => 24, 'note' => 'Return checked.'],
        ]);

        $html = $this->render('return', $facts, true, $facts['form_data']);

        $this->assertStringContainsString('value="2026-09-03T09&#x3A;15"', $html);
        $this->assertStringContainsString('value="airport_hnl" selected', $html);
        $this->assertStringContainsString('Level 7 · Row F · International Garage', $html);
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

    public function testProjectionRendersKnownFactsAndOnlyGenuineHumanControls(): void
    {
        $html = $this->render('return', $this->facts([
            'event_title' => 'Actual return recorded',
            'location_label' => 'Return location',
            'location_class_label' => 'Waikiki Hotel',
            'location_detail_value' => 'Romer House',
            'cleanliness_label' => 'Dirty',
            'energy_value' => '83%',
        ]), false, [], $this->readinessViewData());

        $this->assertStringContainsString('Return Workflow', $html);
        $this->assertStringContainsString('Awaiting return', $html);
        $this->assertStringNotContainsString('1 blocking actions remaining', $html);
        $this->assertStringContainsString('83%', $html);
        $this->assertStringContainsString('Dirty', $html);
        $this->assertStringContainsString('Legacy checklist history', $html);
        $this->assertStringNotContainsString('/operations/checklist-items/81/complete', $html);
        $this->assertStringNotContainsString('/operations/checklist-items/82/undo', $html);
        $this->assertStringNotContainsString('>Confirm</button>', $html);
        $this->assertStringNotContainsString('Exceptional vehicle hold', $html);
    }

    public function testReturnReadinessPutsActionableRowsBeforeKnownAndCompletedFacts(): void
    {
        $data = $this->readinessViewData();
        $data['returnCompleted'] = true;
        $data['turnaroundWork'] = ['cleaning' => ['fleet_vehicle_id' => 10], 'energy' => ['label' => 'Charge Needed — 25%, target 80%', 'condition_code' => 'charge_required', 'action_label' => 'Record Charge Level']];
        $html = html_entity_decode($this->render('return', $this->facts(), false, [], $data), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('Recovery complete', $html);
        $this->assertStringContainsString('Cleaning Required', $html);
        $this->assertStringContainsString('Charge Needed — 25%, target 80%', $html);
        $this->assertStringContainsString('>Mark Clean</button>', $html);
        $this->assertStringContainsString('>Record Condition</a>', $html);
        $this->assertStringContainsString('>Record Charge Level</button>', $html);
        $this->assertSame(2, substr_count($html, 'action="/fleet/vehicles/10/current-readiness"'));
        $this->assertSame(2, substr_count($html, 'name="return_checklist_id"'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'method="post"'));
        $this->assertLessThan(strpos($html, 'Legacy checklist history'), strpos($html, 'Cleaning Required'));
        $this->assertStringNotContainsString('id="checklist-action-exterior_inspected"', $html);
    }

    public function testMissingEnergyTargetIsConfigurationRatherThanRoutineTargetDecision(): void
    {
        $data = $this->readinessViewData();
        $data['returnCompleted'] = true;
        $data['turnaroundWork'] = ['cleaning' => null, 'energy' => [
            'label' => 'Fuel target not configured',
            'condition_code' => 'target_needed',
            'action_label' => 'Configure Vehicle',
            'href' => '/fleet/vehicles/10/edit',
        ]];

        $html = html_entity_decode($this->render('return', $this->facts(), false, [], $data), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('Fuel target not configured', $html);
        $this->assertStringContainsString('Configuration needed', $html);
        $this->assertStringContainsString('href="/fleet/vehicles/10/edit"', $html);
        $this->assertStringContainsString('>Configure Vehicle</a>', $html);
        $this->assertStringNotContainsString('Set charge/fuel target', $html);
    }

    public function testChecklistFocusTargetsClearStickyMobileNavigation(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('.movement-main [id^="checklist-action-"],', $css);
        $this->assertStringContainsString('.movement-main #readiness-heading,', $css);
        $this->assertStringContainsString('.movement-main #handoff-entry,', $css);
        $this->assertStringContainsString('.movement-main #position-entry,', $css);
        $this->assertStringContainsString('scroll-margin-top: var(--checklist-focus-offset);', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 900px\) \{\s*\.app-frame \{\s*--mobile-nav-height: 64px;/s', $css);
        $this->assertStringContainsString('min-height: var(--mobile-nav-height);', $css);
        $this->assertStringContainsString('max-height: calc(100vh - var(--mobile-nav-height));', $css);
        $this->assertStringContainsString('--checklist-focus-offset: calc(var(--mobile-nav-height) + 20px);', $css);
    }

    public function testTripFactsHandoffActionsUseResponsiveSharedFormActions(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/trip_movement_checklists/show.php');
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('<div class="form-actions"><button class="primary-action" type="submit">Record Guest Handoff</button><a class="action-link"', $view);
        $this->assertMatchesRegularExpression('/\.issue-filters > \.form-actions\s*\{[^}]*grid-column: 1 \/ -1;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.form-actions \.primary-action\s*\{[^}]*min-width: 220px;[^}]*min-height: 52px;[^}]*white-space: nowrap;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 560px\).*?\.form-actions \.primary-action\s*\{[^}]*width: 100%;[^}]*min-width: 0;.*?\.issue-filters > \.form-actions\s*\{[^}]*justify-content: flex-start;/s', $css);
    }

    public function testActionRequiredRowsUseSharedAlignedActionColumnWithoutChangingPostSecurity(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/trip_movement_checklists/_readiness.php');
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertStringContainsString('class="is-pending readiness-action-row', $view);
        $this->assertStringContainsString('class="readiness-action-label"', $view);
        $this->assertStringContainsString('<div class="readiness-action-controls"><a class="action-link" href="#handoff-entry">Record facts</a></div>', $view);
        $this->assertSame(3, substr_count($view, '<button class="primary-action" type="submit">Confirm</button>'));
        $this->assertGreaterThanOrEqual(3, substr_count($view, 'method="post"><?= csrf_field() ?>'));
        $this->assertMatchesRegularExpression('/\.readiness-actions li\.readiness-action-row\s*\{[^}]*grid-template-columns: 22px minmax\(0, 1fr\) minmax\(124px, auto\);/s', $css);
        $this->assertMatchesRegularExpression('/\.readiness-action-row \.readiness-action-controls \.primary-action,.*?min-width: 124px;.*?justify-content: center;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 560px\).*?\.readiness-actions li\.readiness-action-row\s*\{[^}]*grid-template-columns: 22px minmax\(0, 1fr\);.*?\.readiness-action-row > \.readiness-action-controls\s*\{[^}]*grid-column: 2;/s', $css);
    }

    public function testLegacyRowsRemainOnlyInCollapsedChecklistHistory(): void
    {
        $html = $this->render('return', $this->facts(), false, [], $this->readinessViewData());

        $this->assertStringContainsString('<details class="checklist-history"><summary>Legacy checklist history</summary>', $html);
        $this->assertStringContainsString('Legacy return workflow completed', $html);
        $this->assertStringContainsString('Imported history', $html);
        $this->assertSame(1, substr_count($html, 'Legacy return workflow completed'));
        $this->assertStringNotContainsString('movement-checklist-list', $html);
    }

    public function testMovementPageRetainsTheCompleteProjectedActionList(): void
    {
        $data = $this->readinessViewData();
        $data['readiness']['blocking_remaining_count'] = 12;
        $data['readiness']['requirements'] = array_map(static fn (int $index): array => [
            'code' => 'full_action_' . $index,
            'label' => 'Full requirement ' . $index,
            'phase' => 'return_intake',
            'kind' => 'derived',
            'status' => 'unsatisfied',
            'blocking' => true,
            'satisfied_by' => null,
            'basis_at' => null,
            'action' => ['type' => 'record_fact', 'label' => 'Complete full action ' . $index],
            'allows_na' => false,
        ], range(1, 12));

        $html = $this->render('return', $this->facts(), false, [], $data);

        $this->assertStringNotContainsString('12 blocking actions remaining', $html);
        $this->assertStringNotContainsString('Complete full action', $html);
        $this->assertStringContainsString('Awaiting return', $html);
    }

    public function testClosedWorkflowLocksHumanControlsUntilExplicitReopen(): void
    {
        $data = $this->readinessViewData();
        $data['checklist']['completed_at'] = '2026-09-03 10:30:00';
        $data['checklist']['completion_note'] = 'Return reviewed.';
        $data['readiness']['workflow_history']['historically_completed'] = true;
        $data['readiness']['workflow_history']['completed_at'] = '2026-09-03 10:30:00';

        $html = $this->render('return', $this->facts(), false, [], $data);

        $this->assertStringContainsString('Legacy checklist history', $html);
        $this->assertStringContainsString('Historical workflow completion', $html);
        $this->assertStringNotContainsString('/operations/checklist-items/81/complete', $html);
        $this->assertStringNotContainsString('/operations/checklist-items/82/undo', $html);
        $this->assertStringNotContainsString('Close Movement Workflow', $html);
    }

    public function testPickupPreparationKeepsGuestHandoffInNonBlockingLifecycle(): void
    {
        $data = $this->readinessViewData();
        $data['checklist'] = array_merge($data['checklist'], ['movement_type' => 'pickup', 'items' => []]);
        $data['readiness'] = [
            'ready' => true,
            'blocking_remaining_count' => 0,
            'readiness_phase' => 'pickup_preparation',
            'requirements' => [
                ['code' => 'airport_staging', 'label' => 'Airport staging', 'phase' => 'pickup_preparation', 'kind' => 'derived', 'status' => 'satisfied', 'blocking' => true, 'satisfied_by' => 'movement_event', 'basis_at' => '2026-09-03 07:30:00', 'action' => null, 'allows_na' => false],
                ['code' => 'guest_handoff', 'label' => 'Guest handoff', 'phase' => 'pickup_lifecycle', 'kind' => 'derived', 'status' => 'unsatisfied', 'blocking' => false, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'record_fact', 'label' => 'Record actual guest handoff'], 'allows_na' => false],
            ],
            'workflow_history' => ['historically_completed' => false, 'completed_at' => null, 'legacy_items' => []],
        ];

        $html = $this->render('pickup', $this->facts(['event_code' => 'vehicle_staged', 'event_title' => 'Staged for pickup']), false, [], $data);

        $this->assertStringContainsString('Pickup preparation', $html);
        $this->assertStringContainsString('Ready', $html);
        $this->assertStringContainsString('Airport staging', $html);
        $this->assertStringContainsString('Lifecycle', $html);
        $this->assertStringContainsString('Guest handoff', $html);
        $this->assertStringContainsString('Does not gate pickup preparation', $html);
    }

    public function testExceptionalDispositionControlHasVisibleCanonicalOptionsOnly(): void
    {
        $data = $this->readinessViewData();

        $html = $this->render('return', $this->facts(), false, [], $data);

        $this->assertStringNotContainsString('Inspect exterior</button>', $html);
        $this->assertStringNotContainsString('Inspect exterior</a>', $html);
        $this->assertStringContainsString('<strong>Exterior inspected</strong><span>Open</span>', $html);
        $this->assertStringNotContainsString('Exceptional vehicle hold', $html);

        $data['recoveryExceptions'] = [['id' => 99, 'status' => 'open', 'exception_code' => 'not_drivable', 'note' => 'Tow required']];
        $exceptionHtml = $this->render('return', $this->facts(), false, [], $data);
        $this->assertStringContainsString('Exceptional vehicle hold', $exceptionHtml);
        $this->assertStringContainsString('<option value="">No exceptional hold</option>', $exceptionHtml);
        $this->assertStringContainsString('Maintenance required', $exceptionHtml);
        $this->assertStringContainsString('Claim / damage review required', $exceptionHtml);
        $this->assertStringContainsString('Offline / unavailable', $exceptionHtml);
        $this->assertStringNotContainsString('Needs Cleaning</option>', $exceptionHtml);
        $this->assertStringNotContainsString('Needs Charging</option>', $exceptionHtml);
    }

    public function testNextPickupHeadingOnlyUsesTurnaroundForSameDayPair(): void
    {
        $data = $this->readinessViewData();
        $data['returnCompleted'] = true;
        $data['readiness']['next_trip'] = ['starts_at' => '2026-09-04 09:00:00'];
        $data['readiness']['is_same_day_turnaround'] = false;

        $preparationHtml = $this->render('return', $this->facts(), false, [], $data);

        $this->assertStringContainsString('Next confirmed pickup: 2026-09-04 09:00:00', $preparationHtml);
        $this->assertStringNotContainsString('Same-day turnaround', $preparationHtml);

        $data['readiness']['is_same_day_turnaround'] = true;
        $turnaroundHtml = $this->render('return', $this->facts(), false, [], $data);

        $this->assertStringContainsString('Next confirmed pickup: 2026-09-04 09:00:00', $turnaroundHtml);
        $this->assertStringNotContainsString('Same-day turnaround', $turnaroundHtml);
    }

    public function testPickupRendersCompactCompositeCapabilityActionsAndLegacyHistory(): void
    {
        $items = [
            ['id' => 91, 'item_code' => 'vehicle_inspected', 'label' => 'Vehicle inspected', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
            ['id' => 92, 'item_code' => 'exterior_photos_completed', 'label' => 'Exterior condition photos completed', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
            ['id' => 93, 'item_code' => 'interior_photos_completed', 'label' => 'Interior condition photos completed', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
            ['id' => 94, 'item_code' => 'guest_pickup_instructions_confirmed', 'label' => 'Guest pickup instructions confirmed', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
            ['id' => 95, 'item_code' => 'turo_access_instructions_confirmed', 'label' => 'Turo Access instructions confirmed', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
        ];
        $data = [
            'checklist' => ['movement_type' => 'pickup', 'items' => $items, 'vehicle_disposition' => null],
            'readiness' => [
                'ready' => false,
                'blocking_remaining_count' => 3,
                'readiness_phase' => 'pickup_preparation',
                'requirements' => [
                    ['code' => 'photos_complete', 'label' => 'Photos complete', 'phase' => 'pickup_preparation', 'kind' => 'human', 'status' => 'unsatisfied', 'blocking' => true, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'photos_composite', 'checklist_id' => 4, 'label' => 'Photos complete'], 'allows_na' => false],
                    ['code' => 'key_card_confirmed', 'label' => 'Key card present', 'phase' => 'pickup_preparation', 'kind' => 'hybrid', 'status' => 'unsatisfied', 'blocking' => true, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'checklist_item', 'item_id' => null, 'label' => 'Key card present'], 'allows_na' => false],
                    ['code' => 'charging_adapter_confirmed', 'label' => 'Charging adapter present', 'phase' => 'pickup_preparation', 'kind' => 'human', 'status' => 'unsatisfied', 'blocking' => true, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'charging_adapter', 'checklist_id' => 4, 'label' => 'Charging adapter present'], 'allows_na' => false],
                    ['code' => 'guest_handoff', 'label' => 'Guest handoff', 'phase' => 'pickup_lifecycle', 'kind' => 'derived', 'status' => 'unsatisfied', 'blocking' => false, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'record_fact', 'label' => 'Record actual guest handoff'], 'allows_na' => false],
                ],
                'workflow_history' => ['historically_completed' => false, 'completed_at' => null, 'vehicle_disposition' => null, 'legacy_items' => $items],
            ],
        ];

        $html = $this->render('pickup', $this->facts(), false, [], $data);

        $this->assertSame(1, substr_count($html, 'Photos complete'));
        $this->assertStringContainsString('/operations/checklists/4/photos-complete', $html);
        $this->assertStringContainsString('/operations/checklists/4/charging-adapter-present', $html);
        $this->assertStringContainsString('Key card present', $html);
        $this->assertStringContainsString('Charging adapter present', $html);
        $this->assertStringNotContainsString('Confirm vehicle inspected', $html);
        $this->assertStringNotContainsString('Confirm guest pickup instructions', $html);
        $this->assertStringNotContainsString('Confirm Turo Access instructions', $html);
        $this->assertStringContainsString('Lifecycle', $html);
        $this->assertStringContainsString('Guest handoff', $html);
        $this->assertStringContainsString('Vehicle inspected', $html);
        $this->assertStringContainsString('Guest pickup instructions confirmed', $html);
        $this->assertStringContainsString('Turo Access instructions confirmed', $html);
    }

    /** @param array<string, mixed> $latestFacts @param array<string, mixed> $formData */
    private function render(string $movementType, array $latestFacts, bool $correcting = false, array $formData = [], array $extra = []): string
    {
        $checklist = array_merge(['exists' => true, 'id' => 4, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'fleet_code' => 'EV-10', 'movement_type' => $movementType, 'scheduled_at' => '2026-09-03 08:00:00', 'guest_name' => 'Guest', 'readiness_status' => 'ready', 'progress' => ['required_complete_count' => 1, 'required_count' => 1, 'required_remaining_count' => 0], 'items' => [], 'vehicle_disposition' => 'available', 'completed_at' => null], $extra['checklist'] ?? []);
        unset($extra['checklist']);

        return CoreServices::renderer()->setData(array_merge([
            'assets' => ['css' => null, 'js' => null],
            'checklist' => $checklist,
            'latestFacts' => $latestFacts,
            'correctingFacts' => $correcting,
            'factFormData' => $formData,
            'notice' => null,
            'error' => null,
        ], $extra))->render('trip_movement_checklists/show');
    }

    /** @return array<string, mixed> */
    private function readinessViewData(): array
    {
        $items = [
            ['id' => 80, 'item_code' => 'vehicle_received', 'label' => 'Legacy return received', 'completion_state' => 'complete', 'applicability' => 'applicable', 'completion_source' => 'imported', 'completed_at' => '2026-09-03 09:10:00', 'note' => null],
            ['id' => 81, 'item_code' => 'exterior_inspected', 'label' => 'Exterior inspected', 'completion_state' => 'open', 'applicability' => 'applicable', 'completion_source' => null, 'completed_at' => null, 'note' => null],
            ['id' => 82, 'item_code' => 'interior_inspected', 'label' => 'Interior inspected', 'completion_state' => 'complete', 'applicability' => 'applicable', 'completion_source' => 'manual', 'completed_at' => '2026-09-03 09:22:00', 'note' => null],
            ['id' => 83, 'item_code' => 'return_workflow_completed', 'label' => 'Legacy return workflow completed', 'completion_state' => 'complete', 'applicability' => 'applicable', 'completion_source' => 'manual', 'completed_at' => '2026-09-03 09:30:00', 'note' => 'Imported history'],
        ];
        $requirements = [
            ['code' => 'vehicle_received', 'label' => 'Vehicle returned', 'phase' => 'return_intake', 'kind' => 'derived', 'status' => 'satisfied', 'blocking' => true, 'satisfied_by' => 'movement_event', 'basis_at' => '2026-09-03 09:16:00', 'action' => null, 'allows_na' => false],
            ['code' => 'energy_known', 'label' => 'Energy known', 'phase' => 'return_intake', 'kind' => 'derived', 'status' => 'satisfied', 'blocking' => true, 'satisfied_by' => 'movement_assessment', 'basis_at' => '2026-09-03 09:17:00', 'action' => null, 'allows_na' => false],
            ['code' => 'cleaning_status_known', 'label' => 'Cleaning status known', 'phase' => 'return_intake', 'kind' => 'derived', 'status' => 'satisfied', 'blocking' => true, 'satisfied_by' => 'movement_assessment', 'basis_at' => '2026-09-03 09:17:00', 'action' => null, 'allows_na' => false],
            ['code' => 'exterior_inspected', 'label' => 'Exterior inspected', 'phase' => 'return_intake', 'kind' => 'human', 'status' => 'unsatisfied', 'blocking' => true, 'satisfied_by' => null, 'basis_at' => null, 'action' => ['type' => 'checklist_item', 'item_id' => 81, 'label' => 'Confirm exterior inspected'], 'allows_na' => false],
            ['code' => 'interior_inspected', 'label' => 'Interior inspected', 'phase' => 'return_intake', 'kind' => 'human', 'status' => 'satisfied', 'blocking' => true, 'satisfied_by' => 'human_checklist', 'basis_at' => '2026-09-03 09:22:00', 'action' => null, 'allows_na' => false],
        ];

        return [
            'checklist' => ['items' => $items, 'vehicle_disposition' => null],
            'readiness' => [
                'ready' => false,
                'blocking_remaining_count' => 1,
                'readiness_phase' => 'return_intake',
                'requirements' => $requirements,
                'exceptional_dispositions' => [
                    'maintenance_required' => 'Maintenance required',
                    'claim_review_required' => 'Claim / damage review required',
                    'offline' => 'Offline / unavailable',
                ],
                'workflow_history' => ['historically_completed' => false, 'completed_at' => null, 'vehicle_disposition' => 'needs_cleaning', 'legacy_items' => $items],
            ],
        ];
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
