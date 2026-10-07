<?php

use App\Controllers\TripMovementChecklists;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\HnlStagingChecklistFixture as Fixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class HnlStagingChecklistHotfixTest extends CIUnitTestCase
{
    private \CodeIgniter\Database\BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->setPrefix('');
        VehicleDamageDatabaseFixture::migrate($this->connection);
        Fixture::seed($this->connection);
        Services::injectMock('auth', new class (new AuthConfig()) extends Auth {
            public function setAuthenticator(?string $alias = null): self
            {
                return $this;
            }

            public function loggedIn(): bool
            {
                return false;
            }

            public function user(): User
            {
                return new class (['id' => 7, 'username' => 'synthetic-hnl-operator']) extends User {
                    public function getEmail(): string
                    {
                        return 'synthetic-hnl@example.test';
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

    public function testHistoricalStageAtHomeHasAnActionableStructuredEntryTarget(): void
    {
        $custody = Services::currentVehicleCustodyService()->resolve(10);
        $this->assertSame('operator', $custody['custody']);
        $this->assertSame('home', Services::currentVehicleLocationService()->resolve(10)['location_class']);
        $requirement = $this->stagingRequirement();
        $this->assertSame('unsatisfied', $requirement['status']);
        $this->assertTrue($requirement['blocking']);
        $this->assertTrue($requirement['actionable']);
        $this->assertSame('Stage vehicle at HNL', $requirement['action']['label']);
        $html = $this->controller()->show(102);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $link = $xpath->query('//*[@id="checklist-action-airport_staging"]//a')->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame('#handoff-entry', $link->getAttribute('href'));
        $forms = $xpath->query('//*[@id="handoff-entry"]');
        $this->assertCount(1, $forms, 'Record facts must target a rendered, unique form.');
        $form = $forms->item(0);
        assert($form instanceof DOMElement);
        $this->assertSame('/operations/checklists/102/stage-at-hnl', $form->getAttribute('action'));
        foreach (['occurred_on', 'occurred_time', 'airport_garage_code', 'airport_parking_level', 'airport_parking_row', 'cleanliness', 'energy_percent'] as $name) {
            $this->assertCount(1, $xpath->query('//*[@id="handoff-entry"]//*[@name="' . $name . '"]'));
        }
        $this->assertCount(0, $xpath->query('//*[@id="handoff-entry"]//button[contains(text(),"Handoff")]'));
        $this->assertStringContainsString('value="airport_hnl" selected', $html);
        $this->assertStringNotContainsString('Guest pickup confirmed</strong>', $html);
    }

    public function testNewStagingPreservesHistoryAndScopesPositionReadinessWithoutHandoff(): void
    {
        $before = $this->snapshot();
        $asOf = new DateTimeImmutable();
        $other = Services::movementReadinessReadService()->forCompany(1, [100, 103], $asOf);
        $old = $this->connection->table('trip_movement_events')->orderBy('id')->get()->getResultArray();
        $data = Fixture::stagingData();
        $this->assertTrue(Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), $data, 7));
        $after = $this->snapshot();
        $this->assertSame($before['events'] + 1, $after['events']);
        $this->assertSame($before['assessments'] + 1, $after['assessments']);
        $this->assertSame($before['audits'], $after['audits']);
        $this->assertSame($before['airport_audits'] + 1, $after['airport_audits']);
        $this->assertSame($old, $this->connection->table('trip_movement_events')->where('id <=', 3)->orderBy('id')->get()->getResultArray());
        $stage = Services::movementEventService()->latestForTrip(102);
        $this->assertSame('vehicle_staged', $stage['event_code']);
        $this->assertSame(102, (int) $stage['turo_trip_normalized_id']);
        $this->assertSame('International Garage L7 RG', $stage['location_detail']);
        $this->assertSame('international', $stage['airport_garage_code']);
        $this->assertSame(7, (int) $stage['airport_parking_level']);
        $this->assertSame('G', $stage['airport_parking_row']);
        $position = Services::currentVehicleLocationService()->resolve(10);
        $this->assertSame('airport_hnl', $position['location_class']);
        $this->assertSame((int) $stage['id'], (int) $position['event_id']);
        $this->assertSame('operator', Services::currentVehicleCustodyService()->resolve(10)['custody']);
        $this->assertSame('satisfied', $this->stagingRequirement()['status']);
        $this->assertSame($other, Services::movementReadinessReadService()->forCompany(1, [100, 103], $asOf));
        $this->assertSame(1, $this->connection->table('trip_movement_events')->where('event_code', 'actual_handoff')->countAllResults());
        $this->assertSame(0, $this->connection->table('trip_movement_events')->where('event_code', 'vehicle_positioned')->countAllResults());
        $this->assertNull(Services::tripMovementChecklistService()->checklist(102)['completed_at']);
        try {
            Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), $data, 7);
            $this->fail('Replay must not create another current stage.');
        } catch (InvalidArgumentException) {
            $this->assertSame($after, $this->snapshot());
        }
    }

    public function testActivePriorGuestCustodyDefersTheFormAndRejectsFutureStage(): void
    {
        $this->connection->table('trip_movement_events')->where('event_code', 'vehicle_recovered')->delete();
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => (new \App\Repositories\LookupRepository($this->connection))->valueId('trip_status', 'in_progress')]);
        $before = $this->snapshot();
        $custody = Services::currentVehicleCustodyService()->resolve(10);
        $this->assertSame('guest', $custody['custody']);
        $this->assertSame(100, $custody['active_trip_id']);
        $this->assertFalse($this->stagingRequirement()['actionable']);
        $this->assertStringNotContainsString('formaction="/operations/checklists/102/stage-at-hnl"', $this->controller()->show(102));
        $this->rejectStage(Fixture::stagingData(), 'guest possession');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($custody, Services::currentVehicleCustodyService()->resolve(10));
    }

    public function testRestagingRejectsOldFutureOrLaterReservationChronology(): void
    {
        $before = $this->snapshot();
        $this->rejectStage(array_replace(Fixture::stagingData(), ['occurred_at' => (new DateTimeImmutable('-3 days'))->format('Y-m-d\TH:i')]), 'after the intervening movement');
        $this->rejectStage(array_replace(Fixture::stagingData(), ['occurred_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i')]), 'cannot be in the future');
        $this->assertSame($before, $this->snapshot());
        Services::movementEventService()->record(10, 103, 'vehicle_recovered', 'return', (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'home', null, 'checklist_operator', 7);
        $later = $this->snapshot();
        $this->rejectStage(Fixture::stagingData(), 'current pickup reservation');
        $this->assertSame($later, $this->snapshot());
    }

    public function testSameDayRestagingUsesChronologyRatherThanTimestampFormatting(): void
    {
        Services::movementEventService()->record(10, 100, 'vehicle_recovered', 'return', (new DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'), 'home', null, 'checklist_operator', 7);
        $this->rejectStage(array_replace(Fixture::stagingData(), ['occurred_at' => (new DateTimeImmutable('-45 minutes'))->format('Y-m-d\TH:i')]), 'after the intervening movement');
    }

    public function testValidCurrentStageIsKnownAndOffersOnlyGuestPickupConfirmation(): void
    {
        Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), Fixture::stagingData(), 7);
        $html = $this->controller()->show(102);
        $this->assertSame('satisfied', $this->stagingRequirement()['status']);
        $this->assertStringNotContainsString('id="checklist-action-airport_staging" tabindex="-1" class="is-pending', $html);
        $this->assertStringContainsString('action="/operations/checklists/102/confirm-guest-pickup"', $html);
        $this->assertStringNotContainsString('>Stage at HNL</button>', $html);
        $this->assertSame('operator', Services::currentVehicleCustodyService()->resolve(10)['custody']);
    }

    public function testIceStagingUsesTheSameMeasuredEnergyAndFuelPresentation(): void
    {
        $this->connection->table('vehicle_operational_profiles')->where('fleet_vehicle_id', 10)->update(['energy_kind' => 'gasoline']);
        $this->assertStringContainsString('name="energy_percent"', $this->controller()->show(102));
        Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), array_replace(Fixture::stagingData(), ['energy_percent' => '70']), 7);
        $this->assertStringContainsString('<dt>Fuel</dt><dd>70%</dd>', $this->controller()->show(102));
        $this->assertSame('operator', Services::currentVehicleCustodyService()->resolve(10)['custody']);
    }

    public function testPostRedirectGetAndValidationRetainTheStructuredForm(): void
    {
        $data = array_replace(Fixture::stagingData(), ['airport_parking_level' => '9']);
        $request = CoreServices::request();
        assert($request instanceof IncomingRequest);
        $request->setMethod('POST')->setGlobal('post', $data);
        $response = $this->controller()->stageAtHnl(102);
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertStringEndsWith('/operations/checklists/102#handoff-entry', $response->getHeaderLine('Location'));
        $this->assertSame($data['airport_parking_row'], CoreServices::session()->getFlashdata('movement_fact_data')['airport_parking_row']);
        $this->assertStringContainsString('action="/operations/checklists/102/stage-at-hnl"', html_entity_decode($this->controller()->show(102)));
        $request->setGlobal('post', Fixture::stagingData());
        $response = $this->controller()->stageAtHnl(102);
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertSame('Vehicle staged at HNL. Guest pickup is not yet confirmed.', CoreServices::session()->getFlashdata('movement_checklist_notice'));
        $this->assertSame('satisfied', $this->stagingRequirement()['status']);
    }

    public function testHomeHotelReturnAndCanceledWorkflowsDoNotGainARestagingForm(): void
    {
        $this->connection->table('airport_movement_workflows')->where('id', 102)->delete();
        foreach (['home', 'waikiki_hotel'] as $location) {
            $this->connection->table('scheduled_movement_locations')->where('turo_trip_normalized_id', 102)->update(['location_class' => $location]);
            $this->assertStringNotContainsString('formaction="/operations/checklists/102/stage-at-hnl"', $this->controller()->show(102));
        }
        $this->connection->table('trip_movement_checklists')->where('id', 102)->update(['movement_type' => 'return']);
        $this->assertStringNotContainsString('formaction="/operations/checklists/102/stage-at-hnl"', $this->controller()->show(102));
        $this->connection->table('turo_trips_normalized')->where('id', 102)->update(['canceled_at' => date('Y-m-d H:i:s')]);
        $this->assertStringNotContainsString('id="handoff-entry"', $this->controller()->show(102));
    }

    private function rejectStage(array $data, string $message): void
    {
        try {
            Services::movementOperationalFactService()->stageForChecklist(Services::tripMovementChecklistService()->checklist(102), $data, 7);
            $this->fail('Staging should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testStagingPostRejectsForeignChecklistAndTripVehicleMismatchWithoutWrites(): void
    {
        $this->connection->table('companies')->insert(['id' => 2, 'name' => 'Synthetic Other Company', 'slug' => 'synthetic-other']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 20, 'company_id' => 2, 'vehicle_spec_id' => 1, 'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'SYNTHETIC-OTHER', 'display_name' => 'Synthetic Other Vehicle', 'out_of_service_date' => '2020-01-01']);
        $this->connection->table('turo_trips_normalized')->insert(['id' => 200, 'fleet_vehicle_id' => 20, 'turo_trip_id' => 'SYNTHETIC-OTHER-200', 'turo_reservation_id' => 'SYNTHETIC-OTHER-200', 'starts_at' => date('Y-m-d H:i:s'), 'ends_at' => date('Y-m-d H:i:s')]);
        $this->connection->table('trip_movement_checklists')->insert(['id' => 200, 'fleet_vehicle_id' => 20, 'turo_trip_normalized_id' => 200, 'movement_type' => 'pickup', 'scheduled_at' => date('Y-m-d H:i:s')]);
        $before = $this->snapshot();
        try {
            $this->controller()->stageAtHnl(200);
            $this->fail('Foreign company checklist must be rejected.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $this->assertSame($before, $this->snapshot());
        }
        $this->connection->table('trip_movement_checklists')->where('id', 102)->update(['turo_trip_normalized_id' => 200]);
        $request = CoreServices::request();
        assert($request instanceof IncomingRequest);
        $request->setGlobal('post', Fixture::stagingData());
        try {
            $this->controller()->stageAtHnl(102);
            $this->fail('A mismatched trip and vehicle must be rejected.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function testStagingRouteRequiresSessionAdminCsrfAndPost(): void
    {
        CoreServices::routes()->loadRoutes();
        $collector = new \CodeIgniter\Commands\Utilities\Routes\FilterCollector();
        $filters = $collector->get('POST', 'operations/checklists/102/stage-at-hnl')['before'];
        $this->assertContains('session', $filters);
        $this->assertContains('permission:admin.access', $filters);
        $this->assertSame(1, array_count_values($filters)['csrf'] ?? 0);
        $this->assertNotContains('TripMovementChecklists::stageAtHnl/$1', CoreServices::routes()->getRoutes('GET'));
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
    private function stagingRequirement(): array
    {
        $projection = Services::movementReadinessReadService()->forCompany(1, [102])[102];
        return array_values(array_filter($projection['requirements'], static fn (array $r): bool => $r['code'] === 'airport_staging'))[0];
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return ['events' => $this->connection->table('trip_movement_events')->countAllResults(), 'assessments' => $this->connection->table('movement_assessments')->countAllResults(), 'audits' => $this->connection->table('operational_fact_audits')->countAllResults(), 'airport_audits' => $this->connection->table('airport_movement_audits')->countAllResults()];
    }
}
