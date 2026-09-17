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

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->facts = new TripMovementChecklistPositionControllerTestService();
        Services::injectMock('movementOperationalFactService', $this->facts);
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
            ->disableOriginalConstructor()->onlyMethods(['activeFleetCompanyIds'])->getMock();
        $repository->expects($this->once())->method('activeFleetCompanyIds')->willReturn([1]);
        Services::injectMock('operationalFactsRepository', $repository);

        $response = $this->controller([])->completePhotos(623);

        $this->assertStringEndsWith('/operations/checklists/623#checklist-action-photos_complete', $response->getHeaderLine('Location'));
        $this->assertSame('Pickup photos could not be completed.', CoreServices::session()->getFlashdata('movement_checklist_error'));
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

    public function completePickupPhotos(int $checklistId, int $companyId, int $actorUserId): bool
    {
        return false;
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
