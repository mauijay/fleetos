<?php

use App\Database\Migrations\CreateExtraFulfillment;
use App\Database\Migrations\CreateFleetExtrasFoundation;
use App\Repositories\AuditLogRepository;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use App\Repositories\TuroImportBatchRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Services\Fleet\FleetExtraService;
use App\Services\Fleet\MovementReadinessProjectionService;
use App\Services\Fleet\TripExtraFulfillmentService;
use App\Services\Turo\TuroExtrasImportService;
use App\Services\Turo\TuroImportAuditService;
use App\Validation\Turo\TuroExtrasPayloadValidator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

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
        $this->assertSame('never', $this->catalog->verificationForTrips(1, [100])[100]['state']);
        $this->observe('2026-10-01T09:00:00-10:00');
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame('complete_empty', $this->catalog->verificationForTrips(1, [100])[100]['state']);
        $json = $this->observation('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()]);
        $this->importer->import($json, 1, 10, 'synthetic-addition.json');
        $this->configureSyntheticExtra();
        $rows = $this->fulfillments()->forTrips(1, [100])[100];
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_actionable']);
        $this->assertSame(1, $this->extraBlockerCount($rows));
        $this->assertSame(['70000001'], $this->repository->reservationIdsNeedingSnapshot(1));
        $this->assertSame('complete_nonempty', $this->catalog->verificationForTrips(1, [100])[100]['state']);
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
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['ends_at' => '2020-01-01 10:00:00']);
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
        $verification = $this->catalog->verificationForTrips(1, [100])[100];
        $this->assertSame('complete_empty', $verification['state']);
        $this->assertStringContainsString('incomplete', $verification['issue']);
        $this->observe('2026-10-01T14:00:00-10:00', [$this->syntheticExtra()]);
        $this->assertFalse($this->fulfillments()->forTrips(1, [100])[100][0]['is_removed']);
        $this->assertSame(1, $this->connection->table('trip_extra_fulfillments')->countAllResults());
    }

    public function testPartialObservationAloneCannotCreatePurchaseWork(): void
    {
        $this->observe('2026-10-01T10:00:00-10:00', [$this->syntheticExtra()], false);
        $this->assertSame(0, $this->connection->table('turo_extra_selections')->countAllResults());
        $verification = $this->catalog->verificationForTrips(1, [100])[100];
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
        $this->assertStringContainsString('Conflicting', $this->catalog->verificationForTrips(1, [100])[100]['issue']);
        $this->observe('2026-10-01T11:00:00-10:00');
        $this->assertNull($this->catalog->verificationForTrips(1, [100])[100]['issue']);
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
        $this->assertNull($this->catalog->verificationForTrips(1, [100])[100]['issue']);
        $this->importer->import(json_encode($payload, JSON_THROW_ON_ERROR), 1, 10, 'failure.json');
        $verification = $this->catalog->verificationForTrips(1, [100])[100];
        $this->assertSame('complete_empty', $verification['state']);
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
            'extraPreparation' => $rows, 'extraVerification' => $this->catalog->verificationForTrips(1, [100])[100]])->render('trip_movement_checklists/_guest_commitments');
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
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER, event_code VARCHAR(80), voided_at DATETIME NULL)');
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
