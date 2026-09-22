<?php

use App\Database\Migrations\CreateFleetTripCommitments;
use App\Repositories\FleetExtraRepository;
use App\Repositories\TripCommitmentRepository;
use App\Services\Fleet\TripCommitmentService;
use App\Services\Fleet\TripEnergyRuleResolver;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-20-000023_CreateFleetTripCommitments.php';

/** @internal */
final class TripCommitmentsTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TripCommitmentRepository $repository;
    private TripCommitmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['fleet_trip_commitment_audits', 'fleet_trip_commitments', 'fleet_extras', 'financial_activities', 'turo_extra_selections', 'scheduled_movement_locations', 'vehicle_operational_profiles', 'turo_trips_normalized', 'lookup_values', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->connection->getPrefix() . $table);
        }
        $this->createPrerequisites();
        $this->connection->query('PRAGMA foreign_keys = ON');
        (new CreateFleetTripCommitments(Database::forge($this->connection)))->up();
        $this->connection->query('ALTER TABLE ' . $this->connection->getPrefix() . 'fleet_trip_commitments ADD COLUMN fleet_extra_id INTEGER NULL');
        $this->repository = new TripCommitmentRepository($this->connection);
        $this->service = new TripCommitmentService($this->repository, null, new FleetExtraRepository($this->connection));
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    public function testMigrationCreatesNormalizedTripOwnedAuditedStorage(): void
    {
        $this->assertTrue($this->connection->tableExists('fleet_trip_commitments'));
        $this->assertTrue($this->connection->tableExists('fleet_trip_commitment_audits'));
        $this->assertNotContains('fleet_vehicle_id', $this->connection->getFieldNames('fleet_trip_commitments'));
        $indexes = array_keys($this->connection->getIndexData('fleet_trip_commitments'));
        $this->assertContains('fleet_trip_commitment_active_override_unique', $indexes);
        $this->assertContains('fleet_trip_commitment_company_trip_state', $indexes);
        $this->assertContains('fleet_trip_commitment_company_phase_state', $indexes);
        $this->assertContains('fleet_trip_commitment_trip_category', $indexes);
        $this->assertCount(2, $this->connection->getForeignKeyData('fleet_trip_commitments'));
    }

    public function testWrongCompanyCannotAccessTripWorkspace(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->workspace(2, 101);
    }

    public function testCrudLifecycleScopingAndAppendOnlyAudit(): void
    {
        $information = $this->service->create(1, 101, $this->input('pickup_instruction', 'Meet at hotel valet entrance', 'informational'), 7);
        $task = $this->service->create(1, 101, $this->input('guest_amenity', 'Cooler with ice', 'task', true), 7);
        $acknowledgment = $this->service->create(1, 101, $this->input('vehicle_setup', 'Confirm child-seat orientation', 'acknowledgment', true), 7);

        $edited = $this->service->edit(1, 101, (int) $information['id'], $this->input('pickup_instruction', 'Meet at east valet entrance', 'informational'), 8);
        $acknowledged = $this->service->acknowledge(1, 101, (int) $acknowledgment['id'], 8);
        $completed = $this->service->complete(1, 101, (int) $task['id'], 8);
        $this->assertSame('Meet at east valet entrance', $edited['instruction']);
        $this->assertNotNull($acknowledged['acknowledged_at']);
        $this->assertSame(8, $acknowledged['acknowledged_by_user_id']);
        $this->assertSame('completed', $completed['state']);
        $this->assertNotNull($completed['completed_at']);

        try {
            $this->service->cancel(1, 101, (int) $information['id'], '', 8);
            $this->fail('Canceling without a reason must fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $canceled = $this->service->cancel(1, 101, (int) $information['id'], 'Guest changed plans', 8);
        $this->assertSame('canceled', $canceled['state']);
        $this->assertSame('Guest changed plans', $canceled['cancellation_reason']);
        $this->assertCount(2, $this->service->workspace(1, 101)['history']);
        $this->assertSame(
            ['created', 'created', 'created', 'edited', 'acknowledged', 'completed', 'canceled'],
            array_reverse(array_column($this->repository->auditsForTrip(1, 101), 'action')),
        );

        $this->expectException(RuntimeException::class);
        $this->service->edit(2, 101, (int) $acknowledgment['id'], $this->input('vehicle_setup', 'Cross-company edit', 'task'), 9);
    }

    public function testTripAttachmentSurvivesSameIdentityReimportAndVehicleRemappingButDoesNotTransfer(): void
    {
        $created = $this->service->create(1, 101, $this->input('other', 'Exact-trip promise', 'informational'), 7);
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update(['starts_at' => '2026-11-11 14:30:00']);
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update(['fleet_vehicle_id' => 12]);

        $this->assertSame((int) $created['id'], (int) $this->service->workspace(1, 101)['active'][0]['id']);
        $this->assertSame([], $this->service->workspace(1, 102)['active']);
        $this->assertSame(1, $this->connection->table('fleet_trip_commitments')->where('turo_trip_normalized_id', 101)->countAllResults());
    }

    public function testCanceledTripRetainsHistoryButSuppressesActiveOperationalWork(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 103)->update([
            'trip_status_lookup_value_id' => 1,
            'canceled_at' => null,
        ]);
        $task = $this->service->create(1, 103, $this->input('guest_amenity', 'Canceled-trip setup', 'task', true), 7);
        $overrideInput = $this->input('energy_override', 'Do not exceed 50% for this trip', 'automatic_override', true);
        $overrideInput['energy_comparison'] = 'maximum';
        $overrideInput['energy_percent'] = '50';
        $override = $this->service->create(1, 103, $overrideInput, 7);

        $this->connection->table('turo_trips_normalized')->where('id', 103)->update([
            'trip_status_lookup_value_id' => 2,
            'canceled_at' => '2026-11-10 09:00:00',
        ]);

        $this->assertSame([], $this->service->activeForTrip(1, 103));
        $workspace = $this->service->workspace(1, 103);
        $this->assertFalse($workspace['trip_is_operational']);
        $this->assertSame([], $workspace['active']);
        $this->assertSame([(int) $task['id'], (int) $override['id']], array_map('intval', array_column($workspace['preserved'], 'id')));
        $this->assertSame([], $workspace['history']);
        $this->assertCount(2, $workspace['audits']);
        $this->assertSame(2, $this->connection->table('fleet_trip_commitments')->where('turo_trip_normalized_id', 103)->where('state', 'active')->countAllResults());
        $this->assertSame('vehicle_profile', (new TripEnergyRuleResolver($this->repository))->forTrip(1, 103, ['ready_energy_target_percent' => 75])['source']);

        try {
            $this->service->create(1, 103, $this->input('other', 'Must be rejected', 'informational'), 7);
            $this->fail('Creating a commitment for a canceled trip must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('canceled or invalid trip', $exception->getMessage());
        }
        $this->assertSame(2, $this->connection->table('fleet_trip_commitments')->where('turo_trip_normalized_id', 103)->countAllResults());
        $this->assertCount(2, $this->repository->auditsForTrip(1, 103));

        $this->connection->table('turo_trips_normalized')->where('id', 103)->update([
            'trip_status_lookup_value_id' => 3,
            'canceled_at' => null,
        ]);
        $this->assertFalse($this->service->workspace(1, 103)['trip_is_operational']);
        try {
            $this->service->create(1, 103, $this->input('other', 'Invalid trip commitment', 'informational'), 7);
            $this->fail('Creating a commitment for an invalid trip must fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->connection->table('turo_trips_normalized')->where('id', 103)->update([
            'trip_status_lookup_value_id' => 1,
            'canceled_at' => null,
        ]);
        $reactivated = $this->service->workspace(1, 103);
        $this->assertTrue($reactivated['trip_is_operational']);
        $this->assertSame([], $reactivated['preserved']);
        $this->assertSame([(int) $task['id'], (int) $override['id']], array_map('intval', array_column($reactivated['active'], 'id')));
        $this->assertCount(2, $reactivated['audits']);
        $this->assertSame('trip_commitment', (new TripEnergyRuleResolver($this->repository))->forTrip(1, 103, ['ready_energy_target_percent' => 75])['source']);
    }

    public function testOnlyOneActiveEnergyOverrideAndExactTripResolution(): void
    {
        $normalProfile = ['ready_energy_target_percent' => 75];
        $normalBefore = new TripEnergyRuleResolver($this->repository);
        $this->assertSame(75, $normalBefore->forTrip(1, 101, $normalProfile)['percent']);

        $override = $this->input('energy_override', 'Guest requested vehicle be charged only to 50%.', 'automatic_override', true);
        $override['energy_comparison'] = 'maximum';
        $override['energy_percent'] = '50';
        $created = $this->service->create(1, 101, $override, 7);
        try {
            $this->service->create(1, 101, $override, 7);
            $this->fail('A second active override must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $resolver = new TripEnergyRuleResolver($this->repository);
        $rule = $resolver->forTrip(1, 101, $normalProfile);
        $this->assertSame('trip_commitment', $rule['source']);
        $this->assertSame('maximum', $rule['comparison']);
        $this->assertSame(50, $rule['percent']);
        $this->assertSame(75, $rule['normal_vehicle_target']);
        $this->assertSame((int) $created['id'], $rule['commitment_id']);
        $presented = $this->service->activeForTrip(1, 101)[0];
        $this->assertSame(75, $presented['energy_rule']['normal_vehicle_target']);
        $this->assertSame('maximum', $presented['energy_rule']['comparison']);
        $this->assertSame('vehicle_profile', $resolver->forTrip(1, 102, $normalProfile)['source']);
        $this->assertSame(75, $resolver->forTrip(1, 102, $normalProfile)['percent']);

        $this->expectException(RuntimeException::class);
        $resolver->forTrip(2, 101, $normalProfile);
    }

    public function testMinimumTargetAndMaximumSemanticsAtFortyFiftyAndSixty(): void
    {
        $resolver = new TripEnergyRuleResolver($this->repository);
        $minimum = ['comparison' => 'minimum', 'percent' => 50];
        $target = ['comparison' => 'target', 'percent' => 50];
        $maximum = ['comparison' => 'maximum', 'percent' => 50];

        $this->assertFalse($resolver->evaluate($minimum, 40)['ready']);
        $this->assertTrue($resolver->evaluate($minimum, 50)['ready']);
        $this->assertTrue($resolver->evaluate($minimum, 60)['ready']);
        $this->assertSame('charge_required', $resolver->evaluate($target, 40)['condition']);
        $this->assertTrue($resolver->evaluate($target, 50)['ready']);
        $this->assertTrue($resolver->evaluate($target, 60)['ready']);
        $this->assertSame('Above guest-requested target', $resolver->evaluate($target, 60)['attention_label']);
        $this->assertTrue($resolver->evaluate($maximum, 40)['ready']);
        $this->assertTrue($resolver->evaluate($maximum, 50)['ready']);
        $above = $resolver->evaluate($maximum, 60);
        $this->assertSame('above_maximum', $above['condition']);
        $this->assertFalse($above['ready']);
        $this->assertStringNotContainsString('Charge', (string) $above['action_label']);
    }

    public function testExtraAndSetupCommitmentCoexistWithoutCommercialOrFinancialWrites(): void
    {
        $beforeExtra = $this->connection->table('turo_extra_selections')->where('turo_reservation_id', 'LOCAL-COMMIT-R101')->get()->getRowArray();
        $beforeFinancialCount = $this->connection->table('financial_activities')->countAllResults();

        $this->service->create(1, 101, $this->input('child_seat_setup', 'Install one rear-facing and one forward-facing', 'task', true), 7);

        $afterExtra = $this->connection->table('turo_extra_selections')->where('turo_reservation_id', 'LOCAL-COMMIT-R101')->get()->getRowArray();
        $this->assertSame($beforeExtra, $afterExtra);
        $this->assertSame('2', (string) $afterExtra['quantity']);
        $this->assertSame(60.0, (float) $afterExtra['selected_value']);
        $this->assertSame($beforeFinancialCount, $this->connection->table('financial_activities')->countAllResults());
    }

    public function testOptionalCanonicalExtraLinkIsCompanyScopedAndDoesNotDuplicateCommitment(): void
    {
        $input = $this->input('child_seat_setup', 'One rear-facing and one forward-facing', 'informational');
        $input['fleet_extra_id'] = '501';
        $linked = $this->service->create(1, 101, $input, 7);
        $this->assertSame(501, (int) $linked['fleet_extra_id']);
        $this->assertSame('Child Safety Seat', $linked['fleet_extra_name']);
        $this->assertCount(1, $this->service->activeForTrip(1, 101));

        $input['fleet_extra_id'] = '502';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('owned by the active company');
        $this->service->create(1, 101, $input, 7);
    }

    /** @return array<string, mixed> */
    private function input(string $category, string $instruction, string $handling, bool $required = false): array
    {
        return [
            'category' => $category,
            'instruction' => $instruction,
            'applies_during' => 'preparation',
            'handling_mode' => $handling,
            'required_before_dispatch' => $required ? '1' : '0',
            'energy_comparison' => null,
            'energy_percent' => null,
            'arranged_at' => null,
        ];
    }

    private function createPrerequisites(): void
    {
        $prefix = $this->connection->getPrefix();
        $this->connection->query('CREATE TABLE ' . $prefix . 'companies (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'fleet_vehicles (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_code VARCHAR(80), display_name VARCHAR(190), model VARCHAR(190), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'lookup_values (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'turo_trips_normalized (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, trip_status_lookup_value_id INTEGER, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), starts_at DATETIME, ends_at DATETIME, canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'scheduled_movement_locations (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER, movement_type VARCHAR(20), location_class VARCHAR(40), source_text VARCHAR(190))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'vehicle_operational_profiles (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, energy_kind VARCHAR(20), ready_energy_target_percent INTEGER)');
        $this->connection->query('CREATE TABLE ' . $prefix . 'turo_extra_selections (id INTEGER PRIMARY KEY, turo_reservation_id VARCHAR(80), quantity INTEGER NULL, selected_value DECIMAL(10,2))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'financial_activities (id INTEGER PRIMARY KEY, amount DECIMAL(10,2))');
        $this->connection->query('CREATE TABLE ' . $prefix . 'fleet_extras (id INTEGER PRIMARY KEY, company_id INTEGER, code VARCHAR(80), display_name VARCHAR(190), active BOOLEAN, sort_order INTEGER, notes TEXT)');
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 11, 'company_id' => 1, 'fleet_code' => 'LOCAL-COMMIT-A', 'display_name' => 'Synthetic A', 'model' => 'Test EV'],
            ['id' => 12, 'company_id' => 1, 'fleet_code' => 'LOCAL-COMMIT-A2', 'display_name' => 'Synthetic A2', 'model' => 'Test EV'],
            ['id' => 22, 'company_id' => 2, 'fleet_code' => 'LOCAL-COMMIT-B', 'display_name' => 'Synthetic B', 'model' => 'Test EV'],
        ]);
        $this->connection->table('lookup_values')->insertBatch([['id' => 1, 'code' => 'booked'], ['id' => 2, 'code' => 'canceled_zero_payout'], ['id' => 3, 'code' => 'invalid']]);
        $this->connection->table('vehicle_operational_profiles')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 11, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 75],
            ['id' => 2, 'fleet_vehicle_id' => 12, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 75],
            ['id' => 3, 'fleet_vehicle_id' => 22, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 80],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 101, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'LOCAL-COMMIT-101', 'turo_reservation_id' => 'LOCAL-COMMIT-R101', 'starts_at' => '2026-11-11 14:00:00', 'ends_at' => '2026-11-12 14:00:00', 'canceled_at' => null],
            ['id' => 102, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'LOCAL-COMMIT-102', 'turo_reservation_id' => 'LOCAL-COMMIT-R102', 'starts_at' => '2026-11-13 14:00:00', 'ends_at' => '2026-11-14 14:00:00', 'canceled_at' => null],
            ['id' => 103, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 2, 'turo_trip_id' => 'LOCAL-COMMIT-103', 'turo_reservation_id' => 'LOCAL-COMMIT-R103', 'starts_at' => '2026-11-15 14:00:00', 'ends_at' => '2026-11-16 14:00:00', 'canceled_at' => '2026-11-10 09:00:00'],
            ['id' => 201, 'fleet_vehicle_id' => 22, 'trip_status_lookup_value_id' => 1, 'turo_trip_id' => 'LOCAL-COMMIT-201', 'turo_reservation_id' => 'LOCAL-COMMIT-R201', 'starts_at' => '2026-11-11 14:00:00', 'ends_at' => '2026-11-12 14:00:00', 'canceled_at' => null],
        ]);
        $this->connection->table('turo_extra_selections')->insert(['id' => 1, 'turo_reservation_id' => 'LOCAL-COMMIT-R101', 'quantity' => 2, 'selected_value' => '60.00']);
        $this->connection->table('fleet_extras')->insertBatch([
            ['id' => 501, 'company_id' => 1, 'code' => 'child_seat', 'display_name' => 'Child Safety Seat', 'active' => 1, 'sort_order' => 1],
            ['id' => 502, 'company_id' => 2, 'code' => 'other_company_extra', 'display_name' => 'Other Company Extra', 'active' => 1, 'sort_order' => 1],
        ]);
    }
}
