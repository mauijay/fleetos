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
        $this->assertSame(31, $seen);
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

    public function testB22EmptyHistoricalCurrentAndPrivateDownloadPresentation(): void
    {
        $work = new VehicleDamageRepairService($this->connection);
        $created = $work->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection), Fixture::condition($this->connection)]), 7);
        $this->assertTrue($created['success']);
        $job = (int) $created['id'];
        $this->authenticate();
        $base = '/fleet/vehicles/10/damage-repairs/' . $job;
        $this->assertStringContainsString('No repair estimate recorded.', $this->get($base)->getBody());
        $repository = new VehicleDamageRepairRepository($this->connection);
        $common = ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 1, 'quote_series_key' => VehicleDamageRepairService::commandKey(), 'amount' => '0', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1', 'scope_membership_ids' => array_column($repository->members(1, 10, $job), 'id')];
        $historical = $work->createEstimate(1, 10, $job, $common + ['recording_mode' => 'historical_incomplete', 'historical_recording_reason' => 'Synthetic source details incomplete'], 7);
        $this->assertTrue($historical['success'], json_encode($historical));
        $body = $this->get($base)->getBody();
        $this->assertStringContainsString('Historical estimate — source details incomplete', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString('/estimates/' . $historical['estimate_id'] . '/accept', $body);
        $temp = tempnam(sys_get_temp_dir(), 'b22_web_');
        file_put_contents($temp, "%PDF-1.4\n% Synthetic authorized source " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
        $upload = new \CodeIgniter\HTTP\Files\UploadedFile($temp, 'synthetic-source.pdf', 'application/pdf', filesize($temp), UPLOAD_ERR_OK);
        $current = $work->createEstimate(1, 10, $job, array_replace($common, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 2, 'quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => 'current_quote', 'vendor_snapshot' => 'Synthetic web vendor', 'quote_date' => '2026-10-06', 'vendor_confirmed' => '1', 'date_confirmed' => '1', 'scope_confirmed' => '1', 'document' => ['kind_code' => 'estimate', 'upload' => $upload]]), 7);
        $this->assertTrue($current['success'], json_encode($current));
        $documents = new \App\Repositories\VehicleDamageRepairDocumentRepository($this->connection);
        $doc = $documents->document(1, 10, $job, $current['document_id']);
        $binary = (new \App\Services\Files\RepairDocumentStorageService($this->connection))->resolve(1, $doc, $documents->metadata($doc));
        try {
            $before = $this->rows();
            foreach ([$base, $base . '/estimates/new', $base . '/estimates/' . $current['estimate_id'] . '/revision', $base . '/documents/new'] as $url) {
                $this->get($url)->assertOK();
            }
            $body = $this->get($base)->getBody();
            foreach (['USD 0.00', 'Synthetic web vendor', 'Frozen scope', 'Download private document'] as $label) {
                $this->assertStringContainsString($label, $body);
            }
            foreach (['Actual cost', 'Host loss', 'Paid'] as $label) {
                $this->assertStringNotContainsString($label, $body);
            }
            $controller = new \App\Controllers\VehicleDamageRepairs();
            $controller->initController(CoreServices::request(), CoreServices::response(), CoreServices::logger());
            $response = $controller->downloadDocument(10, $job, $current['document_id']);
            $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertStringStartsWith('attachment; filename="synthetic-source.pdf"', $response->getHeaderLine('Content-Disposition'));
            foreach ([[11, $job, $current['document_id']], [10, 999, $current['document_id']], [10, $job, 999]] as [$v, $j, $d]) {
                try {
                    $controller->downloadDocument($v, $j, $d);
                    $this->fail('Foreign context must be not found.');
                } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($before, $this->rows());
            $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)->disableOriginalConstructor()->onlyMethods(['getPost', 'getFile'])->getMock();
            $request->expects($this->atLeastOnce())->method('getPost')->willReturn(array_replace($common, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 3, 'recording_mode' => 'historical_incomplete', 'historical_recording_reason' => 'Synthetic malformed multipart input', 'document' => 'malformed']));
            $request->expects($this->once())->method('getFile')->with('repair_document')->willReturn($upload);
            $controller->initController($request, CoreServices::response(), CoreServices::logger());
            $rejected = $controller->createEstimate(10, $job);
            $this->assertStringEndsWith($base, $rejected->getHeaderLine('Location'));
            $this->assertSame(['work' => 'Choose a valid document source.'], CoreServices::session()->getFlashdata('damage_work_errors'));
            $this->assertSame($before, $this->rows());
        } finally {
            if ($binary !== null && is_file($binary['path'])) {
                unlink($binary['path']);
            }
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'audit_logs'] as $table) {
            $rows[$table] = $this->connection->table($table)->get()->getResultArray();
        } return $rows;
    }
}
