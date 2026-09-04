<?php

use App\Services\Fleet\VehiclePositioningRecommendationService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class VehiclePositioningRecommendationServiceTest extends CIUnitTestCase
{
    #[DataProvider('ruleProvider')]
    public function testPositioningRuleMatrix(array $context, string $code, string $strength): void
    {
        $result = (new VehiclePositioningRecommendationService())->recommend(array_merge([
            'basis_type' => 'actual',
            'freshness' => ['is_stale' => false, 'warning' => null],
        ], $context));

        $this->assertSame($code, $result['code']);
        $this->assertSame($strength, $result['strength']);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function ruleProvider(): array
    {
        return [
            'HNL immediate HNL' => [['basis_location_class' => 'airport_hnl', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'immediate']], 'leave_at_airport', 'Recommended'],
            'HNL near HNL' => [['basis_location_class' => 'airport_hnl', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'leave_at_airport', 'Recommended'],
            'HNL medium HNL' => [['basis_location_class' => 'airport_hnl', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'medium_term']], 'leave_at_airport', 'Consider'],
            'HNL medium stale HNL' => [['basis_location_class' => 'airport_hnl', 'freshness' => ['is_stale' => true, 'warning' => 'Refresh Turo data before finalizing.'], 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'medium_term']], 'leave_at_airport', 'Flexible'],
            'HNL distant HNL' => [['basis_location_class' => 'airport_hnl', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'distant']], 'retrieve_home', 'Flexible'],
            'HNL non-HNL' => [['basis_location_class' => 'airport_hnl', 'next_trip' => ['pickup_location_class' => 'waikiki_hotel', 'planning_horizon' => 'near_term']], 'retrieve_home', 'Flexible'],
            'HNL no trip' => [['basis_location_class' => 'airport_hnl'], 'retrieve_home', 'Flexible'],
            'Waikiki transport confirmed' => [['basis_location_class' => 'waikiki_hotel', 'transportation_state' => 'confirmed', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'move_to_airport', 'Recommended'],
            'Waikiki transport unavailable' => [['basis_location_class' => 'waikiki_hotel', 'transportation_state' => 'unavailable', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'immediate']], 'retrieve_home', 'Consider'],
            'Waikiki transport unknown' => [['basis_location_class' => 'waikiki_hotel', 'transportation_state' => 'unknown', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'immediate']], 'operator_decision_needed', 'Consider'],
            'Waikiki default home' => [['basis_location_class' => 'waikiki_hotel'], 'retrieve_home', 'Consider'],
            'home' => [['basis_location_class' => 'home'], 'hold_home_flexible', 'Flexible'],
            'unknown' => [['basis_location_class' => 'unknown'], 'operator_decision_needed', 'Consider'],
            'dirty electric remains HNL' => [['basis_location_class' => 'airport_hnl', 'profile' => ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80], 'assessment' => ['cleanliness' => 'dirty', 'energy_percent' => 90], 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'leave_at_airport', 'Recommended'],
            'low charge electric remains HNL' => [['basis_location_class' => 'airport_hnl', 'profile' => ['energy_kind' => 'electric', 'ready_energy_target_percent' => 80], 'assessment' => ['cleanliness' => 'clean', 'energy_percent' => 25], 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'leave_at_airport', 'Recommended'],
            'low fuel gasoline retrieves' => [['basis_location_class' => 'airport_hnl', 'profile' => ['energy_kind' => 'gasoline', 'ready_energy_target_percent' => 80], 'assessment' => ['cleanliness' => 'clean', 'energy_percent' => 25], 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'retrieve_home', 'Consider'],
            'International HNL remains staged' => [['basis_location_class' => 'airport_hnl', 'basis_airport_garage_code' => 'international', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'leave_at_airport', 'Recommended'],
            'Terminal 1 requires relocation' => [['basis_location_class' => 'airport_hnl', 'basis_airport_garage_code' => 'terminal_1', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term']], 'relocate_to_international', 'Recommended'],
            'Terminal 2 requires relocation' => [['basis_location_class' => 'airport_hnl', 'basis_airport_garage_code' => 'terminal_2', 'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'immediate']], 'relocate_to_international', 'Recommended'],
        ];
    }

    public function testStaleFreshnessDowngradesAndOverrideDoesNotChangeComputedRecommendation(): void
    {
        $override = ['id' => 7, 'positioning_code' => 'retrieve_home'];
        $result = (new VehiclePositioningRecommendationService())->recommend([
            'basis_location_class' => 'airport_hnl',
            'basis_type' => 'actual',
            'next_trip' => ['pickup_location_class' => 'airport_hnl', 'planning_horizon' => 'near_term'],
            'freshness' => ['is_stale' => true, 'warning' => 'Refresh Turo data before finalizing.'],
            'active_override' => $override,
        ]);

        $this->assertSame('leave_at_airport', $result['code']);
        $this->assertSame('Consider', $result['strength']);
        $this->assertSame($override, $result['active_override']);
        $this->assertContains('stale_import_data', $result['reason_codes']);
        $this->assertNotNull($result['freshness_warning']);
    }
}
