<?php
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed>|null $loan */
/** @var array<int, array<string, mixed>> $lenders */
/** @var array<int, array<string, mixed>> $loan_statuses */
/** @var array<int, array<string, mixed>> $loans */
/** @var Closure $options */
$loan ??= [];
$editingLoan = isset($loan['id']);
$action = '/fleet/vehicles/' . (int) $vehicle['id'] . '/loans' . ($editingLoan ? '/' . (int) $loan['id'] : '');
$predecessorLoans = array_map(static function (array $candidate): array {
    $name = trim((string) ($candidate['loan_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($candidate['lender_name'] ?? 'Vehicle')) . ' financing';
    }

    return ['id' => (int) $candidate['id'], 'name' => $name];
}, array_values(array_filter($loans, static fn (array $candidate): bool => ! $editingLoan || (int) $candidate['id'] !== (int) $loan['id'])));
?>
<form action="<?= $action ?>" method="post"><?= csrf_field() ?><div class="issue-filters">
<label>Lender<select name="lender_id" required><?= $options($lenders, $loan['lender_id'] ?? null) ?></select></label>
<label>Loan name<input name="loan_name" maxlength="120" value="<?= esc((string) ($loan['loan_name'] ?? ''), 'attr') ?>"></label>
<label>Status<select name="loan_status_lookup_value_id" required><?= $options($loan_statuses, $loan['loan_status_lookup_value_id'] ?? null) ?></select></label>
<label>Account last four<input name="account_number_last4" inputmode="numeric" maxlength="4" value="<?= esc((string) ($loan['account_number_last4'] ?? ''), 'attr') ?>"></label>
<label>Original principal<input name="original_principal" inputmode="decimal" value="<?= esc((string) ($loan['original_principal'] ?? ''), 'attr') ?>"></label>
<label>APR percentage<input name="interest_rate" inputmode="decimal" value="<?= esc((string) ($loan['interest_rate'] ?? ''), 'attr') ?>"></label>
<label>Term months<input name="term_months" type="number" min="1" max="600" value="<?= esc((string) ($loan['term_months'] ?? ''), 'attr') ?>"></label>
<label>Monthly payment<input name="monthly_payment" inputmode="decimal" value="<?= esc((string) ($loan['monthly_payment'] ?? ''), 'attr') ?>"></label>
<label>Balloon amount<input name="balloon_amount" inputmode="decimal" value="<?= esc((string) ($loan['balloon_amount'] ?? ''), 'attr') ?>"></label>
<label>Origination date<input name="opened_on" type="date" value="<?= esc((string) ($loan['opened_on'] ?? ''), 'attr') ?>"></label>
<label>First payment<input name="first_payment_on" type="date" value="<?= esc((string) ($loan['first_payment_on'] ?? ''), 'attr') ?>"></label>
<label>Payment due day<input name="payment_due_day" type="number" min="1" max="31" value="<?= esc((string) ($loan['payment_due_day'] ?? ''), 'attr') ?>"></label>
<label>Maturity date<input name="matures_on" type="date" value="<?= esc((string) ($loan['matures_on'] ?? ''), 'attr') ?>"></label>
<label>Paid-off date<input name="paid_off_on" type="date" value="<?= esc((string) ($loan['paid_off_on'] ?? ''), 'attr') ?>"></label>
<label>Closed date<input name="closed_on" type="date" value="<?= esc((string) ($loan['closed_on'] ?? ''), 'attr') ?>"></label>
<label>Refinances<select name="refinanced_from_loan_id"><?= $options($predecessorLoans, $loan['refinanced_from_loan_id'] ?? null, 'No predecessor') ?></select></label>
<label class="capital-wide">Loan notes<textarea name="notes" rows="3"><?= esc((string) ($loan['notes'] ?? '')) ?></textarea></label></div><div class="form-actions"><button class="primary-action" type="submit"><?= $editingLoan ? 'Save agreement' : 'Add agreement' ?></button></div></form>
