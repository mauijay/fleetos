<?php

use App\Repositories\LookupRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairAuthorizationTest extends VehicleDamageRepairTestCase
{
    public function testIdentifiersNeverAuthorizeCrossCompanyVehicleJobMembershipOrDeletedVehicle(): void
    {
        $id = $this->condition();
        $job = $this->createWork([$id]);
        $before = $this->counts();
        $command = $this->command($job, ['started_at' => '2026-10-01T09:00']);
        $this->failure($this->work->start(2, 20, $job, $command, 7));
        $this->failure($this->work->start(1, 11, $job, $command, 7));
        $this->failure($this->work->start(1, 10, $job + 500, $command, 7));
        $this->assertNull($this->repairs->job(2, 20, $job));
        $this->assertSame([], $this->repairs->events(1, 11, $job));
        $this->assertNull($this->repairs->event(2, 20, (int) $this->repairs->events(1, 10, $job)[0]['id']));
        $this->failure($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => 999, 'reason' => 'Synthetic unauthorized scope']), 7));
        $foreign = $this->condition(2, 20);
        $count = $this->counts();
        $this->failure($this->work->createJob(1, 10, Fixture::creation($this->connection, [$foreign]), 7));
        $this->assertSame($count, $this->counts());
        $this->connection->table('fleet_vehicles')->where('id', 10)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->failure($this->work->start(1, 10, $job, $command, 7));
        $this->assertNull($this->repairs->job(1, 10, $job));
        $this->assertSame($before['vehicle_damage_repair_job_events'], $this->counts()['vehicle_damage_repair_job_events']);
    }

    public function testVendorSelectorRequiresOwnedExistingRelationshipAndCapturesSnapshot(): void
    {
        $vendorType = (new LookupRepository($this->connection))->valueId('company_type', 'vendor');
        $this->connection->table('companies')->insertBatch([
            ['id' => 30, 'name' => 'Synthetic Allowed Vendor', 'slug' => 'synthetic-allowed-vendor', 'company_type_lookup_value_id' => $vendorType, 'is_active' => 1],
            ['id' => 31, 'name' => 'Synthetic Other Vendor', 'slug' => 'synthetic-other-vendor', 'company_type_lookup_value_id' => $vendorType, 'is_active' => 1],
        ]);
        $id = $this->condition();
        $this->assertSame([], $this->repairs->vendors(1));
        $data = Fixture::creation($this->connection, [$id]);
        $data['vendor_company_id'] = 31;
        $this->failure($this->work->createJob(1, 10, $data, 7), 'owned business relationship');
        // Establish the permitted relationship using a synthetic existing job, not a B2 vendor-creation flow.
        $job = $this->createWork([$id]);
        $this->connection->table('vehicle_damage_repair_jobs')->where('id', $job)->update(['vendor_company_id' => 30]);
        $this->assertSame([30], array_map('intval', array_column($this->repairs->vendors(1), 'id')));
        $other = $this->createWork([$id], ['vendor_company_id' => 30]);
        $this->assertSame('Synthetic Allowed Vendor', $this->repairs->job(1, 10, $other)['vendor_snapshot']);
        $this->assertSame([], $this->repairs->vendors(2));
        $this->connection->table('companies')->where('id', 30)->update(['is_active' => 0]);
        $this->assertSame([], $this->repairs->vendors(1));
    }

    public function testIncompleteSchemaExplicitlyRefusesWritesAndCanonicalGuard(): void
    {
        $id = $this->condition();
        $this->connection->query('ALTER TABLE ' . $this->connection->prefixTable('vehicle_damage_repair_jobs') . ' RENAME COLUMN creation_command_payload_hash TO unavailable_hash');
        $this->assertFalse($this->repairs->ready());
        $this->failure($this->work->createJob(1, 10, Fixture::creation($this->connection, [$id]), 7), 'schema is incomplete');
        $this->expectException(RuntimeException::class);
        $this->repairs->hasAnyMembershipForCondition(1, 10, $id);
    }

    public function testMissingCommandUniquenessExplicitlyRefusesWrites(): void
    {
        $id = $this->condition();
        $this->connection->query('DROP INDEX repair_events_command_uq');
        $this->assertFalse($this->repairs->ready());
        $this->failure($this->work->createJob(1, 10, Fixture::creation($this->connection, [$id]), 7), 'schema is incomplete');
        $this->assertSame(0, $this->counts()['vehicle_damage_repair_jobs']);
    }

    public function testCommandIndexWithWrongColumnsFailsClosed(): void
    {
        $this->connection->query('DROP INDEX repair_events_command_uq');
        $table = $this->connection->prefixTable('vehicle_damage_repair_job_events');
        $this->connection->query('CREATE UNIQUE INDEX repair_events_command_uq ON ' . $table . ' (id, command_key)');
        $this->assertFalse($this->repairs->ready());
        $this->expectException(RuntimeException::class);
        $this->repairs->hasAnyMembershipForCondition(1, 10, $this->condition());
    }
}
