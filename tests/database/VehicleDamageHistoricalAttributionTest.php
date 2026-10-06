<?php

use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageReadService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageMariaDbFixture;

/** @internal Synthetic records only; actual migrations run on isolated SQLite. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageHistoricalAttributionTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleDamageRepository $items;
    private VehicleDamageIncidentRepository $incidents;
    private VehicleDamageIncidentService $service;
    private VehicleDamageService $conditions;
    private ?VehicleDamageMariaDbFixture $mariaDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        VehicleDamageDatabaseFixture::migrate($this->connection);
        VehicleDamageDatabaseFixture::seed($this->connection);
        $this->items = new VehicleDamageRepository($this->connection);
        $this->incidents = new VehicleDamageIncidentRepository($this->connection);
        $this->service = new VehicleDamageIncidentService($this->connection);
        $this->conditions = new VehicleDamageService($this->connection);
    }

    protected function tearDown(): void
    {
        $this->mariaDb?->close();
        parent::tearDown();
    }

    public function testMariaDbConcurrentOriginalBackfillBlocksThenRejectsDuplicateWithoutLosingAudits(): void
    {
        $this->useMariaDb();
        $item = $this->legacy();
        $request = $this->backfillData($item);
        $physical = $this->rows(false);
        $audits = $this->connection->table('audit_logs')->countAllResults();
        $this->connection->transBegin();
        $winner = $this->service->backfillOriginal(1, 10, $item, $request, 7);
        $this->assertTrue($winner['success'], json_encode($winner));
        $contender = $this->mariaDb->contend('backfill', [1, 10, $item, $request, 9]);
        $this->assertTrue($contender['blocked'], 'The second connection must actually wait on an InnoDB row lock.');
        $this->assertFalse($contender['result']['success']);
        $this->assertStringContainsString('already', implode(' ', $contender['result']['errors']));
        $this->assertCount(1, $this->incidents->forVehicle(1, 10));
        $this->assertCount(1, $this->incidents->memberships(1, 10, (int) $winner['id']));
        $this->assertSame($audits + 2, $this->connection->table('audit_logs')->countAllResults());
        $this->assertSame($physical, $this->rows(false));
    }

    public function testMariaDbConcurrentAttributionRevalidatesStalePreviewAfterLockRelease(): void
    {
        $this->useMariaDb();
        $item = $this->legacy();
        $created = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];
        $request = $this->attributionData($id, 'operator_attributed_cause');
        $olderRequest = array_replace($request, ['trip_id' => 100, 'attribution_type' => 'suspected_cause']);
        $physical = $this->rows(false);
        $memberships = $this->incidents->memberships(1, 10, $id);
        $audits = $this->connection->table('audit_logs')->countAllResults();
        $this->connection->transBegin();
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $request, 7)['success']);
        $contender = $this->mariaDb->contend('attribute', [1, 10, $id, $olderRequest, 9]);
        $this->assertTrue($contender['blocked'], json_encode($contender));
        $this->assertFalse($contender['result']['success']);
        $this->assertStringContainsString('changed', implode(' ', $contender['result']['errors']));
        $this->assertSame('operator_attributed_cause', $this->incidents->incident(1, 10, $id)['attribution_type']);
        $this->assertSame(102, (int) $this->incidents->incident(1, 10, $id)['turo_trip_normalized_id']);
        $this->assertSame($audits + 1, $this->connection->table('audit_logs')->countAllResults());
        $this->assertSame($memberships, $this->incidents->memberships(1, 10, $id));
        $this->assertSame($physical, $this->rows(false));
    }

    public function testMariaDbConcurrentCanonicalLinkRevalidatesAndPreservesOneHopInvariants(): void
    {
        $this->useMariaDb();
        $root = $this->legacy();
        $child = $this->legacy('moderate');
        $other = $this->legacy();
        $winnerRequest = $this->linkData($child, $root);
        $stale = $this->linkData($root, $other);
        $this->connection->transBegin();
        $this->assertTrue($this->service->linkHistorical(1, 10, $child, $root, $winnerRequest, 7)['success']);
        $contender = $this->mariaDb->contend('link', [1, 10, $root, $other, $stale, 9]);
        $this->assertTrue($contender['blocked'], json_encode($contender));
        $this->assertFalse($contender['result']['success']);
        $this->assertStringContainsString('changed', implode(' ', $contender['result']['errors']));
        $before = $this->rows();
        $retry = $this->service->linkHistorical(1, 10, $root, $other, $this->linkData($root, $other), 9);
        $this->assertFalse($retry['success']);
        $this->assertStringContainsString('chain', implode(' ', $retry['errors']));
        $this->assertSame($before, $this->rows());
        $this->assertSame($root, (int) $this->items->item(1, 10, $child)['current_condition_item_id']);
        $this->assertNull($this->items->item(1, 10, $root)['current_condition_item_id']);
        $this->assertCount(2, $this->items->currentForVehicle(1, 10));
    }

    private function useMariaDb(): void
    {
        if (! getenv('B11_MARIADB_CONFIG')) {
            $this->markTestSkipped('Opt in with an explicitly isolated disposable MariaDB fixture.');
        }
        $this->mariaDb = new VehicleDamageMariaDbFixture();
        $this->connection = $this->mariaDb->db;
        $this->items = new VehicleDamageRepository($this->connection);
        $this->incidents = new VehicleDamageIncidentRepository($this->connection);
        $this->service = new VehicleDamageIncidentService($this->connection);
        $this->conditions = new VehicleDamageService($this->connection);
    }

    private function linkData(int $source, int $target): array
    {
        return ['confirmed' => '1', 'reason' => 'Synthetic canonical relationship confirmation', 'source_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $source)), 'target_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $target))];
    }

    public function testReconciledLegacyPairGainsProvenanceWithoutChangingPhysicalHistoryOrProjection(): void
    {
        $original = $this->legacy();
        $later = $this->legacy('moderate');
        $linked = $this->service->linkHistorical(1, 10, $later, $original, [
            'confirmed' => '1', 'reason' => 'Synthetic operator reconciliation',
            'source_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $later)),
            'target_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $original)),
        ], 7);
        $this->assertTrue($linked['success'], json_encode($linked));
        $laterIncident = (int) $linked['id'];
        $physical = $this->rows(false);
        $workspace = $this->workspace();
        $memberships = $this->incidents->memberships(1, 10, $laterIncident);
        $this->assertSame(['observed_existing', 'worsened'], array_column($memberships, 'effect_code'));
        $this->assertSame('unknown', $this->incidents->incident(1, 10, $laterIncident)['attribution_type']);
        $auditCount = $this->connection->table('audit_logs')->countAllResults();

        $created = $this->service->backfillOriginal(1, 10, $original, $this->backfillData($original, ['attribution_type' => 'operator_attributed_cause']), 7);
        $this->assertTrue($created['success'], json_encode($created));
        $incident = $this->incidents->incident(1, 10, (int) $created['id']);
        $membership = $this->incidents->memberships(1, 10, (int) $created['id'])[0];
        $this->assertSame($original, (int) $membership['vehicle_damage_item_id']);
        $this->assertSame('new_damage', $membership['effect_code']);
        $this->assertNull($membership['panel_code']);
        $this->assertSame('cosmetic', $membership['severity_code']);
        $this->assertSame('moderate', $this->items->item(1, 10, $original)['severity_code']);
        $this->assertSame($physical['vehicle_damage_items'][0]['discovered_at'], $incident['discovered_at']);
        $this->assertNull($incident['occurred_at']);
        $this->assertSame('SYNTHETIC-RESERVATION-100', $incident['turo_reservation_id']);
        $this->assertArrayNotHasKey('guest_name', $incident);
        $this->assertSame($physical, $this->rows(false));
        $this->assertSame($workspace, $this->workspace());
        $this->assertSame($auditCount + 2, $this->connection->table('audit_logs')->countAllResults());
        $audit = $this->incidents->history(1, 10, (int) $created['id'])[0];
        $this->assertNull($audit['old_values']);
        $this->assertSame(7, (int) $audit['actor_user_id']);
        $newValues = json_decode($audit['new_values'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($newValues['historical_backfill']);
        $this->assertSame($original, $newValues['existing_condition_id']);
        $this->assertSame('Synthetic authoritative historical review', $newValues['reason']);
        $membershipAudit = $this->connection->table('audit_logs')->where('table_name', 'vehicle_damage_incident_items')->where('record_id', $membership['id'])->get()->getRowArray();
        $this->assertNull($membershipAudit['old_values']);
        $this->assertSame(7, (int) $membershipAudit['actor_user_id']);
        $this->assertSame('new_damage', json_decode($membershipAudit['new_values'], true)['effect_code']);

        $before = $this->incidents->incident(1, 10, $laterIncident);
        $attributed = $this->service->attributeTrip(1, 10, $laterIncident, $this->attributionData($laterIncident, 'operator_attributed_cause'), 7);
        $this->assertTrue($attributed['success'], json_encode($attributed));
        $after = $this->incidents->incident(1, 10, $laterIncident);
        $this->assertSame('operator_attributed_cause', $after['attribution_type']);
        $this->assertSame('SYNTHETIC-RESERVATION-102', $after['turo_reservation_id']);
        foreach (['discovered_at', 'occurred_at', 'created_by', 'created_at', 'overall_note'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame(7, (int) $after['updated_by']);
        $this->assertNotEmpty($after['updated_at']);
        $this->assertSame($memberships, $this->incidents->memberships(1, 10, $laterIncident));
        $this->assertSame($physical, $this->rows(false));
        $this->assertSame($workspace, $this->workspace());
        $this->assertSame($auditCount + 3, $this->connection->table('audit_logs')->countAllResults());
        $history = $this->incidents->history(1, 10, $laterIncident);
        $updatedAudit = $history[count($history) - 1];
        $this->assertSame($before, json_decode($updatedAudit['old_values'], true));
        $this->assertSame('Synthetic explicit incident attribution', json_decode($updatedAudit['new_values'], true)['reason']);
        $this->assertSame(7, (int) $updatedAudit['actor_user_id']);
        $this->assertCount(1, $workspace['current']);
        $this->assertCount(1, $workspace['related']);
        $this->assertSame($original, (int) $this->items->item(1, 10, $later)['current_condition_item_id']);
        $viewData = ['vehicle' => ['id' => 10], 'checklist' => ['id' => 321, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 102, 'starts_at' => '2026-10-06 09:00:00'], 'vehicleDamage' => $this->workspace(), 'notice' => null, 'errors' => [], 'form' => null, 'formData' => []];
        foreach (['fleet_vehicles/components/vehicle_damage', 'trip_movement_checklists/_known_damage'] as $view) {
            $html = \CodeIgniter\Config\Services::renderer()->setData($viewData)->render($view);
            $this->assertSame(1, substr_count($html, 'damage-item-card'));
            $this->assertStringContainsString('Synthetic legacy front damage', $html);
        }
        $this->assertSame([], $this->connection->query('PRAGMA foreign_key_check')->getResultArray());
    }

    /** @return iterable<string,array{string}> */
    public static function attributionTypes(): iterable
    {
        foreach (['unknown', 'discovered_during_trip', 'suspected_cause', 'operator_attributed_cause'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('attributionTypes')]
    public function testExplicitAttributionLevelsRemainDistinctOnBackfillAndUpdate(string $type): void
    {
        $item = $this->legacy();
        $before = $this->rows(false);
        $created = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item, ['attribution_type' => $type]), 7);
        $this->assertTrue($created['success'], json_encode($created));
        $id = (int) $created['id'];
        $this->assertSame($type, $this->incidents->incident(1, 10, $id)['attribution_type']);
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $this->attributionData($id, $type), 7)['success']);
        $this->assertSame($type, $this->incidents->incident(1, 10, $id)['attribution_type']);
        $this->assertSame($before, $this->rows(false));
    }

    public function testBackfillRequiresConfirmationActorReasonTripAndRecordedPanel(): void
    {
        $item = $this->legacy();
        $before = $this->rows();
        foreach ([['confirmed' => ''], ['expected_state' => 'stale'], ['reason' => ' '], ['trip_id' => null], ['trip_id' => 101], ['trip_id' => 200], ['trip_id' => 999], ['attribution_type' => 'inferred'], ['panel_code' => 'hood'], ['discovered_at' => 'invalid']] as $invalid) {
            $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $this->backfillData($item, $invalid), 7)['success']);
            $this->assertSame($before, $this->rows());
        }
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 0)['success']);
        foreach ([[2, 10], [1, 11], [2, 20]] as [$company, $vehicle]) {
            $this->assertFalse($this->service->backfillOriginal($company, $vehicle, $item, $this->backfillData($item), 7)['success']);
        }
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testDuplicateBackfillAndTwoConcurrentPreviewSubmissionsCannotCreateAnotherOriginal(): void
    {
        $item = $this->legacy();
        $firstRequest = $this->backfillData($item);
        $secondRequest = $this->backfillData($item);
        $first = $this->service->backfillOriginal(1, 10, $item, $firstRequest, 7);
        $this->assertTrue($first['success']);
        $before = $this->rows();
        foreach ([$secondRequest, $this->backfillData($item)] as $duplicate) {
            $result = $this->service->backfillOriginal(1, 10, $item, $duplicate, 7);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('already', implode(' ', $result['errors']));
            $this->assertSame($before, $this->rows());
        }
        $this->assertSame((int) $first['id'], $this->service->historicalOriginalPreview(1, 10, $item)['original_incident_id']);
    }

    public function testBackfillRevalidatesConditionAndOriginalEventAfterPreview(): void
    {
        $item = $this->legacy();
        $stale = $this->backfillData($item);
        $this->assertTrue($this->conditions->changeSeverity(1, 10, $item, 'moderate', 'Synthetic concurrent severity review', 7)['success']);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $stale, 7)['success']);
        $this->assertSame($before, $this->rows());
        $stale = $this->backfillData($item);
        $this->connection->table('vehicle_damage_item_events')->where('vehicle_damage_item_id', $item)->where('event_code', 'created')->update(['note' => 'Synthetic concurrent history correction']);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $stale, 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testHistoricalChildIsRejectedRatherThanSilentlyResolvingItsOriginalIncidentToRoot(): void
    {
        $root = $this->legacy();
        $child = $this->legacy('moderate');
        $request = $this->backfillData($child);
        $this->assertTrue($this->service->linkHistorical(1, 10, $child, $root, ['confirmed' => '1', 'reason' => 'Synthetic confirmed relationship', 'source_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $child)), 'target_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $root))], 7)['success']);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $child, $request, 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testBackfillRequiresUnambiguousRecordedOriginalSeverity(): void
    {
        $item = $this->legacy();
        $request = $this->backfillData($item);
        $event = $this->connection->table('vehicle_damage_item_events')->where('vehicle_damage_item_id', $item)->get()->getRowArray();
        unset($event['id']);
        $this->connection->table('vehicle_damage_item_events')->insert($event);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $request, 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testAuditFailureRollsBackIncidentAndMembership(): void
    {
        $item = $this->legacy();
        $before = $this->rows();
        $this->connection->query('CREATE TRIGGER reject_backfill_audit BEFORE INSERT ON ' . $this->connection->prefixTable('audit_logs') . " BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testExistingNormalOriginalMembershipAlsoBlocksBackfill(): void
    {
        $created = $this->service->create(1, 10, ['discovered_at' => '2026-10-05T09:30', 'areas' => [[
            'panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic',
            'effect_code' => 'new_damage', 'note' => 'Synthetic normal original condition',
        ]]], 7);
        $this->assertTrue($created['success']);
        $item = (int) $this->items->currentForVehicle(1, 10)[0]['id'];
        $preview = $this->service->historicalOriginalPreview(1, 10, $item);
        $this->assertSame((int) $created['id'], $preview['original_incident_id']);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testRecordedKnownPanelAndOptionalOccurrenceArePreservedWithoutInferringAnotherPanel(): void
    {
        $item = $this->legacy();
        // Model an already confirmed stored panel, independently of the backfill action.
        $this->connection->table('vehicle_damage_items')->where('id', $item)->update(['panel_code' => 'hood']);
        $before = $this->rows(false);
        $result = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item, ['occurred_at' => '2026-09-24T11:30']), 7);
        $this->assertTrue($result['success']);
        $id = (int) $result['id'];
        $this->assertSame('hood', $this->incidents->memberships(1, 10, $id)[0]['panel_code']);
        $this->assertSame('2026-09-24 11:30:00', $this->incidents->incident(1, 10, $id)['occurred_at']);
        $this->assertSame($before, $this->rows(false));
    }

    public function testDeletedVehicleAndMissingConditionCannotAuthorizeBackfill(): void
    {
        $item = $this->legacy();
        $request = $this->backfillData($item);
        $this->connection->table('fleet_vehicles')->where('id', 10)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $before = $this->rows();
        $this->assertFalse($this->service->backfillOriginal(1, 10, $item, $request, 7)['success']);
        $this->assertFalse($this->service->backfillOriginal(1, 11, 999, $request, 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testNormalCreationStillRequiresPanelAndCannotReuseAnExistingCondition(): void
    {
        $item = $this->legacy();
        $before = $this->rows();
        $base = ['discovered_at' => '2026-10-05T09:30', 'trip_id' => 100, 'attribution_type' => 'unknown'];
        $area = ['panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic', 'effect_code' => 'new_damage', 'note' => 'Synthetic current damage'];
        foreach ([array_replace($area, ['panel_code' => null]), $area + ['vehicle_damage_item_id' => $item]] as $invalid) {
            $this->assertFalse($this->service->create(1, 10, $base + ['areas' => [$invalid]], 7)['success']);
            $this->assertSame($before, $this->rows());
        }
        $this->assertFalse($this->service->create(1, 10, array_replace($base, ['attribution_type' => 'operator_attributed_cause', 'areas' => [$area]]), 7)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testObservedOnlyCannotBecomeCausalAndMixedCurrentEntryRequiresExplicitReason(): void
    {
        $item = $this->legacy();
        $affectedItem = $this->legacy();
        $observed = ['panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic', 'effect_code' => 'observed_existing', 'vehicle_damage_item_id' => $item, 'note' => 'Synthetic observation'];
        $base = ['discovered_at' => '2026-10-05T09:30', 'trip_id' => 100, 'areas' => [$observed]];
        $created = $this->service->create(1, 10, $base, 7);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];
        $this->assertSame('unknown', $this->incidents->incident(1, 10, $id)['attribution_type']);
        $before = $this->rows();
        foreach (['suspected_cause', 'operator_attributed_cause'] as $type) {
            $this->assertFalse($this->service->attributeTrip(1, 10, $id, $this->attributionData($id, $type), 7)['success']);
            $this->assertFalse($this->service->create(1, 10, $base + ['attribution_type' => $type, 'overall_note' => 'Synthetic explicit reason'], 7)['success']);
            $this->assertSame($before, $this->rows());
        }
        $worsened = array_replace($observed, ['effect_code' => 'worsened', 'severity_code' => 'moderate', 'vehicle_damage_item_id' => $affectedItem]);
        $mixed = $this->service->create(1, 10, array_replace($base, ['areas' => [$observed, $worsened], 'attribution_type' => 'operator_attributed_cause', 'overall_note' => 'Synthetic explicit worsening cause']), 7);
        $this->assertTrue($mixed['success'], json_encode($mixed));
        $this->assertSame(['observed_existing', 'worsened'], array_column($this->incidents->memberships(1, 10, (int) $mixed['id']), 'effect_code'));
    }

    public function testAttributionRejectsMissingReasonActorStaleStateAndUnauthorizedTripWithoutChangingMemberships(): void
    {
        $item = $this->legacy();
        $created = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];
        $before = $this->rows();
        foreach ([['reason' => ''], ['expected_state' => 'stale'], ['trip_id' => 101], ['trip_id' => 200]] as $invalid) {
            $this->assertFalse($this->service->attributeTrip(1, 10, $id, array_replace($this->attributionData($id, 'operator_attributed_cause'), $invalid), 7)['success']);
            $this->assertSame($before, $this->rows());
        }
        $this->assertFalse($this->service->attributeTrip(1, 10, $id, $this->attributionData($id, 'operator_attributed_cause'), 0)['success']);
        $this->assertSame($before, $this->rows());
    }

    public function testAlreadyAttributedIncidentIsANoopAndStaleRetryNeedsFreshConfirmation(): void
    {
        $item = $this->legacy();
        $created = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];
        $old = $this->attributionData($id, 'operator_attributed_cause');
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $old, 7)['success']);
        $before = $this->rows();
        $this->assertFalse($this->service->attributeTrip(1, 10, $id, $old, 7)['success']);
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $this->attributionData($id, 'operator_attributed_cause'), 7)['success']);
        $this->assertSame($before, $this->rows());
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $this->attributionData($id, 'suspected_cause'), 7)['success']);
        $this->assertSame('suspected_cause', $this->incidents->incident(1, 10, $id)['attribution_type']);
    }

    public function testDuplicateMembershipRejectsBeforePhysicalMutationAndAllowsValidRetry(): void
    {
        $item = $this->legacy();
        $other = $this->legacy();
        $created = $this->service->backfillOriginal(1, 10, $item, $this->backfillData($item), 7);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];
        $area = ['panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic', 'effect_code' => 'observed_existing', 'vehicle_damage_item_id' => $item, 'note' => 'Synthetic duplicate observation'];
        $before = $this->rows();
        $result = $this->service->attachArea(1, 10, $id, $area, 7);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already attached', implode(' ', $result['errors']));
        $this->assertSame($before, $this->rows());
        $this->assertTrue($this->service->attachArea(1, 10, $id, array_replace($area, ['vehicle_damage_item_id' => $other]), 7)['success']);
        $this->assertCount(2, $this->incidents->memberships(1, 10, $id));
    }

    private function legacy(string $severity = 'cosmetic'): int
    {
        $result = $this->conditions->create(1, 10, ['zone_code' => 'front', 'damage_type_code' => 'dent', 'severity_code' => $severity, 'description' => 'Synthetic legacy front damage', 'discovered_at' => '2026-09-25 07:30:17', 'file_id' => 800], 7);
        $this->assertTrue($result['success'], json_encode($result));

        return (int) $result['id'];
    }

    private function backfillData(int $item, array $overrides = []): array
    {
        return array_replace(['confirmed' => '1', 'expected_state' => $this->service->historicalOriginalPreview(1, 10, $item)['expected_state'], 'trip_id' => 100, 'attribution_type' => 'unknown', 'reason' => 'Synthetic authoritative historical review'], $overrides);
    }

    private function attributionData(int $incident, string $type): array
    {
        return ['trip_id' => 102, 'attribution_type' => $type, 'reason' => 'Synthetic explicit incident attribution', 'expected_state' => VehicleDamageIncidentService::fingerprint($this->incidents->incident(1, 10, $incident))];
    }

    private function workspace(): array
    {
        return (new VehicleDamageReadService($this->conditions, $this->items))->workspace(1, 10);
    }

    private function rows(bool $includeProvenance = true): array
    {
        $tables = ['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_item_evidence'];
        if ($includeProvenance) {
            $tables = [...$tables, 'vehicle_damage_incidents', 'vehicle_damage_incident_items', 'audit_logs'];
        }
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = $this->connection->table($table)->orderBy('id')->get()->getResultArray();
        }

        return $rows;
    }
}
