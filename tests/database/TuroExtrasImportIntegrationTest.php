<?php

use App\Database\Migrations\CreateExtraFulfillment;
use App\Database\Migrations\CreateFleetExtrasFoundation;
use App\Repositories\AuditLogRepository;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use App\Repositories\MovementReadinessReadModelRepository;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetExtraService;
use App\Services\Fleet\MovementBoardIntelligenceService;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\MovementReadinessReadService;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\TripExtraFulfillmentService;
use App\Services\Fleet\VehiclePositioningPlanService;
use App\Services\Turo\TuroExtrasImportService;
use App\Services\Turo\TuroImportAuditService;
use App\Validation\Turo\TuroExtrasPayloadValidator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-13-000021_CreateFleetExtrasFoundation.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-09-21-000024_CreateExtraFulfillment.php';

/** @internal */
final class TuroExtrasImportIntegrationTest extends CIUnitTestCase
{
    private const TABLES = [
        'trip_extra_fulfillment_audits', 'trip_extra_fulfillments', 'trip_movement_events', 'fleet_trip_commitments',
        'turo_extra_selections', 'turo_extra_reservation_snapshots', 'fleet_extra_source_mappings', 'fleet_extras',
        'audit_logs', 'turo_import_errors', 'turo_import_batches', 'turo_trips_normalized', 'fleet_vehicles',
        'lookup_values', 'lookup_types', 'companies',
    ];

