<?php

namespace App\Services\Fleet;

use App\Repositories\TripCommitmentRepository;
use Config\Services;
use RuntimeException;

class TripEnergyRuleResolver
{
    public function __construct(private readonly ?TripCommitmentRepository $repository = null)
    {
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    public function forTrip(int $companyId, int $tripId, ?array $profile = null): array
    {
        $normalPolicy = $this->forProfile($profile);
        if (! $this->repo()->storageExists()) {
            return $normalPolicy;
        }
        $trip = $this->repo()->tripForCompany($companyId, $tripId);
        if ($trip === null) {
            throw new RuntimeException('Trip not found for the active company.');
        }
        $override = (new TripCommitmentService($this->repo()))->tripIsOperational($trip)
            ? $this->repo()->activeEnergyOverride($companyId, $tripId)
            : null;
        if ($override === null) {
            return $normalPolicy;
        }

        $comparison = (string) $override['energy_comparison'];
        $mode = match ($comparison) {
            'minimum' => 'minimum',
            'target' => 'target',
            'maximum' => 'hard_maximum',
            'preferred_range' => 'preferred_range',
            default => 'unconfigured',
        };
        $percent = isset($override['energy_percent']) ? (int) $override['energy_percent'] : null;
        $minimum = $comparison === 'preferred_range' && isset($override['energy_min_percent'])
            ? (int) $override['energy_min_percent']
            : ($comparison === 'minimum' ? $percent : null);
        $preferredMaximum = $comparison === 'preferred_range' && isset($override['energy_max_percent'])
            ? (int) $override['energy_max_percent']
            : null;

        return [
            'source' => 'trip_commitment',
            'mode' => $mode,
            'energy_kind' => $normalPolicy['energy_kind'],
            'minimum_percent' => $minimum,
            'target_percent' => $comparison === 'target' ? $percent : null,
            'preferred_max_percent' => $preferredMaximum,
            'hard_max_percent' => $comparison === 'maximum' ? $percent : null,
            'normal_vehicle_policy' => $normalPolicy,
            'commitment_id' => (int) $override['id'],
            'instruction' => (string) $override['instruction'],
            'required' => (bool) $override['required_before_dispatch'],
            // Transitional aliases keep existing presentation and integrations compatible.
            'comparison' => $comparison,
            'percent' => $percent,
            'normal_vehicle_target' => $this->readinessMinimum($normalPolicy),
        ];
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    public function forProfile(?array $profile): array
    {
        $minimum = isset($profile['ready_energy_min_percent'])
            ? (int) $profile['ready_energy_min_percent']
            : null;
        $preferredMaximum = isset($profile['ready_energy_preferred_max_percent'])
            ? (int) $profile['ready_energy_preferred_max_percent']
            : null;

        if ($minimum !== null) {
            $mode = $preferredMaximum === null ? 'minimum' : 'preferred_range';

            return [
                'source' => 'vehicle_profile',
                'mode' => $mode,
                'energy_kind' => $profile['energy_kind'] ?? 'unknown',
                'minimum_percent' => $minimum,
                'target_percent' => null,
                'preferred_max_percent' => $preferredMaximum,
                'hard_max_percent' => null,
                'normal_vehicle_policy' => null,
                'commitment_id' => null,
                'instruction' => null,
                'required' => true,
                'comparison' => $mode === 'preferred_range' ? 'preferred_range' : 'minimum',
                'percent' => $minimum,
                'normal_vehicle_target' => $minimum,
            ];
        }

        $legacyTarget = isset($profile['ready_energy_target_percent'])
            ? (int) $profile['ready_energy_target_percent']
            : null;
        if ($legacyTarget !== null) {
            return [
                'source' => 'legacy_profile',
                'mode' => 'minimum',
                'energy_kind' => $profile['energy_kind'] ?? 'unknown',
                'minimum_percent' => $legacyTarget,
                'target_percent' => null,
                'preferred_max_percent' => null,
                'hard_max_percent' => null,
                'normal_vehicle_policy' => null,
                'commitment_id' => null,
                'instruction' => null,
                'required' => true,
                'comparison' => 'minimum',
                'percent' => $legacyTarget,
                'normal_vehicle_target' => $legacyTarget,
            ];
        }

        return [
            'source' => 'unconfigured',
            'mode' => 'unconfigured',
            'energy_kind' => $profile['energy_kind'] ?? 'unknown',
            'minimum_percent' => null,
            'target_percent' => null,
            'preferred_max_percent' => null,
            'hard_max_percent' => null,
            'normal_vehicle_policy' => null,
            'commitment_id' => null,
            'instruction' => null,
            'required' => false,
            'comparison' => null,
            'percent' => null,
            'normal_vehicle_target' => null,
        ];
    }

    /** @param array<string, mixed> $rule @return array<string, mixed> */
    public function evaluate(array $rule, ?int $energyPercent): array
    {
        $rule = $this->normalizeLegacyRule($rule);
        $mode = (string) $rule['mode'];
        if ($mode === 'unconfigured') {
            return $this->evaluation('target_needed', false, 'Configure vehicle energy readiness', null, null);
        }
        if ($energyPercent === null) {
            return $this->evaluation('measurement_needed', false, 'Record ' . $this->energyNoun($rule, true) . ' percentage', null, null);
        }

        if ($mode === 'hard_maximum') {
            $maximum = (int) $rule['hard_max_percent'];
            $ready = $energyPercent <= $maximum;

            return $this->evaluation(
                $ready ? null : 'above_maximum',
                $ready,
                $ready ? null : 'Above guest-requested maximum of ' . $maximum . '% — review before handoff',
                $ready ? null : 'Above guest-requested maximum',
                null,
            );
        }

        if ($mode === 'target') {
            $target = (int) $rule['target_percent'];
            $ready = $energyPercent >= $target;

            return $this->evaluation(
                $ready ? null : 'charge_required',
                $ready,
                $ready ? null : $this->energyNoun($rule) . ' toward ' . $target . '%',
                $energyPercent > $target ? 'Above guest-requested target' : null,
                $energyPercent > $target ? 'above_target' : null,
            );
        }

        $minimum = (int) $rule['minimum_percent'];
        if ($energyPercent < $minimum) {
            $preferredMaximum = $rule['preferred_max_percent'];
            $destination = $preferredMaximum === null
                ? 'at least ' . $minimum . '%'
                : $minimum . '–' . (int) $preferredMaximum . '%';

            return $this->evaluation('charge_required', false, $this->energyNoun($rule) . ' to ' . $destination, null, null);
        }

        $preferredMaximum = $rule['preferred_max_percent'];
        $abovePreferred = $preferredMaximum !== null && $energyPercent > (int) $preferredMaximum;

        return $this->evaluation(
            null,
            true,
            null,
            $abovePreferred ? (($rule['source'] ?? null) === 'trip_commitment' ? 'Above guest-preferred range' : 'Above preferred range') : null,
            $abovePreferred ? 'above_preferred' : null,
        );
    }

    /** @param array<string, mixed> $rule */
    public function readinessMinimum(array $rule): ?int
    {
        $rule = $this->normalizeLegacyRule($rule);

        return match ($rule['mode']) {
            'minimum', 'preferred_range' => $rule['minimum_percent'],
            'target' => $rule['target_percent'],
            'hard_maximum' => $rule['hard_max_percent'],
            default => null,
        };
    }

    /** @param array<string, mixed> $rule */
    public function policyLabel(array $rule, bool $guest = false): ?string
    {
        $rule = $this->normalizeLegacyRule($rule);

        return match ($rule['mode']) {
            'preferred_range' => ($guest ? 'Guest-preferred range: ' : 'Ready range: ')
                . (int) $rule['minimum_percent'] . '–' . (int) $rule['preferred_max_percent'] . '%',
            'minimum' => ($guest ? 'Guest minimum: ' : 'Ready minimum: ') . (int) $rule['minimum_percent'] . '%',
            'target' => ($guest ? 'Guest target: ' : 'Ready target: ') . (int) $rule['target_percent'] . '%',
            'hard_maximum' => ($guest ? 'Guest maximum: ' : 'Maximum: ') . (int) $rule['hard_max_percent'] . '%',
            default => null,
        };
    }

    /** @param array<string, mixed> $rule @return array<string, mixed> */
    private function normalizeLegacyRule(array $rule): array
    {
        if (isset($rule['mode'])) {
            return $rule;
        }
        $comparison = $rule['comparison'] ?? null;
        $percent = isset($rule['percent']) ? (int) $rule['percent'] : null;

        return array_merge($rule, [
            'mode' => match ($comparison) {
                'minimum' => 'minimum',
                'target' => 'target',
                'maximum' => 'hard_maximum',
                'preferred_range' => 'preferred_range',
                default => 'unconfigured',
            },
            'minimum_percent' => $comparison === 'preferred_range'
                ? ($rule['minimum_percent'] ?? $rule['energy_min_percent'] ?? null)
                : ($comparison === 'minimum' ? $percent : null),
            'target_percent' => $comparison === 'target' ? $percent : null,
            'preferred_max_percent' => $comparison === 'preferred_range'
                ? ($rule['preferred_max_percent'] ?? $rule['energy_max_percent'] ?? null)
                : null,
            'hard_max_percent' => $comparison === 'maximum' ? $percent : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluation(?string $condition, bool $ready, ?string $actionLabel, ?string $attentionLabel, ?string $attentionCode): array
    {
        return [
            'condition' => $condition,
            'ready' => $ready,
            'action_label' => $actionLabel,
            'attention_label' => $attentionLabel,
            'attention_code' => $attentionCode,
        ];
    }

    /** @param array<string, mixed> $rule */
    private function energyNoun(array $rule, bool $generic = false): string
    {
        if ($generic) {
            return match ($rule['energy_kind'] ?? 'unknown') {
                'electric' => 'charge',
                'gasoline', 'diesel' => 'fuel',
                default => 'Charge/Fuel',
            };
        }

        return match ($rule['energy_kind'] ?? 'unknown') {
            'electric' => 'Charge',
            'gasoline', 'diesel' => 'Fuel',
            default => 'Charge/Fuel',
        };
    }

    private function repo(): TripCommitmentRepository
    {
        return $this->repository ?? Services::tripCommitmentRepository();
    }
}
