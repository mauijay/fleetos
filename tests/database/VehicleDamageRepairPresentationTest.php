<?php

use App\Repositories\VehicleDamageRepairRepository;
use App\Services\Fleet\VehicleDamageRepairService;
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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture as Fixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairPresentationTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use FeatureTestTrait;

    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->connection = Database::connect('tests');
        Fixture::prepare($this->connection);
        $this->connection->table('fleet_vehicles')->where('id', 20)->update(['out_of_service_date' => '1900-01-01']);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testOwnedGetScreensArePureAndExposeOnlyLegalActions(): void
    {
        $id = Fixture::condition($this->connection);
        $result = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [$id]), 7);
        $this->assertTrue($result['success']);
        $job = (int) $result['id'];
        $this->authenticate();
        $before = $this->rows();
        foreach (['', '/new', '/' . $job, '/condition-preview?selected_item_id=' . $id] as $suffix) {
            $response = $this->get('/fleet/vehicles/10/damage-repairs' . $suffix);
            $response->assertOK();
            $this->assertStringContainsString('Work', $response->getBody());
        }
        $response = $this->get('/fleet/vehicles/10/damage-repairs/' . $job);
        $this->assertStringContainsString('Start work', $response->getBody());
        $this->assertStringNotContainsString('Complete job', $response->getBody());
        $this->assertStringContainsString('Repair reported means inspection pending', $response->getBody());
        foreach (['/fleet/vehicles/11/damage-repairs/' . $job, '/fleet/vehicles/20/damage-repairs/' . $job, '/fleet/vehicles/10/damage-repairs/' . $job . '/conditions/999/confirm-repair'] as $url) {
            try {
                $this->get($url);
                $this->fail('An unowned resource must fail closed.');
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->rows());
    }

    public function testAllWorkRoutesRequireSessionPermissionAndPostCsrf(): void
    {
        $this->authenticate();
        $routes = CoreServices::routes();
        $filters = CoreServices::filters();
        $seen = 0;
        foreach (['GET', 'POST'] as $method) {
            foreach ($routes->getRoutes($method) as $path => $handler) {
                if (! str_contains((string) $handler, 'VehicleDamageRepairs')) {
                    continue;
                }
                $uri = preg_replace('/\([^)]*\)/', '10', $path);
                $required = (new \CodeIgniter\Commands\Utilities\Routes\FilterCollector())->get($method, $uri)['before'];
                $flatten = json_encode($required);
                $this->assertStringContainsString('session', $flatten);
                $this->assertStringContainsString('permission', $flatten);
                if ($method === 'POST') {
                    $this->assertStringContainsString('csrf', $flatten);
                }
                $seen++;
            }
        }
        $this->assertSame(20, $seen);
        ShieldServices::auth()->logout();
        $this->get('/fleet/vehicles/10/damage-repairs')->assertRedirectTo('login');
    }

    public function testChecklistBadgeUsesSharedWorkspaceAndContainsNoWorkForms(): void
    {
        $id = Fixture::condition($this->connection);
        $result = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [$id]), 7);
        $this->assertTrue($result['success']);
        $workspace = Services::vehicleDamageReadService()->workspace(1, 10);
        $html = CoreServices::renderer()->setData(['work' => $workspace['work'], 'conditionId' => $id, 'vehicleId' => 10])->render('vehicle_damage_repairs/_badge');
        $this->assertStringContainsString('1 active', $html);
        $this->assertStringContainsString('/damage-repairs/' . $result['id'], $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertSame([], (new VehicleDamageRepairRepository($this->connection))->workspace(2, 10)['jobs']);
    }

    public function testAuthenticatedOperatorWithoutAdminPermissionCannotReadOrWriteWork(): void
    {
        $this->authenticate(false);
        $before = $this->rows();
        $this->get('/fleet/vehicles/10/damage-repairs')->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $security = CoreServices::security();
        $this->post('/fleet/vehicles/10/damage-repairs', [$security->getTokenName() => $security->getHash()])->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $this->assertSame($before, $this->rows());
    }

    public function testMissingCsrfRejectsCreationBeforeControllerAndLeavesHistoryEmpty(): void
    {
        $this->authenticate();
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('/fleet/vehicles/10/damage-repairs', ['summary' => 'Synthetic missing token']);
    }

    private function authenticate(bool $permission = true): void
    {
        $runner = new MigrationRunner(new Migrations(), $this->connection);
        $runner->setNamespace('CodeIgniter\\Shield')->latest();
        $runner->setNamespace('CodeIgniter\\Settings')->latest();
        $users = new UserModel();
        $user = new User(['username' => 'synthetic-work-operator', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        if ($permission) {
            $user->addPermission('admin.access');
        }
        $this->actingAs($user);
        CoreServices::routes()->resetRoutes();
        CoreServices::routes()->loadRoutes();
        $this->withRoutes();
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'audit_logs'] as $table) {
            $rows[$table] = $this->connection->table($table)->get()->getResultArray();
        } return $rows;
    }
}
