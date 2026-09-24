<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleHealthViewTest extends CIUnitTestCase
{
    public function testVehicleHealthRendersAuthorityPolicyReminderAndHistory(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 3, 'fleet_code' => 'Spaceship03'],
            'vehicleHealth' => [
                'current_tire_pressure' => [
                    'lf_psi' => '35', 'rf_psi' => '35', 'lr_psi' => '36', 'rr_psi' => '36',
                    'recommended_psi' => '42', 'observed_at' => '2026-09-23 09:00:00', 'source' => 'manual',
                ],
                'current_odometer' => ['odometer_miles' => 12345, 'observed_at' => '2026-09-23 08:00:00', 'source' => 'manual'],
                'legacy_odometer' => 12000,
                'policy' => ['id' => 1, 'recommended_psi' => '42', 'acceptable_min_psi' => '40', 'acceptable_max_psi' => '44', 'interval_value' => 30, 'safety_min_psi' => null, 'safety_max_psi' => null, 'is_enabled' => 1],
                'reminders' => [[
                    'title' => 'Tire pressure needs attention', 'state' => 'attention', 'context' => 'LF below range',
                    'due_at' => '2026-10-23 09:00:00', 'action_label' => 'Correct tire pressure',
                    'href' => '/fleet/vehicles/3#record-tire-pressure',
                ]],
                'history' => [[
                    'id' => 4, 'observation_code' => 'tire_pressure', 'observed_at' => '2026-09-23 09:00:00',
                    'source' => 'manual', 'lf_psi' => '35', 'rf_psi' => '35', 'lr_psi' => '36', 'rr_psi' => '36',
                    'recommended_psi' => '42', 'odometer_miles' => null, 'note' => null, 'supersedes_observation_id' => null,
                    'voided_at' => null, 'void_reason' => null,
                ]],
                'default_observed_at' => '2026-09-23T10:00',
            ],
            'notice' => null, 'errors' => [], 'form' => null, 'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_health');

        $this->assertStringContainsString('Vehicle Health', $html);
        $this->assertStringContainsString('35 PSI', $html);
        $this->assertStringContainsString('Acceptable range: 40–44 PSI', $html);
        $this->assertStringNotContainsString('35.0 PSI', $html);
        $this->assertStringContainsString('12,345 mi', $html);
        $this->assertStringContainsString('Correct tire pressure', $html);
        $this->assertStringContainsString('Next routine check:', $html);
        $this->assertStringNotContainsString('Next check:', $html);
        $this->assertStringContainsString('Observation history · 1', $html);
        $this->assertStringContainsString('name="lf_psi"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('step="1" min="1" max="200"', $html);
        $this->assertStringNotContainsString('step="0.1"', $html);
        $this->assertStringContainsString('name="correction_reason" required', $html);
        $this->assertStringContainsString('name="void_reason" required', $html);
    }

    public function testMissingPolicyAndLegacyOdometerAreExplicitlyUnverified(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 8, 'fleet_code' => 'Spaceship08'],
            'vehicleHealth' => [
                'current_tire_pressure' => null, 'current_odometer' => null, 'legacy_odometer' => 9999,
                'policy' => null, 'reminders' => [[
                    'title' => 'Current odometer not confirmed', 'state' => 'due',
                    'context' => 'Legacy odometer 9,999 mi is unverified.', 'due_at' => null,
                    'action_label' => 'Record current odometer', 'href' => '/fleet/vehicles/8#record-odometer',
                ]], 'history' => [], 'default_observed_at' => '2026-09-23T10:00',
            ],
            'notice' => null, 'errors' => [], 'form' => null, 'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_health');

        $this->assertStringContainsString('Tire-pressure policy not configured.', $html);
        $this->assertStringContainsString('Legacy odometer — unverified.', $html);
        $this->assertStringContainsString('Record current odometer', $html);
        $this->assertStringContainsString('No acceptable range will be inferred', $html);
    }

    public function testOverdueReminderUsesRoutineDueWording(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 3, 'fleet_code' => 'Spaceship03'],
            'vehicleHealth' => [
                'current_tire_pressure' => null, 'current_odometer' => null, 'legacy_odometer' => null,
                'policy' => null, 'reminders' => [[
                    'title' => 'Tire pressure check', 'state' => 'overdue',
                    'context' => 'Routine tire-pressure verification is overdue.',
                    'due_at' => '2026-09-22 09:00:00', 'action_label' => 'Check tire pressure',
                    'href' => '/fleet/vehicles/3#record-tire-pressure',
                ]], 'history' => [], 'default_observed_at' => '2026-09-23T10:00',
            ],
            'notice' => null, 'errors' => [], 'form' => null, 'formData' => [],
        ])->render('fleet_vehicles/components/vehicle_health');

        $this->assertStringContainsString('Routine check was due:', $html);
    }

    public function testVehicleEditNoLongerPostsIndependentOdometerAndResponsiveContainmentExists(): void
    {
        $form = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/form.php');
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/FleetVehicles.php');
        $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');

        $this->assertIsString($form);
        $this->assertIsString($controller);
        $this->assertIsString($css);
        $this->assertStringNotContainsString('name="odometer_miles"', $form);
        $this->assertStringContainsString('timestamped observations', $form);
        $this->assertStringNotContainsString("'out_of_service_date', 'odometer_miles'", $controller);
        $this->assertMatchesRegularExpression('/\.health-wheel-fields\s*\{[^}]*grid-template-columns: repeat\(auto-fit, minmax\(min\(100%, 10rem\), 1fr\)\);/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 44rem\).*\.health-wheel-fields,[^{]*\.health-void-form\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\);/s', $css);
    }

    public function testRoutesAreExplicitPostOnlyAndMovementUsesSharedHealthAction(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../app/Config/Routes.php');
        $readiness = file_get_contents(__DIR__ . '/../../app/Views/trip_movement_checklists/_readiness.php');
        $return = file_get_contents(__DIR__ . '/../../app/Views/trip_movement_checklists/_return_workflow.php');

        $this->assertIsString($routes);
        $this->assertIsString($readiness);
        $this->assertIsString($return);
        $this->assertStringContainsString("post('(:num)/health/tire-pressure'", $routes);
        $this->assertStringContainsString("post('(:num)/health/odometer'", $routes);
        $this->assertStringNotContainsString("get('(:num)/health/", $routes);
        $this->assertStringContainsString("\$actionType === 'vehicle_health'", $readiness);
        $this->assertStringContainsString('Record tire pressure (optional)', $return);
        $this->assertStringContainsString('not by return count', $return);
    }
}
