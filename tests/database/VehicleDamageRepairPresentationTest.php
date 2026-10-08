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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(51, $seen);
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

    public function testB31OwnedScreensPrivateEvidenceAndFinalizationPresentationArePure(): void
    {
        $work = new VehicleDamageRepairService($this->connection);
        $j = $work->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7)['id'];
        $this->authenticate();
        $base = '/fleet/vehicles/10/damage-repairs/' . $j;
        $before = $this->rows();
        foreach ([$base, $base . '/recoveries/new'] as $url) {
            $response = $this->get($url);
            $response->assertOK();
            $this->assertStringContainsString('Turo', $response->getBody());
        }
        $this->assertStringContainsString('No recovery recorded', $this->get($base)->getBody());
        $this->assertSame($before, $this->rows());
        $tmp = tempnam(sys_get_temp_dir(), 'b31_synthetic_web_');
        file_put_contents($tmp, "%PDF-1.4\n% Synthetic recovery web evidence " . bin2hex(random_bytes(8)) . "\n%%EOF\n");
        $upload = new \CodeIgniter\HTTP\Files\UploadedFile($tmp, 'synthetic-web-recovery.pdf', 'application/pdf', filesize($tmp), UPLOAD_ERR_OK);
        $result = $work->recordRecovery(1, 10, $j, \Tests\Support\VehicleDamageRepairRecoveryTestCase::facts(['source_reference' => str_repeat('SYNTHETIC-', 12), 'source_details' => 'Synthetic source <script>unsafe()</script>', 'note' => str_repeat('Synthetic note ', 100)]) + ['expected_version' => 1, 'command_key' => VehicleDamageRepairService::commandKey(), 'document' => ['kind_code' => 'recovery_payment', 'upload' => $upload]], 7);
        $this->assertTrue($result['success'], json_encode($result));
        $helper = new \App\Services\Fleet\VehicleDamageRepairRecoveryService($this->connection);
        $doc = $helper->verifyDocument(1, 10, $j, $result['document_id'], 'recovery');
        $binary = $helper->sources->documents->storage->resolve(1, $doc, $helper->sources->documents->documents->metadata($doc));
        try {
            $before = $this->rows();
            foreach ([$base, $base . '/recoveries/new', $base . '/recoveries/' . $result['recovery_entry_id'] . '/replacement'] as $url) {
                $response = $this->get($url);
                $response->assertOK();
                $this->assertStringNotContainsString('<script>unsafe()', $response->getBody());
            }
            $body = $this->get($base)->getBody();
            $this->assertStringContainsString('Host-borne repair cost', $body);
            $this->assertStringContainsString('Unknown', $body);
            $this->assertStringContainsString('Private recovery evidence', $body);
            $this->assertStringContainsString('Retained permanently', $body);
            $this->assertStringNotContainsString('Archive document</summary>', $body);
            $controller = new \App\Controllers\VehicleDamageRepairs();
            $controller->initController(CoreServices::request(), CoreServices::response(), CoreServices::logger());
            $response = $controller->downloadDocument(10, $j, $result['document_id']);
            $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $this->assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
            foreach ([[11, $j, $result['document_id']], [10, 999, $result['document_id']], [10, $j, 999]] as [$v, $job, $id]) {
                try {
                    $controller->downloadDocument($v, $job, $id);
                    $this->fail('Private recovery evidence requires owned job context.');
                } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($before, $this->rows());
            $final = $work->finalizeRecovery(1, 10, $j, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 2, 'confirmed' => '1', 'completeness_confirmed' => '1', 'expected_ledger_state' => $helper->ledgerFingerprint(1, 10, $j), 'note' => 'Synthetic finalized recovery before repair'], 7);
            $this->assertTrue($final['success'], json_encode($final));
            $this->assertStringContainsString('Finalized', $this->get($base)->getBody());
            $this->assertStringNotContainsString('Final host-borne repair balance:', $this->get($base)->getBody());
            $void = $work->voidRecovery(1, 10, $j, ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => 3, 'confirmed' => '1', 'reason' => 'Synthetic erroneous receipt correction', 'recovery_entry_id' => $result['recovery_entry_id'], 'expected_entry_state' => $helper->entryFingerprint(1, 10, $j, $result['recovery_entry_id'])], 7);
            $this->assertTrue($void['success'], json_encode($void));
            $before = $this->rows();
            $body = $this->get($base)->getBody();
            $this->assertStringContainsString('No recovery recorded', $body);
            $this->assertStringNotContainsString('Source review required', $body);
            $this->assertStringContainsString('Retained permanently', $body);
            $this->assertSame($before, $this->rows());
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            if ($binary !== null && is_file($binary['path'])) {
                unlink($binary['path']);
            }
        }
    }

    public function testB31NonAdminCannotReadOrWriteAndMissingCsrfFailsBeforeController(): void
    {
        $this->authenticate(false);
        $base = '/fleet/vehicles/10/damage-repairs/999/recoveries';
        $before = $this->rows();
        $this->get($base . '/new')->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $security = CoreServices::security();
        $this->post($base, [$security->getTokenName() => $security->getHash()])->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $this->assertSame($before, $this->rows());
    }

    public function testB31MissingCsrfRejectsBeforeAnyRecoveryWrite(): void
    {
        $this->authenticate();
        $before = $this->rows();
        try {
            $this->post('/fleet/vehicles/10/damage-repairs/999/recoveries', ['confirmed' => '1']);
            $this->fail('CSRF must reject recovery POST.');
        } catch (\CodeIgniter\Security\Exceptions\SecurityException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public static function malformedRecoveryReviews(): array
    {
        return [
            'scalar document' => [['document' => 'malformed']],
            'array payer' => [['payer_snapshot' => ['malformed']]],
            'array kind' => [['kind_code' => ['malformed']]],
            'array version' => [['expected_version' => ['malformed']]],
            'array command key' => [['command_key' => ['malformed'], 'document' => 'malformed']],
            'array document label' => [['document' => ['label' => ['malformed']]]],
            'scalar descriptor' => [['document' => ['descriptor' => 'malformed']]],
            'nested descriptor' => [['document' => ['descriptor' => ['checksum' => ['malformed']]]]],
        ];
    }

    #[DataProvider('malformedRecoveryReviews')]
    public function testMalformedRecoveryReviewRendersValidationWithoutWriting(array $invalid): void
    {
        $created = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7);
        $this->assertTrue($created['success']);
        $this->authenticate();
        $data = array_replace(\Tests\Support\VehicleDamageRepairRecoveryTestCase::facts() + ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => '1'], $invalid);
        $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)->disableOriginalConstructor()->onlyMethods(['getPost', 'getFile'])->getMock();
        $request->expects($this->once())->method('getPost')->willReturn($data);
        $request->expects($this->once())->method('getFile')->with('repair_document')->willReturn(null);
        $controller = new \App\Controllers\VehicleDamageRepairs();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        $before = $this->rows();
        $body = $controller->reviewRecovery(10, $created['id']);
        $this->assertStringContainsString('Record documented recovery', $body);
        $this->assertStringContainsString('role="alert"', $body);
        $this->assertStringNotContainsString('value="Array"', $body);
        $this->assertSame($before, $this->rows());
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

    public function testB23CostScreensArePureAndStartWithUnknownAndBlankSourceFacts(): void
    {
        $created = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7);
        $this->assertTrue($created['success']);
        $this->authenticate();
        $base = '/fleet/vehicles/10/damage-repairs/' . $created['id'];
        $before = $this->rows();
        $body = $this->get($base)->getBody();
        $this->assertStringContainsString('Repair cost: Unknown', $body);
        $this->assertStringContainsString('No vendor payments recorded', $body);
        $form = $this->get($base . '/costs/new');
        $form->assertOK();
        $this->assertMatchesRegularExpression('/name="amount"[^>]*value=""/', $form->getBody());
        $this->assertMatchesRegularExpression('/name="occurred_on"[^>]*value=""/', $form->getBody());
        $this->assertStringContainsString('Review duplicate candidates', $form->getBody());
        $this->assertSame($before, $this->rows());
        foreach (['/fleet/vehicles/11/damage-repairs/' . $created['id'] . '/costs/new', $base . '/costs/999/replacement'] as $path) {
            try {
                $this->get($path);
                $this->fail('Wrong owned cost context must fail closed.');
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->rows());
    }

    public function testB23PostRequiresCsrfBeforeAnyMonetaryWrite(): void
    {
        $created = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7);
        $this->authenticate();
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('/fleet/vehicles/10/damage-repairs/' . $created['id'] . '/costs', ['kind_code' => 'invoice']);
    }

    public function testUnresolvedCostAcknowledgementRetainsOriginalPayloadAcrossReloads(): void
    {
        $created = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7);
        $this->assertTrue($created['success']);
        $job = $created['id'];
        $this->authenticate();
        $original = ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => '1', 'kind_code' => 'invoice', 'amount' => '25.00', 'currency' => 'USD', 'occurred_on' => '2026-10-06', 'vendor_snapshot' => 'Synthetic unresolved vendor', 'confirmed' => '1', 'performed_work_confirmed' => '1', 'document' => ['kind_code' => 'invoice', 'descriptor' => ['checksum' => str_repeat('a', 64), 'mime_type' => 'application/pdf', 'size_bytes' => '48', 'original_filename' => 'synthetic-unresolved.pdf']]];
        $posted = $original;
        $calls = 0;
        $service = $this->getMockBuilder(VehicleDamageRepairService::class)->disableOriginalConstructor()->onlyMethods(['recordCostEntry'])->getMock();
        $service->expects($this->exactly(4))->method('recordCostEntry')->willReturnCallback(function ($c, $v, $j, $data, $actor) use (&$calls, $original, $job): array {
            $this->assertSame([1, 10, $job], [$c, $v, $j]);
            $this->assertGreaterThan(0, $actor);
            $this->assertSame($original, $data, 'Even a later edited POST must recover the frozen original command.');
            $calls++;
            return match ($calls) {
                1 => ['success' => false, 'uncertain' => true, 'retry_payload' => $original, 'errors' => ['work' => 'Synthetic acknowledgement unavailable']],
                4 => ['success' => true, 'replayed' => true, 'id' => $job, 'errors' => []],
                default => ['success' => false, 'errors' => ['work' => 'Synthetic recovery unavailable']],
            };
        });
        Services::injectMock('vehicleDamageRepairService', $service);
        $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)->disableOriginalConstructor()->onlyMethods(['getPost', 'getFile'])->getMock();
        $request->expects($this->exactly(3))->method('getPost')->willReturnCallback(static function () use (&$posted): array {
            return $posted;
        });
        $request->expects($this->exactly(3))->method('getFile')->with('repair_document')->willReturn(null);
        $controller = new \App\Controllers\VehicleDamageRepairs();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        $before = $this->rows();
        $controller->recordCost(10, $job);
        foreach ([1, 2] as $reload) {
            $html = $controller->show(10, $job);
            $this->assertStringContainsString('Retry original cost command', $html);
            $this->assertStringNotContainsString('Record invoice / credit / payment / refund', $html);
            $this->assertSame($before, $this->rows());
        }
        $posted = ['command_key' => VehicleDamageRepairService::commandKey(), 'amount' => '999.00'];
        $controller->recordCost(10, $job);
        $this->assertStringContainsString('Retry original cost command', $controller->show(10, $job));
        $controller->recordCost(10, $job);
        $this->assertStringNotContainsString('Retry original cost command', $controller->show(10, $job));
        $this->assertSame($before, $this->rows());
    }

    public function testUnresolvedRecoveryAcknowledgementRetainsOriginalPayloadAcrossEditedReloads(): void
    {
        $created = (new VehicleDamageRepairService($this->connection))->createJob(1, 10, Fixture::creation($this->connection, [Fixture::condition($this->connection)]), 7);
        $this->assertTrue($created['success']);
        $job = $created['id'];
        $this->authenticate();
        $original = \Tests\Support\VehicleDamageRepairRecoveryTestCase::facts() + ['command_key' => VehicleDamageRepairService::commandKey(), 'expected_version' => '1', 'document' => ['kind_code' => 'recovery_payment', 'descriptor' => ['checksum' => str_repeat('a', 64), 'mime_type' => 'application/pdf', 'size_bytes' => '48', 'original_filename' => 'synthetic-unresolved-recovery.pdf']]];
        $posted = $original;
        $calls = 0;
        $service = $this->getMockBuilder(VehicleDamageRepairService::class)->disableOriginalConstructor()->onlyMethods(['recordRecovery'])->getMock();
        $service->expects($this->exactly(4))->method('recordRecovery')->willReturnCallback(function ($c, $v, $j, $data, $actor) use (&$calls, $original, $job): array {
            $this->assertSame([1, 10, $job], [$c, $v, $j]);
            $this->assertGreaterThan(0, $actor);
            $this->assertSame($original, $data, 'Even a later edited POST must recover the frozen original command.');
            $calls++;
            return match ($calls) {
                1 => ['success' => false, 'uncertain' => true, 'retry_payload' => $original, 'errors' => ['work' => 'Synthetic acknowledgement unavailable']],
                4 => ['success' => true, 'replayed' => true, 'id' => $job, 'errors' => []],
                default => ['success' => false, 'errors' => ['work' => 'Synthetic recovery unavailable']],
            };
        });
        Services::injectMock('vehicleDamageRepairService', $service);
        $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)->disableOriginalConstructor()->onlyMethods(['getPost', 'getFile'])->getMock();
        $request->expects($this->exactly(3))->method('getPost')->willReturnCallback(static function () use (&$posted): array {
            return $posted;
        });
        $request->expects($this->exactly(3))->method('getFile')->with('repair_document')->willReturn(null);
        $controller = new \App\Controllers\VehicleDamageRepairs();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        $before = $this->rows();
        $controller->recordRecovery(10, $job);
        foreach ([1, 2] as $reload) {
            $html = $controller->show(10, $job);
            $this->assertStringContainsString('Retry original recovery command', $html);
            $this->assertStringNotContainsString('Record recovery / reversal', $html);
            $this->assertSame($before, $this->rows());
        }
        $posted = ['command_key' => VehicleDamageRepairService::commandKey(), 'amount' => '999.00'];
        $controller->recordRecovery(10, $job);
        $this->assertStringContainsString('Retry original recovery command', $controller->show(10, $job));
        $controller->recordRecovery(10, $job);
        $this->assertStringNotContainsString('Retry original recovery command', $controller->show(10, $job));
        $this->assertSame($before, $this->rows());
    }

    public function testB32OwnedPreviewIsPureAndRejectsRawUnownedIds(): void
    {
        $paths = [];
        try {
            $pair = \Tests\Support\VehicleDamageFinancialReconciliationFixture::pair($this->connection, $paths);
            $this->authenticate();
            $base = '/fleet/vehicles/10/damage-repairs/' . $pair['job'];
            $preview = $base . '/financial-reconciliations/preview?cost_root_entry_id=' . $pair['root'] . '&operating_expense_id=' . $pair['expense'];
            $before = $this->rows();
            foreach ([$base, $preview] as $url) {
                $response = $this->get($url);
                $response->assertOK();
                $this->assertStringContainsString('reconciliation', strtolower($response->getBody()));
            }
            foreach ([$preview . '&reconciliation_id=999999', $base . '/financial-reconciliations/preview?cost_root_entry_id=999999&operating_expense_id=' . $pair['expense'], $base . '/financial-reconciliations/preview?cost_root_entry_id=' . $pair['root'] . '&operating_expense_id=999999'] as $url) {
                try {
                    $this->get($url);
                    $this->fail('Unowned source preview must fail closed.');
                } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($before, $this->rows());
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testB32NonAdminAndMissingCsrfFailBeforeSourcesAreRead(): void
    {
        $this->authenticate(false);
        $base = '/fleet/vehicles/10/damage-repairs/999/financial-reconciliations';
        $before = $this->rows();
        $this->get($base . '/preview')->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        $security = CoreServices::security();
        foreach (['', '/invalidate', '/replace'] as $suffix) {
            $this->post($base . $suffix, [$security->getTokenName() => $security->getHash()])->assertRedirectTo((new \Config\Auth())->permissionDeniedRedirect());
        }
        $this->assertSame($before, $this->rows());
    }

    public function testB32MissingCsrfRejectsEveryMaterialCommand(): void
    {
        $this->authenticate();
        $before = $this->rows();
        foreach (['', '/invalidate', '/replace'] as $suffix) {
            try {
                $this->post('/fleet/vehicles/10/damage-repairs/999/financial-reconciliations' . $suffix, ['confirmed' => '1']);
                $this->fail('CSRF must reject financial reconciliation POST.');
            } catch (\CodeIgniter\Security\Exceptions\SecurityException) {
                $this->assertSame($before, $this->rows());
            }
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['vehicle_damage_financial_reconciliations', 'operating_expenses', 'operating_expense_receipts', 'vehicle_damage_items', 'vehicle_damage_item_events', 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_items', 'vehicle_damage_repair_job_events', 'vehicle_damage_repair_cost_entries', 'vehicle_damage_repair_recovery_entries', 'vehicle_damage_repair_estimates', 'vehicle_damage_repair_estimate_items', 'vehicle_damage_repair_documents', 'files', 'audit_logs'] as $table) {
            $rows[$table] = $this->connection->table($table)->get()->getResultArray();
        } return $rows;
    }
}
