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
    private DateTimeImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asOf = new DateTimeImmutable();
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
        $authority = $this->authority();
        $requirements = array_column($authority['readiness']['requirements'], null, 'code');
        foreach (['location_confirmed', 'airport_staging', 'parking_location_recorded'] as $code) {
            $this->assertSame('satisfied', $requirements[$code]['status']);
        }
        $this->assertSame(102, $authority['custody']['basis_trip_id']);
        $this->assertSame($authority['facts']['pickup']['event_id'], $authority['custody']['basis_event_id']);
        $html = $this->controller()->show(102);
        $this->assertStringContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        $this->assertStringNotContainsString('Prior staging is historical', $html);
        $this->assertStringNotContainsString('current vehicle lifecycle belongs to a later reservation', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/stage-at-hnl"', $html);
        $this->assertSame($before, Fixture::businessRows($this->connection));
        $this->assertSame($authority, $this->authority());

        $handoffAt = (new DateTimeImmutable('-1 day'))->setTime(21, 33)->format('Y-m-d H:i:s');
        $this->assertTrue(Services::movementOperationalFactService()->confirmGuestPickup(
            Services::tripMovementChecklistService()->checklist(102),
            ['occurred_at' => $handoffAt, 'note' => 'Synthetic next-day handoff'],
            7,
        ));
        $afterHandoff = $this->authority();
        $this->assertSame('guest', $afterHandoff['custody']['custody']);
        $this->assertSame('rented', $afterHandoff['position']['operational_state']);
        $this->assertSame('actual_handoff', $afterHandoff['facts']['pickup']['event_code']);
        $this->assertSame($handoffAt, $afterHandoff['facts']['pickup']['occurred_at']);
        $this->assertSame('satisfied', array_column($afterHandoff['readiness']['requirements'], null, 'code')['guest_handoff']['status']);
        $rows = Fixture::businessRows($this->connection);
        $html = $this->controller()->show(102);
        $this->assertStringNotContainsString('Prior staging is historical', $html);
        $this->assertStringNotContainsString('Guest pickup confirmation is unavailable', $html);
        $this->assertSame($afterHandoff, $this->authority());
        $this->assertSame($rows, Fixture::businessRows($this->connection));
    }

    public function testRecoveryAloneMakesStagingHistoricalWithoutInventingLaterReservation(): void
    {
        Fixture::seed($this->connection, 'recovery');
        $before = Fixture::businessRows($this->connection);
        $authority = $this->authority();
        $this->assertSame('vehicle_recovered', $authority['custody']['basis_event_code']);
        $this->assertFalse(Services::currentVehicleCustodyService()->hasLaterTripLifecycle(10, 102));
        foreach (['location_confirmed', 'airport_staging'] as $code) {
            $this->assertSame('unsatisfied', array_column($authority['readiness']['requirements'], null, 'code')[$code]['status']);
        }
        $html = $this->controller()->show(102);
        $this->assertStringContainsString('vehicle recovery occurred at', $html);
        $this->assertStringContainsString('staging occurred at', $html);
        $this->assertStringNotContainsString('activity recorded on', $html);
        $this->assertStringNotContainsString('belongs to a later reservation', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        // Restaging is genuinely executable after recovery; the UI must retain it.
        $this->assertStringContainsString('action="/operations/checklists/102/stage-at-hnl"', $html);
        $this->assertSame($authority, $this->authority());
        $this->assertSame($before, Fixture::businessRows($this->connection));
        $this->assertTrue(Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), \Tests\Support\HnlStagingChecklistFixture::stagingData(), 7));
        $this->assertSame(102, Services::currentVehicleCustodyService()->resolve(10)['basis_trip_id']);
    }

    public function testLaterReservationStageUsesOccurrenceTimeAndPreservesBothTrips(): void
    {
        Fixture::seed($this->connection, 'later');
        $before = Fixture::businessRows($this->connection);
        $authority = $this->authority();
        $this->assertSame(103, $authority['custody']['basis_trip_id']);
        $this->assertSame('vehicle_staged', $authority['custody']['basis_event_code']);
        $tripC = Services::movementReadinessReadService()->forCompany(1, [103])[103];
        $this->assertSame('satisfied', array_column($tripC['requirements'], null, 'code')['airport_staging']['status']);
        $html = $this->controller()->show(102);
        $this->assertStringContainsString('activity that occurred at', $html);
        $this->assertStringContainsString('4:58 PM Honolulu', $html);
        $this->assertStringNotContainsString('vehicle recovery occurred', $html);
        $this->assertStringNotContainsString('activity recorded on', $html);
        $this->assertStringNotContainsString('action="/operations/checklists/102/stage-at-hnl"', $html);
        $stage = $authority['custody']['basis_event'];
        $this->assertNotSame($stage['created_at'], $stage['occurred_at']);
        $this->assertSame($authority, $this->authority());
        $this->assertSame($tripC, Services::movementReadinessReadService()->forCompany(1, [103])[103]);
        $this->assertSame($before, Fixture::businessRows($this->connection));
        try {
            Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), \Tests\Support\HnlStagingChecklistFixture::stagingData(), 7);
            $this->fail('A later reservation must still block restaging.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('current pickup reservation', $e->getMessage());
        }
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

    /** @return array<string, mixed> */
    private function authority(): array
    {
        return [
            'readiness' => Services::movementReadinessReadService()->forCompany(1, [102], $this->asOf)[102],
            'custody' => Services::currentVehicleCustodyService()->resolve(10, $this->asOf),
            'position' => Services::currentVehicleLocationService()->resolve(10, $this->asOf),
            'facts' => Services::movementOperationalFactPresentationService()->tripFacts(102),
            'history' => Services::operationalFactsRepository()->vehicleTripHistory(10),
            'checklist' => Services::tripMovementChecklistService()->checklist(102),
        ];
    }
}
