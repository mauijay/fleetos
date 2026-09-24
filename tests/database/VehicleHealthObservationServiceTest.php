<?php

use App\Database\Migrations\CreateVehicleHealthObservationFoundation;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleHealthObservationRepository;
use App\Repositories\VehicleHealthPolicyRepository;
use App\Services\Fleet\CurrentVehicleOdometerResolver;
use App\Services\Fleet\VehicleHealthObservationService;
use App\Services\Fleet\VehicleHealthPolicyService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-24-000026_CreateVehicleHealthObservationFoundation.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleHealthObservationServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleHealthObservationRepository $repository;
    private CurrentVehicleOdometerResolver $resolver;
    private VehicleHealthObservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep SQLite schema metadata isolated from the migration contract tests.
        $this->connection = Database::connect('tests', false);
        $this->dropTables();
        $this->createPrerequisites();
        (new CreateVehicleHealthObservationFoundation(Database::forge($this->connection)))->up();
        $this->seed();
        $this->repository = new VehicleHealthObservationRepository($this->connection);
        $this->resolver = new CurrentVehicleOdometerResolver($this->repository);
        $this->service = new VehicleHealthObservationService(
            $this->connection,
            $this->repository,
            $this->resolver,
            new AuditLogRepository($this->connection),
            new LookupRepository($this->connection),
        );
    }

    public function testCreatesCompleteWholePressureSnapshot(): void
    {
        $result = $this->service->recordTirePressure(1, 10, [
            'lf_psi' => '35', 'rf_psi' => '36', 'lr_psi' => '42', 'rr_psi' => '45',
            'recommended_psi' => '42', 'observed_at' => '2026-09-23 09:00:00', 'note' => 'Manual check',
        ], 7, now: $this->time());

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $row = $this->repository->latestTirePressure(1, 10, '2026-09-23 10:00:00');
        $this->assertSame('35', (string) $row['lf_psi']);
        $this->assertSame('45', (string) $row['rr_psi']);
        $this->assertSame('42', (string) $row['recommended_psi']);
        $this->assertSame('manual', $row['source']);
        $this->assertSame(1, $this->connection->table('audit_logs')->where('table_name', 'vehicle_health_observations')->countAllResults());
    }

    public function testRejectsIncompleteInvalidAndFutureManualPressure(): void
    {
        $result = $this->service->recordTirePressure(1, 10, [
            'lf_psi' => '-1', 'rf_psi' => '35', 'lr_psi' => '36', 'recommended_psi' => '42',
            'observed_at' => '2026-09-23 10:00:01',
        ], 7, now: $this->time());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('lf_psi', $result['errors']);
        $this->assertArrayHasKey('rr_psi', $result['errors']);
        $this->assertArrayHasKey('observed_at', $result['errors']);
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());

        $invalidDate = $this->service->recordTirePressure(1, 10, [
            'lf_psi' => '35', 'rf_psi' => '35', 'lr_psi' => '36', 'rr_psi' => '36', 'recommended_psi' => '42',
            'observed_at' => '2026-02-31 09:00:00',
        ], 7, now: $this->time());
        $this->assertFalse($invalidDate['success']);
        $this->assertArrayHasKey('observed_at', $invalidDate['errors']);
    }

    public function testRejectsDecimalZeroNegativeNonnumericAndAboveMaximumPressure(): void
    {
        foreach (['35.5', '0', '-1', 'not-a-number', '201'] as $invalid) {
            $result = $this->service->recordTirePressure(1, 10, [
                'lf_psi' => $invalid, 'rf_psi' => '36', 'lr_psi' => '42', 'rr_psi' => '45',
                'recommended_psi' => '42', 'observed_at' => '2026-09-23 09:00:00',
            ], 7, now: $this->time());

            $this->assertFalse($result['success'], 'Expected invalid PSI to be rejected: ' . $invalid);
            $this->assertArrayHasKey('lf_psi', $result['errors']);
        }
        $this->assertSame(0, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testOdometerAuthorityUsesObservedTimeAndUpdatesCompatibilityCache(): void
    {
        $newer = $this->service->recordOdometer(1, 10, ['odometer_miles' => '12345', 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $older = $this->service->recordOdometer(1, 10, ['odometer_miles' => '12000', 'observed_at' => '2026-09-20 09:00:00'], 7, now: $this->time());

        $this->assertTrue($newer['success']);
        $this->assertFalse($older['success'], 'A lower ordinary reading must use correction.');
        $this->assertSame(12345, $this->resolver->resolve(1, 10, $this->time())['odometer_miles']);
        $this->assertSame(12345, (int) $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRow('odometer_miles'));
    }

    public function testDelayedOlderHigherObservationDoesNotReplaceNewerObservedFact(): void
    {
        $this->service->recordOdometer(1, 10, ['odometer_miles' => 12345, 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $this->service->recordOdometer(1, 10, ['odometer_miles' => 13000, 'observed_at' => '2026-09-21 09:00:00'], 7, now: $this->time());

        $this->assertSame(12345, $this->resolver->resolve(1, 10, $this->time())['odometer_miles']);
        $this->assertSame(12345, (int) $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRow('odometer_miles'));
    }

    public function testTieBreakCorrectionAndVoidPreserveHistory(): void
    {
        $first = $this->service->recordOdometer(1, 10, ['odometer_miles' => 12000, 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $second = $this->service->recordOdometer(1, 10, ['odometer_miles' => 12345, 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $this->assertSame((int) $second['id'], $this->resolver->resolve(1, 10, $this->time())['observation_id']);

        $corrected = $this->service->correct(1, 10, (int) $second['id'], [
            'odometer_miles' => 12100,
            'observed_at' => '2026-09-23 09:00:00',
            'correction_reason' => 'Mistyped mileage',
        ], 7, $this->time());

        $this->assertTrue($corrected['success']);
        $this->assertSame(12100, $this->resolver->resolve(1, 10, $this->time())['odometer_miles']);
        $this->assertNotNull($this->repository->observation(1, 10, (int) $second['id'])['voided_at']);
        $this->assertSame((int) $second['id'], (int) $this->repository->observation(1, 10, (int) $corrected['id'])['supersedes_observation_id']);

        $voided = $this->service->void(1, 10, (int) $corrected['id'], 'Correction also invalid', 7, $this->time());
        $this->assertTrue($voided['success']);
        $this->assertSame((int) $first['id'], $this->resolver->resolve(1, 10, $this->time())['observation_id']);
        $this->assertCount(3, $this->repository->history(1, 10, 'odometer'));
    }

    public function testFutureAndCrossCompanyObservationsAreExcluded(): void
    {
        $this->connection->table('vehicle_health_observations')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => 10, 'observation_code' => 'odometer', 'observed_at' => '2026-09-24 10:00:00',
            'received_at' => '2026-09-23 10:00:00', 'source' => 'import', 'created_at' => '2026-09-23 10:00:00',
        ]);
        $futureId = (int) $this->connection->insertID();
        $this->connection->table('vehicle_odometer_observations')->insert(['vehicle_health_observation_id' => $futureId, 'odometer_miles' => 15000]);

        $this->assertNull($this->resolver->resolve(1, 10, $this->time()));
        $result = $this->service->recordOdometer(2, 10, ['odometer_miles' => 12000, 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('vehicle', $result['errors']);
    }

    public function testExternalIdentityIsIdempotentAndHashConflictIsRejected(): void
    {
        $hash = hash('sha256', 'one');
        $data = ['odometer_miles' => 12000, 'observed_at' => '2026-09-23 09:00:00'];
        $first = $this->service->recordOdometer(1, 10, $data, null, 'import', 'reading-1', $hash, $this->time());
        $again = $this->service->recordOdometer(1, 10, $data, null, 'import', 'reading-1', $hash, $this->time());
        $conflict = $this->service->recordOdometer(1, 10, $data, null, 'import', 'reading-1', hash('sha256', 'two'), $this->time());

        $this->assertTrue($first['success']);
        $this->assertTrue($again['existing']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertFalse($conflict['success']);
        $this->assertSame(1, $this->connection->table('vehicle_health_observations')->countAllResults());
    }

    public function testVoidingOnlyAuthoritativeOdometerDoesNotEraseCompatibilityScalar(): void
    {
        $created = $this->service->recordOdometer(1, 10, ['odometer_miles' => 12345, 'observed_at' => '2026-09-23 09:00:00'], 7, now: $this->time());
        $voided = $this->service->void(1, 10, (int) $created['id'], 'Reading belonged to another vehicle', 7, $this->time());

        $this->assertTrue($voided['success']);
        $this->assertNull($this->resolver->resolve(1, 10, $this->time()));
        $this->assertSame(12345, (int) $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRow('odometer_miles'));
    }

    public function testPolicyValidationAndAudit(): void
    {
        $service = new VehicleHealthPolicyService(
            $this->connection,
            new VehicleHealthPolicyRepository($this->connection),
            $this->repository,
            new AuditLogRepository($this->connection),
            new LookupRepository($this->connection),
        );
        $invalid = $service->saveTirePressurePolicy(1, 10, [
            'interval_value' => 30, 'recommended_psi' => 50, 'acceptable_min_psi' => 40, 'acceptable_max_psi' => 44,
        ], 7);
        $valid = $service->saveTirePressurePolicy(1, 10, [
            'interval_value' => 30, 'recommended_psi' => 42, 'acceptable_min_psi' => 40, 'acceptable_max_psi' => 44,
            'safety_min_psi' => 35, 'safety_max_psi' => 50, 'is_enabled' => 1,
        ], 7);
        $decimal = $service->saveTirePressurePolicy(1, 10, [
            'interval_value' => 30, 'recommended_psi' => '42', 'acceptable_min_psi' => '35.5', 'acceptable_max_psi' => '44',
        ], 7);

        $this->assertFalse($invalid['success']);
        $this->assertArrayHasKey('recommended_psi', $invalid['errors']);
        $this->assertTrue($valid['success']);
        $this->assertFalse($decimal['success']);
        $this->assertArrayHasKey('acceptable_min_psi', $decimal['errors']);
        $this->assertSame(1, $this->connection->table('vehicle_health_policies')->countAllResults());
        $this->assertSame(1, $this->connection->table('audit_logs')->where('table_name', 'vehicle_health_policies')->countAllResults());
    }

    private function dropTables(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['vehicle_health_policies', 'vehicle_odometer_observations', 'vehicle_tire_pressure_observations', 'vehicle_health_observations', 'audit_logs', 'lookup_values', 'lookup_types', 'fleet_vehicles', 'vehicle_statuses', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_statuses') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80), name VARCHAR(120))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, vehicle_status_id INTEGER, fleet_number INTEGER NULL, fleet_code VARCHAR(80), display_name VARCHAR(150), odometer_miles INTEGER NULL, in_service_date DATE NULL, out_of_service_date DATE NULL, deleted_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, lookup_type_id INTEGER, code VARCHAR(80), is_active BOOLEAN)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER NULL, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('vehicle_statuses')->insert(['id' => 1, 'code' => 'active', 'name' => 'Active']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 10, 'company_id' => 1, 'vehicle_status_id' => 1, 'fleet_number' => 3, 'fleet_code' => 'Spaceship03', 'display_name' => 'Spaceship03', 'odometer_miles' => 11000]);
        $this->connection->table('lookup_types')->insert(['id' => 1, 'code' => 'audit_action']);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'lookup_type_id' => 1, 'code' => 'created', 'is_active' => 1],
            ['id' => 2, 'lookup_type_id' => 1, 'code' => 'updated', 'is_active' => 1],
        ]);
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('Pacific/Honolulu'));
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
