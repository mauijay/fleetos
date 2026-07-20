<?php /** @var array<string, array<int, array<string, mixed>>> $decisionSupport */ ?>
<?php $recommendations = $decisionSupport['todays_recommendations'] ?? []; ?>
<?php if ($recommendations === []): ?>
    <div class="empty-state">No decision support recommendations need attention.</div>
<?php else: ?>
    <div class="recommendation-grid">
        <?php foreach ($recommendations as $recommendation): ?>
            <article class="recommendation-card priority-<?= esc(strtolower((string) $recommendation['priority']), 'attr') ?>">
                <div class="card-row">
                    <span class="status-badge tone-info"><?= esc((string) $recommendation['category']) ?></span>
                    <span class="count-pill"><?= esc((string) $recommendation['confidence']) ?>%</span>
                </div>
                <h3><?= esc((string) $recommendation['title']) ?></h3>
                <p><?= esc((string) $recommendation['reason']) ?></p>
                <dl class="recommendation-meta">
                    <div><dt>Priority</dt><dd><?= esc((string) $recommendation['priority']) ?></dd></div>
                    <div><dt>Action</dt><dd><?= esc((string) $recommendation['action']) ?></dd></div>
                </dl>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
