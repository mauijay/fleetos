<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class SuperchargerReconciliation extends BaseController
{
    public function index(): string
    {
        $filter = $this->request->getGet('filter');

        return CoreServices::renderer()->setData([
            'workspace' => Services::superchargerReconciliationService()->workspace($this->activeCompanyId(), is_string($filter) ? $filter : null),
            'notice' => CoreServices::session()->getFlashdata('supercharger_notice'),
            'error' => CoreServices::session()->getFlashdata('supercharger_error'),
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
        ])->render('supercharger_reconciliation/index');
    }

    public function import(): RedirectResponse
    {
        $file = $this->request->getFile('tesla_csv');
        if ($file === null || ! $file->isValid()) {
            return $this->failure('Choose a Tesla charging history CSV file.');
        }
        if (! in_array(strtolower($file->getClientExtension()), ['csv', 'txt'], true)) {
            return $this->failure('Export the Tesla charging history as CSV before importing it.');
        }
        $uploadPath = rtrim((new \Config\Paths())->writableDirectory, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tesla-charging-imports';
        if (! is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }
        $storedName = $file->getRandomName();
        $file->move($uploadPath, $storedName);
        $path = $uploadPath . DIRECTORY_SEPARATOR . $storedName;
        try {
            $result = Services::teslaChargingImportService()->import(
                $path,
                $this->activeCompanyId(),
                $this->actorUserId(),
                $file->getClientName(),
            );
        } catch (\Throwable $exception) {
            $this->remove($path);

            return $this->failure($exception->getMessage());
        }
        $this->remove($path);
        $message = $result['replayed']
            ? sprintf('This exact Tesla file was already imported as batch %d; no rows were written.', $result['batch_id'])
            : sprintf('Tesla batch %d: %d imported, %d review, %d rejected, %d duplicates.', $result['batch_id'], $result['imported'], $result['review'], $result['rejected'], $result['duplicate']);

        return CoreServices::redirectresponse()->to('/operations/supercharger-reimbursements?filter=all')->with('supercharger_notice', $message);
    }

    public function workflow(int $id): RedirectResponse
    {
        try {
            Services::superchargerReconciliationService()->changeWorkflow(
                $this->activeCompanyId(),
                $id,
                (string) $this->request->getPost('workflow_state_code'),
                $this->request->getPost('workflow_note'),
                $this->request->getPost('invoice_reference'),
                $this->actorUserId(),
            );
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/operations/supercharger-reimbursements?filter=all#case-' . $id)->with('supercharger_notice', 'Invoice workflow updated.');
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new \RuntimeException('Supercharger reconciliation requires exactly one active fleet company context.');
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

    private function failure(string $message): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/operations/supercharger-reimbursements?filter=all')->with('supercharger_error', $message);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return list<array{label:string,href:string,active:string}> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Expenses & Receipts', 'href' => '/operations/expenses?view=needs_attention', 'active' => 'false'],
            ['label' => 'Supercharger Reimbursements', 'href' => '/operations/supercharger-reimbursements', 'active' => 'true'],
            ['label' => 'Incidentals Review', 'href' => '/operations/incidentals', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
        ];
    }
}
