<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed> $inbox */
/** @var list<array{label:string,href:string,active:string}> $navigation */
$summary = $inbox['summary'];
$policy = $inbox['policy'];
$filter = $inbox['filter'];
$statusLabel = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Airport Receipts &amp; Follow-up · FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame import-frame airport-receipts-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main airport-receipts-main" tabindex="-1">
        <header class="top-status airport-receipts-header">
            <div>
                <p class="eyebrow">Airport operations evidence</p>
                <h1>Airport Receipts &amp; Follow-up</h1>
                <p class="status-copy">Resolve receipt setup first, then move historical Turo Access claims through filing and outcome. Expected reimbursement is not revenue.</p>
            </div>
            <a class="action-link" href="/operations/airport">Airport operations</a>
        </header>

        <?php if ($notice): ?><div class="notice success" role="status"><?= esc($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= esc($error) ?></div><?php endif; ?>

        <section class="section hnl-policy" aria-labelledby="hnl-policy-title">
            <div class="section-heading"><div><p class="eyebrow">Current HNL policy</p><h2 id="hnl-policy-title">New reimbursement claims are inactive</h2></div><a class="text-link" href="<?= esc((string) $policy['reference'], 'attr') ?>" rel="noreferrer">Policy reference</a></div>
            <p>The $<?= esc(number_format((float) $policy['host_parking_fee'], 2)) ?> host parking fee is deducted from host earnings as an operating cost. Do not request guest reimbursement. The historical $<?= esc(number_format((float) $policy['legacy_cap'], 2)) ?> cap remains unchanged for stored legacy claims.</p>
        </section>

        <section class="section airport-action-queue" aria-labelledby="airport-follow-up-title">
            <div class="section-heading airport-queue-heading">
                <div><p class="eyebrow">Action queue</p><h2 id="airport-follow-up-title">Airport follow-up</h2></div>
                <div class="airport-summary" aria-label="Airport follow-up summary">
                    <span><span>Needs setup</span><strong><?= (int) $summary['needs_setup'] ?></strong></span>
                    <span><span>Ready</span><strong><?= (int) $summary['ready_to_file'] ?></strong></span>
                    <span><span>Awaiting outcome</span><strong><?= (int) $summary['filed_pending'] ?></strong></span>
                </div>
            </div>

            <nav class="queue-tabs" aria-label="Airport receipt filters">
                <?php foreach (['action' => 'Action needed', 'ready' => 'Ready to file', 'filed' => 'Filed', 'history' => 'History', 'all' => 'All'] as $code => $label): ?>
                    <a href="/operations/airport/reimbursements?filter=<?= esc($code, 'attr') ?>" class="<?= $filter === $code ? 'is-active' : '' ?>"<?= $filter === $code ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="airport-card-list">
                <?php foreach ($inbox['incidents'] as $incident): ?>
                    <?php $status = (string) $incident['claim_status']; ?>
                    <article class="airport-action-card tone-<?= $status === 'ready_to_file' ? 'success' : ($status === 'filed' ? 'info' : 'warning') ?>">
                        <div class="airport-card-heading">
                            <div><p class="eyebrow">Legacy claim #<?= (int) $incident['id'] ?></p><h3><?= esc((string) ($incident['fleet_code'] ?? 'Unknown vehicle')) ?> · Trip <?= esc((string) ($incident['turo_trip_id'] ?? 'not linked')) ?></h3></div>
                            <span class="status-pill"><?= esc($statusLabel($status)) ?></span>
                        </div>
                        <dl class="issue-facts">
                            <div><dt>Incident</dt><dd><?= esc((string) ($incident['incident_at'] ?? 'Not recorded')) ?></dd></div>
                            <div><dt>Paid</dt><dd>$<?= esc(number_format((float) ($incident['parking_amount_paid'] ?? 0), 2)) ?></dd></div>
                            <div><dt>Expected</dt><dd>$<?= esc(number_format((float) ($incident['expected_reimbursement_amount'] ?? 0), 2)) ?></dd></div>
                            <?php if ($status === 'filed'): ?><div><dt>Filed</dt><dd><?= esc((string) ($incident['claim_filed_on'] ?? 'Date missing')) ?><?= trim((string) ($incident['claim_reference'] ?? '')) !== '' ? ' · ' . esc((string) $incident['claim_reference']) : '' ?></dd></div><?php endif; ?>
                        </dl>

                        <?php if ($status === 'not_ready'): ?>
                            <p><strong>Still needed:</strong> <?= esc($incident['missing_readiness'] === [] ? 'review recorded evidence' : implode(', ', $incident['missing_readiness'])) ?>.</p>
                            <details class="secondary-work"><summary>Add paid receipt evidence</summary>
                                <form class="issue-filters" action="/operations/airport/reimbursements/<?= (int) $incident['id'] ?>/receipt" method="post" enctype="multipart/form-data"><?= csrf_field() ?><label>Receipt file<input name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></label><label>Receipt type<select name="attachment_type"><option value="paid_receipt">Paid receipt</option><option value="parking_ticket">Parking ticket</option><option value="exit_receipt">Exit receipt</option><option value="payment_screenshot">Payment screenshot</option><option value="other_evidence">Other evidence</option></select></label><label>Amount<input name="amount" inputmode="decimal"></label><label>Ticket<input name="ticket_number"></label><button class="primary-action" type="submit">Attach receipt</button></form>
                            </details>
                        <?php elseif ($status === 'ready_to_file'): ?>
                            <form class="issue-filters compact-action" action="/operations/airport/reimbursements/<?= (int) $incident['id'] ?>/filed" method="post"><?= csrf_field() ?><label>Claim reference<input name="claim_reference"></label><label>Claimed amount<input name="claimed_amount" inputmode="decimal" value="<?= esc(number_format((float) ($incident['expected_reimbursement_amount'] ?? 0), 2, '.', ''), 'attr') ?>"></label><button class="primary-action" type="submit">Mark filed</button></form>
                        <?php elseif ($status === 'filed'): ?>
                            <div class="filed-outcomes">
                                <form class="compact-action" action="/operations/airport/reimbursements/<?= (int) $incident['id'] ?>/reimbursed" method="post"><?= csrf_field() ?><label>Received amount<input name="reimbursed_amount" inputmode="decimal" value="<?= esc(number_format((float) ($incident['claimed_amount'] ?? $incident['expected_reimbursement_amount'] ?? 0), 2, '.', ''), 'attr') ?>"></label><button class="primary-action" type="submit">Mark reimbursed</button></form>
                                <form class="compact-action" action="/operations/airport/reimbursements/<?= (int) $incident['id'] ?>/denied" method="post"><?= csrf_field() ?><label>Denial reason<input name="denial_reason" required></label><button class="secondary-action" type="submit">Mark denied</button></form>
                            </div>
                        <?php endif; ?>

                        <?php if ($incident['receipts'] !== []): ?><ul class="receipt-evidence"><?php foreach ($incident['receipts'] as $receipt): ?><li><?= esc((string) ($receipt['original_filename'] ?? 'Receipt evidence')) ?><?php if (($receipt['file_id'] ?? null) !== null): ?> · <a class="text-link" href="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/file">Preview</a><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($inbox['receipt_actions'] as $receipt): ?>
                    <?php $classification = (string) ($receipt['receipt_classification'] ?? 'unresolved'); ?>
                    <article class="airport-action-card tone-<?= in_array($classification, ['non_business', 'duplicate'], true) ? 'neutral' : 'warning' ?>">
                        <div class="airport-card-heading"><div><p class="eyebrow">Receipt #<?= (int) $receipt['id'] ?></p><h3><?= esc((string) ($receipt['original_filename'] ?? 'Airport receipt')) ?></h3></div><span class="status-pill"><?= esc($statusLabel($classification)) ?></span></div>
                        <p><?= esc((string) ($receipt['document_date'] ?? 'No date')) ?> · $<?= esc(number_format((float) ($receipt['amount'] ?? 0), 2)) ?></p>
                        <?php if (! in_array($classification, ['non_business', 'duplicate'], true)): ?><p><strong>Next:</strong> <?= $classification === 'unresolved' ? 'choose the correct business classification and match target' : ($classification === 'trip_reimbursement' ? 'link to an existing legacy claim' : 'link to an airport operations expense') ?>.</p><a class="primary-action button-link" href="/operations/airport/reimbursements/match/<?= (int) $receipt['id'] ?>">Review receipt</a><?php endif; ?>
                        <?php if (($receipt['file_id'] ?? null) !== null): ?><a class="text-link" href="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/file">Preview evidence</a><?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php if ($inbox['incidents'] === [] && $inbox['receipt_actions'] === []): ?><div class="empty-state">No airport receipt work matches this view.</div><?php endif; ?>
            </div>

            <?php if ($inbox['incident_pagination']['total'] > $inbox['incident_pagination']['per_page']): ?><nav class="pager-wrap" aria-label="Claim pages"><?= $inbox['incident_pagination']['links'] ?></nav><?php endif; ?>
            <?php if ($inbox['receipt_pagination']['total'] > $inbox['receipt_pagination']['per_page']): ?><nav class="pager-wrap" aria-label="Receipt pages"><?= $inbox['receipt_pagination']['links'] ?></nav><?php endif; ?>
        </section>

        <details class="section secondary-work"><summary><strong>Capture or log airport evidence</strong><span>Secondary workflow</span></summary>
            <div class="capture-grid">
                <section><h2>Needs classification</h2><form class="issue-filters" action="/operations/airport/reimbursements/unmatched-receipt" method="post" enctype="multipart/form-data"><?= csrf_field() ?><label>Receipt file<input name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></label><label>Vehicle (optional)<select name="fleet_vehicle_id"><option value="">Unassigned — match later</option><?php foreach ($inbox['fleet_vehicles'] as $vehicle): ?><option value="<?= (int) $vehicle['id'] ?>"><?= esc(($vehicle['fleet_number'] ?? null) === null ? (string) $vehicle['fleet_code'] : 'Fleet #' . $vehicle['fleet_number'] . ' · ' . $vehicle['fleet_code']) ?></option><?php endforeach; ?></select></label><label>Date<input name="document_date" type="date"></label><label>Amount<input name="amount" inputmode="decimal"></label><label>Classification<select name="receipt_classification"><option value="unresolved">Unresolved</option><option value="trip_reimbursement">Legacy trip reimbursement</option><option value="airport_operations_expense">Airport operations expense</option><option value="non_business">Non-business</option><option value="duplicate">Duplicate</option></select></label><button class="primary-action" type="submit">Capture receipt</button></form></section>
                <section><h2>Log airport run expense</h2><form class="issue-filters" action="/operations/airport/reimbursements/run-expense" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="create_airport_operations_run" value="1"><label>Receipt file<input name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required></label><label>Expense date<input name="expense_date" type="date"></label><label>Amount<input name="amount" inputmode="decimal"></label><label>Category<select name="expense_category"><option value="parking">Parking</option><option value="fuel">Fuel</option><option value="ev_charging">EV charging</option><option value="car_wash">Car wash</option><option value="supplies">Supplies</option><option value="toll">Airport-run toll</option><option value="airport_access_fee">Airport access fee</option><option value="mileage_reimbursement">Mileage</option><option value="other">Other</option></select></label><label>Run date<input name="run_date" type="date"></label><label>Purpose<input name="purpose" value="Airport operations run"></label><label>Business purpose<textarea name="business_purpose_note">Airport operations expense</textarea></label><button class="primary-action" type="submit">Save run expense</button></form></section>
            </div>
        </details>

        <?= view('fleet_command_center/components/footer') ?>
    </main>
</div>
</body>
</html>
