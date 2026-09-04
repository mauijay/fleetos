<?php

namespace App\Services\Fleet;

use Config\MovementIntelligence;

class VehiclePositioningRecommendationService
{
    public function __construct(private readonly ?MovementIntelligence $config = null)
    {
    }

    /** @return array<string, mixed> */
    public function recommend(array $context): array
    {
        $location = (string) ($context['basis_location_class'] ?? 'unknown');
        $basisType = (string) ($context['basis_type'] ?? 'unknown');
        $garageCode = (string) ($context['basis_airport_garage_code'] ?? '');
        $nextTrip = $context['next_trip'] ?? null;
        $pickup = (string) ($nextTrip['pickup_location_class'] ?? 'unknown');
        $horizon = (string) ($nextTrip['planning_horizon'] ?? 'none');
        $freshness = $context['freshness'] ?? ['is_stale' => true, 'warning' => 'Import freshness unknown.'];
        $transport = (string) ($context['transportation_state'] ?? 'unknown');
        $profile = $context['profile'] ?? [];
        $assessment = $context['assessment'] ?? [];
        $missing = [];
        $reasons = [];
        $dependency = null;

        if ($basisType === 'unknown') {
            $missing[] = 'position_basis';
        }
        if ($location === 'airport_hnl') {
            $wrongGarage = $basisType === 'actual' && in_array($garageCode, ['terminal_1', 'terminal_2'], true);
            if ($wrongGarage && $nextTrip !== null && $pickup === 'airport_hnl' && in_array($horizon, ['immediate', 'near_term', 'medium_term'], true)) {
                [$code, $strength, $reasons] = ['relocate_to_international', $horizon === 'medium_term' ? 'Consider' : 'Recommended', ['wrong_airport_garage', 'next_pickup_hnl', $horizon]];
            } elseif ($nextTrip !== null && $pickup === 'airport_hnl' && in_array($horizon, ['immediate', 'near_term'], true)) {
                [$code, $strength, $reasons] = ['leave_at_airport', 'Recommended', ['already_at_hnl', 'next_pickup_hnl', $horizon]];
                if ($this->requiresUnsupportedHnlEnergy($profile, $assessment)) {
                    [$code, $strength, $reasons] = ['retrieve_home', 'Consider', ['hnl_energy_service_unavailable', 'retrieve_for_turnaround']];
                } elseif ($this->needsHnlTurnaround($profile, $assessment)) {
                    $reasons[] = 'hnl_turnaround_supported';
                }
            } elseif ($nextTrip !== null && $pickup === 'airport_hnl' && $horizon === 'medium_term') {
                [$code, $strength, $reasons] = ['leave_at_airport', 'Consider', ['already_at_hnl', 'next_pickup_hnl', 'medium_term']];
            } else {
                [$code, $strength, $reasons] = ['retrieve_home', 'Flexible', [$nextTrip === null ? 'no_confirmed_trip' : ($pickup === 'airport_hnl' ? 'distant_hnl_pickup' : 'next_pickup_not_hnl'), 'retrieve_home']];
            }
        } elseif ($location === 'waikiki_hotel') {
            if ($nextTrip !== null && $pickup === 'airport_hnl' && in_array($horizon, ['immediate', 'near_term'], true)) {
                $dependency = 'transportation_confirmation';
                if ($transport === 'confirmed') {
                    [$code, $strength, $reasons] = ['move_to_airport', 'Recommended', ['waikiki_basis', 'next_pickup_hnl', 'transport_confirmed']];
                } elseif ($transport === 'unavailable') {
                    [$code, $strength, $reasons] = ['retrieve_home', 'Consider', ['waikiki_basis', 'transport_unavailable', 'retrieve_home']];
                } else {
                    [$code, $strength, $reasons] = ['operator_decision_needed', 'Consider', ['waikiki_basis', 'transport_unknown']];
                    $missing[] = 'transportation_state';
                }
            } else {
                [$code, $strength, $reasons] = ['retrieve_home', 'Consider', ['waikiki_basis', 'default_home_retrieval']];
            }
        } elseif ($location === 'home') {
            [$code, $strength, $reasons] = ['hold_home_flexible', 'Flexible', ['vehicle_at_home', 'preserve_flexibility']];
        } else {
            [$code, $strength, $reasons] = ['operator_decision_needed', 'Consider', ['location_requires_operator_decision']];
            $missing[] = 'known_location';
        }

        $warning = $freshness['warning'] ?? null;
        if ((bool) ($freshness['is_stale'] ?? true)) {
            $strength = $strength === 'Recommended' ? 'Consider' : ($strength === 'Consider' ? 'Flexible' : $strength);
            $reasons[] = 'stale_import_data';
        }

        return [
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'strength' => $strength,
            'reason_codes' => array_values(array_unique($reasons)),
            'explanation' => $this->explanation($code, $strength),
            'basis_type' => $basisType,
            'missing_facts' => array_values(array_unique($missing)),
            'freshness_warning' => $warning,
            'transportation_dependency' => $dependency,
            'active_override' => $context['active_override'] ?? null,
        ];
    }

    private function needsHnlTurnaround(array $profile, array $assessment): bool
    {
        $target = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        return ($assessment['cleanliness'] ?? null) === 'dirty'
            || ($target !== null && ($assessment['energy_percent'] ?? null) !== null && (int) $assessment['energy_percent'] < $target);
    }

    private function requiresUnsupportedHnlEnergy(array $profile, array $assessment): bool
    {
        if (! $this->needsHnlTurnaround($profile, $assessment)) {
            return false;
        }
        $kind = (string) ($profile['energy_kind'] ?? 'unknown');
        if (! in_array($kind, ['gasoline', 'diesel'], true)) {
            return false;
        }
        return ! (bool) ($this->settings()->locationCapabilities['airport_hnl'][$kind . '_refueling'] ?? false);
    }

    private function explanation(string $code, string $strength): string
    {
        $actions = [
            'leave_at_airport' => 'Keep the vehicle at HNL for the next commitment.',
            'retrieve_home' => 'Retrieve the vehicle to home staging.',
            'move_to_airport' => 'Move the vehicle to HNL staging.',
            'hold_home_flexible' => 'Keep the vehicle at home and preserve flexibility.',
            'operator_decision_needed' => 'Confirm the vehicle position and choose the next move.',
            'relocate_to_international' => 'Relocate the vehicle to the International Garage.',
        ];
        return $strength . ': ' . $actions[$code];
    }

    private function settings(): MovementIntelligence
    {
        return $this->config ?? new MovementIntelligence();
    }
}
