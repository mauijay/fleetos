<?php

use App\Services\Turo\TuroTransactionRelinkingService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class TuroTransactionRelinkingServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        foreach (['turo_transactions_normalized', 'turo_trips_normalized', 'turo_trip_raw', 'vehicle_turo_listings'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_vehicle_id VARCHAR(80), is_active INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trip_raw') . ' (id INTEGER PRIMARY KEY, external_vehicle_id VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, turo_trip_raw_id INTEGER, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_transactions_normalized') . ' (id INTEGER PRIMARY KEY, turo_trip_normalized_id INTEGER NULL, fleet_vehicle_id INTEGER NULL, external_trip_id VARCHAR(80) NULL, updated_at DATETIME NULL)');
        $this->connection->table('vehicle_turo_listings')->insert(['id' => 1, 'fleet_vehicle_id' => 11, 'turo_vehicle_id' => 'bronco-turo', 'is_active' => 1]);
        $this->connection->table('turo_trip_raw')->insert(['id' => 4, 'external_vehicle_id' => 'bronco-turo']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 8, 'turo_trip_raw_id' => 4, 'fleet_vehicle_id' => 11, 'turo_trip_id' => 'trip-8', 'deleted_at' => null]);
        $this->connection->table('turo_transactions_normalized')->insertBatch([
            ['id' => 20, 'external_trip_id' => 'trip-8', 'turo_trip_normalized_id' => null, 'fleet_vehicle_id' => null],
            ['id' => 21, 'external_trip_id' => 'unrelated', 'turo_trip_normalized_id' => null, 'fleet_vehicle_id' => null],
        ]);
    }

    public function testRelinkingIsExactUpdateOnlyAndIdempotent(): void
    {
        $service = new TuroTransactionRelinkingService($this->connection);
        $first = $service->relinkForTuroVehicle('bronco-turo');
        $second = $service->relinkForTuroVehicle('bronco-turo');

        $this->assertSame(['examined' => 1, 'relinked' => 1, 'unchanged' => 0], $first);
        $this->assertSame(['examined' => 1, 'relinked' => 0, 'unchanged' => 1], $second);
        $this->assertSame(2, $this->connection->table('turo_transactions_normalized')->countAllResults());
        $linked = $this->connection->table('turo_transactions_normalized')->where('id', 20)->get()->getRowArray();
        $this->assertSame(8, (int) $linked['turo_trip_normalized_id']);
        $this->assertSame(11, (int) $linked['fleet_vehicle_id']);
        $this->assertNull($this->connection->table('turo_transactions_normalized')->where('id', 21)->get()->getRowArray()['fleet_vehicle_id']);
    }

    private function table(string $table): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($table));
    }
}
