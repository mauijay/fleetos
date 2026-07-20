<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class TripMovementChecklists extends BaseController
{
    public function show(int $id): string
    {
        return view('trip_movement_checklists/show', [
            'assets' => service('assetManifestService')->appAssets(),
            'checklist' => service('tripMovementChecklistService')->checklist($id),
            'notice' => session()->getFlashdata('movement_checklist_notice'),
            'error' => session()->getFlashdata('movement_checklist_error'),
        ]);
    }

    public function completeItem(int $id): RedirectResponse
    {
        return $this->back(service('tripMovementChecklistService')->completeItem($id, $this->request->getPost('note')), 'Item completed.', 'That checklist item could not be completed.');
    }

    public function undoItem(int $id): RedirectResponse
    {
        return $this->back(service('tripMovementChecklistService')->undoItem($id), 'Item reopened.', 'That checklist item could not be reopened.');
    }

    public function markNotApplicable(int $id): RedirectResponse
    {
        return $this->back(service('tripMovementChecklistService')->markNotApplicable($id, $this->request->getPost('note')), 'Item marked not applicable.', 'That checklist item could not be changed.');
    }

    public function setDisposition(int $id): RedirectResponse
    {
        return $this->back(service('tripMovementChecklistService')->setDisposition($id, (string) $this->request->getPost('vehicle_disposition')), 'Vehicle disposition saved.', 'Choose a valid vehicle disposition.');
    }

    public function complete(int $id): RedirectResponse
    {
        return $this->back(service('tripMovementChecklistService')->completeChecklist($id, $this->request->getPost('completion_note')), 'Movement workflow completed.', 'Complete required critical items before closing this workflow.');
    }

    public function reopen(int $id): RedirectResponse
    {
        $confirmed = $this->request->getPost('confirm_reopen') === '1';
        return $this->back($confirmed && service('tripMovementChecklistService')->reopenChecklist($id), 'Movement workflow reopened.', 'Confirm before reopening a completed workflow.');
    }

    private function back(bool $ok, string $notice, string $error): RedirectResponse
    {
        return redirect()->back()->with($ok ? 'movement_checklist_notice' : 'movement_checklist_error', $ok ? $notice : $error);
    }
}
