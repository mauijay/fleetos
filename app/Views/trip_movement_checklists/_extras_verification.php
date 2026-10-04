<?php /** @var array<string, mixed>|null $verification */ ?>
<div class="extras-verification" data-verification-state="<?= esc((string) ($verification['state'] ?? 'never'), 'attr') ?>">
    <p class="<?= ($verification['is_stale'] ?? false) || ($verification['refresh_required'] ?? false) ? 'tone-warning' : 'muted' ?>"><?= esc((string) ($verification['summary'] ?? 'Extras have not been verified.')) ?></p>
    <?php if (($verification['age_label'] ?? null) !== null): ?><small>Complete observation age: <?= esc((string) $verification['age_label']) ?></small><?php endif; ?>
    <?php if (($verification['issue'] ?? null) !== null): ?><p class="tone-warning"><?= esc((string) $verification['issue']) ?><?php if (($verification['issue_label'] ?? null) !== null): ?> Attempt observed <?= esc((string) $verification['issue_label']) ?>.<?php endif; ?></p><?php endif; ?>
    <?php if (($verification['refresh_required'] ?? false) || ($verification['advisory'] ?? false)): ?>
        <p class="tone-warning"><strong><?= ($verification['refresh_required'] ?? false) ? 'Refresh required before pickup.' : 'Refresh recommended before pickup preparation.' ?></strong> <?= esc((string) $verification['refresh_reason']) ?></p>
        <?php if (($verification['action_href'] ?? null) !== null): ?><a class="action-link" href="<?= esc((string) $verification['action_href'], 'attr') ?>">Refresh Turo Extras</a><?php endif; ?>
    <?php elseif (($verification['optional_active_refresh'] ?? false) && ($verification['action_href'] ?? null) !== null): ?>
        <p class="muted">Pickup preparation is closed. An optional refresh can discover later changes.</p><a class="action-link" href="<?= esc((string) $verification['action_href'], 'attr') ?>">Refresh Turo Extras</a>
    <?php endif; ?>
</div>
