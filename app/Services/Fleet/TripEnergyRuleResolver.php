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
        $normalTarget = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        if (! $this->repo()->storageExists()) {
            return $this->forProfile($profile);
        }
        $trip = $this->repo()->tripForCompany($companyId, $tripId);
        if ($trip === null) {
            throw new RuntimeException('Trip not found for the active company.');
        }
        $override = (new TripCommitmentService($this->repo()))->tripIsOperational($trip)
            ? $this->repo()->activeEnergyOverride($companyId, $tripId)
            : null;
        if ($override !== null) {
            return [
                'source' => 'trip_commitment',
                'comparison' => (string) $override['energy_comparison'],
                'percent' => (int) $override['energy_percent'],
                'normal_vehicle_target' => $normalTarget,
                'commitment_id' => (int) $override['id'],
                'instruction' => (string) $override['instruction'],
                'required' => (bool) $override['required_before_dispatch'],
            ];
        }
        return $this->forProfile($profile);
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    public function forProfile(?array $profile): array
    {
        $normalTarget = isset($profile['ready_energy_target_percent']) ? (int) $profile['ready_energy_target_percent'] : null;
        if ($normalTarget !== null) {
            return [
                'source' => 'vehicle_profile', 'comparison' => 'minimum', 'percent' => $normalTarget,
                'normal_vehicle_target' => $normalTarget, 'commitment_id' => null, 'instruction' => null, 'required' => true,
            ];
        }

        return [
            'source' => 'unconfigured', 'comparison' => null, 'percent' => null,
            'normal_vehicle_target' => null, 'commitment_id' => null, 'instruction' => null, 'required' => false,
        ];
    }

    /** @param array<string, mixed> $rule @return array<string, mixed> */
    public function evaluate(array $rule, ?int $energyPercent): array
    {
        $target = isset($rule['percent']) ? (int) $rule['percent'] : null;
        if ($target === null) {
            return ['condition' => 'target_needed', 'ready' => false, 'action_label' => 'Configure vehicle energy target', 'attention_label' => null];
        }
        if ($energyPercent === null) {
            return ['condition' => 'measurement_needed', 'ready' => false, 'action_label' => 'Record Charge/Fuel percentage', 'attention_label' => null];
        }
        $comparison = (string) ($rule['comparison'] ?? 'minimum');
        if ($comparison === 'maximum') {
            $ready = $energyPercent <= $target;

            return [
                'condition' => $ready ? null : 'above_maximum',
                'ready' => $ready,
                'action_label' => $ready ? null : 'Above guest-requested maximum of ' . $target . '% — review before handoff',
                'attention_label' => $ready ? null : 'Above guest-requested maximum',
            ];
        }
        $ready = $energyPercent >= $target;

        return [
            'condition' => $ready ? null : 'charge_required',
            'ready' => $ready,
            'action_label' => $ready ? null : ($comparison === 'target' ? 'Charge/Fuel toward ' . $target . '%' : 'Charge/Fuel to ' . $target . '%'),
            'attention_label' => $comparison === 'target' && $energyPercent > $target ? 'Above guest-requested target' : null,
        ];
    }

    private function repo(): TripCommitmentRepository
    {
        return $this->repository ?? Services::tripCommitmentRepository();
    }
}
