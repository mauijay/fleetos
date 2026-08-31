<?php
/** @var array{css:?string,js:?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed>|null $acquisition */
/** @var string $acquisition_state */
/** @var string $financing_state */
/** @var array<int, array<string, mixed>> $loans */
/** @var array<int, array<string, mixed>> $startup_costs */
/** @var array<int, array<string, mixed>> $notes */
/** @var array<int, array<string, mixed>> $files */
/** @var string $lifetime_operating_revenue */
/** @var array<int, array<string, mixed>> $lenders */
/** @var array<int, array<string, mixed>> $acquisition_methods */
/** @var array<int, array<string, mixed>> $funding_methods */
/** @var array<int, array<string, mixed>> $loan_statuses */
/** @var array<int, array<string, mixed>> $balance_sources */
/** @var int $editing_snapshot_id */
/** @var string|null $notice */
/** @var array<string, string> $errors */
$money = static fn (mixed $value): string => $value === null || $value === '' ? 'Not entered' : '$' . number_format((float) $value, 2);
$date = static fn (mixed $value): string => $value === null || $value === '' ? 'Not entered' : date('M j, Y', strtotime((string) $value));
$label = static fn (string $state): string => match ($state) {
    'not_entered' => 'Not entered',
    'none' => 'None',
    'active' => 'Active',
    'unknown_incomplete' => 'Unknown / incomplete',
    'cash_purchase' => 'Cash purchase',
    'financed' => 'Financed',
    'paid_off' => 'Paid off',
    'refinanced' => 'Refinanced',
    default => ucwords(str_replace('_', ' ', $state)),
};
$options = static function (array $rows, mixed $selected = null, string $empty = 'Choose'): string {
    $html = '<option value="">' . esc($empty) . '</option>';
    foreach ($rows as $row) {
        $isSelected = (int) $selected === (int) $row['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $row['id'] . '"' . $isSelected . '>' . esc((string) $row['name']) . '</option>';
    }
    return $html;
};
$acquisition ??= [];
$acquisitionLoanOptions = array_map(static function (array $loan): array {
    $loanName = trim((string) ($loan['loan_name'] ?? '')) ?: 'Vehicle financing';

    return ['id' => (int) $loan['id'], 'name' => $loanName . ' / ' . (string) $loan['lender_name']];
}, $loans);
$fundingMethodCode = (string) ($acquisition['funding_method_code'] ?? '');
$acquisitionLoanPrincipal = $acquisition['acquisition_loan_original_principal'] ?? null;
$acquisitionLoanLabel = trim((string) ($acquisition['acquisition_loan_name'] ?? '')) ?: 'Vehicle financing';
$acquisitionLoanSource = $acquisitionLoanLabel . ' / ' . (string) ($acquisition['acquisition_lender_name'] ?? 'Lender not entered');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= esc((string) $vehicle['fleet_code']) ?> | FleetOS</title><?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell"><a class="skip-link" href="#main-content">Skip to main content</a><div class="app-frame import-frame"><?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?><main id="main-content" class="command-main import-main vehicle-main" tabindex="-1">
<header class="top-status"><div><p class="eyebrow">Fleet / Vehicles / <?= esc((string) $vehicle['fleet_code']) ?></p><h1><?= esc((string) $vehicle['display_name']) ?></h1><p class="status-copy"><?= esc(trim((string) $vehicle['model_year'] . ' ' . (string) $vehicle['make_name'] . ' ' . (string) $vehicle['model_name'])) ?></p></div><div class="vehicle-detail-actions"><a class="secondary-action button-link" href="/fleet/vehicles">Back to vehicles</a><a class="primary-action button-link" href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/edit">Edit vehicle</a></div></header>
<?php if ($notice !== null): ?><section class="section import-message tone-success"><strong><?= esc($notice) ?></strong></section><?php endif; ?>
<?php if ($errors !== []): ?><section class="section import-message tone-danger"><strong>Financial details were not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<nav class="capital-tabs" aria-label="Vehicle detail sections"><a href="#overview">Overview</a><a href="#acquisition">Acquisition</a><a href="#financing">Financing</a><a href="#performance">Financial Performance</a><a href="#documents">Notes &amp; Documents</a></nav>

<section class="section" id="overview"><div class="section-heading"><p class="eyebrow">Vehicle detail</p><h2>Overview</h2></div><dl class="issue-facts capital-facts"><div><dt>Fleet code</dt><dd><?= esc((string) $vehicle['fleet_code']) ?></dd></div><div><dt>Status</dt><dd><?= esc((string) $vehicle['status_name']) ?></dd></div><div><dt>Acquired</dt><dd><?= esc($date($vehicle['purchase_date'] ?? null)) ?></dd></div><div><dt>Funding</dt><dd><span class="status-badge tone-info"><?= esc((string) ($acquisition['funding_method_name'] ?? 'Not entered')) ?></span></dd></div><div><dt>Loan status</dt><dd><span class="status-badge tone-info"><?= esc($label($financing_state)) ?></span></dd></div><div><dt>Agreements</dt><dd><?= count($loans) ?></dd></div></dl><?= view('fleet_vehicles/components/compliance_summary', ['vehicle' => $vehicle, 'date' => $date]) ?></section>

<section class="section" id="acquisition"><div class="section-heading"><p class="eyebrow">Capital facts</p><h2>Acquisition</h2></div><p class="muted">The acquisition date is maintained on the vehicle record. Amounts below are independent recorded facts and are not inferred from one another.</p>
<form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/acquisition" method="post"><?= csrf_field() ?><div class="issue-filters">
<label>Acquisition method<select name="acquisition_method_lookup_value_id"><?= $options($acquisition_methods, $acquisition['acquisition_method_lookup_value_id'] ?? null) ?></select></label>
<label>Funding method<select name="funding_method_lookup_value_id"><?= $options($funding_methods, $acquisition['funding_method_lookup_value_id'] ?? null) ?></select></label>
<label>Source name<input name="source_name" maxlength="190" value="<?= esc((string) ($acquisition['source_name'] ?? ''), 'attr') ?>"></label>
<label>External reference<input name="external_reference" maxlength="120" value="<?= esc((string) ($acquisition['external_reference'] ?? ''), 'attr') ?>"></label>
<label>Purchase order subtotal<input name="purchase_order_subtotal" inputmode="decimal" value="<?= esc((string) ($acquisition['purchase_order_subtotal'] ?? ''), 'attr') ?>"></label>
<label>Rebates / incentives<input name="rebates_incentives" inputmode="decimal" value="<?= esc((string) ($acquisition['rebates_incentives'] ?? ''), 'attr') ?>"></label>
<label>Trade-in credit<input name="trade_in_credit" inputmode="decimal" value="<?= esc((string) ($acquisition['trade_in_credit'] ?? ''), 'attr') ?>"></label>
<label>Cash paid at closing<input name="cash_paid_at_closing" inputmode="decimal" value="<?= esc((string) ($acquisition['cash_paid_at_closing'] ?? ''), 'attr') ?>"></label>
<div class="capital-readonly"><span>Original amount financed</span><?php if ($fundingMethodCode === 'cash'): ?><strong>—</strong><?php elseif ($acquisitionLoanPrincipal !== null): ?><strong><?= esc($money($acquisitionLoanPrincipal)) ?></strong><small>From: <?= esc($acquisitionLoanSource) ?></small><?php else: ?><strong>Not entered</strong><small>Add or select acquisition financing</small><?php endif; ?></div>
<label>Acquisition financing agreement<select name="acquisition_loan_id"><?= $options($acquisitionLoanOptions, $acquisition['acquisition_loan_id'] ?? null, 'Not associated') ?></select></label>
<label class="capital-wide">Acquisition notes<textarea name="notes" rows="3"><?= esc((string) ($acquisition['notes'] ?? '')) ?></textarea></label></div><div class="form-actions"><button class="primary-action" type="submit">Save acquisition</button></div></form>
<?php if ($startup_costs !== []): ?><div class="capital-subsection"><h3>Existing Startup-Cost Records</h3><p class="muted">Preserved legacy records. These are not an authoritative acquisition cost or vehicle value.</p><div class="mapping-list"><?php foreach ($startup_costs as $cost): ?><article class="mapping-card"><div><strong><?= esc((string) $cost['description']) ?></strong><p><?= esc((string) ($cost['cost_type_name'] ?? 'Unclassified')) ?> · <?= esc($date($cost['incurred_on'])) ?></p></div><strong><?= esc($money($cost['amount'])) ?></strong></article><?php endforeach; ?></div></div><?php endif; ?></section>

<section class="section" id="financing"><div class="section-heading"><p class="eyebrow"><?= count($loans) ?> agreements</p><h2>Financing</h2></div>
<details class="capital-disclosure"><summary>Add lender</summary><form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/lenders" method="post"><?= csrf_field() ?><div class="issue-filters"><label>Lender name<input name="name" maxlength="190" required></label></div><div class="form-actions"><button class="primary-action" type="submit">Create lender</button></div></form></details>
<details class="capital-disclosure" <?= $loans === [] ? 'open' : '' ?>><summary>Add financing agreement</summary><?= view('fleet_vehicles/components/loan_form', ['vehicle' => $vehicle, 'loan' => null, 'lenders' => $lenders, 'loan_statuses' => $loan_statuses, 'loans' => $loans, 'options' => $options]) ?></details>
<?php if ($loans === []): ?><div class="empty-state">No financing agreements entered. Cash and unfinanced vehicles remain valid.</div><?php endif; ?>
<div class="mapping-list"><?php foreach ($loans as $loan): ?><article class="mapping-card capital-loan"><div class="mapping-card-main"><div><div class="vehicle-status-row"><span class="status-badge tone-info"><?= esc((string) ($loan['status_name'] ?? 'Status not entered')) ?></span><span><?= esc((string) $loan['lender_name']) ?></span></div><h3><?= esc((string) ($loan['loan_name'] ?: 'Vehicle financing')) ?></h3><p><?= $loan['account_number_last4'] ? 'Account ending ' . esc((string) $loan['account_number_last4']) : 'Account reference not entered' ?></p></div><dl class="issue-facts"><div><dt>Original principal</dt><dd><?= esc($money($loan['original_principal'])) ?></dd></div><div><dt>APR</dt><dd><?= $loan['interest_rate'] === null ? 'Not entered' : esc(number_format((float) $loan['interest_rate'], 4)) . '%' ?></dd></div><div><dt>Term</dt><dd><?= $loan['term_months'] === null ? 'Not entered' : (int) $loan['term_months'] . ' months' ?></dd></div><div><dt>Monthly payment</dt><dd><?= esc($money($loan['monthly_payment'])) ?></dd></div><div><dt>Opened</dt><dd><?= esc($date($loan['opened_on'])) ?></dd></div><div><dt>Maturity</dt><dd><?= esc($date($loan['matures_on'])) ?></dd></div></dl></div>
<?php if ($loan['snapshot_id'] !== null): ?><div class="capital-balance"><div><span>Latest authoritative snapshot</span><?php if ($loan['principal_balance'] !== null): ?><strong>Principal <?= esc($money($loan['principal_balance'])) ?></strong><?php endif; ?><?php if ($loan['payoff_amount'] !== null): ?><strong>Payoff <?= esc($money($loan['payoff_amount'])) ?></strong><?php endif; ?></div><div><span>As of <?= esc($date($loan['as_of_date'])) ?></span><span><?= esc((string) $loan['snapshot_source_name']) ?></span><a class="action-link" href="?edit_snapshot=<?= (int) $loan['snapshot_id'] ?>#snapshot-<?= (int) $loan['snapshot_id'] ?>">Edit snapshot</a></div></div>
<?php elseif ($loan['current_balance'] !== null): ?><div class="capital-balance tone-warning"><div><span>Legacy balance</span><strong><?= esc($money($loan['current_balance'])) ?></strong></div><div><span>As-of date unavailable</span><span>Not authoritative</span></div></div><?php else: ?><div class="capital-balance"><span>No balance or payoff snapshot entered.</span></div><?php endif; ?>
<details class="capital-disclosure"><summary>Edit agreement</summary><?= view('fleet_vehicles/components/loan_form', ['vehicle' => $vehicle, 'loan' => $loan, 'lenders' => $lenders, 'loan_statuses' => $loan_statuses, 'loans' => $loans, 'options' => $options]) ?></details>
<details class="capital-disclosure" id="loan-<?= (int) $loan['id'] ?>-add-snapshot"><summary>Add dated snapshot</summary><?= view('fleet_vehicles/components/snapshot_form', ['vehicle' => $vehicle, 'loan' => $loan, 'snapshot' => null, 'balance_sources' => $balance_sources, 'options' => $options]) ?></details>
<?php $editingSnapshot = array_find($loan['snapshots'], static fn (array $snapshot): bool => (int) $snapshot['id'] === $editing_snapshot_id); ?>
<?php if ($editingSnapshot !== null): ?><details class="capital-disclosure" id="snapshot-<?= (int) $editingSnapshot['id'] ?>" open><summary>Edit snapshot from <?= esc($date($editingSnapshot['as_of_date'])) ?></summary><?= view('fleet_vehicles/components/snapshot_form', ['vehicle' => $vehicle, 'loan' => $loan, 'snapshot' => $editingSnapshot, 'balance_sources' => $balance_sources, 'options' => $options]) ?></details><?php endif; ?>
<?php if ($loan['snapshots'] !== []): ?><details class="capital-disclosure"><summary>Snapshot history (<?= count($loan['snapshots']) ?>)</summary><dl class="issue-facts"><?php foreach ($loan['snapshots'] as $snapshot): ?><div><dt><?= esc($date($snapshot['as_of_date'])) ?> · <?= esc((string) $snapshot['source_name']) ?></dt><dd><?php if ($snapshot['principal_balance'] !== null): ?>Principal <?= esc($money($snapshot['principal_balance'])) ?><?php endif; ?><?php if ($snapshot['payoff_amount'] !== null): ?><?= $snapshot['principal_balance'] !== null ? ' / ' : '' ?>Payoff <?= esc($money($snapshot['payoff_amount'])) ?><?php endif; ?><br><a class="action-link" href="?edit_snapshot=<?= (int) $snapshot['id'] ?>#snapshot-<?= (int) $snapshot['id'] ?>">Edit snapshot</a></dd></div><?php endforeach; ?></dl></details><?php endif; ?></article><?php endforeach; ?></div></section>

<section class="section" id="performance"><div class="section-heading"><p class="eyebrow">Trustworthy existing data</p><h2>Financial Performance</h2></div><dl class="issue-facts capital-facts"><div><dt>Lifetime operating revenue</dt><dd><?= esc($money($lifetime_operating_revenue)) ?></dd></div><div><dt>Purchase order subtotal</dt><dd><?= esc($money($acquisition['purchase_order_subtotal'] ?? null)) ?></dd></div><div><dt>Cash paid at closing</dt><dd><?= esc($money($acquisition['cash_paid_at_closing'] ?? null)) ?></dd></div><div><dt>Original amount financed</dt><dd><?= $fundingMethodCode === 'cash' ? '—' : esc($money($acquisitionLoanPrincipal)) ?></dd></div></dl><p class="muted">Original amount financed comes only from the explicitly associated acquisition financing agreement. No current vehicle value, equity, depreciation, book value, or accounting profit is calculated in this release.</p></section>

<section class="section" id="documents"><div class="section-heading"><p class="eyebrow">Existing vehicle records</p><h2>Notes &amp; Documents</h2></div><div class="capital-columns"><div><h3>Notes</h3><?php if ($notes === []): ?><div class="empty-state">No vehicle notes attached.</div><?php else: ?><div class="mapping-list"><?php foreach ($notes as $note): ?><article class="mapping-card"><strong><?= esc((string) ($note['note_type_name'] ?? 'Note')) ?></strong><p><?= nl2br(esc((string) $note['body'])) ?></p></article><?php endforeach; ?></div><?php endif; ?></div><div><h3>Documents</h3><?php if ($files === []): ?><div class="empty-state">No vehicle documents attached.</div><?php else: ?><div class="mapping-list"><?php foreach ($files as $file): ?><article class="mapping-card"><strong><?= esc((string) ($file['file_type_name'] ?? 'Document')) ?></strong><p><?= esc((string) ($file['original_filename'] ?? 'Stored file')) ?> · <?= esc($date($file['document_date'])) ?></p></article><?php endforeach; ?></div><?php endif; ?></div></div><p class="muted">Finance document types are available for the existing file workflow; this page does not create a parallel upload system.</p></section>
<?= view('fleet_command_center/components/footer') ?></main></div><?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?></body></html>
