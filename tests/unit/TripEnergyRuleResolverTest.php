<?php

use App\Services\Fleet\TripEnergyRuleResolver;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TripEnergyRuleResolverTest extends CIUnitTestCase
{
    private TripEnergyRuleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TripEnergyRuleResolver();
    }

    public function testPreferredRangeUsesLowerBoundForReadinessAndUpperBoundAsInformationOnly(): void
    {
        $rule = $this->resolver->forProfile([
            'energy_kind' => 'electric',
            'ready_energy_min_percent' => 70,
            'ready_energy_preferred_max_percent' => 80,
            'ready_energy_target_percent' => 70,
        ]);

        $this->assertSame('vehicle_profile', $rule['source']);
        $this->assertSame('preferred_range', $rule['mode']);
        $this->assertSame('Ready range: 70–80%', $this->resolver->policyLabel($rule));
        $this->assertSame('Charge to 70–80%', $this->resolver->evaluate($rule, 62)['action_label']);
        foreach ([70, 76, 80] as $energy) {
            $evaluation = $this->resolver->evaluate($rule, $energy);
            $this->assertTrue($evaluation['ready']);
            $this->assertNull($evaluation['condition']);
            $this->assertNull($evaluation['attention_code']);
        }

        $above = $this->resolver->evaluate($rule, 85);
        $this->assertTrue($above['ready']);
        $this->assertNull($above['condition']);
        $this->assertSame('above_preferred', $above['attention_code']);
        $this->assertSame('Above preferred range', $above['attention_label']);
        $this->assertNull($above['action_label']);

        $unknown = $this->resolver->evaluate($rule, null);
        $this->assertFalse($unknown['ready']);
        $this->assertSame('measurement_needed', $unknown['condition']);
        $this->assertSame('Record charge percentage', $unknown['action_label']);
    }

    public function testMinimumLegacyAndUnconfiguredPoliciesRemainDistinct(): void
    {
        $minimum = $this->resolver->forProfile([
            'energy_kind' => 'gasoline',
            'ready_energy_min_percent' => 75,
            'ready_energy_preferred_max_percent' => null,
            'ready_energy_target_percent' => 75,
        ]);
        $this->assertSame('minimum', $minimum['mode']);
        $this->assertSame('Fuel to at least 75%', $this->resolver->evaluate($minimum, 60)['action_label']);
        $this->assertTrue($this->resolver->evaluate($minimum, 80)['ready']);

        $legacy = $this->resolver->forProfile([
            'energy_kind' => 'electric',
            'ready_energy_target_percent' => 75,
        ]);
        $this->assertSame('legacy_profile', $legacy['source']);
        $this->assertSame('minimum', $legacy['mode']);
        $this->assertSame('Charge to at least 75%', $this->resolver->evaluate($legacy, 60)['action_label']);
        $this->assertTrue($this->resolver->evaluate($legacy, 80)['ready']);

        $unconfigured = $this->resolver->forProfile(['energy_kind' => 'electric']);
        $this->assertSame('unconfigured', $unconfigured['source']);
        $this->assertSame('target_needed', $this->resolver->evaluate($unconfigured, 43)['condition']);
    }

    public function testGuestPreferredRangeAndHardMaximumKeepDifferentSemantics(): void
    {
        $normalPolicy = $this->resolver->forProfile([
            'energy_kind' => 'electric',
            'ready_energy_min_percent' => 70,
            'ready_energy_preferred_max_percent' => 80,
        ]);
        $range = [
            'source' => 'trip_commitment',
            'mode' => 'preferred_range',
            'energy_kind' => 'electric',
            'minimum_percent' => 50,
            'target_percent' => null,
            'preferred_max_percent' => 60,
            'hard_max_percent' => null,
            'normal_vehicle_policy' => $normalPolicy,
        ];

        $this->assertSame('Guest-preferred range: 50–60%', $this->resolver->policyLabel($range, true));
        $this->assertSame('Charge to 50–60%', $this->resolver->evaluate($range, 45)['action_label']);
        foreach ([50, 55, 60] as $energy) {
            $this->assertTrue($this->resolver->evaluate($range, $energy)['ready']);
        }
        $aboveRange = $this->resolver->evaluate($range, 65);
        $this->assertTrue($aboveRange['ready']);
        $this->assertNull($aboveRange['condition']);
        $this->assertSame('Above guest-preferred range', $aboveRange['attention_label']);

        $hardMaximum = array_merge($range, [
            'mode' => 'hard_maximum',
            'minimum_percent' => null,
            'preferred_max_percent' => null,
            'hard_max_percent' => 60,
        ]);
        $this->assertTrue($this->resolver->evaluate($hardMaximum, 55)['ready']);
        $violation = $this->resolver->evaluate($hardMaximum, 65);
        $this->assertFalse($violation['ready']);
        $this->assertSame('above_maximum', $violation['condition']);
        $this->assertStringContainsString('review before handoff', (string) $violation['action_label']);
    }
}
