<?php

use App\Services\Fleet\FleetVehicleService;
use App\Services\Fleet\UnknownVehicleOnboardingService;
use App\Services\Turo\TuroTransactionRelinkingService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class UnknownVehicleOnboardingServiceTest extends CIUnitTestCase
{
    public function testSubmittedIdentifierMustResolveToAuthoritativeUnmatchedIssue(): void
    {
        $vehicles = $this->createMock(FleetVehicleService::class);
        $vehicles->expects($this->never())->method('create');
        $mappings = $this->createMock(TuroVehicleMappingService::class);
        $mappings->expects($this->once())->method('unmatchedVehicle')->with('tampered')->willReturn(null);

        $result = $this->service($vehicles, $mappings)->onboard('tampered', ['fleet_number' => 11], 7);

        $this->assertFalse($result['success']);
        $this->assertSame('authority', $result['stage']);
    }

    public function testSuccessfulOnboardingCreatesMapsReconcilesThenRelinks(): void
    {
        $vehicles = $this->createMock(FleetVehicleService::class);
        $vehicles->expects($this->once())->method('create')->with(['fleet_number' => 11], 7, 'turo-11')->willReturn(['success' => true, 'id' => 11, 'errors' => []]);
        $mappings = $this->createMock(TuroVehicleMappingService::class);
        $mappings->expects($this->once())->method('unmatchedVehicle')->with('turo-11')->willReturn(['turo_vehicle_id' => 'turo-11']);
        $reconciliation = $this->createMock(TuroTripReconciliationService::class);
        $reconciliation->expects($this->once())->method('execute')->with('turo-11', 'Vehicle created from the unmatched vehicle workflow.', 7)->willReturn(['summary' => ['reconciled_successfully' => 1]]);
        $transactions = $this->createMock(TuroTransactionRelinkingService::class);
        $transactions->expects($this->once())->method('relinkForTuroVehicle')->with('turo-11')->willReturn(['examined' => 1, 'relinked' => 1, 'unchanged' => 0]);

        $result = (new UnknownVehicleOnboardingService($vehicles, $mappings, $reconciliation, $transactions))->onboard('turo-11', ['fleet_number' => 11], 7);

        $this->assertTrue($result['success']);
        $this->assertSame(11, $result['id']);
        $this->assertSame(1, $result['transactions']['relinked']);
    }

    public function testReconciliationFailureDoesNotUndoCreatedVehicleAndMapping(): void
    {
        $vehicles = $this->createStub(FleetVehicleService::class);
        $vehicles->method('create')->willReturn(['success' => true, 'id' => 11, 'errors' => []]);
        $mappings = $this->createStub(TuroVehicleMappingService::class);
        $mappings->method('unmatchedVehicle')->willReturn(['turo_vehicle_id' => 'turo-11']);
        $reconciliation = $this->createStub(TuroTripReconciliationService::class);
        $reconciliation->method('execute')->willReturn(['summary' => ['reprocessing_failed' => 1]]);
        $transactions = $this->createStub(TuroTransactionRelinkingService::class);
        $transactions->method('relinkForTuroVehicle')->willReturn(['examined' => 0, 'relinked' => 0, 'unchanged' => 0]);

        $result = (new UnknownVehicleOnboardingService($vehicles, $mappings, $reconciliation, $transactions))->onboard('turo-11', [], 7);

        $this->assertTrue($result['success']);
        $this->assertSame('complete', $result['stage']);
        $this->assertSame(1, $result['reconciliation']['summary']['reprocessing_failed']);
    }

    private function service(FleetVehicleService $vehicles, TuroVehicleMappingService $mappings): UnknownVehicleOnboardingService
    {
        return new UnknownVehicleOnboardingService(
            $vehicles,
            $mappings,
            $this->createStub(TuroTripReconciliationService::class),
            $this->createStub(TuroTransactionRelinkingService::class),
        );
    }
}
