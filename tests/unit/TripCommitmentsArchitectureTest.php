<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TripCommitmentsArchitectureTest extends CIUnitTestCase
{
    public function testRoutesAreExplicitAdminProtectedAndMutationsUsePost(): void
    {
        $routes = $this->source('app/Config/Routes.php');
        $this->assertStringContainsString("get('operations/trips/(:num)/commitments', 'TripCommitments::index/$1'", $routes);
        foreach (['commitments', 'edit', 'acknowledge', 'complete', 'cancel'] as $fragment) {
            $this->assertStringContainsString($fragment, $routes);
        }
        $this->assertGreaterThanOrEqual(6, substr_count($routes, "permission:admin.access']"));
        $this->assertStringNotContainsString("get('operations/trips/(:num)/commitments/(:num)/", $routes);
    }

    public function testCanonicalAndWorkflowViewsEscapeOutputAndUseParentTripRoutes(): void
    {
        $canonical = $this->source('app/Views/trip_commitments/index.php');
        $workflow = $this->source('app/Views/trip_movement_checklists/_guest_commitments.php');
        $movementCard = $this->source('app/Views/fleet_command_center/components/movement_card.php');

        $this->assertStringContainsString('Guest Commitments', $canonical);
        $this->assertStringContainsString('Turo reservation facts remain unchanged', $canonical);
        $this->assertStringContainsString('csrf_field()', $canonical);
        $this->assertStringContainsString('esc((string) $commitment[\'instruction\'])', $canonical);
        $this->assertStringContainsString('/operations/trips/', $workflow);
        $this->assertStringContainsString('Review all guest commitments', $workflow);
        $this->assertStringContainsString('Special instructions', $movementCard);
        foreach ([$canonical, $workflow, $movementCard] as $surface) {
            $this->assertStringContainsString('trip_commitments/components/energy_override_context', $surface);
        }
        $this->assertStringNotContainsString('company_id', $canonical);
    }

    public function testResponsiveStylesContainCardsFormsAndMovementPreview(): void
    {
        $css = $this->source('resources/css/app.css');
        $this->assertStringContainsString('.guest-commitment-form', $css);
        $this->assertStringContainsString('.movement-card__guest-commitments', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr))', $css);
        $this->assertStringContainsString('min-width: 0', $css);
        $this->assertStringContainsString('@media (max-width: 560px)', $css);
    }

    public function testCommitmentsRemainOutsideExtrasAndFinancialReadPaths(): void
    {
        foreach ([
            'app/Services/Fleet/FinancialActivityReadService.php',
            'app/Services/Fleet/FinancialSummaryService.php',
            'app/Services/Fleet/VehicleFinancialSummaryService.php',
            'app/Services/Fleet/FleetExtraService.php',
            'app/Services/Turo/TuroExtrasImportService.php',
        ] as $path) {
            $this->assertStringNotContainsString('fleet_trip_commitments', $this->source($path), $path);
            $this->assertStringNotContainsString('TripCommitment', $this->source($path), $path);
        }
    }

    public function testCanceledTripApplicabilityIsSharedByReadinessQueueBoardAndEnergyConsumers(): void
    {
        $service = $this->source('app/Services/Fleet/TripCommitmentService.php');
        $readiness = $this->source('app/Services/Fleet/MovementReadinessReadService.php');
        $board = $this->source('app/Services/Fleet/MovementBoardIntelligenceService.php');
        $energy = $this->source('app/Services/Fleet/TripEnergyRuleResolver.php');

        $this->assertStringContainsString('if (! $this->tripIsOperational($trip))', $service);
        $this->assertStringContainsString("'active' => \$tripIsOperational ? \$activeCommitments : []", $service);
        $this->assertStringContainsString("'preserved' => \$tripIsOperational ? [] : \$activeCommitments", $service);
        $this->assertStringContainsString('activeForTrip($companyId, $tripId, $phases)', $readiness);
        $this->assertStringContainsString('activeForTrip($companyId, $commitmentTripId, $commitmentPhases', $board);
        $this->assertStringContainsString('tripIsOperational($trip)', $energy);
    }

    public function testCanceledMovementWorkflowUsesHistoricalViewAndSharedMutationGuard(): void
    {
        $controller = $this->source('app/Controllers/TripMovementChecklists.php');
        $view = $this->source('app/Views/trip_movement_checklists/show.php');
        $inactive = $this->source('app/Views/trip_movement_checklists/_inactive.php');
        $position = $this->source('app/Views/trip_movement_checklists/_position.php');

        $this->assertStringContainsString("'tripIsOperational' => \$tripIsOperational", $controller);
        $this->assertGreaterThanOrEqual(16, substr_count($controller, 'inactiveMovementRedirect('));
        $this->assertStringContainsString('if (! $tripIsOperational)', $view);
        $this->assertStringContainsString("'readOnly' => ! \$tripIsOperational", $view);
        $this->assertStringContainsString('Trip canceled', $inactive);
        $this->assertStringContainsString('No operational work is required.', $inactive);
        $this->assertStringContainsString('Checklist history', $inactive);
        $this->assertStringContainsString('Review preserved Guest Commitments', $inactive);
        $this->assertStringContainsString('if (! $readOnly && ! $isRented', $position);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path);
        $this->assertNotFalse($source, $path);

        return $source;
    }
}
