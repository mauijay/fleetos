<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalQueueViewTest extends CIUnitTestCase
{
    public function testQueueRendersCanonicalActionsAndNonActionableClearCard(): void
    {
        $html = html_entity_decode(CoreServices::renderer()->setData(['queueView' => [
            'label' => 'All operational work',
            'scopes' => [
                ['code' => 'all', 'label' => 'All', 'count' => null, 'href' => '/#operational-queue', 'active' => true, 'actionable' => true],
                ['code' => 'today', 'label' => 'Today', 'count' => 2, 'href' => '/?queue=today#operational-queue', 'active' => false, 'actionable' => true],
                ['code' => 'urgent', 'label' => 'Urgent', 'count' => 0, 'href' => '/?queue=urgent#operational-queue', 'active' => false, 'actionable' => false],
            ],
            'items' => [
                ['label' => 'Complete Movement Readiness', 'detail' => '2 items', 'href' => '/?movement=readiness#movement-board', 'actionable' => true],
                ['label' => 'Review Import Issues', 'detail' => '3 items', 'href' => '/turo/import-issues', 'actionable' => true],
                ['label' => 'Airport Follow-up', 'detail' => '1 item', 'href' => '/operations/airport/reimbursements?filter=action', 'actionable' => true],
            ],
        ]])->render('fleet_command_center/components/operational_queue'), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('href="/?movement=readiness#movement-board"', $html);
        $this->assertStringContainsString('href="/turo/import-issues"', $html);
        $this->assertStringContainsString('Airport Follow-up', $html);
        $this->assertStringContainsString('href="/operations/airport/reimbursements?filter=action"', $html);
        $this->assertStringNotContainsString('Import Turo Trips', $html);
        $this->assertStringNotContainsString('href="/?queue=urgent#operational-queue"', $html);
    }

    public function testEmptyScopedQueueRendersClearState(): void
    {
        $html = CoreServices::renderer()->setData(['queueView' => [
            'label' => 'Urgent work',
            'scopes' => [],
            'items' => [],
        ]])->render('fleet_command_center/components/operational_queue');

        $this->assertStringContainsString('No work is currently in this queue.', $html);
    }
}
