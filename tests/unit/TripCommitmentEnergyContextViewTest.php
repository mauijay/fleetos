<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class TripCommitmentEnergyContextViewTest extends CIUnitTestCase
{
    #[DataProvider('comparisonProvider')]
    public function testEnergyOverrideShowsResolvedRuleAndNormalVehiclePolicy(string $comparison, string $summary): void
    {
        $html = $this->render($comparison, $summary, 'Normal vehicle minimum: 75%');

        $this->assertStringContainsString($summary, $html);
        $this->assertStringContainsString('Normal vehicle minimum: 75%', $html);
        $this->assertStringContainsString('Guest-specific override', $html);
    }

    public function testPreferredRangeShowsGuestAndNormalVehicleRanges(): void
    {
        $html = $this->render('preferred_range', 'Guest-preferred range: 50–60%', 'Normal vehicle range: 70–80%');

        $this->assertStringContainsString('Guest-preferred range: 50–60%', $html);
        $this->assertStringContainsString('Normal vehicle range: 70–80%', $html);
        $this->assertStringContainsString('Guest-specific override', $html);
    }

    public function testEnergyOverrideDoesNotFabricateMissingNormalTarget(): void
    {
        $html = $this->render('maximum', 'Do not exceed 50% for this trip', null);

        $this->assertStringContainsString('Do not exceed 50% for this trip', $html);
        $this->assertStringNotContainsString('Normal vehicle target:', $html);
        $this->assertStringContainsString('Guest-specific override', $html);
    }

    /** @return iterable<string, array{string,string}> */
    public static function comparisonProvider(): iterable
    {
        yield 'maximum' => ['maximum', 'Do not exceed 50% for this trip'];
        yield 'target' => ['target', 'Aim for 50% for this trip'];
        yield 'minimum' => ['minimum', 'At least 50% for this trip'];
    }

    private function render(string $comparison, string $summary, ?string $normalPolicySummary): string
    {
        return CoreServices::renderer()->setData(['commitment' => [
            'energy_comparison' => $comparison,
            'energy_rule_summary' => $summary,
            'normal_vehicle_policy_summary' => $normalPolicySummary,
        ]])->render('trip_commitments/components/energy_override_context');
    }
}
