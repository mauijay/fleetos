<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Services\Files\PrivateEvidenceStorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\ExpenseReceipts;
use Config\Services;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class OperatingExpenseService
{
    /** @var list<string> */
    private const PAYMENT_METHODS = ['business_credit_card', 'personal_credit_card', 'debit_card', 'cash', 'bank', 'other'];

    public function __construct(
        private readonly ?OperatingExpenseRepository $repository = null,
        private readonly ?AuditLogRepository $auditLogs = null,
        private readonly ?LookupRepository $lookups = null,
        private readonly ?PrivateEvidenceStorageService $storage = null,
        private readonly ?ExpenseReceipts $receiptConfig = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function workspace(int $companyId, array $query): array
    {
        $view = in_array($query['view'] ?? '', ['needs_attention', 'recent', 'vehicle', 'history'], true)
            ? (string) $query['view']
            : 'needs_attention';
        $filters = [
            'category' => $this->allowedFilter((string) ($query['category'] ?? ''), array_column($this->repo()->categories(), 'code')),
            'vehicle' => $this->vehicleFilter($companyId, (string) ($query['vehicle'] ?? '')),
            'source' => $this->allowedFilter((string) ($query['source'] ?? ''), ['manual', 'receipt_inbox']),
            'from' => $this->optionalDate((string) ($query['from'] ?? '')) ?? '',
            'to' => $this->optionalDate((string) ($query['to'] ?? '')) ?? '',
        ];
        $expensePage = max(1, (int) ($query['page_expenses'] ?? 1));
        $receiptPage = max(1, (int) ($query['page_receipts'] ?? 1));
        $perPage = 20;

        return [
            'view' => $view,
            'filters' => $filters,
            'categories' => $this->repo()->categories(),
            'vehicles' => $this->repo()->vehicles($companyId),
            'trips' => $this->repo()->trips($companyId),
            'expenses' => $this->repo()->expensePage($companyId, $view, $filters, $expensePage, $perPage),
            'receipts' => $this->repo()->receiptPage($companyId, $view, $receiptPage, $perPage),
            'expense_page' => $expensePage,
            'receipt_page' => $receiptPage,
            'per_page' => $perPage,
            'recorded_total' => $this->repo()->recordedTotal($companyId),
            'needs_classification' => $this->repo()->needsClassificationCount($companyId),
        ];
    }

    /** @return array<string, mixed> */
    public function expenseDetail(int $companyId, int $id): array
    {
        $expense = $this->requireExpense($companyId, $id);
        $expense['receipts'] = $this->repo()->receiptsForExpense($companyId, $id);

        return $expense;
    }

    /** @return array{success:bool,errors:array<string,string>,id?:int,warning?:string,candidates?:array<int,array<string,mixed>>} */
    public function createManual(int $companyId, array $data, int $actorUserId, ?UploadedFile $upload = null): array
    {
        $this->requireActor($actorUserId);
        $validated = $this->validateExpense($companyId, $data);
        if ($validated['errors'] !== []) {
            return ['success' => false, 'errors' => $validated['errors']];
        }
        $duplicate = $this->duplicateWarning($companyId, $validated['data'], $data);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $stored = null;
        try {
            $id = $this->repo()->transaction(function () use ($companyId, $validated, $actorUserId, $upload, &$stored): int {
                $now = date('Y-m-d H:i:s');
                $expense = array_merge($validated['data'], [
                    'company_id' => $companyId,
                    'source_code' => 'manual',
                    'status_code' => 'recorded',
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = $this->repo()->createExpense($expense);
                $this->audit('operating_expenses', $id, 'created', null, array_merge($expense, ['id' => $id]), $actorUserId);

                if ($upload !== null) {
                    $stored = $this->store($upload, $validated['data']['expense_date'], $actorUserId);
                    $existing = $this->repo()->receiptForCompanyFile($companyId, (int) $stored['file_id']);
                    if ($existing !== null) {
                        throw new InvalidArgumentException('This receipt is already in the company expense inbox. Record the expense without re-uploading it or classify the existing receipt.');
                    }
                    $receipt = [
                        'company_id' => $companyId,
                        'operating_expense_id' => $id,
                        'file_id' => $stored['file_id'],
                        'classification_code' => 'operating_expense',
                        'document_date' => $validated['data']['expense_date'],
                        'observed_amount' => $validated['data']['amount'],
                        'vendor' => $validated['data']['vendor'],
                        'note' => $validated['data']['business_purpose'],
                        'created_by' => $actorUserId,
                        'classified_by' => $actorUserId,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'classified_at' => $now,
                    ];
                    $receiptId = $this->repo()->createReceipt($receipt);
                    $this->audit('operating_expense_receipts', $receiptId, 'uploaded', null, array_merge($receipt, ['id' => $receiptId]), $actorUserId);
                    $this->audit('operating_expense_receipts', $receiptId, 'attached', null, ['company_id' => $companyId, 'operating_expense_id' => $id], $actorUserId);
                }

                return $id;
            });

            return ['success' => true, 'errors' => [], 'id' => $id];
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }

            return ['success' => false, 'errors' => ['receipt_file' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }
            throw $exception;
        }
    }

    /** @return array{success:bool,errors:array<string,string>,receipt_id?:int,duplicate_evidence?:bool} */
    public function uploadReceipt(int $companyId, UploadedFile $upload, array $data, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $documentDate = $this->optionalDate((string) ($data['document_date'] ?? ''));
        if (trim((string) ($data['document_date'] ?? '')) !== '' && $documentDate === null) {
            return ['success' => false, 'errors' => ['document_date' => 'Use a valid receipt date.']];
        }
        $amount = $this->optionalAmount($data['observed_amount'] ?? null);
        if (($data['observed_amount'] ?? '') !== '' && $amount === null) {
            return ['success' => false, 'errors' => ['observed_amount' => 'Amount must be greater than zero with no more than two decimal places.']];
        }

        $stored = null;
        try {
            return $this->repo()->transaction(function () use ($companyId, $upload, $data, $actorUserId, $documentDate, $amount, &$stored): array {
                $stored = $this->store($upload, $documentDate, $actorUserId);
                $existing = $this->repo()->receiptForCompanyFile($companyId, (int) $stored['file_id']);
                if ($existing !== null) {
                    return [
                        'success' => true,
                        'errors' => [],
                        'receipt_id' => (int) $existing['id'],
                        'duplicate_evidence' => true,
                    ];
                }
                $now = date('Y-m-d H:i:s');
                $receipt = [
                    'company_id' => $companyId,
                    'operating_expense_id' => null,
                    'file_id' => $stored['file_id'],
                    'classification_code' => 'needs_classification',
                    'document_date' => $documentDate,
                    'observed_amount' => $amount,
                    'vendor' => $this->text($data['vendor'] ?? null, 190),
                    'note' => $this->text($data['note'] ?? null),
                    'created_by' => $actorUserId,
                    'classified_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'classified_at' => null,
                ];
                $id = $this->repo()->createReceipt($receipt);
                $this->audit('operating_expense_receipts', $id, 'uploaded', null, array_merge($receipt, ['id' => $id]), $actorUserId);

                // Do not disclose a checksum match belonging only to another company.
                return ['success' => true, 'errors' => [], 'receipt_id' => $id, 'duplicate_evidence' => false];
            });
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }

            return ['success' => false, 'errors' => ['receipt_file' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }
            throw $exception;
        }
    }

    /** @return array{success:bool,errors:array<string,string>,id?:int,warning?:string,candidates?:array<int,array<string,mixed>>} */
    public function classifyReceipt(int $companyId, int $receiptId, array $data, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $receipt = $this->requireReceipt($companyId, $receiptId);
        if ($receipt['classification_code'] !== 'needs_classification' || $receipt['archived_at'] !== null) {
            return ['success' => false, 'errors' => ['receipt' => 'Only a receipt needing classification can become an operating expense.']];
        }
        $defaults = [
            'expense_date' => $receipt['document_date'],
            'amount' => $receipt['observed_amount'],
            'vendor' => $receipt['vendor'],
            'business_purpose' => $receipt['note'],
        ];
        $validated = $this->validateExpense($companyId, array_merge($defaults, $data));
        if ($validated['errors'] !== []) {
            return ['success' => false, 'errors' => $validated['errors']];
        }
        $duplicate = $this->duplicateWarning($companyId, $validated['data'], $data);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $id = $this->repo()->transaction(function () use ($companyId, $receipt, $receiptId, $validated, $actorUserId): int {
            $now = date('Y-m-d H:i:s');
            $expense = array_merge($validated['data'], [
                'company_id' => $companyId,
                'source_code' => 'receipt_inbox',
                'status_code' => 'recorded',
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = $this->repo()->createExpense($expense);
            $this->repo()->updateReceipt($companyId, $receiptId, [
                'operating_expense_id' => $id,
                'classification_code' => 'operating_expense',
                'classified_by' => $actorUserId,
                'classified_at' => $now,
                'updated_at' => $now,
            ]);
            $updated = array_merge($receipt, ['operating_expense_id' => $id, 'classification_code' => 'operating_expense', 'classified_by' => $actorUserId, 'classified_at' => $now, 'updated_at' => $now]);
            $this->audit('operating_expenses', $id, 'created', null, array_merge($expense, ['id' => $id]), $actorUserId);
            $this->audit('operating_expense_receipts', $receiptId, 'classified', $receipt, $updated, $actorUserId);
            $this->audit('operating_expense_receipts', $receiptId, 'attached', null, ['company_id' => $companyId, 'operating_expense_id' => $id], $actorUserId);

            return $id;
        });

        return ['success' => true, 'errors' => [], 'id' => $id];
    }

    /** @return array{success:bool,errors:array<string,string>} */
    public function correct(int $companyId, int $id, array $data, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $old = $this->requireExpense($companyId, $id);
        if ($old['status_code'] !== 'recorded') {
            return ['success' => false, 'errors' => ['expense' => 'Restore this expense before correcting it.']];
        }
        $validated = $this->validateExpense($companyId, $data);
        if ($validated['errors'] !== []) {
            return ['success' => false, 'errors' => $validated['errors']];
        }
        $material = ['amount', 'expense_date', 'expense_category_lookup_value_id', 'fleet_vehicle_id', 'turo_trip_normalized_id'];
        $materialChanged = array_filter($material, static fn (string $field): bool => (string) ($old[$field] ?? '') !== (string) ($validated['data'][$field] ?? '')) !== [];
        $reason = $this->text($data['correction_reason'] ?? null);
        if ($materialChanged && $reason === null) {
            return ['success' => false, 'errors' => ['correction_reason' => 'Explain why this financial or assignment value is being corrected.']];
        }
        $duplicate = $this->duplicateWarning($companyId, $validated['data'], $data, $id);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->repo()->transaction(function () use ($companyId, $id, $old, $validated, $actorUserId, $reason, $materialChanged): void {
            $newValues = array_merge($validated['data'], ['updated_by' => $actorUserId, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->repo()->updateExpense($companyId, $id, $newValues);
            $this->audit('operating_expenses', $id, $materialChanged ? 'corrected' : 'updated', $old, array_merge($old, $newValues, ['correction_reason' => $reason]), $actorUserId);
        });

        return ['success' => true, 'errors' => []];
    }

    /** @return array{success:bool,errors:array<string,string>} */
    public function archiveExpense(int $companyId, int $id, string $reason, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $old = $this->requireExpense($companyId, $id);
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'errors' => ['archive_reason' => 'An archive reason is required.']];
        }
        if ($old['status_code'] === 'archived') {
            return ['success' => true, 'errors' => []];
        }
        $now = date('Y-m-d H:i:s');
        $new = ['status_code' => 'archived', 'archived_at' => $now, 'archived_by' => $actorUserId, 'archive_reason' => $reason, 'updated_at' => $now, 'updated_by' => $actorUserId];
        $this->repo()->transaction(function () use ($companyId, $id, $old, $new, $actorUserId): void {
            $this->repo()->updateExpense($companyId, $id, $new);
            $this->audit('operating_expenses', $id, 'archived', $old, array_merge($old, $new), $actorUserId);
        });

        return ['success' => true, 'errors' => []];
    }

    /** @return array{success:bool,errors:array<string,string>} */
    public function restoreExpense(int $companyId, int $id, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $old = $this->requireExpense($companyId, $id);
        if ($old['status_code'] !== 'archived') {
            return ['success' => true, 'errors' => []];
        }
        $now = date('Y-m-d H:i:s');
        $new = ['status_code' => 'recorded', 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'updated_at' => $now, 'updated_by' => $actorUserId];
        $this->repo()->transaction(function () use ($companyId, $id, $old, $new, $actorUserId): void {
            $this->repo()->updateExpense($companyId, $id, $new);
            $this->audit('operating_expenses', $id, 'restored', $old, array_merge($old, $new), $actorUserId);
        });

        return ['success' => true, 'errors' => []];
    }

    /** @return array{success:bool,errors:array<string,string>,receipt_id?:int} */
    public function attachReceipt(int $companyId, int $expenseId, UploadedFile $upload, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $expense = $this->requireExpense($companyId, $expenseId);
        $stored = null;
        try {
            return $this->repo()->transaction(function () use ($companyId, $expenseId, $expense, $upload, $actorUserId, &$stored): array {
                $stored = $this->store($upload, (string) $expense['expense_date'], $actorUserId);
                if ($this->repo()->receiptForCompanyFile($companyId, (int) $stored['file_id']) !== null) {
                    throw new InvalidArgumentException('This receipt is already in the company expense inbox.');
                }
                $now = date('Y-m-d H:i:s');
                $receipt = [
                    'company_id' => $companyId,
                    'operating_expense_id' => $expenseId,
                    'file_id' => $stored['file_id'],
                    'classification_code' => 'operating_expense',
                    'document_date' => $expense['expense_date'],
                    'observed_amount' => $expense['amount'],
                    'vendor' => $expense['vendor'],
                    'note' => $expense['business_purpose'],
                    'created_by' => $actorUserId,
                    'classified_by' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'classified_at' => $now,
                ];
                $receiptId = $this->repo()->createReceipt($receipt);
                $this->audit('operating_expense_receipts', $receiptId, 'uploaded', null, array_merge($receipt, ['id' => $receiptId]), $actorUserId);
                $this->audit('operating_expense_receipts', $receiptId, 'attached', null, ['company_id' => $companyId, 'operating_expense_id' => $expenseId], $actorUserId);

                return ['success' => true, 'errors' => [], 'receipt_id' => $receiptId];
            });
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }

            return ['success' => false, 'errors' => ['receipt_file' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->storage()->discardNewFile($stored);
            }
            throw $exception;
        }
    }

    public function markReceiptNonBusiness(int $companyId, int $receiptId, ?string $note, int $actorUserId): bool
    {
        return $this->terminalReceipt($companyId, $receiptId, 'non_business', 'non_business', ['note' => $this->text($note)], $actorUserId);
    }

    public function markReceiptDuplicate(int $companyId, int $receiptId, ?int $duplicateOfReceiptId, ?string $note, int $actorUserId): bool
    {
        if ($duplicateOfReceiptId !== null) {
            if ($duplicateOfReceiptId === $receiptId) {
                throw new InvalidArgumentException('A receipt cannot duplicate itself.');
            }
            $this->requireReceipt($companyId, $duplicateOfReceiptId);
        }

        return $this->terminalReceipt($companyId, $receiptId, 'duplicate', 'duplicate', [
            'duplicate_of_receipt_id' => $duplicateOfReceiptId,
            'note' => $this->text($note),
        ], $actorUserId);
    }

    /** @return array{success:bool,errors:array<string,string>} */
    public function archiveReceipt(int $companyId, int $receiptId, string $reason, int $actorUserId): array
    {
        $this->requireActor($actorUserId);
        $old = $this->requireReceipt($companyId, $receiptId);
        if ($old['classification_code'] !== 'needs_classification') {
            return ['success' => false, 'errors' => ['receipt' => 'Only a receipt needing classification can be archived.']];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'errors' => ['archive_reason' => 'An archive reason is required.']];
        }
        $now = date('Y-m-d H:i:s');
        $new = ['classification_code' => 'archived', 'archived_at' => $now, 'archived_by' => $actorUserId, 'archive_reason' => $reason, 'classified_by' => $actorUserId, 'classified_at' => $now, 'updated_at' => $now];
        $this->repo()->transaction(function () use ($companyId, $receiptId, $old, $new, $actorUserId): void {
            $this->repo()->updateReceipt($companyId, $receiptId, $new);
            $this->audit('operating_expense_receipts', $receiptId, 'archived', $old, array_merge($old, $new), $actorUserId);
        });

        return ['success' => true, 'errors' => []];
    }

    /** @return array{path:string,metadata:array<string,mixed>} */
    public function receiptFile(int $companyId, int $receiptId): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        $resolved = $this->storage()->resolve($receipt, $this->config()->storageDirectory, $this->config()->allowedMimeTypes);
        if ($resolved === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $resolved;
    }

    public function needsClassificationCount(int $companyId): int
    {
        return $this->repo()->needsClassificationCount($companyId);
    }

    /** @return array{total:int,href:string} */
    public function attentionSummary(int $companyId): array
    {
        return [
            'total' => $this->needsClassificationCount($companyId),
            'href' => '/operations/expenses?view=needs_attention',
        ];
    }

    /** @param array<string, mixed> $data @return array{data:array<string,mixed>,errors:array<string,string>} */
    private function validateExpense(int $companyId, array $data): array
    {
        $errors = [];
        $date = $this->optionalDate((string) ($data['expense_date'] ?? ''));
        if ($date === null) {
            $errors['expense_date'] = 'Expense date is required.';
        }
        $amount = $this->optionalAmount($data['amount'] ?? null);
        if ($amount === null) {
            $errors['amount'] = 'Amount must be greater than zero with no more than two decimal places.';
        }
        $categoryCode = trim((string) ($data['category_code'] ?? ''));
        $category = $this->repo()->category($categoryCode);
        if ($category === null) {
            $errors['category_code'] = 'Choose an operating expense category.';
        }
        $purpose = $this->text($data['business_purpose'] ?? $data['note'] ?? null);
        if ($categoryCode === 'other' && $purpose === null) {
            $errors['business_purpose'] = 'Explain the business purpose for an Other operating expense.';
        }
        $paymentMethod = trim((string) ($data['payment_method_code'] ?? ''));
        if ($paymentMethod !== '' && ! in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            $errors['payment_method_code'] = 'Choose a valid payment method.';
        }
        $paymentReference = $this->text($data['payment_reference'] ?? null, 120);
        if ($paymentReference !== null && preg_match('/(?:\d[ -]*?){13,19}/', $paymentReference) === 1) {
            $errors['payment_reference'] = 'Use a short card label only; do not enter an account or card number.';
        }

        $vehicleId = (int) ($data['fleet_vehicle_id'] ?? 0);
        $tripId = (int) ($data['turo_trip_normalized_id'] ?? 0);
        $vehicleId = $vehicleId > 0 ? $vehicleId : null;
        $tripId = $tripId > 0 ? $tripId : null;
        if ($tripId !== null) {
            $trip = $this->repo()->trip($companyId, $tripId);
            if ($trip === null || (int) ($trip['fleet_vehicle_id'] ?? 0) < 1) {
                throw PageNotFoundException::forPageNotFound();
            }
            $tripVehicleId = (int) $trip['fleet_vehicle_id'];
            if ($vehicleId !== null && $vehicleId !== $tripVehicleId) {
                throw PageNotFoundException::forPageNotFound();
            }
            $vehicleId = $tripVehicleId;
        } elseif ($vehicleId !== null && $this->repo()->vehicle($companyId, $vehicleId) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return [
            'data' => [
                'fleet_vehicle_id' => $vehicleId,
                'turo_trip_normalized_id' => $tripId,
                'expense_category_lookup_value_id' => $category['id'] ?? null,
                'expense_date' => $date,
                'amount' => $amount,
                'vendor' => $this->text($data['vendor'] ?? null, 190),
                'payment_method_code' => $paymentMethod === '' ? null : $paymentMethod,
                'payment_reference' => $paymentReference,
                'business_purpose' => $purpose,
            ],
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $expenseData @return array{success:false,errors:array<string,string>,warning:string,candidates:array<int,array<string,mixed>>}|null */
    private function duplicateWarning(int $companyId, array $expenseData, array $requestData, ?int $exceptId = null): ?array
    {
        if (($requestData['confirm_possible_duplicate'] ?? '0') === '1') {
            return null;
        }
        $candidates = $this->repo()->possibleExpenseDuplicates(
            $companyId,
            (string) $expenseData['expense_date'],
            (string) $expenseData['amount'],
            (int) $expenseData['expense_category_lookup_value_id'],
            $expenseData['vendor'],
            $exceptId,
        );
        if ($candidates === []) {
            return null;
        }

        return [
            'success' => false,
            'errors' => [],
            'warning' => 'A similar recorded expense exists. Review it, then confirm if this is a separate purchase.',
            'candidates' => $candidates,
        ];
    }

    /** @param array<string, mixed> $changes */
    private function terminalReceipt(int $companyId, int $receiptId, string $classification, string $action, array $changes, int $actorUserId): bool
    {
        $this->requireActor($actorUserId);
        $old = $this->requireReceipt($companyId, $receiptId);
        if ($old['classification_code'] !== 'needs_classification') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $new = array_merge($changes, ['classification_code' => $classification, 'classified_by' => $actorUserId, 'classified_at' => $now, 'updated_at' => $now]);

        return (bool) $this->repo()->transaction(function () use ($companyId, $receiptId, $old, $new, $action, $actorUserId): bool {
            $updated = $this->repo()->updateReceipt($companyId, $receiptId, $new);
            if ($updated) {
                $this->audit('operating_expense_receipts', $receiptId, $action, $old, array_merge($old, $new), $actorUserId);
            }

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    private function requireExpense(int $companyId, int $id): array
    {
        $expense = $this->repo()->expense($companyId, $id);
        if ($expense === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $expense;
    }

    /** @return array<string, mixed> */
    private function requireReceipt(int $companyId, int $id): array
    {
        $receipt = $this->repo()->receipt($companyId, $id);
        if ($receipt === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $receipt;
    }

    private function requireActor(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated operator is required.');
        }
    }

    /** @return array<string, mixed> */
    private function store(UploadedFile $upload, ?string $documentDate, int $actorUserId): array
    {
        return $this->storage()->store(
            $upload,
            $this->config()->storageDirectory,
            $this->config()->allowedMimeTypes,
            $this->config()->maxFileSizeBytes,
            $documentDate,
            $actorUserId,
        );
    }

    private function optionalAmount(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        if ($whole === '0' && $fraction === '00') {
            return null;
        }

        return $whole . '.' . $fraction;
    }

    private function optionalDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function text(mixed $value, ?int $maxLength = null): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function allowedFilter(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function vehicleFilter(int $companyId, string $value): string
    {
        if ($value === 'fleet' || $value === '') {
            return $value;
        }
        $vehicleId = (int) $value;

        return $vehicleId > 0 && $this->repo()->vehicle($companyId, $vehicleId) !== null ? (string) $vehicleId : '';
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new */
    private function audit(string $table, int $id, string $action, ?array $old, ?array $new, int $actorUserId): void
    {
        $this->audits()->record($actorUserId, $this->lookup()->valueId('audit_action', $action), $table, $id, $old, $new);
    }

    private function repo(): OperatingExpenseRepository
    {
        return $this->repository ?? Services::operatingExpenseRepository();
    }

    private function audits(): AuditLogRepository
    {
        return $this->auditLogs ?? new AuditLogRepository();
    }

    private function lookup(): LookupRepository
    {
        return $this->lookups ?? new LookupRepository();
    }

    private function storage(): PrivateEvidenceStorageService
    {
        return $this->storage ?? Services::privateEvidenceStorageService();
    }

    private function config(): ExpenseReceipts
    {
        return $this->receiptConfig ?? new ExpenseReceipts();
    }
}
