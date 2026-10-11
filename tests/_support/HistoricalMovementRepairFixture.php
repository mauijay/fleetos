<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;

/** Entirely synthetic, using the existing application schema. */
final class HistoricalMovementRepairFixture
{
    public static function seed(BaseConnection $db): array
    {
        VehicleDamageDatabaseFixture::seed($db);
        $forge = \Config\Database::forge($db);
        if (! $db->tableExists('users')) {
            $forge->addField(['id' => ['type' => 'INT', 'constraint' => 11], 'username' => ['type' => 'VARCHAR', 'constraint' => 100], 'active' => ['type' => 'INT', 'default' => 1], 'status' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true]]);
            $forge->addKey('id', true);
            $forge->createTable('users');
        }
        foreach (['auth_groups_users' => 'group', 'auth_permissions_users' => 'permission'] as $table => $column) {
            $forge->addField(['id' => ['type' => 'INT', 'auto_increment' => true], 'user_id' => ['type' => 'INT'], $column => ['type' => 'VARCHAR', 'constraint' => 100]]);
            $forge->addKey('id', true);
            $forge->createTable($table, true);
        }
        $db->table('users')->insertBatch([['id' => 7, 'username' => 'synthetic-superadmin', 'active' => 1], ['id' => 8, 'username' => 'synthetic-admin', 'active' => 1]]);
        $db->table('auth_groups_users')->insertBatch([['user_id' => 7, 'group' => 'superadmin'], ['user_id' => 8, 'group' => 'admin']]);
        $lookups = new LookupRepository($db);
        foreach ([[3001, '2025-10-07 08:30:00', '2025-10-08 23:00:00', 'completed'], [3002, '2025-10-09 10:00:00', '2025-10-10 17:30:00', 'completed'], [3003, '2025-10-10 21:30:00', '2025-10-24 07:00:00', 'in_progress']] as [$id, $start, $end, $status]) {
            $db->table('turo_trips_normalized')->insert(['id' => $id, 'fleet_vehicle_id' => 10, 'turo_trip_id' => 'SYNTHETIC-' . $id, 'turo_reservation_id' => 'SYNTHETIC-RESERVATION-' . $id, 'starts_at' => $start, 'ends_at' => $end, 'trip_status_lookup_value_id' => $lookups->valueId('trip_status', $status)]);
        }
        // Earlier synthetic damage recovery fixtures must precede this interval.
        $db->table('trip_movement_events')->where('fleet_vehicle_id', 10)->update(['occurred_at' => '2025-10-02 21:22:00']);
        $facts = new OperationalFactsRepository($db);
        $events = new MovementEventService($facts);
        (new MovementAssessmentService($facts))->record(10, 100, 1000, 'return', 'clean', 79, '2025-10-02 21:22:00', 'operator', 7);
        $staging = $events->record(10, 3001, 'vehicle_staged', 'pickup', '2025-10-07 09:31:00', 'airport_hnl', 'Synthetic airport', 'operator', 7);
        $assessment = (new MovementAssessmentService($facts))->record(10, 3001, $staging, 'pickup', 'clean', 80, '2025-10-07 09:31:00', 'operator', 7);
        $laterStage = $events->record(10, 3003, 'vehicle_staged', 'pickup', '2025-10-10 19:44:00', 'airport_hnl', 'Synthetic airport', 'operator', 7);
        (new MovementAssessmentService($facts))->record(10, 3003, $laterStage, 'pickup', 'clean', 85, '2025-10-10 19:44:00', 'operator', 7);
        $later = $events->record(10, 3003, 'actual_handoff', 'pickup', '2025-10-10 21:35:00', 'airport_hnl', 'Synthetic airport', 'operator', 7);
        $checklists = new TripMovementChecklistService(new MovementChecklistRepository($db));
        $trip = $facts->tripSchedule(3001);
        $pickup = $checklists->ensureForMovement($trip, 'pickup');
        $return = $checklists->ensureForMovement($trip, 'return');
        $checklists->ensureForMovement($facts->tripSchedule(3003), 'pickup');
        $checklists->ensureForMovement($facts->tripSchedule(3003), 'return');
        return [
            'company_id' => 1, 'vehicle_id' => 10, 'trip_id' => 3001,
            'pickup_checklist_id' => (int) $pickup['id'], 'return_checklist_id' => (int) $return['id'],
            'handoff' => ['occurred_at' => '2025-10-07 08:25:00', 'location_class' => 'other_delivery', 'location_detail' => 'Archive Lodge'],
            'recovery' => ['occurred_at' => '2025-10-08 21:06:00', 'location_class' => 'other_delivery', 'location_detail' => 'Archive Lodge'],
            'reason' => 'Synthetic operator attestation of the closed historical interval; measurements were not captured.',
            'context' => 'synthetic-closed-interval-review', 'assessment_mode' => 'not_captured',
            'expected_authority' => ['custody_event_id' => $later, 'position_event_id' => $later, 'latest_movement_event_id' => $later],
            'invalid_fact' => ['event_id' => $staging, 'assessment_id' => $assessment, 'reason' => 'Synthetic staging conflicts with attested handoff; no defensible replacement staging time.'],
        ];
    }

    public static function snapshot(BaseConnection $db): array
    {
        $result = [];
        foreach ($db->listTables() as $table) {
            $result[$table] = $db->table($table)->get()->getResultArray();
        }
        return $result;
    }
}
