<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleHealthObservationRepository;
use App\Repositories\VehicleHealthPolicyRepository;
use DateTimeImmutable;

class VehicleHealthReminderProjectionService
{
    public function __construct(
        private readonly ?VehicleHealthObservationRepository $observationRepository = null,
        private readonly ?VehicleHealthPolicyRepository $policyRepository = null,
        private readonly ?CurrentVehicleOdometerResolver $odometerResolver = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(int $companyId, ?DateTimeImmutable $asOf = null, bool $includeInactiveStates = false): array
    {
        $asOf ??= new DateTimeImmutable();
        $reminders = [];
        foreach ($this->observations()->vehicles($companyId) as $vehicle) {
            foreach ($this->forVehicle($companyId, (int) $vehicle['id'], $asOf, $includeInactiveStates, $vehicle) as $reminder) {
                if (($reminder['reminder_code'] ?? null) === 'current_odometer') {
                    continue;
                }
                $reminders[] = $reminder;
            }
        }
        usort($reminders, static function (array $left, array $right): int {
            $priority = ['attention' => 0, 'overdue' => 1, 'due' => 2, 'upcoming' => 3, 'clear' => 4];
            $state = ($priority[$left['state']] ?? 9) <=> ($priority[$right['state']] ?? 9);
            if ($state !== 0) {
                return $state;
            }
            $fleet = ((int) ($left['fleet_number'] ?? PHP_INT_MAX)) <=> ((int) ($right['fleet_number'] ?? PHP_INT_MAX));

            return $fleet !== 0 ? $fleet : strnatcasecmp((string) $left['fleet_code'], (string) $right['fleet_code']);
        });

        return $reminders;
    }

    /** @param array<string, mixed>|null $vehicle @return list<array<string, mixed>> */
    public function forVehicle(int $companyId, int $vehicleId, ?DateTimeImmutable $asOf = null, bool $includeInactiveStates = true, ?array $vehicle = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $vehicle ??= $this->observations()->vehicle($companyId, $vehicleId);
        if ($vehicle === null || ! $this->lifecycleActive($vehicle, $asOf)) {
            return [];
        }
        $rows = [];
        $pressure = $this->tirePressureReminder($companyId, $vehicle, $asOf);
        if ($pressure !== null && ($includeInactiveStates || in_array($pressure['state'], ['attention', 'overdue', 'due'], true))) {
            $rows[] = $pressure;
        }
        $odometer = $this->odometer()->resolve($companyId, $vehicleId, $asOf);
        if ($odometer === null) {
            $rows[] = $this->baseReminder($companyId, $vehicle, [
                'identity' => "vehicle-health:{$companyId}:{$vehicleId}:current-odometer",
                'reminder_code' => 'current_odometer',
                'state' => 'due',
                'title' => 'Current odometer not confirmed',
                'action_label' => 'Record current odometer',
                'context' => $vehicle['odometer_miles'] === null
                    ? 'No authoritative odometer observation has been recorded.'
                    : 'Legacy odometer ' . number_format((int) $vehicle['odometer_miles']) . ' mi is unverified.',
                'observed_at' => null,
                'due_at' => null,
                'basis_identity' => 'no-authoritative-observation',
                'movement_relevance' => false,
                'blocking' => false,
                'href' => '/fleet/vehicles/' . $vehicleId . '#record-odometer',
            ]);
        }

        return $rows;
    }

    /** @param array<string, mixed> $vehicle @return array<string, mixed>|null */
    private function tirePressureReminder(int $companyId, array $vehicle, DateTimeImmutable $asOf): ?array
    {
        $vehicleId = (int) $vehicle['id'];
        $policy = $this->policies()->tirePressurePolicy($companyId, $vehicleId, true);
        if ($policy === null) {
            return null;
        }
        $observation = $this->observations()->latestTirePressure($companyId, $vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($observation === null) {
            return $this->baseReminder($companyId, $vehicle, [
                'identity' => "vehicle-health:{$companyId}:{$vehicleId}:tire-pressure-check",
                'reminder_code' => 'tire_pressure_check',
                'state' => 'due',
                'title' => 'Tire pressure check due',
                'action_label' => 'Check tire pressure',
                'context' => 'No tire-pressure observation has been recorded for this policy.',
                'observed_at' => null,
                'due_at' => null,
                'basis_identity' => 'no-observation:policy:' . (int) $policy['id'],
                'movement_relevance' => true,
                'blocking' => false,
                'affected_wheels' => [],
                'policy' => $policy,
                'href' => '/fleet/vehicles/' . $vehicleId . '#record-tire-pressure',
            ]);
        }

        $acceptableMinimum = (int) $policy['acceptable_min_psi'];
        $acceptableMaximum = (int) $policy['acceptable_max_psi'];
        $safetyMinimum = $policy['safety_min_psi'] === null ? null : (int) $policy['safety_min_psi'];
        $safetyMaximum = $policy['safety_max_psi'] === null ? null : (int) $policy['safety_max_psi'];
        $affected = [];
        $safetyCrossed = false;
        foreach (['lf_psi' => 'LF', 'rf_psi' => 'RF', 'lr_psi' => 'LR', 'rr_psi' => 'RR'] as $field => $label) {
            $value = (int) $observation[$field];
            if ($value < $acceptableMinimum) {
                $affected[] = ['wheel' => $label, 'direction' => 'below', 'psi' => $value];
            } elseif ($value > $acceptableMaximum) {
                $affected[] = ['wheel' => $label, 'direction' => 'above', 'psi' => $value];
            }
            if (($safetyMinimum !== null && $value < $safetyMinimum) || ($safetyMaximum !== null && $value > $safetyMaximum)) {
                $safetyCrossed = true;
            }
        }
        $dueAt = (new DateTimeImmutable((string) $observation['observed_at'], $asOf->getTimezone()))
            ->modify('+' . (int) $policy['interval_value'] . ' days');
        if ($affected !== []) {
            $state = 'attention';
            $action = 'Correct tire pressure';
            $context = implode(', ', array_map(
                static fn (array $wheel): string => $wheel['wheel'] . ' ' . $wheel['direction'] . ' range',
                $affected,
            ));
            if ($safetyCrossed) {
                $context .= '. Outside configured safety range.';
            }
        } elseif ($asOf > $dueAt) {
            $state = 'overdue';
            $action = 'Check tire pressure';
            $context = 'Routine tire-pressure verification is overdue.';
        } elseif ($asOf == $dueAt) {
            $state = 'due';
            $action = 'Check tire pressure';
            $context = 'Routine tire-pressure verification is due.';
        } else {
            $state = 'upcoming';
            $action = null;
            $context = 'Last observed pressures are inside the configured acceptable range.';
        }

        return $this->baseReminder($companyId, $vehicle, [
            'identity' => "vehicle-health:{$companyId}:{$vehicleId}:tire-pressure-check",
            'reminder_code' => 'tire_pressure_check',
            'state' => $state,
            'title' => $state === 'attention' ? 'Tire pressure needs attention' : 'Tire pressure check',
            'action_label' => $action,
            'context' => $context,
            'observed_at' => (string) $observation['observed_at'],
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'basis_identity' => 'observation:' . (int) $observation['id'] . ':policy:' . (int) $policy['id'] . ':due:' . $dueAt->format('YmdHis'),
            'movement_relevance' => in_array($state, ['attention', 'overdue', 'due'], true),
            'blocking' => $safetyCrossed,
            'affected_wheels' => $affected,
            'observation' => $observation,
            'policy' => $policy,
            'href' => '/fleet/vehicles/' . $vehicleId . '#record-tire-pressure',
        ]);
    }

    /** @param array<string, mixed> $vehicle @param array<string, mixed> $values @return array<string, mixed> */
    private function baseReminder(int $companyId, array $vehicle, array $values): array
    {
        return array_merge([
            'company_id' => $companyId,
            'fleet_vehicle_id' => (int) $vehicle['id'],
            'fleet_number' => $vehicle['fleet_number'] === null ? null : (int) $vehicle['fleet_number'],
            'fleet_code' => (string) $vehicle['fleet_code'],
            'display_name' => (string) $vehicle['display_name'],
        ], $values);
    }

    /** @param array<string, mixed> $vehicle */
    private function lifecycleActive(array $vehicle, DateTimeImmutable $asOf): bool
    {
        if (in_array((string) ($vehicle['status_code'] ?? ''), ['disposed', 'retired', 'out_of_service'], true)) {
            return false;
        }
        $outOfService = trim((string) ($vehicle['out_of_service_date'] ?? ''));

        return $outOfService === '' || $outOfService > $asOf->format('Y-m-d');
    }

    private function observations(): VehicleHealthObservationRepository
    {
        return $this->observationRepository ?? new VehicleHealthObservationRepository();
    }

    private function policies(): VehicleHealthPolicyRepository
    {
        return $this->policyRepository ?? new VehicleHealthPolicyRepository();
    }

    private function odometer(): CurrentVehicleOdometerResolver
    {
        return $this->odometerResolver ?? new CurrentVehicleOdometerResolver($this->observations());
    }
}
