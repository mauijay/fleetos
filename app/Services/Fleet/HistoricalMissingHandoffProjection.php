<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\GuestCommitmentProjectionRepository;
use App\Repositories\MovementReadinessReadModelRepository;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\TripCommitmentRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/** Frozen inputs run through the ordinary authoritative projectors. */
final class HistoricalMissingHandoffProjection
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function capture(array $r, array $state, DateTimeImmutable $now): array
    {
        $facts = new OperationalFactsRepository($this->db);
        $ids = array_column($state['trip_movement_checklists'], 'id');
        $tripIds = array_map('intval', array_column($state['turo_trips_normalized'], 'id'));
        $contexts = (new MovementReadinessReadModelRepository($this->db))->loadForCompany($r['company_id'], $ids, $now);
        $commercial = (new GuestCommitmentProjectionRepository($this->db))->load($r['company_id'], $tripIds, $now);
        $vehicle = $state['fleet_vehicles'][0];
        $status = $this->db->table('vehicle_statuses')->where('id', $vehicle['vehicle_status_id'])->get(1)->getRowArray();
        $codes = array_column($this->db->table('lookup_values')->whereIn('id', array_values(array_unique(array_column($state['turo_trips_normalized'], 'trip_status_lookup_value_id'))))->get()->getResultArray(), 'code', 'id');
        $locations = [];
        foreach ($state['scheduled_movement_locations'] as $location) {
            $locations[$location['turo_trip_normalized_id']][$location['movement_type']] = $location;
        }
        $reservations = [];
        foreach ($state['turo_trips_normalized'] as $trip) {
            $code = $codes[$trip['trip_status_lookup_value_id']] ?? '';
            if ($trip['deleted_at'] === null && $trip['canceled_at'] === null && $code !== 'invalid' && ! str_starts_with($code, 'canceled')) {
                $scheduled = $locations[$trip['id']] ?? [];
                $reservations[] = $trip + ['status_code' => $code, 'source' => 'turo', 'source_reservation_id' => $trip['turo_trip_id'],
                    'pickup_location_class' => $scheduled['pickup']['location_class'] ?? null, 'pickup_location_source_text' => $scheduled['pickup']['source_text'] ?? null,
                    'return_location_class' => $scheduled['return']['location_class'] ?? null, 'return_location_source_text' => $scheduled['return']['source_text'] ?? null];
            }
        }
        usort($reservations, static fn ($a, $b) => [$a['starts_at'], $a['id']] <=> [$b['starts_at'], $b['id']]);
        $inputs = ['contexts' => array_filter($contexts, static fn ($c) => (int) $c['turo_trip_normalized_id'] !== $r['trip_id']), 'canonical_contexts' => $contexts, 'commercial' => $commercial,
            'timeline' => $facts->activeCustodyTimelineEvents([$r['vehicle_id']], $now->format('Y-m-d H:i:s'), $r['company_id']),
            'location' => $facts->latestLocationEvent($r['vehicle_id'], $now->format('Y-m-d H:i:s')),
            'physical_cleanliness' => $facts->latestCleanlinessForCompany($r['company_id'], [$r['vehicle_id']], $now->format('Y-m-d H:i:s')),
            'physical_energy' => $facts->latestEnergyForCompany($r['company_id'], [$r['vehicle_id']], $now->format('Y-m-d H:i:s')),
            'profile' => $state['vehicle_operational_profiles'][0] ?? null,
            'current_readiness' => $facts->latestCurrentReadinessAssessment($r['company_id'], $r['vehicle_id'], $now->format('Y-m-d H:i:s')),
            'availability_vehicle' => $vehicle + ['status_code' => $status['code'] ?? '', 'is_available_for_booking' => $status['is_available_for_booking'] ?? false],
            'reservations' => $reservations, 'deliveries' => $state['airport_deliveries']];
        return ['inputs' => $inputs, 'projected' => $this->project($r, $inputs, $now)];
    }

    public function project(array $r, array $inputs, DateTimeImmutable $now): array
    {
        $facts = new class ($this->db, $inputs) extends OperationalFactsRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function activeCustodyTimelineEvents(array $vehicleIds, string $asOf, ?int $companyId = null): array
            {
                return array_values(array_filter($this->inputs['timeline'], static fn ($e) => $e['occurred_at'] <= $asOf));
            }
            public function latestLocationEvent(int $vehicleId, ?string $asOf = null): ?array
            {
                return $this->inputs['location'];
            }
        };
        $custody = new CurrentVehicleCustodyService($facts);
        $current = $custody->resolve($r['vehicle_id'], $now);
        unset($current['basis_event'], $current['conflicting_guest_states']);
        $position = (new CurrentVehicleLocationService($facts, $custody))->resolve($r['vehicle_id'], $now);
        unset($position['age_seconds']);
        $repository = new class ($this->db, $inputs['contexts']) extends MovementReadinessReadModelRepository {
            public function __construct(BaseConnection $db, private readonly array $contexts)
            {
                parent::__construct($db);
            }
            public function loadForCompany(int $companyId, array $checklistIds, DateTimeImmutable $asOf): array
            {
                return $this->contexts;
            }
        };
        $commercial = new class ($this->db, $inputs['commercial']) extends GuestCommitmentProjectionRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function load(int $companyId, array $tripIds, DateTimeImmutable $asOf): array
            {
                return $this->inputs;
            }
        };
        // Resolve energy overrides from the same frozen bulk inputs, not per-
        // checklist live queries that could contaminate the before projection.
        $tripRepository = new class ($this->db, $inputs['commercial']) extends TripCommitmentRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function storageExists(): bool
            {
                return true;
            }
            public function tripForCompany(int $companyId, int $tripId): ?array
            {
                foreach ($this->inputs['trips'] as $trip) {
                    if ((int) $trip['id'] === $tripId && (int) $trip['company_id'] === $companyId) {
                        return $trip;
                    }
                }
                return null;
            }
            public function activeEnergyOverride(int $companyId, int $tripId, ?int $excludingId = null): ?array
            {
                foreach ($this->inputs['manuals'] as $row) {
                    if ((int) $row['company_id'] === $companyId && (int) $row['turo_trip_normalized_id'] === $tripId && $row['state'] === 'active' && $row['category'] === 'energy_override' && ($row['active_override_slot'] ?? null) === 'energy' && (int) $row['id'] !== $excludingId) {
                        return $row;
                    }
                }
                return null;
            }
        };
        $fulfillment = new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->db));
        $commitments = new TripCommitmentService($tripRepository);
        $readiness = new MovementReadinessReadService(
            $repository,
            new MovementReadinessProjectionService(),
            $commitments,
            new TripEnergyRuleResolver($tripRepository),
            $fulfillment,
            null,
            new GuestCommitmentProjectionService($commercial, $fulfillment, $commitments)
        );
        $availabilityRepo = new class ($this->db, $inputs) extends FleetIntelligenceRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function fleetVehicles(): array
            {
                return [$this->inputs['availability_vehicle']];
            }
            public function operationalReservationsBetween(string $startsAt, string $endsAt, ?int $companyId = null): array
            {
                return array_values(array_filter($this->inputs['reservations'], static fn ($t) => $t['starts_at'] < $endsAt && $t['ends_at'] > $startsAt));
            }
            public function airportDeliveriesBetween(string $startsAt, string $endsAt, ?int $companyId = null): array
            {
                return array_values(array_filter($this->inputs['deliveries'], static fn ($d) => $d['deleted_at'] === null && $d['scheduled_at'] >= $startsAt && $d['scheduled_at'] < $endsAt));
            }
        };
        return $this->withoutDisplayClock(['custody' => $current, 'position' => $position, 'current_readiness' => $inputs['current_readiness'],
            'readiness' => $readiness->forCompany($r['company_id'], array_keys($inputs['contexts']), $now),
            'availability' => (new VehicleAvailabilityService($availabilityRepo))->vehicleStatus($now)]);
    }

    /** Canonical work is independently recomputed, never inferred from filtered history. */
    public function currentFuture(array $r, array $inputs, DateTimeImmutable $now): array
    {
        $canonical = $inputs;
        $canonical['contexts'] = $inputs['canonical_contexts'];
        $all = $this->project($r, $canonical, $now)['readiness'];
        $policy = new HistoricalMissingHandoffPreservation();
        $readiness = [];
        foreach ($all as $id => $row) {
            $context = $inputs['canonical_contexts'][$id];
            if (! $policy->closedTrip((int) $row['trip_id'], $inputs)) {
                $readiness[$id] = $row;
            } elseif ($context['movement_type'] === 'return' && isset($context['next_trip']['id'])
                && ! $policy->closedTrip((int) $context['next_trip']['id'], $inputs)) {
                // A closed source may still own current/future preparation work.
                $readiness[$id] = array_values(array_filter($row['requirements'], static fn ($q) => $q['phase'] === MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION));
            }
        }
        $commercial = new GuestCommitmentProjectionService();
        $extras = $commercial->compose($inputs['commercial'], $now);
        $preparation = [];
        $custody = (new CurrentVehicleCustodyService())->resolveEvents($inputs['timeline']);
        foreach ($extras as $tripId => &$trip) {
            if (! $policy->closedTrip((int) $tripId, $inputs)) {
                $schedule = $trip['trip'];
                $status = (string) ($schedule['trip_status_code'] ?? '');
                $preparation[$tripId] = (new MovementReadinessProjectionService())->projectTripPreparation($schedule + [
                    'company_id' => $r['company_id'], 'turo_trip_normalized_id' => (int) $tripId,
                    'trip_is_operational' => $schedule['deleted_at'] === null && $schedule['canceled_at'] === null && $status !== 'invalid' && ! str_starts_with($status, 'canceled'),
                    'latest_custody_event' => $custody['basis_event'], 'active_events' => in_array((int) $tripId, $inputs['commercial']['handoffs'], true) ? ['actual_handoff' => ['turo_trip_normalized_id' => (int) $tripId]] : [],
                    'active_commitments' => $trip['manual'], 'extra_fulfillments' => $trip['purchased'], 'extra_verification' => $trip['verification'],
                ]);
            }
            // Explanatory lifecycle membership is not an obligation. All source
            // evidence, freshness decisions and actual purchased/manual rows remain.
            unset($trip['has_actual_handoff'], $trip['verification']['pickup_applicable'], $trip['verification']['lifecycle']);
        }
        unset($trip);
        $facts = new class ($this->db, $inputs) extends OperationalFactsRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function activeCustodyTimelineEvents(array $vehicleIds, string $asOf, ?int $companyId = null): array
            {
                return $this->inputs['timeline'];
            }
            public function activeFleetVehiclesForCompany(int $companyId, ?string $activeOn = null): array
            {
                return [$this->inputs['availability_vehicle']];
            }
            public function latestCleanlinessForCompany(int $companyId, array $vehicleIds, string $asOf): array
            {
                return $this->inputs['physical_cleanliness'];
            }
            public function latestEnergyForCompany(int $companyId, array $vehicleIds, string $asOf): array
            {
                return $this->inputs['physical_energy'];
            }
            public function profile(int $vehicleId): ?array
            {
                return $this->inputs['profile'];
            }
        };
        $custody = new CurrentVehicleCustodyService($facts);
        $nextTrips = new NextConfirmedTripService($facts, custodyService: $custody);
        $nextTrip = $nextTrips->forVehicle($r['vehicle_id'], $now);
        $work = new OperationalMovementWorkService($facts, $nextTrips, new TripEnergyRuleResolver(new TripCommitmentRepository($this->db)), $custody);
        $cleaning = array_map(static fn ($need) => $need + ['target_trip_id' => $nextTrip['id'] ?? null], $work->cleaningNeedsForCompany($r['company_id'], $now));
        return $this->withoutDisplayClock(['readiness' => $readiness, 'preparation' => $preparation, 'execution' => $this->executionWork($inputs, $now), 'extras' => $extras, 'next_trip' => $nextTrip,
            'cleaning' => $cleaning,
            'energy' => $work->energyNeedsForCompany($r['company_id'], $now)]);
    }

    /** Current/future Command Center movement identities, using canonical completion authority.
     *  Schedule eligibility mirrors TaskService: a recovered trip can still have
     *  an uncompleted pickup on today's board, so physical closure alone cannot
     *  exempt it. Calendar eligibility here is NOT historical closure authority.
     */
    private function executionWork(array $inputs, DateTimeImmutable $now): array
    {
        $completion = (new OperationalMovementWorkService())->completionByMovement($inputs['timeline']);
        $custody = (new CurrentVehicleCustodyService())->resolveEvents($inputs['timeline']);
        $stagedTrip = ($custody['basis_event_code'] ?? null) === 'guest_return_staged' ? (int) $custody['active_trip_id'] : null;
        $start = $now->setTime(0, 0)->format('Y-m-d H:i:s');
        $work = [];
        foreach ($inputs['reservations'] as $trip) {
            foreach (['pickup' => 'starts_at', 'return' => 'ends_at'] as $type => $field) {
                if ($trip[$field] >= $start && ! isset($completion[(int) $trip['id'] . ':' . $type])
                    && ! ($type === 'return' && (int) $trip['id'] === $stagedTrip)) {
                    $work[] = ['trip_id' => (int) $trip['id'], 'movement_type' => $type, 'scheduled_at' => $trip[$field]];
                }
            }
        }
        return $work;
    }

    /** Frozen source evidence isolates the historical action-availability comparison. */
    public function historicalView(array $r, array $inputs, array $evidence, DateTimeImmutable $now): array
    {
        $inputs['commercial'] = $evidence;
        return $this->project($r, $inputs, $now);
    }

    /** Overlay the exact missing fact in every canonical consumer, including Extras. */
    public function withHandoff(array $r, array $inputs, array $event): array
    {
        $inputs['timeline'][] = $event;
        $inputs['commercial']['handoffs'][] = $r['trip_id'];
        sort($inputs['commercial']['handoffs']);
        $custody = new CurrentVehicleCustodyService();
        foreach (['contexts', 'canonical_contexts'] as $key) {
            foreach ($inputs[$key] as &$context) {
                $resolved = $custody->resolveEvents($inputs['timeline']);
                $context['vehicle_custody'] = $resolved;
                $context['latest_custody_event'] = $resolved['basis_event'];
                $context['latest_vehicle_use_event'] = $custody->resolveEvents(array_values(array_filter($inputs['timeline'], static fn ($e) => $e['event_code'] !== 'vehicle_staged')))['basis_event'];
                if ((int) ($context['next_trip']['id'] ?? 0) === $r['trip_id']) {
                    $context['next_pickup_handoff'] = $event;
                }
                if ((int) $context['turo_trip_normalized_id'] === $r['trip_id'] && $context['movement_type'] === 'pickup') {
                    $context['active_events']['actual_handoff'] = $event;
                }
            }
            unset($context);
        }
        return $inputs;
    }

    /** Eligibility/freshness is still evaluated at now; only display clocks are omitted. */
    private function withoutDisplayClock(array $value): array
    {
        unset($value['as_of']);
        if (array_key_exists('qualifies_for_preparation', $value) && array_key_exists('observation_trusted', $value)) {
            // These three fields only narrate elapsed time. Keep every actual
            // freshness/eligibility decision, source identity and obligation.
            unset($value['age_seconds'], $value['age_label'], $value['summary']);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->withoutDisplayClock($item);
            }
        }
        unset($item);
        return $value;
    }
}
