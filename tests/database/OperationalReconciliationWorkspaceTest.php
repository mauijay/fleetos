<?php

use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\FleetOperationalReconciliationService;
use App\Services\Fleet\OperationalReconciliationViewModel;
use CodeIgniter\Commands\Utilities\Routes\FilterCollector;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GuestCommitmentProjectionFixture;
use Tests\Support\OperationalReconciliationFixture;
use Tests\Support\OperationalReconciliationUiFixture;

/** @internal */
final class OperationalReconciliationWorkspaceTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $connection;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = GuestCommitmentProjectionFixture::sqlite();
        $property = new ReflectionProperty(Database::class, 'instances');
        $this->connections = $property->getValue();
        $property->setValue(null, ['tests' => $this->connection]);
        Services::injectMock('auth', new \Tests\Support\ReconciliationUiAuth());
        Services::injectMock('fleetOperationalReconciliationService', new FleetOperationalReconciliationService($this->connection));
        Services::injectMock('operationalFactsRepository', new OperationalFactsRepository($this->connection));
        $this->withRoutes([['GET', 'operations/reconciliation', 'OperationalReconciliation::index'], ['GET', '/', 'Home::index']]);
    }

    protected function tearDown(): void
    {
        Services::reset();
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        $this->connection->close();
        parent::tearDown();
    }

    public static function scenarios(): iterable
    {
        yield 'custody P0' => ['p0', 'movement.old_guest_custody', 'P0'];
        yield 'preparation P1' => ['p1', 'readiness.current_blocker', 'P1'];
        yield 'obsolete checklist P2' => ['checklist', 'checklist.obsolete_schedule_generation', 'P2'];
        yield 'historical Extra' => ['historical', 'extras.missing_fulfillment', 'Historical review'];
        yield 'healthy' => ['healthy', 'No operational reconciliation issues found.', '1 vehicles reviewed'];
    }

    #[DataProvider('scenarios')]
    public function testRealEngineGetShowsAuthorityEvidence(string $scenario, string $expected, string $label): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, $scenario);
        $before = OperationalReconciliationFixture::fingerprint($this->connection);
        $result = $this->get('operations/reconciliation');
        $result->assertOK();
        $html = $result->getBody();
        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString($label, $html);
        $this->assertStringContainsString('Operational Reconciliation', $html);
        $this->assertStringContainsString('1</strong> vehicles reviewed', $html);
        if ($scenario !== 'healthy') {
            foreach (['Movement authority', 'Import context', 'Position basis', '/operations/vehicles/1000/trip-history'] as $evidence) {
                $this->assertStringContainsString($evidence, $html);
            }
        }
        if ($scenario === 'checklist') {
            $this->assertStringContainsString('Canonical checklist #20000', $html);
            $this->assertStringContainsString('Obsolete checklist #20001', $html);
            $this->assertStringContainsString('persisted state: not_started', $html);
            $this->assertStringNotContainsString('movement.import_current_missing_handoff', $html);
        }
        if ($scenario === 'p1') {
            foreach (['Energy / Fuel', 'Cleaning', 'CURRENT_GENUINE', 'Next confirmed pickup'] as $evidence) {
                $this->assertStringContainsString($evidence, $html);
            }
        }
        $this->assertStringNotContainsString('operational_basis_hash', $html);
        $this->assertStringNotContainsString('guest_name', $html);
        $this->assertSame($before, OperationalReconciliationFixture::fingerprint($this->connection));
    }

    public function testRepeatedFilteredGetsAndCommandCenterCauseZeroBusinessWrites(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'pagination');
        $before = OperationalReconciliationFixture::fingerprint($this->connection);
        $queries = [];
        $listener = static function (Query $query) use (&$queries): void {
            $queries[] = $query->getQuery();
        };
        Events::on('DBQuery', $listener);
        try {
            foreach (['', '?severity=P0', '?severity=P1', '?severity=P2', '?severity=P3', '?vehicle=1000', '?scope=HISTORICAL', '?category=movement', '?page=2', '?severity=P0&scope=CURRENT&category=movement&vehicle=1000&q=Trip+10000'] as $suffix) {
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    $this->get('operations/reconciliation' . $suffix)->assertOK();
                }
            }
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->get('/')->assertOK();
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql))));
        $this->assertSame($before, OperationalReconciliationFixture::fingerprint($this->connection));
    }

    public static function invalidFilters(): iterable
    {
        foreach (['vehicle=20', 'vehicle=999999', 'vehicle=-1', 'vehicle[]=1000', 'trip=200', 'checklist=99999', 'severity=P9', 'scope=PRIVATE', 'category=finance', 'page=0', 'page[]=2', 'q[]=private', 'q=' . str_repeat('x', 101)] as $query) {
            yield $query => [$query];
        }
    }

    #[DataProvider('invalidFilters')]
    public function testUntrustedFiltersFailSafelyWithoutForeignEvidence(string $query): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $result = $this->get('operations/reconciliation?' . $query);
        $result->assertStatus(400);
        $html = $result->getBody();
        $this->assertStringContainsString('Check the review filters', $html);
        foreach (['Synthetic Vehicle 20', 'Trip 200', 'data-fingerprint=', 'Stack trace'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function testForeignAndTamperedChildEvidenceIsNeverRendered(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $this->connection->table('trip_movement_checklists')->insert(['id' => 99999, 'turo_trip_normalized_id' => 200, 'fleet_vehicle_id' => 1000, 'movement_type' => 'pickup', 'scheduled_at' => '2030-01-01 00:00:00']);
        OperationalReconciliationFixture::event($this->connection, 'actual_handoff', date('Y-m-d H:i:s'), 200, 1000, 'home', ['company_id' => 2]);
        $this->connection->table('turo_trips_normalized')->where('id', 200)->update(['guest_name' => 'PRIVATE-FOREIGN-GUEST']);
        $html = $this->get('operations/reconciliation')->getBody();
        foreach (['PRIVATE-FOREIGN-GUEST', 'Trip 200', 'checklist #99999', 'Synthetic Vehicle 20'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function testAmbiguousCompanyContextIsDeniedWithoutLeakingCompanyIdentities(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $this->connection->table('fleet_vehicles')->where('id', 20)->update(['out_of_service_date' => null]);
        $result = $this->get('operations/reconciliation');
        $result->assertStatus(403);
        $this->assertSame('A single active operator company context is required.', trim(strip_tags($result->getBody())));
    }

    public function testRouteRequiresExistingSessionAndOperatorPermissionAndHasNoWriteRoute(): void
    {
        $routes = CoreServices::routes(false);
        CoreServices::injectMock('routes', $routes);
        $routes->loadRoutes();
        $before = (new FilterCollector())->get('GET', 'operations/reconciliation')['before'];
        $this->assertContains('session', $before);
        $this->assertContains('permission:admin.access', $before);
        $this->assertArrayNotHasKey('operations/reconciliation', $routes->getRoutes('POST'));
    }

    public function testPaginationIsBoundedStableAndPreservesAllFilters(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'pagination');
        $projection = (new FleetOperationalReconciliationService($this->connection))->forCompany(1);
        $presenter = new OperationalReconciliationViewModel();
        $query = ['severity' => 'P0', 'scope' => 'CURRENT', 'category' => 'position', 'q' => 'Synthetic'];
        $first = $presenter->present($projection, $query);
        $this->assertSame(25, array_sum(array_map(static fn (array $group): int => count($group['issues']), $first['groups'])));
        $this->assertStringContainsString('severity=P0&scope=CURRENT&category=position', $first['next']);
        $this->assertStringContainsString('q=Synthetic', $first['next']);
        $second = $presenter->present($projection, $query + ['page' => '2']);
        $ids = static fn (array $model): array => array_merge(...array_map(static fn (array $group): array => array_column($group['issues'], 'fingerprint'), $model['groups']));
        $this->assertSame([], array_intersect($ids($first), $ids($second)));
        $this->assertSame($first, $presenter->present($projection, $query));
        $this->assertSame($first['summary'], $second['summary']);
    }

    public function testPageQueryBudgetAddsOneCompanyReadForOneTenAndOneHundredVehicles(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $selects = static function (callable $read): int {
            $count = 0;
            $listener = static function (Query $query) use (&$count): void {
                if (preg_match('/^SELECT\b/i', $query->getQuery())) {
                    $count++;
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $read();
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            return $count;
        };
        $pageCounts = [];
        $previous = 1;
        foreach ([1, 10, 100] as $size) {
            for ($offset = $previous; $offset < $size; $offset++) {
                OperationalReconciliationFixture::vehicle($this->connection, 1000 + $offset);
                OperationalReconciliationFixture::trip($this->connection, 11000 + $offset, 1000 + $offset);
                OperationalReconciliationFixture::checklist($this->connection, 21000 + $offset, trip: 11000 + $offset, vehicle: 1000 + $offset);
            }
            $previous = $size;
            $engine = $selects(fn () => (new FleetOperationalReconciliationService($this->connection))->forCompany(1));
            $page = $selects(fn () => $this->get('operations/reconciliation')->assertOK());
            $this->assertSame($engine + 1, $page, 'Only the existing company-context query is added.');
            $this->assertLessThanOrEqual(33, $page);
            $pageCounts[] = $page;
        }
        $this->assertSame(1, count(array_unique($pageCounts)), 'No per-vehicle or per-issue presentation queries.');
    }

    public static function summaryPriorities(): iterable
    {
        yield 'P1 preparation' => ['p1', 'Upcoming preparation'];
        yield 'P2 historical' => ['historical', 'Recorded authority review'];
        yield 'healthy quiet' => ['healthy', 'Recorded authority review'];
    }

    #[DataProvider('summaryPriorities')]
    public function testCommandCenterPriorityTracksOnlyEngineSeverities(string $scenario, string $label): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, $scenario);
        $result = $this->get('/');
        $result->assertOK();
        $this->assertStringContainsString($label, $result->getBody());
        $this->assertStringNotContainsString('P0 authority review', $result->getBody());
    }

    public function testDisplayAndSearchEscapeOperatorTextWithoutExpandingGuestData(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $this->connection->table('fleet_vehicles')->where('id', 1000)->update(['display_name' => '<script>synthetic</script>']);
        $result = $this->get('operations/reconciliation?q=' . rawurlencode('<script>'));
        $result->assertOK();
        $html = $result->getBody();
        $this->assertStringNotContainsString('<script>synthetic</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('guest_name', $html);
    }

    public function testCommandCenterDisplaysMatchingProjectionCounts(): void
    {
        OperationalReconciliationUiFixture::configure($this->connection, 'p0');
        $queries = new class () {
            private array $statements = [];

            public function record(Query $query): void
            {
                $this->statements[] = $query->getQuery();
            }

            public function all(): array
            {
                return $this->statements;
            }

            public function clear(): void
            {
                $this->statements = [];
            }
        };
        $listener = static fn (Query $query) => $queries->record($query);
        Events::on('DBQuery', $listener);
        try {
            $expected = (new OperationalReconciliationViewModel())->summary((new FleetOperationalReconciliationService($this->connection))->forCompany(1));
            $engineRoster = array_values(array_filter($queries->all(), static fn (string $sql): bool => str_contains(str_replace(['`', '"'], '', $sql), 'statuses.is_available_for_booking') && str_contains(str_replace(['`', '"'], '', $sql), 'vehicles.fleet_number')));
            $queries->clear();
            $result = $this->get('/');
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertCount(1, $engineRoster);
        $this->assertSame(1, count(array_filter($queries->all(), static fn (string $sql): bool => $sql === $engineRoster[0])));
        $result->assertOK();
        $html = $result->getBody();
        $this->assertStringContainsString('P0 authority review', $html);
        $this->assertStringContainsString('Review reconciliation', $html);
        foreach ($expected['counts'] as $severity => $count) {
            $this->assertStringContainsString('<strong>' . $severity . ':</strong> ' . $count, $html);
        }
        $this->assertSame(1, substr_count($html, 'id="reconciliation-summary-heading"'));
    }
}
