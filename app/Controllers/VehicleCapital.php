<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleCapital extends BaseController
{
    public function show(int $vehicleId): string
    {
        $asOf = new \DateTimeImmutable();
        $companyId = $this->activeCompanyId($asOf);
        if (Services::operationalFactsRepository()->vehicleForCompany($companyId, $vehicleId) === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $workspace = Services::vehicleCapitalService()->workspace($vehicleId);
        if ($workspace === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return CoreServices::renderer()->setData(array_merge($workspace, [
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'editing_snapshot_id' => max(0, (int) $this->request->getGet('edit_snapshot')),
            'notice' => CoreServices::session()->getFlashdata('vehicle_capital_notice'),
            'errors' => CoreServices::session()->getFlashdata('vehicle_capital_errors') ?? [],
            'currentStateNotice' => CoreServices::session()->getFlashdata('vehicle_current_state_notice'),
            'currentStateError' => CoreServices::session()->getFlashdata('vehicle_current_state_error'),
            'currentPositionData' => CoreServices::session()->getFlashdata('current_position_data') ?? [],
            'currentReadinessData' => CoreServices::session()->getFlashdata('current_readiness_data') ?? [],
            'currentLocation' => Services::currentVehicleLocationService()->resolve($vehicleId, $asOf),
            'currentReadiness' => $this->currentReadiness($companyId, $vehicleId, $asOf),
            'currentMovementHref' => $this->currentMovementHref($vehicleId, $asOf),
            'hnlGarages' => (new \App\Services\Fleet\HnlGarageCatalog())->definitions(),
        ]))->render('fleet_vehicles/show');
    }

    public function saveAcquisition(int $vehicleId): RedirectResponse
    {
        return $this->handle($vehicleId, Services::vehicleCapitalService()->saveAcquisition($vehicleId, $this->request->getPost(), $this->actorUserId()), 'Acquisition details saved.');
    }

    public function createLender(int $vehicleId): RedirectResponse
    {
        return $this->handle($vehicleId, Services::vehicleCapitalService()->createLender($this->request->getPost(), $this->actorUserId()), 'Lender created.');
    }

    public function createLoan(int $vehicleId): RedirectResponse
    {
        return $this->handle($vehicleId, Services::vehicleCapitalService()->saveLoan($vehicleId, null, $this->request->getPost(), $this->actorUserId()), 'Financing agreement created.');
    }

    public function updateLoan(int $vehicleId, int $loanId): RedirectResponse
    {
        return $this->handle($vehicleId, Services::vehicleCapitalService()->saveLoan($vehicleId, $loanId, $this->request->getPost(), $this->actorUserId()), 'Financing agreement updated.');
    }

    public function saveSnapshot(int $vehicleId, int $loanId): RedirectResponse
    {
        $data = $this->request->getPost();
        $result = Services::vehicleCapitalService()->saveSnapshot($vehicleId, $loanId, $data, $this->actorUserId());
        $snapshotId = (int) ($data['snapshot_id'] ?? 0);
        $failureTarget = $snapshotId > 0 ? '?edit_snapshot=' . $snapshotId . '#snapshot-' . $snapshotId : '#loan-' . $loanId . '-add-snapshot';

        return $this->handle($vehicleId, $result, 'Balance snapshot saved.', $failureTarget);
    }

    /** @param array{success:bool,id?:int,errors:array<string,string>} $result */
    private function handle(int $vehicleId, array $result, string $notice, string $failureTarget = ''): RedirectResponse
    {
        $response = CoreServices::redirectresponse()->to('/fleet/vehicles/' . $vehicleId . ($result['success'] ? '' : $failureTarget));
        if (! $result['success']) {
            return $response->with('vehicle_capital_errors', $result['errors']);
        }

        return $response->with('vehicle_capital_notice', $notice);
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated actor is required for financial changes.');
        }

        return (int) $user->id;
    }

    private function activeCompanyId(\DateTimeImmutable $asOf): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds($asOf->format('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Vehicle Details requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    /** @return array<string, mixed>|null */
    private function currentReadiness(int $companyId, int $vehicleId, \DateTimeImmutable $asOf): ?array
    {
        $readiness = Services::operationalFactsRepository()->latestCurrentReadinessAssessment($companyId, $vehicleId, $asOf->format('Y-m-d H:i:s'));
        $latestHandoff = Services::operationalFactsRepository()->latestActiveHandoffEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if ($readiness !== null
            && $latestHandoff !== null
            && (string) $latestHandoff['occurred_at'] >= (string) $readiness['captured_at']) {
            return null;
        }

        return $readiness;
    }

    private function currentMovementHref(int $vehicleId, \DateTimeImmutable $asOf): ?string
    {
        $repository = Services::operationalFactsRepository();
        $lifecycle = $repository->latestActiveLifecycleEvent($vehicleId, $asOf->format('Y-m-d H:i:s'));
        if (isset($lifecycle['turo_trip_normalized_id']) && in_array($lifecycle['event_code'] ?? null, ['actual_handoff', 'vehicle_staged'], true)) {
            $movementType = $lifecycle['event_code'] === 'actual_handoff' ? 'return' : 'pickup';
            $href = $repository->movementChecklistHref((int) $lifecycle['turo_trip_normalized_id'], $movementType);
            if ($href !== null) {
                return $href;
            }
        }
        $nextTrip = Services::nextConfirmedTripService()->forVehicle($vehicleId, $asOf);

        return $nextTrip === null ? null : $repository->movementChecklistHref((int) $nextTrip['id'], 'pickup');
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'true'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
        ];
    }
}
