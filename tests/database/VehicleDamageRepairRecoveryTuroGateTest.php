<?php

namespace Tests\Database;

use App\Services\Fleet\VehicleDamageRepairRecoveryTuroSource as TuroSource;
use Tests\Support\VehicleDamageRepairRecoveryTestCase;

final class VehicleDamageRepairRecoveryTuroGateTest extends VehicleDamageRepairRecoveryTestCase
{
    public function testFixtureLabelsDoNotEstablishPaidAuthorityAndCannotActivateAdapter(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/../fixtures/turo/b31-unproven-recoveries.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $row) {
            try {
                TuroSource::recognize($row + ['id' => 1, 'external_transaction_id' => 'SYNTHETIC-TXN', 'fleet_vehicle_id' => 10], ['raw_payload' => $row]);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(TuroSource::UNAVAILABLE, $e->getMessage());
            }
        }
    }

    public function testTuroCommandsAndDisguisedManualSourcesFailWithoutAnyWrite(): void
    {
        $j = $this->createWork([$this->condition()]);
        $before = $this->counts();
        foreach ([['authority_code' => 'turo_transaction'], ['source_type' => 'turo_reimbursement'], ['turo_transaction_normalized_id' => 1], ['payer_snapshot' => 'Turo'], ['source_namespace' => 'payment_processor:turo']] as $change) {
            $this->failure($this->receipt($j, $change));
            $this->assertSame($before, $this->counts());
        }
        $this->assertFalse($this->economics($j)['turo_enabled']);
        $this->assertSame(0, $this->connection->table('vehicle_damage_repair_recovery_entries')->countAllResults());
    }
}
