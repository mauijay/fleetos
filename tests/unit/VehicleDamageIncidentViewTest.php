<?php

use App\Services\Fleet\VehicleDamageIncidentService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleDamageIncidentViewTest extends CIUnitTestCase
{
    public function testRepeatableIncidentFormPreservesErrorsAndEscapesSyntheticNotes(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData($this->data() + [
            'formData' => ['discovered_at' => '2026-10-05T09:30', 'areas' => [
                ['panel_code' => 'front_bumper', 'note' => '<script>synthetic</script>'],
                ['panel_code' => 'hood', 'effect_code' => 'observed_existing'],
            ]],
        ])->render('vehicle_damage_incidents/_form'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('name="areas[0][panel_code]"', $html);
        $this->assertStringContainsString('name="areas[1][panel_code]"', $html);
        $this->assertStringContainsString('Add another area', $html);
        $this->assertStringNotContainsString('<script>synthetic</script>', CoreServices::renderer()->setData($this->data() + ['formData' => ['areas' => [['note' => '<script>synthetic</script>']]]])->render('vehicle_damage_incidents/_form'));
        $this->assertStringContainsString('cause not established', $html);
        $this->assertStringContainsString('name="' . CoreServices::security()->getTokenName() . '"', $html);
        $this->assertStringNotContainsString('name="damage_claim_id"', $html);
        $this->assertStringNotContainsString('payment', $html);
    }

    public function testHistoricalPreviewShowsBothRecordsCountsAndExplicitConfirmation(): void
    {
        $source = $this->record(2);
        $target = $this->record(1);
        $html = CoreServices::renderer()->setData($this->data() + ['source' => $source, 'target' => $target, 'sourceEvidenceCount' => 2, 'targetEvidenceCount' => 1])->render('vehicle_damage_incidents/link_preview');
        $this->assertStringContainsString('Source / historical item #2', $html);
        $this->assertStringContainsString('Canonical target item #1', $html);
        $this->assertStringContainsString('2 evidence references', $html);
        $this->assertStringContainsString('1 evidence references', $html);
        $this->assertStringContainsString('2 → 1', $html);
        $this->assertStringContainsString('name="confirmed" value="1" required', $html);
        $this->assertStringContainsString(VehicleDamageIncidentService::fingerprint($source), $html);
        $this->assertStringContainsString('name="reason" required', $html);
    }

    public function testIncidentDetailAndHistoricalParentDoNotExposePrivatePaths(): void
    {
        $incident = ['id' => 1, 'discovered_at' => '2026-10-05 09:30:00', 'occurred_at' => null, 'turo_trip_normalized_id' => null, 'turo_reservation_id' => null, 'attribution_type' => 'unknown', 'overall_note' => 'Synthetic discovery', 'created_by' => 7, 'created_at' => '2026-10-05 09:30:00'];
        $html = CoreServices::renderer()->setData($this->data() + ['incident' => $incident, 'memberships' => [['vehicle_damage_item_id' => 2, 'panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'moderate', 'effect_code' => 'worsened', 'note' => 'Synthetic worsening', 'created_by' => 7, 'created_at' => '2026-10-05 09:30:00', 'current_condition_item_id' => 1]]])->render('vehicle_damage_incidents/show');
        $this->assertStringContainsString('Historical provenance for canonical condition #1', $html);
        $this->assertStringContainsString('/fleet/vehicles/10/damage/2', $html);
        $this->assertStringContainsString('name="expected_state"', $html);
        $itemHtml = CoreServices::renderer()->setData($this->data() + ['item' => $this->record(2), 'events' => [], 'evidence' => [['label' => 'Synthetic evidence', 'external_reference' => null, 'file_path' => 'private/synthetic-secret.pdf']]])->render('vehicle_damage_incidents/item');
        $this->assertStringContainsString('Synthetic evidence', $itemHtml);
        $this->assertStringNotContainsString('private/synthetic-secret.pdf', $itemHtml);
    }

    public function testNewRoutesRemainUnderSessionAdminPermissionAndGlobalCsrf(): void
    {
        $routes = CoreServices::routes(false);
        $routes->loadRoutes();
        foreach (['fleet/vehicles/([0-9]+)/damage-incidents', 'fleet/vehicles/([0-9]+)/damage/([0-9]+)/link', 'fleet/vehicles/([0-9]+)/damage/([0-9]+)/evidence'] as $path) {
            $filters = $routes->getFiltersForRoute($path, 'POST');
            $this->assertContains('session', $filters);
            $this->assertContains('permission:admin.access', $filters);
        }
        $this->assertContains('csrf', (new \Config\Filters())->globals['before']);
    }

    public function testInvalidHistoricalPreviewCannotPresentConfirmation(): void
    {
        foreach ([[$this->record(1), $this->record(1)], [$this->record(2), array_replace($this->record(1), ['status_code' => 'resolved_other'])], [array_replace($this->record(2), ['current_condition_item_id' => 1]), $this->record(1)]] as [$source, $target]) {
            $html = CoreServices::renderer()->setData($this->data() + ['source' => $source, 'target' => $target, 'sourceEvidenceCount' => 0, 'targetEvidenceCount' => 0])->render('vehicle_damage_incidents/link_preview');
            $this->assertStringContainsString('This relationship cannot be confirmed.', $html);
            $this->assertStringNotContainsString('name="confirmed"', $html);
        }
    }

    public function testFailedPostCreateActionsRetainEscapedOperatorInput(): void
    {
        $source = $this->record(2);
        $html = CoreServices::renderer()->setData($this->data() + ['source' => $source, 'target' => $this->record(1), 'sourceEvidenceCount' => 0, 'targetEvidenceCount' => 0, 'formData' => ['reason' => '<synthetic reason>']])->render('vehicle_damage_incidents/link_preview');
        $this->assertStringContainsString('&lt;synthetic reason&gt;', $html);
        $itemHtml = CoreServices::renderer()->setData($this->data() + ['item' => $source, 'events' => [], 'evidence' => [], 'formData' => ['external_reference' => '<synthetic evidence>', 'evidence_label' => 'Synthetic retained label', 'file_id' => 800]])->render('vehicle_damage_incidents/item');
        $this->assertStringContainsString('Synthetic retained label', html_entity_decode($itemHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('value="800"', $itemHtml);
        $this->assertStringNotContainsString('value="<synthetic evidence>"', $itemHtml);
        $areaHtml = CoreServices::renderer()->setData($this->data() + ['area' => ['note' => 'Synthetic retained area', 'panel_code' => 'hood'], 'prefix' => 'area'])->render('vehicle_damage_incidents/_areas');
        $this->assertStringContainsString('Synthetic retained area', $areaHtml);
        $this->assertStringContainsString('value="hood" selected', $areaHtml);
    }

    private function data(): array
    {
        return ['vehicle' => ['id' => 10, 'fleet_code' => 'SYNTHETIC-10'], 'vehicleDamage' => ['current' => [
            $this->record(1) + ['zone_label' => 'Front', 'severity_label' => 'Cosmetic'],
            $this->record(2) + ['zone_label' => 'Front', 'severity_label' => 'Cosmetic'],
        ]], 'trips' => [['id' => 100, 'turo_reservation_id' => 'SYNTHETIC-TRIP']]];
    }

    private function record(int $id): array
    {
        return ['id' => $id, 'company_id' => 1, 'fleet_vehicle_id' => 10, 'panel_code' => 'front_bumper', 'zone_code' => 'front', 'description' => 'Synthetic scrape', 'severity_code' => 'cosmetic', 'status_code' => 'open', 'damage_type_code' => 'scratch_scuff', 'discovered_at' => '2026-10-05 09:30:00', 'turo_reservation_id' => 'SYNTHETIC-TRIP', 'current_condition_item_id' => null];
    }
}
