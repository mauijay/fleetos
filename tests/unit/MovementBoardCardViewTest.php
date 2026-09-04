<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class MovementBoardCardViewTest extends CIUnitTestCase
{
    public function testCardRendersPlannedFactsFutureTripRecommendationFreshnessAndOneAction(): void
    {
        $html = $this->render($this->vehicle());

        $this->assertStringContainsString('movement-card movement-card--structured tone-info', $html);
        $this->assertStringContainsString('movement-card__facts', $html);
        $this->assertStringContainsString('Currently Rented', $html);
        $this->assertStringContainsString('On trip; due Sep 5, 5:00 PM.', $html);
        $this->assertStringNotContainsString('Starting at', $html);
        $this->assertStringContainsString('<dt>Planned return</dt>', $html);
        $this->assertStringContainsString('<strong>Airport HNL</strong>', $html);
        $this->assertStringContainsString('<span>Terminal 2 garage</span>', $html);
        $this->assertStringContainsString('<dt>Next confirmed trip</dt>', $html);
        $this->assertStringContainsString('Sep 6, 8:00 AM', $html);
        $this->assertStringNotContainsString('None today', $html);
        $this->assertStringContainsString('<dt>Condition</dt>', $html);
        $this->assertStringContainsString('<dt>Charge</dt>', $html);
        $this->assertStringContainsString('Recommended: Leave at HNL', $html);
        $this->assertStringContainsString('FleetOS recommendation', $html);
        $this->assertStringContainsString('Clean and charge on site.', $html);
        $this->assertStringContainsString('Stale - needs review', $html);
        $this->assertStringContainsString('Set by jlamping', $html);
        $this->assertStringContainsString('Review positioning plan', $html);
        $this->assertStringContainsString('href="&#x2F;fleet&#x2F;vehicles&#x2F;9&#x2F;positioning-plan"', $html);
        $this->assertStringNotContainsString('Active operator plan', $html);
        $this->assertStringContainsString('Turo data: 9h old', $html);
        $this->assertStringContainsString('Refresh Turo data', $html);
        $this->assertStringContainsString('href="/turo/imports"', $html);
        $this->assertStringContainsString('href="&#x2F;operations&#x2F;checklists&#x2F;41"', $html);
        $this->assertSame(1, substr_count($html, 'movement-card__action'));
        $this->assertSame(1, substr_count($html, '<li>Critical checklist items open</li>'));
        $this->assertStringNotContainsString('4 required checklist items', $html);
    }

    public function testCardKeepsActualLocationSemanticsAndNoUpcomingTripExplicit(): void
    {
        $vehicle = $this->vehicle();
        $vehicle['state'] = ['tone' => 'warning', 'label' => 'Return assessment needed'];
        $vehicle['primary_line'] = 'Vehicle returned; complete the return assessment.';
        $vehicle['location_heading'] = 'Current location';
        $vehicle['location_class_label'] = 'Home';
        $vehicle['location_detail'] = 'Fleet yard';
        $vehicle['next_trip'] = null;
        $vehicle['operator_plan'] = null;
        $vehicle['freshness'] = ['is_stale' => false, 'age_label' => '1h old', 'warning' => null];

        $html = $this->render($vehicle);

        $this->assertStringContainsString('<dt>Current location</dt>', $html);
        $this->assertStringContainsString('<strong>Home</strong>', $html);
        $this->assertStringContainsString('No upcoming trip', $html);
        $this->assertStringNotContainsString('Refresh Turo data', $html);

        $vehicle['location_heading'] = 'Last known location';
        $this->assertStringContainsString('<dt>Last known location</dt>', $this->render($vehicle));
    }

    /** @param array<string, mixed> $vehicle */
    private function render(array $vehicle): string
    {
        return CoreServices::renderer()->setData(['vehicle' => $vehicle])->render('fleet_command_center/components/movement_card');
    }

    /** @return array<string, mixed> */
    private function vehicle(): array
    {
        return [
            'fleet_code' => 'EV-09',
            'model' => '2026 Tesla Model Y',
            'state' => ['tone' => 'info', 'label' => 'Currently Rented'],
            'primary_line' => 'On trip; due Sep 5, 5:00 PM.',
            'location_heading' => 'Planned return',
            'location_class_label' => 'Airport HNL',
            'location_detail' => 'Terminal 2 garage',
            'next_trip' => ['starts_at_label' => 'Sep 6, 8:00 AM', 'pickup_location_label' => 'Airport HNL', 'guest_name' => 'Guest Nine'],
            'condition_label' => 'Dirty',
            'energy_label' => 'Charge',
            'energy_value' => '24%',
            'blockers' => [['code' => 'critical_checklist_items', 'label' => 'Critical checklist items open', 'severity' => 'critical']],
            'recommendation' => ['display_label' => 'Recommended: Leave at HNL', 'reason_labels' => ['Already at HNL.', 'Clean and charge on site.']],
            'operator_plan' => ['label' => 'Hold at home', 'status_label' => 'Stale - needs review', 'is_basis_stale' => true, 'note' => 'Prior shift plan', 'actor_label' => 'jlamping', 'created_at_label' => 'Sep 3, 11:45 AM'],
            'positioning_plan_href' => '/fleet/vehicles/9/positioning-plan',
            'freshness' => ['is_stale' => true, 'age_label' => '9h old', 'warning' => 'Refresh Turo data before finalizing.'],
            'action' => ['code' => 'monitor_return', 'label' => 'Record return', 'href' => '/operations/checklists/41'],
        ];
    }
}
