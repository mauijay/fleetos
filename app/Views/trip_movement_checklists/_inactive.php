<?php
/** @var array<string, mixed> $checklist */
/** @var string|null $tripStatusCode */
$statusLabel = str_starts_with((string) $tripStatusCode, 'canceled') ? 'Trip canceled' : 'Trip inactive';
$items = $checklist['items'] ?? [];
$audits = $checklist['audits'] ?? [];
?>
<section class="section import-message tone-warning" id="historical-workflow" aria-labelledby="historical-workflow-heading">
    <p class="eyebrow">Historical movement workflow</p>
    <h2 id="historical-workflow-heading" tabindex="-1"><?= esc($statusLabel) ?></h2>
    <strong>No operational work is required.</strong>
    <p>This movement is preserved for history and audit. Preparation and handoff actions are no longer applicable.</p>
    <a class="action-link" href="/operations/trips/<?= (int) $checklist['turo_trip_normalized_id'] ?>/commitments">Review preserved Guest Commitments</a>
</section>

<details class="section checklist-history">
    <summary>Checklist history</summary>
    <div class="history-list">
        <?php if ($items === []): ?><p class="muted">No checklist items were recorded.</p><?php endif; ?>
        <?php foreach ($items as $item): ?>
            <div>
                <strong><?= esc((string) ($item['label'] ?? $item['item_code'] ?? 'Checklist item')) ?></strong>
                <span><?= esc(ucwords(str_replace('_', ' ', (string) (($item['applicability'] ?? 'applicable') === 'not_applicable' ? 'not_applicable' : ($item['completion_state'] ?? 'pending'))))) ?><?php if (($item['completion_source'] ?? null) !== null): ?> &middot; <?= esc(ucwords(str_replace('_', ' ', (string) $item['completion_source']))) ?><?php endif; ?><?php if (($item['completed_at'] ?? null) !== null): ?> &middot; <?= esc(date('M j, Y g:i A', strtotime((string) $item['completed_at']))) ?><?php endif; ?></span>
                <?php if (trim((string) ($item['note'] ?? '')) !== ''): ?><small><?= esc((string) $item['note']) ?></small><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($audits as $audit): ?>
            <div><strong>Checklist audit: <?= esc((string) ($audit['action'] ?? 'Recorded')) ?></strong><span><?= esc((string) ($audit['created_at'] ?? 'Time not captured')) ?> &middot; item #<?= (int) ($audit['trip_movement_checklist_item_id'] ?? 0) ?></span></div>
        <?php endforeach; ?>
        <?php if (($checklist['completed_at'] ?? null) !== null): ?>
            <div><strong>Workflow completed</strong><span><?= esc(date('M j, Y g:i A', strtotime((string) $checklist['completed_at']))) ?></span><?php if (trim((string) ($checklist['completion_note'] ?? '')) !== ''): ?><small><?= esc((string) $checklist['completion_note']) ?></small><?php endif; ?></div>
        <?php endif; ?>
    </div>
</details>
