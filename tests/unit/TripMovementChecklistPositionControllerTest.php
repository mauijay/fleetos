<?php

use App\Controllers\TripMovementChecklists;
use App\Repositories\OperationalFactsRepository;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\MovementReadinessReadService;
use App\Services\Fleet\TripMovementChecklistService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class TripMovementChecklistPositionControllerTest extends CIUnitTestCase
{
    private TripMovementChecklistPositionControllerTestService $facts;
    private TripMovementChecklistPositionControllerTestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->facts = new TripMovementChecklistPositionControllerTestService();
        $this->repository = new TripMovementChecklistPositionControllerTestRepository();
        Services::injectMock('movementOperationalFactService', $this->facts);
        Services::injectMock('operationalFactsRepository', $this->repository);
        Services::injectMock('tripMovementChecklistService', new TripMovementChecklistPositionControllerTestChecklistService());
        $readiness = $this->createStub(MovementReadinessReadService::class);
        $readiness->method('forCompany')->willReturn([]);
        Services::injectMock('movementReadinessReadService', $readiness);
        Services::injectMock('auth', new TripMovementChecklistPositionControllerTestAuth(42));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testSuccessfulPositionPostRedirectsToCanonicalChecklistWithFlashNotice(): void
    {
        $response = $this->controller([
            'occurred_on' => '2026-09-08',
            'occurred_time' => '16:50',
            'occurred_at' => '2026-09-08T16:50',
            'location_class' => 'home',
            'location_detail' => '',
            'note' => 'Returned home.',
        ])->recordVehiclePosition(623);

        $location = $response->getHeaderLine('Location');
        $this->assertStringEndsWith('/operations/checklists/623#readiness-heading', $location);
        $this->assertStringNotContainsString('action=position', $location);
        $this->assertSame('Current vehicle position recorded.', CoreServices::session()->getFlashdata('movement_checklist_notice'));
        $this->assertSame(1, $this->facts->calls);
        $this->assertSame('2026-09-08T16:50', $this->facts->received['occurred_at']);
        $this->assertSame(42, $this->facts->actorUserId);
    }

    public function testValidationFailureReturnsToOpenPositionFormWithSubmittedValues(): void
    {
        $this->facts->exception = new InvalidArgumentException('Actual position time and location are required.');
        $submitted = [
            'occurred_on' => '2026-09-08',
            'occurred_time' => '16:50',
            'occurred_at' => '2026-09-08T16:50',
            'location_class' => '',
            'location_detail' => 'Fleet driveway',
            'note' => 'Keep this value.',
        ];

        $response = $this->controller($submitted)->recordVehiclePosition(623);

        $this->assertStringEndsWith('/operations/checklists/623?action=position#position-entry', $response->getHeaderLine('Location'));
        $this->assertSame('Actual position time and location are required.', CoreServices::session()->getFlashdata('movement_checklist_error'));
        $this->assertSame($submitted, CoreServices::session()->getFlashdata('vehicle_position_data'));
        $this->assertSame(1, $this->facts->calls);
    }

    public function testSuccessfulPostRedirectsToNextBlockingChecklistAction(): void
    {
        $projection = [
            'requirements' => [
                ['code' => 'optional', 'status' => 'unsatisfied', 'blocking' => false, 'action' => ['label' => 'Review']],
                ['code' => 'exterior_inspected', 'status' => 'unsatisfied', 'blocking' => true, 'action' => ['label' => 'Inspect']],
            ],
            'workflow_history' => ['legacy_items' => []],
        ];
        $readiness = $this->createStub(MovementReadinessReadService::class);
        $readiness->method('forCompany')->willReturn([623 => $projection]);
        Services::injectMock('movementReadinessReadService', $readiness);

        $response = $this->controller(['occurred_at' => '2026-09-08T16:50', 'location_class' => 'home'])->recordVehiclePosition(623);

        $this->assertStringEndsWith('/operations/checklists/623#checklist-action-exterior_inspected', $response->getHeaderLine('Location'));
    }

    public function testFailedChecklistPostKeepsAttemptedFocusableActionAnchor(): void
    {
        $repository = $this->getMockBuilder(OperationalFactsRepository::class)
            ->disableOriginalConstructor()->onlyMethods(['activeFleetCompanyIds', 'tripSchedule'])->getMock();
        $repository->expects($this->exactly(2))->method('activeFleetCompanyIds')->willReturn([1]);
        $repository->expects($this->once())->method('tripSchedule')->with(280)->willReturn(['id' => 280, 'trip_status_code' => 'booked', 'canceled_at' => null]);
        Services::injectMock('operationalFactsRepository', $repository);

        $response = $this->controller([])->completePhotos(623);

        $this->assertStringEndsWith('/operations/checklists/623#checklist-action-photos_complete', $response->getHeaderLine('Location'));
        $this->assertSame('Pickup photos could not be completed.', CoreServices::session()->getFlashdata('movement_checklist_error'));
    }

    public function testCanceledTripRejectsOperationalMutationBeforeWriting(): void
    {
        $this->repository->schedule = ['id' => 280, 'trip_status_code' => 'canceled_zero_payout', 'canceled_at' => '2026-09-20 08:00:00'];

        $response = $this->controller([
            'occurred_at' => '2026-09-20T09:00',
            'location_class' => 'home',
        ])->recordVehiclePosition(623);

        $this->assertStringEndsWith('/operations/checklists/623#historical-workflow', $response->getHeaderLine('Location'));
        $this->assertSame('This trip is canceled or invalid. Operational movement changes are not applicable.', CoreServices::session()->getFlashdata('movement_checklist_error'));
        $this->assertSame(0, $this->facts->calls);
    }

    public function testMissingNormalizedTripRejectsOperationalMutationBeforeWriting(): void
    {
        $this->repository->schedule = null;

        $response = $this->controller([])->completePhotos(623);

        $this->assertStringEndsWith('/operations/checklists/623#checklist-action-photos_complete', $response->getHeaderLine('Location'));
        $this->assertSame('This trip is canceled or invalid. Operational movement changes are not applicable.', CoreServices::session()->getFlashdata('movement_checklist_error'));
    }

    public function testRetroactiveHandoffPostUsesServerCompanyActorAndTripScopedRedirect(): void
    {
        $repository = $this->getMockBuilder(OperationalFactsRepository::class)
            ->disableOriginalConstructor()->onlyMethods(['activeFleetCompanyIds', 'trip', 'movementChecklistHref'])->getMock();
        $repository->method('activeFleetCompanyIds')->willReturn([1]);
        $repository->expects($this->once())->method('trip')->with(280)->willReturn(['id' => 280, 'fleet_vehicle_id' => 13, 'company_id' => 1]);
        $repository->expects($this->once())->method('movementChecklistHref')->with(280, 'return')->willReturn('/operations/checklists/623');
        Services::injectMock('operationalFactsRepository', $repository);
        $submitted = ['occurred_at' => '2026-09-18T17:00', 'location_class' => '', 'cleanliness' => '', 'energy_percent' => ''];

        $response = $this->controller($submitted)->recordRetroactiveHandoff(280);

        $this->assertStringEndsWith('/operations/checklists/623#pickup-fact-heading', $response->getHeaderLine('Location'));
        $this->assertSame('Guest handoff recorded.', CoreServices::session()->getFlashdata('movement_checklist_notice'));
        $this->assertSame(1, $this->facts->retroactiveCalls);
        $this->assertSame(1, $this->facts->companyId);
        $this->assertSame(280, $this->facts->tripId);
        $this->assertSame(42, $this->facts->actorUserId);
        $this->assertSame($submitted, $this->facts->received);
    }

    /** @param array<string, string> $post */
    private function controller(array $post): TripMovementChecklists
    {
        $request = $this->createStub(IncomingRequest::class);
        $request->method('getPost')->willReturn($post);
        $controller = new TripMovementChecklists();
        $controller->initController($request, CoreServices::response(), CoreServices::logger());

        return $controller;
    }
}

