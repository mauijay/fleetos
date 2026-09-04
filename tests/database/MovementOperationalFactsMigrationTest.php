<?php

use App\Database\Migrations\CreateMovementOperationalFacts;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-31-000015_CreateMovementOperationalFacts.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class MovementOperationalFactsMigrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'scheduled_movement_locations',
        'trip_movement_events',
        'movement_assessments',
        'vehicle_operational_profiles',
        'vehicle_operational_capabilities',
        'operational_fact_audits',
    ];

    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (array_reverse(self::TABLES) as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('DROP TABLE IF EXISTS ' . $this->table('movement_slice_sentinel'));
        $this->connection->query('CREATE TABLE ' . $this->table('movement_slice_sentinel') . ' (id INTEGER PRIMARY KEY, marker VARCHAR(20))');
        $this->connection->table('movement_slice_sentinel')->insert(['id' => 1, 'marker' => 'preserve']);
    }

    public function testUpCreatesOperationalFactTablesWithoutChangingExistingData(): void
    {
        $this->migration()->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->connection->tableExists($table), $table . ' was not created.');
        }
        $sentinel = $this->connection->table('movement_slice_sentinel')->where('id', 1)->get()->getRowArray();
        $this->assertSame('preserve', $sentinel['marker']);
        $this->assertContains('supersedes_event_id', $this->connection->getFieldNames('trip_movement_events'));
        $this->assertContains('voided_at', $this->connection->getFieldNames('movement_assessments'));
    }

    public function testDownDropsOnlyOperationalFactTables(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->connection->tableExists($table), $table . ' was not dropped.');
        }
        $sentinel = $this->connection->table('movement_slice_sentinel')->where('id', 1)->get()->getRowArray();
        $this->assertSame('preserve', $sentinel['marker']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }

    private function migration(): CreateMovementOperationalFacts
    {
        return new CreateMovementOperationalFacts(Database::forge($this->connection));
    }
}
