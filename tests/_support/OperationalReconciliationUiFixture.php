<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/** Synthetic authorities shared by GET integration tests and the loopback browser harness. */
final class OperationalReconciliationUiFixture
{
    public static function configure(BaseConnection $db, string $scenario, ?DateTimeImmutable $clock = null): void
    {
        $clock = ($clock ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Pacific/Honolulu'));
        $at = static fn (string $offset): string => $clock->modify($offset)->format('Y-m-d H:i:s');
        $db->table('fleet_vehicles')->whereIn('id', [10, 11, 20])->update(['out_of_service_date' => '2020-01-01']);
        OperationalReconciliationFixture::vehicle($db, 1000);
        if ($scenario === 'healthy') {
            OperationalReconciliationFixture::event($db, 'vehicle_positioned', $at('-1 hour'), null);
            return;
        }
        OperationalReconciliationFixture::trip($db, start: $at('-2 hours'), end: $at('+1 day'));
        OperationalReconciliationFixture::checklist($db, at: $at('-2 hours'));
        if ($scenario === 'p0' || $scenario === 'pagination') {
            OperationalReconciliationFixture::trip($db, 10001, 1000, 'completed', $at('-5 days'), $at('-3 days'));
            OperationalReconciliationFixture::event($db, 'actual_handoff', $at('-5 days'), 10001);
        } elseif ($scenario === 'p1') {
            OperationalReconciliationFixture::event($db, 'actual_handoff', $at('-2 hours'));
            OperationalReconciliationFixture::event($db, 'vehicle_recovered', $at('-1 hour'));
            OperationalReconciliationFixture::trip($db, 10001, 1000, 'booked', $at('+6 hours'), $at('+2 days'));
            OperationalReconciliationFixture::checklist($db, 20001, $at('+6 hours'), 'pickup', 10001);
        } elseif ($scenario === 'checklist') {
            OperationalReconciliationFixture::event($db, 'actual_handoff', $at('-2 hours'));
            OperationalReconciliationFixture::checklist($db, 20001, $at('-3 hours'));
        } elseif ($scenario === 'historical') {
            OperationalReconciliationFixture::status($db, 'completed');
            $db->table('turo_trips_normalized')->where('id', 10000)->update(['starts_at' => $at('-5 days'), 'ends_at' => $at('-3 days')]);
            $db->table('trip_movement_checklists')->where('id', 20000)->update(['scheduled_at' => $at('-5 days')]);
            OperationalReconciliationFixture::event($db, 'actual_handoff', $at('-5 days'));
            OperationalReconciliationFixture::event($db, 'vehicle_recovered', $at('-3 days'));
            GuestCommitmentProjectionFixture::snapshot($db, [GuestCommitmentProjectionFixture::item()], $clock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), true, true, 10000);
        }
        if ($scenario === 'pagination') {
            for ($id = 1002; $id <= 1035; $id++) {
                OperationalReconciliationFixture::vehicle($db, $id);
                OperationalReconciliationFixture::event($db, 'vehicle_positioned', $at('-1 hour'), null, $id, null);
            }
        }
    }
}
