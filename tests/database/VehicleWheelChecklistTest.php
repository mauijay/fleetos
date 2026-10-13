<?php

namespace Tests\Database;

use App\Controllers\TripMovementChecklists;
use App\Controllers\VehicleWheelChecklist;
use App\Services\Fleet\VehicleDamageService;
use App\Services\Fleet\VehicleWheelChecklistService;
use App\Services\Fleet\VehicleWheelDamageService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleWheelChecklistFixture as ChecklistFixture;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelChecklistTest extends CIUnitTestCase
{
    private ?VehicleWheelDamageFixture $fixture = null;
    private array $connections = [];

    protected function setUp(): void
    {
        // The full suite can leave services bound to a preceding fixture's closed connection.
        Services::reset();
        parent::setUp();
    }

    private function fixture(string $engine): VehicleWheelDamageFixture
    {
        $this->connections = \Config\Database::getConnections();
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        ChecklistFixture::prepare($f->schema->db);
        ChecklistFixture::inject($f->schema->db);
        CoreServices::request()->setGlobal('get', []);
        CoreServices::request()->setGlobal('post', []);
        return $f;
    }

    protected function tearDown(): void
    {
        Services::reset();
        (new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        $this->fixture?->close();
        parent::tearDown();
    }

    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'maria' => ['maria']];
    }

    private function controller(): VehicleWheelChecklist
    {
        $controller = new VehicleWheelChecklist();
        $controller->initController(CoreServices::request(), CoreServices::response(), CoreServices::logger());
        return $controller;
    }

    private function checklist(): TripMovementChecklists
    {
        $controller = new TripMovementChecklists();
        $controller->initController(CoreServices::request(), CoreServices::response(), CoreServices::logger());
        return $controller;
    }

    private function post(string $wheel, string $action, array $data, int $checklist = 100): \CodeIgniter\HTTP\RedirectResponse
    {
        CoreServices::request()->setGlobal('post', $data);
        return $this->controller()->command($checklist, $wheel, $action);
    }

    private function firewall(): array
    {
        $rows = $this->fixture->schema->rows();
        foreach (array_keys($rows) as $table) {
            if (str_starts_with($table, 'vehicle_damage_') || in_array($table, ['audit_logs','files','vehicle_files','images','vehicle_images'], true)) {
                unset($rows[$table]);
            }
        }
        return $rows;
    }

    #[DataProvider('engines')]
    public function testChecklistProjectionActionsGetPurityAndConstantQueryIncrease(string $engine): void
    {
        $f = $this->fixture($engine);
        $db = $f->schema->db;
        $rf = $f->service->createNew(1, 10, $f->data('RF'), 7);
        self::assertTrue($rf['success']);
        $rr = $f->service->createNew(1, 10, $f->data('RR'), 7);
        self::assertTrue($rr['success']);
        $root = $db->table('vehicle_damage_items')->where('id', $rr['condition_id'])->get()->getRowArray();
        unset($root['id']);
        $db->table('vehicle_damage_items')->insert($root);
        $damage = new VehicleDamageService($db);
        foreach ([['wheel_tire',null,'wheel_curb_rash','Unknown wheel legacy rash'], ['wheel_tire','rear_left_wheel_tire','crack_broken','Other wheel crack'], ['front','front_bumper','scratch_scuff','Generic body scratch']] as [$zone,$panel,$type,$note]) {
            $result = $damage->create(1, 10, ['zone_code' => $zone,'panel_code' => $panel,'damage_type_code' => $type,'severity_code' => 'cosmetic','description' => $note,'discovered_at' => '2026-10-01 10:00:00'], 7);
            self::assertTrue($result['success'], json_encode($result));
        }
        $before = $f->schema->rows();
        $asOf = new \DateTimeImmutable();
        $readiness = Services::movementReadinessReadService()->forCompany(1, [100,102,103,105], $asOf);
        $html = $this->checklist()->show(100);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(['LF','RF','RR','LR'], array_map(static fn (\DOMElement $node): string => $node->getAttribute('data-wheel-actions'), iterator_to_array($xpath->query('//*[@data-wheel-actions]'))));
        self::assertCount(1, $xpath->query('//*[@data-wheel-actions="LF"]//a[contains(text(),"Record new curb rash")]'));
        self::assertCount(0, $xpath->query('//*[@data-wheel-actions="RF"]//a[contains(text(),"Record new")]'));
        self::assertCount(1, $xpath->query('//*[@data-wheel-actions="RF"]//a[contains(text(),"Confirm unchanged")]'));
        self::assertCount(1, $xpath->query('//*[@data-wheel-actions="RF"]//a[contains(text(),"Record worsening")]'));
        self::assertCount(1, $xpath->query('//*[@data-wheel-actions="RR"]//a'));
        foreach (['No active rash recorded','Review conflicting records','Wheel position unknown','Other wheel crack','Generic body scratch','Wheel review is optional'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        foreach (range(1, 3) as $repeat) {
            $this->checklist()->show(100);
            $this->controller()->entry(100, 'LF', 'new');
            $this->controller()->entry(100, 'RF', 'unchanged');
            Services::vehicleWheelConditionReadService()->history(1, 10, 'RF');
        }
        self::assertSame($before, $f->schema->rows());
        self::assertSame($readiness, Services::movementReadinessReadService()->forCompany(1, [100,102,103,105], $asOf));
        $record = new class () {
            public bool $capture = false;
            public array $queries = [];
            public function observe($query): void
            {
                $sql = (string)$query;
                if ($this->capture && preg_match('/^SELECT\b/i', ltrim($sql)) && !preg_match('/sqlite_master|information_schema/i', $sql)) {
                    $this->queries[] = $sql;
                }
            }
            public function count(): int
            {
                return count($this->queries);
            }
        };
        Events::on('DBQuery', $record->observe(...));
        $disabled = new class ($db) extends VehicleWheelChecklistService {
            public function forChecklist(int $company, int $checklist, ?\DateTimeImmutable $asOf = null): ?array
            {
                return null;
            }
        };
        Services::injectMock('vehicleWheelChecklistService', $disabled);
        $this->checklist()->show(100);
        $record->capture = true;
        $this->checklist()->show(100);
        $record->capture = false;
        $baseline = $record->count();
        $record->queries = [];
        $enabled = new VehicleWheelChecklistService($db, Services::vehicleWheelConditionReadService());
        Services::injectMock('vehicleWheelChecklistService', $enabled);
        $this->checklist()->show(100);
        $record->capture = true;
        $this->checklist()->show(100);
        $record->capture = false;
        $after = $record->count();
        $record->queries = [];
        self::assertSame(4, $after - $baseline, 'One owned context SELECT plus the existing three-query wheel projection.');
        for ($i = 0;$i < 100;$i++) {
            $db->table('vehicle_damage_item_events')->insert(['company_id' => 1,'vehicle_damage_item_id' => $rf['condition_id'],'event_code' => 'observed_existing','occurred_at' => '2026-10-01 11:00:00','actor_user_id' => 7,'created_at' => '2026-10-01 11:00:00','new_status_code' => 'open','new_severity_code' => 'cosmetic','new_description' => 'Synthetic history']);
        }
        $record->capture = true;
        $this->checklist()->show(100);
        $record->capture = false;
        self::assertSame($after, $record->count());
        $directory = dirname(__DIR__, 2).'/build/slice5/';
        if (is_dir($directory)) {
            file_put_contents($directory.'checklist-budget-'.$engine.'.json', json_encode(['baseline' => $baseline,'slice5' => $after,'incremental' => 4,'projection' => 3,'large_history' => $record->count()]));
        }
    }

    #[DataProvider('engines')]
    public function testExplicitCommandsUseExactContextReceiptsAndReadinessFirewall(string $engine): void
    {
        $f = $this->fixture($engine);
        $db = $f->schema->db;
        $spy = new class ($db) extends VehicleWheelDamageService {
            public array $trusted = [];
            public function createNew(int $company, int $vehicle, array $data, int $actor, array $trustedContext = []): array
            {
                $this->trusted = $trustedContext;
                return parent::createNew($company, $vehicle, $data, $actor, $trustedContext);
            }
        };
        Services::injectMock('vehicleWheelDamageService', $spy);
        $firewall = $this->firewall();
        $asOf = new \DateTimeImmutable();
        $readiness = Services::movementReadinessReadService()->forCompany(1, [100,102,103,105], $asOf);
        $new = $f->data('LF', ['trip_id' => 200,'checklist_id' => 200,'fleet_vehicle_id' => 20,'movement_event_id' => 1002,'note' => 'Explicit staging new rash']);
        $response = $this->post('LF', 'new', $new);
        self::assertStringEndsWith('/operations/checklists/100#known-vehicle-damage', $response->getHeaderLine('Location'));
        $member = $db->table('vehicle_damage_incident_items')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $condition = (int)$member['vehicle_damage_item_id'];
        $incident = $db->table('vehicle_damage_incidents')->where('id', $member['vehicle_damage_incident_id'])->get()->getRowArray();
        self::assertSame(100, (int)$incident['turo_trip_normalized_id']);
        self::assertSame(100, $spy->trusted['checklist_id']);
        self::assertNull($incident['trip_movement_event_id']);
        self::assertNotNull($member['condition_event_id']);
        self::assertNotNull($member['basis_snapshot']);
        $beforeReplay = $f->schema->rows();
        $this->post('LF', 'new', $new);
        self::assertSame($beforeReplay, $f->schema->rows());
        $worse = $f->data('LF', ['note' => 'Explicit worsening at 4 o\'clock','severity_code' => 'severe']);
        $this->post('LF', 'worsening', $worse);
        $projection = Services::vehicleWheelConditionReadService()->forVehicles(1, [10], [10 => 100])[10]['wheels']['LF'];
        self::assertSame($condition, $projection['active_condition_id']);
        self::assertSame('Worsened on this trip', $projection['trip_badge']);
        $observation = $f->data('LF', ['observed_at' => date('Y-m-d H:i:s'),'severity_code' => null,'note' => 'Explicit unchanged, noncausal']);
        unset($observation['severity_code']);
        $observationResponse = $this->post('LF', 'unchanged', $observation);
        self::assertStringEndsWith('/operations/checklists/100#known-vehicle-damage', $observationResponse->getHeaderLine('Location'), json_encode(CoreServices::session()->getFlashdata('staging_wheel_errors')));
        $last = $db->table('vehicle_damage_incident_items')->orderBy('id', 'DESC')->get(1)->getRowArray();
        self::assertSame('observed_existing', $last['effect_code']);
        self::assertSame($condition, (int)$last['vehicle_damage_item_id']);
        $item = $db->table('vehicle_damage_items')->where('id', $condition)->get()->getRowArray();
        self::assertSame('severe', $item['severity_code']);
        self::assertSame('open', $item['status_code']);
        $afterObservation = Services::vehicleWheelConditionReadService()->forVehicles(1, [10], [10 => 100])[10]['wheels']['LF'];
        self::assertSame('Worsened on this trip', $afterObservation['trip_badge']);
        self::assertSame((int)$last['id'], (int)$afterObservation['trip_observation']['id']);
        self::assertStringContainsString('Observed on this trip - noncausal observation', $this->checklist()->show(100));
        self::assertNull(Services::vehicleWheelConditionReadService()->forVehicles(1, [10], [10 => 103])[10]['wheels']['LF']['trip_badge']);
        $baselineWorse = $f->data('LF', ['note' => 'Delta with explicitly selected baseline', 'severity_code' => 'severe','baseline_reference' => $last['condition_event_id'].':'.$last['id']]);
        $this->post('LF', 'worsening', $baselineWorse);
        $basisMember = $db->table('vehicle_damage_incident_items')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $basis = json_decode($basisMember['basis_snapshot'], true);
        self::assertSame((int)$last['id'], $basis['comparison']['baseline']['incident_item_id']);
        self::assertSame('observed_existing', $basis['comparison']['baseline']['kind']);
        self::assertSame('Recorded before scheduled pickup', Services::vehicleWheelConditionReadService()->occurrence(1, 10, $condition, (int)$basisMember['id'])['comparison']['heading']);
        $this->post('RF', 'new', $f->data('RF', ['note' => 'Explicit next checklist rash']), 103);
        self::assertSame(103, $spy->trusted['trip_id']);
        self::assertSame(103, $spy->trusted['checklist_id']);
        $nextMember = $db->table('vehicle_damage_incident_items')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $nextIncident = $db->table('vehicle_damage_incidents')->where('id', $nextMember['vehicle_damage_incident_id'])->get()->getRowArray();
        self::assertSame(103, (int)$nextIncident['turo_trip_normalized_id']);
        self::assertSame($firewall, $this->firewall());
        self::assertSame($readiness, Services::movementReadinessReadService()->forCompany(1, [100,102,103,105], $asOf));
        $before = $f->schema->rows();
        unset($observation['observed_at']);
        $observation['command_uuid'] = \App\Services\Fleet\VehicleDamageRepairService::commandKey();
        $this->post('LF', 'unchanged', $observation);
        self::assertSame($before, $f->schema->rows());
        $damageRows = static fn (): array => array_filter($f->schema->rows(), static fn (string $table): bool => str_starts_with($table, 'vehicle_damage_'), ARRAY_FILTER_USE_KEY);
        $lifecycleBefore = $damageRows();
        self::assertTrue(Services::tripMovementChecklistService()->completeChecklist(100, 'Synthetic lifecycle', 7, true));
        self::assertStringNotContainsString('Optional wheel actions', $this->checklist()->show(100));
        self::assertTrue(Services::tripMovementChecklistService()->reopenChecklist(100, 7));
        self::assertSame($lifecycleBefore, $damageRows());
    }

    #[DataProvider('engines')]
    public function testStaleFormsLifecycleScheduleAndForeignContextsCannotWrite(string $engine): void
    {
        $f = $this->fixture($engine);
        $db = $f->schema->db;
        $service = Services::vehicleWheelChecklistService();
        $stale = $f->data('LF');
        self::assertStringContainsString('staging-wheel-form', $this->controller()->entry(100, 'LF', 'new'));
        self::assertTrue($f->service->createNew(1, 10, $f->data('LF'), 7)['success']);
        $before = $f->schema->rows();
        $this->post('LF', 'new', $stale);
        self::assertSame($before, $f->schema->rows());
        $html = $this->controller()->entry(100, 'LF', 'new');
        self::assertStringNotContainsString('id="staging-wheel-form"', $html);
        self::assertStringContainsString('not been reinterpreted', $html);
        foreach ([102,104,105] as $id) {
            self::assertFalse($service->context(1, $id)['can_record']);
            $this->post('RF', 'new', $f->data('RF'), $id);
            self::assertSame($before, $f->schema->rows());
        }
        self::assertNull($service->context(1, 200));
        try {
            $this->controller()->entry(200, 'RF', 'new');
            self::fail('Foreign checklist must not authorize reads.');
        } catch (PageNotFoundException) {
            self::assertSame($before, $f->schema->rows());
        }
        $root = $db->table('vehicle_damage_items')->where('panel_code', 'front_left_wheel_tire')->where('company_id', 1)->get(1)->getRowArray();
        $alias = $root;
        unset($alias['id']);
        $alias['company_id'] = 2;
        $alias['fleet_vehicle_id'] = 20;
        $alias['current_condition_item_id'] = $root['id'];
        $db->table('vehicle_damage_items')->insert($alias);
        $foreignAlias = (int)$db->insertID();
        $conflictBefore = $f->schema->rows();
        $entry = $this->controller()->entry(100, 'LF', 'worsening');
        self::assertStringNotContainsString('id="staging-wheel-form"', $entry);
        self::assertStringNotContainsString('<h2>Current condition</h2>', $entry);
        $this->post('LF', 'worsening', $f->data('LF'));
        self::assertSame($conflictBefore, $f->schema->rows());
        $db->table('vehicle_damage_items')->where('id', $foreignAlias)->delete();
        $db->table('trip_movement_checklists')->where('id', 100)->update(['completed_at' => date('Y-m-d H:i:s')]);
        self::assertFalse($service->context(1, 100)['can_record']);
        $db->table('trip_movement_checklists')->where('id', 100)->update(['completed_at' => null]);
        $lookup = new \App\Repositories\LookupRepository($db);
        $db->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => $lookup->valueId('trip_status', 'canceled_zero_payout')]);
        self::assertFalse($service->context(1, 100)['can_record']);
        $db->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => $lookup->valueId('trip_status', 'booked')]);
        $db->table('trip_movement_events')->insert(['company_id' => 1,'fleet_vehicle_id' => 10,'turo_trip_normalized_id' => 100,'event_code' => 'actual_handoff','occurred_at' => date('Y-m-d H:i:s', time() - 3600),'source' => 'operator','actor_user_id' => 7]);
        self::assertFalse($service->context(1, 100)['can_record']);
        $before = $f->schema->rows();
        $this->post('RF', 'new', $f->data('RF'));
        self::assertSame($before, $f->schema->rows());
        self::assertStringNotContainsString('Optional wheel actions', $this->checklist()->show(100));
        self::assertStringContainsString('Return context', $this->checklist()->show(105));
        $late = $f->service->confirmUnchanged(1, 10, $f->data('LF', ['trip_id' => 100,'observed_at' => date('Y-m-d H:i:s'),'severity_code' => null]), 7);
        self::assertTrue($late['success']);
        $later = $f->service->recordWorsening(1, 10, $f->data('LF', ['trip_id' => 100,'discovered_at' => date('Y-m-d H:i:s'),'baseline_event_id' => $late['condition_event_id'],'baseline_membership_id' => $late['membership_id']]), 7);
        self::assertTrue($later['success']);
        self::assertNotSame('Observed before this guest', Services::vehicleWheelConditionReadService()->occurrence(1, 10, $later['condition_id'], $later['membership_id'])['comparison']['heading']);
    }

    public function testPendingReceiptRecoveryStaysOnOriginalChecklistWheelAndCommand(): void
    {
        $f = $this->fixture('sqlite');
        $uncertain = new class ($f->schema->db) extends VehicleWheelDamageService {
            protected function commit(): void
            {
                parent::commit();
                throw new \RuntimeException('Synthetic lost acknowledgement');
            }
        };
        Services::injectMock('vehicleWheelDamageService', $uncertain);
        $this->post('LF', 'new', $f->data('LF'));
        $before = $f->schema->rows();
        self::assertStringContainsString('Recover pending save', $this->controller()->entry(100, 'LF', 'new'));
        $this->post('RF', 'new', ['recover' => '1']);
        self::assertSame($before, $f->schema->rows());
        $this->post('LF', 'worsening', ['recover' => '1']);
        self::assertSame($before, $f->schema->rows());
        Services::injectMock('vehicleWheelDamageService', $f->service);
        $response = $this->post('LF', 'new', ['recover' => '1']);
        self::assertSame($before, $f->schema->rows());
        self::assertStringEndsWith('/operations/checklists/100#known-vehicle-damage', $response->getHeaderLine('Location'));
        self::assertNull(CoreServices::session()->get('wheel_pending_1_10_7'));
    }

    public function testChecklistRoutesKeepSessionPermissionAndCsrf(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        foreach (['GET','POST'] as $verb) {
            $found = 0;
            foreach ($routes->getRoutes($verb) as $path => $handler) {
                if (str_contains($path, '/wheel-curb-rash/')) {
                    $found++;
                    $filters = $routes->getFiltersForRoute($path, $verb);
                    self::assertContains('session', $filters);
                    self::assertContains('permission:admin.access', $filters);
                    self::assertStringContainsString('VehicleWheelChecklist::', $handler);
                }
            } self::assertSame(1, $found);
        }
        self::assertContains('csrf', (new \Config\Filters())->globals['before']);
    }
}
