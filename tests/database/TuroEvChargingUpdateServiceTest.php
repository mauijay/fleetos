<?php

use App\Repositories\TuroEvChargingUpdateRepository;
use App\Services\Turo\TuroCsvReader;
use App\Services\Turo\TuroEvChargingUpdateService;
use App\Services\Turo\TuroImportAuditService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class TuroEvChargingUpdateServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private TuroEvChargingAuditStub $audit;
    private TuroEvChargingUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->resetSchema();
        $this->createSchema();
        $this->seed();
        $this->audit = new TuroEvChargingAuditStub();
        $this->service = new TuroEvChargingUpdateService(
            new TuroEvChargingUpdateRepository($this->connection),
            new TuroCsvReader(),
            $this->audit,
        );
    }

    public function testDryRunMakesZeroWritesAndReportsSourceMetadata(): void
    {
        $file = $this->csv([$this->sourceRow('SYNTH-RES-1', '$12.34', '$5.67')]);
        $before = $this->trip(101);

        $report = $this->service->execute($file, 1, 77, true);

        $this->assertSame($before, $this->trip(101));
        $this->assertSame('dry_run_complete', $report['status']);
        $this->assertSame(1, $report['reservation_count']);
        $this->assertSame(1, $report['matched_count']);
        $this->assertSame(1, $report['proposed_on_trip_changes']);
        $this->assertSame(1, $report['proposed_post_trip_changes']);
        $this->assertSame(hash_file('sha256', $file), $report['source_sha256']);
        $this->assertSame(basename($file), $report['source_filename']);
        $this->assertSame(77, $report['actor_user_id']);
        $this->assertNotEmpty($report['executed_at']);
        $this->assertSame([], $this->audit->updates);
    }

    public function testWriteChangesOnlyEvFieldsWithCentPrecisionAndAuditsActor(): void
    {
        $file = $this->csv([$this->sourceRow('SYNTH-RES-1', '$1,234.56', '$0.09')]);
        $before = $this->trip(101);

        $report = $this->service->execute($file, 1, 77, false);
        $after = $this->trip(101);

        $this->assertSame('applied', $report['status']);
        $this->assertSame(1, $report['applied_count']);
        $this->assertSame('1234.56', number_format((float) $after['on_trip_ev_charging_amount'], 2, '.', ''));
        $this->assertSame('0.09', number_format((float) $after['post_trip_ev_charging_amount'], 2, '.', ''));
        foreach ($before as $field => $value) {
            if (in_array($field, ['on_trip_ev_charging_amount', 'post_trip_ev_charging_amount'], true)) {
                continue;
            }
            $this->assertSame($value, $after[$field], "Non-EV field {$field} changed.");
        }
        $this->assertCount(1, $this->audit->updates);
        $this->assertSame(77, $this->audit->updates[0]['actor']);
        $this->assertSame(['on_trip_ev_charging_amount' => '0.00', 'post_trip_ev_charging_amount' => '0.00'], $this->audit->updates[0]['old']);
    }

    public function testCompanyIsolationRejectsOtherwiseMatchingReservation(): void
    {
        $file = $this->csv([$this->sourceRow('SYNTH-RES-2', '$4.00', '$0.00', 'SYNTH-TURO-2', 'SYNTHVIN000000002')]);

        $report = $this->service->execute($file, 1, null, true);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('company_mismatch', $report['rows'][0]['code']);
        $this->assertSame('0.00', number_format((float) $this->trip(102)['on_trip_ev_charging_amount'], 2, '.', ''));
    }

    public function testMissingAndUnknownReservationsAreRejected(): void
    {
        $file = $this->csv([
            $this->sourceRow('', '$1.00', '$0.00'),
            $this->sourceRow('SYNTH-MISSING', '$1.00', '$0.00'),
        ]);

        $report = $this->service->execute($file, 1, null, true);

        $this->assertSame(['missing_reservation', 'trip_not_found'], array_column($report['rows'], 'code'));
        $this->assertSame(2, $report['error_count']);
    }

    public function testVehicleAndVinMismatchesAreRejected(): void
    {
        $file = $this->csv([
            $this->sourceRow('SYNTH-RES-1', '$1.00', '$0.00', 'SYNTH-TURO-OTHER'),
            $this->sourceRow('SYNTH-RES-3', '$1.00', '$0.00', 'SYNTH-TURO-3', 'SYNTHVIN-WRONG'),
        ]);

        $report = $this->service->execute($file, 1, null, true);

        $this->assertSame(['vehicle_mismatch', 'vin_mismatch'], array_column($report['rows'], 'code'));
    }

    public function testExistingEqualValuesAreNoOp(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update([
            'on_trip_ev_charging_amount' => '12.34',
            'post_trip_ev_charging_amount' => '5.67',
        ]);
        $file = $this->csv([$this->sourceRow('SYNTH-RES-1', '$12.34', '$5.67')]);

        $report = $this->service->execute($file, 1, null, true);

        $this->assertSame('dry_run_complete', $report['status']);
        $this->assertSame(1, $report['no_change_count']);
        $this->assertSame(0, $report['proposed_on_trip_changes']);
        $this->assertSame(0, $report['proposed_post_trip_changes']);
    }

    public function testConflictingNonzeroValueBlocksEntireWriteBatch(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update(['on_trip_ev_charging_amount' => '9.99']);
        $file = $this->csv([
            $this->sourceRow('SYNTH-RES-1', '$12.34', '$0.00'),
            $this->sourceRow('SYNTH-RES-3', '$8.00', '$0.00', 'SYNTH-TURO-3', 'SYNTHVIN000000003'),
        ]);

        $report = $this->service->execute($file, 1, 77, false);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['conflict_count']);
        $this->assertSame('9.99', number_format((float) $this->trip(101)['on_trip_ev_charging_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $this->trip(103)['on_trip_ev_charging_amount'], 2, '.', ''));
        $this->assertSame([], $this->audit->updates);
    }

    public function testDuplicateSourceReservationAndOverPrecisionMoneyAreRejected(): void
    {
        $file = $this->csv([
            $this->sourceRow('SYNTH-RES-1', '$1.00', '$0.00'),
            $this->sourceRow('SYNTH-RES-1', '$1.00', '$0.00'),
            $this->sourceRow('SYNTH-RES-3', '35.555', '$0.00', 'SYNTH-TURO-3', 'SYNTHVIN000000003'),
        ]);

        $report = $this->service->execute($file, 1, null, true);

        $this->assertSame(['duplicate_source_reservation', 'duplicate_source_reservation', 'invalid_money'], array_column($report['rows'], 'code'));
        $this->assertSame(3, $report['error_count']);
    }

    public function testZeroSourceValuesNeverEraseAcceptedNonzeroValues(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 101)->update([
            'on_trip_ev_charging_amount' => '12.34',
            'post_trip_ev_charging_amount' => '5.67',
        ]);
        $file = $this->csv([$this->sourceRow('SYNTH-RES-1', '$0.00', '0')]);

        $report = $this->service->execute($file, 1, 77, false);

        $this->assertSame('applied', $report['status']);
        $this->assertSame(0, $report['applied_count']);
        $this->assertSame(1, $report['no_change_count']);
        $this->assertSame('12.34', number_format((float) $this->trip(101)['on_trip_ev_charging_amount'], 2, '.', ''));
        $this->assertSame('5.67', number_format((float) $this->trip(101)['post_trip_ev_charging_amount'], 2, '.', ''));
    }

    public function testNullValuesAcceptValidSourceAmounts(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 103)->update([
            'on_trip_ev_charging_amount' => null,
            'post_trip_ev_charging_amount' => null,
        ]);
        $file = $this->csv([$this->sourceRow('SYNTH-RES-3', '$2.00', '$3.00', 'SYNTH-TURO-3', 'SYNTHVIN000000003')]);

        $report = $this->service->execute($file, 1, 77, false);

        $this->assertSame(1, $report['applied_count']);
        $this->assertSame('2.00', number_format((float) $this->trip(103)['on_trip_ev_charging_amount'], 2, '.', ''));
        $this->assertSame('3.00', number_format((float) $this->trip(103)['post_trip_ev_charging_amount'], 2, '.', ''));
    }

    public function testRepositoryOptimisticPreconditionRejectsConcurrentChange(): void
    {
        $repository = new TuroEvChargingUpdateRepository($this->connection);

        $updated = $repository->updateOptimistically(101, 1, '99.00', '0.00', '1.00', '0.00');

        $this->assertFalse($updated);
        $this->assertSame('0.00', number_format((float) $this->trip(101)['on_trip_ev_charging_amount'], 2, '.', ''));
    }

    public function testWriteModeRequiresOperatorIdentity(): void
    {
        $file = $this->csv([$this->sourceRow('SYNTH-RES-1', '$1.00', '$0.00')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operator user id');

        $this->service->execute($file, 1, null, false);
    }

    private function resetSchema(): void
    {
        foreach (['turo_trips_normalized', 'vehicle_turo_listings', 'fleet_vehicles'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->connection->escapeIdentifiers($this->connection->prefixTable($table)));
        }
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_code VARCHAR(80), vin VARCHAR(32) NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_turo_listings') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NOT NULL, turo_vehicle_id VARCHAR(80), is_active INTEGER NOT NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME, gross_revenue_amount DECIMAL(10,2), on_trip_ev_charging_amount DECIMAL(10,2) NULL, post_trip_ev_charging_amount DECIMAL(10,2) NULL, currency_code CHAR(3), updated_at DATETIME NULL, deleted_at DATETIME NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'fleet_code' => 'SYNTH-VEHICLE-1', 'vin' => 'SYNTHVIN000000001'],
            ['id' => 2, 'company_id' => 2, 'fleet_code' => 'SYNTH-VEHICLE-2', 'vin' => 'SYNTHVIN000000002'],
            ['id' => 3, 'company_id' => 1, 'fleet_code' => 'SYNTH-VEHICLE-3', 'vin' => 'SYNTHVIN000000003'],
        ]);
        $this->connection->table('vehicle_turo_listings')->insertBatch([
            ['id' => 1, 'fleet_vehicle_id' => 1, 'turo_vehicle_id' => 'SYNTH-TURO-1', 'is_active' => 1],
            ['id' => 2, 'fleet_vehicle_id' => 2, 'turo_vehicle_id' => 'SYNTH-TURO-2', 'is_active' => 1],
            ['id' => 3, 'fleet_vehicle_id' => 3, 'turo_vehicle_id' => 'SYNTH-TURO-3', 'is_active' => 1],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            $this->tripRow(101, 1, 'SYNTH-RES-1'),
            $this->tripRow(102, 2, 'SYNTH-RES-2'),
            $this->tripRow(103, 3, 'SYNTH-RES-3'),
        ]);
    }

    /** @return array<string, mixed> */
    private function tripRow(int $id, int $vehicleId, string $reservation): array
    {
        return [
            'id' => $id,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_id' => $reservation,
            'turo_reservation_id' => $reservation,
            'guest_name' => 'Synthetic Guest',
            'starts_at' => '2026-09-01 10:00:00',
            'ends_at' => '2026-09-02 10:00:00',
            'gross_revenue_amount' => '321.00',
            'on_trip_ev_charging_amount' => '0.00',
            'post_trip_ev_charging_amount' => '0.00',
            'currency_code' => 'USD',
            'updated_at' => '2026-09-01 00:00:00',
            'deleted_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function trip(int $id): array
    {
        return $this->connection->table('turo_trips_normalized')->where('id', $id)->get()->getRowArray();
    }

    /** @return array<int, string> */
    private function sourceRow(string $reservation, string $onTrip, string $postTrip, string $vehicleId = 'SYNTH-TURO-1', string $vin = 'SYNTHVIN000000001'): array
    {
        return [$reservation, $vehicleId, $vin, $onTrip, $postTrip];
    }

    /** @param list<array<int, string>> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'turo_ev_');
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['Reservation ID', 'Vehicle id', 'VIN', 'On-trip EV charging', 'Post-trip EV charging'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function table(string $name): string
    {
        return $this->connection->escapeIdentifiers($this->connection->prefixTable($name));
    }
}

final class TuroEvChargingAuditStub extends TuroImportAuditService
{
    /** @var list<array{actor:?int,table:string,id:int,old:?array,new:?array}> */
    public array $updates = [];

    public function __construct()
    {
    }

    public function updated(?int $actorUserId, string $tableName, int $recordId, ?array $oldValues = null, ?array $newValues = null): void
    {
        $this->updates[] = [
            'actor' => $actorUserId,
            'table' => $tableName,
            'id' => $recordId,
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }
}
