<?php

use App\Controllers\TripMovementChecklists;
use App\Exceptions\EarlyHandoffConfirmationRequired;
use App\Repositories\LookupRepository;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\MovementScheduleAuthorityFixture as Fixture;
use Tests\Support\VehicleDamageDatabaseFixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class MovementScheduleAuthorityHotfixTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->connection->setPrefix('');
        VehicleDamageDatabaseFixture::migrate($this->connection);
        Fixture::seed($this->connection);
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
                return new class (['id' => 7, 'username' => 'synthetic-schedule-operator']) extends \CodeIgniter\Shield\Entities\User {
                    public function getEmail(): string
                    {
                        return 'synthetic-schedule@example.test';
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

    public function testOldUrlHeaderAndContextUseCurrentScheduleWithoutRewritingSnapshotsOrReadiness(): void
    {
        $asOf = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('Pacific/Honolulu'));
        $before = Fixture::fingerprints($this->connection);
        $readiness = Services::movementReadinessReadService()->forCompany(1, [102], $asOf);
        $request = CoreServices::request();
        assert($request instanceof IncomingRequest);
        $request->setGlobal('get', []);
        $controller = new TripMovementChecklists();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());
        CoreServices::session()->setFlashdata('movement_checklist_error', 'Synthetic warning for the current reservation');
        CoreServices::session()->setFlashdata('movement_early_handoff_warning', '1');
        $html = $controller->show(102);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $header = $xpath->query('//p[contains(concat(" ",normalize-space(@class)," ")," status-copy ")]')->item(0)->textContent;
        $this->assertStringContainsString('2026-10-07 08:30:00', $header);
        $this->assertStringNotContainsString('10:30', $header);
        $this->assertStringContainsString('Historical checklist schedule: 2026-10-07 10:30:00', $html);
        $this->assertStringContainsString('Oct 7, 8:30 AM', $html);
        $this->assertStringContainsString('Synthetic warning for the current reservation', $html);
        $this->assertSame($before, Fixture::fingerprints($this->connection));
        $this->assertSame($readiness, Services::movementReadinessReadService()->forCompany(1, [102], $asOf));
        $this->assertSame('2026-10-07 10:30:00', Services::tripMovementChecklistService()->checklist(102)['scheduled_at']);
    }

    public function testGuardReloadsCurrentOwnedScheduleInsteadOfStaleSnapshotOrCallerStart(): void
    {
        $checklist = $this->checklist();
        $checklist['starts_at'] = '2026-10-07 10:30:00';
        $before = Fixture::fingerprints($this->connection);
        $this->guard($checklist, '2026-10-07T08:25');
        $this->assertSame($before, Fixture::fingerprints($this->connection));
    }

    public function testGenuineEarlyWarningUsesCurrentStartEvenWhenSnapshotWouldPermitIt(): void
    {
        $this->connection->table('turo_trips_normalized')->where('id', 102)->update(['starts_at' => '2026-10-07 10:30:00']);
        $this->connection->table('trip_movement_checklists')->where('id', 302)->delete();
        $this->connection->table('trip_movement_checklists')->where('id', 102)->update(['scheduled_at' => '2026-10-07 08:30:00']);
        $before = Fixture::fingerprints($this->connection);
        try {
            $this->guard($this->checklist(), '2026-10-07T08:25');
            $this->fail('Genuine early handoff must warn.');
        } catch (EarlyHandoffConfirmationRequired $e) {
            $this->assertStringContainsString('10:30 AM', $e->getMessage());
            $this->assertStringContainsString('more than 2 hours early', $e->getMessage());
        }
        $this->assertSame($before, Fixture::fingerprints($this->connection));
    }

    public function testEqualScheduleAndStrictThresholdRemainUnchanged(): void
    {
        $this->guard($this->checklist(302), '2026-10-07T06:30');
        $this->guard($this->checklist(302), '2026-10-07T08:30');
        $this->expectException(EarlyHandoffConfirmationRequired::class);
        $this->guard($this->checklist(302), '2026-10-07T06:29');
    }

    public function testCurrentWorkspaceLinksPreferExactScheduleAndRetainReadOnlyHistoricalFallback(): void
    {
        $repository = Services::operationalFactsRepository();
        $before = Fixture::fingerprints($this->connection);
        $this->assertSame('/operations/checklists/302', $repository->movementChecklistHref(102, 'pickup'));
        $this->assertSame('/operations/checklists/302', $repository->movementChecklistHref(102));
        $this->assertSame($before, Fixture::fingerprints($this->connection));
        $this->connection->table('turo_trips_normalized')->where('id', 102)->update(['starts_at' => '2026-10-07 11:30:00']);
        $before = Fixture::fingerprints($this->connection);
        $this->assertSame('/operations/checklists/102', $repository->movementChecklistHref(102, 'pickup'));
        $this->assertSame($before, Fixture::fingerprints($this->connection));
    }

    public function testCompletedImportStatusDoesNotCreateOrSatisfyGuestHandoff(): void
    {
        $this->assertSame((new LookupRepository($this->connection))->valueId('trip_status', 'completed'), (int) $this->connection->table('turo_trips_normalized')->where('id', 102)->get()->getRow('trip_status_lookup_value_id'));
        $before = Fixture::fingerprints($this->connection);
        $projection = Services::movementReadinessReadService()->forCompany(1, [102])[102];
        $this->assertSame('unsatisfied', array_column($projection['requirements'], null, 'code')['guest_handoff']['status']);
        $this->assertNull(Services::movementEventService()->activeForTrip(102, ['actual_handoff']));
        $this->assertSame($before, Fixture::fingerprints($this->connection));
    }

    public static function ownershipCases(): iterable
    {
        yield 'wrong company' => ['company_id', 2];
        yield 'wrong vehicle' => ['fleet_vehicle_id', 20];
        yield 'wrong parent trip' => ['turo_trip_normalized_id', 103];
        yield 'missing parent trip' => ['turo_trip_normalized_id', 999999];
    }

    #[DataProvider('ownershipCases')]
    public function testGuardRejectsMismatchedOwnershipBeforeWriting(string $field, int $value): void
    {
        $checklist = $this->checklist();
        $checklist[$field] = $value;
        $before = Fixture::fingerprints($this->connection);
        try {
            $this->guard($checklist, '2026-10-07T08:25', true);
            $this->fail('Mismatched schedule ownership must fail even with early override.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }
        $this->assertSame($before, Fixture::fingerprints($this->connection));
        $this->assertNull(Services::tripMovementChecklistService()->checklistForCompany(2, 102));
    }

    public static function localTimes(): iterable
    {
        foreach (['Pacific/Honolulu', 'UTC', 'America/Los_Angeles'] as $zone) {
            foreach (['2026-10-07 00:00:00', '2026-10-07 08:30:00', '2026-10-07 12:00:00', '2026-10-07 23:59:00', '2026-10-08 00:01:00'] as $time) {
                yield $zone . ' ' . $time => [$zone, $time];
            }
        }
    }

    #[DataProvider('localTimes')]
    public function testHonoluluControlsUseReservationAuthorityAcrossDateBoundaries(string $processZone, string $time): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set($processZone);
        try {
            $start = new DateTimeImmutable($time, new DateTimeZone('Pacific/Honolulu'));
            $this->connection->table('turo_trips_normalized')->where('id', 102)->update(['starts_at' => $time]);
            $this->connection->table('trip_movement_checklists')->where('id', 102)->update(['scheduled_at' => $start->modify('+2 hours')->format('Y-m-d H:i:s')]);
            $checklist = $this->checklist();
            $this->assertSame($time, $checklist['current_scheduled_at']);
            $this->guard($checklist, $start->modify('-5 minutes')->format('Y-m-d\TH:i'));
            $this->guard($checklist, $start->modify('-2 hours')->format('Y-m-d\TH:i'));
            $this->expectException(EarlyHandoffConfirmationRequired::class);
            $this->guard($checklist, $start->modify('-121 minutes')->format('Y-m-d\TH:i'));
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    /** @return array<string, mixed> */
    private function checklist(int $id = 102): array
    {
        return Services::tripMovementChecklistService()->checklistForCompany(1, $id);
    }

    /** Inspect the real pre-write guard without creating any synthetic handoff. */
    private function guard(array $checklist, string $time, bool $override = false): void
    {
        $service = Services::movementOperationalFactService();
        (new ReflectionMethod($service, 'requireEarlyHandoffConfirmation'))->invoke($service, $checklist, [
            'occurred_at' => $time, 'location_class' => 'other_delivery', 'confirm_early_handoff' => $override ? '1' : '0',
        ]);
    }
}
