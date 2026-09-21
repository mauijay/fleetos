<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class TripCommitmentEnergyContextViewTest extends CIUnitTestCase
{
    #[DataProvider('comparisonProvider')]
    public function testEnergyOverrideShowsResolvedRuleAndNormalVehicleTarget(string $comparison, string $summary): void
    {
        $html = $this->render($comparison, $summary, 75);

        $this->assertStringContainsString($summary, $html);
        $this->assertStringContainsString('Normal vehicle target: 75%', $html);
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

    private function render(string $comparison, string $summary, ?int $normalTarget): string
    {
        return CoreServices::renderer()->setData(['commitment' => [
            'energy_comparison' => $comparison,
            'energy_rule_summary' => $summary,
            'energy_rule' => [
                'source' => 'trip_commitment',
                'comparison' => $comparison,
                'percent' => 50,
                'normal_vehicle_target' => $normalTarget,
            ],
        ]])->render('trip_commitments/components/energy_override_context');
    }
}
