<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleDamageViewTest extends CIUnitTestCase
{
    public function testVehicleDetailShowsCurrentAcceptedDamageAndAuditedHistory(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'vehicleDamage' => $this->workspace([$this->item(['status_code' => 'accepted_unrepaired', 'status_label' => 'Accepted — unrepaired'])]),
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_damage');

        $this->assertStringContainsString('Damage &amp; Condition', $html);
        $this->assertStringContainsString('Accepted — unrepaired', $html);
        $this->assertStringContainsString('Front bumper scrape', $html);
        $this->assertStringContainsString('Damage recorded', $html);
        $this->assertStringContainsString('operator #7', $html);
        $this->assertStringContainsString('Mark repaired', $html);
        $this->assertStringNotContainsString('price', strtolower($html));
    }

    public function testUnsafeDamageIsProminentWithoutAvailabilityControl(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'vehicleDamage' => $this->workspace([$this->item(['severity_code' => 'unsafe', 'severity_label' => 'Unsafe'])], true),
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_damage');

        $this->assertStringContainsString('Unsafe damage is recorded.', $html);
        $this->assertStringContainsString('has not changed vehicle availability automatically', $html);
        $this->assertStringNotContainsString('out_of_service', $html);
        $this->assertStringNotContainsString('Mark unavailable', $html);
    }

    public function testFutureMovementShowsVehicleLevelDamageAndUsesChecklistScopedActions(): void
    {
        $html = CoreServices::renderer()->setData([
            'checklist' => ['id' => 321, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 101, 'starts_at' => '2026-09-26 09:00:00'],
            'vehicleDamage' => $this->workspace([$this->item()]),
            'availableDamageExceptions' => [['id' => 500, 'note' => 'Bumper scrape']],
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('trip_movement_checklists/_known_damage');

        $this->assertStringContainsString('Current Known Damage', $html);
        $this->assertStringContainsString('Front bumper scrape', $html);
        $this->assertStringContainsString('/operations/checklists/321/damage', $html);
        $this->assertStringContainsString('/operations/checklists/321/damage/44/worsen', $html);
        $this->assertStringContainsString('Review complete damage history', $html);
        $this->assertStringContainsString('Pre-existing for this trip', $html);
        $this->assertStringContainsString('Use this only when this same damage item has become worse.', $html);
        $this->assertStringNotContainsString('name="trip_id"', $html);
        $this->assertStringNotContainsString('name="movement_event_id"', $html);
    }

    public function testMovementLabelsDamageRecordedOnSelectedTrip(): void
    {
        $item = $this->item(['discovered_turo_trip_normalized_id' => 100]);
        $html = CoreServices::renderer()->setData([
            'checklist' => ['id' => 321, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'starts_at' => '2026-09-25 09:00:00'],
            'vehicleDamage' => $this->workspace([$item]),
            'availableDamageExceptions' => [],
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('trip_movement_checklists/_known_damage');

        $this->assertStringContainsString('Recorded on this trip', $html);
        $this->assertStringNotContainsString('Pre-existing for this trip', $html);
    }

    public function testWorseningControlsDoNotOfferASeverityDowngrade(): void
    {
        $item = $this->item(['severity_code' => 'moderate', 'severity_label' => 'Moderate']);
        $workspace = $this->workspace([$item]);
        $movement = CoreServices::renderer()->setData([
            'checklist' => ['id' => 321, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'starts_at' => '2026-09-25 09:00:00'],
            'vehicleDamage' => $workspace,
            'availableDamageExceptions' => [],
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('trip_movement_checklists/_known_damage');
        $vehicle = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'vehicleDamage' => $workspace,
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_damage');

        foreach ([$movement, $vehicle] as $html) {
            $this->assertSame(1, preg_match('/New severity<select[^>]*>(.*?)<\/select>/s', $html, $matches));
            $this->assertStringNotContainsString('value="cosmetic"', $matches[1]);
            $this->assertStringContainsString('value="moderate" selected', $matches[1]);
            $this->assertStringContainsString('value="severe"', $matches[1]);
            $this->assertStringContainsString('value="unsafe"', $matches[1]);
        }
    }

    public function testRepairedDamageMovesToCollapsedVehicleHistory(): void
    {
        $history = $this->item([
            'status_code' => 'repaired',
            'status_label' => 'Repaired',
            'resolved_at' => '2026-09-27 14:00:00',
        ]);
        $workspace = $this->workspace([]);
        $workspace['history'] = [$history];
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'vehicleDamage' => $workspace,
            'notice' => null,
            'errors' => [],
            'form' => null,
            'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_damage');

        $this->assertStringContainsString('No current known damage recorded.', $html);
        $this->assertStringContainsString('Repaired / resolved history · 1', $html);
        $this->assertStringContainsString('Repaired', $html);
    }

    public function testDamageLayoutsRemainBoundedAndStackActionsOnMobile(): void
    {
        $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');
        $movement = file_get_contents(__DIR__ . '/../../app/Views/trip_movement_checklists/_known_damage.php');
        $vehicle = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/components/vehicle_damage.php');

        $this->assertIsString($css);
        $this->assertIsString($movement);
        $this->assertIsString($vehicle);
        $this->assertMatchesRegularExpression('/\.damage-form-grid[^}]*min-width: 0;[^}]*max-width: 100%;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 44rem\)[\s\S]*\.vehicle-damage-section button,[\s\S]*\.known-damage-section button[^}]*width: 100%;/s', $css);
        $this->assertMatchesRegularExpression('/\.damage-evidence li[^}]*overflow-wrap: anywhere;/s', $css);
        $this->assertMatchesRegularExpression('/\.known-damage-section \.damage-item-card > details form[^}]*display: grid;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.known-damage-section \.damage-item-card > details label,[\s\S]*:is\(select, input, textarea\)[^}]*width: 100%;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 44rem\)[\s\S]*\.vehicle-damage-section > \.section-heading,[\s\S]*\.known-damage-section > \.section-heading[^}]*flex-direction: column;/s', $css);
        $this->assertStringContainsString('maxlength="2000" required', $movement);
        $this->assertStringContainsString('External evidence reference', $vehicle);
        $this->assertStringContainsString('Link existing FleetOS records', $vehicle);
    }

    public function testRoutesAndControllerKeepChecklistContextServerSide(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../app/Config/Routes.php');
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/VehicleDamage.php');

        $this->assertIsString($routes);
        $this->assertIsString($controller);
        $this->assertStringContainsString("operations/checklists/(:num)/damage', 'VehicleDamage::createForChecklist", $routes);
        $this->assertStringContainsString("'trip_id' => (int) \$checklist['turo_trip_normalized_id']", $controller);
        $this->assertStringContainsString("'fleet_vehicle_id'", $controller);
    }

    /** @param list<array<string,mixed>> $current @return array<string,mixed> */
    private function workspace(array $current, bool $unsafe = false): array
    {
        return [
            'current' => $current,
            'history' => [],
            'has_unsafe' => $unsafe,
            'zones' => ['front' => 'Front', 'rear' => 'Rear'],
            'damage_types' => ['scratch_scuff' => 'Scratch / scuff'],
            'severities' => ['cosmetic' => 'Cosmetic', 'moderate' => 'Moderate', 'severe' => 'Severe', 'unsafe' => 'Unsafe'],
            'statuses' => ['open' => 'Open', 'accepted_unrepaired' => 'Accepted — unrepaired', 'repaired' => 'Repaired', 'resolved_other' => 'Resolved — other'],
        ];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'id' => 44,
            'zone_code' => 'front',
            'zone_label' => 'Front',
            'damage_type_code' => 'scratch_scuff',
            'damage_type_label' => 'Scratch / scuff',
            'description' => 'Front bumper scrape',
            'severity_code' => 'cosmetic',
            'severity_label' => 'Cosmetic',
            'status_code' => 'open',
            'status_label' => 'Open',
            'discovered_at' => '2026-09-25 09:30:00',
            'discovered_turo_trip_normalized_id' => 90,
            'resolved_at' => null,
            'turo_reservation_id' => 'LOCAL-DAMAGE-TRIP-A',
            'vehicle_recovery_exception_id' => null,
            'recovery_exception_status' => null,
            'damage_claim_id' => null,
            'claim_number' => null,
            'claim_status_name' => null,
            'evidence' => [],
            'events' => [[
                'event_code' => 'created',
                'occurred_at' => '2026-09-25 09:30:00',
                'actor_user_id' => 7,
                'turo_reservation_id' => 'LOCAL-DAMAGE-TRIP-A',
                'note' => null,
            ]],
        ], $overrides);
    }
}
