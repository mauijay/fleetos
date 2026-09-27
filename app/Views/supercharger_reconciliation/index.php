<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supercharger Reimbursements · FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame import-frame incidentals-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main incidentals-main" tabindex="-1">
        <header class="top-status incidentals-header">
            <div>
                <p class="eyebrow">Tesla charging expenses</p>
                <h1>Supercharger Reimbursements</h1>
                <p class="status-copy">Match source-preserved Tesla charges to authoritative guest custody, then compare eligible cost with Turo On-trip EV charging. Post-trip EV charging remains a separate Turo workflow.</p>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice success" role="status"><?= esc($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= esc($error) ?></div><?php endif; ?>

        <section class="section" aria-labelledby="tesla-import-title">
            <div class="section-heading">
                <div><p class="eyebrow">Source import</p><h2 id="tesla-import-title">Tesla charging history</h2></div>
            </div>
            <p>Upload Tesla’s downloadable history as CSV. Offset-bearing timestamps, invoice lines, zero-cost sessions, and rejected rows are preserved.</p>
            <form action="/operations/supercharger-reimbursements/import" method="post" enctype="multipart/form-data" class="issue-filters compact-action">
                <?= csrf_field() ?>
                <label>Tesla CSV<input type="file" name="tesla_csv" accept=".csv,text/csv,.txt" required></label>
                <button class="primary-action" type="submit">Import Tesla charging</button>
            </form>
        </section>

        <section class="section" aria-labelledby="reconciliation-title">
            <div class="section-heading incidentals-queue-heading">
                <div><p class="eyebrow">Trip-level workflow</p><h2 id="reconciliation-title">Reconciliation</h2></div>
                <div class="incidental-summary" aria-label="Reconciliation summary">
                    <span><span>Attention</span><strong><?= (int) $workspace['summary']['attention'] ?></strong></span>
                    <span><span>Reconciled</span><strong><?= (int) $workspace['summary']['reconciled'] ?></strong></span>
                    <span><span>Review</span><strong><?= (int) $workspace['summary']['review'] ?></strong></span>
                </div>
            </div>
            <nav class="queue-tabs" aria-label="Supercharger reconciliation filters">
                <?php foreach (['attention' => 'Needs attention', 'reconciled' => 'Reconciled', 'host' => 'Host expense', 'review' => 'Review', 'all' => 'All'] as $code => $label): ?>
                    <a href="/operations/supercharger-reimbursements?filter=<?= esc($code, 'attr') ?>" class="<?= $workspace['filter'] === $code ? 'is-active' : '' ?>"<?= $workspace['filter'] === $code ? ' aria-current="page"' : '' ?>><?= esc($label) ?> <span><?= (int) $workspace['summary'][$code] ?></span></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($workspace['cases'] === [] && $workspace['sessions'] === [] && $workspace['import_issues'] === []): ?>
                <div class="empty-state">No Supercharger reconciliation records match this view.</div>
            <?php endif; ?>

            <div class="incidental-list">
                <?php foreach ($workspace['cases'] as $case): ?>
                    <?php
                    $money = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
                    $status = ucwords(str_replace('_', ' ', (string) $case['financial_status']));
                    ?>
                    <article class="incidental-card" id="case-<?= (int) $case['id'] ?>">
                        <header class="incidental-card-heading">
                            <div class="incidental-identity">
                                <p class="incidental-card-kicker">Trip-level case</p>
                                <h3><?= esc($case['vehicle_name'] ?: $case['fleet_code']) ?></h3>
                                <p class="incidental-trip-identity"><strong><?= esc($case['guest_name'] ?: 'Guest unavailable') ?></strong><span>Trip <?= esc($case['turo_trip_id']) ?></span></p>
                            </div>
                            <div class="incidental-current-state"><span class="status-badge"><?= esc($status) ?></span><strong><?= esc($money((int) $case['outstanding_cents'])) ?> outstanding</strong></div>
                        </header>
                        <dl class="incidental-facts">
                            <div><dt>Eligible Tesla cost</dt><dd><?= esc($money((int) $case['eligible_cost_cents'])) ?></dd></div>
                            <div><dt>Turo On-trip EV charging</dt><dd><?= esc($money((int) $case['turo_reimbursed_cents'])) ?></dd></div>
                            <div><dt>Invoice workflow</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) $case['workflow_state_code']))) ?></dd></div>
                            <div><dt>Post-trip EV charging</dt><dd>$<?= esc(number_format((float) $case['post_trip_ev_charging_amount'], 2)) ?> · excluded from this match</dd></div>
                        </dl>
                        <details>
                            <summary>Tesla source detail · <?= count($case['line_items']) ?> line<?= count($case['line_items']) === 1 ? '' : 's' ?></summary>
                            <?php foreach ($case['line_items'] as $line): ?>
                                <div class="incidental-card-context">
                                    <strong><?= esc($line['fee_description']) ?> · $<?= esc(number_format((float) $line['total_inc_vat_amount'], 2)) ?></strong>
                                    <span><?= esc($line['source_started_at']) ?> · <?= esc($line['site_location_name'] ?: 'Location unavailable') ?></span>
                                    <span>Invoice <?= esc($line['invoice_number']) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $line['custody_classification']))) ?> by <?= esc((string) $line['custody_basis_code']) ?></span>
                                    <?php if ($line['invoice_url']): ?><a href="<?= esc($line['invoice_url'], 'attr') ?>" rel="noopener noreferrer">Open Tesla invoice</a><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($case['audits'] !== []): ?><p><?= count($case['audits']) ?> recorded workflow change<?= count($case['audits']) === 1 ? '' : 's' ?> in audit history.</p><?php endif; ?>
                        </details>
                        <form action="/operations/supercharger-reimbursements/<?= (int) $case['id'] ?>/workflow" method="post" class="issue-filters compact-action">
                            <?= csrf_field() ?>
                            <label>Invoice workflow<select name="workflow_state_code">
                                <?php foreach (['not_submitted' => 'Not submitted', 'submitted' => 'Submitted', 'no_invoice_needed' => 'No invoice needed', 'waived' => 'Waived'] as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $case['workflow_state_code'] === $code ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
                            </select></label>
                            <label>Invoice reference<input name="invoice_reference" value="<?= esc((string) ($case['invoice_reference'] ?? ''), 'attr') ?>"></label>
                            <label>Operator note<input name="workflow_note" value="<?= esc((string) ($case['workflow_note'] ?? ''), 'attr') ?>"></label>
                            <button class="primary-action" type="submit">Save workflow</button>
                        </form>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($workspace['sessions'] as $session): ?>
                    <?php
                    $sessionContext = match ($session['financial_status']) {
                        'host_expense' => 'Host expense — charging occurred outside guest custody.',
                        'review' => 'Review required — authoritative custody is not confirmed.',
                        default => 'No reimbursable Tesla cost was recorded.',
                    };
                    ?>
                    <article class="incidental-card">
                        <header class="incidental-card-heading">
                            <div class="incidental-identity"><p class="incidental-card-kicker">Tesla session</p><h3><?= esc($session['vehicle_name'] ?: $session['fleet_code']) ?></h3><p><?= esc($session['source_started_at']) ?></p></div>
                            <div class="incidental-current-state"><strong>$<?= esc(number_format((float) $session['cost_amount'], 2)) ?></strong><span><?= esc(ucwords(str_replace('_', ' ', (string) $session['financial_status']))) ?></span></div>
                        </header>
                        <p><strong><?= esc($sessionContext) ?></strong></p>
                        <p><?= esc($session['charging_location'] ?: 'Location unavailable') ?> · Basis: <?= esc((string) $session['custody_basis_code']) ?><?= $session['candidate_turo_trip_id'] ? ' · candidate trip ' . esc($session['candidate_turo_trip_id']) : '' ?></p>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($workspace['import_issues'] as $issue): ?>
                    <article class="incidental-card"><p class="eyebrow">Source row review</p><h3><?= esc(ucwords(str_replace('_', ' ', (string) $issue['issue_code']))) ?></h3><p><?= esc($issue['source_filename']) ?> · row <?= (int) $issue['row_number'] ?></p><p><?= esc((string) $issue['issue_detail']) ?></p></article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>
<?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
