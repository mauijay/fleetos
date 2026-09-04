<?php

use App\Services\Fleet\FleetCommandCenterViewModelService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;

/** @internal */
final class FleetCommandCenterReadOnlyTest extends CIUnitTestCase
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

        $viewModel = $this->createMock(FleetCommandCenterViewModelService::class);
        $viewModel->expects($this->exactly(5))->method('forToday')->willReturn([]);
        Services::injectMock('fleetCommandCenterViewModelService', $viewModel);

        $renderer = $this->createMock(View::class);
        $renderer->expects($this->exactly(5))->method('setData')->willReturnSelf();
        $renderer->expects($this->exactly(5))->method('render')->with('fleet_command_center/index')->willReturn('<!doctype html><title>Fleet Command Center</title>');
        Services::injectMock('renderer', $renderer);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testRepeatedGetDoesNotMaterializeMovementRows(): void
    {
        $this->withRoutes([['GET', '/', 'Home::index']]);
        $tables = $this->protectedTables();
        $before = $this->counts($tables);

        for ($request = 0; $request < 5; $request++) {
            $this->call('GET', '/')->assertOK();
        }

        $this->assertSame($before, $this->counts($tables));
    }

    /** @param list<string> $tables @return array<string, int> */
    private function counts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->connection->table($table)->countAllResults();
        }

        return $counts;
    }

    /** @return list<string> */
    private function protectedTables(): array
    {
        return [
            'trip_movement_checklists',
            'trip_movement_checklist_items',
            'airport_movement_workflows',
            'scheduled_movement_locations',
            'trip_movement_events',
            'movement_assessments',
            'movement_location_aliases',
            'vehicle_positioning_plans',
            'operational_fact_audits',
        ];
    }
}
