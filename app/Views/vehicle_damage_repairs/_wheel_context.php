<?php $wheelContext = $wheelRepair[$condition['id']] ?? null; if ($wheelContext !== null): ?>
<div class="wheel-repair-context">
<p><strong><?= esc($wheelContext['label']) ?></strong> · <?= esc($wheelContext['episode']) ?></p>
<p>Physical condition: <?= esc(\App\Services\Fleet\VehicleDamageService::STATUSES[$condition['status_code']]) ?> · Severity: <?= esc(ucfirst($condition['severity_code'])) ?></p>
<?php if ($wheelContext['warning'] !== null): ?><p role="status"><?= esc($wheelContext['warning']) ?></p><?php endif; ?>
<p><a class="action-link" href="<?= esc($wheelContext['history_url'], 'attr') ?>">View this episode, occurrences, photos and repair history</a></p>
</div>
<?php endif; ?>
