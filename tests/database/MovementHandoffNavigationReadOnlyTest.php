<?php

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactPresentationService;
use App\Services\Fleet\TripMovementChecklistService;
use App\Services\View\AssetManifestService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;

/** @internal */
final class MovementHandoffNavigationReadOnlyTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach ($this->protectedTables() as $table) {
            $prefixed = $this->connection->prefixTable($table);
            $this->connection->query('DROP TABLE IF EXISTS ' . $prefixed);
            $this->connection->query('CREATE TABLE ' . $prefixed . ' (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        }

        $checklist = $this->createMock(TripMovementChecklistService::class);
        $checklist->method('checklist')->willReturn([
            'exists' => true,
            'id' => 41,
            'fleet_vehicle_id' => 9,
            'turo_trip_normalized_id' => 101,
            'movement_type' => 'pickup',
        ]);
        Services::injectMock('tripMovementChecklistService', $checklist);

        $facts = $this->createMock(MovementOperationalFactPresentationService::class);
        $facts->method('latestForTrip')->willReturn(null);
        $facts->method('tripFacts')->willReturn(['pickup' => null, 'return' => null]);
        Services::injectMock('movementOperationalFactPresentationService', $facts);

        $events = $this->createMock(MovementEventService::class);
        $events->method('latestForTrip')->willReturn(null);
        Services::injectMock('movementEventService', $events);

        $location = $this->createMock(CurrentVehicleLocationService::class);
        $location->method('resolve')->willReturn([
            'location_class' => 'unknown',
            'location_detail' => null,
            'observed_at' => null,
            'source' => null,
            'actor_user_id' => null,
            'trip_id' => null,
            'event_id' => null,
            'event_code' => null,
            'position_semantics' => 'unknown',
            'location_label' => 'Last known location',
            'age_seconds' => null,
        ]);
        Services::injectMock('currentVehicleLocationService', $location);

        $repository = $this->createMock(OperationalFactsRepository::class);
        $repository->method('tripContext')->willReturn(['previous' => null, 'current' => null, 'next' => null]);
        $repository->method('vehicle')->willReturn(['id' => 9, 'fleet_code' => 'Test Vehicle 09']);
        $repository->method('vehicleTripHistory')->willReturn([
            ['id' => 101, 'fleet_vehicle_id' => 9, 'guest_name' => 'Test Guest', 'starts_at' => '2026-10-06 21:30:00', 'ends_at' => '2026-10-12 06:00:00', 'movement_href' => '/operations/checklists/41'],
        ]);
        Services::injectMock('operationalFactsRepository', $repository);

        $assets = $this->createMock(AssetManifestService::class);
        $assets->method('appAssets')->willReturn(['css' => null, 'js' => null]);
        Services::injectMock('assetManifestService', $assets);

        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnSelf();
        $renderer->method('render')->willReturn('<!doctype html><title>Movement Checklist</title>');
        CoreServices::injectMock('renderer', $renderer);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testOpeningRecordHandoffEntryCreatesZeroMovementFacts(): void
    {
        $this->withRoutes([['GET', 'operations/checklists/(:num)', 'TripMovementChecklists::show/$1']]);
        $before = $this->factCounts();

        $this->call('GET', '/operations/checklists/41?action=handoff')->assertOK();
        $this->call('GET', '/operations/checklists/41?action=confirm-pickup')->assertOK();
        $this->call('GET', '/operations/checklists/41?action=position')->assertOK();
        $this->call('GET', '/operations/checklists/41')->assertOK();

        $this->assertSame($before, $this->factCounts());
    }

    public function testHistoryAndCrossMovementGetNavigationCreateNoOperationalRows(): void
    {
        $this->withRoutes([
            ['GET', 'operations/vehicles/(:num)/trip-history', 'TripMovementChecklists::vehicleTripHistory/$1'],
            ['GET', 'operations/checklists/(:num)', 'TripMovementChecklists::show/$1'],
        ]);
        $before = $this->factCounts();

        $this->call('GET', '/operations/vehicles/9/trip-history?trip=101')->assertOK();
        $this->call('GET', '/operations/checklists/40')->assertOK();
        $this->call('GET', '/operations/checklists/41')->assertOK();

        $this->assertSame($before, $this->factCounts());
    }

    /** @return array<string, int> */
    private function factCounts(): array
    {
        $counts = [];
        foreach ($this->protectedTables() as $table) {
            $counts[$table] = $this->connection->table($table)->countAllResults();
        }

        return $counts;
    }

    /** @return list<string> */
    private function protectedTables(): array
    {
        return [
            'trip_movement_events',
            'movement_assessments',
            'trip_movement_checklists',
            'trip_movement_checklist_items',
            'operational_fact_audits',
            'vehicle_positioning_plans',
            'airport_movement_workflows',
        ];
    }
}
