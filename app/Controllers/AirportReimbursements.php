<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use InvalidArgumentException;

class AirportReimbursements extends BaseController
{
    public function index(): string
    {
        $query = [
            'filter' => (string) ($this->request->getGet('filter') ?? 'action'),
            'page_airport_incidents' => (string) ($this->request->getGet('page_airport_incidents') ?? '1'),
            'page_airport_receipts' => (string) ($this->request->getGet('page_airport_receipts') ?? '1'),
        ];
        $validation = Services::validation();
        $validation->setRules([
            'filter' => 'required|in_list[action,ready,filed,history,all]',
            'page_airport_incidents' => 'required|is_natural_no_zero',
            'page_airport_receipts' => 'required|is_natural_no_zero',
        ]);
        if (! $validation->run($query)) {
            $query = ['filter' => 'action', 'page_airport_incidents' => '1', 'page_airport_receipts' => '1'];
        }

        return view('airport_reimbursements/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'inbox' => service('turoAccessReimbursementService')->inbox($this->activeCompanyId(), $query['filter'], (int) $query['page_airport_incidents'], (int) $query['page_airport_receipts']),
            'notice' => session()->getFlashdata('airport_reimbursement_notice'),
            'error' => session()->getFlashdata('airport_reimbursement_error'),
            'navigation' => $this->navigation(),
        ]);
    }

    public function matchWorkspace(int $id): string
    {
        return view('airport_reimbursements/match', [
            'assets' => service('assetManifestService')->appAssets(),
            'workspace' => service('turoAccessReimbursementService')->matchingWorkspace($this->activeCompanyId(), $id, $this->request->getGet('q')),
            'notice' => session()->getFlashdata('airport_reimbursement_notice'),
            'error' => session()->getFlashdata('airport_reimbursement_error'),
            'navigation' => $this->navigation(),
        ]);
    }

    public function receiptFile(int $id): ResponseInterface
    {
        $file = Services::turoAccessReimbursementService()->receiptFile($this->activeCompanyIdForReceiptFile(), $id);
        $metadata = $file['metadata'];
        $response = $this->response->download($file['path'], null);
        if ($response === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $response
            ->setFileName($this->safeReceiptFilename((string) ($metadata['original_filename'] ?? ''), (string) $metadata['mime_type']))
            ->setContentType((string) $metadata['mime_type'], '')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->inline()
            ->noCache();
    }

    public function createUnmatchedReceipt(): RedirectResponse
    {
        try {
            $file = $this->request->getFile('receipt_file');
            if ($file !== null && $file->isValid()) {
                $result = service('turoAccessReimbursementService')->uploadUnmatchedReceipt($this->activeCompanyId(), $file, $this->request->getPost());
                return $this->back((bool) $result['success'], 'Airport receipt captured.', 'Receipt could not be uploaded.');
            }

            $id = service('turoAccessReimbursementService')->createUnmatchedReceipt($this->activeCompanyId(), $this->request->getPost());
            return $this->back($id > 0, 'Airport receipt recorded.', 'Receipt could not be recorded.');
        } catch (InvalidArgumentException) {
            return $this->back(false, '', 'Choose an active fleet vehicle or leave Vehicle unassigned.');
        }
    }

    public function logRunExpense(): RedirectResponse
    {
        $file = $this->request->getFile('receipt_file');
        if ($file === null || ! $file->isValid()) {
            return $this->back(false, '', 'Choose a receipt image or PDF before logging the expense.');
        }

        $result = service('turoAccessReimbursementService')->uploadAirportRunExpense($this->activeCompanyId(), $file, $this->request->getPost(), $this->actorUserId());
        return $this->back((bool) ($result['success'] ?? false), 'Airport run expense recorded.', (string) ($result['message'] ?? 'Airport run expense could not be recorded.'));
    }

    public function attachReceipt(int $id): RedirectResponse
    {
        $file = $this->request->getFile('receipt_file');
        if ($file !== null && $file->isValid()) {
            $result = service('turoAccessReimbursementService')->uploadReceiptForIncident($this->activeCompanyId(), $id, $file, $this->request->getPost());
            return $this->back((bool) $result['success'], 'Receipt uploaded and claim readiness refreshed.', 'Receipt could not be uploaded.');
        }

        return $this->back(service('turoAccessReimbursementService')->attachReceipt($this->activeCompanyId(), $id, $this->request->getPost()), 'Receipt attached and claim readiness refreshed.', 'Receipt could not be attached.');
    }

    public function matchReceipt(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->linkReceiptToWorkflow($this->activeCompanyId(), $id, (int) $this->request->getPost('airport_movement_workflow_id'), $this->actorUserId());
        return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with((bool) ($result['success'] ?? false) ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', (string) ($result['message'] ?? 'Receipt could not be matched.'));
    }

    public function assignOperationsExpense(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->assignReceiptToOperationsExpense($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId());
        return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with((bool) ($result['success'] ?? false) ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', (string) ($result['message'] ?? 'Receipt could not be assigned.'));
    }

    public function classifyReceipt(int $id): RedirectResponse
    {
        try {
            $result = service('turoAccessReimbursementService')->classifyReceipt($this->activeCompanyId(), $id, (string) $this->request->getPost('receipt_classification'), $this->request->getPost('classification_note'), $this->actorUserId());
            return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with((bool) ($result['success'] ?? false) ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', (string) ($result['message'] ?? 'Receipt classification could not be saved.'));
        } catch (InvalidArgumentException $exception) {
            return \CodeIgniter\Config\Services::redirectresponse()->to('/operations/airport/reimbursements/match/' . $id)->with('airport_reimbursement_error', $exception->getMessage());
        }
    }

    public function updateReceipt(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->updateReceiptMetadata($this->activeCompanyId(), $id, $this->request->getPost(), $this->actorUserId()), 'Receipt metadata updated.', 'Receipt metadata could not be updated.');
    }

    public function markFiled(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->markFiled($this->activeCompanyId(), $id, (string) $this->request->getPost('claim_reference'), $this->request->getPost('claimed_amount'), $this->actorUserId()), 'Claim marked filed.', 'Claim could not be filed from its current state.');
    }

    public function markReimbursed(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->markReimbursed($this->activeCompanyId(), $id, $this->request->getPost('reimbursed_amount'), $this->actorUserId()), 'Reimbursement recorded.', 'Only a filed claim can be marked reimbursed.');
    }

    public function deny(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->deny($this->activeCompanyId(), $id, (string) $this->request->getPost('denial_reason'), $this->actorUserId()), 'Denial recorded.', 'Only a filed claim can be denied.');
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', $ok ? $notice : $error);
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new \RuntimeException('Airport Receipts requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function activeCompanyIdForReceiptFile(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw PageNotFoundException::forPageNotFound();
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
            ['label' => 'Airport Operations', 'href' => '/operations/airport', 'active' => 'false'],
            ['label' => 'Airport Receipts', 'href' => '/operations/airport/reimbursements?filter=action', 'active' => 'true'],
            ['label' => 'Incidentals Review', 'href' => '/operations/incidentals', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
        ];
    }

    private function safeReceiptFilename(string $filename, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $basename = basename(str_replace('\\', '/', str_replace(["\r", "\n", "\0"], '', $filename)));
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9._() -]+/', '_', $stem) ?? '';
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? '', " .-_\t\n\r\0\x0B");

        return substr($stem === '' ? 'receipt' : $stem, 0, 100) . '.' . $extension;
    }
}
