<?php /** @var array<string, mixed> $task */ ?>
<article class="task-card tone-<?= esc($task['tone'], 'attr') ?>">
    <div class="card-row">
        <h3><?= esc($task['label']) ?></h3>
        <span class="count-pill"><?= esc((string) $task['count']) ?></span>
    </div>
    <?php if ($task['type'] === 'loan'): ?><p class="muted">Informational schedule; payment state is not tracked here.</p><?php endif; ?>
    <?php if ($task['count'] === 0): ?>
        <p><?= esc($task['empty_text']) ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($task['preview_items'] as $item): ?>
                <li><?= esc($item) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
