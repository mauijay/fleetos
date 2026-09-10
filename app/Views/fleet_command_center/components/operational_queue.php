<?php /** @var array<string, mixed> $queueView */ ?>
<nav class="queue-scopes" aria-label="Operational queue scope">
    <?php foreach ($queueView['scopes'] as $scope): ?>
        <?php if ($scope['actionable']): ?>
            <a class="queue-scope<?= $scope['active'] ? ' is-active' : '' ?>" href="<?= esc($scope['href'], 'attr') ?>"<?= $scope['active'] ? ' aria-current="page"' : '' ?>>
                <span><?= esc($scope['label']) ?></span>
                <?php if ($scope['count'] !== null): ?><strong><?= esc((string) $scope['count']) ?></strong><?php endif; ?>
            </a>
        <?php else: ?>
            <span class="queue-scope is-clear">
                <span><?= esc($scope['label']) ?></span>
                <strong><?= esc((string) $scope['count']) ?></strong>
                <small>Clear</small>
            </span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<p class="queue-context"><?= esc($queueView['label']) ?></p>

<?php if ($queueView['items'] === []): ?>
    <div class="empty-state">No work is currently in this queue.</div>
<?php else: ?>
    <div class="operational-action-grid">
        <?php foreach ($queueView['items'] as $action): ?>
            <?php if ($action['actionable']): ?>
                <a class="task-card operational-action" href="<?= esc($action['href'], 'attr') ?>" aria-label="<?= esc($action['label'], 'attr') ?>, <?= esc($action['detail'], 'attr') ?>">
                    <h3><?= esc($action['label']) ?></h3>
                    <p><?= esc($action['detail']) ?></p>
                </a>
            <?php else: ?>
                <div class="task-card operational-action is-clear">
                    <h3><?= esc($action['label']) ?></h3>
                    <p><?= esc($action['detail']) ?></p>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
