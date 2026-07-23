<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, mixed> $preview */
/** @var array<string, mixed>|null $result */
/** @var string|null $error */

$summary = $result['summary'] ?? $preview['summary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reprocess Turo Trips | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>

        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status" aria-label="Turo trip reprocessing status">
                <div>
                    <p class="eyebrow">Safe Reprocessing</p>
                    <h1>Reprocess Historical Rows</h1>
                    <p class="status-copy">Turo vehicle ID <?= esc($preview['turo_vehicle_id']) ?>. Preview classifications before reconciling eligible rows.</p>
                </div>
                <a class="action-link" href="/turo/vehicle-matches">Vehicle Matching</a>
            </header>

            <?php if ($error !== null): ?>
                <section class="section import-message tone-danger"><strong><?= esc($error) ?></strong></section>
            <?php endif; ?>

            <section class="section" aria-labelledby="reprocess-summary-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Preview</p>
                        <h2 id="reprocess-summary-heading">Eligibility Summary</h2>
                    </div>
                    <span class="count-pill"><?= esc((string) $summary['total']) ?> total rows</span>
                </div>
                <div class="import-result-grid">
                    <div><span>Ready</span><b><?= esc((string) $summary['ready']) ?></b></div>
                    <div><span>Equivalent</span><b><?= esc((string) $summary['already_imported_equivalent']) ?></b></div>
                    <div><span>Conflicts</span><b><?= esc((string) $summary['already_imported_conflict']) ?></b></div>
                    <div><span>Invalid</span><b><?= esc((string) $summary['invalid_source_data']) ?></b></div>
                    <div><span>Blocked</span><b><?= esc((string) ($summary['missing_source_payload'] + $summary['mapping_missing'] + $summary['unsupported_issue'])) ?></b></div>
                </div>

                <?php if (! $preview['is_empty']): ?>
                    <form class="resolution-form" action="/turo/vehicle-matches/reprocess" method="post">
                        <input type="hidden" name="turo_vehicle_id" value="<?= esc($preview['turo_vehicle_id'], 'attr') ?>">
                        <label>Operator note
                            <textarea name="resolution_note" rows="3">Reconciled after Turo vehicle mapping was confirmed.</textarea>
                        </label>
                        <label class="checkbox-row">
                            <input type="checkbox" name="confirm_reprocess" value="1" required>
                            <span>Confirm reprocessing only ready rows and marking safe equivalents as reconciled.</span>
                        </label>
                        <button class="primary-action" type="submit">Reprocess Eligible Rows</button>
                    </form>
                <?php endif; ?>
            </section>

            <?php if (is_array($result)): ?>
                <section class="section import-message tone-success"><strong>Reprocessing complete.</strong></section>
            <?php endif; ?>

            <section class="section" aria-labelledby="reprocess-rows-heading">
                <div class="section-heading">
                    <p class="eyebrow">Row Details</p>
                    <h2 id="reprocess-rows-heading">Results</h2>
                </div>

                <?php if ($preview['is_empty']): ?>
                    <div class="empty-state">No unresolved historical rows match this Turo vehicle ID.</div>
                <?php endif; ?>

                <div class="mapping-list">
                    <?php foreach (($result['results'] ?? $preview['items']) as $item): ?>
                        <article class="mapping-card tone-<?= in_array($item['classification'], ['ready', 'already_imported_equivalent', 'reconciled_successfully', 'successfully_imported'], true) ? 'success' : 'warning' ?>">
                            <div class="mapping-card-main">
                                <div>
                                    <div class="vehicle-status-row">
                                        <span class="status-badge tone-<?= in_array($item['classification'], ['already_imported_conflict', 'invalid_source_data', 'reprocessing_failed'], true) ? 'danger' : 'info' ?>"><?= esc(ucwords(str_replace('_', ' ', $item['classification']))) ?></span>
                                        <span>Issue #<?= esc((string) $item['issue_id']) ?></span>
                                    </div>
                                    <h3><?= esc($item['message']) ?></h3>
                                    <p><?= esc($item['source_filename']) ?> · Row <?= esc((string) $item['row_number']) ?></p>
                                </div>
                                <dl class="issue-facts">
                                    <div><dt>Trip</dt><dd><?= esc((string) ($item['payload']['trip_id'] ?? $item['payload']['reservation_id'] ?? 'Not provided')) ?></dd></div>
                                    <div><dt>Vehicle</dt><dd><?= esc((string) ($item['payload']['vehicle_id'] ?? $item['payload']['turo_vehicle_id'] ?? 'Not provided')) ?></dd></div>
                                    <div><dt>FleetOS Trip</dt><dd><?= $item['trip_id'] === null ? 'Pending' : '#' . esc((string) $item['trip_id']) ?></dd></div>
                                </dl>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?= view('fleet_command_center/components/footer') ?>
        </main>
    </div>

    <?php if ($assets['js'] !== null): ?>
        <script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script>
    <?php endif; ?>
</body>
</html>
