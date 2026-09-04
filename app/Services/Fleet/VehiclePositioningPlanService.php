<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class VehiclePositioningPlanService
{
    public const CODES = ['leave_at_airport', 'retrieve_home', 'move_to_airport', 'hold_home_flexible', 'operator_decision_needed'];
    public const TRANSPORTATION_STATES = ['not_applicable', 'confirmed', 'unavailable', 'unknown'];

    public function __construct(private readonly ?OperationalFactsRepository $repository = null)
    {
    }

    public function create(int $vehicleId, string $positioningCode, ?string $targetLocationClass, string $reasonCode, ?string $note, string $transportationState, ?array $basisEvent, ?array $nextTrip, ?string $expiresAt, int $actorUserId): int
    {
        $vehicle = $this->repo()->vehicle($vehicleId);
        $reasonCode = trim($reasonCode);
        $targetLocationClass = $targetLocationClass === null || trim($targetLocationClass) === '' ? null : trim($targetLocationClass);
        if (in_array($positioningCode, ['leave_at_airport', 'hold_home_flexible'], true)) {
            $transportationState = 'not_applicable';
        } elseif (trim($transportationState) === '') {
            $transportationState = 'unknown';
        }
        if ($vehicle === null || $actorUserId < 1 || ! in_array($positioningCode, self::CODES, true) || ! in_array($transportationState, self::TRANSPORTATION_STATES, true)) {
            throw new \InvalidArgumentException('Choose a valid vehicle positioning plan.');
        }
        if ($reasonCode === '' || mb_strlen($reasonCode) > 60) {
            throw new \InvalidArgumentException('A valid positioning reason is required.');
        }
        if ($targetLocationClass !== null && ! in_array($targetLocationClass, LocationClassificationService::CLASSES, true)) {
            throw new \InvalidArgumentException('Choose a valid target location.');
        }
        $requiredTargets = [
            'leave_at_airport' => 'airport_hnl',
            'move_to_airport' => 'airport_hnl',
            'retrieve_home' => 'home',
            'hold_home_flexible' => 'home',
        ];
        if (isset($requiredTargets[$positioningCode]) && $targetLocationClass !== $requiredTargets[$positioningCode]) {
            throw new \InvalidArgumentException('The target location does not match the positioning action.');
        }

        return $this->repo()->replacePositioningPlan([
            'company_id' => (int) $vehicle['company_id'],
            'fleet_vehicle_id' => $vehicleId,
            'positioning_code' => $positioningCode,
            'target_location_class' => $targetLocationClass,
            'reason_code' => $reasonCode,
            'note' => trim((string) $note) ?: null,
            'transportation_state' => $transportationState,
            'basis_event_id' => isset($basisEvent['id']) ? (int) $basisEvent['id'] : null,
            'basis_next_trip_id' => isset($nextTrip['id']) ? (int) $nextTrip['id'] : null,
            'basis_next_trip_starts_at' => $nextTrip['starts_at'] ?? null,
            'basis_next_trip_fingerprint' => $this->tripFingerprint($nextTrip),
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt === null || trim($expiresAt) === '' ? null : (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function active(int $vehicleId, ?array $latestEvent, ?array $nextTrip, ?\DateTimeImmutable $asOf = null): ?array
    {
        $asOf ??= new \DateTimeImmutable();
        $plan = $this->repo()->activePositioningPlan($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($plan === null) {
            return null;
        }
        $stale = (int) ($plan['basis_event_id'] ?? 0) !== (int) ($latestEvent['id'] ?? 0)
            || (int) ($plan['basis_next_trip_id'] ?? 0) !== (int) ($nextTrip['id'] ?? 0)
            || (string) ($plan['basis_next_trip_fingerprint'] ?? '') !== (string) ($this->tripFingerprint($nextTrip) ?? '');

        return array_merge($plan, ['is_basis_stale' => $stale]);
    }

    public function invalidateForWrite(int $vehicleId, string $reason, ?int $actorUserId): int
    {
        return $this->repo()->invalidatePositioningPlans($vehicleId, $reason, $actorUserId);
    }

    private function tripFingerprint(?array $trip): ?string
    {
        if ($trip === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            (string) ($trip['id'] ?? ''),
            (string) ($trip['starts_at'] ?? ''),
            (string) ($trip['trip_status_code'] ?? ''),
            (string) ($trip['pickup_location_class'] ?? ''),
        ]));
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
