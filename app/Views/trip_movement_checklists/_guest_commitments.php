<?php
/** @var array<string,mixed> $checklist */
/** @var list<array<string,mixed>> $guestCommitments */
$tripId = (int) $checklist['turo_trip_normalized_id'];
$purchasedExtras = array_values(array_filter($extraPreparation ?? [], static fn (array $row): bool => ! ($row['is_removed'] ?? false) && (int) ($row['turo_trip_normalized_id'] ?? 0) === $tripId));
?>
<section class="section workflow-guest-commitments" aria-labelledby="workflow-guest-commitments-heading">
    <div class="section-heading split-heading"><div><p class="eyebrow">What must be ready for this guest</p><h2 id="workflow-guest-commitments-heading">Guest Commitments</h2></div><a class="action-link" href="/operations/trips/<?= $tripId ?>/commitments">Review all guest commitments</a></div>
    <h3>Purchased Extras</h3>
    <p class="muted"><?= esc((string) ($extraVerification['summary'] ?? 'Extras not verified for this reservation.')) ?></p>
    <?php if (($extraVerification['issue'] ?? null) !== null): ?><p class="tone-warning"><?= esc((string) $extraVerification['issue']) ?></p><?php endif; ?>
    <div class="workflow-commitment-list">
        <?php foreach ($purchasedExtras as $extra): ?><article class="workflow-commitment">
            <div><span class="eyebrow">Turo purchased selection</span><strong><?= esc((string) $extra['title']) ?></strong>
                <?php if (($extra['is_mapped'] ?? false) && ($extra['source_label'] ?? '') !== ($extra['fleet_extra_name'] ?? '')): ?><small>Turo label: <?= esc((string) ($extra['source_label'] ?? '')) ?></small><?php endif; ?>
                <small><?= ! ($extra['is_mapped'] ?? false) ? 'Operational mapping required' : (($extra['is_completed'] ?? false) ? 'Preparation confirmed' : (($extra['is_informational'] ?? false) ? 'Information only' : (($extra['is_actionable'] ?? false) ? 'Preparation required' : (($extra['configured'] ?? false) ? 'Not currently actionable' : 'Fulfillment not configured')))) ?></small>
                <?php if ($extra['quantity_unknown'] ?? false): ?><small>Quantity not supplied by Turo.</small><?php endif; ?>
            </div>
        </article><?php endforeach; ?>
    </div>
    <?php if ($purchasedExtras !== []): ?><a class="action-link" href="#trip-preparation">Review preparation and fulfillment</a><?php endif; ?>
    <h3>Special instructions<?= $guestCommitments === [] ? '' : ' · ' . count($guestCommitments) ?></h3>
    <?php if ($guestCommitments === []): ?><p class="muted">No manual special instructions apply to this movement.</p><?php endif; ?>
    <div class="workflow-commitment-list">
        <?php foreach ($guestCommitments as $commitment): ?>
            <article class="workflow-commitment<?= $commitment['is_blocking'] ? ' is-blocking' : '' ?>">
                <div><span class="eyebrow">Manual · <?= esc((string) $commitment['category_label']) ?></span><strong><?= esc((string) $commitment['instruction']) ?></strong>
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
