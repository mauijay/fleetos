<?php /** @var array<string, mixed> $summary */ ?>
<section class="section reconciliation-summary reconciliation-<?= esc(strtolower($summary['priority']), 'attr') ?>" aria-labelledby="reconciliation-summary-heading">
    <div><p class="eyebrow"><?= $summary['priority'] === 'P0' ? 'Immediate attention · P0 authority review' : ($summary['priority'] === 'P1' ? 'Upcoming preparation · P1' : 'Recorded authority review') ?></p><h2 id="reconciliation-summary-heading">Operational Reconciliation</h2></div>
    <p class="reconciliation-summary-counts"><?php foreach ($summary['counts'] as $severity => $count): ?><a href="/operations/reconciliation?severity=<?= esc($severity, 'attr') ?>" aria-label="<?= esc($severity . ' reconciliation findings: ' . $count, 'attr') ?>"><strong><?= esc($severity) ?>:</strong> <?= esc((string) $count) ?></a><?php endforeach; ?></p>
    <a href="/operations/reconciliation">Review reconciliation <span aria-hidden="true">&#8599;</span></a>
</section>
