<?php

use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageReadService;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class VehicleDamageReadServiceTest extends CIUnitTestCase
{
    public function testResolverUsesOneOwnedHopAndRejectsMalformedRelationships(): void
    {
        $repository = $this->getMockBuilder(VehicleDamageRepository::class)->disableOriginalConstructor()->onlyMethods(['item'])->getMock();
        $records = [
            1 => ['id' => 1, 'current_condition_item_id' => null],
            2 => ['id' => 2, 'current_condition_item_id' => 1],
            3 => ['id' => 3, 'current_condition_item_id' => 2],
            4 => ['id' => 4, 'current_condition_item_id' => 4],
            5 => ['id' => 5, 'current_condition_item_id' => 99],
            6 => ['id' => 6, 'current_condition_item_id' => 0],
            7 => ['id' => 7, 'current_condition_item_id' => 6],
        ];
        $repository->expects($this->atLeastOnce())->method('item')->willReturnCallback(static fn (int $company, int $vehicle, int $id): ?array => $company === 1 && $vehicle === 10 ? ($records[$id] ?? null) : null);
        $service = new VehicleDamageReadService($this->createStub(VehicleDamageService::class), $repository);
        $this->assertSame($records[1], $service->canonicalItem(1, 10, 1));
        $this->assertSame($records[1], $service->canonicalItem(1, 10, 2));
        $this->assertNull($service->canonicalItem(2, 10, 2));
        $this->assertNull($service->canonicalItem(1, 11, 2));
        foreach ([3, 4, 5, 6, 7] as $id) {
            try {
                $service->canonicalItem(1, 10, $id);
                $this->fail('Malformed canonical relationship was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid canonical condition relationship.', $exception->getMessage());
            }
        }
    }

    public function testVehicleAndChecklistControllersConsumeTheSharedProjection(): void
    {
        foreach (['VehicleCapital', 'TripMovementChecklists'] as $controller) {
            $source = file_get_contents(__DIR__ . '/../../app/Controllers/' . $controller . '.php');
            $this->assertStringContainsString('Services::vehicleDamageReadService()->workspace(', $source);
        }
    }
}
