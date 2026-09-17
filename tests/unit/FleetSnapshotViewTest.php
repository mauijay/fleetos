<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FleetSnapshotViewTest extends CIUnitTestCase
{
    public function testSnapshotRendersOnlyNonEmptyCurrentGroupsAndUniqueVehicleLinks(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData(['activity' => $this->activity()])->render('fleet_command_center/components/activity_panel'), ENT_QUOTES | ENT_HTML5);

        $this->assertLessThan(strpos($html, 'Operations Queue'), strpos($html, 'Fleet Snapshot'));
        $this->assertStringNotContainsString('10 vehicles', $html);
        $this->assertStringNotContainsString('fleet-snapshot__count', $html);
        $this->assertStringContainsString('Rented', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('HNL', $html);
        $this->assertStringNotContainsString('Other', $html);
        $this->assertStringNotContainsString('Unknown', $html);
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $number) {
            $this->assertSame(1, substr_count($html, 'aria-label="Open vehicle ' . $number . '"'));
        }
    }

    public function testResponsiveStylesExposeCompactSnapshotAtMobileAndDesktopWidths(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('.fleet-snapshot__buckets .rail-dataset', $css);
        $this->assertStringContainsString('grid-template-columns: max-content minmax(0, 1fr)', $css);
        $this->assertMatchesRegularExpression('/\.fleet-snapshot__label\s*\{[^}]*white-space: nowrap;[^}]*overflow-wrap: normal;/s', $css);
        $this->assertMatchesRegularExpression('/\.fleet-snapshot__vehicles\s*\{[^}]*min-width: 0;[^}]*overflow-wrap: anywhere;/s', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('grid-template-areas:', $css);
        $this->assertStringContainsString('grid-area: snapshot', $css);
        $this->assertStringContainsString('.activity-panel > .panel-card + .panel-card', $css);
        $this->assertDoesNotMatchRegularExpression('/\.activity-panel \.future-signals\s*\{[^}]*display:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.activity-panel \.rail-dataset\s*\{[^}]*border: 1px solid var\(--line\);[^}]*border-radius: var\(--radius\);[^}]*background: var\(--surface-2\);/s', $css);
        $this->assertMatchesRegularExpression('/\.activity-list__link,\s*\.activity-list__clear\s*\{[^}]*min-height: 44px;/s', $css);
    }

    public function testDailyCountsShowOnlyOperationalMetricsAndRetainLiveFleetStatus(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/fleet_command_center/index.php');

        $this->assertIsString($view);
        $this->assertSame(1, preg_match('/<section class="section" id="operations-status".*?<\/section>/s', $view, $match));
        $dailyCounts = $match[0];
        foreach (['going_out_today', 'returning_today', 'same_day_turnarounds', 'cleaning_needed', 'charging_needed', 'maintenance_attention', 'utilization_percent'] as $code) {
            $this->assertStringContainsString("'" . $code . "' =>", $dailyCounts);
        }
        foreach (['fleet_size', 'currently_rented', 'home', 'hnl', 'other_location', 'unknown_location', 'available_now', 'offline_or_unavailable'] as $code) {
            $this->assertStringNotContainsString("'" . $code . "' =>", $dailyCounts);
        }
        $this->assertStringContainsString("'utilization_percent' => ['label' => 'Month-to-date utilization', 'period' => 'Current month']", $dailyCounts);
        $this->assertStringContainsString("\$commandCenter['daily_operations']['fleet_status'][\$code]", $dailyCounts);
        $this->assertStringContainsString('id="fleet-status"', $view);
        $this->assertStringContainsString("foreach (\$commandCenter['fleet_status'] as \$card)", $view);
    }

    public function testRightRailUsesMatchingHeadingsAndSharedRowsWithoutChangingQueueSemantics(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData(['activity' => $this->activity()])->render('fleet_command_center/components/activity_panel'), ENT_QUOTES | ENT_HTML5);

        $this->assertSame(3, substr_count($html, 'class="rail-heading"'));
        $this->assertSame(3, substr_count($html, 'class="eyebrow"'));
        $this->assertSame(3, substr_count($html, 'class="rail-heading__context"'));
        $this->assertSame(9, substr_count($html, 'class="rail-dataset"'));
        $this->assertStringContainsString('<section class="panel-card future-signals" aria-labelledby="external-context-heading">', $html);
        $this->assertStringContainsString('href="/?queue=today#operational-queue"', $html);
        $this->assertStringContainsString('href="/?queue=tomorrow#operational-queue"', $html);
        $this->assertStringNotContainsString('href="/?queue=urgent#operational-queue"', $html);
        $this->assertStringContainsString('Weather alerts <span>Reserved</span>', $html);
    }

    public function testPositiveActivityScopesLinkToQueueAndZeroScopeIsClear(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData(['activity' => $this->activity()])->render('fleet_command_center/components/activity_panel'), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('href="/?queue=today#operational-queue"', $html);
        $this->assertStringContainsString('aria-label="Open Today operations queue, 2 items"', $html);
        $this->assertStringContainsString('href="/?queue=tomorrow#operational-queue"', $html);
        $this->assertStringNotContainsString('href="/?queue=urgent#operational-queue"', $html);
        $this->assertMatchesRegularExpression('/<span class="activity-list__clear">\s*<span>Urgent<\/span>\s*<strong>0<\/strong>\s*<small>Clear<\/small>/', $html);
    }

    /** @return array<string, mixed> */
    private function activity(): array
    {
        $vehicle = static fn (int $number): array => ['id' => $number, 'label' => (string) $number, 'href' => '/fleet/vehicles/' . $number];

        return [
            'fleet_snapshot' => [
                'total' => 10,
                'buckets' => [
                    ['code' => 'rented', 'label' => 'Rented', 'count' => 4, 'vehicles' => array_map($vehicle, [2, 3, 8, 11])],
                    ['code' => 'home', 'label' => 'Home', 'count' => 4, 'vehicles' => array_map($vehicle, [5, 6, 7, 9])],
                    ['code' => 'hnl', 'label' => 'HNL', 'count' => 2, 'vehicles' => array_map($vehicle, [4, 10])],
                    ['code' => 'other', 'label' => 'Other', 'count' => 0, 'vehicles' => []],
                    ['code' => 'unknown', 'label' => 'Unknown', 'count' => 0, 'vehicles' => []],
                ],
            ],
            'today_count' => 2,
            'tomorrow_count' => 1,
            'urgent_count' => 0,
            'queue_scopes' => [
                ['code' => 'all', 'label' => 'All', 'count' => null, 'href' => '/#operational-queue', 'active' => true, 'actionable' => true],
                ['code' => 'today', 'label' => 'Today', 'count' => 2, 'href' => '/?queue=today#operational-queue', 'active' => false, 'actionable' => true],
                ['code' => 'tomorrow', 'label' => 'Tomorrow', 'count' => 1, 'href' => '/?queue=tomorrow#operational-queue', 'active' => false, 'actionable' => true],
                ['code' => 'urgent', 'label' => 'Urgent', 'count' => 0, 'href' => '/?queue=urgent#operational-queue', 'active' => false, 'actionable' => false],
            ],
            'weather_status' => 'Reserved',
            'traffic_status' => 'Reserved',
            'battery_status' => 'Reserved',
        ];
    }
}
