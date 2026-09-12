<?php

namespace App\Services\Fleet;

use App\Repositories\TuroAccessReimbursementRepository;
use App\Services\Files\PrivateFileStorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\TuroAccess;

class TuroAccessReimbursementService
{
    public function __construct(
        private readonly ?TuroAccessReimbursementRepository $repository = null,
        private readonly ?PrivateFileStorageService $fileStorage = null,
        private readonly ?TuroAccess $config = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function createIncident(int $companyId, int $workflowId, array $data, bool $confirmDuplicate = false): array
    {
        $workflow = $this->requireWorkflow($companyId, $workflowId);

        $ticketNumber = trim((string) ($data['ticket_number'] ?? ''));
        if (! $confirmDuplicate && $this->repo()->possibleDuplicate($companyId, $workflowId, $ticketNumber === '' ? null : $ticketNumber) !== null) {
            return $this->failed('possible_duplicate', 'A Turo Access override incident may already exist for this airport movement. Confirm duplicate if this is a separate ticket.');
        }

        $amount = $this->amount($data['parking_amount_paid'] ?? null);
        $amounts = $this->amounts($amount);
        $incidentId = $this->repo()->transaction(fn (): int => $this->createIncidentAndException($companyId, $workflowId, $workflow, $data, $amounts, $ticketNumber));

        return ['success' => true, 'incident_id' => $incidentId, 'message' => 'Turo Access override incident recorded.'];
    }

    /** @param array<string, mixed> $workflow @param array<string, mixed> $data @param array<string, float> $amounts */
    private function createIncidentAndException(int $companyId, int $workflowId, array $workflow, array $data, array $amounts, string $ticketNumber): int
    {
        $amount = $this->amount($data['parking_amount_paid'] ?? null);
        $incidentId = $this->repo()->createIncident($companyId, array_merge($amounts, [
            'airport_movement_workflow_id' => $workflowId,
            'turo_trip_normalized_id' => (int) $workflow['turo_trip_normalized_id'],
            'fleet_vehicle_id' => (int) $workflow['fleet_vehicle_id'],
            'movement_type' => (string) $workflow['movement_type'],
            'incident_stage' => 'reported',
            'claim_status' => 'not_ready',
            'incident_context' => in_array($data['incident_context'] ?? '', ['entry', 'exit'], true) ? (string) $data['incident_context'] : 'unknown',
            'operator_type' => in_array($data['operator_type'] ?? '', ['guest', 'host', 'other', 'unknown'], true) ? (string) $data['operator_type'] : 'unknown',
            'incident_at' => $data['incident_at'] ?? date('Y-m-d H:i:s'),
            'ticket_number' => $ticketNumber === '' ? null : $ticketNumber,
            'parking_entry_at' => $data['parking_entry_at'] ?? null,
            'parking_exit_at' => $data['parking_exit_at'] ?? null,
            'parking_amount_paid' => $amount,
            'payment_at' => $data['payment_at'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'operator_note' => $data['operator_note'] ?? null,
        ]));

        if (! $this->repo()->recordAirportException($companyId, $workflowId, 'Turo Access was overridden by a physical parking ticket.')) {
            throw new \RuntimeException('Airport incident exception could not be recorded.');
        }

        return $incidentId;
    }

    public function attachReceipt(int $companyId, int $incidentId, array $data): bool
    {
        $incident = $this->requireIncident($companyId, $incidentId);
        $amount = $this->amount($data['amount'] ?? null);
        $this->repo()->createReceipt($companyId, [
            'airport_turo_access_override_incident_id' => $incidentId,
            'turo_trip_normalized_id' => $incident['turo_trip_normalized_id'],
            'fleet_vehicle_id' => $incident['fleet_vehicle_id'],
            'file_id' => $data['file_id'] ?? null,
            'attachment_type' => $data['attachment_type'] ?? 'paid_receipt',
            'receipt_classification' => 'trip_reimbursement',
            'original_filename' => $data['original_filename'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'amount' => $amount,
            'ticket_number' => $data['ticket_number'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return $this->refreshClaimReadiness($companyId, $incidentId);
    }

    public function uploadReceiptForIncident(int $companyId, int $incidentId, UploadedFile $upload, array $data): array
    {
        $this->requireIncident($companyId, $incidentId);
        $stored = $this->files()->storeReceiptEvidence($upload, $data['document_date'] ?? null);
        $ok = $this->attachReceipt($companyId, $incidentId, array_merge($data, [
            'file_id' => $stored['file_id'],
            'original_filename' => $stored['file']['original_filename'] ?? null,
            'mime_type' => $stored['file']['mime_type'] ?? null,
        ]));

        return ['success' => $ok, 'duplicate_file' => (bool) $stored['duplicate'], 'file_id' => $stored['file_id']];
    }

    public function createUnmatchedReceipt(int $companyId, array $data): int
    {
        return $this->repo()->createReceipt($companyId, [
            'fleet_vehicle_id' => isset($data['fleet_vehicle_id']) ? (int) $data['fleet_vehicle_id'] : null,
            'file_id' => $data['file_id'] ?? null,
            'attachment_type' => $data['attachment_type'] ?? 'paid_receipt',
            'receipt_classification' => $this->receiptClassification($data['receipt_classification'] ?? 'unresolved'),
            'original_filename' => $data['original_filename'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'amount' => $this->amount($data['amount'] ?? null),
            'ticket_number' => $data['ticket_number'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }

    public function uploadUnmatchedReceipt(int $companyId, UploadedFile $upload, array $data): array
    {
        $this->repo()->validateReceiptRelationships($companyId, $data);
        $stored = $this->files()->storeReceiptEvidence($upload, $data['document_date'] ?? null);
        $receiptId = $this->createUnmatchedReceipt($companyId, array_merge($data, [
            'file_id' => $stored['file_id'],
            'original_filename' => $stored['file']['original_filename'] ?? null,
            'mime_type' => $stored['file']['mime_type'] ?? null,
        ]));

        return ['success' => true, 'receipt_id' => $receiptId, 'duplicate_file' => (bool) $stored['duplicate'], 'candidates' => $this->candidateTripsForReceipt($companyId, $receiptId)];
    }

    /** @return array<string, mixed> */
    public function createOperationsRun(int $companyId, array $data): array
    {
        $runDate = (string) ($data['run_date'] ?? $data['document_date'] ?? date('Y-m-d'));
        $startMileage = $this->mileage($data['starting_mileage'] ?? null);
        $endMileage = $this->mileage($data['ending_mileage'] ?? null);
        $businessMiles = $this->mileage($data['business_miles'] ?? null);
        if ($businessMiles === null && $startMileage !== null && $endMileage !== null && $endMileage >= $startMileage) {
            $businessMiles = round($endMileage - $startMileage, 1);
        }

        $runId = $this->repo()->transaction(function () use ($companyId, $data, $runDate, $startMileage, $endMileage, $businessMiles): int {
            $runId = $this->repo()->createOperationsRun($companyId, [
                'run_date' => $runDate,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'chase_vehicle_type' => $this->chaseVehicleType($data['chase_vehicle_type'] ?? 'personal_vehicle'),
                'chase_fleet_vehicle_id' => isset($data['chase_fleet_vehicle_id']) && (int) $data['chase_fleet_vehicle_id'] > 0 ? (int) $data['chase_fleet_vehicle_id'] : null,
                'chase_vehicle_description' => $data['chase_vehicle_description'] ?? null,
                'operator_name' => $data['operator_name'] ?? null,
                'purpose' => trim((string) ($data['purpose'] ?? 'Airport operations')),
                'airport_id' => isset($data['airport_id']) && (int) $data['airport_id'] > 0 ? (int) $data['airport_id'] : null,
                'starting_location' => $data['starting_location'] ?? null,
                'ending_location' => $data['ending_location'] ?? null,
                'starting_mileage' => $startMileage,
                'ending_mileage' => $endMileage,
                'business_miles' => $businessMiles,
                'notes' => $data['notes'] ?? null,
                'run_status' => in_array($data['run_status'] ?? 'open', ['open', 'complete', 'cancelled'], true) ? (string) ($data['run_status'] ?? 'open') : 'open',
            ]);

            foreach ($this->activityPayloads($data) as $activity) {
                $this->repo()->createRunActivity($companyId, $runId, $activity);
            }

            return $runId;
        });

        return ['success' => true, 'run_id' => $runId, 'message' => 'Airport operations run recorded.'];
    }

    /** @return array<string, mixed> */
    public function assignReceiptToOperationsExpense(int $companyId, int $receiptId, array $data): array
    {
        $result = $this->repo()->transaction(function () use ($companyId, $receiptId, $data): array {
            $receipt = $this->requireReceipt($companyId, $receiptId);
            if (($receipt['airport_turo_access_override_incident_id'] ?? null) !== null) {
                return $this->failed('already_claim_receipt', 'This receipt is already linked to a trip reimbursement claim. Split it before assigning an operations expense.');
            }

            $runId = $this->runIdFromData($companyId, $data);
            if ($runId !== null && $this->repo()->operationsRun($companyId, $runId) === null) {
                throw PageNotFoundException::forPageNotFound();
            }
            $expenseId = $this->repo()->createOperationsExpense($companyId, [
                'airport_operations_run_id' => $runId,
                'airport_turo_access_receipt_id' => $receiptId,
                'expense_category' => $this->expenseCategory($data['expense_category'] ?? 'parking'),
                'amount' => $this->amount($data['amount'] ?? $receipt['amount'] ?? null) ?? 0.0,
                'expense_date' => $data['expense_date'] ?? $receipt['document_date'] ?? date('Y-m-d'),
                'vendor' => $data['vendor'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'file_id' => $receipt['file_id'] ?? null,
                'business_purpose_note' => trim((string) ($data['business_purpose_note'] ?? $data['note'] ?? 'Airport operations expense')),
                'is_reimbursable' => ($data['is_reimbursable'] ?? '0') === '1' ? 1 : 0,
                'reimbursement_source' => $data['reimbursement_source'] ?? null,
                'accounting_status' => $this->accountingStatus($data['accounting_status'] ?? 'unreviewed'),
            ]);
            if (! $this->repo()->linkReceiptToOperationsExpense($companyId, $receiptId, $expenseId, $runId, (string) ($data['classification_note'] ?? 'Assigned to airport operations expense.'))) {
                throw new \RuntimeException('Receipt could not be linked to the operations expense.');
            }

            return ['success' => true, 'expense_id' => $expenseId, 'run_id' => $runId, 'message' => 'Receipt assigned to airport operations expense.'];
        });

        return is_array($result) ? $result : $this->failed('expense_assignment_failed', 'Receipt could not be assigned to an airport run.');
    }

    /** @return array<string, mixed> */
    public function uploadAirportRunExpense(int $companyId, UploadedFile $upload, array $data): array
    {
        $this->repo()->validateReceiptRelationships($companyId, $data);
        $runId = isset($data['airport_operations_run_id']) ? (int) $data['airport_operations_run_id'] : 0;
        if ($runId > 0 && $this->repo()->operationsRun($companyId, $runId) === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $stored = $this->files()->storeReceiptEvidence($upload, $data['expense_date'] ?? $data['document_date'] ?? null);
        $receiptId = $this->createUnmatchedReceipt($companyId, array_merge($data, [
            'receipt_classification' => 'unresolved',
            'file_id' => $stored['file_id'],
            'original_filename' => $stored['file']['original_filename'] ?? null,
            'mime_type' => $stored['file']['mime_type'] ?? null,
            'document_date' => $data['expense_date'] ?? $data['document_date'] ?? null,
        ]));
        $assigned = $this->assignReceiptToOperationsExpense($companyId, $receiptId, $data);

        return array_merge($assigned, ['receipt_id' => $receiptId, 'duplicate_file' => (bool) $stored['duplicate'], 'file_id' => $stored['file_id']]);
    }

    /** @return array<string, mixed> */
    public function classifyReceipt(int $companyId, int $receiptId, string $classification, ?string $note = null): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        $classification = $this->receiptClassification($classification);
        if ($classification === 'trip_reimbursement' && ($receipt['airport_operations_expense_id'] ?? null) !== null) {
            return $this->failed('already_operations_expense', 'This receipt is already assigned to an operations expense. Split it before creating a reimbursement claim.');
        }

        return ['success' => $this->repo()->classifyReceipt($companyId, $receiptId, $classification, null, $note), 'message' => 'Receipt classification saved.'];
    }

    /** @param array<int, array<string, mixed>> $allocations */
    public function allocateOperationsExpense(int $companyId, int $expenseId, array $allocations): bool
    {
        if ($this->repo()->operationsExpense($companyId, $expenseId) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->repo()->replaceExpenseAllocations($companyId, $expenseId, array_map(function (array $allocation): array {
            return [
                'fleet_vehicle_id' => isset($allocation['fleet_vehicle_id']) && (int) $allocation['fleet_vehicle_id'] > 0 ? (int) $allocation['fleet_vehicle_id'] : null,
                'allocation_method' => in_array($allocation['allocation_method'] ?? 'unallocated', ['equal_split', 'manual_amount', 'manual_percentage', 'unallocated'], true) ? (string) $allocation['allocation_method'] : 'unallocated',
                'allocated_amount' => $this->amount($allocation['allocated_amount'] ?? 0) ?? 0.0,
                'allocated_percentage' => $this->amount($allocation['allocated_percentage'] ?? null),
                'note' => $allocation['note'] ?? null,
            ];
        }, $allocations));
    }

    /** @return array<string, mixed> */
    public function splitReceipt(int $companyId, int $receiptId, array $data): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        $splitId = $this->repo()->createReceiptSplit($companyId, [
            'airport_turo_access_receipt_id' => $receiptId,
            'original_receipt_total' => $this->amount($data['original_receipt_total'] ?? $receipt['amount'] ?? 0) ?? 0.0,
            'reimbursement_portion_amount' => $this->amount($data['reimbursement_portion_amount'] ?? 0) ?? 0.0,
            'operations_expense_portion_amount' => $this->amount($data['operations_expense_portion_amount'] ?? 0) ?? 0.0,
            'remaining_unclassified_amount' => $this->amount($data['remaining_unclassified_amount'] ?? 0) ?? 0.0,
            'note' => $data['note'] ?? null,
        ]);

        return $splitId === false ? $this->failed('split_not_reconciled', 'Split portions must reconcile to the original receipt total.') : ['success' => true, 'split_id' => $splitId, 'message' => 'Receipt split recorded.'];
    }

    public function linkReceiptToWorkflow(int $companyId, int $receiptId, int $workflowId, bool $confirmNonAirport = false): array
    {
        $result = $this->repo()->transaction(function () use ($companyId, $receiptId, $workflowId): array {
            $receipt = $this->requireReceipt($companyId, $receiptId);
            $workflow = $this->requireWorkflow($companyId, $workflowId);

            $incident = $this->repo()->existingIncidentForWorkflow($companyId, $workflowId, $receipt['ticket_number'] ?? null);
            if ($incident === null) {
                $created = $this->createIncident($companyId, $workflowId, [
                    'ticket_number' => $receipt['ticket_number'] ?? null,
                    'parking_amount_paid' => $receipt['amount'] ?? null,
                    'incident_at' => $receipt['document_date'] ?? date('Y-m-d'),
                    'operator_note' => 'Created from matched airport receipt.',
                ], true);
                if (! ($created['success'] ?? false)) {
                    return $created;
                }
                $incident = $this->repo()->incident($companyId, (int) $created['incident_id']);
            }

            if ($incident === null || ! $this->repo()->linkReceiptToIncident($companyId, $receiptId, (int) $incident['id'])) {
                throw new \RuntimeException('Receipt could not be linked to the incident.');
            }
            $this->refreshClaimReadiness($companyId, (int) $incident['id']);

            return ['success' => true, 'incident_id' => (int) $incident['id'], 'message' => 'Receipt linked to airport trip and claim readiness refreshed.'];
        });

        return is_array($result) ? $result : $this->failed('match_failed', 'Receipt could not be linked.');
    }

    public function updateReceiptMetadata(int $companyId, int $receiptId, array $data): bool
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        $ok = $this->repo()->updateReceipt($companyId, $receiptId, [
            'attachment_type' => $data['attachment_type'] ?? $receipt['attachment_type'],
            'document_date' => $data['document_date'] ?? $receipt['document_date'],
            'amount' => $this->amount($data['amount'] ?? $receipt['amount']),
            'ticket_number' => $data['ticket_number'] ?? $receipt['ticket_number'],
            'note' => $data['note'] ?? $receipt['note'],
        ], 'receipt_metadata_updated');
        if ($ok && $receipt['airport_turo_access_override_incident_id'] !== null) {
            $this->refreshClaimReadiness($companyId, (int) $receipt['airport_turo_access_override_incident_id']);
        }

        return $ok;
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateTripsForReceipt(int $companyId, int $receiptId): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        if (($receipt['document_date'] ?? null) === null) {
            return [];
        }

        return $this->rankCandidates($receipt, $this->repo()->candidateAirportTrips($companyId, $receipt['fleet_vehicle_id'] === null ? null : (int) $receipt['fleet_vehicle_id'], (string) $receipt['document_date']));
    }

    /** @return array<int, array<string, mixed>> */
    public function searchCandidates(int $companyId, int $receiptId, string $query): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);
        if (trim($query) === '') {
            return [];
        }

        return $this->rankCandidates($receipt, $this->repo()->searchAirportTrips($companyId, trim($query)));
    }

    /** @return array<string, mixed> */
    public function matchingWorkspace(int $companyId, int $receiptId, ?string $query = null): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);

        return [
            'exists' => true,
            'receipt' => $receipt,
            'candidates' => $query === null || trim($query) === '' ? $this->candidateTripsForReceipt($companyId, $receiptId) : $this->searchCandidates($companyId, $receiptId, $query),
            'operation_runs' => $this->candidateOperationsRunsForReceipt($companyId, $receiptId),
            'summary' => $this->attentionSummary($companyId),
            'query' => $query ?? '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateOperationsRunsForReceipt(int $companyId, int $receiptId): array
    {
        $receipt = $this->requireReceipt($companyId, $receiptId);

        return array_map(fn (array $run): array => $this->operationsRunCandidateView($receipt, $run), $this->repo()->candidateOperationsRuns($companyId, $receipt['document_date'] ?? null, $receipt['fleet_vehicle_id'] === null ? null : (int) $receipt['fleet_vehicle_id']));
    }

    public function markFiled(int $companyId, int $incidentId, string $reference, ?string $claimedAmount = null): bool
    {
        $this->requireIncident($companyId, $incidentId);
        $amount = $this->amount($claimedAmount);
        return $this->repo()->updateIncident($companyId, $incidentId, ['claim_status' => 'filed', 'incident_stage' => 'claim_filed', 'claim_filed_on' => date('Y-m-d'), 'claim_reference' => $reference, 'claimed_amount' => $amount], 'claim_filed');
    }

    public function markReimbursed(int $companyId, int $incidentId, ?string $amount): bool
    {
        $this->requireIncident($companyId, $incidentId);
        return $this->repo()->updateIncident($companyId, $incidentId, ['claim_status' => 'reimbursed', 'incident_stage' => 'reimbursed', 'reimbursed_amount' => $this->amount($amount), 'reimbursed_on' => date('Y-m-d')], 'reimbursed');
    }

    public function deny(int $companyId, int $incidentId, string $reason): bool
    {
        $this->requireIncident($companyId, $incidentId);
        return $this->repo()->updateIncident($companyId, $incidentId, ['claim_status' => 'denied', 'incident_stage' => 'denied', 'denial_reason' => $reason], 'denied');
    }

    /** @return array<string, mixed> */
    public function inbox(int $companyId): array
    {
        $incidents = array_map(fn (array $incident): array => $this->incidentView($companyId, $incident), $this->repo()->inbox($companyId));
        $unmatched = $this->repo()->unmatchedReceipts($companyId);
        return ['incidents' => $incidents, 'unmatched_receipts' => $unmatched, 'summary' => $this->attentionSummary($companyId)];
    }

    /** @return array<string, int|bool|string|float> */
    public function attentionSummary(int $companyId): array
    {
        $inbox = $this->repo()->inbox($companyId);
        $ready = array_values(array_filter($inbox, static fn (array $incident): bool => ($incident['claim_status'] ?? '') === 'ready_to_file'));
        $filed = array_values(array_filter($inbox, static fn (array $incident): bool => ($incident['claim_status'] ?? '') === 'filed'));
        $unmatched = $this->repo()->unmatchedReceipts($companyId);
        $expected = array_reduce($ready, static fn (float $sum, array $incident): float => $sum + (float) ($incident['expected_reimbursement_amount'] ?? 0), 0.0);

        $operations = $this->repo()->operationsAttentionSummary($companyId);

        return array_merge($operations, ['unmatched_receipts' => count($unmatched), 'ready_to_file' => count($ready), 'filed_pending' => count($filed), 'expected_reimbursement_total' => $expected, 'has_reimbursement_work' => count($unmatched) + count($ready) + count($filed) + (int) $operations['needs_classification'] + (int) $operations['expenses_missing_run'] > 0, 'href' => '/operations/airport/reimbursements']);
    }

    private function refreshClaimReadiness(int $companyId, int $incidentId): bool
    {
        $incident = $this->requireIncident($companyId, $incidentId);
        $receipts = $this->repo()->receiptsForIncident($companyId, $incidentId);
        $hasReceipt = count(array_filter($receipts, static fn (array $receipt): bool => ($receipt['attachment_type'] ?? '') === 'paid_receipt')) > 0;
        $amount = (float) ($incident['parking_amount_paid'] ?? 0);
        foreach ($receipts as $receipt) {
            if (($receipt['attachment_type'] ?? '') === 'paid_receipt' && (float) ($receipt['amount'] ?? 0) > 0) {
                $amount = (float) $receipt['amount'];
                break;
            }
        }
        $ready = (int) ($incident['turo_trip_normalized_id'] ?? 0) > 0 && (int) ($incident['fleet_vehicle_id'] ?? 0) > 0 && ($incident['incident_at'] ?? null) !== null && $amount > 0 && $hasReceipt && ! in_array($incident['claim_status'] ?? '', ['filed', 'reimbursed', 'denied'], true);
        $amounts = $this->amounts($amount);
        return $this->repo()->updateIncident($companyId, $incidentId, array_merge($amounts, ['parking_amount_paid' => $amount, 'claim_status' => $ready ? 'ready_to_file' : 'not_ready', 'incident_stage' => $ready ? 'claim_ready' : 'receipt_captured']), 'claim_readiness_refreshed');
    }

    private function incidentView(int $companyId, array $incident): array
    {
        return array_merge($incident, $this->amounts((float) ($incident['parking_amount_paid'] ?? 0)), ['receipts' => $this->repo()->receiptsForIncident($companyId, (int) $incident['id'])]);
    }

    /** @param array<int, array<string, mixed>> $trips @return array<int, array<string, mixed>> */
    private function rankCandidates(array $receipt, array $trips): array
    {
        $candidates = array_values(array_filter(array_map(fn (array $trip): array => $this->candidateView($receipt, $trip), $trips), static fn (array $candidate): bool => $candidate['reasons'] !== []));
        usort($candidates, static fn (array $left, array $right): int => [$right['rank'], $left['scheduled_at'], $left['turo_trip_id']] <=> [$left['rank'], $right['scheduled_at'], $right['turo_trip_id']]);

        return $candidates;
    }

    /** @return array<string, mixed> */
    private function candidateView(array $receipt, array $trip): array
    {
        $reasons = [];
        $warnings = [];
        $rank = 0;
        $receiptDate = (string) ($receipt['document_date'] ?? '');
        $movementDate = substr((string) ($trip['scheduled_at'] ?? ''), 0, 10);

        if (($receipt['ticket_number'] ?? null) !== null && ($trip['existing_ticket_number'] ?? null) === $receipt['ticket_number']) {
            $reasons[] = 'Ticket number matches existing incident';
            $rank += 100;
        }
        if (($trip['existing_incident_id'] ?? null) !== null) {
            $reasons[] = 'Existing Turo Access incident on this airport movement';
            $rank += 80;
        }
        if ((int) ($receipt['fleet_vehicle_id'] ?? 0) > 0 && (int) $receipt['fleet_vehicle_id'] === (int) ($trip['fleet_vehicle_id'] ?? 0)) {
            $reasons[] = 'Exact vehicle match';
            $rank += 60;
        } elseif ((int) ($receipt['fleet_vehicle_id'] ?? 0) > 0) {
            $warnings[] = 'Receipt vehicle differs from candidate vehicle';
        }
        if ($receiptDate !== '' && $receiptDate === $movementDate) {
            $reasons[] = 'Same-day airport ' . (string) ($trip['movement_type'] ?? 'movement');
            $rank += 40;
        } elseif ($receiptDate !== '' && $movementDate !== '') {
            $daysApart = abs((new \DateTimeImmutable($receiptDate))->diff(new \DateTimeImmutable($movementDate))->days);
            if ($daysApart <= 1) {
                $reasons[] = 'Airport movement is within one day of receipt date';
                $rank += 25;
            } elseif ($daysApart <= 2) {
                $reasons[] = 'Airport movement is near the receipt date';
                $rank += 10;
            }
        }
        if ((float) ($receipt['amount'] ?? 0) > 0 && (float) ($trip['existing_parking_amount_paid'] ?? 0) > 0 && number_format((float) $receipt['amount'], 2) === number_format((float) $trip['existing_parking_amount_paid'], 2)) {
            $reasons[] = 'Parking amount matches existing incident';
            $rank += 20;
        }
        if (in_array($trip['existing_claim_status'] ?? '', ['reimbursed', 'denied'], true)) {
            $warnings[] = 'Candidate already has a completed claim state';
        }

        return array_merge($trip, [
            'match_label' => $rank >= 100 ? 'Strong match' : ($rank >= 60 ? 'Likely match' : 'Possible match'),
            'reasons' => $reasons,
            'warnings' => $warnings,
            'rank' => $rank,
        ]);
    }

    /** @return array<string, mixed> */
    private function operationsRunCandidateView(array $receipt, array $run): array
    {
        $reasons = [];
        $receiptDate = (string) ($receipt['document_date'] ?? '');
        if ($receiptDate !== '' && $receiptDate === (string) ($run['run_date'] ?? '')) {
            $reasons[] = 'Same-day airport operations run';
        }
        if ((int) ($receipt['fleet_vehicle_id'] ?? 0) > 0) {
            $reasons[] = 'Run references the receipt vehicle or chase vehicle';
        }
        if ((int) ($run['expense_count'] ?? 0) > 0) {
            $reasons[] = 'Run already has airport expenses attached';
        }

        return array_merge($run, ['match_label' => $reasons === [] ? 'Possible run' : 'Likely run', 'reasons' => $reasons]);
    }

    private function runIdFromData(int $companyId, array $data): ?int
    {
        if (isset($data['airport_operations_run_id']) && (int) $data['airport_operations_run_id'] > 0) {
            return (int) $data['airport_operations_run_id'];
        }
        if (($data['create_airport_operations_run'] ?? '0') === '1') {
            $created = $this->createOperationsRun($companyId, $data);
            return (int) $created['run_id'];
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function activityPayloads(array $data): array
    {
        $activities = $data['activities'] ?? [];
        if ($activities === [] && ($data['activity_type'] ?? null) !== null) {
            $activities = [$data];
        }

        return array_map(function (array $activity): array {
            return [
                'activity_type' => $this->activityType($activity['activity_type'] ?? 'airport_support'),
                'fleet_vehicle_id' => isset($activity['fleet_vehicle_id']) && (int) $activity['fleet_vehicle_id'] > 0 ? (int) $activity['fleet_vehicle_id'] : null,
                'turo_trip_normalized_id' => isset($activity['turo_trip_normalized_id']) && (int) $activity['turo_trip_normalized_id'] > 0 ? (int) $activity['turo_trip_normalized_id'] : null,
                'airport_movement_workflow_id' => isset($activity['airport_movement_workflow_id']) && (int) $activity['airport_movement_workflow_id'] > 0 ? (int) $activity['airport_movement_workflow_id'] : null,
                'movement_type' => $activity['movement_type'] ?? null,
                'started_at' => $activity['started_at'] ?? null,
                'completed_at' => $activity['completed_at'] ?? null,
                'note' => $activity['note'] ?? null,
            ];
        }, array_values($activities));
    }

    private function receiptClassification(mixed $value): string
    {
        return in_array($value, ['trip_reimbursement', 'airport_operations_expense', 'unresolved', 'non_business', 'duplicate'], true) ? (string) $value : 'unresolved';
    }

    private function chaseVehicleType(mixed $value): string
    {
        return in_array($value, ['fleet_vehicle', 'personal_vehicle', 'company_vehicle', 'rental_or_borrowed_vehicle', 'other'], true) ? (string) $value : 'personal_vehicle';
    }

    private function activityType(mixed $value): string
    {
        return in_array($value, ['deliver_fleet_vehicle', 'recover_fleet_vehicle', 'wash_and_return', 'charge_vehicle', 'inspect_vehicle', 'swap_vehicles', 'stage_vehicle', 'guest_assistance', 'airport_support', 'other'], true) ? (string) $value : 'airport_support';
    }

    private function expenseCategory(mixed $value): string
    {
        return in_array($value, ['parking', 'fuel', 'ev_charging', 'car_wash', 'supplies', 'toll', 'airport_access_fee', 'mileage_reimbursement', 'other'], true) ? (string) $value : 'other';
    }

    private function accountingStatus(mixed $value): string
    {
        return in_array($value, ['unreviewed', 'recorded', 'reimbursable', 'reimbursed', 'excluded'], true) ? (string) $value : 'unreviewed';
    }

    private function mileage(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 1) : null;
    }

    /** @return array<string, float> */
    private function amounts(?float $paid): array
    {
        $paid ??= 0.0;
        $expected = min($paid, $this->config()->reimbursementCapAmount);
        return ['expected_reimbursement_amount' => $expected, 'host_unreimbursed_amount' => max(0, $paid - $expected)];
    }

    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function failed(string $code, string $message): array
    {
        return ['success' => false, 'code' => $code, 'message' => $message];
    }

    /** @return array<string, mixed> */
    private function requireWorkflow(int $companyId, int $workflowId): array
    {
        $workflow = $this->repo()->workflow($companyId, $workflowId);
        if ($workflow === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $workflow;
    }

    /** @return array<string, mixed> */
    private function requireIncident(int $companyId, int $incidentId): array
    {
        $incident = $this->repo()->incident($companyId, $incidentId);
        if ($incident === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $incident;
    }

    /** @return array<string, mixed> */
    private function requireReceipt(int $companyId, int $receiptId): array
    {
        $receipt = $this->repo()->receipt($companyId, $receiptId);
        if ($receipt === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $receipt;
    }

    private function repo(): TuroAccessReimbursementRepository
    {
        return $this->repository ?? service('turoAccessReimbursementRepository');
    }

    private function files(): PrivateFileStorageService
    {
        return $this->fileStorage ?? service('privateFileStorageService');
    }

    private function config(): TuroAccess
    {
        return $this->config ?? config(TuroAccess::class);
    }
}
