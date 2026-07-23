<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, mixed>> $workflows */
/** @var string|null $notice */
/** @var string|null $error */
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Airport Operations | FleetOS</title><?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell">
<main class="command-main import-main">
    <header class="top-status"><div><p class="eyebrow">HNL Operations</p><h1>Airport Operations</h1><p class="status-copy">Today&apos;s airport staging, pickup, return, recovery, and cost-review work.</p></div><a class="action-link" href="/">Command Center</a></header>
    <?php if ($notice !== null): ?><section class="section import-message tone-success"><strong><?= esc($notice) ?></strong></section><?php endif; ?>
    <?php if ($error !== null): ?><section class="section import-message tone-danger"><strong><?= esc($error) ?></strong></section><?php endif; ?>
    <section class="section"><div class="mapping-list">
        <?php if ($workflows === []): ?><div class="empty-state">No airport movements are scheduled today.</div><?php endif; ?>
        <?php foreach ($workflows as $workflow): ?>
            <article class="mapping-card tone-<?= $workflow['workflow_status'] === 'completed' ? 'success' : 'warning' ?>">
                <div class="mapping-card-main"><div><div class="vehicle-status-row"><span class="status-badge tone-info"><?= esc(ucfirst($workflow['movement_type'])) ?></span><span><?= esc(ucwords(str_replace('_', ' ', $workflow['workflow_status']))) ?></span></div><h3><?= esc((string) ($workflow['fleet_code'] ?? $workflow['display_name'] ?? 'Vehicle')) ?></h3><p><?= esc((string) ($workflow['airport_code'] ?? 'HNL')) ?> · <?= esc((string) $workflow['scheduled_at']) ?></p></div><dl class="issue-facts"><div><dt>Garage</dt><dd><?= esc((string) ($workflow['garage'] ?? 'Not recorded')) ?></dd></div><div><dt>Level</dt><dd><?= esc((string) ($workflow['parking_level'] ?? 'Not recorded')) ?></dd></div><div><dt>Stall</dt><dd><?= esc((string) ($workflow['parking_stall'] ?? 'Not recorded')) ?></dd></div></dl></div>
                <p><a class="text-link" href="<?= esc($workflow['href'], 'attr') ?>">Open airport workflow</a></p>
            </article>
        <?php endforeach; ?>
    </div></section>
    <?= view('fleet_command_center/components/footer') ?>
</main>
</body>
</html>
