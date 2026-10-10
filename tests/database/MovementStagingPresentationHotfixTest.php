<?php

use App\Controllers\TripMovementChecklists;
use App\Services\Fleet\MovementStagingPresentationService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\MovementPresentationFixture as Fixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class MovementStagingPresentationHotfixTest extends CIUnitTestCase
{
    private \CodeIgniter\Database\BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->setPrefix('');
        VehicleDamageDatabaseFixture::migrate($this->connection);
        Services::injectMock('auth', new class (new \Config\Auth()) extends \CodeIgniter\Shield\Auth {
            public function setAuthenticator(?string $alias = null): self
            {
                return $this;
            }
            public function loggedIn(): bool
            {
                return false;
            }
            public function user(): \CodeIgniter\Shield\Entities\User
            {
                return new class (['id' => 7, 'username' => 'synthetic-operator']) extends \CodeIgniter\Shield\Entities\User {
                    public function getEmail(): string
                    {
                        return 'synthetic-operator@example.test';
                    }
                };
            }
        });
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->connection->close();
        parent::tearDown();
    }

    public function testHistoricalStageExplainsRecoveryAndLaterReservationWithoutChangingAuthorityOrRows(): void
    {
        Fixture::seed($this->connection);
        $before = Fixture::businessRows($this->connection);
        $asOf = new DateTimeImmutable();
        $readiness = Services::movementReadinessReadService()->forCompany(1, [102], $asOf)[102];
        $custody = Services::currentVehicleCustodyService()->resolve(10, $asOf);
        $position = Services::currentVehicleLocationService()->resolve(10, $asOf);
        $this->assertSame(103, $custody['basis_trip_id']);
        $this->assertSame('vehicle_staged', $position['event_code']);
        $this->assertTrue(Services::currentVehicleCustodyService()->hasLaterTripLifecycle(10, 102, $asOf));
        $html = $this->controller()->show(102);
        $this->assertStringContainsString('A later vehicle recovery occurred', $html);
        $this->assertStringContainsString('4:42 PM Honolulu', $html);
        $this->assertStringContainsString('2:47 PM Honolulu', $html);
        $this->assertStringContainsString('4:58 PM Honolulu', $html);
        $this->assertStringContainsString('current vehicle lifecycle belongs to a later reservation', $html);
        $this->assertStringContainsString('Historical staging and current vehicle position alone do not satisfy it', $html);
        $this->assertStringContainsString('Guest pickup confirmation is unavailable', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/stage-at-hnl"', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        $this->assertStringNotContainsString('>Record missing handoff</a>', $html);
        $this->assertStringNotContainsString('Use Record missing handoff above', $html);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertCount(0, $xpath->query('//*[@id="checklist-action-airport_staging"]//a | //*[@id="checklist-action-airport_staging"]//button'));
        $this->assertSame('-1', $xpath->query('//*[@id="historical-staging-explanation"]/@tabindex')->item(0)->nodeValue);
        $this->assertSame(Fixture::businessRows($this->connection), $before);
        $this->assertSame($readiness, Services::movementReadinessReadService()->forCompany(1, [102], $asOf)[102]);
        $this->assertSame($custody, Services::currentVehicleCustodyService()->resolve(10, $asOf));
        $this->assertSame($position, Services::currentVehicleLocationService()->resolve(10, $asOf));
        foreach (['location_confirmed', 'airport_staging'] as $code) {
            $this->assertSame('unsatisfied', array_column($readiness['requirements'], null, 'code')[$code]['status']);
        }
        $this->assertStringContainsString('A later vehicle recovery occurred', $this->controller()->show(102));
        $this->assertSame($before, Fixture::businessRows($this->connection));
        try {
            Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), \Tests\Support\HnlStagingChecklistFixture::stagingData(), 7);
            $this->fail('The same backend later-trip guard must reject staging.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('current pickup reservation', $e->getMessage());
        }
        $this->assertSame($before, Fixture::businessRows($this->connection));
    }

    public function testCurrentStageRetainsGuestPickupConfirmation(): void
    {
        Fixture::seed($this->connection, 'valid');
        $before = Fixture::businessRows($this->connection);
        $html = $this->controller()->show(102);
        $this->assertStringContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        $this->assertStringNotContainsString('Prior staging is historical', $html);
        $this->assertStringNotContainsString('current vehicle lifecycle belongs to a later reservation', $html);
        $this->assertSame($before, Fixture::businessRows($this->connection));
    }

    public function testGenericPositionDoesNotRetireCurrentStage(): void
    {
        Fixture::seed($this->connection, 'position');
        $custody = Services::currentVehicleCustodyService()->resolve(10);
        $this->assertSame(102, $custody['basis_trip_id']);
        $this->assertSame('vehicle_positioned', Services::currentVehicleLocationService()->resolve(10)['event_code']);
        $projection = Services::movementReadinessReadService()->forCompany(1, [102])[102];
        foreach (['location_confirmed', 'airport_staging'] as $code) {
            $this->assertSame('satisfied', array_column($projection['requirements'], null, 'code')[$code]['status']);
        }
        $this->assertStringContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $this->controller()->show(102));
    }

    public function testActiveGuestCustodyStillDefersStaging(): void
    {
        Fixture::seed($this->connection, 'guest');
        $before = Fixture::businessRows($this->connection);
        $this->assertSame('guest', Services::currentVehicleCustodyService()->resolve(10)['custody']);
        $projection = Services::movementReadinessReadService()->forCompany(1, [102])[102];
        $this->assertFalse(array_column($projection['requirements'], null, 'code')['airport_staging']['actionable']);
        $html = $this->controller()->show(102);
        $this->assertStringNotContainsString('action="/operations/checklists/102/stage-at-hnl"', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        $this->assertSame($before, Fixture::businessRows($this->connection));
    }

    public function testScheduledUnknownRemainsSeparateFromCanonicalHotelFactsAndHistory(): void
    {
        Fixture::seed($this->connection);
        $before = Fixture::businessRows($this->connection);
        foreach ([200, 201] as $id) {
            $html = $this->controller()->show($id);
            $this->assertStringContainsString('Scheduled pickup location: Unknown', $html);
            $this->assertStringContainsString('Scheduled return location: Unknown', $html);
            $this->assertStringContainsString('Guest handoff recorded', $html);
            $this->assertStringContainsString('Vehicle recovery recorded', $html);
            $this->assertStringContainsString('Synthetic Garden Hotel', $html);
            $this->assertStringContainsString('Operator Correction', $html);
        }
        $history = $this->controller()->vehicleTripHistory(20);
        $this->assertStringContainsString('Scheduled pickup location: Unknown', $history);
        $this->assertStringContainsString('Scheduled return location: Unknown', $history);
        $this->assertSame($before, Fixture::businessRows($this->connection));
    }

    public function testPresentationPreservesAllAuthorityFieldsAndDoesNotInventRecovery(): void
    {
        Fixture::seed($this->connection);
        $asOf = new DateTimeImmutable();
        $readiness = Services::movementReadinessReadService()->forCompany(1, [102], $asOf)[102];
        $custody = Services::currentVehicleCustodyService()->resolve(10, $asOf);
        $fact = Services::movementOperationalFactPresentationService()->tripFacts(102)['pickup'];
        $service = new MovementStagingPresentationService();
        $presentation = $service->forChecklist(['movement_type' => 'pickup', 'turo_trip_normalized_id' => 102], $readiness, $fact, $custody, [], true, false);
        $this->assertStringNotContainsString('vehicle recovery occurred', $presentation['historical_explanation']);
        $display = $service->readinessForDisplay($readiness, $presentation);
        foreach ($readiness['requirements'] as $i => $original) {
            foreach (['status', 'blocking', 'phase', 'relevant', 'satisfied_by', 'basis_at'] as $field) {
                $this->assertSame($original[$field], $display['requirements'][$i][$field]);
            }
        }
        $this->assertSame($readiness['blocking_remaining_count'], $display['blocking_remaining_count']);
        $this->assertSame($readiness['ready'], $display['ready']);
    }

    private function controller(): TripMovementChecklists
    {
        $request = CoreServices::request();
        assert($request instanceof IncomingRequest);
        $request->setGlobal('get', []);
        $controller = new TripMovementChecklists();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        return $controller;
    }
}
