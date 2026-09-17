<?php /** @var array<string, mixed> $activity */ ?>
<aside class="activity-panel" aria-label="Activity panel">
    <section class="panel-card fleet-snapshot" id="fleet-snapshot" aria-labelledby="fleet-snapshot-heading">
        <div class="rail-heading">
            <p class="eyebrow">Live Operations</p>
            <h2 id="fleet-snapshot-heading">Fleet Snapshot</h2>
            <p class="rail-heading__context">Current authoritative locations</p>
        </div>
        <ul class="fleet-snapshot__buckets">
            <?php foreach ($activity['fleet_snapshot']['buckets'] as $bucket): ?>
                <?php if ((int) $bucket['count'] === 0) {
                    continue;
                } ?>
                <li class="rail-dataset">
                    <span class="fleet-snapshot__label"><?= esc($bucket['label']) ?></span>
                    <span class="fleet-snapshot__vehicles">
                        <?php foreach ($bucket['vehicles'] as $index => $vehicle): ?>
                            <?= $index === 0 ? '' : ', ' ?><a href="<?= esc($vehicle['href'], 'attr') ?>" aria-label="Open vehicle <?= esc($vehicle['label'], 'attr') ?>"><?= esc($vehicle['label']) ?></a>
                        <?php endforeach; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="panel-card operations-queue-summary" aria-labelledby="activity-queue-heading">
        <div class="rail-heading">
            <p class="eyebrow">Activity</p>
            <h2 id="activity-queue-heading">Operations Queue</h2>
            <p class="rail-heading__context">Actionable work by timeframe</p>
        </div>
        <ul class="activity-list">
            <?php foreach ($activity['queue_scopes'] as $scope): ?>
                <?php if ($scope['code'] === 'all') {
                    continue;
                } ?>
                <li class="rail-dataset">
                    <?php if ($scope['actionable']): ?>
                        <a class="activity-list__link" href="<?= esc($scope['href'], 'attr') ?>" aria-label="Open <?= esc($scope['label'], 'attr') ?> operations queue, <?= esc((string) $scope['count'], 'attr') ?> item<?= (int) $scope['count'] === 1 ? '' : 's' ?>">
                            <span><?= esc($scope['label']) ?></span>
                            <strong><?= esc((string) $scope['count']) ?></strong>
                        </a>
                    <?php else: ?>
                        <span class="activity-list__clear">
                            <span><?= esc($scope['label']) ?></span>
                            <strong><?= esc((string) $scope['count']) ?></strong>
                            <small>Clear</small>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="panel-card future-signals" aria-labelledby="external-context-heading">
        <div class="rail-heading">
            <p class="eyebrow">Future Signals</p>
            <h2 id="external-context-heading">External Context</h2>
            <p class="rail-heading__context">Integration status</p>
        </div>
        <ul class="signal-list">
            <li class="rail-dataset">Weather alerts <span><?= esc($activity['weather_status']) ?></span></li>
            <li class="rail-dataset">Traffic alerts <span><?= esc($activity['traffic_status']) ?></span></li>
            <li class="rail-dataset">Battery alerts <span><?= esc($activity['battery_status']) ?></span></li>
        </ul>
    </section>
</aside>
