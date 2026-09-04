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
            ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id')
            ->join('turo_trip_raw raw', 'raw.id = trips.turo_trip_raw_id', 'left')
            ->join('turo_import_batches batches', 'batches.id = raw.turo_import_batch_id', 'left')
            ->join('lookup_values import_statuses', 'import_statuses.id = batches.import_status_lookup_value_id', 'left')
            ->where('trips.fleet_vehicle_id', $vehicleId)->where('trips.starts_at >', $after)->where('trips.deleted_at', null)
            ->whereNotIn('trip_statuses.code', ['completed', 'canceled_zero_payout', 'canceled_host_payout'])
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
        $row = $this->db->table('fleet_vehicles')->select('id, company_id')->where('id', $vehicleId)->where('deleted_at', null)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    public function createEvent(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('trip_movement_events')->insert(array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function event(int $eventId): ?array
    {
        $row = $this->db->table('trip_movement_events')->where('id', $eventId)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    public function correctEvent(int $eventId, array $replacement, int $actorUserId, string $reason): int
    {
        $original = $this->event($eventId);
        if ($original === null || $original['voided_at'] !== null) {
            throw new RuntimeException('The event cannot be corrected.');
        }
        $this->db->transBegin();
        try {
            $replacementId = $this->createEvent(array_merge($replacement, ['supersedes_event_id' => $eventId]));
            $now = date('Y-m-d H:i:s');
            $this->db->table('trip_movement_events')->where('id', $eventId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => $reason, 'updated_at' => $now]);
            $this->audit((int) $original['company_id'], 'trip_movement_events', $eventId, 'superseded', $original, ['replacement_id' => $replacementId, 'reason' => $reason], $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Event correction transaction failed.');
            }
            $this->db->transCommit();
            return $replacementId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
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
    public function latestActiveFactsForTrip(int $tripId): ?array
    {
        $row = $this->db->table('movement_assessments assessments')
            ->select('assessments.id AS assessment_id, assessments.movement_type, assessments.cleanliness, assessments.energy_percent, assessments.captured_at, assessments.source, assessments.actor_user_id, assessments.note')
            ->select('events.id AS event_id, events.event_code, events.occurred_at, events.location_class, events.location_detail')
            ->select('profiles.energy_kind, users.username AS actor_username')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id')
            ->join('vehicle_operational_profiles profiles', 'profiles.fleet_vehicle_id = assessments.fleet_vehicle_id', 'left')
            ->join('users', 'users.id = assessments.actor_user_id', 'left')
            ->where('assessments.turo_trip_normalized_id', $tripId)
            ->where('assessments.voided_at', null)
            ->where('events.voided_at', null)
            ->orderBy('assessments.captured_at', 'DESC')->orderBy('assessments.id', 'DESC')->get(1)->getRowArray();

        return $row === null ? null : $row;
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

    public function correctAssessment(int $assessmentId, array $replacement, int $actorUserId, string $reason): int
    {
        $original = $this->assessment($assessmentId);
        if ($original === null || $original['voided_at'] !== null) {
            throw new RuntimeException('The assessment cannot be corrected.');
        }
        $this->db->transBegin();
        try {
            $replacementId = $this->createAssessment(array_merge($replacement, ['supersedes_assessment_id' => $assessmentId]));
            $now = date('Y-m-d H:i:s');
            $this->db->table('movement_assessments')->where('id', $assessmentId)->update(['voided_at' => $now, 'voided_by_user_id' => $actorUserId, 'void_reason' => $reason, 'updated_at' => $now]);
            $this->audit((int) $original['company_id'], 'movement_assessments', $assessmentId, 'superseded', $original, ['replacement_id' => $replacementId, 'reason' => $reason], $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Assessment correction transaction failed.');
            }
            $this->db->transCommit();
            return $replacementId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
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
}
