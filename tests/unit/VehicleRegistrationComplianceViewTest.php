<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleRegistrationComplianceViewTest extends CIUnitTestCase
{
    public function testEditFormBindsSavedRegistrationComplianceValues(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/form.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('Ownership &amp; Registration', $source);
        $this->assertStringContainsString('Service &amp; Lifecycle', $source);
        $this->assertStringContainsString('name="registered_owner" maxlength="190" value="<?= esc((string) $value(\'registered_owner\'), \'attr\') ?>"', $source);
        $this->assertStringContainsString('name="registration_renewal_on" type="date" value="<?= esc((string) $value(\'registration_renewal_on\'), \'attr\') ?>"', $source);
        $this->assertStringContainsString('name="safety_inspection_due_on" type="date" value="<?= esc((string) $value(\'safety_inspection_due_on\'), \'attr\') ?>"', $source);
    }

    public function testWorkspaceDisplaysRegistrationComplianceValues(): void
    {
        $html = $this->renderWorkspace($this->vehicle([
            'registered_owner' => 'Jordan Lee and Casey Kim',
            'registration_renewal_on' => '2027-08-31',
            'safety_inspection_due_on' => '2027-06-30',
        ]));

        $this->assertStringContainsString('Registration &amp; Compliance', $html);
        $this->assertStringContainsString('Jordan Lee and Casey Kim', $html);
        $this->assertStringContainsString('Aug 31, 2027', $html);
        $this->assertStringContainsString('Jun 30, 2027', $html);
    }

    public function testWorkspaceDisplaysNotEnteredForMissingRegistrationComplianceValues(): void
    {
        $html = $this->renderWorkspace([]);
        $summaryStart = strpos($html, 'Registration &amp; Compliance');
        $summary = substr($html, $summaryStart === false ? 0 : $summaryStart, 600);

        $this->assertSame(3, substr_count($summary, 'Not entered'));
    }

    public function testVehicleLayoutsUseScopedResponsiveBreakpoints(): void
    {
        $form = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/form.php');
        $index = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/index.php');
        $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');

        $this->assertIsString($form);
        $this->assertIsString($index);
        $this->assertIsString($css);
        $this->assertStringContainsString('class="vehicle-form-layout"', $form);
        $this->assertStringContainsString('class="mapping-list vehicle-registry-grid"', $index);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1440px\) \{(?:(?!@media).)*\.vehicle-main \{\s*max-width: none;/s', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1440px\) \{(?:(?!@media).)*\.vehicle-form-column \.issue-filters \{\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s', $css);
        $this->assertDoesNotMatchRegularExpression('/@media \(min-width: 1(?:4\d{2}|[56]\d{2})px\) \{(?:(?!@media).)*\.(?:vehicle-registry-grid|vehicle-form-layout)[\s,{]/s', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1700px\) \{(?:(?!@media).)*\.vehicle-registry-grid,\s*\.vehicle-form-layout \{\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s', $css);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function vehicle(array $overrides = []): array
    {
        return array_merge([
            'id' => 11,
            'fleet_number' => 11,
            'fleet_code' => 'Bronco11-087',
            'display_name' => 'Bronco 11',
            'model_year' => 2026,
            'make_name' => 'Ford',
            'model_name' => 'Bronco',
            'status_name' => 'Active',
            'purchase_date' => null,
            'registered_owner' => null,
            'registration_renewal_on' => null,
            'safety_inspection_due_on' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $vehicle */
    private function renderWorkspace(array $vehicle): string
    {
        return CoreServices::renderer()->setData([
            'vehicle' => $vehicle,
            'date' => static fn (mixed $value): string => $value === null || $value === '' ? 'Not entered' : date('M j, Y', strtotime((string) $value)),
        ])->render('fleet_vehicles/components/compliance_summary');
    }
}
