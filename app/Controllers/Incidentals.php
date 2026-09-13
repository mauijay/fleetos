<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class Incidentals extends BaseController
{
    public function index(): string
    {
        $filter = $this->request->getGet('filter');
        return CoreServices::renderer()->setData([
            'queue' => Services::tripIncidentalReviewService()->index($this->activeCompanyId(), is_string($filter) ? $filter : null),
            'notice' => CoreServices::session()->getFlashdata('incidental_notice'),
            'error' => CoreServices::session()->getFlashdata('incidental_error'),
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
        ])->render('incidentals/index');
    }

    public function approvePolicy(int $id): RedirectResponse
    {
        return $this->action(fn () => Services::tripIncidentalReviewService()->approvePolicy($this->activeCompanyId(), $id, (string) $this->request->getPost('approval_rationale'), $this->actorUserId()), 'Policy version approved.');
    }

    public function confirmPlan(int $id): RedirectResponse
    {
        return $this->action(fn () => Services::tripIncidentalReviewService()->confirmPlan($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId()), 'Trip plan confirmed and filing deadline recorded.');
    }

    public function saveAssignment(): RedirectResponse
    {
        return $this->action(
            function (): void {
                Services::tripIncidentalReviewService()->recordEarningsPlanAssignment($this->activeCompanyId(), $this->request->getPost(), $this->actorUserId());
            },
            'Earnings plan assignment recorded; eligible unresolved trips were evaluated.',
            '/operations/incidentals#policy-setup',
        );
    }

    public function invoiceSent(int $id): RedirectResponse
    {
        return $this->action(fn () => Services::tripIncidentalReviewService()->complete($this->activeCompanyId(), $id, 'invoice_sent', $this->request->getPost(), $this->actorUserId()), 'Invoice sent; follow-up closed.');
    }

    public function noInvoiceNeeded(int $id): RedirectResponse
    {
        return $this->action(fn () => Services::tripIncidentalReviewService()->complete($this->activeCompanyId(), $id, 'no_invoice_needed', $this->request->getPost(), $this->actorUserId()), 'No invoice needed; follow-up closed.');
    }

    /** @param callable():void $operation */
    private function action(callable $operation, string $notice, string $successTarget = '/operations/incidentals?filter=action'): RedirectResponse
    {
        try {
            $operation();
            return CoreServices::redirectresponse()->to($successTarget)->with('incidental_notice', $notice);
        } catch (\Throwable $exception) {
            return CoreServices::redirectresponse()->to('/operations/incidentals?filter=all')->with('incidental_error', $exception->getMessage());
        }
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new \RuntimeException('Incidentals Review requires exactly one active fleet company context.');
        }
        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null || (int) $user->id < 1) {
            throw new \RuntimeException('An authenticated operator is required.');
        }
        return (int) $user->id;
    }

    /** @return list<array{label:string,href:string,active:string}> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Expenses & Receipts', 'href' => '/operations/expenses?view=needs_attention', 'active' => 'false'],
            ['label' => 'Incidentals Review', 'href' => '/operations/incidentals', 'active' => 'true'],
            ['label' => 'Policy Setup', 'href' => '/operations/incidentals#policy-setup', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
        ];
    }
}
