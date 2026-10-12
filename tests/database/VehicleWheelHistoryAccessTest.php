<?php

namespace Tests\Database;

use App\Controllers\VehicleWheelHistory;
use App\Services\Fleet\VehicleDamageOccurrenceEvidenceService;
use App\Services\Fleet\VehicleWheelConditionReadService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelHistoryAccessTest extends CIUnitTestCase
{
    public function testAuthenticatedReadPagesAndPrivateLegacyViewerArePure(): void
    {
        $f = new VehicleWheelDamageFixture();
        try {
            $db = $f->schema->db;
            $receipt = $f->service->createNew(1, 10, $f->data('LF', ['photos' => [['role' => 'full_wheel','upload' => $f->upload()]]]), 7);
            self::assertTrue($receipt['success']);
            $legacy = $db->table('vehicle_damage_item_evidence')->where('id', $receipt['evidence_ids'][0])->get()->getRowArray();
            unset($legacy['id']);
            $legacy['vehicle_damage_incident_item_id'] = null;
            $db->table('vehicle_damage_item_evidence')->insert($legacy);
            $legacyId = (int)$db->insertID();
            Services::injectMock('vehicleWheelConditionReadService', new VehicleWheelConditionReadService($db));
            Services::injectMock('vehicleDamageOccurrenceEvidenceService', new VehicleDamageOccurrenceEvidenceService($db));
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
            });
            $request = CoreServices::request();
            $request->setGlobal('get', []);
            $controller = new VehicleWheelHistory();
            $controller->initController($request, CoreServices::response(), CoreServices::logger());
            $before = $f->schema->rows();
            foreach ([$controller->ledger(10),$controller->history(10, 'LF'),$controller->occurrence(10, $receipt['condition_id'], $receipt['membership_id'])] as $html) {
                self::assertStringContainsString("Clock positions are referenced with the valve stem at 12 o'clock.", $html);
                self::assertStringNotContainsString('damage-evidence/company-', $html);
                self::assertStringNotContainsString('file_path', $html);
            }
            $html = $controller->ledger(10);
            foreach (['LF','RF','RR','LR'] as $wheel) {
                self::assertStringContainsString($wheel, $html);
            }self::assertStringContainsString('No active rash recorded', $html);
            self::assertStringNotContainsString('No damage', $html);
            self::assertStringContainsString('Episode evidence — occurrence unknown', $controller->history(10, 'LF'));
            self::assertStringContainsString('No documented pre-trip baseline', $controller->occurrence(10, $receipt['condition_id'], $receipt['membership_id']));
            // Evidence cursors are IDs, not page numbers: large valid IDs remain navigable.
            foreach (['after','baseline_after'] as $cursor) {
                $request->setGlobal('get', [$cursor => '100001']);
                self::assertStringContainsString('Wheel occurrence history', $controller->occurrence(10, $receipt['condition_id'], $receipt['membership_id']));
            }
            foreach (['-1','01',str_repeat('9', 30),[]] as $invalid) {
                $request->setGlobal('get', ['after' => $invalid]);
                try {
                    $controller->occurrence(10, $receipt['condition_id'], $receipt['membership_id']);
                    self::fail('Reject malformed cursors.');
                } catch (PageNotFoundException) {
                    self::assertSame($before, $f->schema->rows());
                }
            }
            $request->setGlobal('get', []);
            $response = $controller->episodeEvidence(10, $receipt['condition_id'], $legacyId);
            self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
            self::assertSame($before, $f->schema->rows());
            foreach ([[11,$receipt['condition_id'],$legacyId],[20,$receipt['condition_id'],$legacyId],[10,999,$legacyId],[10,$receipt['condition_id'],$receipt['evidence_ids'][0]]] as $scope) {
                try {
                    $controller->episodeEvidence(...$scope);
                    self::fail('Child IDs cannot authorize evidence.');
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
            $controller->ledger(10);
        } finally {
            $f->close();
        }
    }

    public function testReadRoutesRetainSessionPermissionAndExposeNoWrites(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $found = 0;
        foreach ($routes->getRoutes('GET') as $path => $handler) {
            if (str_contains($path, '/wheel-history/') || str_ends_with($path, '/damage-ledger')) {
                ++$found;
                $filters = $routes->getFiltersForRoute($path, 'GET');
                self::assertContains('session', $filters);
                self::assertContains('permission:admin.access', $filters);
                self::assertStringContainsString('VehicleWheelHistory::', $handler);
            }
        }
        self::assertSame(4, $found);
        foreach ($routes->getRoutes('POST') as $handler) {
            self::assertStringNotContainsString('VehicleWheelHistory::', $handler);
        }
    }
}
