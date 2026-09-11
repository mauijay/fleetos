<?php

namespace App\Services\Fleet;

use App\Repositories\TripIncidentalReviewRepository;
use Config\Incidentals;
use InvalidArgumentException;

class TripIncidentalReviewService
{
    public function __construct(
        private readonly TripIncidentalReviewRepository $repository = new TripIncidentalReviewRepository(),
        private readonly IncidentalReviewUrgencyService $urgency = new IncidentalReviewUrgencyService(),
        private readonly Incidentals $config = new Incidentals(),
    ) {
    }

    public function projectTrip(int $tripId, ?int $actorUserId = null, ?\DateTimeImmutable $asOfUtc = null): bool
    {
        if (! $this->repository->available()) {
            return false;
        }
        $trip = $this->repository->eligibleTrip($tripId);
        return $trip !== null && $this->projectEligibleTrip($trip, $actorUserId, $asOfUtc);
    }

    public function projectEligible(?int $actorUserId = null, ?\DateTimeImmutable $asOfUtc = null): int
    {
        if (! $this->repository->available()) {
            return 0;
        }
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->repository->promoteDue($asOfUtc->format('Y-m-d H:i:s'));
        $created = 0;
        $honolulu = new \DateTimeZone('Pacific/Honolulu');
        $activationLocal = (new \DateTimeImmutable($this->config->activationEndedAtUtc, new \DateTimeZone('UTC')))->setTimezone($honolulu)->format('Y-m-d H:i:s');
        $asOfLocal = $asOfUtc->setTimezone($honolulu)->format('Y-m-d H:i:s');
        foreach ($this->repository->eligibleTrips($activationLocal, $asOfLocal, $this->config->projectionBatchSize) as $trip) {
            $created += $this->projectEligibleTrip($trip, $actorUserId, $asOfUtc) ? 1 : 0;
        }
        return $created;
    }

    /** @return array<string,mixed> */
    public function index(int $companyId, ?string $filter = null, ?\DateTimeImmutable $asOfUtc = null): array
    {
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $filter = in_array($filter, ['all', 'action', 'waiting', 'overdue', 'complete', 'needs_plan'], true) ? $filter : 'action';
        $reviews = array_map(function (array $review) use ($asOfUtc): array {
            $review['urgency'] = $this->urgency->project($review, $asOfUtc);
            $review['display_status'] = $review['urgency']['code'] === 'waiting' ? 'waiting_for_review' : (($review['urgency']['code'] === 'complete') ? $review['status'] : 'action_required');
            return $review;
        }, $this->repository->queue($companyId));
        $reviews = array_values(array_filter($reviews, static function (array $review) use ($filter): bool {
            return match ($filter) {
                'action' => in_array($review['urgency']['code'], ['due', 'due_soon', 'overdue_unconfirmed'], true),
                'waiting' => $review['urgency']['code'] === 'waiting',
                'overdue' => $review['urgency']['code'] === 'overdue_unconfirmed',
                'complete' => $review['urgency']['code'] === 'complete',
                'needs_plan' => $review['earnings_plan_code_snapshot'] === null && $review['urgency']['code'] !== 'complete',
                default => true,
            };
        }));
        $policies = $this->repository->policies($companyId);
        $planOptions = [];
        foreach ($policies as $policy) {
            $planOptions[(string) $policy['earnings_plan_code']] = (string) $policy['display_name'];
        }

        return [
            'reviews' => $reviews,
            'policies' => $policies,
            'plan_options' => $planOptions,
            'assignments' => $this->repository->earningsPlanAssignments($companyId),
            'vehicles' => $this->repository->fleetVehicles($companyId),
            'summary' => $this->repository->attentionSummary($companyId, $asOfUtc->format('Y-m-d H:i:s')),
            'filter' => $filter,
        ];
    }

