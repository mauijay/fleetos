<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

class OperatingExpenses extends BaseController
{
    public function index(): string
    {
        $query = $this->request->getGet();
        $workspace = Services::operatingExpenseService()->workspace($this->activeCompanyId(), is_array($query) ? $query : []);
        $pager = CoreServices::pager()->only(['view', 'category', 'vehicle', 'source', 'from', 'to', 'page_expenses', 'page_receipts']);
        $expenseLinks = $pager->makeLinks($workspace['expense_page'], $workspace['per_page'], $workspace['expenses']['total'], 'default_full', 0, 'expenses');
        $receiptLinks = $pager->makeLinks($workspace['receipt_page'], $workspace['per_page'], $workspace['receipts']['total'], 'default_full', 0, 'receipts');

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'workspace' => $workspace,
            'expenseLinks' => $expenseLinks,
            'receiptLinks' => $receiptLinks,
            'navigation' => $this->navigation(),
            'notice' => CoreServices::session()->getFlashdata('operating_expense_notice'),
            'errors' => CoreServices::session()->getFlashdata('operating_expense_errors') ?? [],
            'warning' => CoreServices::session()->getFlashdata('operating_expense_warning'),
            'formData' => CoreServices::session()->getFlashdata('operating_expense_form') ?? [],
            'pendingReceiptId' => (int) (CoreServices::session()->getFlashdata('operating_expense_receipt_id') ?? 0),
        ])->render('operating_expenses/index');
    }

    public function show(int $id): string
    {
        $companyId = $this->activeCompanyIdForResource();
        $expense = Services::operatingExpenseService()->expenseDetail($companyId, $id);
        $workspace = Services::operatingExpenseService()->workspace($companyId, ['view' => 'recent']);

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'expense' => $expense,
            'categories' => $workspace['categories'],
            'vehicles' => $workspace['vehicles'],
            'trips' => $workspace['trips'],
            'navigation' => $this->navigation(),
            'notice' => CoreServices::session()->getFlashdata('operating_expense_notice'),
            'errors' => CoreServices::session()->getFlashdata('operating_expense_errors') ?? [],
            'warning' => CoreServices::session()->getFlashdata('operating_expense_warning'),
            'formData' => CoreServices::session()->getFlashdata('operating_expense_form') ?? [],
        ])->render('operating_expenses/show');
    }

    public function create(): RedirectResponse
    {
        $data = $this->request->getPost();
        $formData = is_array($data) ? $data : [];
        try {
            $upload = $this->validOptionalUpload('receipt_file');
        } catch (InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->back()->with('operating_expense_errors', ['receipt_file' => $exception->getMessage()])->with('operating_expense_form', $formData);
        }
        $result = Services::operatingExpenseService()->createManual(
            $this->activeCompanyId(),
            $formData,
            $this->actorUserId(),
            $upload,
        );

        return $this->result($result, '/operations/expenses?view=recent', 'Operating expense recorded.', $formData);
    }

    public function correct(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        $result = Services::operatingExpenseService()->correct($this->activeCompanyIdForResource(), $id, is_array($data) ? $data : [], $this->actorUserId());

        return $this->result($result, '/operations/expenses/' . $id, 'Operating expense corrected.', is_array($data) ? $data : []);
    }

    public function archive(int $id): RedirectResponse
    {
        $result = Services::operatingExpenseService()->archiveExpense($this->activeCompanyIdForResource(), $id, (string) $this->request->getPost('archive_reason'), $this->actorUserId());

        $data = $this->request->getPost();

        return $this->result($result, '/operations/expenses?view=history', 'Operating expense archived.', is_array($data) ? $data : []);
    }

    public function restore(int $id): RedirectResponse
    {
        $result = Services::operatingExpenseService()->restoreExpense($this->activeCompanyIdForResource(), $id, $this->actorUserId());

        return $this->result($result, '/operations/expenses/' . $id, 'Operating expense restored.', []);
    }

    public function attachReceipt(int $id): RedirectResponse
    {
        try {
            $upload = $this->requiredUpload('receipt_file');
        } catch (InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->back()->with('operating_expense_errors', ['receipt_file' => $exception->getMessage()]);
        }
        $result = Services::operatingExpenseService()->attachReceipt($this->activeCompanyIdForResource(), $id, $upload, $this->actorUserId());

        return $this->result($result, '/operations/expenses/' . $id, 'Receipt attached.', []);
    }

    public function uploadReceipt(): RedirectResponse
    {
        $data = $this->request->getPost();
        $formData = is_array($data) ? $data : [];
        try {
            $upload = $this->requiredUpload('receipt_file');
        } catch (InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->back()->with('operating_expense_errors', ['receipt_file' => $exception->getMessage()])->with('operating_expense_form', $formData);
        }
        $result = Services::operatingExpenseService()->uploadReceipt($this->activeCompanyId(), $upload, $formData, $this->actorUserId());
        $notice = ($result['duplicate_evidence'] ?? false)
            ? 'This receipt is already in the company inbox; the existing record was kept.'
            : 'Receipt captured for classification.';

        return $this->result($result, '/operations/expenses?view=needs_attention', $notice, $formData);
    }

    public function classifyReceipt(int $id): RedirectResponse
    {
        $data = $this->request->getPost();
        $result = Services::operatingExpenseService()->classifyReceipt($this->activeCompanyIdForResource(), $id, is_array($data) ? $data : [], $this->actorUserId());

        return $this->result($result, '/operations/expenses?view=recent', 'Receipt classified and operating expense recorded.', is_array($data) ? $data : [], $id);
    }

    public function nonBusinessReceipt(int $id): RedirectResponse
    {
        $ok = Services::operatingExpenseService()->markReceiptNonBusiness($this->activeCompanyIdForResource(), $id, $this->request->getPost('note'), $this->actorUserId());

        return $this->booleanResult($ok, '/operations/expenses?view=history', 'Receipt marked non-business.');
    }

    public function duplicateReceipt(int $id): RedirectResponse
    {
        $duplicateId = (int) $this->request->getPost('duplicate_of_receipt_id');
        try {
            $ok = Services::operatingExpenseService()->markReceiptDuplicate($this->activeCompanyIdForResource(), $id, $duplicateId > 0 ? $duplicateId : null, $this->request->getPost('note'), $this->actorUserId());
        } catch (InvalidArgumentException $exception) {
            return CoreServices::redirectresponse()->back()->with('operating_expense_errors', ['duplicate_of_receipt_id' => $exception->getMessage()]);
        }

        return $this->booleanResult($ok, '/operations/expenses?view=history', 'Receipt marked duplicate.');
    }

    public function archiveReceipt(int $id): RedirectResponse
    {
        $result = Services::operatingExpenseService()->archiveReceipt($this->activeCompanyIdForResource(), $id, (string) $this->request->getPost('archive_reason'), $this->actorUserId());

        $data = $this->request->getPost();

        return $this->result($result, '/operations/expenses?view=history', 'Receipt archived.', is_array($data) ? $data : [], $id);
    }

    public function receiptFile(int $id): ResponseInterface
    {
        $file = Services::operatingExpenseService()->receiptFile($this->activeCompanyIdForResource(), $id);
        $metadata = $file['metadata'];
        $response = $this->response->download($file['path'], null);
        if ($response === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $response
            ->setFileName(Services::privateEvidenceStorageService()->safeResponseFilename($metadata['original_filename'] ?? null, (string) $metadata['mime_type'], 'expense-receipt'))
            ->setContentType((string) $metadata['mime_type'], '')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->inline()
            ->noCache();
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $formData */
    private function result(array $result, string $successTarget, string $notice, array $formData, int $receiptId = 0): RedirectResponse
    {
        if ($result['success'] ?? false) {
            return CoreServices::redirectresponse()->to($successTarget)->with('operating_expense_notice', $notice);
        }
        $response = CoreServices::redirectresponse()->back()
            ->with('operating_expense_errors', $result['errors'] ?? ['expense' => 'The request could not be completed.'])
            ->with('operating_expense_form', array_merge($formData, isset($result['warning']) ? ['confirm_possible_duplicate' => '1'] : []));
        if (isset($result['warning'])) {
            $response = $response->with('operating_expense_warning', $result['warning']);
        }
        if ($receiptId > 0) {
            $response = $response->with('operating_expense_receipt_id', (string) $receiptId);
        }

        return $response;
    }

    private function booleanResult(bool $ok, string $target, string $notice): RedirectResponse
    {
        return $ok
            ? CoreServices::redirectresponse()->to($target)->with('operating_expense_notice', $notice)
            : CoreServices::redirectresponse()->back()->with('operating_expense_errors', ['receipt' => 'The receipt could not be changed from its current state.']);
    }

    private function requiredUpload(string $field): UploadedFile
    {
        $upload = $this->request->getFile($field);
        if ($upload === null || ! $upload->isValid()) {
            throw new InvalidArgumentException('Choose a JPEG, PNG, WebP, or PDF receipt.');
        }

        return $upload;
    }

    private function validOptionalUpload(string $field): ?UploadedFile
    {
        $upload = $this->request->getFile($field);
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (! $upload->isValid()) {
            throw new InvalidArgumentException('The optional receipt upload failed. Choose the file again.');
        }

        return $upload;
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Expenses & Receipts requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function activeCompanyIdForResource(): int
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
            ['label' => 'Expenses & Receipts', 'href' => '/operations/expenses?view=needs_attention', 'active' => 'true'],
            ['label' => 'Airport Operations', 'href' => '/operations/airport', 'active' => 'false'],
            ['label' => 'Airport Receipts', 'href' => '/operations/airport/reimbursements?filter=action', 'active' => 'false'],
            ['label' => 'Incidentals Review', 'href' => '/operations/incidentals', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
        ];
    }
}
