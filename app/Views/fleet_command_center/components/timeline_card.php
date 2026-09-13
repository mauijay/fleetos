<?php /** @var array<string, mixed> $timeline */ ?>
<article class="timeline-card">
    <div class="card-row">
        <h3><?= esc($timeline['label']) ?></h3>
        <span class="count-pill"><?= esc((string) $timeline['count']) ?></span>
    </div>
    <?php if ($timeline['items'] === []): ?>
        <p class="muted">No scheduled items.</p>
    <?php else: ?>
        <ol class="timeline-list">
            <?php foreach ($timeline['items'] as $item): ?>
                <li>
                    <span><?= esc($item['title_label']) ?></span>
                    <strong><?= esc($item['starts_at_label']) ?></strong>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</article>
