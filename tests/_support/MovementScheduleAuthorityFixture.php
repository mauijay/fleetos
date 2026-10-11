<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use CodeIgniter\Database\BaseConnection;

/** Invented changed reservation; no guest-specific application behavior. */
final class MovementScheduleAuthorityFixture
{
    public static function seed(BaseConnection $db): void
    {
        MovementPresentationFixture::seed($db, 'valid');
        $db->table('turo_trips_normalized')->where('id', 102)->update([
            'starts_at' => '2026-10-07 08:30:00', 'ends_at' => '2026-10-08 23:00:00',
            'trip_status_lookup_value_id' => (new LookupRepository($db))->valueId('trip_status', 'completed'),
        ]);
        $db->table('trip_movement_checklists')->where('id', 102)->update(['scheduled_at' => '2026-10-07 10:30:00']);
        $db->table('trip_movement_checklists')->insert([
            'id' => 302, 'turo_trip_normalized_id' => 102, 'fleet_vehicle_id' => 10,
            'movement_type' => 'pickup', 'scheduled_at' => '2026-10-07 08:30:00', 'readiness_status' => 'not_started',
        ]);
    }

    /** @return array<string, array{count:int,sha256:string}> */
    public static function fingerprints(BaseConnection $db): array
    {
        $result = [];
        foreach ($db->listTables() as $table) {
            $rows = $db->table($table)->get()->getResultArray();
            $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
        }

        return $result;
    }
}
