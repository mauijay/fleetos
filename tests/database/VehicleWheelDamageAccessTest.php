<?php

namespace Tests\Database;

use App\Controllers\VehicleWheelDamage;
use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageOccurrenceEvidenceService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelDamageAccessTest extends CIUnitTestCase
{
    public function testAuthenticatedControllerReadsRemainPrivateAndPure(): void
    {
        $f = new VehicleWheelDamageFixture();
        try {
            $db = $f->schema->db;
            $receipt = $f->service->createNew(1, 10, $f->data('LF', ['photos' => [['role' => 'full_wheel', 'upload' => $f->upload()]]]), 7);
            self::assertTrue($receipt['success'], json_encode($receipt));
            foreach (['vehicleDamageRepository' => new VehicleDamageRepository($db), 'vehicleDamageIncidentRepository' => new VehicleDamageIncidentRepository($db),
                'vehicleDamageOccurrenceRepository' => new VehicleDamageOccurrenceRepository($db), 'vehicleWheelDamageService' => $f->service,
                'vehicleDamageOccurrenceEvidenceService' => new VehicleDamageOccurrenceEvidenceService($db)] as $name => $instance) {
                Services::injectMock($name, $instance);
            }
            Services::injectMock('operationalFactsRepository', new class ($db) extends \App\Repositories\OperationalFactsRepository {
                public function activeFleetCompanyIds(?string $asOfDate = null): array
                {
                    return [1];
                }
            });
            ShieldServices::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
                public function user(): \CodeIgniter\Shield\Entities\User
                {
                    return new \CodeIgniter\Shield\Entities\User(['id' => 7]);
                }
                public function loggedIn(): bool
                {
                    return false;
                }
            });
            $controller = new VehicleWheelDamage();
            $controller->initController(CoreServices::request(), CoreServices::response(), CoreServices::logger());
            $before = $f->schema->rows();
            $entry = $controller->entry(10);
            self::assertStringContainsString("Clock positions are referenced with the valve stem at 12 o'clock.", $entry);
            self::assertStringContainsString('Record worsening', $entry);
            self::assertStringNotContainsString('New curb rash</button>', $entry);
            $occurrence = $controller->occurrence(10, $receipt['condition_id'], $receipt['membership_id']);
            self::assertStringContainsString('Exact event #' . $receipt['condition_event_id'], $occurrence);
            self::assertStringNotContainsString('damage-evidence/company-', $occurrence);
            $response = $controller->viewPhoto(10, $receipt['condition_id'], $receipt['membership_id'], $receipt['evidence_ids'][0]);
            self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
            self::assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'));
            self::assertSame($before, $f->schema->rows());
            foreach ([[11, $receipt['condition_id'], $receipt['membership_id'], $receipt['evidence_ids'][0]], [10, 999, $receipt['membership_id'], $receipt['evidence_ids'][0]], [10, $receipt['condition_id'], 999, $receipt['evidence_ids'][0]]] as $scope) {
                try {
                    $controller->viewPhoto(...$scope);
                    self::fail('Child ID must never authorize private content.');
                } catch (PageNotFoundException) {
                    self::assertSame($before, $f->schema->rows());
                }
            }
            ShieldServices::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
                public function user(): ?\CodeIgniter\Shield\Entities\User
                {
                    return null;
                }
            });
            $this->expectException(\RuntimeException::class);
            $controller->viewPhoto(10, $receipt['condition_id'], $receipt['membership_id'], $receipt['evidence_ids'][0]);
        } finally {
            $f->close();
        }
    }

    public function testWheelRoutesKeepExistingSessionPermissionAndCsrfBoundaries(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        foreach (['GET', 'POST'] as $verb) {
            foreach ($routes->getRoutes($verb) as $path => $handler) {
                if (str_contains($path, '/wheel-damage/')) {
                    $filters = $routes->getFiltersForRoute($path, $verb);
                    self::assertContains('session', $filters);
                    self::assertContains('permission:admin.access', $filters);
                    self::assertStringContainsString('VehicleWheelDamage::', $handler);
                }
            }
        }
        self::assertContains('csrf', (new \Config\Filters())->globals['before']);
        self::assertArrayHasKey('fleet/vehicles/([0-9]+)/wheel-damage/(new|worsening|unchanged)', $routes->getRoutes('POST'));
    }
}
