<?php

namespace Tests\Support;

use App\Repositories\GuestCommitmentProjectionRepository;
use App\Repositories\TripCommitmentRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use App\Services\Fleet\GuestCommitmentProjectionService;
use App\Services\Fleet\TripCommitmentService;
use App\Services\Fleet\TripExtraFulfillmentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/** Synthetic data on real application migrations, for SQLite, MariaDB and browser gates. */
final class GuestCommitmentProjectionFixture
{
    public const OBSERVED = '2030-01-02 18:00:00';

    public static function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-02 10:00:00-10:00');
    }

    public static function sqlite(): BaseConnection
    {
        $config = (new Database())->tests;
        $config['database'] = ':memory:';
        $config['DBPrefix'] = '';
        $db = Database::connect($config, false);
        VehicleDamageDatabaseFixture::migrate($db);
        VehicleDamageDatabaseFixture::seed($db);
        self::seed($db);
        return $db;
    }

    public static function seed(BaseConnection $db): void
    {
        $db->table('turo_trips_normalized')->where('id', 100)->update([
            'turo_trip_id' => '80000100', 'turo_reservation_id' => '80000100',
            'starts_at' => '2030-01-03 08:00:00', 'ends_at' => '2030-01-05 09:00:00',
        ]);
        $db->table('fleet_extras')->insert([
            'id' => 301, 'company_id' => 1, 'code' => 'synthetic-pack', 'display_name' => 'Synthetic Beach Gear',
            'active' => 1, 'sort_order' => 1, 'fulfillment_type' => 'pack', 'fulfillment_phase' => 'preparation',
            'requires_operator_confirmation' => 1, 'readiness_blocking' => 1, 'default_action_label' => 'Pack {quantity} synthetic sets',
            'created_at' => self::OBSERVED, 'updated_at' => self::OBSERVED,
        ]);
        $db->table('fleet_extra_source_mappings')->insert([
            'company_id' => 1, 'source_system' => 'turo', 'source_extra_id' => '900001', 'fleet_extra_id' => 301,
            'first_seen_at' => self::OBSERVED, 'last_seen_at' => self::OBSERVED, 'created_at' => self::OBSERVED, 'updated_at' => self::OBSERVED,
        ]);
        self::snapshot($db, [self::item()], self::OBSERVED, true, true);
    }

    public static function service(BaseConnection $db): GuestCommitmentProjectionService
    {
        return new GuestCommitmentProjectionService(new GuestCommitmentProjectionRepository($db), self::fulfillments($db), new TripCommitmentService(new TripCommitmentRepository($db)));
    }

    public static function fulfillments(BaseConnection $db): TripExtraFulfillmentService
    {
        return new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($db), self::clock());
    }

    public static function item(array $changes = []): array
    {
        return array_merge([
            'extra_id' => '900001', 'reservation_state_extra_id' => '910001', 'reservation_state_id' => null,
            'type' => 'BEACH_GEAR', 'label' => 'Synthetic source gear', 'description' => 'Synthetic fixture only',
            'price' => '12.00', 'quantity' => '2.000', 'currency' => 'USD', 'pricing_type' => 'PER_TRIP',
        ], $changes);
    }

    public static function snapshot(BaseConnection $db, array $items, string $observed, bool $complete = true, bool $apply = false, int $tripId = 100): int
    {
        $trip = $db->table('turo_trips_normalized')->where('id', $tripId)->get()->getRowArray();
        $reservation = (string) $trip['turo_reservation_id'];
        $db->table('turo_import_batches')->insert(['source_hash' => hash('sha256', 'synthetic-' . random_bytes(16))]);
        $batch = (int) $db->insertID();
        $payload = json_encode(['reservation_id' => $reservation, 'extras' => $items], JSON_THROW_ON_ERROR);
        $db->table('turo_extra_reservation_snapshots')->insert([
            'company_id' => 1, 'turo_import_batch_id' => $batch, 'turo_trip_normalized_id' => $tripId, 'turo_reservation_id' => $reservation,
            'snapshot_complete' => $complete ? 1 : 0, 'observed_at' => $observed, 'source_payload_hash' => hash('sha256', $payload),
            'source_payload' => $payload, 'created_at' => '2030-01-02 19:00:00',
        ]);
        $id = (int) $db->insertID();
        if (! $apply) {
            return $id;
        }
        $db->table('turo_extra_selections')->where('turo_trip_normalized_id', $tripId)->update(['removed_at' => $observed]);
        foreach ($items as $item) {
            $existing = $db->table('turo_extra_selections')->where('company_id', 1)->where('turo_reservation_id', $reservation)
                ->where('reservation_state_extra_id', $item['reservation_state_extra_id'])->get()->getRowArray();
            $values = [
                'company_id' => 1, 'turo_trip_normalized_id' => $tripId, 'turo_reservation_id' => $reservation,
                'last_snapshot_id' => $id, 'source_extra_id' => $item['extra_id'], 'reservation_state_extra_id' => $item['reservation_state_extra_id'],
                'source_label' => $item['label'], 'quantity' => $item['quantity'], 'unit_price' => $item['price'], 'currency_code' => $item['currency'],
                'removed_at' => null, 'last_observed_at' => $observed, 'source_payload_hash' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
                'source_payload' => json_encode($item, JSON_THROW_ON_ERROR), 'updated_at' => $observed,
            ];
            if ($existing === null) {
                $db->table('turo_extra_selections')->insert(array_merge($values, ['first_snapshot_id' => $id, 'first_observed_at' => $observed, 'created_at' => $observed]));
            } else {
                $db->table('turo_extra_selections')->where('id', $existing['id'])->update($values);
            }
        }
        return $id;
    }

    public static function manual(BaseConnection $db, array $changes = []): int
    {
        $db->table('fleet_trip_commitments')->insert(array_merge([
            'company_id' => 1, 'turo_trip_normalized_id' => 100, 'category' => 'guest_amenity', 'instruction' => 'Synthetic manual child seat request',
            'applies_during' => 'preparation', 'handling_mode' => 'task', 'required_before_dispatch' => 1, 'state' => 'active',
            'created_by_user_id' => 7, 'updated_by_user_id' => 7, 'created_at' => self::OBSERVED, 'updated_at' => self::OBSERVED,
        ], $changes));
        return (int) $db->insertID();
    }

    public static function handoff(BaseConnection $db, array $changes = []): int
    {
        $db->table('trip_movement_events')->insert(array_merge([
            'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff',
            'occurred_at' => '2030-01-02 09:00:00', 'source' => 'operator', 'actor_user_id' => 7,
        ], $changes));
        return (int) $db->insertID();
    }
}
