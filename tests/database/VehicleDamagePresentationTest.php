<?php

use App\Repositories\OperationalFactsRepository;
use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageReadService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Migrations;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamagePresentationTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use FeatureTestTrait;

    private BaseConnection $connection;
    private VehicleDamageRepository $items;
    private VehicleDamageIncidentRepository $incidents;
    private VehicleDamageService $conditions;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->connection = Database::connect('tests');
        VehicleDamageDatabaseFixture::migrate($this->connection);
        VehicleDamageDatabaseFixture::seed($this->connection);
        // The application selects one active fleet company; the other remains an ownership adversary.
        $this->connection->table('fleet_vehicles')->where('id', 20)->update(['out_of_service_date' => '1900-01-01']);
        $this->items = new VehicleDamageRepository($this->connection);
        $this->incidents = new VehicleDamageIncidentRepository($this->connection);
        $this->conditions = new VehicleDamageService($this->connection);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testAuthenticatedVehiclePageRendersLegacyCurrentAndHistoryWithEmptyIncidents(): void
    {
        $this->legacyPresentation();
        $this->authenticate();
        $before = $this->damageRows();
        $response = $this->get('/fleet/vehicles/10');
        $response->assertOK();
        $html = $response->getBody();
        $this->assertStringContainsString('Damage &amp; Condition', $html);
        $this->assertStringContainsString('2 current known', $html);
        $this->assertStringContainsString('1 historical', $html);
        $this->assertStringContainsString('Synthetic trip-linked legacy dent', $html);
        $this->assertStringContainsString('Synthetic unlinked legacy scratch', $html);
        $this->assertStringContainsString('Synthetic repaired legacy dent', $html);
        $this->assertStringContainsString('SYNTHETIC-RESERVATION-100', $html);
        $this->assertStringNotContainsString('Synthetic other-company damage', $html);
        $this->assertStringNotContainsString('Synthetic other-vehicle damage', $html);
        $this->assertSame($before, $this->damageRows());
    }

    public function testAuthenticatedChecklistRendersOnlyCurrentLegacyDamageWithEmptyIncidents(): void
    {
        $this->legacyPresentation();
        $this->connection->table('trip_movement_checklists')->insert([
            'id' => 321, 'turo_trip_normalized_id' => 102, 'fleet_vehicle_id' => 10,
            'movement_type' => 'pickup', 'scheduled_at' => '2026-09-25 08:00:00',
        ]);
        // The unrelated trip-context query fails under SQLite's db_ test table prefix.
        // Keep the controller, authorization, damage queries, projection and renderer real.
        $facts = $this->getMockBuilder(OperationalFactsRepository::class)
            ->setConstructorArgs([$this->connection])->onlyMethods(['tripContext'])->getMock();
        $facts->expects($this->once())->method('tripContext')->willReturn(['previous' => null, 'current' => null, 'next' => null]);
        Services::injectMock('operationalFactsRepository', $facts);
        $this->authenticate();
        $before = $this->damageRows();
        $response = $this->get('/operations/checklists/321');
        $response->assertOK();
        $html = $response->getBody();
        $this->assertStringContainsString('Current Known Damage', $html);
        $this->assertSame(2, substr_count($html, 'damage-item-card'));
        $this->assertStringContainsString('Synthetic trip-linked legacy dent', $html);
        $this->assertStringContainsString('Synthetic unlinked legacy scratch', $html);
        $this->assertStringContainsString('SYNTHETIC-RESERVATION-100', $html);
        $this->assertStringNotContainsString('Synthetic repaired legacy dent', $html);
        $this->assertStringNotContainsString('Synthetic other-company damage', $html);
        $this->assertStringNotContainsString('Synthetic other-vehicle damage', $html);
        $this->assertSame($before, $this->damageRows());
    }

    public function testTripValidationAndSelectorsAuthorizeThroughTheMatchingVehicle(): void
    {
        $this->assertFalse($this->connection->fieldExists('company_id', 'turo_trips_normalized'));
        $this->assertSame(100, (int) $this->items->trip(1, 10, 100)['id']);
        $this->assertSame([102, 100], array_map('intval', array_column($this->incidents->trips(1, 10), 'id')));
        $this->createDamage('Synthetic valid trip', 100);
        foreach ([[1, 10, 101], [1, 10, 200], [2, 10, 100], [1, 20, 200]] as [$company, $vehicle, $trip]) {
            $this->assertNull($this->items->trip($company, $vehicle, $trip));
            $this->assertFalse($this->conditions->create($company, $vehicle, $this->damageData('Synthetic invalid trip', $trip), 7)['success']);
        }
        $this->assertSame([], $this->incidents->trips(2, 10));
        $this->assertSame([], $this->incidents->trips(1, 20));
        $this->assertCount(1, $this->items->currentForVehicle(1, 10));
        $this->assertSame([], $this->items->currentForVehicle(2, 10));
    }

    /** @return iterable<string,array{?int,bool}> */
    public static function optionalTripContexts(): iterable
    {
        yield 'matching vehicle' => [100, false];
        yield 'wrong vehicle' => [101, false];
        yield 'other company' => [200, false];
        yield 'soft-deleted trip' => [100, true];
        yield 'unassigned trip' => [300, false];
        yield 'null trip' => [null, false];
    }

    #[DataProvider('optionalTripContexts')]
    public function testLegacyAndEventMetadataKeepOwnedDamageWhenOptionalTripContextIsUnavailable(?int $trip, bool $deleted): void
    {
        $this->unassignedTrip();
        $id = $this->createDamage('Synthetic retained legacy damage', 100);
        // Model persisted legacy references without relaxing production write validation.
        $this->connection->table('vehicle_damage_items')->where('id', $id)->update(['discovered_turo_trip_normalized_id' => $trip]);
        $this->connection->table('vehicle_damage_item_events')->where('vehicle_damage_item_id', $id)->update(['source_turo_trip_normalized_id' => $trip]);
        if ($deleted) {
            $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['deleted_at' => '2026-10-01 00:00:00']);
        }
        $expected = $trip === 100 && ! $deleted ? 'SYNTHETIC-RESERVATION-100' : null;
        $workspace = (new VehicleDamageReadService($this->conditions, $this->items))->workspace(1, 10);
        $this->assertCount(1, $workspace['current']);
        $item = $workspace['current'][0];
        $this->assertSame($id, (int) $item['id']);
        $this->assertSame('Synthetic retained legacy damage', $item['description']);
        $this->assertSame($expected, $item['turo_reservation_id']);
        $this->assertCount(1, $item['events']);
        $this->assertSame($expected, $item['events'][0]['turo_reservation_id']);
        $this->assertSame([], $this->items->events(2, $id));
        if ($deleted || $trip === 300) {
            $this->assertNull($this->items->trip(1, 10, (int) $trip));
            $this->assertNotContains($trip, array_map('intval', array_column($this->incidents->trips(1, 10), 'id')));
        }
    }

    #[DataProvider('optionalTripContexts')]
    public function testIncidentMetadataKeepsOwnedIncidentWithoutLeakingOptionalTripContext(?int $trip, bool $deleted): void
    {
        $this->unassignedTrip();
        $result = (new VehicleDamageIncidentService($this->connection))->create(1, 10, [
            'discovered_at' => '2026-09-25T07:30', 'trip_id' => 100,
            'attribution_type' => 'discovered_during_trip', 'areas' => [[
                'panel_code' => 'hood', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic',
                'effect_code' => 'new_damage', 'note' => 'Synthetic incident dent',
            ]],
        ], 7);
        $this->assertTrue($result['success'], json_encode($result));
        $id = (int) $result['id'];
        $this->connection->table('vehicle_damage_incidents')->where('id', $id)->update(['turo_trip_normalized_id' => $trip]);
        if ($deleted) {
            $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['deleted_at' => '2026-10-01 00:00:00']);
        }
        $expected = $trip === 100 && ! $deleted ? 'SYNTHETIC-RESERVATION-100' : null;
        $this->assertSame($expected, $this->incidents->incident(1, 10, $id)['turo_reservation_id']);
        $this->assertCount(1, $this->incidents->forVehicle(1, 10));
        $this->assertCount(1, $this->incidents->memberships(1, 10, $id));
        $this->assertNotEmpty($this->incidents->history(1, 10, $id));
        foreach ([[2, 10], [1, 11], [2, 20]] as [$company, $vehicle]) {
            $this->assertNull($this->incidents->incident($company, $vehicle, $id));
            $this->assertSame([], $this->incidents->memberships($company, $vehicle, $id));
            $this->assertSame([], $this->incidents->history($company, $vehicle, $id));
        }
        // Damage/company columns alone must not authorize a reassigned vehicle.
        $this->connection->table('fleet_vehicles')->where('id', 10)->update(['company_id' => 2]);
        $this->assertNull($this->incidents->incident(1, 10, $id));
        $this->assertSame([], $this->incidents->forVehicle(1, 10));
        $this->assertSame([], $this->incidents->memberships(1, 10, $id));
        $this->assertSame([], $this->incidents->history(1, 10, $id));
        $this->assertSame([], $this->items->currentForVehicle(1, 10));
        $itemId = (int) $this->connection->table('vehicle_damage_items')->get()->getRowArray()['id'];
        $this->assertSame([], $this->items->events(1, $itemId));
    }

    public function testSoftDeletedVehicleCannotAuthorizeDamageOrIncidentReads(): void
    {
        $id = $this->createDamage('Synthetic soft-deleted vehicle damage', 100);
        $this->connection->table('fleet_vehicles')->where('id', 10)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $this->assertNull($this->items->trip(1, 10, 100));
        $this->assertNull($this->items->item(1, 10, $id));
        $this->assertSame([], $this->items->currentForVehicle(1, 10));
        $this->assertSame([], $this->items->events(1, $id));
        $this->assertSame([], $this->incidents->trips(1, 10));
    }

    public function testHistoricalBackfillScreenAndPostReuseUnknownPanelCondition(): void
    {
        $id = $this->createDamage('Synthetic historical original', null);
        $this->authenticate();
        $before = $this->damageRows();
        $url = '/fleet/vehicles/10/damage/' . $id . '/historical-original';
        $response = $this->get($url);
        $response->assertOK();
        $html = $response->getBody();
        $this->assertStringContainsString('Unknown / Not specified', $html);
        $this->assertStringContainsString('No new damage condition will be created', $html);
        $this->assertStringContainsString('SYNTHETIC-RESERVATION-100', $html);
        $this->assertStringNotContainsString('SYNTHETIC-RESERVATION-200', $html);
        $this->assertStringNotContainsString('name="panel_code"', $html);
        $this->assertStringContainsString('name="confirmed"', $html);
        foreach (array_keys(\Config\VehicleDamage::ATTRIBUTIONS) as $type) {
            $this->assertStringContainsString('value="' . $type . '"', $html);
        }
        $this->assertSame($before, $this->damageRows());
        $service = new VehicleDamageIncidentService($this->connection);
        $data = ['trip_id' => 100, 'attribution_type' => 'operator_attributed_cause', 'reason' => 'Synthetic authoritative original incident', 'confirmed' => '1', 'expected_state' => $service->historicalOriginalPreview(1, 10, $id)['expected_state']];
        $security = CoreServices::security();
        $response = $this->post($url, $data + [$security->getTokenName() => $security->getHash()]);
        $incident = $this->incidents->forVehicle(1, 10)[0];
        $response->assertRedirectTo('/fleet/vehicles/10/damage-incidents/' . $incident['id']);
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_item_evidence'] as $table) {
            $this->assertSame($before[$table], $this->damageRows()[$table]);
        }
        $this->assertNull($this->incidents->memberships(1, 10, (int) $incident['id'])[0]['panel_code']);
        $show = $this->get('/fleet/vehicles/10/damage-incidents/' . $incident['id']);
        $show->assertOK();
        $this->assertStringContainsString('Unknown / Not specified', $show->getBody());
        $this->assertStringContainsString('name="reason"', $show->getBody());
        $this->get($url)->assertOK();
        $this->assertStringNotContainsString('name="confirmed"', $this->get($url)->getBody());
    }

    public function testHistoricalRoutesRejectUnownedItemAndStaleSubmission(): void
    {
        $id = $this->createDamage('Synthetic owned condition', null);
        $other = $this->createDamage('Synthetic unowned condition', 200, 2, 20);
        $this->authenticate();
        foreach (['/fleet/vehicles/10/damage/' . $other . '/historical-original', '/fleet/vehicles/11/damage/' . $id . '/historical-original'] as $unownedUrl) {
            try {
                $this->get($unownedUrl);
                $this->fail('Unowned condition must not be accessible.');
            } catch (\CodeIgniter\Exceptions\PageNotFoundException $exception) {
                $this->assertSame(404, $exception->getCode());
            }
        }
        $before = $this->damageRows();
        $url = '/fleet/vehicles/10/damage/' . $id . '/historical-original';
        $security = CoreServices::security();
        $this->post($url, ['trip_id' => 100, 'confirmed' => '1', 'expected_state' => 'stale', 'reason' => 'Synthetic stale request', $security->getTokenName() => $security->getHash()])->assertRedirectTo($url);
        $this->assertSame($before, $this->damageRows());
    }

    public function testHistoricalBackfillPostWithoutCsrfCannotMutateRecords(): void
    {
        $id = $this->createDamage('Synthetic protected condition', null);
        $this->authenticate();
        $before = $this->damageRows();
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        try {
            $this->post('/fleet/vehicles/10/damage/' . $id . '/historical-original', ['confirmed' => '1']);
        } finally {
            $this->assertSame($before, $this->damageRows());
        }
    }

    public function testHistoricalStaleValidationPreservesEnteredContextAndRequiresFreshConfirmation(): void
    {
        $id = $this->createDamage('Synthetic retained historical context', null);
        $this->authenticate();
        $url = '/fleet/vehicles/10/damage/' . $id . '/historical-original';
        $data = ['trip_id' => 100, 'confirmed' => '1', 'expected_state' => 'stale', 'attribution_type' => 'suspected_cause', 'reason' => 'Synthetic retained operator reason', 'discovered_at' => '2026-09-25 07:30:17'];
        $security = CoreServices::security();
        $before = $this->damageRows();
        $this->post($url, $data + [$security->getTokenName() => $security->getHash()])->assertRedirectTo($url);
        $screen = $this->withSession()->get($url);
        $screen->assertOK();
        $html = $screen->getBody();
        $this->assertStringContainsString('Reload if the condition changed', $html);
        $this->assertStringContainsString('Synthetic retained operator reason', $html);
        $this->assertStringContainsString('value="100" selected', $html);
        $this->assertStringContainsString('value="suspected_cause" selected', $html);
        $this->assertStringContainsString('value="2026-09-25 07:30:17"', $html);
        $this->assertStringNotContainsString('value="1" checked', $html);
        $preview = (new VehicleDamageIncidentService($this->connection))->historicalOriginalPreview(1, 10, $id);
        $this->assertStringContainsString('value="' . $preview['expected_state'] . '"', $html);
        $this->assertSame($before, $this->damageRows());
        $data['expected_state'] = $preview['expected_state'];
        $this->withSession()->post($url, $data + [$security->getTokenName() => $security->getHash()])->assertRedirect();
        $this->assertCount(1, $this->incidents->forVehicle(1, 10));
    }

    public function testHistoricalBackfillRequiresAdminPermissionBeforeControllerAccess(): void
    {
        $id = $this->createDamage('Synthetic permission-protected condition', null);
        $this->authenticate();
        ShieldServices::auth()->user()->removePermission('admin.access');
        $before = $this->damageRows();
        $url = '/fleet/vehicles/10/damage/' . $id . '/historical-original';
        $this->get($url)->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $security = CoreServices::security();
        $this->post($url, [$security->getTokenName() => $security->getHash(), 'confirmed' => '1'])->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $this->assertSame($before, $this->damageRows());
    }

    public function testHistoricalRoutesRequireSessionPermissionAndCsrf(): void
    {
        $this->authenticate();
        foreach (['GET', 'POST'] as $method) {
            $filters = (new \CodeIgniter\Commands\Utilities\Routes\FilterCollector())->get($method, 'fleet/vehicles/10/damage/999/historical-original')['before'];
            $this->assertContains('session', $filters);
            $this->assertContains('permission:admin.access', $filters);
            if ($method === 'POST') {
                $this->assertContains('csrf', $filters);
            }
        }
        ShieldServices::auth()->logout();
        $before = $this->damageRows();
        $this->get('/fleet/vehicles/10/damage/999/historical-original')->assertRedirectTo('login');
        $this->assertSame($before, $this->damageRows());
    }

    private function authenticate(): void
    {
        $runner = new MigrationRunner(new Migrations(), $this->connection);
        $runner->setNamespace('CodeIgniter\\Shield')->latest();
        $runner->setNamespace('CodeIgniter\\Settings')->latest();
        $users = new UserModel();
        $user = new User(['username' => 'synthetic-damage-operator', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addPermission('admin.access');
        $this->actingAs($user);
        $this->assertTrue(ShieldServices::auth()->loggedIn());
        CoreServices::routes()->resetRoutes();
        CoreServices::routes()->loadRoutes();
        $this->withRoutes();
    }

    private function legacyPresentation(): void
    {
        $this->assertFalse($this->connection->fieldExists('company_id', 'turo_trips_normalized'));
        $this->createDamage('Synthetic trip-linked legacy dent', 100);
        $this->createDamage('Synthetic unlinked legacy scratch', null);
        $repaired = $this->createDamage('Synthetic repaired legacy dent', 100);
        $this->assertTrue($this->conditions->transitionStatus(1, 10, $repaired, 'repaired', 'Synthetic repair', 7)['success']);
        $this->createDamage('Synthetic other-vehicle damage', 101, 1, 11);
        $this->createDamage('Synthetic other-company damage', 200, 2, 20);
        $workspace = (new VehicleDamageReadService($this->conditions, $this->items))->workspace(1, 10);
        $this->assertCount(2, $workspace['current']);
        $this->assertCount(1, $workspace['history']);
        $this->assertSame(0, $this->connection->table('vehicle_damage_incidents')->countAllResults());
        $this->assertSame(0, $this->connection->table('vehicle_damage_incident_items')->countAllResults());
    }

    private function unassignedTrip(): void
    {
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 300, 'fleet_vehicle_id' => null, 'turo_trip_id' => 'SYNTHETIC-UNASSIGNED',
            'turo_reservation_id' => 'SYNTHETIC-UNASSIGNED-RESERVATION',
            'starts_at' => '2026-09-25 08:00:00', 'ends_at' => '2026-09-27 08:00:00',
        ]);
    }

    private function createDamage(string $description, ?int $trip, int $company = 1, int $vehicle = 10): int
    {
        $result = $this->conditions->create($company, $vehicle, $this->damageData($description, $trip), 7);
        $this->assertTrue($result['success'], json_encode($result));

        return (int) $result['id'];
    }

    /** @return array<string,mixed> */
    private function damageData(string $description, ?int $trip): array
    {
        return [
            'zone_code' => 'front', 'damage_type_code' => 'dent', 'severity_code' => 'cosmetic',
            'description' => $description, 'discovered_at' => '2026-09-25T07:30', 'trip_id' => $trip,
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function damageRows(): array
    {
        $rows = [];
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_item_evidence', 'vehicle_damage_incidents', 'vehicle_damage_incident_items'] as $table) {
            $rows[$table] = $this->connection->table($table)->orderBy('id')->get()->getResultArray();
        }

        return $rows;
    }
}