    private BaseConnection $connection;
    private FleetExtraRepository $repository;
    private TuroExtrasImportService $importer;
    private FleetExtraService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (self::TABLES as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->createPrerequisites();
        (new CreateFleetExtrasFoundation(Database::forge($this->connection)))->up();
        (new CreateExtraFulfillment(Database::forge($this->connection)))->up();
        $this->seedLookups();
        $this->connection->query('PRAGMA foreign_keys = ON');

        $this->repository = new FleetExtraRepository($this->connection);
        $lookups = new LookupRepository($this->connection);
        $audit = new AuditLogRepository($this->connection);
        $fulfillments = new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->connection));
        $this->importer = new TuroExtrasImportService(
            $this->repository,
            new TuroExtrasPayloadValidator(),
            $lookups,
            new TuroImportBatchRepository($this->connection),
            new TuroImportErrorRepository($this->connection),
            new TuroImportAuditService($audit, $lookups),
            $fulfillments,
        );
        $this->catalog = new FleetExtraService($this->repository, $audit, $lookups, $fulfillments);
    }

    protected function tearDown(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        parent::tearDown();
    }

    #[DataProvider('overdueEvidenceCases')]
    public function testOverduePickupRemainsVisibleAcrossSurfaces(string $status, bool $stale): void
    {
        $asOf = $this->overdueTrip($status);
        if ($stale) {
            $this->observe('2029-12-17T12:00:00-10:00');
        }
        $model = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame($stale ? 'stale_empty' : 'never', $model['state']);
        $this->assertSame('handoff_missing', $model['lifecycle']);
        $this->assertOverdueSurfaces($asOf, true);
    }

    public static function overdueEvidenceCases(): array
    {
        return [['booked', false], ['booked', true], ['in_progress', false]];
    }

    #[DataProvider('handoffStatusCases')]
    public function testOverduePickupClosesOnlyAtItsOwnHandoff(string $status): void
    {
        $asOf = $this->overdueTrip($status);
        $this->assertOverdueSurfaces($asOf, true);
        $this->connection->table('trip_movement_events')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100,
            'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-01 09:05:00',
        ]);
        $model = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame('handoff_closed', $model['lifecycle']);
        $this->assertTrue($model['optional_active_refresh']);
        $this->assertOverdueSurfaces($asOf, false);
    }

    public static function handoffStatusCases(): array
    {
        return [['booked'], ['in_progress']];
    }

    public function testOtherTripsHandoffCannotHideOverduePickup(): void
    {
        $asOf = $this->overdueTrip('booked');
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 101, 'fleet_vehicle_id' => 1, 'turo_trip_id' => 'synthetic-other-trip',
            'turo_reservation_id' => '70000003', 'starts_at' => '2030-01-01 10:00:00',
            'ends_at' => '2030-01-02 10:00:00',
        ]);
        $this->connection->table('trip_movement_events')->insert([
            'company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 101,
            'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-01 10:05:00',
        ]);
        $batch = $this->catalog->refreshVerificationForCompany(1, $asOf);
        $this->assertArrayHasKey(101, $batch);
        $this->assertFalse($batch[101]['refresh_required']);
        $this->assertOverdueSurfaces($asOf, true);
    }

    #[DataProvider('inactiveOverdueCases')]
    public function testInactiveOverduePickupsStayExcluded(string $status, array $lifecycle): void
    {
        $asOf = $this->overdueTrip($status);
        if ($lifecycle !== []) {
            $this->connection->table('turo_trips_normalized')->where('id', 100)->update($lifecycle);
        }
        if ($status === 'completed') {
            $this->connection->table('trip_movement_events')->insertBatch([
                ['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100,
                    'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-01 09:05:00'],
                ['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100,
                    'event_code' => 'vehicle_recovered', 'occurred_at' => '2030-01-02 09:05:00'],
            ]);
        }
        $this->assertSame([], $this->repository->refreshCandidates(1, null, null, $asOf));
        $this->assertSame([], $this->catalog->workspace(1, null, $asOf)['refresh_candidates']);
        $this->assertSame([], $this->catalog->refreshVerificationForCompany(1, $asOf));
        $model = $this->catalog->verificationForTrips(1, [100], $asOf)[100] ?? null;
        if ($model !== null) {
            $this->assertFalse($model['refresh_required']);
            $this->assertFalse($model['advisory']);
            $this->assertSame('inactive', $model['lifecycle']);
        }
        $card = $this->overdueBoard($asOf);
        $this->assertSame([], $card['extras_verification_actions']);
        $this->assertSame(0, $card['readiness_blocking_remaining']);
        $this->assertSame([], $this->overdueQueue($card));
    }

    public static function inactiveOverdueCases(): array
    {
        return [
            ['canceled', []], ['invalid', []], ['completed', []],
            ['booked', ['canceled_at' => '2030-01-01 08:00:00']],
            ['booked', ['deleted_at' => '2030-01-01 08:00:00']],
        ];
    }

    private function overdueTrip(string $status): DateTimeImmutable
    {
        $this->connection->table('lookup_types')->insert(['code' => 'trip_status', 'name' => 'Synthetic trip status']);
        $typeId = (int) $this->connection->insertID();
        $this->connection->table('lookup_values')->insert(['lookup_type_id' => $typeId, 'code' => $status, 'name' => 'Synthetic lifecycle']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'trip_status_lookup_value_id' => (int) $this->connection->insertID(),
            'starts_at' => '2030-01-01 09:00:00', 'ends_at' => '2030-01-02 09:00:00',
        ]);
        return new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu');
    }

    private function assertOverdueSurfaces(DateTimeImmutable $asOf, bool $required): void
    {
        $href = '/turo/extras?reservation_id=70000001#export-heading';
        $model = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame($required, $model['refresh_required']);
        $batch = $this->catalog->refreshVerificationForCompany(1, $asOf);
        $this->assertArrayHasKey(100, $batch);
        $this->assertSame($model, $batch[100]);
        $workspace = $this->catalog->workspace(1, null, $asOf);
        $candidates = array_column($workspace['refresh_candidates'], null, 'trip_id');
        $this->assertArrayHasKey(100, $candidates);
        $this->assertSame($model, $candidates[100]['verification']);
        $this->assertContains('70000001', $workspace['reservation_ids']);
        $this->assertSame($href, $model['action_href']);

        $context = [
            'id' => 10, 'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 1,
            'movement_type' => 'pickup', 'starts_at' => '2030-01-01 09:00:00',
            'active_events' => [], 'items_by_code' => [], 'capabilities' => [], 'profile' => [],
            'airport_workflow' => null, 'scheduled_location' => null, 'positioning_plan' => null,
            'completed_at' => null, 'readiness_status' => 'open', 'next_trip' => null,
        ];
        $readinessRepo = $this->createStub(MovementReadinessReadModelRepository::class);
        $readinessRepo->method('loadForCompany')->willReturn([10 => $context]);
        $readiness = (new MovementReadinessReadService(
            $readinessRepo,
            new MovementReadinessProjectionService(),
            extraVerificationService: $this->catalog,
        ))->forCompany(1, [10], $asOf)[10];
        $source = array_values(array_filter($readiness['requirements'], static fn (array $row): bool => $row['source_type'] === 'extras_verification'));
        $this->assertCount(1, $source);
        $this->assertSame($model, $readiness['extra_verification']);
        $this->assertSame($required, MovementReadinessProjectionService::isBlocking($source[0]));
        $this->assertSame($required ? $href : null, $source[0]['action']['href'] ?? null);
        if ($required) {
            $this->assertFalse($readiness['ready']);
            $html = html_entity_decode(\Config\Services::renderer()->setData([
                'checklist' => ['id' => 10, 'completed_at' => null, 'items' => []],
                'readiness' => $readiness, 'tripFacts' => ['pickup' => null, 'return' => null],
            ])->render('trip_movement_checklists/_readiness', null, false));
            $this->assertStringContainsString($href, $html);
        }
        $preparation = (new MovementReadinessProjectionService())->projectTripPreparation(array_merge($context, ['extra_verification' => $model]));
        $this->assertSame(! $required, $preparation['ready']);

        $card = $this->overdueBoard($asOf);
        $this->assertSame([], $card['checklists']);
        $this->assertNull($card['next_trip']);
        $this->assertSame($required ? 1 : 0, $card['readiness_blocking_remaining']);
        $this->assertSame(! $required, $card['checklist_ready']);
        $this->assertCount($required ? 1 : 0, $card['extras_verification_actions']);
        $queue = $this->overdueQueue($card);
        $this->assertCount($required ? 1 : 0, $queue);
        if ($required) {
            $this->assertSame($source[0]['work_identity'], $card['extras_verification_actions'][0]['work_identity']);
            $this->assertSame($href, $card['readiness_compact']['next_actions'][0]['href']);
            $this->assertSame('Refresh Turo Extras', $queue[0]['label']);
            $this->assertSame($href, $queue[0]['href']);
            $this->assertSame('required', $queue[0]['urgency']);
        }
        $queueView = new ReflectionMethod(FleetCommandCenterViewModelService::class, 'queueView');
        foreach ([null, 'today', 'urgent'] as $scope) {
            $view = $queueView->invoke(new FleetCommandCenterViewModelService(), $scope, [], [], [], $queue, $queue, $asOf);
            $this->assertSame($queue, $view['items']);
        }
    }

    private function overdueBoard(DateTimeImmutable $asOf): array
    {
        $facts = $this->createStub(OperationalFactsRepository::class);
        $nextTrips = $this->createStub(NextConfirmedTripService::class);
        $nextTrips->method('forVehicle')->willReturn(null);
        $plans = $this->createStub(VehiclePositioningPlanService::class);
        $plans->method('active')->willReturn(null);
        $custody = $this->createStub(CurrentVehicleCustodyService::class);
        $custody->method('forCompany')->willReturn([1 => ['basis_event' => null]]);
        return (new MovementBoardIntelligenceService(
            repository: $facts,
            nextTripService: $nextTrips,
            positioningPlanService: $plans,
            custodyService: $custody,
            extraVerificationService: $this->catalog,
        ))->enrich([['fleet_vehicle_id' => 1, 'status' => 'available', 'checklists' => []]], $asOf, 1)[0];
    }

    private function overdueQueue(array $card): array
    {
        return (new ReflectionMethod(DailyOperationsDashboardService::class, 'extrasVerificationQueue'))
            ->invoke(new DailyOperationsDashboardService(), [], [$card]);
    }

    public function testWorkspacePrioritizesRequiredRefreshBeforeApplyingExportCap(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2030-01-02 14:00:00']);
        $trips = $events = [];
        for ($index = 1; $index <= 500; $index++) {
            $trips[] = ['id' => 1000 + $index, 'fleet_vehicle_id' => 1, 'turo_trip_id' => 'synthetic-active-' . $index, 'turo_reservation_id' => (string) (71000000 + $index), 'starts_at' => '2030-01-01 09:00:00'];
            $events[] = ['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 1000 + $index, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-01 09:00:00'];
        }
        $this->connection->table('turo_trips_normalized')->insertBatch($trips);
        $this->connection->table('trip_movement_events')->insertBatch($events);
        $asOf = new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu');
        $workspace = $this->catalog->workspace(1, null, $asOf);
        $this->assertCount(500, $workspace['refresh_candidates']);
        $this->assertSame('70000001', $workspace['reservation_ids'][0]);
        $this->assertSame('required', $workspace['refresh_candidates'][0]['verification']['urgency']);
        $this->assertCount(501, $this->catalog->refreshVerificationForCompany(1, $asOf));
        $this->assertSame(0, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
    }

    public function testStaleEmptySourceDoesNotBecomeFreshFromReplayOrFailedAttempt(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2030-01-02 14:00:00']);
        $this->observe('2029-12-17T12:00:00-10:00');
        $asOf = new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu');
        $model = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame('stale_empty', $model['state']);
        $this->assertTrue($model['refresh_required']);
        $this->assertSame('2029-12-17 22:00:00', $model['observed_at']);
        $this->importer->import($this->observation('2029-12-17T12:00:00-10:00') . "\n", 1, 10, 'synthetic-old-replay.json');
        $afterReplay = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame($model['age_seconds'], $afterReplay['age_seconds']);
        $this->assertTrue($afterReplay['refresh_required']);
        $this->connection->table('turo_extra_reservation_snapshots')->update(['created_at' => '2030-01-02 12:00:00']);
        $replayed = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame($model['age_seconds'], $replayed['age_seconds']);
        $payload = ['schema' => 'fleetos-turo-extras-v1', 'exported_at' => '2030-01-02T11:00:00-10:00', 'reservations' => [], 'failures' => [['reservation_id' => '70000001', 'error' => 'HTTP 403']]];
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'synthetic-failed-refresh.json');
        $failed = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertSame($model['observed_at'], $failed['observed_at']);
        $this->assertSame($model['age_seconds'], $failed['age_seconds']);
        $this->assertStringContainsString('failed', $failed['issue']);
        $this->assertTrue($failed['refresh_required']);
        $this->assertSame(0, $this->connection->table('turo_extra_selections')->countAllResults());
        $this->assertSame(0, $this->connection->table('trip_extra_fulfillments')->countAllResults());
        $this->assertSame([], $this->catalog->verificationForTrips(2, [100], $asOf));
    }

    public function testOnlyOwnedUnvoidedHandoffClosesVerificationAndWorkspaceSortsUrgency(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2030-01-02 14:00:00']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 101, 'fleet_vehicle_id' => 1, 'turo_trip_id' => 'synthetic-far-trip', 'turo_reservation_id' => '70000003', 'starts_at' => '2030-01-10 14:00:00']);
        $asOf = new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu');
        $workspace = $this->catalog->workspace(1, null, $asOf);
        $this->assertSame(['70000001', '70000003'], $workspace['reservation_ids']);
        $this->assertSame('required', $workspace['refresh_candidates'][0]['verification']['urgency']);
        $this->assertSame('informational', $workspace['refresh_candidates'][1]['verification']['urgency']);
        $this->connection->table('trip_movement_events')->insert(['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 101, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-02 11:00:00']);
        $this->assertTrue($this->catalog->verificationForTrips(1, [100], $asOf)[100]['refresh_required']);
        $this->connection->table('trip_movement_events')->insert(['company_id' => 2, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-02 11:00:00']);
        $this->assertTrue($this->catalog->verificationForTrips(1, [100], $asOf)[100]['refresh_required']);
        $this->connection->table('trip_movement_events')->insert(['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-02 13:00:00']);
        $this->assertTrue($this->catalog->verificationForTrips(1, [100], $asOf)[100]['refresh_required']);
        $this->connection->table('trip_movement_events')->insert(['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-02 11:00:00', 'voided_at' => '2030-01-02 11:30:00']);
        $this->assertTrue($this->catalog->verificationForTrips(1, [100], $asOf)[100]['refresh_required']);
        $this->connection->table('trip_movement_events')->insert(['company_id' => 1, 'fleet_vehicle_id' => 1, 'turo_trip_normalized_id' => 100, 'event_code' => 'actual_handoff', 'occurred_at' => '2030-01-02 11:00:00']);
        $closed = $this->catalog->verificationForTrips(1, [100], $asOf)[100];
        $this->assertFalse($closed['refresh_required']);
        $this->assertSame('handoff_closed', $closed['lifecycle']);
    }

    public function testImportIsStablePreservesSourceFactsAndOnlyCompleteSnapshotsRemove(): void
    {
        $fixture = $this->fixture('turo_extras_export_v1.json');
        $first = $this->importer->import($fixture, 1, 10, 'extras.json');

        $this->assertSame(3, $first->reservationsProcessed);
        $this->assertSame(4, $first->selectionsAdded);
        $this->assertSame(3, $first->unmappedSourceExtraIds);
        $this->assertSame(4, $this->connection->table('turo_extra_selections')->where('company_id', 1)->countAllResults());
        $missingQuantity = $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRowArray();
        $this->assertNull($missingQuantity['quantity']);
        $this->assertNull($missingQuantity['gross_amount']);
        $this->assertSame(30.0, (float) $missingQuantity['unit_price']);
        $this->assertNull($missingQuantity['turo_trip_normalized_id'], 'A same-ID trip belonging to another company must not attach.');
        $matched = $this->connection->table('turo_extra_selections')->where('turo_reservation_id', '70000001')->get()->getRowArray();
        $this->assertSame(100, (int) $matched['turo_trip_normalized_id']);

        $duplicate = $this->importer->import($fixture, 1, 10, 'extras-again.json');
        $this->assertTrue($duplicate->duplicateFile);
        $this->assertSame(4, $duplicate->selectionsUnchanged);
        $this->assertSame(3, $this->connection->table('turo_extra_reservation_snapshots')->where('company_id', 1)->countAllResults());

        $older = json_decode($fixture, true, 64, JSON_THROW_ON_ERROR);
        $older['exported_at'] = '2026-09-12T08:00:00-10:00';
        $older['reservations'] = [$older['reservations'][0]];
        $older['reservations'][0]['extras'][0]['price'] = '12.00';
        $this->importer->import(json_encode($older, JSON_THROW_ON_ERROR), 1, 10, 'older.json');
        $this->assertSame(47.0, (float) $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020576')->get()->getRow('unit_price'));

        $partial = json_decode($this->fixture('turo_extras_export_v1_removal.json'), true, 64, JSON_THROW_ON_ERROR);
        $partial['exported_at'] = '2026-09-13T08:30:00-10:00';
        $partial['reservations'][0]['snapshot_complete'] = false;
        $this->importer->import(json_encode($partial, JSON_THROW_ON_ERROR), 1, 10, 'partial.json');
        $this->assertNull($this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRow('removed_at'));

        $removed = $this->importer->import($this->fixture('turo_extras_export_v1_removal.json'), 1, 10, 'complete-removal.json');
        $this->assertSame(1, $removed->selectionsRemoved);
        $selection = $this->connection->table('turo_extra_selections')->where('reservation_state_extra_id', '8020577')->get()->getRowArray();
        $this->assertNotNull($selection['removed_at']);
        $this->assertSame('2026-09-13 19:00:00', $selection['last_observed_at']);
        $this->assertSame(6, (int) $selection['last_snapshot_id']);

        $failureOnly = ['schema' => 'fleetos-turo-extras-v1', 'exported_at' => '2026-09-13T10:00:00-10:00', 'reservations' => [], 'failures' => [['reservation_id' => '79999999', 'error' => 'HTTP 404']]];
        $failureResult = $this->importer->import(json_encode($failureOnly, JSON_THROW_ON_ERROR), 1, 10, 'failure.json');
        $this->assertSame(1, $failureResult->exportFailures);
        $this->assertSame('extras_export_failure', $this->connection->table('turo_import_errors')->where('message', 'Reservation 79999999: HTTP 404')->get()->getRow('error_code'));
    }

    public function testCatalogMappingsAreCompanyScopedExplicitManyToOneAndAudited(): void
    {
        $fixture = $this->fixture('turo_extras_export_v1.json');
        $this->importer->import($fixture, 1, 10, 'company-a.json');
        $this->importer->import($fixture, 2, 20, 'company-b.json');

        $premiumId = $this->catalog->createExtra(1, ['code' => 'premium_beach_gear', 'display_name' => 'Premium Beach Gear', 'active' => '1', 'sort_order' => 10], 10);
        $basicId = $this->catalog->createExtra(1, ['code' => 'basic_beach_gear', 'display_name' => 'Basic Beach Gear', 'active' => '1', 'sort_order' => 20], 10);
        $companyBId = $this->catalog->createExtra(2, ['code' => 'beach_gear', 'display_name' => 'Company B Beach Gear', 'active' => '1', 'sort_order' => 10], 20);

        $this->catalog->mapSource(1, '3154920', $premiumId, null, 10);
        $this->catalog->mapSource(1, '3199029', $basicId, null, 10);
        $this->catalog->mapSource(2, '3154920', $companyBId, null, 20);
        $this->assertSame($premiumId, (int) $this->repository->mapping(1, 'turo', '3154920')['fleet_extra_id']);
        $this->assertSame($companyBId, (int) $this->repository->mapping(2, 'turo', '3154920')['fleet_extra_id']);
        $this->assertSame('Beach gear', $this->repository->latestSourceObservation(1, '3199029')['source_label']);

        $this->catalog->mapSource(1, '3199029', $premiumId, 'Operator consolidated recreated source Extra', 10);
        $this->assertSame($premiumId, (int) $this->repository->mapping(1, 'turo', '3199029')['fleet_extra_id']);
        $audit = $this->connection->table('audit_logs')->where('table_name', 'fleet_extra_source_mappings')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringContainsString('Operator consolidated', (string) $audit['new_values']);

        try {
            $this->catalog->mapSource(1, '4000000', $companyBId, null, 10);
            $this->fail('A Company B canonical Extra must not be a Company A mapping target.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $created = $this->catalog->createAndMap(1, '4000000', ['code' => 'fsd_upgrade', 'display_name' => 'FSD Upgrade', 'active' => '1', 'sort_order' => 30], 10);
        $this->assertSame($created['extra_id'], (int) $this->repository->mapping(1, 'turo', '4000000')['fleet_extra_id']);

        $this->catalog->updateExtra(1, $premiumId, ['code' => 'premium_beach_gear', 'display_name' => 'Premium Beach Gear', 'sort_order' => 10], 10);
        $this->assertSame(0, (int) $this->repository->extra(1, $premiumId)['active']);
        $this->assertNotNull($this->repository->mapping(1, 'turo', '3154920'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot change after source activity');
        $this->catalog->updateExtra(1, $premiumId, ['code' => 'renamed_code', 'display_name' => 'Premium Beach Gear', 'active' => '1', 'sort_order' => 10], 10);
    }

    public function testImportAndMappingCreateFulfillmentWhilePriceOnlyChangeDoesNotReopen(): void
    {
        $payload = json_decode($this->fixture('turo_extras_export_v1.json'), true, 64, JSON_THROW_ON_ERROR);
        $payload['reservations'] = [$payload['reservations'][0]];
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'fulfillment.json');
        $extraId = $this->catalog->createExtra(1, [
            'code' => 'premium_beach_gear', 'display_name' => 'Premium Beach Gear', 'active' => '1', 'sort_order' => 10,
            'fulfillment_type' => 'pack', 'requires_operator_confirmation' => '1', 'readiness_blocking' => '1',
            'default_action_label' => 'Pack {quantity} premium set(s)', 'fulfillment_phase' => 'preparation',
        ], 10);
        $this->catalog->mapSource(1, '3154920', $extraId, null, 10);
        $fulfillment = $this->connection->table('trip_extra_fulfillments')->get()->getRowArray();
        $this->assertSame('pending', $fulfillment['state']);
        $service = new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->connection));
        $service->complete(1, 100, (int) $fulfillment['id'], 10);

        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'fulfillment-repeat.json');
        $this->assertSame(1, $this->connection->table('trip_extra_fulfillments')->countAllResults());
        $this->assertSame('completed', $this->connection->table('trip_extra_fulfillments')->where('id', $fulfillment['id'])->get()->getRow('state'));

        $stale = $payload;
        $stale['exported_at'] = '2026-09-12T07:00:00-10:00';
        $stale['reservations'][0]['extras'][0]['quantity'] = 9;
        $this->importer->import(json_encode($stale, JSON_THROW_ON_ERROR), 1, 10, 'stale-fulfillment.json');
        $this->assertSame('completed', $this->connection->table('trip_extra_fulfillments')->where('id', $fulfillment['id'])->get()->getRow('state'));

        $partial = $payload;
        $partial['exported_at'] = '2026-09-15T09:00:00-10:00';
        $partial['reservations'][0]['snapshot_complete'] = false;
        $partial['reservations'][0]['extras'] = [];
        $this->importer->import(json_encode($partial, JSON_THROW_ON_ERROR), 1, 10, 'partial-fulfillment.json');
        $this->assertSame('completed', $this->connection->table('trip_extra_fulfillments')->where('id', $fulfillment['id'])->get()->getRow('state'));

        $payload['exported_at'] = '2026-09-15T10:00:00-10:00';
        $payload['reservations'][0]['extras'][0]['price'] = '49.00';
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'price-change.json');
        $this->assertSame('completed', $this->connection->table('trip_extra_fulfillments')->where('id', $fulfillment['id'])->get()->getRow('state'));

        $payload['exported_at'] = '2026-09-15T11:00:00-10:00';
        $payload['reservations'][0]['extras'][0]['quantity'] = 2;
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'quantity-change.json');
        $this->assertSame('pending', $this->connection->table('trip_extra_fulfillments')->where('id', $fulfillment['id'])->get()->getRow('state'));
    }

    public function testInformationalConfigurationKeepsOptionalEntireTripContextWithoutCreatingWork(): void
    {
        $extraId = $this->catalog->createExtra(1, [
            'code' => 'prepaid_ev_recharge',
            'display_name' => 'Prepaid EV Recharge',
            'active' => '1',
            'sort_order' => 10,
            'fulfillment_type' => 'informational',
            'fulfillment_phase' => 'entire_trip',
            'requires_operator_confirmation' => '1',
            'readiness_blocking' => '1',
            'default_action_label' => 'Must be discarded',
        ], 10);
        $extra = $this->repository->extra(1, $extraId);

        $this->assertSame('informational', $extra['fulfillment_type']);
        $this->assertSame('entire_trip', $extra['fulfillment_phase']);
        $this->assertSame(0, (int) $extra['requires_operator_confirmation']);
        $this->assertSame(0, (int) $extra['readiness_blocking']);
        $this->assertNull($extra['default_action_label']);
        $this->assertSame(0, $this->connection->table('trip_extra_fulfillments')->countAllResults());
    }

    public function testPreviouslyEmptyReservationRefreshesAndLaterPurchaseCreatesExactlyOneMovementBlocker(): void
    {
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame('never', $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['state']);
        $this->observe('2026-10-01T09:00:00-10:00');
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame('current_empty', $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['state']);
        $json = $this->observation('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()]);
        $this->importer->import($json, 1, 10, 'synthetic-addition.json');
        $this->configureSyntheticExtra();
        $rows = $this->fulfillments()->forTrips(1, [100])[100];
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_actionable']);
        $this->assertSame(1, $this->extraBlockerCount($rows));
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame('current_nonempty', $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['state']);
        $this->assertSame(2, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->countAllResults();
        $this->assertTrue($this->importer->import($json, 1, 10, 'synthetic-repeat.json')->duplicateFile);
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->countAllResults());
        $this->assertSame(1, $this->connection->table('turo_extra_selections')->countAllResults());
        $html = $this->movementSummary($rows);
        $this->assertStringContainsString('Synthetic Gear Kit', $html);
        $this->assertStringContainsString('Preparation required', $html);
        $this->assertStringContainsString('Extras verified', $html);
        $this->assertStringContainsString('10:00:00 AM HST', $html);
        $this->assertStringNotContainsString('/complete', $html);
    }

    public function testExactLookupScopesCompanyAndCanRefreshHistoricalReservations(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update([
            'starts_at' => '2020-01-01 09:00:00', 'ends_at' => '2020-01-01 10:00:00',
            'canceled_at' => '2019-12-31 09:00:00',
        ]);
        $this->assertSame([], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame(['70000001'], $this->catalog->workspace(1, '70000001')['reservation_ids']);
        $this->assertSame([], $this->catalog->workspace(2, '70000001')['reservation_ids']);
        $this->assertSame([], $this->catalog->verificationForTrips(2, [100]));
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->workspace(1, 'invalid');
    }

    public function testUpcomingAndInProgressTripsRemainEligibleAndClosedTripsAreExcluded(): void
    {
        $this->connection->table('lookup_values')->insert(['id' => 900, 'code' => 'in_progress', 'name' => 'In progress']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => 900, 'ends_at' => '2020-01-01 10:00:00']);
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        foreach (['invalid', 'canceled_host_payout', 'completed'] as $code) {
            $this->connection->table('lookup_values')->where('id', 900)->update(['code' => $code]);
            $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['ends_at' => '2099-01-01 10:00:00']);
            $this->assertSame([], $this->repository->reservationIdsNeedingSnapshot(1));
        }
        $this->connection->table('lookup_values')->where('id', 900)->update(['code' => 'booked']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['starts_at' => '2099-01-01 10:00:00', 'ends_at' => null]);
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['canceled_at' => '2026-10-01 10:00:00']);
        $this->assertSame([], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['canceled_at' => null]);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['deleted_at' => '2026-10-01 10:00:00']);
        $this->assertSame([], $this->repository->reservationIdsNeedingSnapshot(1));
    }

    public function testUnmappedSameLabelProductsRemainDistinctVisibleAndNonblocking(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra(), array_merge($this->syntheticExtra(), [
            'extra_id' => '90000002', 'reservation_state_extra_id' => '91000002', 'price' => '25.00', 'quantity' => null,
        ])]);
        $rows = $this->fulfillments()->forTrips(1, [100])[100];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertFalse($row['is_mapped']);
            $this->assertFalse($row['is_actionable']);
            $this->assertSame('', $row['action_label']);
        }
        $this->assertSame(0, $this->extraBlockerCount($rows));
        $this->assertSame(0, $this->connection->table('trip_extra_fulfillments')->countAllResults());
        $html = $this->movementSummary($rows);
        $this->assertSame(2, substr_count($html, 'Operational mapping required'));
        $this->assertStringContainsString('Beach gear', $html);
        $this->assertStringContainsString('Quantity not supplied', $html);
        $this->assertStringNotContainsString('Load Beach gear', $html);
    }

    public function testCompleteRemovalSuppressesWorkAndPartialEvidenceCannotReactivateOrChangeIt(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()]);
        $this->configureSyntheticExtra();
        $this->observe('2026-10-01T11:00:00-10:00', [], false);
        $this->assertSame(1, $this->extraBlockerCount($this->fulfillments()->forTrips(1, [100])[100]));
        $this->observe('2026-10-01T12:00:00-10:00');
        $before = $this->connection->table('turo_extra_selections')->get()->getRowArray();
        $this->assertNotNull($before['removed_at']);
        $auditCount = $this->connection->table('trip_extra_fulfillment_audits')->countAllResults();
        $this->observe('2026-10-01T13:00:00-10:00', [array_merge($this->syntheticExtra(), ['quantity' => 3])], false);
        $this->assertSame($before, $this->connection->table('turo_extra_selections')->get()->getRowArray());
        $rows = $this->fulfillments()->forTrips(1, [100])[100];
        $this->assertTrue($rows[0]['is_removed']);
        $this->assertSame(0, $this->extraBlockerCount($rows));
        $this->assertStringNotContainsString('Synthetic Gear Kit', $this->movementSummary($rows));
        $this->assertSame($auditCount, $this->connection->table('trip_extra_fulfillment_audits')->countAllResults());
        $this->assertSame(4, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
        $verification = $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100];
        $this->assertSame('current_empty', $verification['state']);
        $this->assertStringContainsString('incomplete', $verification['issue']);
        $this->observe('2026-10-01T14:00:00-10:00', [$this->syntheticExtra()]);
        $this->assertFalse($this->fulfillments()->forTrips(1, [100])[100][0]['is_removed']);
        $this->assertSame(1, $this->connection->table('trip_extra_fulfillments')->countAllResults());
    }

    public function testPartialObservationAloneCannotCreatePurchaseWork(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()], false);
        $this->assertSame(0, $this->connection->table('turo_extra_selections')->countAllResults());
        $verification = $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100];
        $this->assertSame('never', $verification['state']);
        $this->assertStringContainsString('incomplete', $verification['issue']);
    }

    public function testEqualTimestampReplayIsNoOpAndConflictIsReportedWithoutChangingTruth(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()]);
        $before = $this->connection->table('turo_extra_selections')->get()->getRowArray();
        $this->importer->import($this->observation('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()]) . "\n", 1, 10, 'same-observation.json');
        $this->assertSame(1, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
        $this->assertSame(1, $this->observe('2026-10-01T10:00:00-10:00')->invalidReservations);
        $this->assertSame($before, $this->connection->table('turo_extra_selections')->get()->getRowArray());
        $this->assertSame(1, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
        $this->assertSame('extras_observation_conflict', $this->connection->table('turo_import_errors')->get()->getRow('error_code'));
        $this->assertStringContainsString('Conflicting', $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['issue']);
        $this->observe('2026-10-01T11:00:00-10:00');
        $this->assertNull($this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['issue']);
    }

    public function testEqualTimestampExtraOrderDoesNotChangeIdentityOrCreateConflict(): void
    {
        $extras = [$this->syntheticExtra(), array_merge($this->syntheticExtra(), [
            'extra_id' => '90000002', 'reservation_state_extra_id' => '91000002',
        ])];
        $this->observe('2026-10-01T10:00:00-10:00', $extras);
        $before = $this->connection->table('turo_extra_selections')->orderBy('id')->get()->getResultArray();
        $result = $this->observe('2026-10-01T10:00:00-10:00', array_reverse($extras));
        $this->assertSame(0, $result->invalidReservations);
        $this->assertSame($before, $this->connection->table('turo_extra_selections')->orderBy('id')->get()->getResultArray());
        $this->assertSame(1, $this->connection->table('turo_extra_reservation_snapshots')->countAllResults());
        $this->assertSame(0, $this->connection->table('turo_import_errors')->countAllResults());
    }

    public function testFailureIsCompanyScopedAndRetainsLastCompleteVerification(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00');
        $payload = ['schema' => 'fleetos-turo-extras-v1', 'exported_at' => '2026-10-01T11:00:00-10:00', 'reservations' => [],
            'failures' => [['reservation_id' => '70000001', 'error' => 'HTTP 403']]];
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 2, 10, 'other-company-failure.json');
        $this->assertNull($this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]['issue']);
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'failure.json');
        $verification = $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100];
        $this->assertSame('current_empty', $verification['state']);
        $this->assertStringContainsString('failed', $verification['issue']);
    }

    public function testLateTripAttachmentUsesExistingReconciliationWithoutFileReplay(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()], true, '70000003');
        $this->configureSyntheticExtra();
        $this->assertNull($this->connection->table('turo_extra_selections')->get()->getRow('turo_trip_normalized_id'));
        $this->connection->table('turo_trips_normalized')->insert(['id' => 300, 'fleet_vehicle_id' => 1, 'turo_trip_id' => '70000003', 'turo_reservation_id' => '70000003']);
        $this->fulfillments()->reconcileForTrip(2, 300, 10);
        $this->assertNull($this->connection->table('turo_extra_selections')->get()->getRow('turo_trip_normalized_id'));
        // The existing trip CSV importer invokes this same hook.
        $this->fulfillments()->reconcileForTrip(1, 300, 10);
        $this->assertSame(300, (int) $this->connection->table('turo_extra_selections')->where('turo_reservation_id', '70000003')->get()->getRow('turo_trip_normalized_id'));
        $this->assertSame(300, (int) $this->connection->table('turo_extra_reservation_snapshots')->get()->getRow('turo_trip_normalized_id'));
        $this->assertTrue($this->fulfillments()->forTrips(1, [300])[300][0]['is_actionable']);
        $this->assertSame(1, $this->connection->table('turo_import_batches')->countAllResults());
        $this->catalog->reconcileTripLinks(1, 10);
        $this->assertSame(1, $this->connection->table('trip_extra_fulfillments')->countAllResults());
    }

    /** @param list<array<string, mixed>> $extras */
    private function observe(string $at, array $extras = [], bool $complete = true, string $reservationId = '70000001'): \App\DTOs\Turo\TuroExtrasImportResult
    {
        return $this->importer->import($this->observation($at, $extras, $complete, $reservationId), 1, 10, 'synthetic-extras.json');
    }

    /** @param list<array<string, mixed>> $extras */
    private function observation(string $at, array $extras = [], bool $complete = true, string $reservationId = '70000001'): string
    {
        return json_encode(['schema' => 'fleetos-turo-extras-v1', 'exported_at' => $at, 'reservations' => [[
            'reservation_id' => $reservationId, 'trip_start' => null, 'trip_end' => null, 'status' => 'BOOKED', 'snapshot_complete' => $complete, 'extras' => $extras,
        ]], 'failures' => []], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function syntheticExtra(): array
    {
        return ['extra_id' => '90000001', 'reservation_state_extra_id' => '91000001', 'reservation_state_id' => '92000001',
            'type' => 'BEACH_GEAR', 'label' => 'Beach gear', 'description' => 'Invented test product.', 'price' => '10.00', 'quantity' => 1, 'currency' => 'USD', 'pricing_type' => 'PER_TRIP'];
    }

    private function configureSyntheticExtra(): void
    {
        $id = $this->catalog->createExtra(1, ['code' => 'synthetic_gear', 'display_name' => 'Synthetic Gear Kit', 'active' => '1',
            'fulfillment_type' => 'pack', 'fulfillment_phase' => 'preparation', 'requires_operator_confirmation' => '1', 'readiness_blocking' => '1', 'default_action_label' => 'Pack synthetic gear kit'], 10);
        $this->catalog->mapSource(1, '90000001', $id, null, 10);
    }

    private function fulfillments(): TripExtraFulfillmentService
    {
        return new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->connection));
    }

    /** @param list<array<string, mixed>> $rows */
    private function movementSummary(array $rows): string
    {
        return \Config\Services::renderer()->setData(['checklist' => ['turo_trip_normalized_id' => 100], 'guestCommitments' => [],
            'extraPreparation' => $rows, 'extraVerification' => $this->catalog->verificationForTrips(1, [100], new DateTimeImmutable('2026-10-01 15:00:00 Pacific/Honolulu'))[100]])->render('trip_movement_checklists/_guest_commitments');
    }

    /** @param list<array<string, mixed>> $rows */
    private function extraBlockerCount(array $rows): int
    {
        $projection = (new MovementReadinessProjectionService())->project([
            'id' => 1, 'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 1, 'movement_type' => 'pickup',
            'readiness_status' => 'not_started', 'completed_at' => null, 'active_events' => [], 'active_assessment' => null,
            'profile' => [], 'airport_workflow' => null, 'scheduled_location' => null, 'items_by_code' => [], 'positioning_plan' => null, 'capabilities' => [],
            'extra_fulfillments' => $rows,
        ]);

        return count(array_filter($projection['requirements'], static fn (array $row): bool => str_starts_with($row['code'], 'extra_fulfillment_')
            && $row['blocking'] && $row['status'] === 'unsatisfied' && ($row['actionable'] ?? true)));
    }

    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(190), created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(190), sort_order INTEGER DEFAULT 0, is_active BOOLEAN DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, fleet_code VARCHAR(80) DEFAULT "SYNTHETIC-VEHICLE")');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, trip_status_lookup_value_id INTEGER NULL, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), starts_at DATETIME, ends_at DATETIME DEFAULT "2099-01-01 10:00:00", canceled_at DATETIME NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_trip_commitments') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, turo_trip_normalized_id INTEGER, state VARCHAR(20), instruction TEXT)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 1, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER, event_code VARCHAR(80), occurred_at DATETIME DEFAULT "2026-10-01 12:00:00", voided_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type_lookup_value_id INTEGER, import_status_lookup_value_id INTEGER, source_filename VARCHAR(190), source_hash VARCHAR(128) UNIQUE, row_count INTEGER DEFAULT 0, started_at DATETIME, completed_at DATETIME, error_message TEXT, created_by INTEGER, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_import_errors') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, turo_import_batch_id INTEGER, severity_lookup_value_id INTEGER, raw_table VARCHAR(120), raw_row_id INTEGER, row_number INTEGER, error_code VARCHAR(120), field_name VARCHAR(120), message TEXT, raw_payload TEXT, created_at DATETIME, updated_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action_lookup_value_id INTEGER, table_name VARCHAR(120), record_id INTEGER, old_values TEXT, new_values TEXT, created_at DATETIME)');
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 2]]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 100, 'fleet_vehicle_id' => 1, 'turo_trip_id' => 'trip-a', 'turo_reservation_id' => '70000001', 'starts_at' => '2026-09-14 10:00:00'],
            ['id' => 200, 'fleet_vehicle_id' => 2, 'turo_trip_id' => 'trip-b', 'turo_reservation_id' => '70000002', 'starts_at' => '2026-09-17 10:00:00'],
        ]);
    }

    private function seedLookups(): void
    {
        $now = '2026-09-13 00:00:00';
        foreach (['import_status' => ['processing', 'completed', 'failed'], 'import_error_severity' => ['error', 'warning'], 'audit_action' => ['imported', 'created', 'updated']] as $typeCode => $values) {
            $this->connection->table('lookup_types')->insert(['code' => $typeCode, 'name' => $typeCode, 'created_at' => $now, 'updated_at' => $now]);
            $typeId = (int) $this->connection->insertID();
            foreach ($values as $code) {
                $this->connection->table('lookup_values')->insert(['lookup_type_id' => $typeId, 'code' => $code, 'name' => $code, 'sort_order' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/_support/fixtures/' . $name);
        $this->assertIsString($contents);

        return $contents;
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
