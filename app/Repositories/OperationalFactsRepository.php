<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class OperationalFactsRepository
{
    private const CURRENT_STATE_EVENT_CODES = ['actual_handoff', 'actual_return', 'vehicle_recovered', 'vehicle_positioned', 'vehicle_staged'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<int> */
    public function activeFleetCompanyIds(?string $asOfDate = null): array
    {
        $builder = $this->db->table('fleet_vehicles')
            ->select('company_id')
            ->where('deleted_at', null);
        if ($asOfDate !== null) {
            $builder->groupStart()
                ->where('in_service_date', null)
                ->orWhere('in_service_date <=', $asOfDate)
            ->groupEnd()
            ->groupStart()
                ->where('out_of_service_date', null)
                ->orWhere('out_of_service_date >=', $asOfDate)
            ->groupEnd();
        }

        return array_map('intval', array_column(
            $builder->groupBy('company_id')->orderBy('company_id', 'ASC')->get()->getResultArray(),
            'company_id',
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function activeFleetVehiclesForCompany(int $companyId, ?string $asOfDate = null): array
    {
        if ($companyId < 1) {
            return [];
        }

        $builder = $this->db->table('fleet_vehicles')
            ->select('id, company_id, fleet_number, fleet_code, display_name')
            ->where('company_id', $companyId)
            ->where('deleted_at', null);
        if ($asOfDate !== null) {
            $builder->groupStart()
                ->where('in_service_date', null)
                ->orWhere('in_service_date <=', $asOfDate)
            ->groupEnd()
            ->groupStart()
                ->where('out_of_service_date', null)
                ->orWhere('out_of_service_date >=', $asOfDate)
            ->groupEnd();
        }

        return $builder
            ->orderBy('fleet_number IS NULL', 'ASC', false)
            ->orderBy('fleet_number', 'ASC')
            ->orderBy('fleet_code', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return array<int, array<string, mixed>> keyed by fleet vehicle id */
    public function latestCurrentStateEventsForCompany(int $companyId, array $vehicleIds, string $asOf): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
        if ($companyId < 1 || $vehicleIds === []) {
            return [];
        }

        $rows = $this->db->table('trip_movement_events')
            ->where('company_id', $companyId)
            ->whereIn('fleet_vehicle_id', $vehicleIds)
            ->whereIn('event_code', self::CURRENT_STATE_EVENT_CODES)
            ->where('voided_at', null)
            ->where('occurred_at <=', $asOf)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $latest = [];
        foreach ($rows as $row) {
            $vehicleId = (int) $row['fleet_vehicle_id'];
            $latest[$vehicleId] ??= $this->normalizeMovementEvent($row);
        }

        return $latest;
    }

    public function upsertScheduledLocation(int $tripId, ?int $vehicleId, string $movementType, array $classification): int
    {
        $now = date('Y-m-d H:i:s');
        $key = ['turo_trip_normalized_id' => $tripId, 'movement_type' => $movementType];
        $existing = $this->db->table('scheduled_movement_locations')->where($key)->get()->getRowArray();
        $data = array_merge($key, [
            'fleet_vehicle_id' => $vehicleId,
            'location_class' => $classification['location_class'],
            'source_text' => $classification['source_text'],
            'airport_id' => $classification['airport_id'],
            'airport_movement_workflow_id' => $classification['airport_movement_workflow_id'],
            'classification_source' => $classification['classification_source'],
            'classification_status' => $classification['classification_status'],
            'updated_at' => $now,
        ]);
        if ($existing === null) {
            $this->db->table('scheduled_movement_locations')->insert(array_merge($data, ['created_at' => $now]));
            return (int) $this->db->insertID();
        }
        $this->db->table('scheduled_movement_locations')->where('id', $existing['id'])->update($data);
        return (int) $existing['id'];
    }

    /** @return array<string, mixed>|null */
    public function scheduledLocation(int $tripId, string $movementType): ?array
    {
        $row = $this->db->table('scheduled_movement_locations')->where('turo_trip_normalized_id', $tripId)->where('movement_type', $movementType)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function locationAlias(int $companyId, string $normalizedSourceKey): ?array
    {
        $row = $this->db->table('movement_location_aliases')
            ->where('company_id', $companyId)
            ->where('normalized_source_key', $normalizedSourceKey)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function unknownLocationSources(): array
    {
        return $this->db->table('scheduled_movement_locations locations')
            ->select('vehicles.company_id, companies.name AS company_name, locations.source_text, locations.location_class')
            ->select('COUNT(*) AS occurrence_count, MIN(CASE WHEN trips.starts_at >= CURRENT_TIMESTAMP THEN trips.starts_at END) AS next_occurrence', false)
            ->join('turo_trips_normalized trips', 'trips.id = locations.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('companies', 'companies.id = vehicles.company_id', 'left')
            ->where('locations.source_text IS NOT NULL')
            ->where('locations.location_class', 'unknown')
            ->groupBy('vehicles.company_id, companies.name, locations.source_text, locations.location_class')
            ->orderBy('occurrence_count', 'DESC')
            ->orderBy('locations.source_text', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function saveLocationAlias(int $companyId, string $sourceText, string $normalizedSourceKey, string $locationClass, ?string $note, int $actorUserId): int
    {
        $this->db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $existing = $this->locationAlias($companyId, $normalizedSourceKey);
            $data = [
                'company_id' => $companyId,
                'source_text' => $sourceText,
                'normalized_source_key' => $normalizedSourceKey,
                'location_class' => $locationClass,
                'note' => $note,
                'updated_by' => $actorUserId,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $this->db->table('movement_location_aliases')->insert(array_merge($data, ['created_by' => $actorUserId, 'created_at' => $now]));
                $aliasId = (int) $this->db->insertID();
            } else {
                $aliasId = (int) $existing['id'];
                $this->db->table('movement_location_aliases')->where('id', $aliasId)->update($data);
            }

            $matching = $this->db->table('scheduled_movement_locations locations')
                ->select('locations.id, locations.source_text')
                ->join('turo_trips_normalized trips', 'trips.id = locations.turo_trip_normalized_id')
                ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
                ->where('vehicles.company_id', $companyId)
                ->get()
                ->getResultArray();
            foreach ($matching as $location) {
                if ($this->normalizeLocationKey((string) ($location['source_text'] ?? '')) !== $normalizedSourceKey) {
                    continue;
                }
                $this->db->table('scheduled_movement_locations')->where('id', $location['id'])->update([
                    'location_class' => $locationClass,
                    'classification_source' => 'company_alias',
                    'classification_status' => 'classified',
                    'updated_at' => $now,
                ]);
            }
            $new = $this->locationAlias($companyId, $normalizedSourceKey);
            $this->audit($companyId, 'movement_location_aliases', $aliasId, $existing === null ? 'created' : 'updated', $existing, $new, $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Location alias transaction failed.');
            }
            $this->db->transCommit();

            return $aliasId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function activePositioningPlan(int $vehicleId, string $asOf): ?array
    {
        $builder = $this->db->table('vehicle_positioning_plans plans')->select('plans.*');
        if ($this->db->tableExists('users')) {
            $builder->select('users.username AS actor_username')->join('users', 'users.id = plans.created_by', 'left');
        }
        $row = $builder
            ->where('plans.fleet_vehicle_id', $vehicleId)
            ->where('plans.invalidated_at', null)
            ->groupStart()->where('plans.expires_at', null)->orWhere('plans.expires_at >', $asOf)->groupEnd()
            ->orderBy('plans.created_at', 'DESC')->orderBy('plans.id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    public function replacePositioningPlan(array $data): int
    {
        $this->db->transBegin();
        try {
            $this->invalidatePositioningPlans((int) $data['fleet_vehicle_id'], 'superseded_by_operator_plan', (int) $data['created_by']);
            $this->db->table('vehicle_positioning_plans')->insert($data);
            $id = (int) $this->db->insertID();
            $this->audit((int) $data['company_id'], 'vehicle_positioning_plans', $id, 'created', null, $data, (int) $data['created_by']);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Positioning plan transaction failed.');
            }
            $this->db->transCommit();

            return $id;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function invalidatePositioningPlans(int $vehicleId, string $reason, ?int $actorUserId): int
    {
        if (! $this->db->tableExists('vehicle_positioning_plans')) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $plans = $this->db->table('vehicle_positioning_plans')->where('fleet_vehicle_id', $vehicleId)->where('invalidated_at', null)->get()->getResultArray();
        foreach ($plans as $plan) {
            $new = ['invalidated_at' => $now, 'invalidation_reason' => $reason, 'invalidated_by_user_id' => $actorUserId];
            $this->db->table('vehicle_positioning_plans')->where('id', $plan['id'])->update($new);
            if ($actorUserId !== null) {
                $this->audit((int) $plan['company_id'], 'vehicle_positioning_plans', (int) $plan['id'], 'invalidated', $plan, $new, $actorUserId);
            }
        }

        return count($plans);
    }

    /** @return array<int, array<string, mixed>> */
    public function locationBackfillCandidates(): array
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, raw.raw_payload')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id')
            ->where('trips.deleted_at', null)->orderBy('trips.id', 'ASC')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function airportWorkflow(int $tripId, string $movementType): ?array
    {
        $row = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.id, workflows.airport_id, airports.code AS airport_code')
            ->join('airports', 'airports.id = workflows.airport_id')
            ->where('workflows.turo_trip_normalized_id', $tripId)->where('workflows.movement_type', $movementType)
            ->orderBy('workflows.scheduled_at', 'DESC')->orderBy('workflows.id', 'DESC')->get(1)->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function nextConfirmedTrip(int $vehicleId, string $after, ?int $excludeTripId = null): ?array
    {
        $builder = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, trip_statuses.code AS trip_status_code')
            ->select('import_statuses.code AS import_status_code, batches.completed_at AS import_completed_at, batches.source_filename AS import_source_filename')
            ->select('pickup.location_class AS pickup_location_class, pickup.source_text AS pickup_location_source_text')
            ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = raw.turo_import_batch_id', 'left')
            ->join('lookup_values import_statuses', 'import_statuses.id = batches.import_status_lookup_value_id', 'left')
            ->join('scheduled_movement_locations pickup', 'pickup.turo_trip_normalized_id = trips.id AND pickup.movement_type = \'pickup\'', 'left')
            ->where('trips.fleet_vehicle_id', $vehicleId)->where('trips.starts_at >', $after)->where('trips.deleted_at', null)
            ->where('trip_statuses.code', 'booked');
        if ($excludeTripId !== null) {
            $builder->where('trips.id !=', $excludeTripId);
        }
        $row = $builder->orderBy('trips.starts_at', 'ASC')->orderBy('trips.id', 'ASC')->get(1)->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function trip(int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, fv.company_id')
            ->join('fleet_vehicles fv', 'fv.id = trips.fleet_vehicle_id', 'left')
            ->where('trips.id', $tripId)->where('trips.deleted_at', null)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array{previous:?array<string,mixed>,current:?array<string,mixed>,next:?array<string,mixed>} */
    public function tripContext(int $tripId): array
    {
        $current = $this->tripHistoryRow($tripId);
        if ($current === null || $current['starts_at'] === null) {
            return ['previous' => null, 'current' => $this->withMovementHref($current), 'next' => null];
        }

        $previous = $this->tripHistoryBuilder(excludeCanceled: true)
            ->where('trips.fleet_vehicle_id', $current['fleet_vehicle_id'])
            ->where('trips.starts_at <', $current['starts_at'])
            ->orderBy('trips.starts_at', 'DESC')->orderBy('trips.id', 'DESC')->get(1)->getRowArray();
        $next = $this->tripHistoryBuilder(excludeCanceled: true)
            ->where('trips.fleet_vehicle_id', $current['fleet_vehicle_id'])
            ->where('trips.starts_at >', $current['starts_at'])
            ->orderBy('trips.starts_at', 'ASC')->orderBy('trips.id', 'ASC')->get(1)->getRowArray();

        return [
            'previous' => $this->withMovementHref($previous),
            'current' => $this->withMovementHref($current),
            'next' => $this->withMovementHref($next),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicleTripHistory(int $vehicleId, int $limit = 50): array
    {
        $trips = $this->tripHistoryBuilder()
            ->where('trips.fleet_vehicle_id', $vehicleId)
            ->orderBy('trips.starts_at', 'DESC')->orderBy('trips.id', 'DESC')
            ->get(max(1, min($limit, 100)))
            ->getResultArray();

        return array_map($this->withMovementHref(...), $trips);
    }

    /** @return array<string, mixed>|null */
    private function tripHistoryRow(int $tripId): ?array
    {
        $row = $this->tripHistoryBuilder()->where('trips.id', $tripId)->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    private function tripHistoryBuilder(bool $excludeCanceled = false): \CodeIgniter\Database\BaseBuilder
    {
        $tripFields = $this->db->getFieldNames('turo_trips_normalized');
        $builder = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, trips.guest_name, trips.starts_at, trips.ends_at')
            ->select('pickup.location_class AS pickup_location_class, pickup.source_text AS pickup_location_source_text')
            ->select('return_location.location_class AS return_location_class, return_location.source_text AS return_location_source_text')
            ->join('scheduled_movement_locations pickup', 'pickup.turo_trip_normalized_id = trips.id AND pickup.movement_type = \'pickup\'', 'left')
            ->join('scheduled_movement_locations return_location', 'return_location.turo_trip_normalized_id = trips.id AND return_location.movement_type = \'return\'', 'left')
            ->where('trips.deleted_at', null);
        if (in_array('turo_trip_id', $tripFields, true)) {
            $builder->select('trips.turo_trip_id');
        }
        if ($this->db->tableExists('lookup_values') && in_array('trip_status_lookup_value_id', $tripFields, true)) {
            $builder->select('trip_statuses.code AS trip_status_code')
                ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id', 'left');
            if ($excludeCanceled) {
                $builder->groupStart()
                    ->where('trip_statuses.code', null)
                    ->orWhereNotIn('trip_statuses.code', (new FleetIntelligenceRepository($this->db))->canceledTripStatusCodes())
                    ->groupEnd();
            }
        }
        if ($this->db->tableExists('trip_movement_checklists')) {
            $builder->select('pickup_checklist.id AS pickup_checklist_id, return_checklist.id AS return_checklist_id')
                ->join('trip_movement_checklists pickup_checklist', 'pickup_checklist.turo_trip_normalized_id = trips.id AND pickup_checklist.movement_type = \'pickup\' AND pickup_checklist.scheduled_at = trips.starts_at', 'left')
                ->join('trip_movement_checklists return_checklist', 'return_checklist.turo_trip_normalized_id = trips.id AND return_checklist.movement_type = \'return\' AND return_checklist.scheduled_at = trips.ends_at', 'left');
        } else {
            $builder->select('NULL AS pickup_checklist_id, NULL AS return_checklist_id', false);
        }

        return $builder;
    }

    /** @param array<string, mixed>|null $trip @return array<string, mixed>|null */
    private function withMovementHref(?array $trip): ?array
    {
        if ($trip === null) {
            return null;
        }
        $checklistId = (int) ($trip['pickup_checklist_id'] ?? 0) ?: (int) ($trip['return_checklist_id'] ?? 0);

        return array_merge($trip, ['movement_href' => $checklistId > 0 ? '/operations/checklists/' . $checklistId : null]);
    }

    public function movementChecklistHref(int $tripId, ?string $preferredMovementType = null): ?string
    {
        if (! $this->db->tableExists('trip_movement_checklists')) {
            return null;
        }
        if ($preferredMovementType !== null) {
            $preferred = $this->db->table('trip_movement_checklists')
                ->select('id')
                ->where('turo_trip_normalized_id', $tripId)
                ->where('movement_type', $preferredMovementType)
                ->orderBy('scheduled_at', 'DESC')
                ->get(1)
                ->getRowArray();
            if ($preferred !== null) {
                return '/operations/checklists/' . (int) $preferred['id'];
            }
        }

        $fallback = $this->db->table('trip_movement_checklists')
            ->select('id')
            ->where('turo_trip_normalized_id', $tripId)
            ->orderBy('scheduled_at', 'DESC')
            ->get(1)
            ->getRowArray();

        return $fallback === null ? null : '/operations/checklists/' . (int) $fallback['id'];
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles')->where('id', $vehicleId)->where('deleted_at', null)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function vehicleForCompany(int $companyId, int $vehicleId): ?array
    {
        if ($companyId < 1 || $vehicleId < 1) {
            return null;
        }

        $row = $this->db->table('fleet_vehicles')
            ->where('id', $vehicleId)
            ->where('company_id', $companyId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    public function createEvent(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $fields = array_flip($this->db->getFieldNames('trip_movement_events'));
        $rowColumn = $this->airportParkingRowColumn();
        if ($rowColumn !== null && $rowColumn !== 'airport_parking_row') {
            $data[$rowColumn] = $data['airport_parking_row'] ?? null;
        }
        $this->db->table('trip_movement_events')->insert(array_intersect_key(array_merge($data, ['created_at' => $now, 'updated_at' => $now]), $fields));
        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function event(int $eventId): ?array
    {
        $row = $this->db->table('trip_movement_events')->where('id', $eventId)->get()->getRowArray();
        return $this->normalizeMovementEvent($row);
    }

    /** @return array<string, mixed>|null */
    public function latestActiveEventForTrip(int $tripId): ?array
    {
        $row = $this->db->table('trip_movement_events')
            ->where('turo_trip_normalized_id', $tripId)
            ->where('voided_at', null)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();

        return $this->normalizeMovementEvent($row);
    }

    /** @param list<string> $eventCodes @return array<string, mixed>|null */
    public function activeMovementConflict(int $tripId, array $eventCodes): ?array
    {
        if ($eventCodes === []) {
            return null;
        }

        $row = $this->db->table('trip_movement_events')
            ->where('turo_trip_normalized_id', $tripId)
            ->whereIn('event_code', $eventCodes)
            ->where('voided_at', null)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRowArray();

        return $this->normalizeMovementEvent($row);
    }

    /** @param array<string, mixed> $fact */
    public function hasExactActivePositionFact(array $fact): bool
    {
        $builder = $this->db->table('trip_movement_events')
            ->where('fleet_vehicle_id', $fact['fleet_vehicle_id'])
            ->where('turo_trip_normalized_id', $fact['turo_trip_normalized_id'])
            ->where('event_code', 'vehicle_positioned')
            ->where('occurred_at', $fact['occurred_at'])
            ->where('voided_at', null);
        foreach (['location_class', 'location_detail'] as $field) {
            $builder->where($field, $fact[$field] ?? null);
        }
        if ($this->hasStructuredAirportParking()) {
            $builder->where('airport_garage_code', $fact['airport_garage_code'] ?? null)
                ->where('airport_parking_level', $fact['airport_parking_level'] ?? null)
                ->where($this->airportParkingRowColumn(), $fact['airport_parking_row'] ?? null);
        }

        return $builder->countAllResults() > 0;
    }

    /** @param array<string, mixed> $fact */
    public function hasExactActiveReadinessFact(array $fact): bool
    {
        return $this->db->table('movement_assessments assessments')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id')
            ->where('assessments.company_id', $fact['company_id'])
            ->where('assessments.fleet_vehicle_id', $fact['fleet_vehicle_id'])
            ->where('assessments.turo_trip_normalized_id', null)
            ->where('assessments.movement_type', 'current')
            ->where('assessments.cleanliness', $fact['cleanliness'])
            ->where('assessments.energy_percent', $fact['energy_percent'])
            ->where('assessments.captured_at', $fact['captured_at'])
            ->where('assessments.voided_at', null)
            ->where('events.event_code', 'vehicle_readiness_observed')
            ->where('events.voided_at', null)
            ->countAllResults() > 0;
    }

    public function correctEvent(int $eventId, array $replacement, int $actorUserId, string $reason, bool $manageTransaction = true): int
    {
        $original = $this->event($eventId);
        if ($original === null || $original['voided_at'] !== null) {
            throw new RuntimeException('The event cannot be corrected.');
        }
        if ($manageTransaction) {
            $this->db->transBegin();
        }
        try {
            $replacementId = $this->createEvent(array_merge($replacement, ['supersedes_event_id' => $eventId]));
            $now = date('Y-m-d H:i:s');
            $this->db->table('trip_movement_events')->where('id', $eventId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => $reason, 'updated_at' => $now]);
            $this->audit((int) $original['company_id'], 'trip_movement_events', $eventId, 'superseded', $original, ['replacement_id' => $replacementId, 'reason' => $reason], $actorUserId);
            if ($manageTransaction) {
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Event correction transaction failed.');
                }
                $this->db->transCommit();
            }
            return $replacementId;
        } catch (\Throwable $exception) {
            if ($manageTransaction) {
                $this->db->transRollback();
            }
            throw $exception;
        }
    }

    public function voidEvent(int $eventId, int $actorUserId, string $reason): bool
    {
        $event = $this->event($eventId);
        if ($event === null || $event['voided_at'] !== null || trim($reason) === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('trip_movement_events')->where('id', $eventId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => trim($reason), 'updated_at' => $now]);
        $this->audit((int) $event['company_id'], 'trip_movement_events', $eventId, 'voided', $event, ['reason' => trim($reason)], $actorUserId);
        return $this->db->affectedRows() > 0;
    }

    /** @return array<string, mixed>|null */
    public function latestLocationEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->where('fleet_vehicle_id', $vehicleId)->where('location_class IS NOT NULL')->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
        return $this->normalizeMovementEvent($row);
    }

    /** @return array<string, mixed>|null */
    public function latestCurrentStateEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->where('fleet_vehicle_id', $vehicleId)
            ->whereIn('event_code', self::CURRENT_STATE_EVENT_CODES)
            ->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $this->normalizeMovementEvent($row);
    }

    /** @return array<string, mixed>|null */
    public function latestActiveMovementEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->select($this->movementEventSelect())
            ->where('fleet_vehicle_id', $vehicleId)
            ->whereIn('event_code', self::CURRENT_STATE_EVENT_CODES)
            ->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestActiveLifecycleEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->select($this->movementEventSelect())
            ->where('fleet_vehicle_id', $vehicleId)
            ->whereIn('event_code', ['vehicle_staged', 'actual_handoff', 'actual_return', 'vehicle_recovered'])
            ->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestActiveHandoffEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->select($this->movementEventSelect())
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('event_code', 'actual_handoff')
            ->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestCurrentReadinessAssessment(int $companyId, int $vehicleId, ?string $asOf = null): ?array
    {
        return $this->latestCurrentReadinessAssessmentsForCompany($companyId, [$vehicleId], $asOf)[$vehicleId] ?? null;
    }

    /** @return array<int, array<string, mixed>> keyed by fleet vehicle id */
    public function latestCurrentReadinessAssessmentsForCompany(int $companyId, array $vehicleIds, ?string $asOf = null): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
        if ($companyId < 1 || $vehicleIds === []) {
            return [];
        }

        $builder = $this->db->table('movement_assessments assessments')
            ->select('assessments.*, events.occurred_at AS event_occurred_at, events.event_code')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id')
            ->where('assessments.company_id', $companyId)
            ->whereIn('assessments.fleet_vehicle_id', $vehicleIds)
            ->where('assessments.turo_trip_normalized_id', null)
            ->where('assessments.movement_type', 'current')
            ->where('assessments.voided_at', null)
            ->where('events.event_code', 'vehicle_readiness_observed')
            ->where('events.voided_at', null);
        if ($asOf !== null) {
            $builder->where('assessments.captured_at <=', $asOf)
                ->where('events.occurred_at <=', $asOf);
        }

        $latest = [];
        foreach ($builder->orderBy('assessments.captured_at', 'DESC')->orderBy('assessments.id', 'DESC')->get()->getResultArray() as $row) {
            $vehicleId = (int) $row['fleet_vehicle_id'];
            $latest[$vehicleId] ??= $row;
        }

        return $latest;
    }

    /** @return array<string, mixed>|null */
    public function assessmentForEventOrTrip(?int $eventId, ?int $tripId): ?array
    {
        if ($eventId !== null) {
            $row = $this->db->table('movement_assessments')
                ->where('trip_movement_event_id', $eventId)
                ->where('voided_at', null)
                ->orderBy('captured_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }
        if ($tripId === null) {
            return null;
        }
        $row = $this->db->table('movement_assessments')
            ->where('turo_trip_normalized_id', $tripId)
            ->where('voided_at', null)
            ->orderBy('captured_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function tripSchedule(int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, trip_statuses.code AS trip_status_code')
            ->select('import_statuses.code AS import_status_code, batches.completed_at AS import_completed_at, batches.source_filename AS import_source_filename')
            ->select('pickup.location_class AS pickup_location_class, pickup.source_text AS pickup_location_source_text')
            ->select('return_location.location_class AS return_location_class, return_location.source_text AS return_location_source_text')
            ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = raw.turo_import_batch_id', 'left')
            ->join('lookup_values import_statuses', 'import_statuses.id = batches.import_status_lookup_value_id', 'left')
            ->join('scheduled_movement_locations pickup', 'pickup.turo_trip_normalized_id = trips.id AND pickup.movement_type = \'pickup\'', 'left')
            ->join('scheduled_movement_locations return_location', 'return_location.turo_trip_normalized_id = trips.id AND return_location.movement_type = \'return\'', 'left')
            ->where('trips.id', $tripId)->where('trips.deleted_at', null)->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestActiveFactsForTrip(int $tripId): ?array
    {
        return $this->activeFactsForTrip($tripId)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function activeFactsForTrip(int $tripId): array
    {
        $builder = $this->db->table('movement_assessments assessments')
            ->select('assessments.id AS assessment_id, assessments.movement_type, assessments.cleanliness, assessments.energy_percent, assessments.captured_at, assessments.source, assessments.actor_user_id, assessments.note')
            ->select('events.id AS event_id, events.event_code, events.occurred_at, events.location_class, events.location_detail')
            ->select('profiles.energy_kind, users.username AS actor_username')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id')
            ->join('vehicle_operational_profiles profiles', 'profiles.fleet_vehicle_id = assessments.fleet_vehicle_id', 'left')
            ->join('users', 'users.id = assessments.actor_user_id', 'left')
            ->where('assessments.turo_trip_normalized_id', $tripId)
            ->where('assessments.voided_at', null)
            ->where('events.voided_at', null);
        if ($this->hasStructuredAirportParking()) {
            $builder->select('events.airport_garage_code, events.airport_parking_level, ' . $this->qualifiedAirportParkingRowSelect('events'));
        }
        return $builder->orderBy('assessments.captured_at', 'DESC')->orderBy('assessments.id', 'DESC')->get()->getResultArray();
    }

    private function movementEventSelect(): string
    {
        $columns = 'id, fleet_vehicle_id, turo_trip_normalized_id, event_code, movement_type, occurred_at, location_class, location_detail, source, note';

        return $this->hasStructuredAirportParking()
            ? $columns . ', airport_garage_code, airport_parking_level, ' . $this->airportParkingRowSelect()
            : $columns;
    }

    private function hasStructuredAirportParking(): bool
    {
        $fields = $this->db->getFieldNames('trip_movement_events');

        return in_array('airport_garage_code', $fields, true)
            && in_array('airport_parking_level', $fields, true)
            && $this->airportParkingRowColumn($fields) !== null;
    }

    /** @param list<string>|null $fields */
    private function airportParkingRowColumn(?array $fields = null): ?string
    {
        $fields ??= $this->db->getFieldNames('trip_movement_events');
        if (in_array('airport_parking_row', $fields, true)) {
            return 'airport_parking_row';
        }

        return in_array('airport_parking_stall', $fields, true) ? 'airport_parking_stall' : null;
    }

    private function airportParkingRowSelect(): string
    {
        $column = $this->airportParkingRowColumn();

        return $column === 'airport_parking_row' ? $column : $column . ' AS airport_parking_row';
    }

    private function qualifiedAirportParkingRowSelect(string $tableAlias): string
    {
        $column = $this->airportParkingRowColumn();

        return $column === 'airport_parking_row'
            ? $tableAlias . '.' . $column
            : $tableAlias . '.' . $column . ' AS airport_parking_row';
    }

    /** @param array<string, mixed>|null $event @return array<string, mixed>|null */
    private function normalizeMovementEvent(?array $event): ?array
    {
        if ($event === null) {
            return null;
        }
        if (! array_key_exists('airport_parking_row', $event) && array_key_exists('airport_parking_stall', $event)) {
            $event['airport_parking_row'] = $event['airport_parking_stall'];
        }
        unset($event['airport_parking_stall']);

        return $event;
    }

    public function createAssessment(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('movement_assessments')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function assessment(int $assessmentId): ?array
    {
        $row = $this->db->table('movement_assessments')->where('id', $assessmentId)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function assessmentsForTrip(int $tripId): array
    {
        return $this->db->table('movement_assessments')->where('turo_trip_normalized_id', $tripId)->where('voided_at', null)->orderBy('captured_at', 'DESC')->get()->getResultArray();
    }

    public function correctAssessment(int $assessmentId, array $replacement, int $actorUserId, string $reason, bool $manageTransaction = true): int
    {
        $original = $this->assessment($assessmentId);
        if ($original === null || $original['voided_at'] !== null) {
            throw new RuntimeException('The assessment cannot be corrected.');
        }
        if ($manageTransaction) {
            $this->db->transBegin();
        }
        try {
            $replacementId = $this->createAssessment(array_merge($replacement, ['supersedes_assessment_id' => $assessmentId]));
            $now = date('Y-m-d H:i:s');
            $this->db->table('movement_assessments')->where('id', $assessmentId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => $reason, 'updated_at' => $now]);
            $this->audit((int) $original['company_id'], 'movement_assessments', $assessmentId, 'superseded', $original, ['replacement_id' => $replacementId, 'reason' => $reason], $actorUserId);
            if ($manageTransaction) {
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Assessment correction transaction failed.');
                }
                $this->db->transCommit();
            }
            return $replacementId;
        } catch (\Throwable $exception) {
            if ($manageTransaction) {
                $this->db->transRollback();
            }
            throw $exception;
        }
    }

    public function voidAssessment(int $assessmentId, int $actorUserId, string $reason): bool
    {
        $assessment = $this->assessment($assessmentId);
        if ($assessment === null || $assessment['voided_at'] !== null || trim($reason) === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('movement_assessments')->where('id', $assessmentId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => trim($reason), 'updated_at' => $now]);
        $this->audit((int) $assessment['company_id'], 'movement_assessments', $assessmentId, 'voided', $assessment, ['reason' => trim($reason)], $actorUserId);
        return $this->db->affectedRows() > 0;
    }

    /** @return array<string, mixed>|null */
    public function profile(int $vehicleId): ?array
    {
        $row = $this->db->table('vehicle_operational_profiles')->where('fleet_vehicle_id', $vehicleId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $capabilities = $this->db->table('vehicle_operational_capabilities')->where('fleet_vehicle_id', $vehicleId)->where('is_applicable', true)->orderBy('id', 'ASC')->get()->getResultArray();
        $row['capabilities'] = array_column($capabilities, 'capability_code');
        return $row;
    }

    public function saveProfile(int $companyId, int $vehicleId, string $energyKind, ?int $target, array $capabilities, int $actorUserId): void
    {
        $this->db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $old = $this->profile($vehicleId);
            if ($old === null) {
                $this->db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => $vehicleId, 'energy_kind' => $energyKind, 'ready_energy_target_percent' => $target, 'created_by' => $actorUserId, 'updated_by' => $actorUserId, 'created_at' => $now, 'updated_at' => $now]);
                $profileId = (int) $this->db->insertID();
            } else {
                $profileId = (int) $old['id'];
                $this->db->table('vehicle_operational_profiles')->where('id', $profileId)->update(['energy_kind' => $energyKind, 'ready_energy_target_percent' => $target, 'updated_by' => $actorUserId, 'updated_at' => $now]);
            }
            foreach (['key_card', 'charging_adapter'] as $code) {
                $existing = $this->db->table('vehicle_operational_capabilities')->where(['fleet_vehicle_id' => $vehicleId, 'capability_code' => $code])->get()->getRowArray();
                $applicable = in_array($code, $capabilities, true);
                if ($existing === null) {
                    $this->db->table('vehicle_operational_capabilities')->insert(['fleet_vehicle_id' => $vehicleId, 'capability_code' => $code, 'is_applicable' => $applicable, 'created_by' => $actorUserId, 'updated_by' => $actorUserId, 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    $this->db->table('vehicle_operational_capabilities')->where('id', $existing['id'])->update(['is_applicable' => $applicable, 'updated_by' => $actorUserId, 'updated_at' => $now]);
                }
            }
            $new = $this->profile($vehicleId);
            $this->audit($companyId, 'vehicle_operational_profiles', $profileId, $old === null ? 'created' : 'updated', $old, $new, $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operational profile transaction failed.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function audit(?int $companyId, string $table, int $recordId, string $action, ?array $old, ?array $new, int $actorUserId): void
    {
        $this->db->table('operational_fact_audits')->insert([
            'company_id' => $companyId, 'table_name' => $table, 'record_id' => $recordId, 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'actor_user_id' => $actorUserId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeLocationKey(string $sourceText): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $sourceText) ?? $sourceText));
    }
}
