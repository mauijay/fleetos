<?php
/** @var array<string, mixed> $reconciliation */
/** @var array{css: ?string, js: ?string} $assets */
$r = $reconciliation;
$navigation = [
    ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
    ['label' => 'Operational Reconciliation', 'href' => '/operations/reconciliation', 'active' => 'true'],
    ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
    ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operational Reconciliation | FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame command-center-frame reconciliation-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main reconciliation-main" tabindex="-1">
        <header class="top-status">
            <div>
                <p class="eyebrow">FleetOS · Authority review</p>
                <h1>Operational Reconciliation</h1>
                <p class="status-copy">Compare FleetOS movement, trip, checklist, readiness, and commitment authorities to find records that need review.</p>
                <p class="reconciliation-meta">Recorded authorities as of <?= esc($r['as_of']) ?> · Pacific/Honolulu</p>
            </div>
        </header>
        <?php if ($r['return_link'] !== null): ?><a class="reconciliation-return" href="<?= esc($r['return_link']['href'], 'attr') ?>"><?= esc($r['return_link']['label']) ?></a><?php endif; ?>
        <section class="section" aria-label="Fleet reconciliation coverage">
            <div class="reconciliation-counts">
                <?php foreach ($r['summary']['counts'] as $severity => $count): ?>
                    <div class="reconciliation-count reconciliation-<?= esc(strtolower($severity), 'attr') ?>"><strong><?= esc($severity) ?> <span><?= esc((string) $count) ?></span></strong><small><?= esc($r['legend'][$severity]) ?></small></div>
                <?php endforeach; ?>
            </div>
            <p class="reconciliation-coverage"><strong><?= esc((string) $r['summary']['reviewed']) ?></strong> vehicles reviewed <span>&middot; <?= esc((string) $r['summary']['with_findings']) ?> with findings</span> <span>&middot; <?= esc((string) $r['summary']['without_findings']) ?> with no reconciliation findings</span></p>
        </section>
        <section class="section" aria-labelledby="filters-heading">
            <h2 id="filters-heading">Find records to review</h2>
            <?php if ($r['errors'] !== []): ?>
                <div class="reconciliation-errors" role="alert"><h3>Check the review filters</h3><ul><?php foreach ($r['errors'] as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <form method="get" action="/operations/reconciliation" class="reconciliation-filters">
                <?php foreach (['trip', 'checklist', 'issue', 'return'] as $key): ?><?php if ($r['filters'][$key] !== ''): ?><input type="hidden" name="<?= esc($key, 'attr') ?>" value="<?= esc($r['filters'][$key], 'attr') ?>"><?php endif; ?><?php endforeach; ?>
                <?php foreach (['severity' => ['' => 'All severities'] + array_combine(array_keys($r['legend']), array_keys($r['legend'])), 'scope' => ['' => 'All scopes', 'CURRENT' => 'Current', 'FUTURE' => 'Future', 'HISTORICAL' => 'Historical'], 'vehicle' => ['' => 'All owned vehicles'] + $r['vehicles'], 'category' => ['' => 'All categories'] + $r['categories']] as $name => $options): ?>
                    <label for="filter-<?= esc($name, 'attr') ?>"><?= esc(ucfirst($name)) ?><select id="filter-<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>"><?php foreach ($options as $value => $label): ?><option value="<?= esc((string) $value, 'attr') ?>" <?= (string) $r['filters'][$name] === (string) $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <?php endforeach; ?>
                <label for="reconciliation-search">Search vehicle, Trip ID or summary<input id="reconciliation-search" type="search" name="q" maxlength="100" value="<?= esc($r['filters']['q'], 'attr') ?>" placeholder="Vehicle or Trip 10000"></label>
                <div class="reconciliation-actions"><button type="submit" class="button">Apply filters</button><a href="/operations/reconciliation">Clear filters</a></div>
            </form>
        </section>
        <section class="section" aria-labelledby="findings-heading">
            <?php if ($r['context_label'] !== null): ?><p class="reconciliation-meta"><?= esc($r['context_label']) ?> &middot; Findings tied to this context. <a class="reconciliation-return" href="/operations/reconciliation">Review all fleet findings</a></p><?php endif; ?>
            <div class="section-heading split-heading"><h2 id="findings-heading">Authority findings</h2><span><?= esc((string) $r['total']) ?> matching · Page <?= esc((string) $r['page']) ?> of <?= esc((string) $r['pages']) ?></span></div>
            <?php if ($r['errors'] === [] && $r['groups'] === []): ?>
                <div class="empty-state"><?php if ($r['summary']['with_findings'] === 0): ?>No operational reconciliation issues found.<?php else: ?>No findings match these filters.<?php endif; ?> <?= esc((string) $r['summary']['reviewed']) ?> vehicles reviewed against recorded authorities.</div>
            <?php endif; ?>
            <div class="reconciliation-groups">
                <?php foreach ($r['groups'] as $group): ?>
                    <section class="reconciliation-vehicle" aria-label="<?= esc($group['name'], 'attr') ?> findings">
                        <header class="reconciliation-vehicle-header">
                            <div><h3><?= esc($group['name']) ?></h3><p>Highest severity <?= esc($group['highest_severity']) ?> · <?= esc((string) $group['total']) ?> fleet findings · <?= esc((string) count($group['issues'])) ?> of <?= esc((string) $group['matching']) ?> matching findings in this group</p></div>
                            <dl class="reconciliation-context">
                                <?php foreach (['Recorded custody' => $group['custody'], 'Current position' => $group['position'], 'Current imported trip' => $group['current_trip'], 'Movement current trip' => $group['movement_trip'], 'Next confirmed trip' => $group['next_trip']] as $label => $value): ?><div><dt><?= esc($label) ?></dt><dd><?= esc($value) ?></dd></div><?php endforeach; ?>
                            </dl>
                        </header>
                        <?php foreach ($group['issues'] as $issue): ?>
                            <article class="reconciliation-issue reconciliation-<?= esc(strtolower($issue['severity']), 'attr') ?>" data-fingerprint="<?= esc($issue['fingerprint'], 'attr') ?>">
                                <div class="reconciliation-issue-heading"><span class="reconciliation-badge"><?= esc($issue['severity']) ?></span><span class="reconciliation-scope"><?= esc($issue['scope']) ?></span><?php if ($issue['scope'] === 'HISTORICAL'): ?><span>Historical review</span><?php endif; ?></div>
                                <h4><?= esc($issue['summary']) ?></h4>
                                <p class="reconciliation-meta"><?= esc($issue['code']) ?> · <?= esc($issue['trip_label']) ?></p>
                                <details class="reconciliation-detail" <?= $r['filters']['issue'] === $issue['fingerprint'] ? 'open' : '' ?>>
                                <summary>Review evidence <span class="reconciliation-meta"><?= esc($issue['severity'] . ' / ' . $issue['code']) ?></span></summary>
                                <p class="reconciliation-authority"><strong>Authority basis:</strong> <?= esc($issue['authority_label']) ?></p>
                                <dl class="reconciliation-evidence"><?php foreach ($issue['facts'] as $fact): ?><div><dt><?= esc($fact['label']) ?></dt><dd><?= esc($fact['value']) ?></dd></div><?php endforeach; ?></dl>
                                <?php foreach ($issue['families'] as $family => $items): ?>
                                    <div class="reconciliation-family"><h5><?= esc($family) ?></h5><ul><?php foreach ($items as $item): ?><li><strong><?= esc($item['code']) ?></strong> · <?= esc($item['status']) ?><br><span><?= esc($item['classification']) ?> · <?= esc($item['actionability']) ?></span></li><?php endforeach; ?></ul></div>
                                <?php endforeach; ?>
                                <ol class="reconciliation-chronology" aria-label="Concise authority chronology"><?php foreach ($issue['chronology'] as $step): ?><li><strong><?= esc($step['label']) ?></strong><span><?= esc($step['value']) ?></span></li><?php endforeach; ?></ol>
                                <details class="reconciliation-support"><summary>Support reference</summary><p>Issue fingerprint <code><?= esc($issue['fingerprint']) ?></code></p></details>
                                <nav class="reconciliation-review-links" aria-label="Review <?= esc($group['name'] . ' ' . $issue['code'], 'attr') ?>"><?php foreach ($issue['links'] as $link): ?><a href="<?= esc($link['href'], 'attr') ?>"><?= esc($link['label']) ?> <span aria-hidden="true">&#8599;</span></a><?php endforeach; ?></nav>
                                </details>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <?php if ($r['errors'] === [] && $r['pages'] > 1): ?><nav class="reconciliation-pagination" aria-label="Findings pages"><?php if ($r['previous'] !== null): ?><a rel="prev" href="<?= esc($r['previous'], 'attr') ?>">Previous findings</a><?php endif; ?><span>Page <?= esc((string) $r['page']) ?> of <?= esc((string) $r['pages']) ?></span><?php if ($r['next'] !== null): ?><a rel="next" href="<?= esc($r['next'], 'attr') ?>">Next findings</a><?php endif; ?></nav><?php endif; ?>
        </section>
        <section class="section reconciliation-healthy" aria-label="Vehicles without findings"><h2>No reconciliation findings</h2><p><?= esc((string) $r['summary']['without_findings']) ?> of <?= esc((string) $r['summary']['reviewed']) ?> reviewed vehicles have no findings in the recorded authorities.</p></section>
        <?= view('fleet_command_center/components/footer') ?>
    </main>
</div>
</body>
</html>