    public function confirmPlan(int $companyId, int $reviewId, array $data, int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated actor is required to confirm or correct a trip plan.');
        }
        $review = $this->requireOpenReview($companyId, $reviewId);
        $planCode = (string) ($data['earnings_plan_code'] ?? '');
        $reason = trim((string) ($data['selection_reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('Record how the trip plan was confirmed.');
        }
        $policy = $this->repository->activePolicy($companyId, $planCode, (string) $review['trip_ended_at_utc']);
        if ($policy === null) {
            throw new InvalidArgumentException('An approved policy version is required for that plan and trip end time.');
        }
        $deadline = (new \DateTimeImmutable((string) $review['trip_ended_at_utc'], new \DateTimeZone('UTC')))
            ->modify('+' . (int) $policy['filing_window_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        $now = date('Y-m-d H:i:s');
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $new = [
            'earnings_plan_code_snapshot' => $planCode,
            'filing_deadline_at_utc' => $deadline, 'incidental_review_policy_id' => $policy['id'],
            'policy_rule_version_snapshot' => $policy['rule_version'], 'filing_window_minutes_snapshot' => $policy['filing_window_minutes'],
            'policy_source_reference_snapshot' => $policy['source_reference'],
            'plan_source_reference' => trim((string) ($data['source_reference'] ?? '')) ?: null,
            'plan_selection_reason' => $reason, 'plan_confirmed_by_user_id' => $actorUserId,
            'plan_confirmed_at_utc' => $nowUtc, 'updated_at' => $now,
        ];
        $this->repository->updateReview($companyId, $reviewId, $new);
        $action = $review['earnings_plan_code_snapshot'] === null ? 'earnings_plan_confirmed' : 'earnings_plan_corrected';
        $this->repository->audit($companyId, 'trip_incidental_reviews', $reviewId, $action, $review, array_merge($review, $new), $actorUserId);
    }

    public function recordEarningsPlanAssignment(int $companyId, array $data, int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('An authenticated actor is required to record an earnings plan assignment.');
        }
        $planCode = trim((string) ($data['earnings_plan_code'] ?? ''));
        $knownPlanCodes = array_column($this->repository->policies($companyId), 'earnings_plan_code');
        if ($planCode === '' || ! in_array($planCode, $knownPlanCodes, true)) {
            throw new InvalidArgumentException('Select an earnings plan with a configured filing-window policy.');
        }
        $reason = trim((string) ($data['assignment_reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('Record why this effective-dated assignment is correct.');
        }
        $scope = (string) ($data['scope'] ?? '');
        $vehicleId = null;
        if ($scope === 'vehicle') {
            $vehicleId = (int) ($data['fleet_vehicle_id'] ?? 0);
            if ($vehicleId < 1 || $this->repository->fleetVehicle($companyId, $vehicleId) === null) {
                throw new InvalidArgumentException('Select a vehicle owned by this company.');
            }
        } elseif ($scope !== 'fleet') {
            throw new InvalidArgumentException('Select a fleet default or vehicle override scope.');
        }
        $effectiveFromUtc = $this->honoluluLocalToUtc((string) ($data['effective_from'] ?? ''));
        $createdAt = date('Y-m-d H:i:s');
        $assignment = [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'earnings_plan_code' => $planCode,
            'effective_from_at_utc' => $effectiveFromUtc->format('Y-m-d H:i:s'),
            'assignment_reason' => $reason,
            'created_by_user_id' => $actorUserId,
            'created_at' => $createdAt,
        ];
        $assignmentId = $this->repository->createEarningsPlanAssignment($assignment);
        $this->repository->audit($companyId, 'incidental_earnings_plan_assignments', $assignmentId, 'created', null, $assignment, $actorUserId);

        return $this->resolveUnresolvedReviews($companyId, $actorUserId);
    }

    public function complete(int $companyId, int $reviewId, string $status, array $data, int $actorUserId, ?\DateTimeImmutable $asOfUtc = null): void
    {
        if (! in_array($status, ['invoice_sent', 'no_invoice_needed'], true)) {
            throw new InvalidArgumentException('Unsupported incidental review completion.');
        }
        $review = $this->requireOpenReview($companyId, $reviewId);
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($asOfUtc < new \DateTimeImmutable((string) $review['review_after_at_utc'], new \DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Wait until the review-after time before closing this follow-up.');
        }
        $now = date('Y-m-d H:i:s');
        $new = ['status' => $status, 'completion_reference' => trim((string) ($data['reference'] ?? '')) ?: null,
            'completion_note' => trim((string) ($data['note'] ?? '')) ?: null, 'completed_by_user_id' => $actorUserId,
            'completed_at_utc' => $asOfUtc->format('Y-m-d H:i:s'), 'updated_at' => $now];
        $this->repository->updateReview($companyId, $reviewId, $new);
        $this->repository->audit($companyId, 'trip_incidental_reviews', $reviewId, $status, $review, array_merge($review, $new), $actorUserId);
    }

    public function approvePolicy(int $companyId, int $policyId, string $rationale, int $actorUserId): void
    {
        $policy = $this->repository->policy($companyId, $policyId);
        if ($policy === null) {
            throw new InvalidArgumentException('Policy version not found for this company.');
        }
        if ($policy['status'] !== 'draft') {
            throw new InvalidArgumentException('Only draft policy versions can be approved.');
        }
        $rationale = trim($rationale);
        if ($rationale === '') {
            throw new InvalidArgumentException('Approval rationale is required.');
        }
        $now = date('Y-m-d H:i:s');
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $new = ['status' => 'active', 'approved_by_user_id' => $actorUserId, 'approved_at_utc' => $nowUtc, 'approval_rationale' => $rationale, 'updated_at' => $now];
        $this->repository->updatePolicy($companyId, $policyId, $new);
        $this->repository->audit($companyId, 'incidental_review_policies', $policyId, 'approved', $policy, array_merge($policy, $new), $actorUserId);
        $this->resolveUnresolvedReviews($companyId, $actorUserId);
    }

    /** @return array{ready:int,due_soon:int,overdue:int,total:int,href:string} */
    public function attentionSummary(int $companyId, ?\DateTimeImmutable $asOfUtc = null): array
    {
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->repository->attentionSummary($companyId, $asOfUtc->format('Y-m-d H:i:s')) + ['href' => '/operations/incidentals?filter=action'];
    }

    /** @return array{ready:int,due_soon:int,overdue:int,total:int,href:string} */
    public function attentionSummaryForSingleCompany(\DateTimeImmutable $asOf): array
    {
        if (! $this->repository->available()) {
            return ['ready' => 0, 'due_soon' => 0, 'overdue' => 0, 'total' => 0, 'href' => '/operations/incidentals?filter=action'];
        }
        $companyIds = \Config\Services::operationalFactsRepository()->activeFleetCompanyIds($asOf->format('Y-m-d'));
        if (count($companyIds) !== 1) {
            return ['ready' => 0, 'due_soon' => 0, 'overdue' => 0, 'total' => 0, 'href' => '/operations/incidentals?filter=action'];
        }
        return $this->attentionSummary($companyIds[0], $asOf->setTimezone(new \DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $trip */
    private function projectEligibleTrip(array $trip, ?int $actorUserId, ?\DateTimeImmutable $asOfUtc): bool
    {
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ended = (new \DateTimeImmutable((string) $trip['ends_at'], new \DateTimeZone('Pacific/Honolulu')))->setTimezone(new \DateTimeZone('UTC'));
        $activation = new \DateTimeImmutable($this->config->activationEndedAtUtc, new \DateTimeZone('UTC'));
        if ($ended < $activation || $ended > $asOfUtc) {
            return false;
        }
        $reviewAfter = $ended->modify('+' . $this->config->reviewDelayMinutes . ' minutes');
        $now = date('Y-m-d H:i:s');
        $data = ['company_id' => $trip['company_id'], 'turo_trip_normalized_id' => $trip['id'], 'fleet_vehicle_id' => $trip['fleet_vehicle_id'],
            'status' => $asOfUtc >= $reviewAfter ? 'action_required' : 'waiting_for_review', 'trip_ended_at_utc' => $ended->format('Y-m-d H:i:s'),
            'review_after_at_utc' => $reviewAfter->format('Y-m-d H:i:s'), 'review_delay_minutes_snapshot' => $this->config->reviewDelayMinutes,
            'created_by_user_id' => $actorUserId, 'creation_source' => 'trip_projection', 'created_at' => $now, 'updated_at' => $now];
        $resolved = $this->resolveAssignmentSnapshot($trip, $ended, $asOfUtc);
        if ($resolved !== null) {
            $data = array_merge($data, $resolved['snapshot']);
        }
        $id = $this->repository->create($data);
        if ($id === null) {
            $review = $this->repository->reviewForTrip((int) $trip['company_id'], (int) $trip['id']);
            if ($review !== null && $review['earnings_plan_code_snapshot'] === null) {
                $this->resolveReview($review, $actorUserId);
            }
            return false;
        }
        if ($actorUserId !== null && $actorUserId > 0) {
            $this->repository->audit((int) $trip['company_id'], 'trip_incidental_reviews', $id, 'created', null, $data, $actorUserId);
        }
        return true;
    }

    private function resolveUnresolvedReviews(int $companyId, ?int $actorUserId): int
    {
        $resolvedCount = 0;
        foreach ($this->repository->unresolvedReviews($companyId, $this->config->projectionBatchSize) as $review) {
            $resolvedCount += $this->resolveReview($review, $actorUserId) ? 1 : 0;
        }

        return $resolvedCount;
    }

    /** @param array<string,mixed> $review */
    private function resolveReview(array $review, ?int $actorUserId): bool
    {
        if ($review['earnings_plan_code_snapshot'] !== null) {
            return false;
        }
        $trip = [
            'company_id' => $review['company_id'],
            'fleet_vehicle_id' => $review['fleet_vehicle_id'],
            'booked_at' => $review['booked_at'] ?? null,
        ];
        $ended = new \DateTimeImmutable((string) $review['trip_ended_at_utc'], new \DateTimeZone('UTC'));
        $resolved = $this->resolveAssignmentSnapshot($trip, $ended, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($resolved === null) {
            return false;
        }
        $new = $resolved['snapshot'] + ['updated_at' => date('Y-m-d H:i:s')];
        $reviewId = (int) $review['id'];
        $companyId = (int) $review['company_id'];
        $this->repository->updateReview($companyId, $reviewId, $new);
        $auditActor = $actorUserId ?? (int) $resolved['assignment']['created_by_user_id'];
        $this->repository->audit($companyId, 'trip_incidental_reviews', $reviewId, 'earnings_plan_auto_resolved', $review, array_merge($review, $new), $auditActor);

        return true;
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{snapshot:array<string,mixed>,assignment:array<string,mixed>}|null
     */
    private function resolveAssignmentSnapshot(array $trip, \DateTimeImmutable $tripEndedUtc, \DateTimeImmutable $resolvedAtUtc): ?array
    {
        $bookedAt = trim((string) ($trip['booked_at'] ?? ''));
        if ($bookedAt === '') {
            return null;
        }
        $bookedAtLocal = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $bookedAt, new \DateTimeZone('Pacific/Honolulu'));
        if ($bookedAtLocal === false || $bookedAtLocal->format('Y-m-d H:i:s') !== $bookedAt) {
            return null;
        }
        $bookedAtUtc = $bookedAtLocal->setTimezone(new \DateTimeZone('UTC'));
        $assignment = $this->repository->effectiveEarningsPlanAssignment(
            (int) $trip['company_id'],
            (int) $trip['fleet_vehicle_id'],
            $bookedAtUtc->format('Y-m-d H:i:s'),
        );
        if ($assignment === null) {
            return null;
        }
        $planCode = (string) $assignment['earnings_plan_code'];
        $policy = $this->repository->activePolicy((int) $trip['company_id'], $planCode, $tripEndedUtc->format('Y-m-d H:i:s'));
        if ($policy === null) {
            return null;
        }
        $deadline = $tripEndedUtc->modify('+' . (int) $policy['filing_window_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        $scope = $assignment['fleet_vehicle_id'] === null ? 'Fleet default' : 'Vehicle override';

        return [
            'assignment' => $assignment,
            'snapshot' => [
                'earnings_plan_code_snapshot' => $planCode,
                'filing_deadline_at_utc' => $deadline,
                'incidental_review_policy_id' => $policy['id'],
                'policy_rule_version_snapshot' => $policy['rule_version'],
                'filing_window_minutes_snapshot' => $policy['filing_window_minutes'],
                'policy_source_reference_snapshot' => $policy['source_reference'],
                'plan_source_reference' => $scope . ' assignment #' . (int) $assignment['id'] . ' effective ' . $assignment['effective_from_at_utc'] . ' UTC',
                'plan_selection_reason' => $assignment['assignment_reason'],
                'plan_confirmed_by_user_id' => $assignment['created_by_user_id'],
                'plan_confirmed_at_utc' => $resolvedAtUtc->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function honoluluLocalToUtc(string $value): \DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Effective from is required.');
        }
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Pacific/Honolulu'));
        if ($local === false || $local->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Effective from must be a valid Honolulu date and time.');
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array<string,mixed> */
    private function requireOpenReview(int $companyId, int $reviewId): array
    {
        $review = $this->repository->review($companyId, $reviewId);
        if ($review === null) {
            throw new InvalidArgumentException('Trip incidental review not found for this company.');
        }
        if (in_array($review['status'], ['invoice_sent', 'no_invoice_needed'], true)) {
            throw new InvalidArgumentException('This incidental review is already complete.');
        }
        return $review;
    }
}
