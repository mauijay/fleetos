<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, int>|null $trip_result */
/** @var string|null $trip_error */
/** @var array<string, int>|null $earnings_result */
/** @var string|null $earnings_error */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Turo Import | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>

        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status" aria-label="Turo import status">
                <div>
                    <p class="eyebrow">Spreadsheet Elimination</p>
                    <h1>Turo Import</h1>
                    <p class="status-copy">Import trip_earnings_export and earnings_export separately. Trips remain the source of operational truth, while earnings transactions become the source of financial event truth.</p>
                </div>
                <a class="action-link" href="/">Command Center</a>
            </header>

            <?php if ($trip_error !== null): ?>
                <section class="section import-message tone-danger" aria-label="Import error">
                    <strong>Trips import stopped</strong>
                    <p><?= esc($trip_error) ?></p>
                </section>
            <?php endif; ?>

            <?php if ($earnings_error !== null): ?>
                <section class="section import-message tone-danger" aria-label="Import error">
                    <strong>Earnings import stopped</strong>
                    <p><?= esc($earnings_error) ?></p>
                </section>
            <?php endif; ?>

            <?php if (is_array($trip_result)): ?>
                <section class="section import-message tone-success" aria-label="Import result">
                    <strong>Turo trips import complete</strong>
                    <div class="import-result-grid">
                        <div><span>Batch</span><b>#<?= esc((string) $trip_result['batch_id']) ?></b></div>
                        <div><span>Rows read</span><b><?= esc((string) $trip_result['rows_read']) ?></b></div>
                        <div><span>Trips normalized</span><b><?= esc((string) $trip_result['trips_normalized']) ?></b></div>
                        <div><span>Allocations refreshed</span><b><?= esc((string) $trip_result['allocation_rows_created']) ?></b></div>
                        <div><span>Row issues</span><b><?= esc((string) $trip_result['row_issues']) ?></b></div>
                    </div>
                    <?php if ($trip_result['row_issues'] > 0): ?>
                        <p><a class="action-link" href="/turo/import-issues?status=unresolved&amp;batch_id=<?= esc((string) $trip_result['batch_id'], 'attr') ?>">Review issues from this import</a></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (is_array($earnings_result)): ?>
                <section class="section import-message tone-success" aria-label="Import result">
                    <strong>Turo earnings import complete</strong>
                    <div class="import-result-grid">
                        <div><span>Batch</span><b>#<?= esc((string) $earnings_result['batch_id']) ?></b></div>
                        <div><span>Rows read</span><b><?= esc((string) $earnings_result['rows_read']) ?></b></div>
                        <div><span>Transactions normalized</span><b><?= esc((string) $earnings_result['trips_normalized']) ?></b></div>
                        <div><span>Rows skipped</span><b><?= esc((string) $earnings_result['rows_skipped']) ?></b></div>
                        <div><span>Duplicate rows</span><b><?= esc((string) $earnings_result['rows_duplicate']) ?></b></div>
                        <div><span>Unmatched rows</span><b><?= esc((string) $earnings_result['rows_unmatched']) ?></b></div>
                        <div><span>Row issues</span><b><?= esc((string) $earnings_result['row_issues']) ?></b></div>
                    </div>
                    <?php if ($earnings_result['row_issues'] > 0): ?>
                        <p><a class="action-link" href="/turo/import-issues?status=unresolved&amp;batch_id=<?= esc((string) $earnings_result['batch_id'], 'attr') ?>">Review issues from this import</a></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="section import-panel" aria-labelledby="upload-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Build Today</p>
                        <h2 id="upload-heading">Import Turo Trips CSV</h2>
                    </div>
                    <span class="count-pill">trip_earnings_export</span>
                </div>

                <p><a class="text-link" href="/turo/import-issues">Open Import Issues</a> · <a class="text-link" href="/turo/vehicle-matches">Open Vehicle Matching</a></p>

                <form class="upload-form" action="/turo/imports" method="post" enctype="multipart/form-data">
                    <label class="file-drop" for="trips_csv">
                        <span>Choose Turo trip_earnings_export</span>
                        <input id="trips_csv" name="trips_csv" type="file" accept=".csv,text/csv" required>
                    </label>
                    <button class="primary-action" type="submit">Import Trips</button>
                </form>

                <div class="import-notes" aria-label="Import behavior">
                    <div>
                        <strong>Protects reporting</strong>
                        <p>Duplicate files are rejected, duplicate trips update existing normalized records, and month allocations are regenerated from the latest trip data.</p>
                    </div>
                    <div>
                        <strong>Shows cleanup work</strong>
                        <p>Rows with missing dates, invalid money values, duplicate trip ids, or unmatched vehicles are logged as operator-readable issues.</p>
                    </div>
                    <div>
                        <strong>Keeps source data</strong>
                        <p>FleetOS stores raw row payloads for imported rows and row issues, so the original spreadsheet does not become the source of truth.</p>
                    </div>
                </div>
            </section>

            <section class="section import-panel" aria-labelledby="earnings-upload-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Build Today</p>
                        <h2 id="earnings-upload-heading">Import Turo Earnings CSV</h2>
                    </div>
                    <span class="count-pill">earnings_export</span>
                </div>

                <form class="upload-form" action="/turo/earnings-imports" method="post" enctype="multipart/form-data">
                    <label class="file-drop" for="earnings_csv">
                        <span>Choose Turo earnings_export</span>
                        <input id="earnings_csv" name="earnings_csv" type="file" accept=".csv,text/csv" required>
                    </label>
                    <button class="primary-action" type="submit">Import Earnings</button>
                </form>

                <div class="import-notes" aria-label="Earnings import behavior">
                    <div>
                        <strong>Keeps ledgers separate</strong>
                        <p>Earnings rows are normalized into transaction categories (trip_earning, payment, other) without mutating trip operational records.</p>
                    </div>
                    <div>
                        <strong>Prevents wrong-file mistakes</strong>
                        <p>The earnings importer rejects trip_earnings_export files and the trips importer rejects earnings_export files with clear guidance.</p>
                    </div>
                    <div>
                        <strong>Links when trustworthy</strong>
                        <p>Transactions attach to existing normalized trips when trip IDs are present, but FleetOS never creates synthetic trips from earnings data.</p>
                    </div>
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
