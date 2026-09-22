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
        $this->assertStringContainsString("['confirmation_label']", $workflow);
        $this->assertStringNotContainsString('Confirm fulfilled', $workflow);
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

    public function testWorkflowRendersTypedConfirmationAndKeepsInstructionAsDetail(): void
    {
        $html = Services::renderer()->setData([
            'checklist' => ['id' => 668],
            'extraPreparation' => [[
                'fulfillment_id' => 8,
                'turo_trip_normalized_id' => 333,
                'title' => 'Premium Beach Gear',
                'action_label' => 'Pack the premium beach set near the rear cargo door',
                'confirmation_label' => 'Confirm packed',
                'quantity_unknown' => false,
                'linked_commitments' => [],
                'is_removed' => false,
                'is_actionable' => true,
                'is_completed' => false,
                'is_informational' => false,
            ]],
        ])->render('trip_movement_checklists/_trip_preparation');

        $this->assertStringContainsString('Pack the premium beach set near the rear cargo door', $html);
        $this->assertStringContainsString('Confirm packed', $html);
        $this->assertStringContainsString('/operations/trips/333/extra-fulfillments/8/complete', $html);
        $this->assertStringNotContainsString('Confirm fulfilled', $html);
    }

    public function testInformationalFulfillmentHasNoConfirmationControl(): void
    {
        $html = Services::renderer()->setData([
            'checklist' => ['id' => 668],
            'extraPreparation' => [[
                'fulfillment_id' => 10,
                'turo_trip_normalized_id' => 333,
                'title' => 'Informational Extra',
                'action_label' => '',
                'confirmation_label' => null,
                'quantity_unknown' => false,
                'linked_commitments' => [],
                'is_removed' => false,
                'is_actionable' => false,
                'is_completed' => false,
                'is_informational' => true,
            ]],
        ])->render('trip_movement_checklists/_trip_preparation');

        $this->assertStringContainsString('No operator confirmation required', $html);
        $this->assertStringNotContainsString('/complete', $html);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}
