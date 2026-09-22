<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class ExtraFulfillmentArchitectureTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testFulfillmentUsesExactSelectionCompanyScopeAndAdminPostRoutes(): void
    {
        $migration = $this->read('app/Database/Migrations/2026-09-21-000024_CreateExtraFulfillment.php');
        $repository = $this->read('app/Repositories/TripExtraFulfillmentRepository.php');
        $routes = $this->read('app/Config/Routes.php');

        $this->assertStringContainsString("addUniqueKey(['company_id', 'turo_extra_selection_id']", $migration);
        $this->assertStringContainsString('mappings.source_extra_id = selections.source_extra_id', $repository);
        $this->assertStringContainsString("where('selections.company_id', \$companyId)", $repository);
        $this->assertMatchesRegularExpression("/post\('operations\/trips\/\(:num\)\/extra-fulfillments\/\(:num\)\/complete'.*permission:admin\.access/", $routes);
        $this->assertStringNotContainsString("get('operations/trips/(:num)/extra-fulfillments", $routes);
    }

    public function testCatalogAndWorkflowExposeConfigurationAndOneTripPreparationAction(): void
    {
        $catalog = $this->read('app/Views/turo_extras/_fulfillment_fields.php');
        $workflow = $this->read('app/Views/trip_movement_checklists/_trip_preparation.php');
        $readiness = $this->read('app/Views/trip_movement_checklists/_readiness.php');
        $css = $this->read('resources/css/app.css');

        foreach (['fulfillment_type', 'requires_operator_confirmation', 'readiness_blocking', 'default_action_label', 'fulfillment_phase'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $catalog);
        }
        $this->assertStringContainsString('Trip Preparation', $workflow);
        $this->assertStringContainsString('Confirm fulfilled', $workflow);
        $this->assertStringContainsString("'extra_fulfillment_'", $readiness);
        $this->assertStringContainsString('.trip-preparation-item', $css);
        $this->assertStringContainsString('@media (max-width: 44rem)', $css);
    }

    public function testFinancialFirewallRemainsStructural(): void
    {
        foreach (['app/Services/Fleet/FinancialActivityReadService.php', 'app/Services/Fleet/FinancialSummaryService.php', 'app/Services/Fleet/VehicleFinancialSummaryService.php'] as $path) {
            $source = $this->read($path);
            $this->assertStringNotContainsString('trip_extra_fulfillments', $source);
            $this->assertStringNotContainsString('turo_extra_selections', $source);
        }
    }

    public function testRemovedFulfillmentRendersHistoryWithoutOperationalAction(): void
    {
        $html = Services::renderer()->setData([
            'checklist' => ['id' => 668],
            'extraPreparation' => [
                [
                    'fulfillment_id' => 8,
                    'turo_trip_normalized_id' => 333,
                    'title' => 'Removed Beach Kit ×1',
                    'is_removed' => true,
                    'is_actionable' => false,
                    'is_completed' => true,
                    'is_informational' => false,
                    'removed_at' => '2026-09-21 12:30:00',
                    'completed_at' => '2026-09-21 12:15:00',
                    'completed_by_user_id' => 9,
                    'completion_note' => 'Packed before removal',
                ],
                [
                    'fulfillment_id' => 9,
                    'turo_trip_normalized_id' => 333,
                    'title' => 'Removed Pending Kit ×1',
                    'is_removed' => true,
                    'is_actionable' => false,
                    'is_completed' => false,
                    'is_informational' => false,
                    'removed_at' => '2026-09-21 12:35:00',
                    'completed_at' => null,
                    'completed_by_user_id' => null,
                    'completion_note' => null,
                ],
            ],
        ])->render('trip_movement_checklists/_trip_preparation');

        $this->assertStringContainsString('Trip Preparation', $html);
        $this->assertStringContainsString('0 actions', $html);
        $this->assertStringContainsString('Fulfillment history and context · 2', $html);
        $this->assertStringContainsString('Previously fulfilled', $html);
        $this->assertStringContainsString('Fulfillment was pending', $html);
        $this->assertStringContainsString('Selection later removed · No action required', $html);
        $this->assertStringContainsString('Operator #9', $html);
        $this->assertStringNotContainsString('/complete', $html);
        $this->assertStringNotContainsString('/reopen', $html);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}
