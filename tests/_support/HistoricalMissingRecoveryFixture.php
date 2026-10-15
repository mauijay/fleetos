<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;

/** Synthetic identities and observations, independent of any live correction. */
final class HistoricalMissingRecoveryFixture
{
    public const COMPANY = 9101;
    public const VEHICLE = 9201;
    public const TRIP = 9301;
    public const FUTURE = 9302;
    public const ACTOR = 9401;
    public const ADMIN = 9402;

    public static function seed(BaseConnection $db): array
    {
        HistoricalMovementRepairFixture::seed($db);
        $db->table('companies')->insert(['id' => self::COMPANY, 'name' => 'Synthetic Recovery Fleet', 'slug' => 'synthetic-recovery-fleet']);
        $vehicle = $db->table('fleet_vehicles')->where('id', 10)->get()->getRowArray();
        $vehicle['id'] = self::VEHICLE;
        $vehicle['company_id'] = self::COMPANY;
        $vehicle['fleet_code'] = 'SYNTHETIC-RECOVERY';
        $vehicle['display_name'] = 'Synthetic recovery vehicle';
        $db->table('fleet_vehicles')->insert($vehicle);
        $db->table('users')->insertBatch([['id' => self::ACTOR, 'username' => 'synthetic-recovery-superadmin', 'active' => 1], ['id' => self::ADMIN, 'username' => 'synthetic-recovery-admin', 'active' => 1]]);
        $db->table('auth_groups_users')->insertBatch([['user_id' => self::ACTOR, 'group' => 'superadmin'], ['user_id' => self::ADMIN, 'group' => 'admin']]);
        $lookups = new LookupRepository($db);
        $future = (new \DateTimeImmutable('now'))->modify('+8 days')->format('Y-m-d');
        foreach ([[9300, '2023-06-03 10:00:00', '2023-06-05 10:00:00', 'completed'], [self::TRIP, '2023-06-07 10:00:00', '2023-06-09 16:00:00', 'in_progress'], [self::FUTURE, $future . ' 11:00:00', $future . ' 17:00:00', 'booked']] as [$id, $start, $end, $code]) {
            $db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => self::VEHICLE,
                'turo_trip_id' => 'SYNTHETIC-RECOVERY-' . $id, 'turo_reservation_id' => 'SYNTHETIC-RECOVERY-RES-' . $id,
                'starts_at' => $start, 'ends_at' => $end, 'trip_status_lookup_value_id' => $lookups->valueId('trip_status', $code)]);
        }
        $facts = new OperationalFactsRepository($db);
        $events = new MovementEventService($facts);
        $assessments = new MovementAssessmentService($facts);
        $events->record(self::VEHICLE, 9300, 'actual_handoff', 'pickup', '2023-06-03 10:05:00', 'home', 'Synthetic prior origin', 'synthetic_operator', self::ACTOR);
        $events->record(self::VEHICLE, 9300, 'vehicle_recovered', 'return', '2023-06-05 10:00:00', 'home', 'Synthetic prior depot', 'synthetic_operator', self::ACTOR);
        $handoff = $events->record(self::VEHICLE, self::TRIP, 'actual_handoff', 'pickup', '2023-06-07 10:18:00', 'other_delivery', 'Synthetic Meadow Lodge', 'synthetic_operator', self::ACTOR);
        $assessment = $assessments->record(self::VEHICLE, self::TRIP, $handoff, 'pickup', 'clean', 72, '2023-06-07 10:18:00', 'synthetic_operator', self::ACTOR);
        $observed = $events->record(self::VEHICLE, null, 'vehicle_readiness_observed', null, '2023-06-08 11:00:00', null, null, 'synthetic_operator', self::ACTOR);
        $assessments->record(self::VEHICLE, null, $observed, 'current', 'clean', 78, '2023-06-08 11:00:00', 'synthetic_operator', self::ACTOR);
        $db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => self::VEHICLE, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 75, 'created_by' => self::ACTOR, 'updated_by' => self::ACTOR]);
        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($db));
        // A completed prior return still shows inactive preparation for the
        // already-handed-off target. Its explanatory membership may retire
        // when this missing recovery canonically closes the target interval.
        $checklists->ensureForMovement($facts->tripSchedule(9300), 'return');
        $pickup = $checklists->ensureForMovement($facts->tripSchedule(self::TRIP), 'pickup');
        $return = $checklists->ensureForMovement($facts->tripSchedule(self::TRIP), 'return');
        foreach (['pickup', 'return'] as $type) {
            $checklists->ensureForMovement($facts->tripSchedule(self::FUTURE), $type);
        }
        $db->table('turo_import_batches')->insert(['import_type_lookup_value_id' => $lookups->valueId('import_type', 'turo_extras'), 'source_hash' => hash('sha256', 'synthetic-recovery-empty'), 'created_at' => gmdate('Y-m-d H:i:s')]);
        $batch = (int) $db->insertID();
        foreach ([9300, self::TRIP, self::FUTURE] as $id) {
            $db->table('turo_extra_reservation_snapshots')->insert(['company_id' => self::COMPANY, 'turo_import_batch_id' => $batch, 'turo_trip_normalized_id' => $id,
                'turo_reservation_id' => 'SYNTHETIC-RECOVERY-RES-' . $id, 'snapshot_complete' => 1, 'observed_at' => gmdate('Y-m-d H:i:s', time() - 60),
                'source_payload_hash' => hash('sha256', 'synthetic-recovery-empty-' . $id), 'source_payload' => '{"extras":[]}', 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
        return ['operation_uuid' => HistoricalMissingHandoffFixture::uuid(), 'company_id' => self::COMPANY, 'vehicle_id' => self::VEHICLE, 'trip_id' => self::TRIP,
            'pickup_checklist_id' => (int) $pickup['id'], 'return_checklist_id' => (int) $return['id'], 'handoff_event_id' => $handoff, 'handoff_assessment_id' => $assessment,
            'recovery' => ['occurred_at' => '2023-06-09 15:22:00', 'location_class' => 'other_delivery', 'location_detail' => 'Synthetic Harbor Depot', 'location_note' => 'Synthetic covered bay'],
            'assessment' => ['cleanliness' => 'clean', 'energy_percent' => 67, 'note' => 'Synthetic operator observed an exceptionally clean interior.'],
            'reason' => 'Synthetic archive found an omitted recovery.', 'attestation' => 'Synthetic operator attests the historical recovery, location and observations.'];
    }

    public static function laterPositions(BaseConnection $db): int
    {
        $events = new MovementEventService(new OperationalFactsRepository($db));
        $events->record(self::VEHICLE, null, 'vehicle_positioned', null, '2023-06-09 15:45:00', 'home', 'Synthetic garage', 'synthetic_operator', self::ACTOR);
        return $events->record(self::VEHICLE, null, 'vehicle_positioned', null, '2023-06-10 12:20:00', 'airport_hnl', 'International Garage L3 RG', 'synthetic_operator', self::ACTOR, 'Synthetic storage');
    }
}
