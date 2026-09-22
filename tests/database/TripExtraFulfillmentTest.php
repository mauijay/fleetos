<?php

use App\Database\Migrations\CreateExtraFulfillment;
use App\Repositories\TripExtraFulfillmentRepository;
use App\Services\Fleet\TripExtraFulfillmentService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-21-000024_CreateExtraFulfillment.php';

/** @internal */
final class TripExtraFulfillmentTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TripExtraFulfillmentRepository $repository;
    private TripExtraFulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh connection avoids SQLite schema metadata cached by migration tests that run before this class.
        $this->connection = Database::connect('tests', false);
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['trip_extra_fulfillment_audits', 'trip_extra_fulfillments', 'trip_movement_events', 'fleet_trip_commitments', 'turo_extra_selections', 'fleet_extra_source_mappings', 'fleet_extras', 'turo_trips_normalized', 'lookup_values', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->prerequisites();
        (new CreateExtraFulfillment(Database::forge($this->connection)))->up();
        $this->seed();
        $this->connection->query('PRAGMA foreign_keys = ON');
        $this->repository = new TripExtraFulfillmentRepository($this->connection);
        $this->service = new TripExtraFulfillmentService($this->repository);
    }

    public function testMigrationAndExactSelectionFulfillmentWithQuantity(): void
    {
        $this->assertContains('fulfillment_type', $this->connection->getFieldNames('fleet_extras'));
        $this->assertContains('fleet_extra_id', $this->connection->getFieldNames('fleet_trip_commitments'));
        $this->assertTrue($this->connection->tableExists('trip_extra_fulfillments'));

        $this->service->reconcileSelectionIds(1, [301]);
        $row = $this->repository->fulfillmentForSelection(1, 301);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['state']);
        $presentation = $this->service->forTrips(1, [101])[101][0];
        $this->assertSame('Premium Beach Gear ×2', $presentation['title']);
        $this->assertSame('Pack 2 beach gear set(s)', $presentation['action_label']);
        $this->assertTrue($presentation['is_actionable']);
    }

    public function testCompletionIsIdempotentPriceDoesNotReopenButQuantityDoes(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $id = (int) $this->repository->fulfillmentForSelection(1, 301)['id'];
        $commercialBefore = $this->connection->table('turo_extra_selections')->select('quantity, unit_price, gross_amount')->where('id', 301)->get()->getRowArray();
        $this->service->complete(1, 101, $id, 9, 'Packed and verified');
        $this->assertSame($commercialBefore, $this->connection->table('turo_extra_selections')->select('quantity, unit_price, gross_amount')->where('id', 301)->get()->getRowArray());
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults();
        $this->service->complete(1, 101, $id, 9, 'Duplicate submit');
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults());

        $this->connection->table('turo_extra_selections')->where('id', 301)->update(['unit_price' => '99.00']);
        $this->service->reconcileSelectionIds(1, [301]);
        $this->assertSame('completed', $this->repository->fulfillmentForSelection(1, 301)['state']);

        $this->connection->table('turo_extra_selections')->where('id', 301)->update(['quantity' => '3.000']);
        $this->service->reconcileSelectionIds(1, [301]);
        $this->assertSame('pending', $this->repository->fulfillmentForSelection(1, 301)['state']);
        $this->assertSame('automatic_reopen', $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->orderBy('id', 'DESC')->get()->getRow('action'));
    }

    public function testRemovalRetainsHistoryAndReadditionExplicitlyReopens(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $id = (int) $this->repository->fulfillmentForSelection(1, 301)['id'];
        $this->service->complete(1, 101, $id, 9);
        $this->connection->table('turo_extra_selections')->where('id', 301)->update(['removed_at' => '2026-09-21 12:00:00']);
        $this->service->reconcileSelectionIds(1, [301]);
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults();
        $history = $this->service->forTrips(1, [101])[101][0];
        $this->assertTrue($history['is_removed']);
        $this->assertTrue($history['is_completed']);
        $this->assertFalse($history['is_actionable']);
        $this->assertSame('2026-09-21 12:00:00', $history['removed_at']);
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults());
        $this->assertSame('completed', $this->repository->fulfillmentForSelection(1, 301)['state']);

        $this->connection->table('turo_extra_selections')->where('id', 301)->update(['removed_at' => null]);
        $this->service->reconcileSelectionIds(1, [301], [301]);
        $this->assertSame('pending', $this->repository->fulfillmentForSelection(1, 301)['state']);
        $this->assertSame('reactivated', $this->connection->table('trip_extra_fulfillment_audits')->orderBy('id', 'DESC')->get()->getRow('action'));
    }

    public function testRemovedPendingFulfillmentRemainsReadOnlyHistoricalContext(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $id = (int) $this->repository->fulfillmentForSelection(1, 301)['id'];
        $this->connection->table('turo_extra_selections')->where('id', 301)->update(['removed_at' => '2026-09-21 13:00:00']);
        $before = $this->repository->fulfillmentForSelection(1, 301);
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults();

        $history = $this->service->forTrips(1, [101])[101][0];

        $this->assertTrue($history['is_removed']);
        $this->assertFalse($history['is_completed']);
        $this->assertFalse($history['is_actionable']);
        $this->assertSame('pending', $history['fulfillment_state']);
        $this->assertSame($before, $this->repository->fulfillmentForSelection(1, 301));
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->where('trip_extra_fulfillment_id', $id)->countAllResults());
    }

    public function testRemapAndVehicleChangeInvalidateCompletionWhileInactiveExtraDoesNotHideWork(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $id = (int) $this->repository->fulfillmentForSelection(1, 301)['id'];
        $this->service->complete(1, 101, $id, 9);
        $this->connection->table('fleet_extra_source_mappings')->where('source_extra_id', 'SRC-1')->update(['fleet_extra_id' => 202]);
        $this->service->reconcileForSource(1, 'SRC-1', 9);
        $this->assertSame('pending', $this->repository->fulfillmentForSelection(1, 301)['state']);

        $this->service->complete(1, 101, $id, 9);
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update(['fleet_vehicle_id' => 12]);
        $this->service->reconcileForTrip(1, 101, 9);
        $this->assertSame('pending', $this->repository->fulfillmentForSelection(1, 301)['state']);
        $this->connection->table('fleet_extras')->where('id', 202)->update(['active' => 0]);
        $this->assertTrue($this->service->forTrips(1, [101])[101][0]['is_actionable']);
    }

    public function testCompanyCanceledAndHandoffGuardsAndLinkedCommitment(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $id = (int) $this->repository->fulfillmentForSelection(1, 301)['id'];
        $this->assertNull($this->repository->rowForCompany(2, $id));
        try {
            $this->service->complete(2, 101, $id, 9);
            $this->fail('A different company must not complete the fulfillment.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->connection->table('fleet_trip_commitments')->insert([
            'company_id' => 1, 'turo_trip_normalized_id' => 101, 'fleet_extra_id' => 201, 'state' => 'active', 'instruction' => 'Guest requested premium setup',
        ]);
        $this->assertCount(1, $this->service->forTrips(1, [101])[101][0]['linked_commitments']);

        $this->connection->table('trip_movement_events')->insert(['fleet_vehicle_id' => 11, 'turo_trip_normalized_id' => 101, 'event_code' => 'actual_handoff', 'voided_at' => null]);
        $this->assertArrayHasKey(101, $this->repository->activeHandoffTripIds(1, [101]));
        $this->assertFalse($this->service->forTrips(1, [101])[101][0]['is_actionable']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->complete(1, 101, $id, 9);
    }

    public function testCanceledTripRetainsFulfillmentHistoryWithoutActiveWork(): void
    {
        $this->service->reconcileSelectionIds(1, [301]);
        $this->connection->table('lookup_values')->insert(['id' => 2, 'code' => 'canceled_zero_payout']);
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update(['trip_status_lookup_value_id' => 2]);

        $row = $this->service->forTrips(1, [101])[101][0];
        $this->assertFalse($row['trip_is_operational']);
        $this->assertFalse($row['is_actionable']);
        $this->assertNotNull($this->repository->fulfillmentForSelection(1, 301));
    }

    public function testMigrationRollbackRemovesOnlyAddedStorage(): void
    {
        (new CreateExtraFulfillment(Database::forge($this->connection)))->down();

        $this->assertFalse($this->connection->tableExists('trip_extra_fulfillments'));
        $this->assertFalse($this->connection->tableExists('trip_extra_fulfillment_audits'));
        $this->assertNotContains('fulfillment_type', $this->connection->getFieldNames('fleet_extras'));
        $this->assertNotContains('fleet_extra_id', $this->connection->getFieldNames('fleet_trip_commitments'));
    }

    private function prerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, trip_status_lookup_value_id INTEGER, canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_extras') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, code VARCHAR(80), display_name VARCHAR(190), active BOOLEAN, sort_order INTEGER, notes TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_extra_source_mappings') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, source_system VARCHAR(30), source_extra_id VARCHAR(120), fleet_extra_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_extra_selections') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, turo_trip_normalized_id INTEGER, turo_reservation_id VARCHAR(120), source_extra_id VARCHAR(120), reservation_state_extra_id VARCHAR(120), quantity DECIMAL(10,3) NULL, unit_price DECIMAL(12,2), gross_amount DECIMAL(12,2), removed_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_trip_commitments') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, turo_trip_normalized_id INTEGER, state VARCHAR(20), instruction TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER, event_code VARCHAR(80), voided_at DATETIME NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('companies')->insert(['id' => 1]);
        $this->connection->table('fleet_vehicles')->insertBatch([['id' => 11, 'company_id' => 1], ['id' => 12, 'company_id' => 1]]);
        $this->connection->table('lookup_values')->insert(['id' => 1, 'code' => 'booked']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 101, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 1]);
        $this->connection->table('fleet_extras')->insertBatch([
            ['id' => 201, 'company_id' => 1, 'code' => 'premium_beach', 'display_name' => 'Premium Beach Gear', 'active' => 1, 'sort_order' => 1, 'fulfillment_type' => 'pack', 'requires_operator_confirmation' => 1, 'readiness_blocking' => 1, 'default_action_label' => 'Pack {quantity} beach gear set(s)', 'fulfillment_phase' => 'preparation'],
            ['id' => 202, 'company_id' => 1, 'code' => 'other_gear', 'display_name' => 'Other Gear', 'active' => 1, 'sort_order' => 2, 'fulfillment_type' => 'pack', 'requires_operator_confirmation' => 1, 'readiness_blocking' => 1, 'default_action_label' => 'Pack other gear', 'fulfillment_phase' => 'preparation'],
        ]);
        $this->connection->table('fleet_extra_source_mappings')->insert(['id' => 211, 'company_id' => 1, 'source_system' => 'turo', 'source_extra_id' => 'SRC-1', 'fleet_extra_id' => 201]);
        $this->connection->table('turo_extra_selections')->insert(['id' => 301, 'company_id' => 1, 'turo_trip_normalized_id' => 101, 'turo_reservation_id' => 'RES-101', 'source_extra_id' => 'SRC-1', 'reservation_state_extra_id' => 'STATE-1', 'quantity' => '2.000', 'unit_price' => '47.00', 'gross_amount' => '94.00']);
    }

    private function table(string $name): string
    {
        return $this->connection->getPrefix() . $name;
    }
}
