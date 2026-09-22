<?php
/** @var array{css:?string,js:?string} $assets */
/** @var list<array{label:string,href:string,active:string}> $navigation */
/** @var array<string,mixed> $workspace */
/** @var array<string,int|bool>|null $import_result */
/** @var string|null $success */
/** @var string|null $error */
$money = static fn (mixed $amount, string $currency = 'USD'): string => $currency . ' ' . number_format((float) $amount, 2);
$reservationIds = implode("\n", $workspace['reservation_ids']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extras Import | FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame extras-import-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main operator-main extras-import-main" tabindex="-1">
        <header class="top-status">
            <div><p class="eyebrow">Source-backed commercial activity</p><h1>Extras Import</h1><p class="status-copy">Import sanitized Turo reservation Extra snapshots, then map each Turo Extra ID to a company-owned canonical Extra.</p></div>
            <a class="action-link" href="/turo/imports">Turo Import</a>
        </header>

        <?php if ($error !== null): ?><section class="section import-message tone-danger" role="alert"><strong>Extras action stopped</strong><p><?= esc($error) ?></p></section><?php endif; ?>
        <?php if ($success !== null): ?><section class="section import-message tone-success" role="status"><strong><?= esc($success) ?></strong></section><?php endif; ?>
        <?php if (is_array($import_result)): ?>
            <section class="section import-message tone-success" aria-label="Extras import result">
                <strong><?= $import_result['duplicate_file'] ? 'Extras file already imported; no rows duplicated' : 'Turo Extras import complete' ?></strong>
                <div class="import-result-grid extras-result-grid">
                    <div><span>Batch</span><b>#<?= (int) $import_result['batch_id'] ?></b></div>
                    <div><span>Reservations</span><b><?= (int) $import_result['reservations_processed'] ?></b></div>
                    <div><span>Added</span><b><?= (int) $import_result['selections_added'] ?></b></div>
                    <div><span>Updated</span><b><?= (int) $import_result['selections_updated'] ?></b></div>
                    <div><span>Unchanged</span><b><?= (int) $import_result['selections_unchanged'] ?></b></div>
                    <div><span>Removed</span><b><?= (int) $import_result['selections_removed'] ?></b></div>
                    <div><span>Unmapped IDs</span><b><?= (int) $import_result['unmapped_source_extra_ids'] ?></b></div>
                    <div><span>Invalid reservations</span><b><?= (int) $import_result['invalid_reservations'] ?></b></div>
                    <div><span>Exporter failures</span><b><?= (int) $import_result['export_failures'] ?></b></div>
                </div>
            </section>
        <?php endif; ?>

        <section class="extras-foundation-metrics" aria-label="Extras foundation status">
            <article><span>Canonical Extras</span><strong><?= count($workspace['catalog']) ?></strong></article>
            <article><span>Current selections</span><strong><?= (int) $workspace['counts']['selection_count'] ?></strong></article>
            <article><span>Unmapped source IDs</span><strong><?= (int) $workspace['counts']['unmapped_count'] ?></strong></article>
            <article><span>Snapshots preserved</span><strong><?= (int) $workspace['counts']['snapshot_count'] ?></strong></article>
        </section>

        <section class="section" aria-labelledby="export-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Browser-side source capture</p><h2 id="export-heading">Export reservation Extras</h2></div><span class="count-pill"><?= count($workspace['reservation_ids']) ?> need first snapshot</span></div>
            <p>These IDs belong to the active company and have no complete Extras snapshot yet. Copy them, open an authenticated <strong>turo.com</strong> tab, paste <code>tools/turo-extras-exporter.js</code> into DevTools, then run <code>FleetOSTuroExtrasExporter.run(ids)</code>.</p>
            <label for="extras-reservation-ids">Reservation IDs needing first Extras refresh</label>
            <textarea id="extras-reservation-ids" class="source-id-list" rows="6" readonly><?= esc($reservationIds) ?></textarea>
            <button class="secondary-action" type="button" data-copy-target="extras-reservation-ids"<?= $reservationIds === '' ? ' disabled' : '' ?>>Copy reservation IDs</button>
            <p class="muted" data-copy-status aria-live="polite">The exporter downloads sanitized JSON locally. It never exports cookies, authorization headers, messages, or guest contact data.</p>
        </section>

        <section class="section import-panel" aria-labelledby="extras-upload-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Sanitized JSON only</p><h2 id="extras-upload-heading">Import reservation Extras</h2></div><span class="count-pill">fleetos-turo-extras-v1</span></div>
            <form class="upload-form" action="/turo/extras/import" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label class="file-drop" for="extras_json"><span>Choose FleetOS Turo Extras JSON</span><input id="extras_json" name="extras_json" type="file" accept=".json,application/json" required></label>
                <button class="primary-action" type="submit">Import Extras Snapshot</button>
            </form>
            <p class="muted">Maximum 2 MB. Invalid reservation blocks are rejected without causing valid reservations to disappear. Only an explicitly complete snapshot can mark a missing selection removed.</p>
        </section>

        <section class="section" aria-labelledby="unmapped-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Operator decision required</p><h2 id="unmapped-heading">Unmapped Turo Extras</h2></div><span class="count-pill"><?= count($workspace['unmapped']) ?> source IDs</span></div>
            <?php if ($workspace['unmapped'] === []): ?><div class="empty-state"><strong>No unmapped source IDs</strong><p>New Turo Extra IDs will appear here and are never mapped from label or price automatically.</p></div><?php endif; ?>
            <div class="extras-unmapped-grid">
                <?php foreach ($workspace['unmapped'] as $source): ?>
                    <article class="mapping-card extra-source-card">
                        <div class="mapping-card-main"><div><p class="eyebrow">New Turo Extra detected</p><h3><?= esc((string) $source['source_label']) ?></h3></div><span class="count-pill"><?= (int) $source['selection_count'] ?> selection<?= (int) $source['selection_count'] === 1 ? '' : 's' ?></span></div>
                        <dl class="extra-source-facts"><div><dt>Source extraId</dt><dd><?= esc((string) $source['source_extra_id']) ?></dd></div><div><dt>Type</dt><dd><?= esc((string) ($source['source_type'] ?? 'Not supplied')) ?></dd></div><div><dt>Observed price</dt><dd><?= esc($money($source['minimum_price'], (string) $source['currency_code'])) ?><?= (string) $source['minimum_price'] !== (string) $source['maximum_price'] ? ' – ' . esc($money($source['maximum_price'], (string) $source['currency_code'])) : '' ?></dd></div></dl>
                        <?php if ($source['source_description'] !== null): ?><p><?= esc((string) $source['source_description']) ?></p><?php endif; ?>
                        <?php if ($workspace['catalog'] !== []): ?>
                            <form class="extra-map-form" action="/turo/extras/mappings" method="post">
                                <?= csrf_field() ?><input type="hidden" name="source_extra_id" value="<?= esc((string) $source['source_extra_id'], 'attr') ?>">
                                <label>Map to existing Extra<select name="fleet_extra_id" required><option value="">Choose canonical Extra</option><?php foreach ($workspace['catalog'] as $extra): ?><option value="<?= (int) $extra['id'] ?>"><?= esc((string) $extra['display_name']) ?><?= (int) $extra['active'] === 1 ? '' : ' (inactive)' ?></option><?php endforeach; ?></select></label>
                                <button class="secondary-action" type="submit">Map to existing Extra</button>
                            </form>
                        <?php endif; ?>
                        <details class="secondary-disclosure"><summary>Create new canonical Extra</summary>
                            <form class="extra-catalog-form" action="/turo/extras/mappings/create-extra" method="post">
                                <?= csrf_field() ?><input type="hidden" name="source_extra_id" value="<?= esc((string) $source['source_extra_id'], 'attr') ?>"><input type="hidden" name="active" value="1">
                                <label>Stable code<input name="code" type="text" maxlength="80" pattern="[a-z][a-z0-9_]+" required></label>
                                <label>Display name<input name="display_name" type="text" maxlength="190" value="<?= esc((string) $source['source_label'], 'attr') ?>" required></label>
                                <label>Sort order<input name="sort_order" type="number" min="0" max="100000" value="0" required></label>
                                <label class="wide-field">Notes<textarea name="notes" rows="2" maxlength="4000"></textarea></label>
                                <?= view('turo_extras/_fulfillment_fields', ['extra' => []]) ?>
                                <button class="secondary-action" type="submit">Create and map</button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" aria-labelledby="catalog-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Company-owned identity</p><h2 id="catalog-heading">Canonical Extras</h2></div><span class="count-pill"><?= count($workspace['catalog']) ?> configured</span></div>
            <details class="secondary-disclosure"><summary>Add canonical Extra</summary>
                <form class="extra-catalog-form" action="/turo/extras/catalog" method="post">
                    <?= csrf_field() ?><input type="hidden" name="active" value="1">
                    <label>Stable code<input name="code" type="text" maxlength="80" pattern="[a-z][a-z0-9_]+" required></label>
                    <label>Display name<input name="display_name" type="text" maxlength="190" required></label>
                    <label>Sort order<input name="sort_order" type="number" min="0" max="100000" value="0" required></label>
                    <label class="wide-field">Notes<textarea name="notes" rows="2" maxlength="4000"></textarea></label>
                    <?= view('turo_extras/_fulfillment_fields', ['extra' => []]) ?>
                    <button class="primary-action" type="submit">Create Extra</button>
                </form>
            </details>
            <div class="extras-catalog-grid<?= $workspace['catalog'] === [] ? ' is-empty' : '' ?>">
                <?php foreach ($workspace['catalog'] as $extra): ?>
                    <article class="mapping-card"><div class="mapping-card-main"><div><h3><?= esc((string) $extra['display_name']) ?></h3><p><code><?= esc((string) $extra['code']) ?></code></p></div><span class="status-badge <?= (int) $extra['active'] === 1 ? 'tone-success' : 'tone-neutral' ?>"><?= (int) $extra['active'] === 1 ? 'Active' : 'Inactive' ?></span></div>
                        <p class="muted"><?= (int) $extra['mapping_count'] ?> source mapping<?= (int) $extra['mapping_count'] === 1 ? '' : 's' ?> · <?= (int) $extra['selection_count'] ?> current selection<?= (int) $extra['selection_count'] === 1 ? '' : 's' ?></p>
                        <p class="muted">Fulfillment: <strong><?= ($extra['fulfillment_type'] ?? 'none') === 'none' ? 'Not configured' : esc(ucwords(str_replace('_', ' ', (string) $extra['fulfillment_type']))) ?></strong><?php if (($extra['fulfillment_phase'] ?? null) !== null): ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $extra['fulfillment_phase']))) ?><?php endif; ?><?= (int) ($extra['requires_operator_confirmation'] ?? 0) === 1 ? ' · Requires confirmation' : '' ?><?= (int) ($extra['readiness_blocking'] ?? 0) === 1 ? ' · Blocks dispatch' : '' ?></p>
                        <details class="secondary-disclosure"><summary>Edit catalog record</summary><form class="extra-catalog-form" action="/turo/extras/catalog/<?= (int) $extra['id'] ?>" method="post">
                            <?= csrf_field() ?><input type="hidden" name="active" value="0">
                            <label>Stable code<input name="code" value="<?= esc((string) $extra['code'], 'attr') ?>" maxlength="80" pattern="[a-z][a-z0-9_]+" required></label>
                            <label>Display name<input name="display_name" value="<?= esc((string) $extra['display_name'], 'attr') ?>" maxlength="190" required></label>
                            <label>Sort order<input name="sort_order" type="number" min="0" max="100000" value="<?= (int) $extra['sort_order'] ?>" required></label>
                            <label class="checkbox-field"><input name="active" type="checkbox" value="1"<?= (int) $extra['active'] === 1 ? ' checked' : '' ?>> Active</label>
                            <label class="wide-field">Notes<textarea name="notes" rows="2" maxlength="4000"><?= esc((string) ($extra['notes'] ?? '')) ?></textarea></label>
                            <?= view('turo_extras/_fulfillment_fields', ['extra' => $extra]) ?>
                            <button class="secondary-action" type="submit">Save Extra</button>
                        </form></details>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" aria-labelledby="mappings-heading"><div class="section-heading split-heading"><div><p class="eyebrow">Current interpretation</p><h2 id="mappings-heading">Turo source mappings</h2></div><span class="count-pill"><?= count($workspace['mappings']) ?> mappings</span></div>
            <?php if ($workspace['mappings'] === []): ?><div class="empty-state"><strong>No mappings yet</strong><p>Import sanitized reservation Extras, then map each observed source ID explicitly.</p></div><?php endif; ?>
            <div class="financial-table-scroll" tabindex="0" role="region" aria-label="Scrollable Turo Extra mappings"><table class="financial-results-table extras-mapping-table"><thead><tr><th scope="col">Turo source</th><th scope="col">Canonical Extra</th><th scope="col">Last seen</th><th scope="col">Remap</th></tr></thead><tbody>
                <?php foreach ($workspace['mappings'] as $mapping): ?><tr><th scope="row"><?= esc((string) ($mapping['latest_source_label'] ?? 'Unlabeled')) ?><small>ID <?= esc((string) $mapping['source_extra_id']) ?> · <?= esc((string) ($mapping['source_type'] ?? 'type not supplied')) ?></small></th><td><?= esc((string) $mapping['fleet_extra_name']) ?></td><td><?= esc((string) $mapping['last_seen_at']) ?></td><td><form class="inline-remap-form" action="/turo/extras/mappings" method="post"><?= csrf_field() ?><input type="hidden" name="source_extra_id" value="<?= esc((string) $mapping['source_extra_id'], 'attr') ?>"><label><span class="sr-only">Canonical Extra</span><select name="fleet_extra_id" required><?php foreach ($workspace['catalog'] as $extra): ?><option value="<?= (int) $extra['id'] ?>"<?= (int) $mapping['fleet_extra_id'] === (int) $extra['id'] ? ' selected' : '' ?>><?= esc((string) $extra['display_name']) ?></option><?php endforeach; ?></select></label><label><span class="sr-only">Reason required when changing mapping</span><input name="reason" placeholder="Reason if remapping" maxlength="4000"></label><button class="compact-action" type="submit">Save</button></form></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="section financial-firewall-note"><strong>Financial firewall</strong><p>Reservation Extra prices are commercial attribution facts. They are not added to Realized Operating Revenue, recoveries, costs, or net realized results.</p></section>
        <?= view('fleet_command_center/components/footer') ?>
    </main>
</div>
<?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
