<?php
/** @var array<string, mixed> $checklist */
/** @var list<array<string, mixed>> $extraPreparation */
$pending = array_values(array_filter($extraPreparation, static fn (array $row): bool => (bool) ($row['is_actionable'] ?? false)));
$removed = array_values(array_filter($extraPreparation, static fn (array $row): bool => (bool) ($row['is_removed'] ?? false)));
$completed = array_values(array_filter($extraPreparation, static fn (array $row): bool => ! ($row['is_removed'] ?? false) && (bool) ($row['is_completed'] ?? false)));
$information = array_values(array_filter($extraPreparation, static fn (array $row): bool => ! ($row['is_removed'] ?? false) && (bool) ($row['is_informational'] ?? false)));
$context = array_values(array_filter($extraPreparation, static fn (array $row): bool => ! ($row['is_removed'] ?? false) && ! ($row['is_actionable'] ?? false) && ! ($row['is_completed'] ?? false) && ! ($row['is_informational'] ?? false)));
?>
<?php if ($extraPreparation !== []): ?>
<section class="section trip-preparation" id="trip-preparation" aria-labelledby="trip-preparation-heading">
    <div class="section-heading split-heading"><div><p class="eyebrow">Purchased Extras</p><h2 id="trip-preparation-heading">Trip Preparation</h2></div><span class="count-pill"><?= count($pending) ?> action<?= count($pending) === 1 ? '' : 's' ?></span></div>
    <div class="trip-preparation-list">
        <?php foreach ($pending as $fulfillment): ?>
            <article class="trip-preparation-item is-pending" id="extra-fulfillment-<?= (int) $fulfillment['fulfillment_id'] ?>">
                <div><h3><?= esc((string) $fulfillment['title']) ?></h3><?php if ($fulfillment['is_next_trip'] ?? false): ?><span class="status-badge tone-info">Next trip</span><?php endif; ?><p><?= esc((string) $fulfillment['action_label']) ?></p><?php if ($fulfillment['quantity_unknown']): ?><small>Quantity not supplied by Turo; verify the purchased selection.</small><?php endif; ?><?php foreach ($fulfillment['linked_commitments'] as $commitment): ?><small>Guest commitment: <?= esc((string) $commitment['instruction']) ?></small><?php endforeach; ?></div>
                <form action="/operations/trips/<?= (int) $fulfillment['turo_trip_normalized_id'] ?>/extra-fulfillments/<?= (int) $fulfillment['fulfillment_id'] ?>/complete" method="post"><?= csrf_field() ?><label><span class="visually-hidden">Completion note</span><input name="completion_note" maxlength="2000" placeholder="Optional note"></label><button class="primary-action" type="submit">Confirm fulfilled</button></form>
            </article>
        <?php endforeach; ?>
        <?php foreach ($information as $fulfillment): ?><article class="trip-preparation-item is-informational"><div><h3><?= esc((string) $fulfillment['title']) ?></h3><p>Purchased Extra recorded for trip context. No operator confirmation required.</p></div><span class="status-badge tone-info">Information</span></article><?php endforeach; ?>
    </div>
    <?php if ($completed !== [] || $context !== [] || $removed !== []): ?><details class="secondary-disclosure"><summary>Fulfillment history and context · <?= count($completed) + count($context) + count($removed) ?></summary><div class="history-list">
        <?php foreach ($completed as $fulfillment): ?><div><strong><?= esc((string) $fulfillment['title']) ?></strong><span>Fulfilled<?= $fulfillment['completed_at'] === null ? '' : ' · ' . esc((string) $fulfillment['completed_at']) ?><?= $fulfillment['completed_by_user_id'] === null ? '' : ' · Operator #' . (int) $fulfillment['completed_by_user_id'] ?></span><?php if ($fulfillment['completion_note'] !== null): ?><small><?= esc((string) $fulfillment['completion_note']) ?></small><?php endif; ?><form action="/operations/trips/<?= (int) $fulfillment['turo_trip_normalized_id'] ?>/extra-fulfillments/<?= (int) $fulfillment['fulfillment_id'] ?>/reopen" method="post"><?= csrf_field() ?><button class="action-link" type="submit">Reopen</button></form></div><?php endforeach; ?>
        <?php foreach ($removed as $fulfillment): ?><div><strong><?= esc((string) $fulfillment['title']) ?></strong><span><?= ($fulfillment['is_completed'] ?? false) ? 'Previously fulfilled' : 'Fulfillment was pending' ?></span><small>Selection later removed · No action required<?= $fulfillment['removed_at'] === null ? '' : ' · ' . esc((string) $fulfillment['removed_at']) ?></small><?php if ($fulfillment['completed_at'] !== null): ?><small>Completed <?= esc((string) $fulfillment['completed_at']) ?><?= $fulfillment['completed_by_user_id'] === null ? '' : ' · Operator #' . (int) $fulfillment['completed_by_user_id'] ?></small><?php endif; ?><?php if ($fulfillment['completion_note'] !== null): ?><small><?= esc((string) $fulfillment['completion_note']) ?></small><?php endif; ?></div><?php endforeach; ?>
        <?php foreach ($context as $fulfillment): ?><div><strong><?= esc((string) $fulfillment['title']) ?></strong><span><?= ($fulfillment['configured'] ?? false) ? 'Not currently actionable' : 'Fulfillment not configured' ?></span></div><?php endforeach; ?>
    </div></details><?php endif; ?>
</section>
<?php endif; ?>
