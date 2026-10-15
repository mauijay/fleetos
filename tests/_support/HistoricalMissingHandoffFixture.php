<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;

/** New synthetic identities; no motivating production identifiers or measurements. */
final class HistoricalMissingHandoffFixture
{
    public const COMPANY = 8101;
    public const VEHICLE = 8201;
    public const TRIP = 8301;
    public const ACTOR = 8401;
    public const ADMIN = 8402;

    public static function seed(BaseConnection $db, bool $returnReport = true, string $status = 'in_progress'): array
    {
        HistoricalMovementRepairFixture::seed($db);
        $db->table('companies')->insert(['id' => self::COMPANY, 'name' => 'Synthetic Archive Fleet', 'slug' => 'synthetic-archive-fleet']);
        $vehicle = $db->table('fleet_vehicles')->where('id', 10)->get()->getRowArray();
        $vehicle['id'] = self::VEHICLE;
        $vehicle['company_id'] = self::COMPANY;
        $vehicle['fleet_code'] = 'SYNTHETIC-ARCHIVE';
        $vehicle['display_name'] = 'Synthetic archive vehicle';
        $db->table('fleet_vehicles')->insert($vehicle);
        $db->table('users')->insertBatch([['id' => self::ACTOR, 'username' => 'synthetic-archive-superadmin', 'active' => 1], ['id' => self::ADMIN, 'username' => 'synthetic-archive-admin', 'active' => 1]]);
        $db->table('auth_groups_users')->insertBatch([['user_id' => self::ACTOR, 'group' => 'superadmin'], ['user_id' => self::ADMIN, 'group' => 'admin']]);
        $lookups = new LookupRepository($db);
        $future = (new \DateTimeImmutable('now'))->modify('+10 days')->format('Y-m-d');
        foreach ([[8300, '2024-03-10 09:00:00', '2024-03-12 09:00:00', 'completed'], [self::TRIP, '2024-03-14 11:00:00', '2024-03-17 13:00:00', $status], [8302, $future . ' 09:00:00', $future . ' 17:00:00', 'booked']] as [$id, $start, $end, $code]) {
            $db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => self::VEHICLE,
                'turo_trip_id' => 'SYNTHETIC-ARCHIVE-' . $id, 'turo_reservation_id' => 'SYNTHETIC-ARCHIVE-RES-' . $id,
                'starts_at' => $start, 'ends_at' => $end, 'trip_status_lookup_value_id' => $lookups->valueId('trip_status', $code)]);
        }
        $facts = new OperationalFactsRepository($db);
        $events = new MovementEventService($facts);
        $events->record(self::VEHICLE, 8300, 'vehicle_recovered', 'return', '2024-03-12 09:00:00', 'home', 'Synthetic prior depot', 'synthetic_operator', self::ACTOR);
        if ($returnReport) {
            $events->record(self::VEHICLE, self::TRIP, 'guest_return_staged', 'return', '2024-03-17 13:15:00', 'airport_hnl', 'International Garage L2 RF', 'synthetic_report', self::ACTOR);
        }
        $recovery = $events->record(self::VEHICLE, self::TRIP, 'vehicle_recovered', 'return', '2024-03-17 14:00:00', 'airport_hnl', 'International Garage L2 RF', 'synthetic_operator', self::ACTOR);
        $assessment = (new MovementAssessmentService($facts))->record(self::VEHICLE, self::TRIP, $recovery, 'return', null, 61, '2024-03-17 14:00:00', 'synthetic_operator', self::ACTOR);
        $events->record(self::VEHICLE, self::TRIP, 'vehicle_positioned', null, '2024-03-17 14:20:00', 'home', 'Synthetic garage', 'synthetic_operator', self::ACTOR);
        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($db));
        $pickup = $checklists->ensureForMovement($facts->tripSchedule(self::TRIP), 'pickup');
        $return = $checklists->ensureForMovement($facts->tripSchedule(self::TRIP), 'return');
        foreach (['pickup', 'return'] as $type) {
            $checklists->ensureForMovement($facts->tripSchedule(8302), $type);
        }
        self::freshExtras($db);
        return ['operation_uuid' => self::uuid(), 'company_id' => self::COMPANY, 'vehicle_id' => self::VEHICLE, 'trip_id' => self::TRIP,
            'pickup_checklist_id' => (int) $pickup['id'], 'return_checklist_id' => (int) $return['id'],
            'recovery_event_id' => $recovery, 'recovery_assessment_id' => $assessment,
            'handoff' => ['occurred_at' => '2024-03-14 11:27:00', 'location_class' => 'airport_hnl',
                'airport_garage_code' => 'international', 'airport_parking_level' => 2, 'airport_parking_row' => 'F', 'location_note' => 'Synthetic charging bay'],
            'reason' => 'Synthetic archive review found an omitted guest handoff.', 'attestation' => 'Synthetic operator attests this occurrence and location; no pickup measurements were captured.'];
    }

    /** Closed owner, two immutable workspace snapshots and an independent future pickup. */
    public static function historicalAffordances(BaseConnection $db): array
    {
        $facts = new OperationalFactsRepository($db);
        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($db));
        $old = $checklists->ensureForMovement($facts->tripSchedule(8300), 'return');
        $duplicate = $db->table('trip_movement_checklists')->where('id', $old['id'])->get()->getRowArray();
        unset($duplicate['id']);
        $duplicate['scheduled_at'] = '2024-03-12 08:00:00';
        $db->table('trip_movement_checklists')->insert($duplicate);
        $ids = [(int) $old['id'], (int) $db->insertID()];
        $db->table('vehicle_operational_profiles')->insert(['fleet_vehicle_id' => self::VEHICLE,
            'energy_kind' => 'electric', 'ready_energy_target_percent' => 80, 'created_by' => self::ACTOR, 'updated_by' => self::ACTOR]);
        return $ids;
    }

    public static function freshExtras(BaseConnection $db): void
    {
        $db->table('turo_import_batches')->insert(['import_type_lookup_value_id' => (new LookupRepository($db))->valueId('import_type', 'turo_extras'), 'source_hash' => hash('sha256', 'synthetic-empty'), 'created_at' => gmdate('Y-m-d H:i:s')]);
        $batch = (int) $db->insertID();
        $db->table('turo_extra_reservation_snapshots')->insert([
            'company_id' => self::COMPANY, 'turo_import_batch_id' => $batch, 'turo_trip_normalized_id' => self::TRIP,
            'created_at' => gmdate('Y-m-d H:i:s'), 'source_payload_hash' => hash('sha256', 'synthetic-empty'),
            'turo_reservation_id' => 'SYNTHETIC-ARCHIVE-RES-' . self::TRIP,
            'observed_at' => gmdate('Y-m-d H:i:s', time() - 60), 'snapshot_complete' => 1,
            'source_payload' => json_encode(['extras' => []], JSON_THROW_ON_ERROR),
        ]);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $h = bin2hex($bytes);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
