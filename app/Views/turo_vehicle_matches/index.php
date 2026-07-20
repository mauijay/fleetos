<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, mixed> $queue */
/** @var string|null $notice */
/** @var string|null $error */

$filters = $queue['filters'];
$summary = $queue['summary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vehicle Matching | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>

        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status" aria-label="Turo vehicle matching status">
                <div>
                    <p class="eyebrow">Vehicle Matching</p>
                    <h1>Turo Vehicle Cleanup</h1>
                    <p class="status-copy">Map each unmatched Turo vehicle ID to the correct FleetOS vehicle once. Future imports will reuse the saved mapping automatically.</p>
                </div>
                <div class="status-cluster">
                    <span><?= esc((string) $summary['unique_unmatched_vehicles']) ?> unique unmatched</span>
                    <span><?= esc((string) $summary['affected_issues']) ?> affected rows</span>
                    <a class="action-link" href="/turo/import-issues?category=vehicle_unmatched">Import issues</a>
                </div>
            </header>

            <?php if ($notice !== null): ?>
                <section class="section import-message tone-success" aria-label="Mapping notice"><strong><?= esc($notice) ?></strong></section>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <section class="section import-message tone-danger" aria-label="Mapping error"><strong><?= esc($error) ?></strong></section>
            <?php endif; ?>

            <section class="section" aria-labelledby="mapping-filters-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Default: unmapped</p>
                        <h2 id="mapping-filters-heading">Filters</h2>
                    </div>
                    <a class="text-link" href="/turo/vehicle-matches">Clear filters</a>
                </div>

                <form class="issue-filters" action="/turo/vehicle-matches" method="get">
                    <label>Status
                        <select name="status">
                            <?php foreach (['unmapped' => 'Unmapped', 'mapped' => 'Mapped', 'conflicts' => 'Conflicts', 'suggested' => 'Suggested matches', 'all' => 'All'] as $value => $label): ?>
                                <option value="<?= esc($value, 'attr') ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Vehicle / Turo ID
                        <input name="vehicle" type="search" value="<?= esc($filters['vehicle'], 'attr') ?>" placeholder="turo id or vehicle name">
                    </label>
                    <label>FleetOS vehicle
                        <select name="fleet_vehicle_id">
                            <option value="0">Any FleetOS vehicle</option>
                            <?php foreach ($queue['fleet_vehicles'] as $vehicle): ?>
                                <option value="<?= esc((string) $vehicle['id'], 'attr') ?>" <?= $filters['fleet_vehicle_id'] === (string) $vehicle['id'] ? 'selected' : '' ?>><?= esc((string) ($vehicle['fleet_code'] ?? $vehicle['display_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Import batch
                        <input name="batch_id" type="number" min="0" value="<?= esc($filters['batch_id'], 'attr') ?>">
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

            <section class="section" aria-labelledby="mapping-queue-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow"><?= esc((string) count($queue['items'])) ?> grouped identities</p>
                        <h2 id="mapping-queue-heading">Cleanup Queue</h2>
                    </div>
                    <span class="count-pill">Authoritative ID: Turo vehicle ID</span>
                </div>

                <?php if ($queue['is_empty']): ?>
                    <div class="empty-state">No unmatched Turo vehicle mappings need cleanup.</div>
                <?php endif; ?>

                <div class="mapping-list">
                    <?php foreach ($queue['items'] as $item): ?>
                        <article class="mapping-card tone-<?= $item['mapping'] === null ? 'warning' : 'success' ?>">
                            <div class="mapping-card-main">
                                <div>
                                    <div class="vehicle-status-row">
                                        <span class="status-badge tone-<?= $item['mapping'] === null ? 'warning' : 'success' ?>"><?= esc($item['mapping_status']) ?></span>
                                        <span><?= esc($item['suggestion']['confidence']) ?> suggestion</span>
                                    </div>
                                    <h3><?= esc($item['vehicle_name']) ?></h3>
                                    <p>Turo vehicle ID <?= esc($item['turo_vehicle_id']) ?> · Last seen <?= esc($item['last_seen']) ?></p>
                                    <?php if ($item['suggestion']['fleet_vehicle_id'] !== null): ?>
                                        <p>Suggested: <?= esc($item['suggestion']['label']) ?> · <?= esc($item['suggestion']['reason']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <dl class="issue-facts">
                                    <div><dt>Affected issues</dt><dd><?= esc((string) $item['affected_issue_count']) ?></dd></div>
                                    <div><dt>Affected trips</dt><dd><?= esc((string) $item['affected_trip_count']) ?></dd></div>
                                    <div><dt>Imports affected</dt><dd><?= esc((string) $item['import_count']) ?></dd></div>
                                    <div><dt>Source file</dt><dd><?= esc($item['source_filename']) ?></dd></div>
                                    <div><dt>Plate</dt><dd><?= esc((string) ($item['license_plate'] ?? 'Not provided')) ?></dd></div>
                                    <div><dt>VIN</dt><dd><?= esc((string) ($item['vin_fragment'] ?? 'Not provided')) ?></dd></div>
                                </dl>
                            </div>

                            <details class="issue-details">
                                <summary>Map this vehicle</summary>
                                <div class="issue-detail-grid">
                                    <form class="resolution-form" action="/turo/vehicle-matches/map" method="post">
                                        <input type="hidden" name="turo_vehicle_id" value="<?= esc($item['turo_vehicle_id'], 'attr') ?>">
                                        <label>FleetOS vehicle
                                            <select name="fleet_vehicle_id" required>
                                                <option value="">Choose vehicle</option>
                                                <?php foreach ($queue['fleet_vehicles'] as $vehicle): ?>
                                                    <option value="<?= esc((string) $vehicle['id'], 'attr') ?>" <?= (int) ($item['suggestion']['fleet_vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= esc((string) ($vehicle['fleet_code'] ?? $vehicle['display_name'])) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Note
                                            <textarea name="mapping_note" rows="3" placeholder="Optional reason for this mapping or remap."></textarea>
                                        </label>
                                        <label class="checkbox-row">
                                            <input type="checkbox" name="confirm_remap" value="1">
                                            <span>Confirm remap or replace an existing active Turo mapping for this FleetOS vehicle.</span>
                                        </label>
                                        <p class="muted">Confirm before saving: map Turo vehicle “<?= esc($item['vehicle_name']) ?>” to the selected FleetOS vehicle.</p>
                                        <button class="primary-action" type="submit">Save Mapping</button>
                                    </form>
                                    <div class="resolution-form">
                                        <h4>Historical Rows</h4>
                                        <p class="muted">Mapping fixes future imports. Reprocessing verifies whether historical rows can be safely reconciled before issues are closed.</p>
                                        <a class="primary-action button-link" href="/turo/vehicle-matches/reprocess?turo_vehicle_id=<?= esc(rawurlencode($item['turo_vehicle_id']), 'attr') ?>">Preview Reprocessing</a>
                                    </div>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>

    <?php if ($assets['js'] !== null): ?>
        <script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script>
    <?php endif; ?>
</body>
</html>
