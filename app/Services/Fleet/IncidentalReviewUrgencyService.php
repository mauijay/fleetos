<?php

namespace App\Services\Fleet;

final class IncidentalReviewUrgencyService
{
    /** @return array{code:string,label:string,hours_remaining:?float} */
    public function project(array $review, ?\DateTimeImmutable $asOfUtc = null): array
    {
        $asOfUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (in_array($review['status'] ?? null, ['invoice_sent', 'no_invoice_needed'], true)) {
            return ['code' => 'complete', 'label' => $review['status'] === 'invoice_sent' ? 'Invoice sent' : 'No invoice needed', 'hours_remaining' => null];
        }
        $reviewAfter = new \DateTimeImmutable((string) $review['review_after_at_utc'], new \DateTimeZone('UTC'));
        if ($asOfUtc < $reviewAfter) {
            return ['code' => 'waiting', 'label' => 'Waiting for Turo incidentals', 'hours_remaining' => null];
        }
        $deadline = $review['filing_deadline_at_utc'] ?? null;
        if (! is_string($deadline) || $deadline === '') {
            return ['code' => 'due', 'label' => 'Review due — plan confirmation required', 'hours_remaining' => null];
        }
        $deadlineAt = new \DateTimeImmutable($deadline, new \DateTimeZone('UTC'));
        $hours = ($deadlineAt->getTimestamp() - $asOfUtc->getTimestamp()) / 3600;
        if ($hours < 0) {
            return ['code' => 'overdue_unconfirmed', 'label' => 'Overdue — review still open', 'hours_remaining' => $hours];
        }
        if ($hours <= 24) {
            return ['code' => 'due_soon', 'label' => 'Due within 24 hours', 'hours_remaining' => $hours];
        }
        return ['code' => 'due', 'label' => 'Review Turo incidentals', 'hours_remaining' => $hours];
    }
}
