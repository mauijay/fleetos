<?php

namespace App\Services\Fleet;

use App\DTOs\Fleet\OperationalReconciliation;
use App\Repositories\FleetOperationalReconciliationRepository;
use App\Repositories\GuestCommitmentProjectionRepository;
use App\Repositories\MovementReadinessReadModelRepository;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\TripCommitmentRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/** Canonical company-scoped, read-only reconciliation entry point. */
final class FleetOperationalReconciliationService
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function forCompany(int $companyId, ?DateTimeImmutable $asOf = null): OperationalReconciliation
    {
        return $this->read($companyId, null, $asOf);
    }

    /** @param list<int> $vehicleIds */
    public function forVehicles(int $companyId, array $vehicleIds, ?DateTimeImmutable $asOf = null): OperationalReconciliation
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
        return $this->read($companyId, $ids, $asOf);
    }

    private function read(int $companyId, ?array $vehicleIds, ?DateTimeImmutable $asOf): OperationalReconciliation
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('An explicit company context is required.');
        }
        $asOf = ($asOf ?? new DateTimeImmutable())->setTimezone(new DateTimeZone((new \Config\App())->appTimezone));
        if ($vehicleIds === []) {
            return new OperationalReconciliation($companyId, $asOf->format(DATE_ATOM), []);
        }
        // All authorities share one connection/snapshot. No locks or business writes.
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->db->query('SET TRANSACTION READ ONLY');
            }
            $this->db->transBegin();
        }
        try {
            $projection = $this->load($companyId, $vehicleIds, $asOf);
            if ($ownsTransaction) {
                $this->db->transCommit();
            }
            return $projection;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $this->db->transRollback();
            }
            throw $exception;
        }
    }

    private function load(int $companyId, ?array $vehicleIds, DateTimeImmutable $asOf): OperationalReconciliation
    {
        $data = (new FleetOperationalReconciliationRepository($this->db))->load($companyId, $vehicleIds, $asOf);
        if ($data['vehicles'] === []) {
            return new OperationalReconciliation($companyId, $asOf->format(DATE_ATOM), []);
        }
        $facts = new OperationalFactsRepository($this->db);
        $ids = array_map('intval', array_column($data['vehicles'], 'id'));
        $custody = new CurrentVehicleCustodyService($facts);
        $data['custody'] = $custody->forCompany($companyId, $ids, $asOf);
        $latestLocations = $eventIds = $activeEventsByTrip = [];
        foreach ($data['events'] as $event) {
            $eventIds[(int) $event['id']] = $event;
            $activeEventsByTrip[(int) ($event['turo_trip_normalized_id'] ?? 0)][$event['event_code']] = $event;
            if ($event['location_class'] !== null) {
                $latestLocations[(int) $event['fleet_vehicle_id']] = $event;
            }
        }
        $locations = new CurrentVehicleLocationService();
        $data['positions'] = [];
        foreach ($ids as $vehicleId) {
            $data['positions'][$vehicleId] = $locations->projectEvents($data['custody'][$vehicleId], $latestLocations[$vehicleId] ?? null, $asOf);
        }
        $manuals = new TripCommitmentService(new TripCommitmentRepository($this->db));
        $commitments = new GuestCommitmentProjectionService(new GuestCommitmentProjectionRepository($this->db), new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->db)), $manuals);
        $tripIds = array_map('intval', array_column($data['trips'], 'id'));
        $data['commitments'] = $commitments->forTrips($companyId, $tripIds, $asOf);
        $data['generations'] = FleetOperationalReconciliationProjector::generations($data['trips'], $data['checklists']);
        $canonicalIds = array_map('intval', array_column(array_filter($data['generations'], static fn (array $row): bool => $row['is_current']), 'id'));
        $contexts = (new MovementReadinessReadModelRepository($this->db))->loadForCompany($companyId, $canonicalIds, $asOf);
        $energy = new TripEnergyRuleResolver();
        $projector = new MovementReadinessProjectionService($energy);
        $policies = array_column($data['policies'], null, 'fleet_vehicle_id');
        $pressure = [];
        foreach ($data['pressure'] as $row) {
            $pressure[(int) $row['fleet_vehicle_id']] ??= $row;
        }
        $vehicles = array_column($data['vehicles'], null, 'id');
        $data['readiness'] = [];
        foreach ($contexts as $id => $context) {
            $tripId = (int) $context['turo_trip_normalized_id'];
            $vehicleId = (int) $context['fleet_vehicle_id'];
            $nextId = (int) ($context['next_trip']['id'] ?? 0);
            foreach (['' => $tripId, 'next_trip_' => $nextId] as $prefix => $ownerId) {
                $projection = $data['commitments'][$ownerId] ?? [];
                $active = $projection['manual'] ?? [];
                $phases = $prefix === '' && $context['movement_type'] === 'return' ? ['return', 'entire_trip'] : ['preparation', 'pickup', 'entire_trip'];
                $context[$prefix === '' ? 'active_commitments' : 'next_trip_commitments'] = array_values(array_filter($active, static fn (array $row): bool => in_array($row['phase'], $phases, true)));
                $context[$prefix === '' ? 'extra_fulfillments' : 'next_trip_extra_fulfillments'] = $projection['purchased'] ?? [];
                $context[$prefix === '' ? 'extra_verification' : 'next_trip_extra_verification'] = $projection['verification'] ?? null;
                $overrides = array_values(array_filter($active, static fn (array $row): bool => $row['category'] === 'energy_override' && ($row['active_override_slot'] ?? null) === 'energy'));
                $context[$prefix . 'energy_rule'] = $energy->forOverride($energy->forProfile($context['profile']), $overrides[0] ?? null);
            }
            $context['as_of'] = $asOf->format('Y-m-d H:i:s');
            $health = (new VehicleHealthReminderProjectionService())->projectTirePressure($companyId, $vehicles[$vehicleId], $policies[$vehicleId] ?? null, $pressure[$vehicleId] ?? null, $asOf);
            $context['vehicle_health_reminders'] = $health === null ? [] : [$health];
            // Child rows must agree with the already-owned trip and vehicle.
            $context['active_events'] = array_filter($context['active_events'], static fn (array $event): bool => (int) $event['company_id'] === $companyId && (int) $event['fleet_vehicle_id'] === $vehicleId && (int) $event['turo_trip_normalized_id'] === $tripId);
            $context['active_events'] = array_filter($context['active_events'], static fn (array $event): bool => isset($eventIds[(int) $event['id']]));
            foreach (['active_assessment', 'current_readiness_assessment', 'target_pickup_assessment'] as $key) {
                $assessment = $context[$key];
                if ($assessment !== null && (! isset($eventIds[(int) $assessment['trip_movement_event_id']]) || (int) $assessment['fleet_vehicle_id'] !== $vehicleId || (int) $assessment['company_id'] !== $companyId || (int) ($eventIds[(int) $assessment['trip_movement_event_id']]['turo_trip_normalized_id'] ?? 0) !== (int) ($assessment['turo_trip_normalized_id'] ?? 0))) {
                    $context[$key] = null;
                }
            }
            if ($context['airport_workflow'] !== null && (int) $context['airport_workflow']['fleet_vehicle_id'] !== $vehicleId) {
                $context['airport_workflow'] = null;
            }
            foreach (['cleanliness', 'energy_percent'] as $field) {
                $sourceId = (int) ($context['last_known_vehicle_assessment']['_' . $field . '_event_id'] ?? 0);
                if ($sourceId > 0 && ! isset($eventIds[$sourceId])) {
                    $context['last_known_vehicle_assessment'][$field] = null;
                    foreach (array_keys($context['last_known_vehicle_assessment']) as $sourceKey) {
                        if (str_starts_with($sourceKey, '_' . $field . '_')) {
                            unset($context['last_known_vehicle_assessment'][$sourceKey]);
                        }
                    }
                }
            }
            $data['readiness'][$id] = $projector->project($context);
            $data['readiness'][$id]['source_ids'] = [
                'custody_event_id' => $context['vehicle_custody']['basis_event_id'],
                'assessment_id' => $context['active_assessment']['id'] ?? null,
                'current_assessment_id' => $context['current_readiness_assessment']['id'] ?? null,
                'last_cleanliness_event_id' => $context['last_known_vehicle_assessment']['_cleanliness_event_id'] ?? null,
                'last_energy_event_id' => $context['last_known_vehicle_assessment']['_energy_percent_event_id'] ?? null,
                'health_basis' => $health['basis_identity'] ?? null,
                'workflow_id' => $context['airport_workflow']['id'] ?? null,
                'profile_id' => $context['profile']['id'] ?? null,
            ];
        }
        $pickupTripIds = [];
        foreach ($contexts as $context) {
            if ($context['movement_type'] === 'pickup') {
                $pickupTripIds[(int) $context['turo_trip_normalized_id']] = true;
            }
        }
        foreach ($data['trips'] as $trip) {
            $tripId = (int) $trip['id'];
            if (isset($pickupTripIds[$tripId])) {
                continue;
            }
            $vehicleId = (int) $trip['fleet_vehicle_id'];
            $owned = $data['commitments'][$tripId];
            // Existing authority can project owned work before a checklist exists.
            // Physical readiness stays unknown; no workspace is materialized.
            $context = array_merge($trip, [
                'company_id' => $companyId, 'turo_trip_normalized_id' => $tripId,
                'active_events' => $activeEventsByTrip[$tripId] ?? [],
                'active_commitments' => array_values(array_filter($owned['manual'], static fn (array $row): bool => in_array($row['phase'], ['preparation', 'pickup', 'entire_trip'], true))),
                'extra_fulfillments' => $owned['purchased'], 'extra_verification' => $owned['verification'],
                'latest_custody_event' => $data['custody'][$vehicleId]['basis_event'],
                'trip_is_operational' => $manuals->tripIsOperational($owned['trip']),
            ]);
            $preparation = $projector->projectTripPreparation($context);
            $data['readiness']['trip:' . $tripId] = array_merge($preparation, [
                'checklist_id' => null, 'coverage' => 'COMMITMENTS_ONLY',
                'commitments_ready' => $preparation['ready'], 'ready' => null,
                'workflow_history' => ['stored_readiness_status' => null, 'completed_at' => null],
            ]);
        }
        $rows = (new FleetOperationalReconciliationProjector())->project($companyId, $data, $asOf);
        return new OperationalReconciliation($companyId, $asOf->format(DATE_ATOM), $rows);
    }
}
