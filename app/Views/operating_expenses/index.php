<?php
/** @var array{css:?string,js:?string} $assets */
/** @var array<string,mixed> $workspace */
/** @var list<array{label:string,href:string,active:string}> $navigation */
$view = (string) $workspace['view'];
$filters = $workspace['filters'];
$categories = $workspace['categories'];
$vehicles = $workspace['vehicles'];
$trips = $workspace['trips'];
$value = static fn (string $key, mixed $fallback = ''): mixed => $formData[$key] ?? $fallback;
$vehicleLabel = static fn (array $row): string => ($row['fleet_number'] ?? null) === null ? (string) ($row['display_name'] ?? $row['fleet_code']) : 'Fleet #' . $row['fleet_number'] . ' · ' . ($row['display_name'] ?? $row['fleet_code']);
$statusLabel = static fn (string $code): string => ucwords(str_replace('_', ' ', $code));
$businessPurposeError = isset($errors['business_purpose']) ? (string) $errors['business_purpose'] : null;
$newExpensePurposeInvalid = $businessPurposeError !== null && $pendingReceiptId === 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expenses &amp; Receipts · FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame import-frame expenses-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main operator-main expenses-main" tabindex="-1">
        <header class="top-status expenses-header">
            <div><p class="eyebrow">Generic operating costs</p><h1>Expenses &amp; Receipts</h1><p class="status-copy">Capture ordinary fleet spending and resolve uploaded receipt evidence. Airport, maintenance, charging, acquisition, financing, insurance, and imported Turo costs remain in their specialized workflows.</p></div>
            <div class="expense-summary"><span>Needs attention <strong><?= (int) $workspace['needs_classification'] ?></strong></span><span>Recorded operating expenses <strong>$<?= esc((string) $workspace['recorded_total']) ?></strong></span></div>
        </header>

        <?= view('operating_expenses/components/feedback', ['notice' => $notice, 'warning' => $warning, 'warningHeading' => 'Possible duplicate', 'errors' => $errors]) ?>

        <section class="section operator-work" aria-labelledby="capture-title">
            <div class="section-heading"><div><p class="eyebrow">Quick capture</p><h2 id="capture-title">Record operating evidence</h2></div><p class="muted">Receipts are optional. A missing receipt is not queue work.</p></div>
            <div class="expense-capture-grid">
                <section class="capture-panel primary-capture"><h3>Upload receipt</h3><p>Save evidence now and classify it when ready.</p>
                    <form class="expense-form" action="/operations/expenses/receipts" method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?><label>Receipt file<input name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required></label>
                        <label>Date shown (optional)<input name="document_date" type="date" value="<?= esc((string) $value('document_date'), 'attr') ?>"></label>
                        <label>Amount shown (optional)<input name="observed_amount" inputmode="decimal" value="<?= esc((string) $value('observed_amount'), 'attr') ?>"></label>
                        <label>Vendor (optional)<input name="vendor" maxlength="190" value="<?= esc((string) $value('vendor'), 'attr') ?>"></label>
                        <label class="form-wide">Note (optional)<textarea name="note"><?= esc((string) $value('note')) ?></textarea></label>
                        <button class="primary-action" type="submit">Upload receipt</button>
                    </form>
                </section>
                <details class="secondary-work capture-panel"<?= ($warning || $errors !== []) && $pendingReceiptId === 0 ? ' open' : '' ?>><summary><strong>New expense</strong><span>Receipt optional</span></summary>
                    <form class="expense-form" action="/operations/expenses" method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?><?php if ((string) $value('confirm_possible_duplicate') === '1' && $pendingReceiptId === 0): ?><input type="hidden" name="confirm_possible_duplicate" value="1"><?php endif; ?>
                        <label>Date<input name="expense_date" type="date" required value="<?= esc((string) $value('expense_date', date('Y-m-d')), 'attr') ?>"></label>
                        <label>Amount<input name="amount" inputmode="decimal" required placeholder="0.00" value="<?= esc((string) $value('amount'), 'attr') ?>"></label>
                        <label>Category<select name="category_code" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= esc((string) $category['code'], 'attr') ?>"<?= $value('category_code') === $category['code'] ? ' selected' : '' ?>><?= esc((string) $category['name']) ?></option><?php endforeach; ?></select></label>
                        <label>Vehicle (optional)<select name="fleet_vehicle_id"><option value="">Fleet-wide</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= (int) $vehicle['id'] ?>"<?= (int) $value('fleet_vehicle_id') === (int) $vehicle['id'] ? ' selected' : '' ?>><?= esc($vehicleLabel($vehicle)) ?></option><?php endforeach; ?></select></label>
                        <label class="form-wide">Trip (optional)<select name="turo_trip_normalized_id"><option value="">No trip</option><?php foreach ($trips as $trip): ?><option value="<?= (int) $trip['id'] ?>"<?= (int) $value('turo_trip_normalized_id') === (int) $trip['id'] ? ' selected' : '' ?>><?= esc((string) ($trip['turo_trip_id'] ?? 'Trip ' . $trip['id'])) ?> · <?= esc((string) ($trip['guest_name'] ?? 'Guest not captured')) ?></option><?php endforeach; ?></select></label>
                        <label>Vendor (optional)<input name="vendor" maxlength="190" value="<?= esc((string) $value('vendor'), 'attr') ?>"></label>
                        <label>Receipt (optional)<input name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></label>
                        <label>Payment method (optional)<select name="payment_method_code"><option value="">Not captured</option><?php foreach (['business_credit_card' => 'Business credit card','personal_credit_card' => 'Personal credit card','debit_card' => 'Debit card','cash' => 'Cash','bank' => 'Bank','other' => 'Other'] as $code => $label): ?><option value="<?= $code ?>"<?= $value('payment_method_code') === $code ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                        <label>Payment label (optional)<input name="payment_reference" maxlength="120" placeholder="Amex Business Platinum" value="<?= esc((string) $value('payment_reference'), 'attr') ?>"></label>
                        <label class="form-wide">Business purpose / note<input name="business_purpose" value="<?= esc((string) $value('business_purpose'), 'attr') ?>" aria-describedby="new-expense-business-purpose-help<?= $newExpensePurposeInvalid ? ' new-expense-business-purpose-error' : '' ?>"<?= $newExpensePurposeInvalid ? ' aria-invalid="true"' : '' ?>><small id="new-expense-business-purpose-help">Required when category is Other. Never enter account or card numbers.</small><?php if ($newExpensePurposeInvalid): ?><small id="new-expense-business-purpose-error" class="field-error">Required for Other expenses.</small><?php endif; ?></label>
                        <button class="secondary-action" type="submit"><?= $warning && $pendingReceiptId === 0 ? 'Confirm separate expense' : 'Record expense' ?></button>
                    </form>
                </details>
            </div>
        </section>

        <section class="section" aria-labelledby="expense-queue-title">
            <div class="section-heading"><div><p class="eyebrow">Operator workspace</p><h2 id="expense-queue-title"><?= $view === 'needs_attention' ? 'Needs attention' : ($view === 'vehicle' ? 'By vehicle' : ucfirst($view)) ?></h2></div><p class="muted">Recorded operating expenses — generic records only.</p></div>
            <nav class="queue-tabs" aria-label="Expense views"><?php foreach (['needs_attention' => 'Needs attention','recent' => 'Recent','vehicle' => 'By vehicle','history' => 'History'] as $code => $label): ?><a href="/operations/expenses?view=<?= $code ?>" class="<?= $view === $code ? 'is-active' : '' ?>"<?= $view === $code ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav>

            <?php if ($view !== 'needs_attention'): ?><form class="issue-filters" method="get"><input type="hidden" name="view" value="<?= esc($view, 'attr') ?>"><label>Category<select name="category"><option value="">All</option><?php foreach ($categories as $category): ?><option value="<?= esc((string) $category['code'], 'attr') ?>"<?= $filters['category'] === $category['code'] ? ' selected' : '' ?>><?= esc((string) $category['name']) ?></option><?php endforeach; ?></select></label><label>Vehicle<select name="vehicle"><option value="">All</option><option value="fleet"<?= $filters['vehicle'] === 'fleet' ? ' selected' : '' ?>>Fleet-wide</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= (int) $vehicle['id'] ?>"<?= $filters['vehicle'] === (string) $vehicle['id'] ? ' selected' : '' ?>><?= esc($vehicleLabel($vehicle)) ?></option><?php endforeach; ?></select></label><label>Source<select name="source"><option value="">All</option><option value="manual"<?= $filters['source'] === 'manual' ? ' selected' : '' ?>>Manual</option><option value="receipt_inbox"<?= $filters['source'] === 'receipt_inbox' ? ' selected' : '' ?>>Receipt inbox</option></select></label><label>From<input name="from" type="date" value="<?= esc((string) $filters['from'], 'attr') ?>"></label><label>To<input name="to" type="date" value="<?= esc((string) $filters['to'], 'attr') ?>"></label><button class="secondary-action" type="submit">Apply</button></form><?php endif; ?>

            <?php if ($view === 'needs_attention'): ?>
                <p class="domain-guidance">Airport evidence belongs in <a class="text-link" href="/operations/airport/reimbursements?filter=action">Airport Receipts</a>. Do not record it here as a generic expense.</p>
                <?php if ($workspace['receipts']['rows'] === []): ?><div class="empty-state"><h3>Receipt inbox is clear</h3><p>No generic operating receipts need classification.</p></div><?php endif; ?>
                <div class="mapping-list"><?php foreach ($workspace['receipts']['rows'] as $receipt): ?><article class="mapping-card expense-receipt-card"><div class="mapping-card-main"><div><span class="status-badge tone-warning">Needs classification</span><h3><?= esc((string) ($receipt['file_original_filename'] ?? 'Receipt evidence')) ?></h3><p><?= esc((string) ($receipt['vendor'] ?? 'Vendor not captured')) ?> · <?= esc((string) ($receipt['document_date'] ?? 'Date not captured')) ?> · <?= ($receipt['observed_amount'] ?? null) === null ? 'Amount not captured' : '$' . esc((string) $receipt['observed_amount']) ?></p></div><a class="secondary-action button-link" href="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/file">Preview</a></div>
                    <form class="expense-form classify-form" action="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/classify" method="post"><?= csrf_field() ?><?php if ($pendingReceiptId === (int) $receipt['id'] && (string) $value('confirm_possible_duplicate') === '1'): ?><input type="hidden" name="confirm_possible_duplicate" value="1"><?php endif; ?><label>Date<input name="expense_date" type="date" required value="<?= esc((string) ($pendingReceiptId === (int) $receipt['id'] ? $value('expense_date') : ($receipt['document_date'] ?? '')), 'attr') ?>"></label><label>Amount<input name="amount" inputmode="decimal" required value="<?= esc((string) ($pendingReceiptId === (int) $receipt['id'] ? $value('amount') : ($receipt['observed_amount'] ?? '')), 'attr') ?>"></label><label>Category<select name="category_code" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= esc((string) $category['code'], 'attr') ?>"<?= $pendingReceiptId === (int) $receipt['id'] && $value('category_code') === $category['code'] ? ' selected' : '' ?>><?= esc((string) $category['name']) ?></option><?php endforeach; ?></select></label><label>Vehicle (optional)<select name="fleet_vehicle_id"><option value="">Fleet-wide</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= (int) $vehicle['id'] ?>"><?= esc($vehicleLabel($vehicle)) ?></option><?php endforeach; ?></select></label><label class="form-wide">Trip (optional)<select name="turo_trip_normalized_id"><option value="">No trip</option><?php foreach ($trips as $trip): ?><option value="<?= (int) $trip['id'] ?>"><?= esc((string) ($trip['turo_trip_id'] ?? 'Trip ' . $trip['id'])) ?> · <?= esc((string) ($trip['guest_name'] ?? 'Guest not captured')) ?></option><?php endforeach; ?></select></label><label>Vendor (optional)<input name="vendor" value="<?= esc((string) ($receipt['vendor'] ?? ''), 'attr') ?>"></label><label>Payment method (optional)<select name="payment_method_code"><option value="">Not captured</option><option value="business_credit_card">Business credit card</option><option value="personal_credit_card">Personal credit card</option><option value="debit_card">Debit card</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="other">Other</option></select></label><label>Payment label (optional)<input name="payment_reference" maxlength="120"></label><label class="form-wide">Business purpose / note<input name="business_purpose" value="<?= esc((string) ($receipt['note'] ?? ''), 'attr') ?>"></label><button class="primary-action" type="submit"><?= $pendingReceiptId === (int) $receipt['id'] && $warning ? 'Confirm separate expense' : 'Record operating expense' ?></button></form>
                    <div class="receipt-decisions"><form action="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/non-business" method="post"><?= csrf_field() ?><input name="note" aria-label="Non-business reason" placeholder="Reason (optional)"><button class="secondary-action" type="submit">Non-business</button></form><form action="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/duplicate" method="post"><?= csrf_field() ?><input name="duplicate_of_receipt_id" inputmode="numeric" aria-label="Original receipt ID" placeholder="Original receipt ID"><button class="secondary-action" type="submit">Duplicate</button></form><form action="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/archive" method="post"><?= csrf_field() ?><input name="archive_reason" required aria-label="Archive reason" placeholder="Archive reason"><button class="secondary-action" type="submit">Archive</button></form></div>
                </article><?php endforeach; ?></div>
                <?php if ($receiptLinks !== ''): ?><nav class="pager-wrap" aria-label="Receipt pages"><?= $receiptLinks ?></nav><?php endif; ?>
            <?php else: ?>
                <?php if ($workspace['expenses']['rows'] === [] && $workspace['receipts']['rows'] === []): ?><div class="empty-state"><h3>No records in this view</h3><p>Adjust the filters or capture a generic operating expense.</p></div><?php endif; ?>
                <div class="mapping-list"><?php $lastVehicleGroup = null;
