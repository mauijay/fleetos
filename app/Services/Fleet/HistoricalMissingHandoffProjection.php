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
        $ids = array_column(array_filter($state['trip_movement_checklists'], static fn ($c) => (int) $c['turo_trip_normalized_id'] !== $r['trip_id']), 'id');
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
        $inputs = ['contexts' => $contexts, 'commercial' => $commercial,
            'timeline' => $facts->activeCustodyTimelineEvents([$r['vehicle_id']], $now->format('Y-m-d H:i:s'), $r['company_id']),
            'location' => $facts->latestLocationEvent($r['vehicle_id'], $now->format('Y-m-d H:i:s')),
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

    /** Eligibility/freshness is still evaluated at now; only display clocks are omitted. */
    private function withoutDisplayClock(array $value): array
    {
        unset($value['as_of']);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->withoutDisplayClock($item);
            }
        }
        unset($item);
        return $value;
    }
}
