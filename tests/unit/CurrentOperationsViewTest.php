<?php

use App\Services\Fleet\HnlGarageCatalog;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class CurrentOperationsViewTest extends CIUnitTestCase
{
    public function testPanelShowsTruthfulStateCanonicalLinksAndVehicleScopedForms(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'currentLocation' => [
                'location_class' => 'airport_hnl',
                'operational_state' => 'parked',
                'airport_garage_code' => 'international',
                'airport_parking_level' => 7,
                'airport_parking_row' => 'F',
                'observed_at' => '2026-09-09 16:30:00',
            ],
            'currentReadiness' => ['cleanliness' => 'clean', 'energy_percent' => 88, 'captured_at' => '2026-09-09 16:45:00'],
            'currentMovementHref' => '/operations/checklists/41',
            'hnlGarages' => (new HnlGarageCatalog())->definitions(),
            'currentStateNotice' => null,
            'currentStateError' => null,
            'currentPositionData' => [],
            'currentReadinessData' => [],
        ])->render('fleet_vehicles/components/current_operations'), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('Current Operations', $html);
        $this->assertStringContainsString('International Garage · Level 7 · Row F', $html);
        $this->assertStringContainsString('Clean · 88%', $html);
        $this->assertStringContainsString('href="/operations/vehicles/10/trip-history"', $html);
        $this->assertStringContainsString('href="/operations/checklists/41"', $html);
        $this->assertStringContainsString('action="/fleet/vehicles/10/current-position"', $html);
        $this->assertStringContainsString('action="/fleet/vehicles/10/current-readiness"', $html);
        $this->assertStringContainsString('name="airport_parking_row"', $html);
        $this->assertStringNotContainsString('stall', strtolower($html));
    }

    public function testRentedVehicleDoesNotExposeCurrentStateWriteForms(): void
    {
        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'currentLocation' => ['location_class' => 'home', 'operational_state' => 'rented'],
            'currentReadiness' => null,
            'currentMovementHref' => null,
            'hnlGarages' => (new HnlGarageCatalog())->definitions(),
            'currentStateNotice' => null,
            'currentStateError' => null,
            'currentPositionData' => [],
            'currentReadinessData' => [],
        ])->render('fleet_vehicles/components/current_operations');

        $this->assertStringContainsString('Rented', $html);
        $this->assertStringContainsString('Record its return or recovery first', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('Open Movement', $html);
    }

    public function testCanonicalWaikikiAndTrueUnknownLocationsRemainDistinct(): void
    {
        $waikiki = $this->renderLocation(['location_class' => 'waikiki_hotel', 'location_detail' => 'Romer House']);
        $unknown = $this->renderLocation(['location_class' => 'unknown']);

        $this->assertStringContainsString('<strong>Waikiki Hotel</strong>', $waikiki);
        $this->assertStringContainsString('<small>Romer House</small>', $waikiki);
        $this->assertStringContainsString('<strong>Unknown</strong>', $unknown);
        $this->assertStringNotContainsString('<strong>Waikiki Hotel</strong>', $unknown);
    }

    public function testRegistryAndMovementLayoutsExposeDiscoverableAndCenteredPaths(): void
    {
        $registry = file_get_contents(dirname(__DIR__, 2) . '/app/Views/fleet_vehicles/index.php');
        $movement = file_get_contents(dirname(__DIR__, 2) . '/app/Views/trip_movement_checklists/show.php');
        $history = file_get_contents(dirname(__DIR__, 2) . '/app/Views/trip_movement_checklists/history.php');
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertIsString($registry);
        $this->assertStringContainsString('/operations/vehicles/<?= (int) $vehicle[\'id\'] ?>/trip-history', $registry);
        $this->assertStringContainsString('app-frame import-frame', $movement);
        $this->assertStringContainsString('command-main import-main movement-main', $movement);
        $this->assertStringContainsString('command-main import-main movement-main', $history);
        $this->assertStringContainsString('max-width: 1440px', $css);
        $this->assertStringContainsString('justify-self: center', $css);
    }

    /** @param array<string, mixed> $currentLocation */
    private function renderLocation(array $currentLocation): string
    {
        return CoreServices::renderer()->setData([
            'vehicle' => ['id' => 10],
            'currentLocation' => array_merge(['operational_state' => 'parked'], $currentLocation),
            'currentReadiness' => null,
            'currentMovementHref' => null,
            'hnlGarages' => (new HnlGarageCatalog())->definitions(),
            'currentStateNotice' => null,
            'currentStateError' => null,
            'currentPositionData' => [],
            'currentReadinessData' => [],
        ])->render('fleet_vehicles/components/current_operations');
    }
}