foreach ($workspace['expenses']['rows'] as $expense): ?><?php $vehicleGroup = ($expense['fleet_vehicle_id'] ?? null) === null ? 'Fleet-wide' : (string) ($expense['display_name'] ?? $expense['fleet_code']);
    if ($view === 'vehicle' && $vehicleGroup !== $lastVehicleGroup): $lastVehicleGroup = $vehicleGroup; ?><h3 class="vehicle-group-heading"><?= esc($vehicleGroup) ?></h3><?php endif; ?><?= view('operating_expenses/components/expense_card', ['expense' => $expense, 'statusLabel' => $statusLabel, 'vehicleGroup' => $vehicleGroup]) ?><?php endforeach; ?></div>
                <?php if ($expenseLinks !== ''): ?><nav class="pager-wrap" aria-label="Expense pages"><?= $expenseLinks ?></nav><?php endif; ?>
                <?php if ($view === 'history' && $workspace['receipts']['rows'] !== []): ?><h3 class="history-heading">Receipt decisions</h3><div class="mapping-list"><?php foreach ($workspace['receipts']['rows'] as $receipt): ?><article class="mapping-card"><span class="status-badge tone-neutral"><?= esc($statusLabel((string) $receipt['classification_code'])) ?></span><h3><?= esc((string) ($receipt['file_original_filename'] ?? 'Receipt evidence')) ?></h3><p><?= esc((string) ($receipt['note'] ?? $receipt['archive_reason'] ?? 'No note')) ?> · <a class="text-link" href="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/file">Preview</a></p></article><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
