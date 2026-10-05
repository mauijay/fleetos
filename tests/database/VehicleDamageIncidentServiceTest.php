<?php

use App\Database\Migrations\AddVehicleDamageIncidentsAndConditionLinks;
use App\Database\Migrations\CreateVehicleDamageLedger;
use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageReadService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-09-25-000027_CreateVehicleDamageLedger.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-05-000029_AddVehicleDamageIncidentsAndConditionLinks.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageIncidentServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleDamageRepository $items;
    private VehicleDamageIncidentRepository $incidents;
    private VehicleDamageIncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->dropTables();
        $this->createPrerequisites();
        (new CreateVehicleDamageLedger(Database::forge($this->connection)))->up();
        (new AddVehicleDamageIncidentsAndConditionLinks(Database::forge($this->connection)))->up();
        $this->seed();
        $this->items = new VehicleDamageRepository($this->connection);
        $this->incidents = new VehicleDamageIncidentRepository($this->connection);
        $this->service = new VehicleDamageIncidentService($this->connection);
    }

    public function testOneIncidentOnePanelAndSeveralPanelsAreIndependentConditions(): void
    {
        $first = $this->create();
        $this->assertSame(1, count($this->incidents->memberships(1, 10, $first)));
        $second = $this->create(['areas' => [$this->area(), $this->area(['panel_code' => 'hood']), $this->area(['panel_code' => 'rear_bumper']), $this->area(['panel_code' => 'front_driver_fender'])]]);
        $this->assertCount(4, $this->incidents->memberships(1, 10, $second));
        $this->assertCount(5, $this->items->currentForVehicle(1, 10));
        $this->assertCount(2, $this->incidents->forVehicle(1, 10));
        $this->assertSame('front_bumper', $this->items->currentForVehicle(1, 10)[0]['panel_code']);
    }

    public function testTripAuthorityAndDiscoveryOnlyAttribution(): void
    {
        $id = $this->create(['trip_id' => 100, 'attribution_type' => 'discovered_during_trip']);
        $incident = $this->incidents->incident(1, 10, $id);
        $this->assertSame('LOCAL-DAMAGE-TRIP-A', $incident['turo_reservation_id']);
        $this->assertSame('discovered_during_trip', $incident['attribution_type']);
        $this->assertArrayNotHasKey('guest_name', $incident);
        foreach ([101, 200, 999] as $tripId) {
            $this->assertFalse($this->service->create(1, 10, $this->data(['trip_id' => $tripId]), 7)['success']);
        }
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $this->assertFalse($this->service->create(1, 10, $this->data(['trip_id' => 100]), 7)['success']);
        $this->assertNull($this->incidents->incident(1, 10, $id)['turo_reservation_id']);
        $this->assertCount(1, $this->incidents->forVehicle(1, 10));
    }

    public function testPostCreateAttributionPreservesDiscoveryAndGuardsStaleRequests(): void
    {
        $id = $this->create();
        $before = $this->incidents->incident(1, 10, $id);
        $data = ['trip_id' => 100, 'attribution_type' => 'suspected_cause', 'reason' => 'Operator reviewed synthetic trip context', 'expected_state' => VehicleDamageIncidentService::fingerprint($before)];
        $this->assertTrue($this->service->attributeTrip(1, 10, $id, $data, 7)['success']);
        $after = $this->incidents->incident(1, 10, $id);
        $this->assertSame($before['discovered_at'], $after['discovered_at']);
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertFalse($this->service->attributeTrip(1, 10, $id, $data + ['trip_id' => 102], 7)['success']);
        $this->assertGreaterThan(0, $this->connection->table('audit_logs')->where('table_name', 'vehicle_damage_incidents')->where('record_id', $id)->countAllResults());
    }

    public function testWorseningAndObservationKeepOneConditionAndImmutableSnapshots(): void
    {
        $this->create();
        $item = $this->items->currentForVehicle(1, 10)[0];
        $worsening = $this->area(['effect_code' => 'worsened', 'vehicle_damage_item_id' => $item['id'], 'severity_code' => 'severe']);
        $id = $this->create(['areas' => [$worsening], 'trip_id' => 102, 'attribution_type' => 'discovered_during_trip']);
        $this->assertCount(1, $this->items->currentForVehicle(1, 10));
        $this->assertSame('severe', $this->items->item(1, 10, (int) $item['id'])['severity_code']);
        $this->assertSame('worsened', $this->incidents->memberships(1, 10, $id)[0]['effect_code']);
        $this->assertFalse($this->service->create(1, 10, $this->data(['areas' => [array_replace($worsening, ['severity_code' => 'cosmetic'])]]), 7)['success']);
        $observation = array_replace($worsening, ['effect_code' => 'observed_existing']);
        $observationId = $this->create(['areas' => [$observation], 'trip_id' => 102]);
        $observedIncident = $this->incidents->incident(1, 10, $observationId);
        $this->assertFalse($this->service->attributeTrip(1, 10, $observationId, ['trip_id' => 102, 'attribution_type' => 'operator_attributed_cause', 'reason' => 'Synthetic attempted causal reassignment', 'expected_state' => VehicleDamageIncidentService::fingerprint($observedIncident)], 7)['success']);
        $this->assertSame('severe', $this->items->item(1, 10, (int) $item['id'])['severity_code']);
        $this->assertFalse($this->service->create(1, 10, $this->data(['areas' => [$observation], 'trip_id' => 102, 'attribution_type' => 'operator_attributed_cause']), 7)['success']);
        $this->assertEqualsCanonicalizing(['created', 'worsened', 'observed_existing'], array_column($this->items->events(1, (int) $item['id']), 'event_code'));
    }

    public function testHistoricalLinkPreservesIdsDiscoveryEvidenceHistoryAndUnsafeSeverity(): void
    {
        $this->create();
        $target = $this->items->currentForVehicle(1, 10)[0];
        $this->create(['areas' => [$this->area(['severity_code' => 'unsafe', 'file_id' => 800])]]);
        $source = $this->items->currentForVehicle(1, 10)[0];
        $sourceId = (int) $source['id'];
        $targetId = (int) $target['id'];
        $events = $this->items->events(1, $sourceId);
        $evidence = $this->items->evidence(1, $sourceId);
        $result = $this->link($sourceId, $targetId);
        $this->assertTrue($result['success'], json_encode($result));
        $after = $this->items->item(1, 10, $sourceId);
        foreach (['id', 'discovered_at', 'created_at', 'created_by', 'status_code', 'description'] as $key) {
            $this->assertSame($source[$key], $after[$key]);
        }
        $this->assertSame($evidence, $this->items->evidence(1, $sourceId));
        foreach ($events as $event) {
            $this->assertContains($event, $this->items->events(1, $sourceId));
        }
        $this->assertCount(1, $this->items->currentForVehicle(1, 10));
        $this->assertSame('unsafe', $this->items->currentForVehicle(1, 10)[0]['severity_code']);
        $this->assertSame($targetId, (int) $this->items->canonicalItem(1, 10, $sourceId)['id']);
        $this->assertCount(2, $this->incidents->memberships(1, 10, (int) $result['id']));
        $projection = (new VehicleDamageReadService(new VehicleDamageService($this->connection)))->workspace(1, 10);
        $this->assertTrue($projection['has_unsafe']);
        $this->assertCount(1, $projection['related']);
    }

    public function testNoSelfCycleChainOrStaleHistoricalRelationship(): void
    {
        $this->create();
        $this->create();
        $this->create();
        $ids = array_map('intval', array_column($this->items->currentForVehicle(1, 10), 'id'));
        [$a, $b, $c] = $ids;
        $this->assertFalse($this->link($a, $a)['success']);
        $old = $this->linkData($b, $a);
        $this->assertTrue($this->link($b, $a)['success']);
        $this->assertFalse($this->service->linkHistorical(1, 10, $b, $a, $old, 7)['success']);
        $this->assertFalse($this->link($a, $b)['success']);
        $this->assertFalse($this->link($a, $c)['success']);
        $this->assertFalse($this->link($c, $b)['success']);
        $this->assertCount(2, $this->items->currentForVehicle(1, 10));
    }

    public function testHistoricalMembershipResolvesCanonicalAndResolvedObservationPreservesState(): void
    {
        $this->create();
        $incidentId = $this->create();
        [$target, $source] = array_map('intval', array_column($this->items->currentForVehicle(1, 10), 'id'));
        $this->assertTrue($this->link($source, $target)['success']);
        $area = $this->area(['effect_code' => 'worsened', 'vehicle_damage_item_id' => $source, 'severity_code' => 'severe', 'external_reference' => 'Synthetic historical evidence']);
        $later = $this->create(['areas' => [$area]]);
        $this->assertSame($target, (int) $this->incidents->memberships(1, 10, $later)[0]['vehicle_damage_item_id']);
        $this->assertSame('severe', $this->items->item(1, 10, $target)['severity_code']);
        $this->assertSame('cosmetic', $this->items->item(1, 10, $source)['severity_code']);
        $this->assertCount(1, $this->items->evidence(1, $source));
        $this->assertSame([], $this->items->evidence(1, $target));
        $conditions = new VehicleDamageService($this->connection);
        $this->assertTrue($conditions->transitionStatus(1, 10, $target, 'resolved_other', 'Synthetic condition resolution', 7)['success']);
        $before = $this->items->item(1, 10, $target);
        $this->assertTrue($this->service->attachArea(1, 10, $incidentId, array_replace($area, ['effect_code' => 'observed_existing', 'external_reference' => '']), 7)['success']);
        $after = $this->items->item(1, 10, $target);
        foreach (['severity_code', 'status_code', 'discovered_at', 'resolved_at', 'description'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame([], $this->items->currentForVehicle(1, 10));
        $this->assertFalse($this->service->create(1, 10, $this->data(['areas' => [$area]]), 7)['success']);
    }

    public function testChangedSeveritySincePreviewAndMissingConfirmationAreRejected(): void
    {
        $this->create();
        $this->create();
        [$source, $target] = array_map('intval', array_column($this->items->currentForVehicle(1, 10), 'id'));
        $data = $this->linkData($source, $target);
        $this->assertFalse($this->service->linkHistorical(1, 10, $source, $target, array_replace($data, ['confirmed' => '']), 7)['success']);
        $this->connection->table('vehicle_damage_items')->where('id', $target)->update(['severity_code' => 'moderate']);
        $this->assertFalse($this->service->linkHistorical(1, 10, $source, $target, $data, 7)['success']);
        $this->assertCount(2, $this->items->currentForVehicle(1, 10));
    }

    public function testPostCreateAreasAndEvidenceEnforceCompanyVehicleAndSoftDeletes(): void
    {
        $id = $this->create();
        $this->assertTrue($this->service->attachArea(1, 10, $id, $this->area(['panel_code' => 'hood', 'image_id' => 900]), 7)['success']);
        $this->assertCount(2, $this->incidents->memberships(1, 10, $id));
        $itemId = (int) $this->items->currentForVehicle(1, 10)[0]['id'];
        $conditions = new VehicleDamageService($this->connection);
        $this->assertTrue($conditions->attachEvidence(1, 10, $itemId, ['file_id' => 800], 7)['success']);
        $this->assertFalse($conditions->attachEvidence(1, 10, $itemId, ['file_id' => 801], 7)['success']);
        $this->assertFalse($conditions->attachEvidence(2, 10, $itemId, ['file_id' => 800], 7)['success']);
        $this->assertFalse($this->service->attachArea(1, 11, $id, $this->area(), 7)['success']);
        $this->assertFalse($this->service->attachArea(2, 20, $id, $this->area(), 7)['success']);
        $this->connection->table('files')->where('id', 800)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $this->connection->table('images')->where('id', 900)->update(['deleted_at' => '2026-10-01 00:00:00']);
        foreach ($this->items->currentForVehicle(1, 10) as $item) {
            $this->assertSame([], $this->items->evidence(1, (int) $item['id']));
        }
        $this->assertFalse($conditions->attachEvidence(1, 10, $itemId, ['file_id' => 800], 7)['success']);
        $this->assertSame([], $this->incidents->forVehicle(2, 10));
    }

    public function testMultiPanelFailureRollsBackAllConditionsEventsMembershipsAndAudit(): void
    {
        $result = $this->service->create(1, 10, $this->data(['areas' => [$this->area(['file_id' => 800]), $this->area(['panel_code' => 'hood', 'image_id' => 901])]]), 7);
        $this->assertFalse($result['success']);
        foreach (['vehicle_damage_items', 'vehicle_damage_incidents', 'vehicle_damage_incident_items', 'vehicle_damage_item_events', 'vehicle_damage_item_evidence', 'audit_logs'] as $table) {
            $this->assertSame(0, $this->connection->table($table)->countAllResults());
        }
    }

    public function testCanonicalMutationResolvesHistoricalRecordAndOwnership(): void
    {
        $this->create();
        $this->create();
        [$a, $b] = array_map('intval', array_column($this->items->currentForVehicle(1, 10), 'id'));
        $this->assertTrue($this->link($b, $a)['success']);
        $conditions = new VehicleDamageService($this->connection);
        $this->assertTrue($conditions->changeSeverity(1, 10, $b, 'severe', 'Operator review of canonical condition', 7)['success']);
        $this->assertSame('severe', $this->items->item(1, 10, $a)['severity_code']);
        $this->assertSame('cosmetic', $this->items->item(1, 10, $b)['severity_code']);
        $this->assertNull($this->items->canonicalItem(2, 10, $b));
        $this->assertNull($this->items->canonicalItem(1, 11, $b));
    }

    private function create(array $overrides = []): int
    {
        $result = $this->service->create(1, 10, $this->data($overrides), 7);
        $this->assertTrue($result['success'], json_encode($result));

        return (int) $result['id'];
    }

    public function testMigrationPreservesPreexistingPhaseADataAndForeignKeys(): void
    {
        $this->dropTables();
        $this->createPrerequisites();
        (new CreateVehicleDamageLedger(Database::forge($this->connection)))->up();
        $this->seed();
        $conditions = new VehicleDamageService($this->connection);
        $created = $conditions->create(1, 10, ['zone_code' => 'front', 'damage_type_code' => 'dent', 'severity_code' => 'unsafe', 'description' => 'Synthetic legacy damage', 'discovered_at' => '2026-09-25T09:30', 'trip_id' => 100, 'file_id' => 800], 7);
        $this->assertTrue($created['success']);
        $itemId = (int) $created['id'];
        $before = $this->items->item(1, 10, $itemId);
        $events = $this->items->events(1, $itemId);
        $evidence = $this->items->evidence(1, $itemId);
        (new AddVehicleDamageIncidentsAndConditionLinks(Database::forge($this->connection)))->up();
        $after = $this->items->item(1, 10, $itemId);
        $this->assertNull($after['panel_code']);
        $this->assertNull($after['current_condition_item_id']);
        unset($after['panel_code'], $after['current_condition_item_id']);
        $this->assertSame($before, $after);
        $this->assertSame($events, $this->items->events(1, $itemId));
        $this->assertSame($evidence, $this->items->evidence(1, $itemId));
        $this->assertSame([], $this->connection->query('PRAGMA foreign_key_check')->getResultArray());
        $this->assertTrue($conditions->worsen(1, 10, $itemId, ['severity_code' => 'unsafe', 'note' => 'Synthetic legacy worsening'], 7)['success']);
    }

    public function testConditionAndChecklistRenderIdenticalCanonicalCards(): void
    {
        $this->create();
        $this->create();
        $this->create(['areas' => [$this->area(['panel_code' => 'hood'])]]);
        [$a, $b] = array_map('intval', array_slice(array_column($this->items->currentForVehicle(1, 10), 'id'), 0, 2));
        $this->assertTrue($this->link($b, $a)['success']);
        $workspace = (new VehicleDamageReadService(new VehicleDamageService($this->connection)))->workspace(1, 10);
        $data = ['vehicle' => ['id' => 10], 'checklist' => ['id' => 321, 'fleet_vehicle_id' => 10, 'turo_trip_normalized_id' => 102, 'starts_at' => '2026-10-06 09:00:00'], 'vehicleDamage' => $workspace, 'notice' => null, 'errors' => [], 'form' => null, 'formData' => []];
        foreach (['fleet_vehicles/components/vehicle_damage', 'trip_movement_checklists/_known_damage'] as $view) {
            $html = \CodeIgniter\Config\Services::renderer()->setData($data)->render($view);
            $this->assertSame(2, substr_count($html, 'damage-item-card'));
            $this->assertStringContainsString('Front bumper', $html);
            $this->assertStringContainsString('Hood', $html);
        }
    }

    public function testPublicEvidenceAndChangedVehicleMetadataAreRejectedOrExcluded(): void
    {
        $this->create();
        $id = (int) $this->items->currentForVehicle(1, 10)[0]['id'];
        $conditions = new VehicleDamageService($this->connection);
        foreach (['https://example.invalid/evidence', '../escape', 'public/evidence.jpg', '/absolute/evidence.jpg'] as $path) {
            $this->connection->table('files')->where('id', 800)->update(['path' => $path]);
            $this->assertFalse($conditions->attachEvidence(1, 10, $id, ['file_id' => 800], 7)['success']);
        }
        $this->connection->table('files')->where('id', 800)->update(['path' => 'private/synthetic.pdf', 'storage_disk' => 'public']);
        $this->assertFalse($conditions->attachEvidence(1, 10, $id, ['file_id' => 800], 7)['success']);
        $this->connection->table('files')->where('id', 800)->update(['storage_disk' => 'local']);
        $this->assertTrue($conditions->attachEvidence(1, 10, $id, ['file_id' => 800], 7)['success']);
        $this->connection->table('vehicle_files')->where('file_id', 800)->update(['fleet_vehicle_id' => 11]);
        $this->assertSame([], $this->items->evidence(1, $id));
    }

    public function testRowLockRevalidationRejectsAConcurrentSeverityChange(): void
    {
        $this->create();
        $id = (int) $this->items->currentForVehicle(1, 10)[0]['id'];
        $repository = new class ($this->connection) extends VehicleDamageRepository {
            public function __construct(private readonly BaseConnection $connection)
            {
                parent::__construct($connection);
            }

            public function lockItem(int $companyId, int $vehicleId, int $itemId): void
            {
                parent::lockItem($companyId, $vehicleId, $itemId);
                // Inject the state visible after acquiring the lock, as a concurrent writer would.
                $this->connection->table('vehicle_damage_items')->where('company_id', $companyId)->where('fleet_vehicle_id', $vehicleId)->where('id', $itemId)->update(['severity_code' => 'unsafe']);
            }
        };
        $conditions = new VehicleDamageService($this->connection, $repository);
        $beforeEvents = count($this->items->events(1, $id));
        $result = $conditions->worsen(1, 10, $id, ['severity_code' => 'moderate', 'note' => 'Synthetic worsening based on stale state'], 7);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('concurrently', implode(' ', $result['errors']));
        $this->assertCount($beforeEvents, $this->items->events(1, $id));
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['discovered_at' => '2026-10-05T09:30', 'attribution_type' => 'unknown', 'areas' => [$this->area()]], $overrides);
    }

    private function area(array $overrides = []): array
    {
        return array_replace(['panel_code' => 'front_bumper', 'damage_type_code' => 'scratch_scuff', 'severity_code' => 'cosmetic', 'effect_code' => 'new_damage', 'note' => 'Synthetic panel scrape'], $overrides);
    }

    private function linkData(int $source, int $target): array
    {
        return ['confirmed' => '1', 'reason' => 'Operator verified synthetic historical worsening', 'source_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $source)), 'target_state' => VehicleDamageIncidentService::fingerprint($this->items->item(1, 10, $target))];
    }

    private function link(int $source, int $target): array
    {
        return $this->service->linkHistorical(1, 10, $source, $target, $this->linkData($source, $target), 7);
    }
    private function createPrerequisites(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, vehicle_status_id INTEGER, fleet_code VARCHAR(80), display_name VARCHAR(150), out_of_service_date DATE NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_vehicle_id INTEGER, turo_reservation_id VARCHAR(80), starts_at DATETIME, ends_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, event_code VARCHAR(40), occurred_at DATETIME, voided_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_recovery_exceptions') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, turo_trip_normalized_id INTEGER, fleet_vehicle_id INTEGER, trip_movement_event_id INTEGER, exception_code VARCHAR(40), note TEXT NULL, status VARCHAR(20))');
        $this->connection->query('CREATE TABLE ' . $this->table('damage_claims') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, claim_status_lookup_value_id INTEGER NULL, claim_number VARCHAR(120) NULL, closed_on DATE NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('images') . ' (id INTEGER PRIMARY KEY, path VARCHAR(255), storage_disk VARCHAR(80) DEFAULT "local", alt_text VARCHAR(190) NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('files') . ' (id INTEGER PRIMARY KEY, path VARCHAR(255), storage_disk VARCHAR(80) DEFAULT "local", original_filename VARCHAR(190) NULL, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_images') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, image_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('vehicle_files') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, file_id INTEGER)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_types') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(120), is_active BOOLEAN)');
        $this->connection->query('CREATE TABLE ' . $this->table('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER NULL, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)');
    }

    private function seed(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'LOCAL-DAMAGE-10', 'display_name' => 'Damage Vehicle'],
            ['id' => 11, 'company_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'LOCAL-DAMAGE-11', 'display_name' => 'Other Vehicle'],
            ['id' => 20, 'company_id' => 2, 'vehicle_status_id' => 1, 'fleet_code' => 'OTHER-COMPANY-20', 'display_name' => 'Other Company'],
        ]);
        $this->connection->table('turo_trips_normalized')->insertBatch([
            ['id' => 100, 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_reservation_id' => 'LOCAL-DAMAGE-TRIP-A'],
            ['id' => 102, 'company_id' => 1, 'fleet_vehicle_id' => 10, 'turo_reservation_id' => 'LOCAL-DAMAGE-TRIP-B'],
            ['id' => 101, 'company_id' => 1, 'fleet_vehicle_id' => 11, 'turo_reservation_id' => 'LOCAL-OTHER-TRIP'],
            ['id' => 200, 'company_id' => 2, 'fleet_vehicle_id' => 20, 'turo_reservation_id' => 'OTHER-COMPANY-TRIP'],
        ]);
        $this->connection->table('trip_movement_events')->insertBatch([
            ['id' => 1000, 'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10, 'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-25 09:00:00'],
            ['id' => 1002, 'company_id' => 1, 'turo_trip_normalized_id' => 102, 'fleet_vehicle_id' => 10, 'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-26 16:30:00'],
            ['id' => 1001, 'company_id' => 1, 'turo_trip_normalized_id' => 101, 'fleet_vehicle_id' => 11, 'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-25 09:00:00'],
        ]);
        $this->connection->table('vehicle_recovery_exceptions')->insertBatch([
            ['id' => 500, 'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 10, 'trip_movement_event_id' => 1000, 'exception_code' => 'damage', 'note' => 'Bumper scrape', 'status' => 'open'],
            ['id' => 501, 'company_id' => 1, 'turo_trip_normalized_id' => 101, 'fleet_vehicle_id' => 11, 'trip_movement_event_id' => 1001, 'exception_code' => 'damage', 'note' => 'Other vehicle', 'status' => 'open'],
        ]);
        $this->connection->table('lookup_types')->insert(['id' => 1, 'code' => 'audit_action']);
        $this->connection->table('lookup_values')->insertBatch([
            ['id' => 1, 'lookup_type_id' => 1, 'code' => 'created', 'name' => 'Created', 'is_active' => 1],
            ['id' => 2, 'lookup_type_id' => 1, 'code' => 'updated', 'name' => 'Updated', 'is_active' => 1],
            ['id' => 3, 'lookup_type_id' => 2, 'code' => 'open', 'name' => 'Open', 'is_active' => 1],
        ]);
        $this->connection->table('damage_claims')->insertBatch([
            ['id' => 700, 'fleet_vehicle_id' => 10, 'claim_status_lookup_value_id' => 3, 'claim_number' => 'CLAIM-700'],
            ['id' => 701, 'fleet_vehicle_id' => 11, 'claim_status_lookup_value_id' => 3, 'claim_number' => 'CLAIM-701'],
        ]);
        $this->connection->table('files')->insertBatch([['id' => 800, 'path' => 'private/file-800', 'original_filename' => 'damage.pdf'], ['id' => 801, 'path' => 'private/file-801', 'original_filename' => 'other.pdf']]);
        $this->connection->table('images')->insertBatch([['id' => 900, 'path' => 'private/image-900', 'alt_text' => 'Damage image'], ['id' => 901, 'path' => 'private/image-901', 'alt_text' => 'Other image']]);
        $this->connection->table('vehicle_files')->insertBatch([['id' => 1, 'fleet_vehicle_id' => 10, 'file_id' => 800], ['id' => 2, 'fleet_vehicle_id' => 11, 'file_id' => 801]]);
        $this->connection->table('vehicle_images')->insertBatch([['id' => 1, 'fleet_vehicle_id' => 10, 'image_id' => 900], ['id' => 2, 'fleet_vehicle_id' => 11, 'image_id' => 901]]);
    }

    private function dropTables(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['vehicle_damage_incident_items', 'vehicle_damage_incidents', 'vehicle_damage_item_evidence', 'vehicle_damage_item_events', 'vehicle_damage_items', 'audit_logs', 'vehicle_files', 'vehicle_images', 'files', 'images', 'damage_claims', 'vehicle_recovery_exceptions', 'trip_movement_events', 'turo_trips_normalized', 'fleet_vehicles', 'companies', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
