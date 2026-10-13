<?php

namespace Tests\Support;

use App\Repositories\LookupRepository;
use CodeIgniter\Database\BaseConnection;

final class VehicleWheelChecklistFixture
{
    public static function prepare(BaseConnection $db): void
    {
        $lookup = new LookupRepository($db);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Honolulu'));
        foreach ([100 => ['+1 day', '+2 days', 'booked'], 102 => ['-3 days', '-2 days', 'completed'], 101 => ['+1 day', '+2 days', 'booked'], 200 => ['+1 day', '+2 days', 'booked']] as $trip => [$start, $end, $status]) {
            $db->table('turo_trips_normalized')->where('id', $trip)->update(['starts_at' => $now->modify($start)->format('Y-m-d H:i:s'), 'ends_at' => $now->modify($end)->format('Y-m-d H:i:s'), 'trip_status_lookup_value_id' => $lookup->valueId('trip_status', $status)]);
        }
        $db->table('turo_trips_normalized')->insert(['id' => 103, 'fleet_vehicle_id' => 10, 'turo_trip_id' => 'SYNTHETIC-NEXT', 'turo_reservation_id' => 'SYNTHETIC-NEXT', 'starts_at' => $now->modify('+3 days')->format('Y-m-d H:i:s'), 'ends_at' => $now->modify('+4 days')->format('Y-m-d H:i:s'), 'trip_status_lookup_value_id' => $lookup->valueId('trip_status', 'booked')]);
        $db->table('trip_movement_events')->where('turo_trip_normalized_id', 100)->update(['voided_at' => $now->format('Y-m-d H:i:s')]);
        foreach ([[100,100,10,'pickup','+1 day',null], [102,102,10,'pickup','-3 days','-2 days'], [103,103,10,'pickup','+3 days',null], [104,100,10,'pickup','-1 day',null], [105,100,10,'return','+2 days',null], [200,200,20,'pickup','+1 day',null]] as [$id,$trip,$vehicle,$direction,$time,$completed]) {
            $db->table('trip_movement_checklists')->insert(['id' => $id,'turo_trip_normalized_id' => $trip,'fleet_vehicle_id' => $vehicle,'movement_type' => $direction,'scheduled_at' => $now->modify($time)->format('Y-m-d H:i:s'),'completed_at' => $completed === null ? null : $now->modify($completed)->format('Y-m-d H:i:s'),'readiness_status' => 'not_started']);
            foreach ((new \App\Services\Fleet\MovementChecklistDefinitionService())->items($direction, false) as $definition) {
                $db->table('trip_movement_checklist_items')->insert(['trip_movement_checklist_id' => $id,'item_code' => $definition['code'],'label' => $definition['label'],'is_required' => $definition['required'],'is_critical' => $definition['critical'],'sort_order' => $definition['sortOrder']]);
            }
        }
    }

    public static function inject(BaseConnection $db): void
    {
        // All project services must see this isolated connection, never the configured development database.
        $property = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $property->setValue(null, ['tests' => $db]);
        \Config\Services::injectMock('operationalFactsRepository', new class ($db) extends \App\Repositories\OperationalFactsRepository {
            public function activeFleetCompanyIds(?string $asOfDate = null): array
            {
                return [1];
            }
        });
        \CodeIgniter\Shield\Config\Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
            public function loggedIn(): bool
            {
                return false;
            }
            public function user(): \CodeIgniter\Shield\Entities\User
            {
                return new class (['id' => 7, 'username' => 'synthetic-wheel-checklist']) extends \CodeIgniter\Shield\Entities\User {
                    public function getEmail(): ?string
                    {
                        return null;
                    }
                };
            }
        });
    }
}
