<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;
use Throwable;

class TripCommitments extends BaseController
{
    public function index(int $tripId): string
    {
        $workspace = Services::tripCommitmentService()->workspace($this->activeCompanyId(), $tripId);
        $editId = (int) $this->request->getGet('edit');
        $editing = null;
        foreach ($workspace['active'] as $commitment) {
            if ((int) $commitment['id'] === $editId) {
                $editing = $commitment;
                break;
            }
        }

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'workspace' => $workspace,
            'editing' => $editing,
            'formData' => CoreServices::session()->getFlashdata('trip_commitment_data') ?: [],
            'success' => CoreServices::session()->getFlashdata('trip_commitment_success'),
            'error' => CoreServices::session()->getFlashdata('trip_commitment_error'),
        ])->render('trip_commitments/index');
    }

    public function create(int $tripId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId): void {
            Services::tripCommitmentService()->create($this->activeCompanyId(), $tripId, $this->request->getPost(), $this->actorUserId());
        }, 'Guest commitment added.', true);
    }

    public function edit(int $tripId, int $commitmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $commitmentId): void {
            Services::tripCommitmentService()->edit($this->activeCompanyId(), $tripId, $commitmentId, $this->request->getPost(), $this->actorUserId());
        }, 'Guest commitment updated.', true, $commitmentId);
    }

    public function acknowledge(int $tripId, int $commitmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $commitmentId): void {
            Services::tripCommitmentService()->acknowledge($this->activeCompanyId(), $tripId, $commitmentId, $this->actorUserId());
        }, 'Guest commitment acknowledged.');
    }

    public function complete(int $tripId, int $commitmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $commitmentId): void {
            Services::tripCommitmentService()->complete($this->activeCompanyId(), $tripId, $commitmentId, $this->actorUserId());
        }, 'Guest commitment completed.');
    }

    public function cancel(int $tripId, int $commitmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $commitmentId): void {
            Services::tripCommitmentService()->cancel(
                $this->activeCompanyId(),
                $tripId,
                $commitmentId,
                (string) $this->request->getPost('cancellation_reason'),
                $this->actorUserId(),
            );
        }, 'Guest commitment marked not applicable.');
    }

    private function mutate(int $tripId, callable $action, string $success, bool $preserveInput = false, ?int $editId = null): RedirectResponse
    {
        $url = '/operations/trips/' . $tripId . '/commitments';
        try {
            $action();
        } catch (Throwable $exception) {
            $redirect = CoreServices::redirectresponse()->to($url . ($editId === null ? '#commitment-form' : '?edit=' . $editId . '#commitment-form'))
                ->with('trip_commitment_error', $exception->getMessage());
            if ($preserveInput) {
                $redirect->with('trip_commitment_data', $this->request->getPost());
            }

            return $redirect;
        }

        return CoreServices::redirectresponse()->to($url . '#guest-commitments')->with('trip_commitment_success', $success);
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Guest Commitments require exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }

    /** @return list<array{label:string,href:string,active:string}> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Extras Import', 'href' => '/turo/extras', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '/reports/vehicle-financial-results', 'active' => 'false'],
        ];
    }
}
