<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FleetSnapshotViewTest extends CIUnitTestCase
{
    public function testSnapshotRendersBeforeOperationsQueueWithCountsAndUniqueVehicleLinks(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData(['activity' => $this->activity()])->render('fleet_command_center/components/activity_panel'), ENT_QUOTES | ENT_HTML5);

        $this->assertLessThan(strpos($html, 'Operations Queue'), strpos($html, 'Fleet Snapshot'));
        $this->assertStringContainsString('10 vehicles', $html);
        $this->assertStringContainsString('Rented', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('HNL', $html);
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $number) {
            $this->assertSame(1, substr_count($html, 'aria-label="Open vehicle ' . $number . '"'));
        }
    }

    public function testResponsiveStylesExposeCompactSnapshotAtMobileAndDesktopWidths(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/resources/css/app.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('.fleet-snapshot__buckets li', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('grid-template-areas:', $css);
        $this->assertStringContainsString('grid-area: snapshot', $css);
        $this->assertStringContainsString('.activity-panel .future-signals', $css);
        $this->assertStringContainsString('.operations-queue-summary', $css);
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
