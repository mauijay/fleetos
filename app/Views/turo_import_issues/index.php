<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, mixed> $review */
/** @var string|null $notice */
/** @var string|null $error */

$filters = $review['filters'];
$summary = $review['summary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Turo Import Issues | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>

        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status" aria-label="Turo import issue status">
                <div>
                    <p class="eyebrow">Import Cleanup</p>
                    <h1>Turo Import Issues</h1>
                    <p class="status-copy">Review warnings and failed CSV rows, inspect source-row values, and track each issue to resolution.</p>
                </div>
                <div class="status-cluster" aria-label="Unresolved import issue counts">
                    <span><?= esc((string) $summary['unresolved_errors']) ?> errors</span>
                    <span><?= esc((string) $summary['unresolved_warnings']) ?> warnings</span>
                    <a class="action-link" href="/turo/imports">New import</a>
                </div>
            </header>

            <?php if ($notice !== null): ?>
                <section class="section import-message tone-success" aria-label="Issue notice"><strong><?= esc($notice) ?></strong></section>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <section class="section import-message tone-danger" aria-label="Issue error"><strong><?= esc($error) ?></strong></section>
            <?php endif; ?>

            <section class="section" aria-labelledby="filters-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Default: unresolved</p>
                        <h2 id="filters-heading">Filters</h2>
                    </div>
                    <a class="text-link" href="/turo/import-issues">Clear filters</a>
                </div>

                <form class="issue-filters" action="/turo/import-issues" method="get">
                    <label>Status
                        <select name="status">
                            <?php foreach (['unresolved' => 'Unresolved', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label): ?>
                                <option value="<?= esc($value, 'attr') ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Severity
                        <select name="severity">
                            <option value="">All severities</option>
                            <option value="error" <?= $filters['severity'] === 'error' ? 'selected' : '' ?>>Errors</option>
                            <option value="warning" <?= $filters['severity'] === 'warning' ? 'selected' : '' ?>>Warnings</option>
                        </select>
                    </label>
                    <label>Import batch
                        <select name="batch_id">
                            <option value="0">All batches</option>
                            <?php foreach ($review['batches'] as $batch): ?>
                                <option value="<?= esc((string) $batch['id'], 'attr') ?>" <?= $filters['batch_id'] === (string) $batch['id'] ? 'selected' : '' ?>>#<?= esc((string) $batch['id']) ?> <?= esc((string) ($batch['source_filename'] ?? 'Turo import')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Vehicle
                        <input name="vehicle" type="search" value="<?= esc($filters['vehicle'], 'attr') ?>" placeholder="Vehicle ID or fleet code">
                    </label>
                    <label>Category
                        <select name="category">
                            <option value="">All categories</option>
                            <?php foreach ($review['categories'] as $category): ?>
                                <option value="<?= esc($category, 'attr') ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $category))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>From
                        <input name="from" type="date" value="<?= esc($filters['from'], 'attr') ?>">
                    </label>
                    <label>To
                        <input name="to" type="date" value="<?= esc($filters['to'], 'attr') ?>">
                    </label>
                    <button class="primary-action" type="submit">Apply Filters</button>
                </form>
            </section>

            <section class="section" aria-labelledby="issues-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow"><?= esc((string) $review['total']) ?> matching issues</p>
                        <h2 id="issues-heading">Issues</h2>
                    </div>
                    <span class="count-pill">Page <?= esc((string) $review['page']) ?> of <?= esc((string) $review['page_count']) ?></span>
                </div>

                <?php if ($review['is_empty']): ?>
                    <div class="empty-state">No import issues match these filters.</div>
                <?php endif; ?>

                <div class="issue-review-list">
                    <?php foreach ($review['issues'] as $issue): ?>
                        <article class="issue-review-card tone-<?= esc($issue['severity_code'], 'attr') ?>">
                            <div class="issue-review-main">
                                <div>
                                    <div class="vehicle-status-row">
                                        <span class="status-badge tone-<?= esc($issue['severity_code'], 'attr') ?>"><?= esc($issue['severity_label']) ?></span>
                                        <span><?= esc($issue['resolution_status']) ?></span>
                                    </div>
                                    <h3><?= esc($issue['plain_message']) ?></h3>
                                    <p><?= esc($issue['source_label']) ?> · Row <?= esc((string) ($issue['row_number'] ?? 'pending')) ?> · <?= esc((string) ($issue['created_at'] ?? 'unknown date')) ?></p>
                                    <?php if (($issue['error_code'] ?? '') === 'vehicle_unmatched' && $issue['vehicle_mapping_href'] !== null): ?>
                                        <p><?= esc($issue['vehicle_mapping_status']) ?> · <a class="text-link" href="<?= esc($issue['vehicle_mapping_href'], 'attr') ?>">Map vehicle</a> · <a class="text-link" href="<?= esc($issue['vehicle_reprocess_href'], 'attr') ?>">Preview reprocessing</a></p>
                                    <?php endif; ?>
                                </div>
                                <dl class="issue-facts">
                                    <div><dt>Category</dt><dd><?= esc($issue['category_label']) ?></dd></div>
                                    <div><dt>Vehicle</dt><dd><?= esc($issue['vehicle_label']) ?></dd></div>
                                    <div><dt>Trip</dt><dd><?= esc($issue['trip_id']) ?></dd></div>
                                    <div><dt>Guest</dt><dd><?= esc($issue['guest_name']) ?></dd></div>
                                    <div><dt>Dates</dt><dd><?= esc($issue['trip_dates']) ?></dd></div>
                                    <div><dt>Resolved</dt><dd><?= esc((string) ($issue['resolved_at'] ?? 'Not yet')) ?></dd></div>
                                </dl>
                            </div>

                            <details class="issue-details">
                                <summary>View source row and resolution</summary>
                                <div class="issue-detail-grid">
                                    <div>
                                        <h4>Relevant Raw Values</h4>
                                        <?php if ($issue['relevant_values'] === []): ?>
                                            <p class="muted">No recognized trip, vehicle, guest, date, or revenue fields were present in this row.</p>
                                        <?php else: ?>
                                            <dl class="raw-value-list">
                                                <?php foreach ($issue['relevant_values'] as $key => $value): ?>
                                                    <div><dt><?= esc($key) ?></dt><dd><?= esc($value) ?></dd></div>
                                                <?php endforeach; ?>
                                            </dl>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h4>Resolution</h4>
                                        <?php if (($issue['resolution_note'] ?? null) !== null): ?>
                                            <p class="muted"><?= esc((string) $issue['resolution_note']) ?></p>
                                        <?php endif; ?>
                                        <form class="resolution-form" action="/turo/import-issues/<?= esc((string) $issue['id'], 'attr') ?>/<?= $issue['requires_action'] ? 'resolve' : 'reopen' ?>" method="post">
                                            <label>Note
                                                <textarea name="resolution_note" rows="3" placeholder="Optional note about what changed or why this can be closed."></textarea>
                                            </label>
                                            <button class="primary-action" type="submit"><?= $issue['requires_action'] ? 'Mark Resolved' : 'Reopen Issue' ?></button>
                                        </form>
                                    </div>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($review['page_count'] > 1): ?>
                    <nav class="pagination-row" aria-label="Import issue pagination">
                        <?php if ($review['page'] > 1): ?>
                            <a class="text-link" href="?<?= esc(http_build_query(array_merge($filters, ['page' => $review['page'] - 1])), 'attr') ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($review['page'] < $review['page_count']): ?>
                            <a class="text-link" href="?<?= esc(http_build_query(array_merge($filters, ['page' => $review['page'] + 1])), 'attr') ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

            <?= view('fleet_command_center/components/footer') ?>
        </main>
    </div>

    <?php if ($assets['js'] !== null): ?>
        <script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script>
    <?php endif; ?>
</body>
</html>
