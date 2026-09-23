<?php

namespace App\Services\Fleet;

use App\Repositories\FleetExtraRepository;
use App\Repositories\TripCommitmentRepository;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

class TripCommitmentService
{
    public const CATEGORIES = [
        'pickup_instruction', 'return_instruction', 'vehicle_setup', 'guest_amenity',
        'child_seat_setup', 'energy_override', 'timing_arrangement', 'other',
    ];
    public const PHASES = ['preparation', 'pickup', 'return', 'entire_trip'];
    public const HANDLING_MODES = ['informational', 'acknowledgment', 'task', 'automatic_override'];
    public const ENERGY_COMPARISONS = ['target', 'minimum', 'maximum', 'preferred_range'];

    public function __construct(
        private readonly ?TripCommitmentRepository $repository = null,
        private readonly ?TripEnergyRuleResolver $energyRuleResolver = null,
        private readonly ?FleetExtraRepository $extraRepository = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function workspace(int $companyId, int $tripId): array
    {
        $trip = $this->trip($companyId, $tripId);
        $tripIsOperational = $this->tripIsOperational($trip);
        $energyRule = $this->energyRules()->forTrip($companyId, $tripId, $trip);
        $commitments = array_map(
            fn (array $commitment): array => $this->present($commitment, $energyRule),
            $this->repo()->forTrip($companyId, $tripId),
        );
        $activeCommitments = array_values(array_filter($commitments, static fn (array $row): bool => $row['state'] === 'active'));

        return [
            'trip' => $trip,
            'trip_is_operational' => $tripIsOperational,
            'active' => $tripIsOperational ? $activeCommitments : [],
            'preserved' => $tripIsOperational ? [] : $activeCommitments,
            'history' => array_values(array_filter($commitments, static fn (array $row): bool => $row['state'] !== 'active')),
            'audits' => $this->repo()->auditsForTrip($companyId, $tripId),
            'categories' => $this->categoryOptions(),
            'phases' => $this->phaseOptions(),
            'handling_modes' => $this->handlingOptions(),
            'energy_comparisons' => $this->energyComparisonOptions(),
            'fleet_extras' => $this->extraRepository?->options($companyId) ?? [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function activeForTrip(int $companyId, int $tripId, ?array $phases = null, ?array $energyRule = null): array
    {
        if (! $this->repo()->storageExists()) {
            return [];
        }
        $trip = $this->repo()->tripForCompany($companyId, $tripId);
        if ($trip === null || ! $this->tripIsOperational($trip)) {
            return [];
        }
        $energyRule ??= $this->energyRules()->forTrip($companyId, $tripId, $trip);
        $commitments = $this->repo()->activeForTrip($companyId, $tripId);
        if ($phases !== null) {
            $commitments = array_values(array_filter($commitments, static fn (array $row): bool => in_array($row['applies_during'], $phases, true)));
        }

        return array_map(
            fn (array $commitment): array => $this->present($commitment, $energyRule),
            $commitments,
        );
    }

    /** @return array<string, mixed> */
    public function create(int $companyId, int $tripId, array $input, int $actorUserId): array
    {
        $this->assertActor($actorUserId);
        $trip = $this->trip($companyId, $tripId);
        if (! $this->tripIsOperational($trip)) {
            throw new InvalidArgumentException('Guest commitments cannot be added to a canceled or invalid trip.');
        }
        $data = $this->validated($input, $companyId);
        $now = date('Y-m-d H:i:s');
        $data = array_merge($data, [
            'company_id' => $companyId,
            'turo_trip_normalized_id' => $tripId,
            'state' => 'active',
            'active_override_slot' => $data['category'] === 'energy_override' ? 'energy' : null,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->repo()->transaction(function () use ($data, $companyId, $tripId, $actorUserId): array {
            if ($data['category'] === 'energy_override' && $this->repo()->activeEnergyOverride($companyId, $tripId) !== null) {
                throw new InvalidArgumentException('This trip already has an active energy override. Edit or cancel it before adding another.');
            }
            $id = $this->repo()->create($data);
            $created = array_merge($data, ['id' => $id]);
            $this->repo()->audit($id, $companyId, 'created', null, $created, $actorUserId);

            return $this->present($created);
        });
    }

    /** @return array<string, mixed> */
    public function edit(int $companyId, int $tripId, int $commitmentId, array $input, int $actorUserId): array
    {
        $this->assertActor($actorUserId);
        $before = $this->activeCommitment($companyId, $tripId, $commitmentId);
        $data = $this->validated($input, $companyId);
        $data = array_merge($data, [
            'active_override_slot' => $data['category'] === 'energy_override' ? 'energy' : null,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->repo()->transaction(function () use ($companyId, $tripId, $commitmentId, $before, $data, $actorUserId): array {
            if ($data['category'] === 'energy_override' && $this->repo()->activeEnergyOverride($companyId, $tripId, $commitmentId) !== null) {
                throw new InvalidArgumentException('This trip already has another active energy override.');
            }
            $this->repo()->update($companyId, $commitmentId, $data);
            $after = array_merge($before, $data);
            $this->repo()->audit($commitmentId, $companyId, 'edited', $before, $after, $actorUserId);

            return $this->present($after);
        });
    }

    /** @return array<string, mixed> */
    public function acknowledge(int $companyId, int $tripId, int $commitmentId, int $actorUserId): array
    {
        $before = $this->activeCommitment($companyId, $tripId, $commitmentId);
        $this->assertActor($actorUserId);
        if (($before['handling_mode'] ?? null) !== 'acknowledgment') {
            throw new InvalidArgumentException('Only acknowledgment commitments can be acknowledged.');
        }
        if (($before['acknowledged_at'] ?? null) !== null) {
            throw new InvalidArgumentException('This commitment is already acknowledged.');
        }

        return $this->transition($companyId, $commitmentId, $before, 'acknowledged', [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_by_user_id' => $actorUserId,
        ], $actorUserId);
    }

    /** @return array<string, mixed> */
    public function complete(int $companyId, int $tripId, int $commitmentId, int $actorUserId): array
    {
        $before = $this->activeCommitment($companyId, $tripId, $commitmentId);
        $this->assertActor($actorUserId);
        if (($before['handling_mode'] ?? null) !== 'task') {
            throw new InvalidArgumentException('Only task commitments can be completed.');
        }

        return $this->transition($companyId, $commitmentId, $before, 'completed', [
            'state' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'completed_by_user_id' => $actorUserId,
            'active_override_slot' => null,
        ], $actorUserId);
    }

    /** @return array<string, mixed> */
    public function cancel(int $companyId, int $tripId, int $commitmentId, string $reason, int $actorUserId): array
    {
        $before = $this->activeCommitment($companyId, $tripId, $commitmentId);
        $this->assertActor($actorUserId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A cancellation or not-applicable reason is required.');
        }

        return $this->transition($companyId, $commitmentId, $before, 'canceled', [
            'state' => 'canceled',
            'canceled_at' => date('Y-m-d H:i:s'),
            'canceled_by_user_id' => $actorUserId,
            'cancellation_reason' => $reason,
            'active_override_slot' => null,
        ], $actorUserId);
    }

    /** @return array<string, mixed> */
    private function transition(int $companyId, int $commitmentId, array $before, string $action, array $changes, int $actorUserId): array
    {
        $changes['updated_by_user_id'] = $actorUserId;
        $changes['updated_at'] = date('Y-m-d H:i:s');

        return $this->repo()->transaction(function () use ($companyId, $commitmentId, $before, $action, $changes, $actorUserId): array {
            $this->repo()->update($companyId, $commitmentId, $changes);
            $after = array_merge($before, $changes);
            $this->repo()->audit($commitmentId, $companyId, $action, $before, $after, $actorUserId);

            return $this->present($after);
        });
    }

    /** @return array<string, mixed> */
    private function validated(array $input, int $companyId): array
    {
        $category = trim((string) ($input['category'] ?? ''));
        $instruction = trim((string) ($input['instruction'] ?? ''));
        $phase = trim((string) ($input['applies_during'] ?? ''));
        $handling = trim((string) ($input['handling_mode'] ?? ''));
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Choose a valid commitment category.');
        }
        if ($instruction === '' || mb_strlen($instruction) > 4000) {
            throw new InvalidArgumentException('Instruction is required and must be 4,000 characters or fewer.');
        }
        if (! in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException('Choose when this commitment applies.');
        }
        if (! in_array($handling, self::HANDLING_MODES, true)) {
            throw new InvalidArgumentException('Choose how the commitment should be handled.');
        }
        if ($category === 'energy_override') {
            $handling = 'automatic_override';
        } elseif ($handling === 'automatic_override') {
            throw new InvalidArgumentException('Automatic override handling is reserved for energy overrides.');
        }
        $comparison = $this->nullable($input['energy_comparison'] ?? null);
        $energy = $this->nullable($input['energy_percent'] ?? null);
        $energyMinimum = $this->nullable($input['energy_min_percent'] ?? null);
        $energyMaximum = $this->nullable($input['energy_max_percent'] ?? null);
        if ($category === 'energy_override') {
            if (! in_array($comparison, self::ENERGY_COMPARISONS, true)) {
                throw new InvalidArgumentException('Choose what the energy percentage means.');
            }
            if ($comparison === 'preferred_range') {
                $minimum = $this->energyPercentage($energyMinimum, 'Energy minimum');
                $maximum = $this->energyPercentage($energyMaximum, 'Energy preferred maximum');
                if ($minimum > $maximum) {
                    throw new InvalidArgumentException('Energy minimum must not exceed the preferred maximum.');
                }
                $energy = null;
                $energyMinimum = (string) $minimum;
                $energyMaximum = (string) $maximum;
            } else {
                if ($energy === null || ! ctype_digit($energy) || (int) $energy < 1 || (int) $energy > 100) {
                    throw new InvalidArgumentException('Energy percentage must be between 1 and 100.');
                }
                $energyMinimum = null;
                $energyMaximum = null;
            }
        } else {
            $comparison = null;
            $energy = null;
            $energyMinimum = null;
            $energyMaximum = null;
        }
        $arrangedAt = $this->nullable($input['arranged_at'] ?? null);
        if ($category === 'timing_arrangement') {
            if ($arrangedAt === null || strtotime($arrangedAt) === false) {
                throw new InvalidArgumentException('Enter the arranged date and time.');
            }
            $arrangedAt = date('Y-m-d H:i:s', strtotime($arrangedAt));
        } else {
            $arrangedAt = null;
        }
        $fleetExtraId = filter_var($input['fleet_extra_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fleetExtraId === false || $fleetExtraId === 0) {
            $fleetExtraId = null;
        }
        if ($fleetExtraId !== null && ($fleetExtraId < 1 || $this->extraRepository?->extra($companyId, $fleetExtraId) === null)) {
            throw new InvalidArgumentException('Choose a canonical Extra owned by the active company.');
        }

        $data = [
            'category' => $category,
            'instruction' => $instruction,
            'applies_during' => $phase,
            'handling_mode' => $handling,
            'required_before_dispatch' => ($input['required_before_dispatch'] ?? null) === '1' || ($input['required_before_dispatch'] ?? null) === 1 || ($input['required_before_dispatch'] ?? null) === true,
            'energy_comparison' => $comparison,
            'energy_percent' => $energy === null ? null : (int) $energy,
            'energy_min_percent' => $energyMinimum === null ? null : (int) $energyMinimum,
            'energy_max_percent' => $energyMaximum === null ? null : (int) $energyMaximum,
            'arranged_at' => $arrangedAt,
        ];
        if (array_key_exists('fleet_extra_id', $input) && $this->repo()->supportsExtraLink()) {
            $data['fleet_extra_id'] = $fleetExtraId;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function activeCommitment(int $companyId, int $tripId, int $commitmentId): array
    {
        $this->trip($companyId, $tripId);
        $commitment = $this->repo()->findForCompany($companyId, $tripId, $commitmentId);
        if ($commitment === null || ($commitment['state'] ?? null) !== 'active') {
            throw new RuntimeException('Active guest commitment not found for this trip.');
        }

        return $commitment;
    }

    /** @return array<string, mixed> */
    private function trip(int $companyId, int $tripId): array
    {
        if ($companyId < 1 || $tripId < 1) {
            throw new RuntimeException('A company-scoped trip is required.');
        }
        $trip = $this->repo()->tripForCompany($companyId, $tripId);
        if ($trip === null) {
            throw new RuntimeException('Trip not found for the active company.');
        }

        return $trip;
    }

    /** @param array<string, mixed> $trip */
    public function tripIsOperational(array $trip): bool
    {
        $status = strtolower(trim((string) ($trip['trip_status_code'] ?? '')));

        return ($trip['canceled_at'] ?? null) === null && $status !== 'invalid' && ! str_starts_with($status, 'canceled');
    }

    /** @return array<string, mixed> */
    private function present(array $row, ?array $energyRule = null): array
    {
        $resolvedEnergyRule = ($row['category'] ?? null) === 'energy_override'
            && (int) ($energyRule['commitment_id'] ?? 0) === (int) ($row['id'] ?? 0)
            ? $energyRule
            : null;

        return array_merge($row, [
            'category_label' => $this->categoryOptions()[$row['category']] ?? 'Guest commitment',
            'phase_label' => $this->phaseOptions()[$row['applies_during']] ?? 'Trip',
            'handling_label' => $this->handlingOptions()[$row['handling_mode']] ?? 'Information',
            'energy_comparison_label' => $row['energy_comparison'] === null ? null : ($this->energyComparisonOptions()[$row['energy_comparison']] ?? null),
            'energy_rule_summary' => $row['energy_comparison'] === null ? null : match ($row['energy_comparison']) {
                'preferred_range' => 'Guest-preferred range: ' . (int) $row['energy_min_percent'] . '–' . (int) $row['energy_max_percent'] . '%',
                'maximum' => 'Do not exceed ' . (int) $row['energy_percent'] . '% for this trip',
                'minimum' => 'At least ' . (int) $row['energy_percent'] . '% for this trip',
                default => 'Aim for ' . (int) $row['energy_percent'] . '% for this trip',
            },
            'energy_rule' => $resolvedEnergyRule,
            'normal_vehicle_policy_summary' => $this->normalVehiclePolicySummary($resolvedEnergyRule),
            'is_blocking' => (bool) $row['required_before_dispatch']
                && (($row['handling_mode'] ?? null) === 'task'
                    || (($row['handling_mode'] ?? null) === 'acknowledgment' && ($row['acknowledged_at'] ?? null) === null)),
            'fleet_extra_name' => $this->linkedExtraName($row),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function linkedExtraName(array $row): ?string
    {
        $extraId = (int) ($row['fleet_extra_id'] ?? 0);
        if ($extraId < 1 || $this->extraRepository === null) {
            return null;
        }
        $extra = $this->extraRepository->extra((int) $row['company_id'], $extraId);

        return $extra === null ? null : (string) $extra['display_name'];
    }

    private function assertActor(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new RuntimeException('An authenticated operator is required.');
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function energyPercentage(?string $value, string $label): int
    {
        if ($value === null || ! ctype_digit($value) || (int) $value < 0 || (int) $value > 100) {
            throw new InvalidArgumentException($label . ' must be between 0 and 100.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed>|null $resolvedEnergyRule */
    private function normalVehiclePolicySummary(?array $resolvedEnergyRule): ?string
    {
        if ($resolvedEnergyRule === null || ! is_array($resolvedEnergyRule['normal_vehicle_policy'] ?? null)) {
            return null;
        }
        $label = $this->energyRules()->policyLabel($resolvedEnergyRule['normal_vehicle_policy']);
        if ($label === null) {
            return null;
        }

        return str_replace(
            ['Ready range:', 'Ready minimum:', 'Ready target:'],
            ['Normal vehicle range:', 'Normal vehicle minimum:', 'Normal vehicle target:'],
            $label,
        );
    }

    /** @return array<string, string> */
    public function categoryOptions(): array
    {
        return [
            'pickup_instruction' => 'Pickup / meeting instruction', 'return_instruction' => 'Return instruction',
            'vehicle_setup' => 'Vehicle setup', 'guest_amenity' => 'Guest amenity', 'child_seat_setup' => 'Child-seat setup',
            'energy_override' => 'Energy override', 'timing_arrangement' => 'Timing arrangement', 'other' => 'Other',
        ];
    }

    /** @return array<string, string> */
    public function phaseOptions(): array
    {
        return ['preparation' => 'Preparation', 'pickup' => 'Pickup', 'return' => 'Return', 'entire_trip' => 'Entire trip'];
    }

    /** @return array<string, string> */
    public function handlingOptions(): array
    {
        return ['informational' => 'Information only', 'acknowledgment' => 'Acknowledge', 'task' => 'Complete a task', 'automatic_override' => 'Automatic override'];
    }

    /** @return array<string, string> */
    public function energyComparisonOptions(): array
    {
        return [
            'target' => 'Aim for this percentage',
            'minimum' => 'At least this percentage',
            'maximum' => 'Do not exceed this percentage',
            'preferred_range' => 'Preferred range',
        ];
    }

    private function repo(): TripCommitmentRepository
    {
        return $this->repository ?? Services::tripCommitmentRepository();
    }

    private function energyRules(): TripEnergyRuleResolver
    {
        return $this->energyRuleResolver ?? new TripEnergyRuleResolver($this->repo());
    }
}
