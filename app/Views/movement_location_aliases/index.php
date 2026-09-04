<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<int, array<string, mixed>> $sources */
/** @var string|null $notice */
/** @var string|null $error */

$classes = [
    'home' => 'Home',
    'airport_hnl' => 'Airport HNL',
    'waikiki_hotel' => 'Waikiki hotel',
    'other_delivery' => 'Other delivery',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Movement Location Aliases | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status" aria-label="Movement location alias status">
                <div>
                    <p class="eyebrow">Movement Data Quality</p>
                    <h1>Location Aliases</h1>
                    <p class="status-copy">Classify exact source locations once. Matching scheduled movements for the same company update immediately.</p>
                </div>
                <div class="status-cluster"><span><?= count($sources) ?> unknown source<?= count($sources) === 1 ? '' : 's' ?></span></div>
            </header>

            <?php if ($notice !== null): ?><section class="section import-message tone-success" aria-label="Alias notice"><strong><?= esc($notice) ?></strong></section><?php endif; ?>
            <?php if ($error !== null): ?><section class="section import-message tone-danger" aria-label="Alias error"><strong><?= esc($error) ?></strong></section><?php endif; ?>

            <section class="section" aria-labelledby="unknown-locations-heading">
                <div class="section-heading split-heading">
                    <div><p class="eyebrow">Exact source matching</p><h2 id="unknown-locations-heading">Unknown Locations</h2></div>
                    <span class="count-pill">Current class: Unknown</span>
                </div>
                <?php if ($sources === []): ?><div class="empty-state">No unknown movement locations need classification.</div><?php endif; ?>
                <div class="mapping-list">
                    <?php foreach ($sources as $source): ?>
                        <article class="mapping-card tone-warning">
                            <div class="mapping-card-main">
                                <div><span class="status-badge tone-warning">Unknown</span><h3><?= esc((string) $source['source_text']) ?></h3><p><?= esc((string) ($source['company_name'] ?? 'Company #' . $source['company_id'])) ?></p></div>
                                <dl class="issue-facts">
                                    <div><dt>Occurrences</dt><dd><?= esc((string) $source['occurrence_count']) ?></dd></div>
                                    <div><dt>Next occurrence</dt><dd><?= esc((string) ($source['next_occurrence'] ?? 'Not scheduled')) ?></dd></div>
                                    <div><dt>Current class</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) $source['location_class']))) ?></dd></div>
                                </dl>
                            </div>
                            <form class="resolution-form" action="/operations/movement-locations" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="company_id" value="<?= esc((string) $source['company_id'], 'attr') ?>">
                                <input type="hidden" name="source_text" value="<?= esc((string) $source['source_text'], 'attr') ?>">
                                <label>Operational class<select name="location_class" required><option value="">Choose class</option><?php foreach ($classes as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
                                <label>Note<textarea name="note" rows="2" placeholder="Optional operator context"></textarea></label>
                                <button class="primary-action" type="submit">Save Alias</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?= view('fleet_command_center/components/footer') ?>
        </main>
    </div>
    <?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
