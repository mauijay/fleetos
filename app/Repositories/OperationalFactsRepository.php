<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class OperationalFactsRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
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
    public function nextConfirmedTrip(int $vehicleId, string $after): ?array
    {
        $row = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, trip_statuses.code AS trip_status_code')
            ->select('import_statuses.code AS import_status_code, batches.completed_at AS import_completed_at, batches.source_filename AS import_source_filename')
            ->select('pickup.location_class AS pickup_location_class, pickup.source_text AS pickup_location_source_text')
            ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = raw.turo_import_batch_id', 'left')
            ->join('lookup_values import_statuses', 'import_statuses.id = batches.import_status_lookup_value_id', 'left')
            ->join('scheduled_movement_locations pickup', 'pickup.turo_trip_normalized_id = trips.id AND pickup.movement_type = \'pickup\'', 'left')
            ->where('trips.fleet_vehicle_id', $vehicleId)->where('trips.starts_at >', $after)->where('trips.deleted_at', null)
            ->where('trip_statuses.code', 'booked')
            ->orderBy('trips.starts_at', 'ASC')->orderBy('trips.id', 'ASC')->get(1)->getRowArray();
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

    /** @return array<string, mixed>|null */
    public function vehicle(int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles')->where('id', $vehicleId)->where('deleted_at', null)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    public function createEvent(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $fields = array_flip($this->db->getFieldNames('trip_movement_events'));
        $this->db->table('trip_movement_events')->insert(array_intersect_key(array_merge($data, ['created_at' => $now, 'updated_at' => $now]), $fields));
        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function event(int $eventId): ?array
    {
        $row = $this->db->table('trip_movement_events')->where('id', $eventId)->get()->getRowArray();
        return $row === null ? null : $row;
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
        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestActiveMovementEvent(int $vehicleId, ?string $asOf = null): ?array
    {
        $builder = $this->db->table('trip_movement_events')
            ->select($this->movementEventSelect())
            ->where('fleet_vehicle_id', $vehicleId)
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
            ->whereIn('event_code', ['actual_handoff', 'actual_return'])
            ->where('voided_at', null);
        if ($asOf !== null) {
            $builder->where('occurred_at <=', $asOf);
        }
        $row = $builder->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
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
            $builder->select('events.airport_garage_code, events.airport_parking_level, events.airport_parking_row');
        }
        $row = $builder->orderBy('assessments.captured_at', 'DESC')->orderBy('assessments.id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    private function movementEventSelect(): string
    {
        $columns = 'id, fleet_vehicle_id, turo_trip_normalized_id, event_code, movement_type, occurred_at, location_class, location_detail, source, note';

        return $this->hasStructuredAirportParking()
            ? $columns . ', airport_garage_code, airport_parking_level, airport_parking_row'
            : $columns;
    }

    private function hasStructuredAirportParking(): bool
    {
        return in_array('airport_garage_code', $this->db->getFieldNames('trip_movement_events'), true);
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
