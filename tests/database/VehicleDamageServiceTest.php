<?php

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleDamageRepository $repository;
    private VehicleDamageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        VehicleDamageDatabaseFixture::migrate($this->connection, false);
        VehicleDamageDatabaseFixture::seed($this->connection);
        $this->repository = new VehicleDamageRepository($this->connection);
        $this->service = new VehicleDamageService(
            $this->connection,
            $this->repository,
            new AuditLogRepository($this->connection),
            new LookupRepository($this->connection),
        );
    }

    public function testMigrationCreatesStableItemsAppendOnlyEventsAndEvidenceReferences(): void
    {
        $this->assertTrue($this->connection->tableExists('vehicle_damage_items'));
        $this->assertTrue($this->connection->tableExists('vehicle_damage_item_events'));
        $this->assertTrue($this->connection->tableExists('vehicle_damage_item_evidence'));
        $itemFields = array_column($this->connection->getFieldData('vehicle_damage_items'), 'name');
        $this->assertEqualsCanonicalizing([
            'id', 'company_id', 'fleet_vehicle_id', 'discovered_turo_trip_normalized_id',
            'discovered_trip_movement_event_id', 'vehicle_recovery_exception_id', 'damage_claim_id',
            'zone_code', 'damage_type_code', 'description', 'severity_code', 'status_code',
            'discovered_at', 'created_by', 'created_at', 'updated_by', 'updated_at',
            'resolved_by', 'resolved_at', 'resolution_note',
        ], $itemFields);
        $eventFields = array_column($this->connection->getFieldData('vehicle_damage_item_events'), 'name');
        $this->assertContains('prior_status_code', $eventFields);
        $this->assertContains('new_status_code', $eventFields);
        $this->assertContains('prior_severity_code', $eventFields);
        $this->assertContains('new_severity_code', $eventFields);
        $this->assertContains('prior_description', $eventFields);
        $this->assertContains('new_description', $eventFields);
    }

    public function testCreatesPersistentDamageWithImmutableCreationHistory(): void
    {
        $result = $this->create();

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $item = $this->repository->item(1, 10, (int) $result['id']);
        $this->assertSame('open', $item['status_code']);
        $this->assertSame('cosmetic', $item['severity_code']);
        $this->assertSame(100, (int) $item['discovered_turo_trip_normalized_id']);
        $events = $this->repository->events(1, (int) $result['id']);
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]['event_code']);
        $this->assertSame('Front bumper scrape', $events[0]['new_description']);
        $this->assertSame(1, $this->connection->table('audit_logs')->where('record_id', $result['id'])->countAllResults());
    }

    public function testAcceptedUnrepairedRemainsCurrentPhysicalDamage(): void
    {
        $id = (int) $this->create()['id'];
        $result = $this->service->transitionStatus(1, 10, $id, 'accepted_unrepaired', 'Cosmetic and accepted for continued use', 7);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->repository->currentForVehicle(1, 10));
        $this->assertCount(0, $this->repository->historyForVehicle(1, 10));
        $this->assertSame('accepted_unrepaired', $this->repository->item(1, 10, $id)['status_code']);
    }

    public function testWorseningAcrossLaterTripKeepsStableIdentityAndOriginalHistory(): void
    {
        $id = (int) $this->create()['id'];
        $result = $this->service->worsen(1, 10, $id, [
            'severity_code' => 'severe',
            'note' => 'Scratch expanded into a cracked panel',
            'occurred_at' => '2026-09-26 17:00:00',
        ], 7, ['trip_id' => 102, 'movement_event_id' => 1002]);

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($id, $result['id']);
        $events = $this->repository->events(1, $id);
        $this->assertSame(['created', 'worsened'], array_column($events, 'event_code'));
        $this->assertSame('cosmetic', $events[1]['prior_severity_code']);
        $this->assertSame('severe', $events[1]['new_severity_code']);
        $this->assertSame(102, (int) $events[1]['source_turo_trip_normalized_id']);
        $this->assertSame('Front bumper scrape', $events[0]['new_description']);
    }

    public function testWorseningCannotLowerSeverity(): void
    {
        $id = (int) $this->create(['severity_code' => 'severe'])['id'];
        $result = $this->service->worsen(1, 10, $id, ['severity_code' => 'cosmetic', 'note' => 'Incorrect attempt'], 7);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('severity_code', $result['errors']);
        $this->assertCount(1, $this->repository->events(1, $id));
    }

    public function testDetailCorrectionAndSeverityChangePreservePreviousAndNewState(): void
    {
        $id = (int) $this->create()['id'];
        $corrected = $this->service->correctDetails(1, 10, $id, [
            'zone_code' => 'passenger_side',
            'damage_type_code' => 'scratch_scuff',
            'description' => 'Passenger-side bumper scrape',
            'note' => 'Corrected the originally selected zone',
        ], 7);
        $severity = $this->service->changeSeverity(1, 10, $id, 'moderate', 'Deeper than first observed', 7);

        $this->assertTrue($corrected['success']);
        $this->assertTrue($severity['success']);
        $events = $this->repository->events(1, $id);
        $this->assertSame(['created', 'detail_corrected', 'severity_changed'], array_column($events, 'event_code'));
        $this->assertSame('Front bumper scrape', $events[1]['prior_description']);
        $this->assertSame('Passenger-side bumper scrape', $events[1]['new_description']);
        $this->assertSame('cosmetic', $events[2]['prior_severity_code']);
        $this->assertSame('moderate', $events[2]['new_severity_code']);
    }

    public function testRepairAndResolvedOtherLeaveCurrentConditionWithoutDeletingHistory(): void
    {
        $repairedId = (int) $this->create()['id'];
        $resolvedId = (int) $this->create(['zone_code' => 'rear', 'description' => 'Rear mark'])['id'];

        $this->assertTrue($this->service->transitionStatus(1, 10, $repairedId, 'repaired', 'Panel repaired and inspected', 7)['success']);
        $this->assertTrue($this->service->transitionStatus(1, 10, $resolvedId, 'resolved_other', 'Confirmed removable residue, not damage', 7)['success']);
        $this->assertCount(0, $this->repository->currentForVehicle(1, 10));
        $this->assertCount(2, $this->repository->historyForVehicle(1, 10));
        $this->assertSame(2, $this->connection->table('vehicle_damage_items')->countAllResults());
        $this->assertSame(4, $this->connection->table('vehicle_damage_item_events')->countAllResults());
    }

    public function testRecoveryExceptionResolutionDoesNotResolveDamageItem(): void
    {
        $id = (int) $this->create(['recovery_exception_id' => 500])['id'];
        $this->connection->table('vehicle_recovery_exceptions')->where('id', 500)->update(['status' => 'resolved']);

        $item = $this->repository->item(1, 10, $id);
        $this->assertSame('open', $item['status_code']);
        $this->assertSame('resolved', $item['recovery_exception_status']);
        $this->assertCount(1, $this->repository->currentForVehicle(1, 10));
    }

    public function testClaimClosureDoesNotResolveDamageItem(): void
    {
        $id = (int) $this->create(['damage_claim_id' => 700])['id'];
        $this->connection->table('damage_claims')->where('id', 700)->update(['closed_on' => '2026-09-27']);

        $this->assertSame('open', $this->repository->item(1, 10, $id)['status_code']);
        $this->assertCount(1, $this->repository->currentForVehicle(1, 10));
    }

    public function testUnsafeWarningStateDoesNotChangeVehicleAvailability(): void
    {
        $before = $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRowArray();
        $this->create(['severity_code' => 'unsafe', 'description' => 'Unsafe synthetic tire damage']);
        $workspace = $this->service->workspace(1, 10);
        $after = $this->connection->table('fleet_vehicles')->where('id', 10)->get()->getRowArray();

        $this->assertTrue($workspace['has_unsafe']);
        $this->assertSame($before['vehicle_status_id'], $after['vehicle_status_id']);
        $this->assertSame($before['out_of_service_date'], $after['out_of_service_date']);
    }

    public function testExactCompanyVehicleTripEventAndClaimScopingIsEnforced(): void
    {
        foreach ([
            ['trip_id' => 200],
            ['trip_id' => 100, 'movement_event_id' => 1001],
            ['damage_claim_id' => 701],
            ['trip_id' => 100, 'recovery_exception_id' => 501],
        ] as $context) {
            $result = $this->create($context);
            $this->assertFalse($result['success'], json_encode($context, JSON_THROW_ON_ERROR));
            $this->assertArrayHasKey('context', $result['errors']);
        }
        $this->assertSame(0, $this->connection->table('vehicle_damage_items')->countAllResults());
    }

    public function testWorkflowTrustedContextIgnoresPostedTripAndMovementEventIds(): void
    {
        $result = $this->service->create(1, 10, [
            'zone_code' => 'front',
            'damage_type_code' => 'dent',
            'description' => 'Workflow-scoped damage',
            'severity_code' => 'cosmetic',
            'discovered_at' => '2026-09-25 09:30:00',
            'trip_id' => 101,
            'movement_event_id' => 1001,
        ], 7, ['trip_id' => 100, 'movement_event_id' => null, 'recovery_exception_id' => null]);

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $item = $this->repository->item(1, 10, (int) $result['id']);
        $this->assertSame(100, (int) $item['discovered_turo_trip_normalized_id']);
        $this->assertNull($item['discovered_trip_movement_event_id']);
    }

    public function testRecoveryExceptionMayCreateOnlyOneLinkedStableDamageItem(): void
    {
        $first = $this->create(['recovery_exception_id' => 500]);
        $second = $this->create(['recovery_exception_id' => 500, 'description' => 'Duplicate attempt']);

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame(1, $this->connection->table('vehicle_damage_items')->countAllResults());
    }

    public function testExternalAndExistingEvidenceAreLinkedWithoutParallelUpload(): void
    {
        $external = $this->create(['external_reference' => 'Turo photo set ABC-123', 'evidence_label' => 'Guest return photos']);
        $image = $this->create(['description' => 'Second item', 'image_id' => 900]);
        $file = $this->create(['description' => 'Third item', 'file_id' => 800]);

        $this->assertTrue($external['success']);
        $this->assertTrue($image['success']);
        $this->assertTrue($file['success']);
        $this->assertSame(3, $this->connection->table('vehicle_damage_item_evidence')->countAllResults());
        $this->assertSame('Turo photo set ABC-123', $this->repository->evidence(1, (int) $external['id'])[0]['external_reference']);
    }

    public function testCrossVehicleEvidenceOwnershipIsRejected(): void
    {
        $wrongFile = $this->create(['file_id' => 801]);
        $wrongImage = $this->create(['image_id' => 901]);

        $this->assertFalse($wrongFile['success']);
        $this->assertFalse($wrongImage['success']);
        $this->assertSame(0, $this->connection->table('vehicle_damage_items')->countAllResults());
    }

    public function testReadWorkspaceHasNoGetSideMutation(): void
    {
        $this->create(['external_reference' => 'evidence-ref']);
        $before = $this->counts();

        $first = $this->service->workspace(1, 10);
        $second = $this->service->workspace(1, 10);

        $this->assertSame($before, $this->counts());
        $this->assertEquals($first, $second);
    }

    public function testOtherVehicleAndCompanyCannotReadDamageItem(): void
    {
        $id = (int) $this->create()['id'];

        $this->assertNull($this->repository->item(1, 11, $id));
        $this->assertNull($this->repository->item(2, 20, $id));
        $this->assertSame([], $this->repository->currentForVehicle(1, 11));
    }

    /** @param array<string,mixed> $overrides @return array{success:bool,id?:int,errors:array<string,string>} */
    private function create(array $overrides = []): array
    {
        return $this->service->create(1, 10, array_merge([
            'zone_code' => 'front',
            'damage_type_code' => 'scratch_scuff',
            'description' => 'Front bumper scrape',
            'severity_code' => 'cosmetic',
            'discovered_at' => '2026-09-25 09:30:00',
            'trip_id' => 100,
            'movement_event_id' => 1000,
        ], $overrides), 7);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'items' => $this->connection->table('vehicle_damage_items')->countAllResults(),
            'events' => $this->connection->table('vehicle_damage_item_events')->countAllResults(),
            'evidence' => $this->connection->table('vehicle_damage_item_evidence')->countAllResults(),
            'audit' => $this->connection->table('audit_logs')->countAllResults(),
        ];
    }

}
