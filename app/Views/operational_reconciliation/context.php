<?php /** @var array<string, mixed>|null $context */ ?>
<?php if (($context ?? null) !== null): ?>
<aside class="reconciliation-context-link reconciliation-<?= esc(strtolower($context['severity']), 'attr') ?>" aria-label="Reconciliation finding">
    <a href="<?= esc($context['href'], 'attr') ?>" aria-label="<?= esc($context['severity'] . ' Reconciliation: ' . $context['count'] . ' findings / ' . $context['entity'] . ' / ' . implode(', ', $context['scopes']) . '. Review evidence', 'attr') ?>"><strong><?= esc($context['severity']) ?> Reconciliation</strong> <span><?= (int) $context['count'] ?> <?= $context['count'] === 1 ? 'finding' : 'findings' ?> &middot; <?= esc(implode(' / ', $context['scopes'])) ?></span><span>Review evidence &#8599;</span></a>
    <small><?= esc($context['entity']) ?><?= in_array('HISTORICAL', $context['scopes'], true) ? ' / Historical review' : '' ?></small>
</aside>
<?php endif; ?>
