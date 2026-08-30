<?php
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed> $loan */
/** @var array<string, mixed>|null $snapshot */
/** @var array<int, array<string, mixed>> $balance_sources */
/** @var Closure $options */
$snapshot ??= [];
$editing = isset($snapshot['id']);
?>
<form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/loans/<?= (int) $loan['id'] ?>/snapshots" method="post"><?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="snapshot_id" value="<?= (int) $snapshot['id'] ?>"><?php endif; ?><div class="issue-filters">
<label>As-of date<input type="date" name="as_of_date" required value="<?= esc((string) ($snapshot['as_of_date'] ?? ''), 'attr') ?>"></label>
<label>Principal balance<input name="principal_balance" inputmode="decimal" value="<?= esc((string) ($snapshot['principal_balance'] ?? ''), 'attr') ?>"></label>
<label>Payoff amount<input name="payoff_amount" inputmode="decimal" value="<?= esc((string) ($snapshot['payoff_amount'] ?? ''), 'attr') ?>"></label>
<label>Source<select name="source_method_lookup_value_id" required><?= $options($balance_sources, $snapshot['source_method_lookup_value_id'] ?? null) ?></select></label>
<label>Source reference<input name="source_reference" maxlength="190" value="<?= esc((string) ($snapshot['source_reference'] ?? ''), 'attr') ?>"></label>
<label class="capital-wide">Notes<textarea name="notes" rows="2"><?= esc((string) ($snapshot['notes'] ?? '')) ?></textarea></label></div>
<p class="muted"><?= $editing ? 'This correction updates the existing dated snapshot and records prior values in audit history.' : 'Enter a principal balance, payoff amount, or both.' ?></p>
<div class="form-actions"><button class="primary-action" type="submit"><?= $editing ? 'Save correction' : 'Add snapshot' ?></button></div></form>
