<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleDamageRepairs extends BaseController
{
    public function index(int $vehicle): string
    {
        return $this->render('index', $this->context($vehicle));
    }
    public function new(int $vehicle): string
    {
        $context = $this->context($vehicle);
        if ((int) $this->request->getGet('selected_item_id') > 0) {
            try {
                $context['aliasPreview'] = Services::vehicleDamageRepairService()->conditionPreview($context['companyId'], $vehicle, (int) $this->request->getGet('selected_item_id'));
            } catch (\InvalidArgumentException) {
                throw PageNotFoundException::forPageNotFound();
            }
        }
        return $this->render('new', $context);
    }

    public function conditionPreview(int $vehicle): string
    {
        $context = $this->context($vehicle);
        try {
            $preview = Services::vehicleDamageRepairService()->conditionPreview($context['companyId'], $vehicle, (int) $this->request->getGet('selected_item_id'));
        } catch (\InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->render('condition_preview', $context + ['preview' => $preview]);
    }

    public function create(int $vehicle): RedirectResponse
    {
        $data = $this->request->getPost();
        $data['conditions'] = is_array($data['conditions'] ?? null) ? array_values(array_filter($data['conditions'], static fn (mixed $row): bool => is_array($row) && ($row['canonical_confirmed'] ?? '') === '1')) : [];
        $result = Services::vehicleDamageRepairService()->createJob($this->company(), $vehicle, $data, $this->actor());
        return $this->result($vehicle, $result, '/fleet/vehicles/' . $vehicle . '/damage-repairs/new');
    }

    public function show(int $vehicle, int $job): string
    {
        return $this->render('show', $this->jobContext($vehicle, $job));
    }
    public function correctDetails(int $v, int $j): RedirectResponse
    {
        return $this->command('correctJobDetails', $v, $j);
    }
    public function addCondition(int $v, int $j): RedirectResponse
    {
        return $this->command('addCondition', $v, $j);
    }
    public function withdrawCondition(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('withdrawCondition', $v, $j, $m);
    }
    public function recordResult(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('recordMembershipResult', $v, $j, $m);
    }
    public function schedule(int $v, int $j): RedirectResponse
    {
        return $this->command('schedule', $v, $j);
    }
    public function start(int $v, int $j): RedirectResponse
    {
        return $this->command('start', $v, $j);
    }
    public function defer(int $v, int $j): RedirectResponse
    {
        return $this->command('defer', $v, $j);
    }
    public function resume(int $v, int $j): RedirectResponse
    {
        return $this->command('resume', $v, $j);
    }
    public function cancel(int $v, int $j): RedirectResponse
    {
        return $this->command('cancel', $v, $j);
    }
    public function complete(int $v, int $j): RedirectResponse
    {
        return $this->command('complete', $v, $j);
    }
    public function reopenJob(int $v, int $j): RedirectResponse
    {
        return $this->command('reopenJob', $v, $j);
    }

    public function repairPreview(int $v, int $j, int $m): string
    {
        $context = $this->jobContext($v, $j);
        $member = $this->ownedMember($context, $m);
        $condition = Services::vehicleDamageRepository()->item($context['companyId'], $v, (int) $member['vehicle_damage_item_id']);
        return $this->render('confirm_repair', $context + ['member' => $member, 'condition' => $condition]);
    }

    public function confirmRepair(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('confirmConditionRepaired', $v, $j, $m, '/fleet/vehicles/' . $v . '/damage-repairs/' . $j . '/conditions/' . $m . '/confirm-repair');
    }

    public function reopenConditionPreview(int $vehicle, int $item): string
    {
        $context = $this->context($vehicle);
        $condition = Services::vehicleDamageRepository()->item($context['companyId'], $vehicle, $item);
        if ($condition === null || $condition['status_code'] !== 'repaired' || $condition['current_condition_item_id'] !== null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $latest = Services::vehicleDamageRepairRepository()->latestConditionEvent($context['companyId'], $item, 'repaired');
        $repair = null;
        if (! empty($latest['repair_job_event_id'])) {
            $event = Services::vehicleDamageRepairRepository()->event($context['companyId'], $vehicle, (int) $latest['repair_job_event_id']);
            if ($event !== null) {
                $job = Services::vehicleDamageRepairRepository()->job($context['companyId'], $vehicle, (int) $event['vehicle_damage_repair_job_id']);
                $repair = ['job' => $job, 'membership_id' => $event['vehicle_damage_repair_job_item_id']];
            }
        }
        return $this->render('reopen_condition', $context + ['condition' => $condition, 'repairContext' => $repair]);
    }

    public function reopenCondition(int $vehicle, int $item): RedirectResponse
    {
        $result = Services::vehicleDamageService()->reopenRepairedCondition($this->company(), $vehicle, $item, $this->request->getPost(), $this->actor());
        return $this->result($vehicle, $result, '/fleet/vehicles/' . $vehicle . '/damage/' . $item . '/reopen', isset($this->request->getPost()['job_id']));
    }

    public function estimateForm(int $v, int $j, ?int $previous = null): string
    {
        $context = $this->jobContext($v, $j);
        Services::vehicleDamageRepairEstimateRepository()->requireReady();
        $estimate = $previous === null ? null : Services::vehicleDamageRepairEstimateRepository()->estimate($context['companyId'], $v, $j, $previous);
        if ($previous !== null && $estimate === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->render('estimate_form', $context + ['previousEstimate' => $estimate]);
    }
    public function documentForm(int $v, int $j): string
    {
        $context = $this->jobContext($v, $j);
        Services::vehicleDamageRepairEstimateRepository()->requireReady();
        return $this->render('document_form', $context);
    }
    public function createEstimate(int $v, int $j): RedirectResponse
    {
        return $this->quoteCommand('createEstimate', $v, $j);
    }
    public function createRevision(int $v, int $j, int $id): RedirectResponse
    {
        return $this->quoteCommand('createRevision', $v, $j, $id);
    }
    public function acceptEstimate(int $v, int $j, int $id): RedirectResponse
    {
        return $this->quoteCommand('acceptEstimate', $v, $j, $id);
    }
    public function rejectEstimate(int $v, int $j, int $id): RedirectResponse
    {
        return $this->quoteCommand('rejectEstimate', $v, $j, $id);
    }
    public function withdrawEstimate(int $v, int $j, int $id): RedirectResponse
    {
        return $this->quoteCommand('withdrawEstimate', $v, $j, $id);
    }
    public function attachDocument(int $v, int $j): RedirectResponse
    {
        return $this->quoteCommand('attachDocument', $v, $j);
    }
    public function archiveDocument(int $v, int $j, int $id): RedirectResponse
    {
        return $this->quoteCommand('archiveDocument', $v, $j, $id);
    }

    public function downloadDocument(int $v, int $j, int $id): \CodeIgniter\HTTP\DownloadResponse
    {
        try {
            $company = $this->company();
            $this->actor();
        } catch (RuntimeException) {
            throw PageNotFoundException::forPageNotFound();
        }
        $repository = Services::vehicleDamageRepairDocumentRepository();
        $document = $repository->document($company, $v, $j, $id);
        $resolved = $document === null ? null : Services::repairDocumentStorageService()->resolve($company, $document, $repository->metadata($document));
        if ($resolved === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $response = $this->response->download($resolved['path'], null)->setFileName(Services::repairDocumentStorageService()->filename($document))
            ->setContentType($resolved['metadata']['mime_type'], '')->setHeader('X-Content-Type-Options', 'nosniff')->setHeader('Cache-Control', 'private, no-store');
        $response->buildHeaders();
        return $response;
    }

    public function recoveryForm(int $v, int $j, ?int $id = null): string
    {
        $context = $this->jobContext($v, $j);
        if (! $context['repairRecoveries']['ready']) {
            throw PageNotFoundException::forPageNotFound();
        }
        $old = null;
        if ($id !== null) {
            $old = array_column($context['repairRecoveries']['entries'], null, 'id')[$id] ?? null;
            if ($old === null || $old['status_code'] !== 'recorded') {
                throw PageNotFoundException::forPageNotFound();
            }
        }
        return $this->render('recovery_form', $context + ['replacement' => $old, 'duplicateReview' => null, 'recoveryClaims' => Services::vehicleDamageRepairRecoveryService()->claimOptions($context['companyId'], $v, $j)]);
    }

    public function recordRecovery(int $v, int $j): RedirectResponse
    {
        return $this->recoveryCommand('recordRecovery', $v, $j);
    }
    public function replaceRecovery(int $v, int $j, int $id): RedirectResponse
    {
        return $this->recoveryCommand('replaceRecovery', $v, $j, $id);
    }
    public function voidRecovery(int $v, int $j, int $id): RedirectResponse
    {
        return $this->recoveryCommand('voidRecovery', $v, $j, $id);
    }
    public function finalizeRecovery(int $v, int $j): RedirectResponse
    {
        return $this->recoveryCommand('finalizeRecovery', $v, $j);
    }
    public function invalidateRecovery(int $v, int $j): RedirectResponse
    {
        return $this->recoveryCommand('invalidateRecoveryFinalization', $v, $j);
    }

    private function recoveryCommand(string $method, int $v, int $j, ?int $id = null): RedirectResponse
    {
        $context = $this->jobContext($v, $j);
        $data = $this->request->getPost();
        if ($id !== null) {
            $data['recovery_entry_id'] = $id;
        }
        $upload = $this->request->getFile('repair_document');
        if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE && (! isset($data['document']) || is_array($data['document']))) {
            $data['document']['upload'] = $upload;
        }
        if (is_array($data['document'] ?? null) && is_string($data['kind_code'] ?? null)) {
            $data['document']['kind_code'] = \Config\VehicleDamageRepairRecoveries::DOCUMENT_KINDS[$data['kind_code']] ?? '';
        }
        if (is_array($data['document_states'] ?? null) && is_scalar($data['repair_document_id'] ?? null)) {
            $data['expected_document_state'] = $data['document_states'][$data['repair_document_id']] ?? '';
        }
        if (isset($data['document']) && is_array($data['document']) && empty($data['document']['upload']) && ! isset($data['document']['descriptor'])) {
            unset($data['document']);
        }
        $retryKey = $this->recoveryRetryKey($v, $j);
        $pending = $context['recoveryRetry'];
        if ($pending !== null) {
            // The retained server-side payload cannot be replaced by a new key or edited form.
            $method = $pending['method'];
            $data = $pending['payload'];
        }
        $result = Services::vehicleDamageRepairService()->{$method}($this->company(), $v, $j, $data, $this->actor());
        if (($result['uncertain'] ?? false) && isset($result['retry_payload'])) {
            // Recover a committed receipt with the original key/state and frozen source identity.
            // No moved upload is re-read, and an uncommitted new source cannot be recreated here.
            $recovered = Services::vehicleDamageRepairService()->{$method}($this->company(), $v, $j, $result['retry_payload'], $this->actor());
            if ($recovered['success']) {
                $result = $recovered;
            }
        }
        $base = '/fleet/vehicles/' . $v . '/damage-repairs/' . $j;
        if (($result['uncertain'] ?? false) || ($pending !== null && ! $result['success'])) {
            CoreServices::session()->set($retryKey, $pending ?? ['method' => $method, 'payload' => $result['retry_payload']]);
            return CoreServices::redirectresponse()->to($base)->with('damage_work_notice', 'Save outcome still needs verification. Retry the original command below with its retained key and source identity.');
        }
        if ($result['success']) {
            CoreServices::session()->remove($retryKey);
        }
        $failure = $method === 'recordRecovery' ? $base . '/recoveries/new' : ($method === 'replaceRecovery' ? $base . '/recoveries/' . $id . '/replacement' : $base);
        return $this->result($v, $result, $failure);
    }

    private function recoveryRetryKey(int $v, int $j): string
    {
        return 'damage_recovery_retry_' . $this->company() . '_' . $v . '_' . $j . '_' . $this->actor();
    }

    public function costForm(int $v, int $j, ?int $id = null): string
    {
        $context = $this->jobContext($v, $j);
        if (! $context['repairCosts']['ready']) {
            throw PageNotFoundException::forPageNotFound();
        }
        $old = null;
        if ($id !== null) {
            $old = array_column($context['repairCosts']['entries'], null, 'id')[$id] ?? null;
            if ($old === null || $old['status_code'] !== 'recorded') {
                throw PageNotFoundException::forPageNotFound();
            }
        }
        return $this->render('cost_form', $context + ['replacement' => $old, 'duplicateReview' => null]);
    }

    public function recordCost(int $v, int $j): RedirectResponse
    {
        return $this->costCommand('recordCostEntry', $v, $j);
    }
    public function replaceCost(int $v, int $j, int $id): RedirectResponse
    {
        return $this->costCommand('replaceCostEntry', $v, $j, $id);
    }
    public function voidCost(int $v, int $j, int $id): RedirectResponse
    {
        return $this->costCommand('voidCostEntry', $v, $j, $id);
    }
    public function finalizeCost(int $v, int $j): RedirectResponse
    {
        return $this->costCommand('finalizeRepairCost', $v, $j);
    }
    public function invalidateCost(int $v, int $j): RedirectResponse
    {
        return $this->costCommand('invalidateCostFinalization', $v, $j);
    }

    private function costCommand(string $method, int $v, int $j, ?int $id = null): RedirectResponse
    {
        $context = $this->jobContext($v, $j);
        $data = $this->request->getPost();
        if ($id !== null) {
            $data['cost_entry_id'] = $id;
        }
        $upload = $this->request->getFile('repair_document');
        if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE && (! isset($data['document']) || is_array($data['document']))) {
            $data['document']['upload'] = $upload;
        }
        if (is_array($data['document'] ?? null) && is_string($data['kind_code'] ?? null)) {
            $data['document']['kind_code'] = \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$data['kind_code']] ?? '';
        }
        if (is_array($data['document_states'] ?? null) && is_scalar($data['repair_document_id'] ?? null)) {
            $data['expected_document_state'] = $data['document_states'][$data['repair_document_id']] ?? '';
        }
        if (isset($data['document']) && is_array($data['document']) && empty($data['document']['upload']) && ! isset($data['document']['descriptor'])) {
            unset($data['document']);
        }
        $retryKey = $this->costRetryKey($v, $j);
        $pending = $context['costRetry'];
        if ($pending !== null) {
            // The retained server-side payload cannot be replaced by a new key or edited form.
            $method = $pending['method'];
            $data = $pending['payload'];
        }
        $result = Services::vehicleDamageRepairService()->{$method}($this->company(), $v, $j, $data, $this->actor());
        if (($result['uncertain'] ?? false) && isset($result['retry_payload'])) {
            // Recover a committed receipt with the original key/state and frozen source identity.
            // No moved upload is re-read, and an uncommitted new source cannot be recreated here.
            $recovered = Services::vehicleDamageRepairService()->{$method}($this->company(), $v, $j, $result['retry_payload'], $this->actor());
            if ($recovered['success']) {
                $result = $recovered;
            }
        }
        $base = '/fleet/vehicles/' . $v . '/damage-repairs/' . $j;
        if (($result['uncertain'] ?? false) || ($pending !== null && ! $result['success'])) {
            CoreServices::session()->set($retryKey, $pending ?? ['method' => $method, 'payload' => $result['retry_payload']]);
            return CoreServices::redirectresponse()->to($base)->with('damage_work_notice', 'Save outcome still needs verification. Retry the original command below with its retained key and source identity.');
        }
        if ($result['success']) {
            CoreServices::session()->remove($retryKey);
        }
        $failure = $method === 'recordCostEntry' ? $base . '/costs/new' : ($method === 'replaceCostEntry' ? $base . '/costs/' . $id . '/replacement' : $base);
        return $this->result($v, $result, $failure);
    }

    private function costRetryKey(int $v, int $j): string
    {
        return 'damage_cost_retry_' . $this->company() . '_' . $v . '_' . $j . '_' . $this->actor();
    }

    /** POST preview validates user facts but never stores or moves the selected upload. */
    public function reviewCost(int $v, int $j): string
    {
        $context = $this->jobContext($v, $j);
        $service = Services::vehicleDamageRepairCostService();
        $service->costs->requireReady();
        $data = $this->request->getPost();
        $duplicateReview = null;
        $old = null;
        try {
            $upload = $this->request->getFile('repair_document');
            if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                if (isset($data['document']) && ! is_array($data['document'])) {
                    throw new \InvalidArgumentException('Choose a valid source.');
                }
                $data['document']['upload'] = $upload;
                if (is_string($data['kind_code'] ?? null)) {
                    $data['document']['kind_code'] = \Config\VehicleDamageRepairCosts::DOCUMENT_KINDS[$data['kind_code']] ?? '';
                }
            }
            $data = $service->normalize($data, $context['companyId'], $v, $j);
            $amount = \App\Services\Fleet\RepairEstimateMoney::normalize($data['amount'] ?? null);
            $checksum = $data['document']['descriptor']['checksum'] ?? null;
            if (! empty($data['repair_document_id'])) {
                $doc = $service->sources->documents->documents->document($context['companyId'], $v, $j, (int) $data['repair_document_id']);
                if ($doc === null) {
                    throw new \InvalidArgumentException('Owned document not found.');
                }
                $checksum = $doc['content_checksum'];
            }
            $duplicateReview = $service->duplicates($context['companyId'], $v, $j, ['amount' => $amount, 'occurred_on' => \App\Services\Fleet\VehicleDamageRepairDocumentService::text($data['occurred_on'] ?? null, 10, true), 'vendor_reference' => \App\Services\Fleet\VehicleDamageRepairDocumentService::text($data['vendor_reference'] ?? null, 120)], $checksum, (int) ($data['cost_entry_id'] ?? 0));
            if (! empty($data['cost_entry_id'])) {
                $old = array_column($context['repairCosts']['entries'], null, 'id')[(int) $data['cost_entry_id']] ?? null;
                if ($old === null || $old['status_code'] !== 'recorded') {
                    throw new \InvalidArgumentException('Recorded correction target not found.');
                }
            }
        } catch (\InvalidArgumentException $e) {
            $context['errors'] = ['cost' => $e->getMessage()];
        }
        unset($data['document']['upload']);
        $context['formData'] = $data;
        return $this->render('cost_form', $context + ['replacement' => $old, 'duplicateReview' => $duplicateReview]);
    }

    private function quoteCommand(string $method, int $v, int $j, ?int $id = null): RedirectResponse
    {
        $this->jobContext($v, $j);
        $data = $this->request->getPost();
        if ($id !== null) {
            $data[$method === 'createRevision' ? 'previous_estimate_id' : ($method === 'archiveDocument' ? 'document_id' : 'estimate_id')] = $id;
        }
        $upload = $this->request->getFile('repair_document');
        if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE && (! isset($data['document']) || is_array($data['document']))) {
            $data['document']['upload'] = $upload;
        }
        if (isset($data['document']) && is_array($data['document']) && empty($data['document']['upload']) && empty($data['document']['source_document_id']) && empty($data['document']['external_reference'])) {
            unset($data['document']);
        }
        $result = Services::vehicleDamageRepairService()->{$method}($this->company(), $v, $j, $data, $this->actor());
        return $this->result($v, $result, '/fleet/vehicles/' . $v . '/damage-repairs/' . $j);
    }

    /** POST preview reads sources only; the upload remains untouched until the material command. */
    public function reviewRecovery(int $v, int $j): string
    {
        $context = $this->jobContext($v, $j);
        $service = Services::vehicleDamageRepairRecoveryService();
        $service->recoveries->requireReady();
        $data = $this->request->getPost();
        $review = null;
        $old = null;
        try {
            $upload = $this->request->getFile('repair_document');
            if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                if (isset($data['document']) && ! is_array($data['document'])) {
                    throw new \InvalidArgumentException('Choose valid recovery evidence.');
                }
                $data['document']['upload'] = $upload;
                $data['document']['kind_code'] = is_string($data['kind_code'] ?? null) ? (\Config\VehicleDamageRepairRecoveries::DOCUMENT_KINDS[$data['kind_code']] ?? '') : '';
            }
            $data = $service->normalize($data, $context['companyId'], $v, $j);
            $facts = $service->facts($data);
            $checksum = $data['document']['descriptor']['checksum'] ?? null;
            if (! empty($data['repair_document_id'])) {
                $doc = $service->verifyDocument($context['companyId'], $v, $j, (int) $data['repair_document_id'], $facts['kind_code']);
                $checksum = $doc['content_checksum'];
            }
            if (! empty($data['recovery_entry_id'])) {
                $old = array_column($context['repairRecoveries']['entries'], null, 'id')[(int) $data['recovery_entry_id']] ?? null;
                if ($old === null || $old['status_code'] !== 'recorded') {
                    throw new \InvalidArgumentException('Recorded recovery correction target not found.');
                }
            }
            $review = $service->duplicates($context['companyId'], $facts, $checksum, (int) ($old['id'] ?? 0));
        } catch (\InvalidArgumentException $e) {
            $context['errors'] = ['recovery' => $e->getMessage()];
        }
        // Invalid multipart shapes must also be safe to redisplay after rejection.
        $scalars = static fn (array $fields): array => array_filter($fields, static fn (mixed $value): bool => $value === null || is_scalar($value));
        $context['formData'] = $scalars($data);
        if (is_array($data['document'] ?? null)) {
            $context['formData']['document'] = $scalars(array_intersect_key($data['document'], array_flip(['label', 'note'])));
            if (is_array($data['document']['descriptor'] ?? null)) {
                $context['formData']['document']['descriptor'] = $scalars($data['document']['descriptor']);
            }
        }
        return $this->render('recovery_form', $context + ['replacement' => $old, 'duplicateReview' => $review, 'recoveryClaims' => $service->claimOptions($context['companyId'], $v, $j)]);
    }

    private function command(string $method, int $vehicle, int $job, ?int $member = null, ?string $failure = null): RedirectResponse
    {
        $data = $this->request->getPost();
        if ($member !== null) {
            $data['membership_id'] = $member;
        }
        $result = Services::vehicleDamageRepairService()->{$method}($this->company(), $vehicle, $job, $data, $this->actor());
        return $this->result($vehicle, $result, $failure ?? '/fleet/vehicles/' . $vehicle . '/damage-repairs/' . $job);
    }

    private function result(int $vehicle, array $result, string $failure, bool $jobResult = true): RedirectResponse
    {
        if (! $result['success']) {
            return CoreServices::redirectresponse()->to($failure)->with('damage_work_errors', $result['errors'])->with('damage_work_data', $this->request->getPost());
        }
        $target = $jobResult ? '/fleet/vehicles/' . $vehicle . '/damage-repairs/' . $result['id'] : '/fleet/vehicles/' . $vehicle . '#vehicle-damage';
        $message = ($result['replayed'] ?? false) ? 'Already saved. This retry created no additional history.' : ($jobResult ? 'Work history saved. Review physical condition separately.' : 'Condition reopened. Prior repair history is preserved.');
        return CoreServices::redirectresponse()->to($target)->with($jobResult ? 'damage_work_notice' : 'vehicle_damage_notice', $message);
    }

    private function context(int $vehicle): array
    {
        $company = $this->company();
        $owned = Services::vehicleDamageRepository()->vehicle($company, $vehicle);
        if ($owned === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        Services::vehicleDamageRepairRepository()->requireReady();
        $damage = Services::vehicleDamageReadService()->workspace($company, $vehicle);
        $conditions = array_merge($damage['current'], $damage['history']);
        return ['companyId' => $company, 'vehicle' => $owned, 'vehicleDamage' => $damage, 'work' => $damage['work'], 'conditionsById' => array_column($conditions, null, 'id'), 'fingerprints' => Services::vehicleDamageRepairRepository()->fingerprints($company, $conditions), 'vendors' => Services::vehicleDamageRepairRepository()->vendors($company), 'assets' => Services::assetManifestService()->appAssets(), 'notice' => CoreServices::session()->getFlashdata('damage_work_notice'), 'errors' => CoreServices::session()->getFlashdata('damage_work_errors') ?? [], 'formData' => CoreServices::session()->getFlashdata('damage_work_data') ?? []];
    }

    private function jobContext(int $vehicle, int $job): array
    {
        $context = $this->context($vehicle);
        $record = Services::vehicleDamageRepairRepository()->job($context['companyId'], $vehicle, $job);
        if ($record === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $estimateRepository = Services::vehicleDamageRepairEstimateRepository();
        $b22Ready = $estimateRepository->ready();
        $estimates = $b22Ready ? $estimateRepository->estimates($context['companyId'], $vehicle, $job) : [];
        $documents = $b22Ready ? Services::vehicleDamageRepairDocumentRepository()->documents($context['companyId'], $vehicle, $job) : [];
        $estimateStates = [];
        $documentStates = [];
        foreach ($estimates as $estimate) {
            $estimateStates[$estimate['id']] = $estimateRepository->fingerprint($context['companyId'], $vehicle, $job, (int) $estimate['id']);
        }
        foreach ($documents as $document) {
            $documentStates[$document['id']] = Services::vehicleDamageRepairDocumentRepository()->fingerprint($context['companyId'], $vehicle, $job, (int) $document['id']);
        }
        return $context + ['recoveryRetry' => CoreServices::session()->get($this->recoveryRetryKey($vehicle, $job)), 'repairRecoveries' => Services::vehicleDamageRepairRecoveryReadService()->workspace($context['companyId'], $vehicle, $job), 'costRetry' => CoreServices::session()->get($this->costRetryKey($vehicle, $job)), 'repairCosts' => Services::vehicleDamageRepairCostReadService()->workspace($context['companyId'], $vehicle, $job), 'b22Ready' => $b22Ready, 'estimates' => $estimates, 'repairDocuments' => $documents, 'estimateStates' => $estimateStates, 'documentStates' => $documentStates, 'estimateScopes' => $b22Ready ? $estimateRepository->scope($context['companyId'], $job) : [], 'job' => $record, 'members' => Services::vehicleDamageRepairRepository()->members($context['companyId'], $vehicle, $job), 'events' => Services::vehicleDamageRepairRepository()->events($context['companyId'], $vehicle, $job)];
    }

    private function ownedMember(array $context, int $id): array
    {
        foreach ($context['members'] as $member) {
            if ((int) $member['id'] === $id && $member['withdrawn_at'] === null) {
                return $member;
            }
        }
        throw PageNotFoundException::forPageNotFound();
    }

    private function company(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Work requires exactly one active fleet company.');
        }
        return $ids[0];
    }

    private function actor(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }
        return (int) $user->id;
    }

    private function render(string $template, array $data): string
    {
        return CoreServices::renderer()->setData($data + ['template' => $template])->render('vehicle_damage_repairs/page');
    }
}
