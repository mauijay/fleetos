<?php

use App\Services\Fleet\LocationClassificationService;
use App\Services\Fleet\PlanningHorizonService;
use Config\MovementIntelligence;
use PHPUnit\Framework\TestCase;

/** @internal */
final class MovementIntelligenceFoundationTest extends TestCase
{
    public function testStructuredHnlEvidenceTakesPrecedence(): void
    {
        $result = (new LocationClassificationService())->classify('Terminal 2', 'hnl', 7, 91);

        $this->assertSame('airport_hnl', $result['location_class']);
        $this->assertSame('structured_airport', $result['classification_source']);
        $this->assertSame('classified', $result['classification_status']);
        $this->assertSame(7, $result['airport_id']);
        $this->assertSame(91, $result['airport_movement_workflow_id']);
    }

    public function testOnlyExactConfiguredAliasesAreClassified(): void
    {
        $config = new MovementIntelligence();
        $config->locationAliases = ['fleet yard' => 'home'];
        $classifier = new LocationClassificationService($config);

        $this->assertSame('home', $classifier->classify('  FLEET   YARD ')['location_class']);
        $ambiguous = $classifier->classify('Meet near the fleet yard after checkout');
        $this->assertSame('unknown', $ambiguous['location_class']);
        $this->assertSame('pending', $ambiguous['classification_status']);
    }

    public function testPlanningHorizonUsesConfiguredBoundaryPolicy(): void
    {
        $config = new MovementIntelligence();
        $config->immediateHours = 24;
        $config->nearTermHours = 72;
        $config->mediumTermHours = 168;
        $service = new PlanningHorizonService($config);
        $asOf = new DateTimeImmutable('2026-09-01 00:00:00');

        $this->assertSame('immediate', $service->classify($asOf->modify('+23 hours'), $asOf));
        $this->assertSame('near_term', $service->classify($asOf->modify('+24 hours'), $asOf));
        $this->assertSame('medium_term', $service->classify($asOf->modify('+72 hours'), $asOf));
        $this->assertSame('medium_term', $service->classify($asOf->modify('+168 hours'), $asOf));
        $this->assertSame('distant', $service->classify($asOf->modify('+169 hours'), $asOf));
        $this->assertSame('none', $service->classify(null, $asOf));
    }
}
