<?php

namespace App\Services\Fleet;

use App\Repositories\SuperchargerReconciliationRepository;
use InvalidArgumentException;

class SuperchargerReconciliationService
{
    private const FILTERS = ['attention', 'reconciled', 'host', 'review', 'all'];
    private const WORKFLOW_STATES = ['not_submitted', 'submitted', 'no_invoice_needed', 'waived'];

    public function __construct(private readonly SuperchargerReconciliationRepository $repository = new SuperchargerReconciliationRepository())
    {
    }

    /** @return array<string, mixed> */
    public function workspace(int $companyId, ?string $filter = null): array
    {
        if ($companyId < 1) {
            throw new InvalidArgumentException('An active company is required.');
        }
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'attention';
        $cases = [];
        foreach ($this->repository->casesForCompany($companyId) as $case) {
            $tripId = (int) $case['turo_trip_normalized_id'];
            $sessions = $this->repository->sessionsForCase($companyId, $tripId);
            $eligibleCents = array_sum(array_map(fn (array $session): int => $this->moneyCents($session['cost_amount'] ?? 0), $sessions));
            $turoCents = $this->moneyCents($case['on_trip_ev_charging_amount'] ?? 0);
            $status = $this->financialStatus($eligibleCents, $turoCents);
            $cases[] = array_merge($case, [
                'eligible_cost_cents' => $eligibleCents,
                'turo_reimbursed_cents' => $turoCents,
                'outstanding_cents' => $eligibleCents - $turoCents,
                'financial_status' => $status,
                'sessions' => $sessions,
                'line_items' => $this->repository->lineItemsForTrip($companyId, $tripId),
                'audits' => $this->repository->caseAudits($companyId, (int) $case['id']),
            ]);
        }
        $hostAndReview = array_map(function (array $session): array {
            $costCents = $this->moneyCents($session['cost_amount'] ?? 0);
            $classification = (string) $session['custody_classification'];
            $session['financial_status'] = $costCents === 0
                ? 'no_cost'
                : (in_array($classification, ['operator', 'between_trips'], true) ? 'host_expense' : 'review');

            return $session;
        }, $this->repository->reviewAndHostSessions($companyId));
        $reviewRows = $this->repository->unresolvedImportRows($companyId);
        $filteredCases = array_values(array_filter($cases, fn (array $case): bool => $this->caseMatchesFilter($case, $filter)));
        $filteredSessions = array_values(array_filter($hostAndReview, static function (array $session) use ($filter): bool {
            $classification = (string) $session['custody_classification'];
            return $filter === 'all'
                || ($filter === 'host' && in_array($classification, ['operator', 'between_trips'], true))
                || ($filter === 'review' && $classification === 'review');
        }));

        return [
            'filter' => $filter,
            'cases' => $filteredCases,
            'sessions' => $filteredSessions,
            'import_issues' => in_array($filter, ['attention', 'review', 'all'], true) ? $reviewRows : [],
            'summary' => [
                'attention' => count(array_filter($cases, fn (array $case): bool => $this->caseMatchesFilter($case, 'attention'))) + count($reviewRows),
                'reconciled' => count(array_filter($cases, static fn (array $case): bool => $case['financial_status'] === 'reconciled')),
                'host' => count(array_filter($hostAndReview, static fn (array $session): bool => in_array($session['custody_classification'], ['operator', 'between_trips'], true))),
                'review' => count(array_filter($hostAndReview, static fn (array $session): bool => $session['custody_classification'] === 'review')) + count($reviewRows),
                'all' => count($cases) + count($hostAndReview) + count($reviewRows),
            ],
        ];
    }

    public function changeWorkflow(int $companyId, int $caseId, string $state, ?string $note, ?string $reference, int $actorUserId): void
    {
        if (! in_array($state, self::WORKFLOW_STATES, true)) {
            throw new InvalidArgumentException('Choose a valid invoice workflow state.');
        }
        $this->repository->updateCaseWorkflow(
            $companyId,
            $caseId,
            $state,
            $this->optional($note),
            $this->optional($reference),
            $actorUserId,
        );
    }

    public function financialStatus(int $eligibleCents, int $turoCents): string
    {
        if ($eligibleCents <= 0) {
            return $turoCents > 0 ? 'over_reconciled_review' : 'no_cost';
        }
        if ($turoCents <= 0) {
            return 'eligible_unreconciled';
        }
        if ($turoCents === $eligibleCents) {
            return 'reconciled';
        }

        return $turoCents < $eligibleCents ? 'partially_reconciled' : 'over_reconciled_review';
    }

    private function caseMatchesFilter(array $case, string $filter): bool
    {
        if ($filter === 'all') {
            return true;
        }
        if ($filter === 'reconciled') {
            return $case['financial_status'] === 'reconciled';
        }
        if ($filter === 'attention') {
            return in_array($case['financial_status'], ['eligible_unreconciled', 'partially_reconciled', 'over_reconciled_review'], true)
                && ! in_array($case['workflow_state_code'], ['no_invoice_needed', 'waived'], true);
        }

        return false;
    }

    private function moneyCents(mixed $amount): int
    {
        $normalized = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            return 0;
        }
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
