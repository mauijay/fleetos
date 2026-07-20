<?php

use App\Repositories\MovementChecklistRepository;
use App\Services\Fleet\MovementReadinessService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class TripMovementChecklistServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TripMovementChecklistService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedTrip();
        $this->service = new TripMovementChecklistService(new MovementChecklistRepository($this->connection));
    }

    public function testPickupAndReturnChecklistsAreCreatedOnce(): void
    {
        $pickup = $this->service->ensureForMovement($this->reservation(), 'pickup');
        $pickupAgain = $this->service->ensureForMovement($this->reservation(), 'pickup');
        $return = $this->service->ensureForMovement($this->reservation(), 'return');

        $this->assertSame($pickup['id'], $pickupAgain['id']);
        $this->assertSame(2, $this->connection->table('trip_movement_checklists')->countAllResults());
        $this->assertCount(9, $pickup['items']);
        $this->assertCount(10, $return['items']);
    }

    public function testAirportOnlyItemsAreNotAddedToOrdinaryPickup(): void
    {
        $ordinary = $this->service->ensureForMovement($this->reservation(), 'pickup', false);
        $airport = $this->service->ensureForMovement(array_merge($this->reservation(), ['id' => 2, 'starts_at' => '2026-07-19 12:00:00']), 'pickup', true);

        $this->assertNotContains('parking_location_recorded', array_column($ordinary['items'], 'item_code'));
        $this->assertContains('parking_location_recorded', array_column($airport['items'], 'item_code'));
    }

    public function testRequiredItemCanBeCompletedUndoneAndMarkedNotApplicable(): void
    {
        $checklist = $this->service->ensureForMovement($this->reservation(), 'pickup');
        $itemId = (int) $checklist['items'][0]['id'];

        $this->assertTrue($this->service->completeItem($itemId, 'Done'));
        $item = $this->connection->table('trip_movement_checklist_items')->where('id', $itemId)->get()->getRowArray();
        $this->assertSame('complete', $item['completion_state']);
        $this->assertSame('manual', $item['completion_source']);
        $this->assertNotNull($item['completed_at']);
        $this->assertSame('Done', $item['note']);

        $this->assertTrue($this->service->undoItem($itemId));
        $this->assertSame('open', $this->connection->table('trip_movement_checklist_items')->where('id', $itemId)->get()->getRowArray()['completion_state']);

        $this->assertTrue($this->service->markNotApplicable($itemId, 'Not needed'));
        $item = $this->connection->table('trip_movement_checklist_items')->where('id', $itemId)->get()->getRowArray();
        $this->assertSame('not_applicable', $item['applicability']);
        $this->assertSame('not_applicable', $item['completion_state']);
    }

    public function testPickupReadinessRequiresCriticalItemsOnly(): void
    {
        $checklist = $this->service->ensureForMovement($this->reservation(), 'pickup');
        $this->assertSame('not_started', $checklist['readiness_status']);

        foreach ($checklist['items'] as $item) {
            if ((bool) $item['is_critical']) {
                $this->service->completeItem((int) $item['id']);
            }
        }

        $ready = $this->service->checklist((int) $checklist['id']);
        $this->assertSame('ready', $ready['readiness_status']);
        $this->assertGreaterThan(0, $ready['progress']['required_remaining_count']);
    }

    public function testReturnRequiresDamageInspectionAndDispositionBeforeCompletion(): void
    {
        $checklist = $this->service->ensureForMovement($this->reservation(), 'return');

        foreach ($checklist['items'] as $item) {
            if ((bool) $item['is_critical'] && $item['item_code'] !== 'damage_check_completed') {
                $this->service->completeItem((int) $item['id']);
            }
        }

        $this->assertSame('blocked', $this->service->checklist((int) $checklist['id'])['readiness_status']);
        $this->assertFalse($this->service->completeChecklist((int) $checklist['id']));

        foreach ($this->service->checklist((int) $checklist['id'])['items'] as $item) {
            if ($item['item_code'] === 'damage_check_completed') {
                $this->service->completeItem((int) $item['id']);
            }
        }

        $this->assertSame('blocked', $this->service->checklist((int) $checklist['id'])['readiness_status']);
        $this->assertTrue($this->service->setDisposition((int) $checklist['id'], 'needs_cleaning'));
        $this->assertSame('ready', $this->service->checklist((int) $checklist['id'])['readiness_status']);
        $this->assertTrue($this->service->completeChecklist((int) $checklist['id'], 'Return done'));
        $this->assertSame('completed', $this->service->checklist((int) $checklist['id'])['readiness_status']);
    }

    public function testInvalidStateTransitionsAreRejectedSafely(): void
    {
        $checklist = $this->service->ensureForMovement($this->reservation(), 'return');

        $this->assertFalse($this->service->setDisposition((int) $checklist['id'], 'sold_to_mars'));
        $this->assertFalse($this->service->completeChecklist(999));
        $this->assertFalse($this->service->completeItem(999));
    }

    public function testSummariesForDaySupportCommandCenterProgress(): void
    {
        $checklist = $this->service->ensureForMovement($this->reservation(), 'pickup');
        $this->service->completeItem((int) $checklist['items'][0]['id']);

        $summaries = $this->service->summariesForDay(new DateTimeImmutable('2026-07-19 08:00:00'));

        $this->assertSame(1, count($summaries));
        $this->assertSame(9, $summaries[0]['required_count']);
        $this->assertSame(1, $summaries[0]['required_complete_count']);
        $this->assertSame('/operations/checklists/' . $checklist['id'], $summaries[0]['href']);
    }

    private function resetSchema(): void
    {
        foreach (['trip_movement_checklist_audits', 'trip_movement_checklist_items', 'trip_movement_checklists', 'turo_trips_normalized', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_code VARCHAR(80), display_name VARCHAR(150))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, movement_type VARCHAR(40), scheduled_at DATETIME, readiness_status VARCHAR(40), vehicle_disposition VARCHAR(40) NULL, completed_at DATETIME NULL, completion_note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklists_unique') . ' ON ' . $this->table('trip_movement_checklists') . ' (turo_trip_normalized_id, movement_type, scheduled_at)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, item_code VARCHAR(80), label VARCHAR(190), is_required INTEGER, is_critical INTEGER, applicability VARCHAR(40) DEFAULT \'applicable\', completion_state VARCHAR(40) DEFAULT \'open\', completion_source VARCHAR(40) NULL, completed_at DATETIME NULL, note TEXT NULL, sort_order INTEGER DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE UNIQUE INDEX ' . $this->table('trip_movement_checklist_items_unique') . ' ON ' . $this->table('trip_movement_checklist_items') . ' (trip_movement_checklist_id, item_code)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER, trip_movement_checklist_item_id INTEGER NULL, action VARCHAR(60), old_values TEXT NULL, new_values TEXT NULL, created_by INTEGER NULL, created_at DATETIME NULL)');
    }

    private function seedTrip(): void
    {
        $this->connection->table('fleet_vehicles')->insert(['id' => 6, 'fleet_code' => 'Spaceship-006', 'display_name' => 'Spaceship-006']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 1, 'fleet_vehicle_id' => 6, 'turo_trip_id' => 'trip-1', 'guest_name' => 'Guest One', 'starts_at' => '2026-07-19 13:30:00', 'ends_at' => '2026-07-21 10:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 2, 'fleet_vehicle_id' => 6, 'turo_trip_id' => 'trip-2', 'guest_name' => 'Guest Two', 'starts_at' => '2026-07-19 12:00:00', 'ends_at' => '2026-07-19 11:00:00']);
    }

    private function reservation(): array
    {
        return ['id' => 1, 'fleet_vehicle_id' => 6, 'starts_at' => '2026-07-19 13:30:00', 'ends_at' => '2026-07-21 10:00:00'];
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
