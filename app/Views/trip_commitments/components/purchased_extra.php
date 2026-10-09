<?php
/** @var array<string,mixed> $extra */
$historical = $historical ?? false;
$verification = $extra['verification'] ?? [];
?>
<article class="guest-commitment-card wrap-anywhere<?= ($extra['is_blocking'] ?? false) ? ' is-blocking' : '' ?>" id="extra-selection-<?= (int) $extra['selection_id'] ?>">
    <div class="guest-commitment-card__heading"><div><p class="eyebrow">Purchased Extra<?= $historical ? ' · History' : '' ?></p><h3><?= esc((string) $extra['title']) ?></h3></div><span class="status-badge tone-info"><?= $historical ? 'Removed selection' : (($extra['is_completed'] ?? false) ? 'Confirmed' : (($extra['is_informational'] ?? false) && ! ($extra['requires_operator_confirmation'] ?? false) ? 'Information' : 'Pending')) ?></span></div>
    <?php if (! ($extra['is_mapped'] ?? false)): ?><p class="tone-warning">Unmapped purchased Extra: <?= esc((string) $extra['source_label']) ?></p><a class="action-link" href="/turo/extras#unmapped-heading">Review source mapping</a><?php elseif (($extra['source_label'] ?? '') !== ($extra['fleet_extra_name'] ?? '')): ?><p class="muted">Turo: <?= esc((string) $extra['source_label']) ?></p><?php endif; ?>
    <?php if ($extra['quantity_unknown'] ?? false): ?><p class="tone-warning">Quantity not supplied — verify selection</p><?php endif; ?>
    <?php if ($historical): ?>
        <p><?= ($extra['is_completed'] ?? false) ? 'Previously confirmed' : 'Fulfillment was unconfirmed' ?> · Selection removed <?= esc((string) ($extra['removed_at'] ?? '')) ?></p>
        <p class="muted"><?= ($extra['historical_configuration'] ?? null) === null ? 'Historical configuration unknown.' : 'Configuration preserved in operational audit context.' ?></p>
    <?php else: ?>
        <p class="muted"><?= ($extra['is_last_known'] ?? true) ? 'Last-known Extra — verification/refresh required.' : 'Confirmed purchased Extra — as of ' . esc((string) ($verification['verified_label'] ?? $extra['observed_at'])) ?></p>
        <?php if (($extra['configuration_state'] ?? '') === 'disabled'): ?><p class="tone-warning">Saved Extra disabled. This purchased selection remains visible.</p><?php elseif (($extra['configuration_state'] ?? '') === 'unconfigured'): ?><p class="tone-warning">Fulfillment not configured — review Extra configuration.</p><?php endif; ?>
        <?php if ($extra['synchronization_required'] ?? false): ?><p class="tone-warning">Fulfillment synchronization required. Current requirements remain unconfirmed.</p><?php endif; ?>
        <?php if ($extra['is_suppressed_after_handoff'] ?? false): ?><p class="tone-warning">Pickup preparation closed at this trip’s handoff. Unconfirmed or changed requirements need review.</p><?php endif; ?>
        <?php if (($extra['action_label'] ?? '') !== ''): ?><p><?= esc((string) $extra['action_label']) ?></p><?php endif; ?>
        <?php if (($extra['fulfillment_type'] ?? '') === 'logistics'): ?><p class="muted">Planned return: <?= esc((string) ($extra['planned_return'] ?? $plannedReturn ?? 'Location not captured — review return workflow')) ?></p><?php if (($extra['return_workflow_href'] ?? null) !== null): ?><a class="action-link" href="<?= esc((string) $extra['return_workflow_href'], 'attr') ?>">Review return workflow</a><?php endif; ?><?php endif; ?>
        <?php if (($extra['overlap_warning'] ?? null) !== null): ?><p class="tone-warning"><?= esc((string) $extra['overlap_warning']) ?></p><?php endif; ?>
        <?php if ($extra['is_actionable'] ?? false): ?><form class="commitment-actions" action="/operations/trips/<?= (int) $extra['turo_trip_normalized_id'] ?>/extra-fulfillments/<?= (int) $extra['fulfillment_id'] ?>/complete" method="post"><?= csrf_field() ?><input type="hidden" name="return_to" value="commitments"><label>Confirmation note<input name="completion_note" maxlength="2000"></label><button class="primary-action" type="submit"><?= esc((string) $extra['confirmation_label']) ?></button></form><?php endif; ?>
        <?php if ($extra['can_reopen'] ?? false): ?><form action="/operations/trips/<?= (int) $extra['turo_trip_normalized_id'] ?>/extra-fulfillments/<?= (int) $extra['fulfillment_id'] ?>/reopen" method="post"><?= csrf_field() ?><input type="hidden" name="return_to" value="commitments"><button class="action-link" type="submit">Reopen</button></form><?php endif; ?>
    <?php endif; ?>
    <?php if (($extra['completed_at'] ?? null) !== null): ?><p class="muted">Confirmation recorded: <?= esc((string) $extra['completed_at']) ?></p><?php endif; ?>
    <details class="secondary-disclosure"><summary>Source and fulfillment history</summary><p>Selection #<?= (int) $extra['selection_id'] ?> · Snapshot #<?= (int) ($extra['snapshot_id'] ?? 0) ?> · Observed <?= esc((string) ($extra['observed_at'] ?? '')) ?></p><?php foreach ($extra['audits'] ?? [] as $audit): ?><p><?= esc((string) $audit['action']) ?> · <?= esc((string) $audit['created_at']) ?></p><?php endforeach; ?></details>
</article>
