<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class VehiclePositioningPlanWorkflowService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly ?NextConfirmedTripService $nextTripService = null,
        private readonly ?ImportFreshnessService $freshnessService = null,
        private readonly ?VehiclePositioningRecommendationService $recommendationService = null,
        private readonly ?VehiclePositioningPlanService $planService = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function context(int $vehicleId, ?\DateTimeImmutable $asOf = null): ?array
    {
        $vehicle = $this->repo()->vehicle($vehicleId);
        if ($vehicle === null) {
            return null;
        }

        $asOf ??= new \DateTimeImmutable();
        $timestamp = $asOf->format('Y-m-d H:i:s');
        $event = $this->repo()->latestActiveMovementEvent($vehicleId, $timestamp);
        $lifecycleEvent = $this->repo()->latestActiveLifecycleEvent($vehicleId, $timestamp);
        $tripId = isset($lifecycleEvent['turo_trip_normalized_id']) ? (int) $lifecycleEvent['turo_trip_normalized_id'] : null;
        $schedule = $tripId === null ? null : $this->repo()->tripSchedule($tripId);
        $assessment = $this->repo()->assessmentForEventOrTrip(isset($lifecycleEvent['id']) ? (int) $lifecycleEvent['id'] : null, $tripId);
        $profile = $this->repo()->profile($vehicleId) ?? ['energy_kind' => 'unknown', 'ready_energy_target_percent' => null, 'capabilities' => []];
        $nextTrip = $this->nextTrips()->forVehicle($vehicleId, $asOf);
        $freshness = $this->freshness()->assess($nextTrip['import_completed_at'] ?? $schedule['import_completed_at'] ?? null, $asOf);
        $basis = $this->positionBasis($lifecycleEvent, $schedule);
        $plan = $this->plans()->active($vehicleId, $event, $nextTrip, $asOf);
        $recommendation = $this->recommendations()->recommend([
            'basis_location_class' => $basis['location_class'],
            'basis_type' => $basis['basis_type'],
            'next_trip' => $nextTrip,
            'freshness' => $freshness,
            'assessment' => $assessment,
            'profile' => $profile,
            'transportation_state' => $plan['transportation_state'] ?? 'unknown',
            'active_override' => $plan,
        ]);

        return compact('vehicle', 'event', 'nextTrip', 'freshness', 'basis', 'plan', 'recommendation');
    }

    public function create(int $vehicleId, array $data, int $actorUserId): int
    {
        $context = $this->context($vehicleId);
        if ($context === null) {
            throw new \InvalidArgumentException('Vehicle not found.');
        }

        return $this->plans()->create(
            $vehicleId,
            (string) ($data['positioning_code'] ?? ''),
            $this->nullable($data['target_location_class'] ?? null),
            (string) ($data['reason_code'] ?? ''),
            $this->nullable($data['note'] ?? null),
            (string) ($data['transportation_state'] ?? ''),
            $context['event'],
            $context['nextTrip'],
            $this->nullable($data['expires_at'] ?? null),
            $actorUserId,
        );
    }

    /** @return array{location_class:string,basis_type:string} */
    private function positionBasis(?array $event, ?array $schedule): array
    {
        if (($event['event_code'] ?? null) === 'actual_handoff') {
            return ['location_class' => (string) ($schedule['return_location_class'] ?? 'unknown'), 'basis_type' => $schedule === null ? 'unknown' : 'scheduled'];
        }
        if ($event !== null && ($event['location_class'] ?? null) !== null) {
            return ['location_class' => (string) $event['location_class'], 'basis_type' => 'actual'];
        }

        return ['location_class' => (string) ($schedule['pickup_location_class'] ?? 'unknown'), 'basis_type' => $schedule === null ? 'unknown' : 'scheduled'];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
    private function nextTrips(): NextConfirmedTripService
    {
        return $this->nextTripService ?? new NextConfirmedTripService($this->repo());
    }
    private function freshness(): ImportFreshnessService
    {
        return $this->freshnessService ?? new ImportFreshnessService();
    }
    private function recommendations(): VehiclePositioningRecommendationService
    {
        return $this->recommendationService ?? new VehiclePositioningRecommendationService();
    }
    private function plans(): VehiclePositioningPlanService
    {
        return $this->planService ?? new VehiclePositioningPlanService($this->repo());
    }
}
