<?php
/** @var array<string,mixed> $checklist */
/** @var list<array<string,mixed>> $guestCommitments */
$tripId = (int) $checklist['turo_trip_normalized_id'];
?>
<section class="section workflow-guest-commitments" aria-labelledby="workflow-guest-commitments-heading">
    <div class="section-heading split-heading"><div><p class="eyebrow">Guest commitments</p><h2 id="workflow-guest-commitments-heading">Special instructions<?= $guestCommitments === [] ? '' : ' · ' . count($guestCommitments) ?></h2></div><a class="action-link" href="/operations/trips/<?= $tripId ?>/commitments">Review all guest commitments</a></div>
    <?php if ($guestCommitments === []): ?><p class="muted">No guest-specific commitments apply to this movement.</p><?php endif; ?>
    <div class="workflow-commitment-list">
        <?php foreach ($guestCommitments as $commitment): ?>
            <article class="workflow-commitment<?= $commitment['is_blocking'] ? ' is-blocking' : '' ?>">
                <div><span class="eyebrow"><?= esc((string) $commitment['category_label']) ?></span><strong><?= esc((string) $commitment['instruction']) ?></strong>
                    <small><?= esc((string) $commitment['phase_label']) ?><?= $commitment['is_blocking'] ? ' · Required before dispatch' : ' · Information' ?></small>
                    <?php if ($commitment['arranged_at'] !== null): ?><span class="commitment-arranged-time">Guest arrangement: <?= esc(date('M j, Y · g:i A', strtotime((string) $commitment['arranged_at']))) ?></span><?php endif; ?>
                    <?php if (($commitment['fleet_extra_name'] ?? null) !== null): ?><span class="commitment-arranged-time">Linked Extra: <?= esc((string) $commitment['fleet_extra_name']) ?></span><?php endif; ?>
                    <?php if ($commitment['category'] === 'energy_override'): ?><?= view('trip_commitments/components/energy_override_context', ['commitment' => $commitment]) ?><?php endif; ?>
                </div>
                <?php if ($commitment['handling_mode'] === 'task'): ?><form action="/operations/trips/<?= $tripId ?>/commitments/<?= (int) $commitment['id'] ?>/complete" method="post"><?= csrf_field() ?><button class="primary-action" type="submit">Complete</button></form><?php endif; ?>
                <?php if ($commitment['handling_mode'] === 'acknowledgment' && $commitment['acknowledged_at'] === null): ?><form action="/operations/trips/<?= $tripId ?>/commitments/<?= (int) $commitment['id'] ?>/acknowledge" method="post"><?= csrf_field() ?><button class="secondary-action" type="submit">Acknowledge</button></form><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
