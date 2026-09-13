<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed> $workspace */
/** @var list<array{label:string,href:string,active:string}> $navigation */
$receipt = $workspace['receipt'];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Classify Airport Receipt · FleetOS</title><?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame import-frame airport-receipts-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main airport-receipts-main" tabindex="-1">
        <header class="top-status"><div><p class="eyebrow">Airport Receipts &amp; Follow-up</p><h1>Classify Airport Receipt</h1><p class="status-copy">Send this evidence to an existing legacy claim, an airport operations expense, unresolved review, non-business, or duplicate history.</p></div><a class="action-link" href="/operations/airport/reimbursements?filter=action">Action queue</a></header>
        <?php if ($notice): ?><div class="notice success" role="status"><?= esc($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= esc($error) ?></div><?php endif; ?>

        <section class="section receipt-match-layout">
            <div class="receipt-panel">
                <p class="eyebrow">Receipt evidence</p><h2><?= esc((string) ($receipt['original_filename'] ?? $receipt['file_original_filename'] ?? 'Receipt')) ?></h2>
                <?php if (($receipt['file_id'] ?? null) !== null): ?><div class="receipt-preview"><iframe src="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/file" title="Receipt preview"></iframe></div><?php endif; ?>
                <dl class="issue-facts"><div><dt>Classification</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) ($receipt['receipt_classification'] ?? 'unresolved')))) ?></dd></div><div><dt>Date</dt><dd><?= esc((string) ($receipt['document_date'] ?? 'Missing')) ?></dd></div><div><dt>Amount</dt><dd>$<?= esc(number_format((float) ($receipt['amount'] ?? 0), 2)) ?></dd></div><div><dt>Ticket</dt><dd><?= esc((string) ($receipt['ticket_number'] ?? 'Not entered')) ?></dd></div></dl>
                <details class="secondary-work"><summary><strong>Edit receipt facts</strong></summary><form class="issue-filters" action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/metadata" method="post"><?= csrf_field() ?><label>Type<select name="attachment_type"><option value="paid_receipt">Paid receipt</option><option value="parking_ticket">Parking ticket</option><option value="exit_receipt">Exit receipt</option><option value="payment_screenshot">Payment screenshot</option><option value="other_evidence">Other evidence</option></select></label><label>Date<input name="document_date" type="date" value="<?= esc((string) ($receipt['document_date'] ?? ''), 'attr') ?>"></label><label>Amount<input name="amount" inputmode="decimal" value="<?= esc((string) ($receipt['amount'] ?? ''), 'attr') ?>"></label><label>Ticket<input name="ticket_number" value="<?= esc((string) ($receipt['ticket_number'] ?? ''), 'attr') ?>"></label><button class="secondary-action" type="submit">Update metadata</button></form></details>
                <div class="classification-actions">
                    <form action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/classification" method="post"><?= csrf_field() ?><input type="hidden" name="receipt_classification" value="unresolved"><button class="secondary-action" type="submit">Leave unresolved</button></form>
                    <form action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/classification" method="post"><?= csrf_field() ?><input type="hidden" name="receipt_classification" value="non_business"><button class="secondary-action" type="submit">Non-business</button></form>
                    <form action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/classification" method="post"><?= csrf_field() ?><input type="hidden" name="receipt_classification" value="duplicate"><button class="secondary-action" type="submit">Duplicate</button></form>
                </div>
            </div>

            <div class="candidate-panel">
                <div class="section-heading"><div><p class="eyebrow">Historical trip reimbursement</p><h2>Existing legacy claims</h2></div></div>
                <div class="import-message tone-warning"><strong>New HNL reimbursement claims are inactive.</strong><p>Only an existing non-terminal historical claim can be matched. A current gate failure does not create a claim.</p></div>
                <form class="issue-filters" method="get"><label>Search existing claims<input name="q" value="<?= esc((string) $workspace['query'], 'attr') ?>" placeholder="Spaceship, guest, trip ID"></label><button class="secondary-action" type="submit">Search</button></form>
                <?php if ($workspace['candidates'] === []): ?><div class="empty-state">No matching active historical claim was found.</div><?php endif; ?>
                <div class="mapping-list"><?php foreach ($workspace['candidates'] as $candidate): ?><article class="mapping-card tone-info"><div class="mapping-card-main"><div><span class="status-badge tone-info"><?= esc($candidate['match_label']) ?></span><h3><?= esc((string) ($candidate['fleet_code'] ?? 'Vehicle')) ?></h3><p><?= esc(ucfirst((string) $candidate['movement_type'])) ?> · <?= esc((string) $candidate['scheduled_at']) ?> · <?= esc((string) ($candidate['guest_name'] ?? 'Guest not captured')) ?></p></div><dl class="issue-facts"><div><dt>Trip</dt><dd><?= esc((string) ($candidate['turo_trip_id'] ?? 'N/A')) ?></dd></div><div><dt>Claim</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) $candidate['existing_claim_status']))) ?></dd></div></dl></div><ul class="issue-list"><?php foreach ($candidate['reasons'] as $reason): ?><li><?= esc($reason) ?></li><?php endforeach; ?></ul><form class="resolution-form" action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/match" method="post"><?= csrf_field() ?><input type="hidden" name="airport_movement_workflow_id" value="<?= (int) $candidate['id'] ?>"><p class="muted">Reuses this existing historical claim; it does not create an expense or a new claim.</p><button class="primary-action" type="submit">Match existing claim</button></form></article><?php endforeach; ?></div>

                <div class="section-heading"><div><p class="eyebrow">Operating cost</p><h2>Airport operations runs</h2></div></div>
                <?php if ($workspace['operation_runs'] === []): ?><div class="empty-state">No nearby airport run found. Create one below without linking a guest trip.</div><?php endif; ?>
                <div class="mapping-list"><?php foreach ($workspace['operation_runs'] as $run): ?><article class="mapping-card tone-info"><h3><?= esc((string) $run['purpose']) ?></h3><p><?= esc((string) $run['run_date']) ?> · <?= esc((string) ($run['chase_vehicle_description'] ?? $run['chase_vehicle_type'])) ?></p><form class="resolution-form" action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/operations-expense" method="post"><?= csrf_field() ?><input type="hidden" name="airport_operations_run_id" value="<?= (int) $run['id'] ?>"><label>Category<select name="expense_category"><option value="parking">Parking</option><option value="fuel">Fuel</option><option value="ev_charging">EV charging</option><option value="car_wash">Car wash</option><option value="supplies">Supplies</option><option value="toll">Airport-run toll</option><option value="airport_access_fee">Airport access fee</option><option value="mileage_reimbursement">Mileage</option><option value="other">Other</option></select></label><label>Business purpose<textarea name="business_purpose_note">Airport operations expense</textarea></label><button class="primary-action" type="submit">Assign to this run</button></form></article><?php endforeach; ?></div>
                <details class="secondary-work"><summary><strong>Create a new airport operations run</strong></summary><form class="issue-filters" action="/operations/airport/reimbursements/receipts/<?= (int) $receipt['id'] ?>/operations-expense" method="post"><?= csrf_field() ?><input type="hidden" name="create_airport_operations_run" value="1"><label>Run date<input name="run_date" type="date" value="<?= esc((string) ($receipt['document_date'] ?? date('Y-m-d')), 'attr') ?>"></label><label>Purpose<input name="purpose" value="Airport operations run"></label><label>Category<select name="expense_category"><option value="parking">Parking</option><option value="fuel">Fuel</option><option value="ev_charging">EV charging</option><option value="car_wash">Car wash</option><option value="supplies">Supplies</option><option value="toll">Airport-run toll</option><option value="other">Other</option></select></label><label>Business purpose<textarea name="business_purpose_note">Airport operations expense</textarea></label><button class="primary-action" type="submit">Create run and assign</button></form></details>
            </div>
        </section>
        <?= view('fleet_command_center/components/footer') ?>
    </main>
</div>
</body>
</html>