final class TripMovementChecklistPositionControllerTestService extends MovementOperationalFactService
{
    public int $calls = 0;
    public int $retroactiveCalls = 0;
    public int $companyId = 0;
    public int $tripId = 0;
    /** @var array<string, mixed> */
    public array $received = [];
    public int $actorUserId = 0;
    public ?InvalidArgumentException $exception = null;

    public function recordVehiclePosition(array $checklist, array $data, int $actorUserId): bool
    {
        ++$this->calls;
        $this->received = $data;
        $this->actorUserId = $actorUserId;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return true;
    }

    public function recordRetroactiveHandoff(int $companyId, int $tripId, array $data, int $actorUserId): int
    {
        ++$this->retroactiveCalls;
        $this->companyId = $companyId;
        $this->tripId = $tripId;
        $this->received = $data;
        $this->actorUserId = $actorUserId;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return 99;
    }
}

final class TripMovementChecklistPositionControllerTestChecklistService extends TripMovementChecklistService
{
    public function checklist(int $id): array
    {
        return [
            'exists' => true,
            'id' => $id,
            'company_id' => 1,
            'fleet_vehicle_id' => 13,
            'turo_trip_normalized_id' => 280,
            'movement_type' => 'return',
        ];
    }

    public function checklistForCompany(int $companyId, int $id): ?array
    {
        return $companyId === 1 ? $this->checklist($id) : null;
    }

    public function completePickupPhotos(int $checklistId, int $companyId, int $actorUserId): bool
    {
        return false;
    }
}

final class TripMovementChecklistPositionControllerTestRepository extends OperationalFactsRepository
{
    /** @var array<string, mixed>|null */
    public ?array $schedule = ['id' => 280, 'trip_status_code' => 'booked', 'canceled_at' => null];

    public function __construct()
    {
    }

    public function activeFleetCompanyIds(?string $asOfDate = null): array
    {
        return [1];
    }

    public function tripSchedule(int $tripId): ?array
    {
        return $tripId === 280 ? $this->schedule : null;
    }
}

final class TripMovementChecklistPositionControllerTestAuth extends Auth
{
    public function __construct(private readonly int $userId)
    {
        parent::__construct(new AuthConfig());
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return true;
    }

    public function user(): User
    {
        $user = new User();
        $user->id = $this->userId;

        return $user;
    }
}
