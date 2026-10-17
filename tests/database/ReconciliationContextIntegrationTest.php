<?php

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\FleetOperationalReconciliationService;
use App\Services\Fleet\OperationalReconciliationViewModel;
use App\Services\Fleet\ReconciliationContextService;
use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GuestCommitmentProjectionFixture as G;
use Tests\Support\OperationalReconciliationFixture as F;
use Tests\Support\OperationalReconciliationUiFixture as U;

/** @internal */
final class ReconciliationContextIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $connection;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = G::sqlite();
        (new MigrationRunner(new \Config\Migrations(), $this->connection))->setNamespace('CodeIgniter\\Shield')->latest();
        $property = new ReflectionProperty(Database::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->connection]);
        Services::injectMock('auth', new \Tests\Support\ReconciliationUiAuth());
        Services::injectMock('fleetOperationalReconciliationService', new FleetOperationalReconciliationService($this->connection));
        Services::injectMock('operationalFactsRepository', new OperationalFactsRepository($this->connection));
        $this->withRoutes([
            ['GET', 'operations/reconciliation', 'OperationalReconciliation::index'],
            ['GET', 'fleet/vehicles/(:num)', 'VehicleCapital::show/$1'],
            ['GET', 'operations/vehicles/(:num)/trip-history', 'TripMovementChecklists::vehicleTripHistory/$1'],
            ['GET', 'operations/trips/(:num)/commitments', 'TripCommitments::index/$1'],
            ['GET', 'operations/checklists/(:num)', 'TripMovementChecklists::show/$1'],
            ['GET', '/', 'Home::index'],
        ]);
    }

    protected function tearDown(): void
    {
        Services::reset();
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        $this->connection->close();
        parent::tearDown();
    }

    public static function surfaces(): iterable
    {
        yield 'P0 vehicle' => ['p0', 'fleet/vehicles/1000', 'P0 Reconciliation'];
        yield 'healthy vehicle' => ['healthy', 'fleet/vehicles/1000', null];
        yield 'P0 trip' => ['p0', 'operations/trips/10000/commitments', 'P0 Reconciliation'];
        yield 'P1 checklist' => ['p1', 'operations/checklists/20001', 'P1 Reconciliation'];
        yield 'P2 obsolete checklist' => ['checklist', 'operations/checklists/20001', 'P2 Reconciliation'];
        yield 'historical trip' => ['historical', 'operations/trips/10000/commitments', 'Historical review'];
        yield 'trip history' => ['p0', 'operations/vehicles/1000/trip-history?trip=10000', 'P0 Reconciliation'];
    }

    #[DataProvider('surfaces')]
    public function testContextRoundTripDetailAndRepeatedGetsArePure(string $scenario, string $path, ?string $expected): void
    {
        U::configure($this->connection, $scenario);
        $before = F::fingerprint($this->connection);
        $queries = $this->queryLog();
        $listener = static fn (Query $query) => $queries->record($query->getQuery());
        Events::on('DBQuery', $listener);
        try {
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $result = $this->get($path);
                $result->assertOK();
                $html = $result->getBody();
                if ($expected === null) {
                    $this->assertStringNotContainsString('reconciliation-context-link', $html);
                    $this->get('operations/reconciliation?vehicle=1000&return=vehicle')->assertOK();
                    continue;
                }
                $this->assertStringContainsString($expected, $html);
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $links = (new DOMXPath($dom))->query('//aside[contains(@class,"reconciliation-context-link")]/a');
                $this->assertGreaterThan(0, $links->length);
                foreach ($links as $link) {
                    $this->assertInstanceOf(DOMElement::class, $link);
                    $href = $link->getAttribute('href');
                    $review = $this->get(ltrim($href, '/'));
                    $review->assertOK();
                    $body = $review->getBody();
                    $this->assertStringContainsString('reconciliation-detail', $body);
                    $this->assertStringContainsString('Support reference', $body);
                    $this->assertStringContainsString('Concise authority chronology', $body);
                    $this->assertStringContainsString('Source freshness', $body);
                    $this->assertStringNotContainsString('method="post"', strtolower($body));
                    $reviewDom = new DOMDocument();
                    @$reviewDom->loadHTML($body);
                    $back = (new DOMXPath($reviewDom))->query('//a[@class="reconciliation-return"]')->item(0);
                    $this->assertInstanceOf(DOMElement::class, $back);
                    $destination = $back->getAttribute('href');
                    $this->assertTrue(str_starts_with($destination, '/'));
                    $this->assertFalse(str_starts_with($destination, '//'));
                    $this->get(ltrim($destination, '/'))->assertOK();
                }
            }
            $this->get('/')->assertOK();
            $this->get('operations/reconciliation?severity=P0')->assertOK();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $writes = array_values(array_filter($queries->all(), static fn (string $sql): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql)));
        $this->assertSame([], $writes, 'Includes business facts, audits and receipts.');
        $this->assertSame($before, F::fingerprint($this->connection));
    }

    public static function invalidContexts(): iterable
    {
        foreach (['vehicle=20', 'trip=200', 'checklist=99999', 'vehicle=1000&trip=200', 'vehicle=1001&trip=10000',
            'trip=10001&checklist=20000', 'trip=0', 'trip=-1', 'trip[]=10000', 'checklist[]=20000',
            'issue=' . str_repeat('a', 64), 'issue[]=x', 'vehicle=1000&return=https://example.com', 'vehicle=1000&return=//example.com',
            'vehicle=1000&return=%2Ffleet%2Fvehicles%2F20', 'vehicle=1000&return[]=vehicle', 'return=trip', 'return=checklist', 'trip=99999999999999999999'] as $query) {
            yield $query => [$query];
        }
    }

    #[DataProvider('invalidContexts')]
    public function testTamperedContextSuppressesAllEvidenceAndReturnDestinations(string $query): void
    {
        U::configure($this->connection, 'p0');
        $before = F::fingerprint($this->connection);
        $result = $this->get('operations/reconciliation?' . $query);
        $result->assertStatus(400);
        foreach (['data-fingerprint=', 'reconciliation-return', 'Trip 200', 'example.com', 'Stack trace'] as $private) {
            $this->assertStringNotContainsString($private, $result->getBody());
        }
        $this->assertSame($before, F::fingerprint($this->connection));
    }

    public function testChildOwnershipAndDeletedTripAreValidatedBeforeAnyLinkIsBuilt(): void
    {
        U::configure($this->connection, 'p0');
        $this->connection->table('trip_movement_checklists')->insert(['id' => 99998, 'turo_trip_normalized_id' => 200, 'fleet_vehicle_id' => 20, 'movement_type' => 'pickup', 'scheduled_at' => '2030-01-02 00:00:00']);
        $this->get('operations/reconciliation?checklist=99998&return=checklist')->assertStatus(400);
        $this->connection->table('trip_movement_checklists')->insert(['id' => 99999, 'turo_trip_normalized_id' => 200, 'fleet_vehicle_id' => 1000, 'movement_type' => 'pickup', 'scheduled_at' => '2030-01-01 00:00:00']);
        $this->get('operations/reconciliation?checklist=99999&return=checklist')->assertStatus(400);
        $this->connection->table('turo_trips_normalized')->where('id', 10000)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->get('operations/reconciliation?trip=10000&return=trip')->assertStatus(400);
        $this->get('operations/reconciliation?checklist=20000&return=checklist')->assertStatus(400);
    }

    public function testCountsUseTripMembershipAndRetainEngineDeduplication(): void
    {
        U::configure($this->connection, 'p0');
        F::trip($this->connection, 10002, 1000, 'completed', '2000-01-01 00:00:00', '2000-01-02 00:00:00');
        $projection = (new FleetOperationalReconciliationService($this->connection))->forCompany(1);
        $context = new ReconciliationContextService($projection);
        $this->assertNull($context->badge(1000, 10002, return: 'trip'), 'Unrelated vehicle issue must not inflate this trip.');
        $vehicle = $context->badge(1000);
        $trip = $context->badge(1000, 10000, return: 'trip');
        $checklist = $context->badge(1000, 10000, 20000, 'checklist');
        $relevant = array_values(array_filter($projection->vehicles[1000]['issues'], static fn (array $issue): bool => in_array(10000, $issue['trip_ids'], true)));
        $this->assertSame(count($projection->vehicles[1000]['issues']), $vehicle['count']);
        $this->assertSame(count($relevant), $trip['count']);
        $this->assertSame($trip['count'], $checklist['count']);
        $this->assertSame('P0', $trip['severity']);
        foreach ($relevant as $issue) {
            $query = ['trip' => '10000', 'issue' => $issue['fingerprint'], 'return' => 'trip'];
            $model = (new OperationalReconciliationViewModel())->present($projection, $query);
            $this->assertSame(1, $model['total']);
            $this->assertSame([], $model['errors']);
            $this->assertSame('/operations/trips/10000/commitments', $model['return_link']['href']);
            $this->get('operations/reconciliation?' . http_build_query($query))->assertOK();
            $this->get('operations/reconciliation?trip=10002&issue=' . $issue['fingerprint'])->assertStatus(400);
        }
    }

    public static function deepLinks(): iterable
    {
        foreach (['vehicle=1000', 'trip=10000', 'checklist=20000', 'vehicle=1000&severity=P0', 'vehicle=1000&trip=10000&checklist=20000&return=checklist'] as $query) {
            yield $query => [$query];
        }
    }

    #[DataProvider('deepLinks')]
    public function testEntityFiltersUseLoadedEvidenceOnly(string $query): void
    {
        U::configure($this->connection, 'p0');
        $projection = (new FleetOperationalReconciliationService($this->connection))->forCompany(1);
        parse_str($query, $filters);
        $queries = $this->queryLog();
        $listener = static fn (Query $query) => $queries->record($query->getQuery());
        Events::on('DBQuery', $listener);
        try {
            $model = (new OperationalReconciliationViewModel())->present($projection, $filters);
            $html = CoreServices::renderer()->setData(['reconciliation' => $model, 'assets' => ['css' => null, 'js' => null]])->render('operational_reconciliation/index');
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame([], $queries->all(), 'Detail expansion and navigation require no further reads.');
        $this->assertSame([], $model['errors']);
        $this->assertGreaterThan(0, $model['total']);
        $this->assertStringContainsString('<details class="reconciliation-detail"', $html);
        $this->get('operations/reconciliation?' . $query)->assertOK();
    }

    public function testObsoleteIssueDetailIncludesAllMatchingCanonicalGenerationsWithoutChoosingWinner(): void
    {
        U::configure($this->connection, 'checklist');
        $projection = (new FleetOperationalReconciliationService($this->connection))->forCompany(1);
        $vehicles = $projection->vehicles;
        $generation = $vehicles[1000]['checklists'][0];
        $generation['id'] = 20002;
        $vehicles[1000]['checklists'][] = $generation;
        $projection = new \App\DTOs\Fleet\OperationalReconciliation(1, $projection->asOf, $vehicles);
        $issue = array_values(array_filter($projection->vehicles[1000]['issues'], static fn (array $issue): bool => $issue['code'] === 'checklist.obsolete_schedule_generation'))[0];
        $model = (new OperationalReconciliationViewModel())->present($projection, ['issue' => $issue['fingerprint'], 'checklist' => '20001', 'return' => 'checklist']);
        $html = CoreServices::renderer()->setData(['reconciliation' => $model, 'assets' => ['css' => null, 'js' => null]])->render('operational_reconciliation/index');
        foreach (['Canonical checklist #20000', 'Canonical checklist #20002', 'Obsolete checklist #20001', 'persisted state: not_started', 'Derived readiness', 'Issue fingerprint'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('reconciliation-detail" open', $html);
    }

    public function testHistoryUsesOneBulkProjectionForOneTenAndOneHundredTrips(): void
    {
        U::configure($this->connection, 'p0');
        $prior = 2;
        $increments = [];
        foreach ([2, 10, 100] as $size) {
            for ($i = $prior; $i < $size; $i++) {
                F::trip($this->connection, 11000 + $i, 1000, 'completed', '2000-01-01 00:00:00', '2000-01-02 00:00:00');
            }
            $prior = $size;
            $queries = $this->queryLog();
            $listener = static fn (Query $query) => $queries->record($query->getQuery());
            Events::on('DBQuery', $listener);
            try {
                $this->get('operations/vehicles/1000/trip-history')->assertOK();
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            $sql = $queries->all();
            $this->assertCount(1, array_filter($sql, static fn (string $sql): bool => str_contains(str_replace(['`', '"'], '', $sql), 'statuses.is_available_for_booking')));
            $increments[] = count(array_filter($sql, static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
        }
        $this->assertCount(1, array_unique($increments), 'No reconciliation per history row.');
    }

    public function testExistingContextRoutesRetainSessionAndPermissionFilters(): void
    {
        $routes = CoreServices::routes(false);
        CoreServices::injectMock('routes', $routes);
        $routes->loadRoutes();
        foreach (['fleet/vehicles/1000', 'operations/trips/10000/commitments', 'operations/checklists/20000', 'operations/vehicles/1000/trip-history', 'operations/reconciliation'] as $path) {
            $filters = (new FilterCollector())->get('GET', $path)['before'];
            $this->assertContains('session', $filters);
            $this->assertContains('permission:admin.access', $filters);
        }
    }

    private function queryLog(): object
    {
        return new class () {
            private array $queries = [];

            public function record(string $sql): void
            {
                $this->queries[] = $sql;
            }

            public function all(): array
            {
                return $this->queries;
            }
        };
    }
}
