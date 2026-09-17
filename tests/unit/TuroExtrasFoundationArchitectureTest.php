<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TuroExtrasFoundationArchitectureTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testRoutesAreSessionAdminProtectedAndPostsUseGlobalCsrf(): void
    {
        $routes = $this->read('app/Config/Routes.php');
        $filters = $this->read('app/Config/Filters.php');

        $this->assertMatchesRegularExpression('/group\(\'\', \[\'filter\' => \'session\'\].*?group\(\'turo\/extras\', \[\'filter\' => \'permission:admin\.access\'\]/s', $routes);
        $this->assertMatchesRegularExpression('/public array \$globals = \[.*?\'before\' => \[.*?\'csrf\'.*?\]/s', $filters);
        foreach (["post('import'", "post('catalog'", "post('catalog/(:num)'", "post('mappings'", "post('mappings/create-extra'"] as $postRoute) {
            $this->assertStringContainsString($postRoute, $routes);
        }
    }

    public function testViewProvidesFoundationWorkflowWithoutPerformanceReport(): void
    {
        $view = $this->read('app/Views/turo_extras/index.php');
        $css = $this->read('resources/css/app.css');

        foreach (['Export reservation Extras', 'Import reservation Extras', 'Unmapped Turo Extras', 'Canonical Extras', 'Turo source mappings'] as $label) {
            $this->assertStringContainsString($label, $view);
        }
        $this->assertStringContainsString("\$workspace['catalog'] === [] ? ' is-empty' : ''", $view);
        $this->assertStringContainsString('.extras-catalog-grid:not(.is-empty)', $css);
        $this->assertStringContainsString('margin-block-start: 1rem;', $css);
        $this->assertMatchesRegularExpression('/\.extras-catalog-grid > \.mapping-card \{.*?min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extras-catalog-grid \.mapping-card-main \{.*?display: flex;.*?flex-wrap: wrap;/s', $css);
        $this->assertMatchesRegularExpression('/\.extras-catalog-grid \.mapping-card-main > :first-child \{.*?flex: 1 1 10rem;.*?min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extras-catalog-grid \.status-badge \{.*?flex: 0 1 auto;.*?max-width: 100%;.*?white-space: normal;/s', $css);
        $this->assertStringContainsString('data-copy-target="extras-reservation-ids"', $view);
        $this->assertStringContainsString('csrf_field()', $view);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit', $css);
        $this->assertStringContainsString('@media (max-width: 700px)', $css);
        $this->assertMatchesRegularExpression('/\.extras-unmapped-grid \{[^}]*min-width: 0;[^}]*max-width: 100%;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-card \{[^}]*min-width: 0;[^}]*max-width: 100%;[^}]*overflow-wrap: anywhere;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-card \.mapping-card-main \{[^}]*display: flex;[^}]*flex-wrap: wrap;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-card \.count-pill \{[^}]*max-width: 100%;[^}]*white-space: normal;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-facts \{[^}]*min-width: 0;[^}]*max-width: 100%;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-map-form \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-map-form > label \{[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-map-form select \{[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-map-form > button \{[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-card > \.secondary-disclosure > summary \{[^}]*max-width: 100%;[^}]*overflow-wrap: anywhere;/s', $css);
        $this->assertMatchesRegularExpression('/\.extra-source-card \.extra-catalog-form \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*width: 100%;/s', $css);
        $this->assertStringNotContainsString('Revenue ranking', $view);
        $this->assertStringNotContainsString('CSV export', $view);
    }

    public function testSelectionsResolveCanonicalIdentityDynamicallyAndFinancialServicesAreUntouched(): void
    {
        $migration = $this->read('app/Database/Migrations/2026-09-13-000021_CreateFleetExtrasFoundation.php');
        $repository = $this->read('app/Repositories/FleetExtraRepository.php');
        $importer = $this->read('app/Services/Turo/TuroExtrasImportService.php');

        $selectionFields = substr($migration, (int) strpos($migration, 'private function createSelectionsTable'));
        $this->assertStringNotContainsString("'fleet_extra_id'", $selectionFields);
        $this->assertStringContainsString('mappings.source_extra_id = selections.source_extra_id', $repository);
        foreach (['FinancialActivityReadService.php', 'FinancialSummaryService.php', 'VehicleFinancialSummaryService.php'] as $financialService) {
            $this->assertStringNotContainsString($financialService, $importer);
        }
    }

    public function testExporterAndImportContractContainNoCredentialOrGuestFields(): void
    {
        $exporter = $this->read('tools/turo-extras-exporter.js');
        $validator = $this->read('app/Validation/Turo/TuroExtrasPayloadValidator.php');

        $this->assertStringContainsString('{ credentials: "same-origin" }', $exporter);
        $this->assertStringNotContainsString('document.cookie', $exporter);
        $this->assertStringNotContainsString('localStorage', $exporter);
        $this->assertStringNotContainsString('guest_email', $exporter);
        $this->assertStringNotContainsString('guest_phone', $exporter);
        $this->assertStringContainsString('rejectUnknownKeys', $validator);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}
