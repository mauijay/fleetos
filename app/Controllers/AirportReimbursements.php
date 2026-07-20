<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class AirportReimbursements extends BaseController
{
    public function index(): string
    {
        return view('airport_reimbursements/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'inbox' => service('turoAccessReimbursementService')->inbox(),
            'notice' => session()->getFlashdata('airport_reimbursement_notice'),
            'error' => session()->getFlashdata('airport_reimbursement_error'),
        ]);
    }

    public function matchWorkspace(int $id): string
    {
        return view('airport_reimbursements/match', [
            'assets' => service('assetManifestService')->appAssets(),
            'workspace' => service('turoAccessReimbursementService')->matchingWorkspace($id, $this->request->getGet('q')),
            'notice' => session()->getFlashdata('airport_reimbursement_notice'),
            'error' => session()->getFlashdata('airport_reimbursement_error'),
        ]);
    }

    public function createUnmatchedReceipt(): RedirectResponse
    {
        $file = $this->request->getFile('receipt_file');
        if ($file !== null && $file->isValid()) {
            $result = service('turoAccessReimbursementService')->uploadUnmatchedReceipt($file, $this->request->getPost());
            return $this->back((bool) $result['success'], 'Unmatched airport receipt captured.', 'Receipt could not be uploaded.');
        }

        $id = service('turoAccessReimbursementService')->createUnmatchedReceipt($this->request->getPost());
        return $this->back($id > 0, 'Unmatched airport receipt recorded.', 'Receipt could not be recorded.');
    }

    public function logRunExpense(): RedirectResponse
    {
        $file = $this->request->getFile('receipt_file');
        if ($file === null || ! $file->isValid()) {
            return $this->back(false, 'Airport run expense recorded.', 'Choose a receipt image or PDF before logging the expense.');
        }

        $result = service('turoAccessReimbursementService')->uploadAirportRunExpense($file, $this->request->getPost());
        return $this->back((bool) ($result['success'] ?? false), 'Airport run expense recorded.', (string) ($result['message'] ?? 'Airport run expense could not be recorded.'));
    }

    public function attachReceipt(int $id): RedirectResponse
    {
        $file = $this->request->getFile('receipt_file');
        if ($file !== null && $file->isValid()) {
            $result = service('turoAccessReimbursementService')->uploadReceiptForIncident($id, $file, $this->request->getPost());
            return $this->back((bool) $result['success'], 'Receipt uploaded and claim readiness refreshed.', 'Receipt could not be uploaded.');
        }

        return $this->back(service('turoAccessReimbursementService')->attachReceipt($id, $this->request->getPost()), 'Receipt attached and claim readiness refreshed.', 'Receipt could not be attached.');
    }

    public function matchReceipt(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->linkReceiptToWorkflow($id, (int) $this->request->getPost('airport_movement_workflow_id'));
        if ((bool) ($result['success'] ?? false)) {
            return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with('airport_reimbursement_notice', (string) $result['message']);
        }

        return $this->back((bool) ($result['success'] ?? false), (string) ($result['message'] ?? 'Receipt matched.'), (string) ($result['message'] ?? 'Receipt could not be matched.'));
    }

    public function assignOperationsExpense(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->assignReceiptToOperationsExpense($id, $this->request->getPost());
        return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with((bool) ($result['success'] ?? false) ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', (string) ($result['message'] ?? 'Receipt could not be assigned.'));
    }

    public function classifyReceipt(int $id): RedirectResponse
    {
        $result = service('turoAccessReimbursementService')->classifyReceipt($id, (string) $this->request->getPost('receipt_classification'), $this->request->getPost('classification_note'));
        return redirect()->to('/operations/airport/reimbursements/match/' . $id)->with((bool) ($result['success'] ?? false) ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', (string) ($result['message'] ?? 'Receipt classification could not be saved.'));
    }

    public function updateReceipt(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->updateReceiptMetadata($id, $this->request->getPost()), 'Receipt metadata updated.', 'Receipt metadata could not be updated.');
    }

    public function markFiled(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->markFiled($id, (string) $this->request->getPost('claim_reference'), $this->request->getPost('claimed_amount')), 'Claim marked filed.', 'Claim could not be filed.');
    }

    public function markReimbursed(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->markReimbursed($id, $this->request->getPost('reimbursed_amount')), 'Reimbursement recorded.', 'Reimbursement could not be recorded.');
    }

    public function deny(int $id): RedirectResponse
    {
        return $this->back(service('turoAccessReimbursementService')->deny($id, (string) $this->request->getPost('denial_reason')), 'Denial recorded.', 'Denial could not be recorded.');
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'airport_reimbursement_notice' : 'airport_reimbursement_error', $ok ? $notice : $error);
    }
}
