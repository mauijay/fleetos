<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use App\Services\Fleet\FleetOperationalReconciliationService;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/** Synthetic identities only; shared cases run on real SQLite and MariaDB schemas. */
final class OperationalReconciliationFixture
{
    public const VEHICLE = 1000;
    public const TRIP = 10000;

    public static function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-02 10:00:00-10:00');
    }

    public static function seed(BaseConnection $db): void
    {
        GuestCommitmentProjectionFixture::seed($db);
        self::vehicle($db, self::VEHICLE);
        self::trip($db);
    }

    public static function vehicle(BaseConnection $db, int $id, int $company = 1): void
    {
        $db->table('fleet_vehicles')->insert([
            'id' => $id, 'company_id' => $company, 'vehicle_spec_id' => 1,
            'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1,
            'fleet_code' => 'SYNTHETIC-RECON-' . $id, 'display_name' => 'Synthetic Reconciliation ' . $id,
        ]);
    }

    public static function trip(BaseConnection $db, int $id = self::TRIP, int $vehicle = self::VEHICLE, string $status = 'in_progress', string $start = '2030-01-02 08:00:00', string $end = '2030-01-03 08:00:00'): void
    {
        $db->table('turo_trips_normalized')->insert([
            'id' => $id, 'fleet_vehicle_id' => $vehicle, 'turo_trip_id' => 'SYNTHETIC-RECON-TRIP-' . $id, 'turo_reservation_id' => 'SYNTHETIC-RECON-RESERVATION-' . $id,
            'trip_status_lookup_value_id' => (new LookupRepository($db))->valueId('trip_status', $status),
            'starts_at' => $start, 'ends_at' => $end,
        ]);
    }

    public static function status(BaseConnection $db, string $status, int $trip = self::TRIP): void
    {
        $db->table('turo_trips_normalized')->where('id', $trip)->update(['trip_status_lookup_value_id' => (new LookupRepository($db))->valueId('trip_status', $status)]);
    }

    public static function event(BaseConnection $db, string $code, string $at = '2030-01-02 08:00:00', ?int $trip = self::TRIP, int $vehicle = self::VEHICLE, ?string $location = 'home', array $changes = []): int
    {
        $db->table('trip_movement_events')->insert(array_merge([
            'company_id' => 1, 'fleet_vehicle_id' => $vehicle, 'turo_trip_normalized_id' => $trip, 'event_code' => $code,
            'movement_type' => in_array($code, ['vehicle_recovered', 'actual_return', 'guest_return_staged'], true) ? 'return' : 'pickup',
            'occurred_at' => $at, 'location_class' => $location, 'source' => 'operator', 'actor_user_id' => 7,
        ], $changes));
        return (int) $db->insertID();
    }

    public static function checklist(BaseConnection $db, int $id = 20000, string $at = '2030-01-02 08:00:00', string $direction = 'pickup', int $trip = self::TRIP, int $vehicle = self::VEHICLE): void
    {
        $db->table('trip_movement_checklists')->insert(['id' => $id, 'turo_trip_normalized_id' => $trip, 'fleet_vehicle_id' => $vehicle, 'movement_type' => $direction, 'scheduled_at' => $at, 'readiness_status' => 'not_started']);
    }

    public static function read(BaseConnection $db): array
    {
        return (new FleetOperationalReconciliationService($db))->forVehicles(1, [self::VEHICLE], self::clock())->vehicles[self::VEHICLE];
    }

    public static function fingerprint(BaseConnection $db): array
    {
        $result = [];
        foreach ($db->listTables() as $table) {
            $rows = $db->table($table)->get()->getResultArray();
            $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded);
            $result[$table] = hash('sha256', implode("\n", $encoded));
        }
        ksort($result);
        return $result;
    }
}
