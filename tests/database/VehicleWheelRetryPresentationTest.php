<?php

namespace Tests\Database;

use App\Controllers\VehicleWheelDamage;
use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleWheelChecklistFixture;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelRetryPresentationTest extends CIUnitTestCase
{
    private array $connections = [];

    protected function setUp(): void
    {
        Services::reset();
        parent::setUp();
        $this->connections = \Config\Database::getConnections();
    }

    protected function tearDown(): void
    {
        Services::reset();
        (new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['new', 'worsening', 'unchanged', 'worsening_repaired'] as $scenario) {
                yield $engine . '-' . $scenario => [$engine, $scenario];
            }
        }
    }

    public static function internalFailures(): iterable
    {
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['database', 'error'] as $fault) {
                yield $engine . '-' . $fault => [$engine, $fault];
            }
        }
    }

    #[DataProvider('internalFailures')]
    public function testInternalFailureDoesNotExposePrivateDetails(string $engine, string $fault): void
    {
        $f = new VehicleWheelDamageFixture($engine);
        try {
            $db = $f->schema->db;
            $before = $f->schema->rows();
            $evidence = new class ($db, $fault) extends \App\Services\Fleet\VehicleDamageOccurrenceEvidenceService {
                public function __construct(\CodeIgniter\Database\BaseConnection $db, private string $fault)
                {
                    parent::__construct($db);
                }

                public function attach(int $company, int $vehicle, int $condition, int $membership, array $photos, int $actor): array
                {
                    $privateMessage = 'Synthetic SQL failure at /private/damage-evidence/secret.jpg';
                    throw $this->fault === 'database' ? new \CodeIgniter\Database\Exceptions\DatabaseException($privateMessage) : new \Error($privateMessage);
                }
            };
            $result = (new \App\Services\Fleet\VehicleWheelDamageService($db, $evidence))->createNew(1, 10, $f->data(), 7);
            self::assertFalse($result['success']);
            self::assertStringNotContainsString('/private/', $result['errors']['wheel']);
            self::assertSame('Wheel occurrence could not be saved. Reload and retry.', $result['errors']['wheel']);
            self::assertSame($before, $f->schema->rows(), 'Internal failure rolls back staged occurrence rows and receipt.');
        } finally {
            $f->close();
        }
    }

    #[DataProvider('cases')]
    public function testRejectedCommandKeepsItsActionAndOriginalFingerprints(string $engine, string $scenario): void
    {
        $action = $scenario === 'worsening_repaired' ? 'worsening' : $scenario;
        $f = new VehicleWheelDamageFixture($engine);
        try {
            $db = $f->schema->db;
            VehicleWheelChecklistFixture::inject($db);
            foreach (['vehicleDamageRepository' => new VehicleDamageRepository($db), 'vehicleDamageIncidentRepository' => new VehicleDamageIncidentRepository($db), 'vehicleWheelDamageService' => $f->service] as $name => $instance) {
                Services::injectMock($name, $instance);
            }
            if ($action !== 'new') {
                self::assertTrue($f->service->createNew(1, 10, $f->data(), 7)['success']);
            }
            $original = $f->data('LF', ['note' => 'Synthetic original operator intent.']);
            if ($action === 'unchanged') {
                $original['observed_at'] = '2026-10-01 11:00:00';
                unset($original['severity_code']);
            }
            // Another writer changes the wheel after the form snapshot.
            $winner = match (true) {
                $scenario === 'worsening_repaired' => (new \App\Services\Fleet\VehicleDamageService($db))->transitionStatus(1, 10, $original['condition_id'], 'repaired', 'Synthetic intervening repair.', 7),
                $action === 'new' => $f->service->createNew(1, 10, $f->data(), 7),
                default => $f->service->recordWorsening(1, 10, $f->data('LF', ['note' => 'Synthetic intervening worsening.']), 7),
            };
            self::assertTrue($winner['success'], json_encode($winner));
            $before = $f->schema->rows();
            $request = CoreServices::request();
            $request->setGlobal('post', $original);
            $request->setGlobal('get', []);
            $controller = new VehicleWheelDamage();
            $controller->initController($request, CoreServices::response(), CoreServices::logger());
            $response = $controller->command(10, $action);
            self::assertStringContainsString('/wheel-damage/new?wheel_code=LF', $response->getHeaderLine('Location'));
            $html = $controller->entry(10);
            self::assertStringContainsString('Wheel occurrence was not saved', $html);
            self::assertStringContainsString('Synthetic original operator intent.', $html);
            self::assertStringContainsString('action="/fleet/vehicles/10/wheel-damage/' . $action . '"', $html);
            self::assertStringContainsString($action === 'new' ? 'Describe the new curb rash' : 'Describe the worsening delta or unchanged observation', $html);
            self::assertStringContainsString('name="command_uuid" value="' . $original['command_uuid'] . '"', $html);
            self::assertStringContainsString('name="expected_wheel_state" value="' . $original['expected_wheel_state'] . '"', $html);
            if ($action !== 'new') {
                self::assertStringContainsString('name="condition_id" value="' . $original['condition_id'] . '"', $html);
                self::assertStringContainsString('name="expected_condition_state" value="' . $original['expected_condition_state'] . '"', $html);
            }
            self::assertStringNotContainsString('formaction=', $html, 'A failed action must not become a different action on retry.');
            self::assertSame($before, $f->schema->rows(), 'Rejected command and error GET make no business writes.');
        } finally {
            $f->close();
        }
    }
}
