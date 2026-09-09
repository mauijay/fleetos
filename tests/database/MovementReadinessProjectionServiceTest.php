<?php

use App\Repositories\MovementReadinessReadModelRepository;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\MovementReadinessReadService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class MovementReadinessProjectionServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private MovementReadinessReadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->dropTables();
        $this->createTables();
        $this->seedCompaniesAndTrips();
        $this->service = new MovementReadinessReadService(
            new MovementReadinessReadModelRepository($this->connection),
            new MovementReadinessProjectionService(),
        );
    }

    public function testReturnFactsWithoutDistinctNextTripDoNotCreateNextPickupWorkOrMutateLegacyItems(): void
    {
        $this->insertChecklist(101, 1001, 11, 'return', [
            'readiness_status' => 'completed',
            'vehicle_disposition' => 'available',
            'completed_at' => '2026-09-08 10:30:00',
        ]);
        $this->insertItems(101, [
            ['vehicle_received', 'Vehicle received', 'open', 'applicable'],
            ['exterior_inspected', 'Exterior inspected', 'complete', 'applicable'],
            ['interior_inspected', 'Interior inspected', 'complete', 'applicable'],
            ['damage_check_completed', 'Damage check completed', 'complete', 'applicable'],
            ['return_photos_completed', 'Return photos completed', 'complete', 'applicable'],
        ]);
        $this->insertEvent(301, 1, 11, 1001, 'actual_return', 'return', '2026-09-08 10:00:00', 'home');
        $this->insertAssessment(401, 1, 11, 1001, 301, 'return', 'dirty', 37, '2026-09-08 10:05:00');
        $this->connection->table('vehicle_operational_profiles')->insert([
            'id' => 1, 'fleet_vehicle_id' => 11, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 80,
        ]);
        $itemCount = $this->connection->table('trip_movement_checklist_items')->countAllResults();
        $eventCount = $this->connection->table('trip_movement_events')->countAllResults();

        $projection = $this->service->forCompany(1, [101], new DateTimeImmutable('2026-09-08 12:00:00'))[101];

        $this->assertTrue($projection['ready']);
        $this->assertSame(0, $projection['blocking_remaining_count']);
        $this->assertSame('movement_event', $this->requirement($projection, 'vehicle_received')['satisfied_by']);
        $this->assertSame('movement_event', $this->requirement($projection, 'return_time_confirmed')['satisfied_by']);
        $this->assertSame('movement_assessment', $this->requirement($projection, 'energy_known')['satisfied_by']);
        $this->assertSame('movement_assessment', $this->requirement($projection, 'cleaning_status_known')['satisfied_by']);
        $this->assertNull($projection['next_trip']);
        $this->assertFalse($projection['is_same_day_turnaround']);
        $this->assertNotContains('vehicle_clean', array_column($projection['requirements'], 'code'));
        $this->assertNotContains('energy_ready', array_column($projection['requirements'], 'code'));
        $this->assertTrue($projection['workflow_history']['historically_completed']);
        $this->assertSame($itemCount, $this->connection->table('trip_movement_checklist_items')->countAllResults());
        $this->assertSame($eventCount, $this->connection->table('trip_movement_events')->countAllResults());
        $this->assertSame('open', $this->connection->table('trip_movement_checklist_items')->where('item_code', 'vehicle_received')->get()->getRow('completion_state'));
    }

    public function testCanceledDeletedAndInvalidTripsDoNotCreateNextPickupWork(): void
    {
        $this->insertChecklist(101, 1001, 11, 'return');
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'code' => 'booked'],
            ['id' => 2, 'code' => 'canceled_by_guest'],
            ['id' => 3, 'code' => 'invalid'],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 1101, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 1, 'starts_at' => '2026-09-08 19:00:00', 'ends_at' => '2026-09-08 20:00:00', 'canceled_at' => null, 'deleted_at' => '2026-09-08 12:00:00'],
            ['id' => 1102, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 1, 'starts_at' => '2026-09-08 20:00:00', 'ends_at' => '2026-09-08 21:00:00', 'canceled_at' => '2026-09-08 12:00:00', 'deleted_at' => null],
            ['id' => 1103, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 2, 'starts_at' => '2026-09-08 21:00:00', 'ends_at' => '2026-09-08 22:00:00', 'canceled_at' => null, 'deleted_at' => null],
            ['id' => 1104, 'fleet_vehicle_id' => 11, 'trip_status_lookup_value_id' => 3, 'starts_at' => '2026-09-08 22:00:00', 'ends_at' => '2026-09-08 23:00:00', 'canceled_at' => null, 'deleted_at' => null],
        ]);

        $projection = $this->service->forCompany(1, [101])[101];

        $this->assertNull($projection['next_trip']);
        $this->assertFalse($projection['is_same_day_turnaround']);
        $this->assertNotContains(MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION, array_column($projection['requirements'], 'phase'));
    }

    public function testValidFutureTripCreatesPreparationWithoutTurnaroundClassification(): void
    {
        $this->insertChecklist(101, 1001, 11, 'return');
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 1201, 'fleet_vehicle_id' => 11, 'starts_at' => '2026-09-09 09:00:00', 'ends_at' => '2026-09-09 12:00:00',
        ]);

        $projection = $this->service->forCompany(1, [101])[101];

        $this->assertSame(1201, $projection['next_trip']['id']);
        $this->assertFalse($projection['is_same_day_turnaround']);
        $this->assertSame(MovementReadinessProjectionService::PHASE_NEXT_PICKUP_PREPARATION, $this->requirement($projection, 'vehicle_clean')['phase']);
        $this->assertSame(1201, $this->requirement($projection, 'vehicle_clean')['target_trip_id']);
        $this->assertSame(1201, $this->requirement($projection, 'energy_ready')['target_trip_id']);
    }

    public function testDistinctSameDayNextTripIsClassifiedAsTurnaround(): void
    {
        $this->insertChecklist(101, 1001, 11, 'return');
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 1202, 'fleet_vehicle_id' => 11, 'starts_at' => '2026-09-08 19:00:00', 'ends_at' => '2026-09-09 08:00:00',
        ]);

        $projection = $this->service->forCompany(1, [101])[101];

        $this->assertSame(1202, $projection['next_trip']['id']);
        $this->assertTrue($projection['is_same_day_turnaround']);
    }

    public function testHistoricalCompletionAndVoidedFactsDoNotMaskCurrentBlockingWork(): void
    {
        $this->insertChecklist(102, 1002, 12, 'return', [
            'readiness_status' => 'completed',
            'vehicle_disposition' => 'available',
            'completed_at' => '2026-09-08 11:30:00',
        ]);
        $this->insertEvent(302, 1, 12, 1002, 'actual_return', 'return', '2026-09-08 11:00:00', 'home', '2026-09-08 11:40:00');
        $this->insertAssessment(402, 1, 12, 1002, 302, 'return', 'clean', 90, '2026-09-08 11:05:00');

        $projection = $this->service->forCompany(1, [102], new DateTimeImmutable('2026-09-08 12:00:00'))[102];

        $this->assertFalse($projection['ready']);
        $this->assertTrue($projection['workflow_history']['historically_completed']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'vehicle_received')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'energy_known')['status']);
        $this->assertGreaterThan(0, $projection['blocking_remaining_count']);
    }

    public function testSafetyCriticalNotApplicableLegacyItemRemainsUnsatisfied(): void
    {
        $this->insertChecklist(103, 1003, 13, 'return', ['vehicle_disposition' => 'maintenance_required']);
        $this->insertItems(103, [
            ['damage_check_completed', 'Damage check completed', 'open', 'not_applicable'],
        ]);
        $this->insertEvent(303, 1, 13, 1003, 'actual_return', 'return', '2026-09-08 12:00:00', 'home');
        $this->insertAssessment(403, 1, 13, 1003, 303, 'return', 'clean', 60, '2026-09-08 12:05:00');

        $projection = $this->service->forCompany(1, [103])[103];
        $damage = $this->requirement($projection, 'damage_check_completed');

        $this->assertSame('unsatisfied', $damage['status']);
        $this->assertTrue($damage['blocking']);
        $this->assertFalse($damage['allows_na']);
        $this->assertSame('checklist_item', $damage['action']['type']);
    }

    public function testAssessmentObservationsDoNotProveHumanInspectionOrPhotos(): void
    {
        $this->insertChecklist(104, 1004, 14, 'return');
        $this->insertEvent(304, 1, 14, 1004, 'actual_return', 'return', '2026-09-08 13:00:00', 'home');
        $this->insertAssessment(404, 1, 14, 1004, 304, 'return', 'clean', 100, '2026-09-08 13:05:00');

        $projection = $this->service->forCompany(1, [104])[104];

        $this->assertSame('satisfied', $this->requirement($projection, 'cleaning_status_known')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'exterior_inspected')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'interior_inspected')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'damage_check_completed')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'return_photos_completed')['status']);
        $this->assertSame(4, $projection['human_actions_remaining_count']);
        $this->assertSame('Inspect exterior', $this->requirement($projection, 'exterior_inspected')['action']['label']);
        $this->assertSame('Inspect interior', $this->requirement($projection, 'interior_inspected')['action']['label']);
        $this->assertSame('Check for damage', $this->requirement($projection, 'damage_check_completed')['action']['label']);
        $this->assertSame('Confirm return photos', $this->requirement($projection, 'return_photos_completed')['action']['label']);
        $this->assertSame('Assign vehicle disposition', $this->requirement($projection, 'vehicle_disposition')['action']['label']);
    }

    public function testActiveUnknownLocationCannotBeOverriddenByWeakerLegacyCompletion(): void
    {
        $this->insertChecklist(105, 1005, 15, 'pickup');
        $this->insertItems(105, [
            ['location_confirmed', 'Pickup location confirmed', 'complete', 'applicable'],
        ]);
        $this->insertEvent(305, 1, 15, 1005, 'vehicle_staged', 'pickup', '2026-09-08 14:00:00', 'unknown');

        $projection = $this->service->forCompany(1, [105])[105];
        $location = $this->requirement($projection, 'location_confirmed');

        $this->assertSame('unsatisfied', $location['status']);
        $this->assertSame('derived', $location['kind']);
        $this->assertNull($location['satisfied_by']);
        $this->assertSame('record_fact', $location['action']['type']);
    }

    public function testAirportPickupCombinesFactsMilestonesHumanProofAndStructuralApplicability(): void
    {
        $this->insertChecklist(106, 1006, 16, 'pickup');
        $this->insertItems(106, [
            ['vehicle_inspected', 'Vehicle inspected', 'complete', 'applicable'],
            ['exterior_photos_completed', 'Exterior condition photos completed', 'complete', 'applicable'],
            ['interior_photos_completed', 'Interior condition photos completed', 'complete', 'applicable'],
        ]);
        $this->connection->table('vehicle_operational_profiles')->insert([
            'id' => 1, 'fleet_vehicle_id' => 16, 'energy_kind' => 'electric', 'ready_energy_target_percent' => 80,
        ]);
        $this->connection->table('vehicle_operational_capabilities')->insert([
            'id' => 1, 'fleet_vehicle_id' => 16, 'capability_code' => 'key_card', 'is_applicable' => 1,
        ]);
        $this->insertEvent(306, 1, 16, 1006, 'vehicle_staged', 'pickup', '2026-09-08 15:00:00', 'airport_hnl', null, [
            'airport_garage_code' => 'T2', 'airport_parking_level' => 4, 'airport_parking_row' => 'B',
        ]);
        $this->insertAssessment(406, 1, 16, 1006, 306, 'pickup', 'clean', 84, '2026-09-08 15:05:00');
        $this->connection->table('airport_movement_workflows')->insert([
            'id' => 1, 'turo_trip_normalized_id' => 1006, 'fleet_vehicle_id' => 16, 'movement_type' => 'pickup',
            'scheduled_at' => '2026-09-08 16:00:00', 'key_card_confirmed_at' => '2026-09-08 15:06:00',
            'guest_instructions_sent_at' => '2026-09-08 15:07:00', 'updated_at' => '2026-09-08 15:07:00',
        ]);

        $projection = $this->service->forCompany(1, [106])[106];

        $this->assertTrue($projection['ready']);
        $this->assertSame('movement_assessment_and_profile', $this->requirement($projection, 'energy_ready')['satisfied_by']);
        $this->assertSame('movement_event', $this->requirement($projection, 'airport_staging')['satisfied_by']);
        $this->assertSame('airport_milestone', $this->requirement($projection, 'key_card_confirmed')['satisfied_by']);
        $this->assertSame('satisfied', $this->requirement($projection, 'parking_location_recorded')['status']);
        $this->assertSame('unsatisfied', $this->requirement($projection, 'guest_handoff')['status']);
        $this->assertFalse($this->requirement($projection, 'guest_handoff')['blocking']);
    }

    public function testAirportPickupCountsBlockingAndAdditionalActionsSeparately(): void
    {
        $this->insertChecklist(107, 1007, 17, 'pickup');
        $this->insertItems(107, [
            ['key_card_confirmed', 'Key card confirmed', 'complete', 'applicable'],
        ]);
        $this->connection->table('vehicle_operational_profiles')->insert([
            'id' => 2, 'fleet_vehicle_id' => 17, 'energy_kind' => 'electric', 'ready_energy_target_percent' => null,
        ]);
        $this->insertEvent(307, 1, 17, 1007, 'vehicle_staged', 'pickup', '2026-09-08 15:00:00', 'airport_hnl', null, [
            'airport_garage_code' => 'T2', 'airport_parking_level' => 4, 'airport_parking_row' => 'B',
        ]);
        $this->insertAssessment(407, 1, 17, 1007, 307, 'pickup', 'dirty', 84, '2026-09-08 15:05:00');

        $projection = $this->service->forCompany(1, [107])[107];
        $pending = array_filter($projection['requirements'], static fn (array $requirement): bool => $requirement['phase'] === $projection['readiness_phase'] && $requirement['status'] === 'unsatisfied');
        $blocking = array_filter($pending, static fn (array $requirement): bool => $requirement['blocking']);
        $additional = array_filter($pending, static fn (array $requirement): bool => ! $requirement['blocking']);

        $this->assertCount(4, $blocking);
        $this->assertCount(2, $additional);
        $this->assertSame(count($blocking), $projection['blocking_remaining_count']);
        $this->assertSame(count($additional), $projection['additional_actions_remaining_count']);
        $this->assertSame(['guest_pickup_instructions_confirmed', 'turo_access_instructions_confirmed'], array_column($additional, 'code'));
    }

    public function testCompanyScopeAndQueryCountAreBoundedForBatch(): void
    {
        $this->insertChecklist(107, 1007, 17, 'pickup');
        $this->insertChecklist(108, 1008, 18, 'return');
        $this->insertChecklist(207, 2007, 27, 'pickup');
        $queryCount = 0;
        $listener = static function () use (&$queryCount): void {
            ++$queryCount;
        };
        Events::on('DBQuery', $listener);

        try {
            $projections = $this->service->forCompany(1, [107, 108, 207, 107, 0]);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame([107, 108], array_keys($projections));
        $this->assertSame(10, $queryCount);
        $this->assertSame(1, $projections[107]['company_id']);
        $this->assertArrayNotHasKey(207, $projections);
    }

    private function dropTables(): void
    {
        foreach (['vehicle_positioning_plans', 'airport_movement_workflows', 'scheduled_movement_locations', 'vehicle_operational_capabilities', 'vehicle_operational_profiles', 'movement_assessments', 'trip_movement_events', 'trip_movement_checklist_items', 'trip_movement_checklists', 'turo_trips_normalized', 'lookup_values', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createTables(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80) NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, trip_status_lookup_value_id INTEGER NULL, starts_at DATETIME NULL, ends_at DATETIME NULL, canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklists') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, movement_type VARCHAR(40) NOT NULL, scheduled_at DATETIME NOT NULL, readiness_status VARCHAR(40) NOT NULL DEFAULT \'not_started\', vehicle_disposition VARCHAR(40) NULL, completed_at DATETIME NULL, completion_note TEXT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_checklist_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_movement_checklist_id INTEGER NOT NULL, item_code VARCHAR(80) NOT NULL, label VARCHAR(190) NOT NULL, is_required INTEGER NOT NULL DEFAULT 1, is_critical INTEGER NOT NULL DEFAULT 1, applicability VARCHAR(40) NOT NULL DEFAULT \'applicable\', completion_state VARCHAR(40) NOT NULL DEFAULT \'open\', completion_source VARCHAR(40) NULL, completed_at DATETIME NULL, note TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, turo_trip_normalized_id INTEGER NULL, event_code VARCHAR(40) NOT NULL, movement_type VARCHAR(20) NULL, occurred_at DATETIME NOT NULL, location_class VARCHAR(40) NULL, location_detail VARCHAR(500) NULL, airport_garage_code VARCHAR(40) NULL, airport_parking_level INTEGER NULL, airport_parking_row VARCHAR(4) NULL, source VARCHAR(40) NOT NULL, actor_user_id INTEGER NOT NULL, note TEXT NULL, supersedes_event_id INTEGER NULL, voided_at DATETIME NULL, voided_by_user_id INTEGER NULL, void_reason TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('movement_assessments') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, turo_trip_normalized_id INTEGER NULL, trip_movement_event_id INTEGER NULL, movement_type VARCHAR(20) NOT NULL, cleanliness VARCHAR(20) NULL, energy_percent INTEGER NULL, captured_at DATETIME NOT NULL, source VARCHAR(40) NOT NULL, actor_user_id INTEGER NOT NULL, note TEXT NULL, supersedes_assessment_id INTEGER NULL, voided_at DATETIME NULL, voided_by_user_id INTEGER NULL, void_reason TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_operational_profiles') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, energy_kind VARCHAR(20) NOT NULL, ready_energy_target_percent INTEGER NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_operational_capabilities') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, capability_code VARCHAR(60) NOT NULL, is_applicable INTEGER NOT NULL DEFAULT 1)');
        $this->connection->query('CREATE TABLE ' . $this->table('scheduled_movement_locations') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NULL, movement_type VARCHAR(20) NOT NULL, location_class VARCHAR(40) NOT NULL, source_text VARCHAR(500) NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('airport_movement_workflows') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, movement_type VARCHAR(40) NOT NULL, scheduled_at DATETIME NOT NULL, garage VARCHAR(120) NULL, parking_level VARCHAR(40) NULL, parking_row VARCHAR(80) NULL, vehicle_staged_at DATETIME NULL, key_card_confirmed_at DATETIME NULL, guest_instructions_sent_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_positioning_plans') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_vehicle_id INTEGER NOT NULL, positioning_code VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NULL, invalidated_at DATETIME NULL)');
    }

    private function seedCompaniesAndTrips(): void
    {
        $vehicles = [];
        $trips = [];
        foreach (range(11, 18) as $vehicleId) {
            $vehicles[] = ['id' => $vehicleId, 'company_id' => 1];
            $trips[] = ['id' => 990 + $vehicleId, 'fleet_vehicle_id' => $vehicleId, 'starts_at' => '2026-09-08 08:00:00', 'ends_at' => '2026-09-08 18:00:00'];
        }
        $vehicles[] = ['id' => 27, 'company_id' => 2];
        $trips[] = ['id' => 2007, 'fleet_vehicle_id' => 27, 'starts_at' => '2026-09-08 08:00:00', 'ends_at' => '2026-09-08 18:00:00'];
        $this->connection->table('fleet_vehicles')->insertBatch($vehicles);
        $this->connection->table('turo_trips_normalized')->insertBatch($trips);
    }

    /** @param array<string, mixed> $overrides */
    private function insertChecklist(int $id, int $tripId, int $vehicleId, string $movementType, array $overrides = []): void
    {
        $this->connection->table('trip_movement_checklists')->insert(array_merge([
            'id' => $id,
            'turo_trip_normalized_id' => $tripId,
            'fleet_vehicle_id' => $vehicleId,
            'movement_type' => $movementType,
            'scheduled_at' => '2026-09-08 18:00:00',
            'readiness_status' => 'not_started',
            'vehicle_disposition' => null,
            'completed_at' => null,
            'completion_note' => null,
        ], $overrides));
    }

    /** @param list<array{string, string, string, string}> $items */
    private function insertItems(int $checklistId, array $items): void
    {
        foreach ($items as $sortOrder => [$code, $label, $state, $applicability]) {
            $this->connection->table('trip_movement_checklist_items')->insert([
                'trip_movement_checklist_id' => $checklistId,
                'item_code' => $code,
                'label' => $label,
                'applicability' => $applicability,
                'completion_state' => $state,
                'completed_at' => $state === 'complete' ? '2026-09-08 09:00:00' : null,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function insertEvent(int $id, int $companyId, int $vehicleId, int $tripId, string $eventCode, string $movementType, string $occurredAt, ?string $locationClass, ?string $voidedAt = null, array $overrides = []): void
    {
        $this->connection->table('trip_movement_events')->insert(array_merge([
            'id' => $id,
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_normalized_id' => $tripId,
            'event_code' => $eventCode,
            'movement_type' => $movementType,
            'occurred_at' => $occurredAt,
            'location_class' => $locationClass,
            'source' => 'operator',
            'actor_user_id' => 7,
            'voided_at' => $voidedAt,
        ], $overrides));
    }

    private function insertAssessment(int $id, int $companyId, int $vehicleId, int $tripId, int $eventId, string $movementType, string $cleanliness, int $energyPercent, string $capturedAt): void
    {
        $this->connection->table('movement_assessments')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_normalized_id' => $tripId,
            'trip_movement_event_id' => $eventId,
            'movement_type' => $movementType,
            'cleanliness' => $cleanliness,
            'energy_percent' => $energyPercent,
            'captured_at' => $capturedAt,
            'source' => 'operator',
            'actor_user_id' => 7,
        ]);
    }

    /** @param array<string, mixed> $projection @return array<string, mixed> */
    private function requirement(array $projection, string $code): array
    {
        foreach ($projection['requirements'] as $requirement) {
            if ($requirement['code'] === $code) {
                return $requirement;
            }
        }

        $this->fail('Missing readiness requirement: ' . $code);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
