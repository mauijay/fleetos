<?php
$base = '/fleet/vehicles/' . (int) $vehicle['id'] . '/damage-repairs/' . (int) $job['id'];
$previous = $previousEstimate ?? null;
$retained = ($formData['work_action'] ?? '') === 'estimate_create' ? $formData : [];
$series = $previous['quote_series_key'] ?? $retained['quote_series_key'] ?? \App\Services\Fleet\VehicleDamageRepairService::commandKey();
?>
<section class="section"><h1><?= $previous === null ? 'Add repair estimate' : 'Record new quote revision' ?></h1>
<p>Record one quoted total for the selected scope. Source details are frozen; corrections require another revision.</p>
<form action="<?= $base ?>/estimates<?= $previous === null ? '' : '/' . (int) $previous['id'] . '/revision' ?>" method="post" enctype="multipart/form-data">
<?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'estimate_create']) ?>
<input type="hidden" name="quote_series_key" value="<?= esc($series, 'attr') ?>">
<?php if ($previous !== null): ?><input type="hidden" name="expected_estimate_state" value="<?= esc($estimateStates[$previous['id']], 'attr') ?>"><p>Series <?= esc($series) ?> · Revision <?= (int) $previous['revision_number'] + 1 ?></p><?php if ($previous['status_code'] === 'received'): ?><label><input type="checkbox" name="supersede_previous_confirmed" value="1" required> Supersede the previous received quote with this revision.</label><?php endif; ?><?php endif; ?>
<div class="damage-form-grid issue-filters">
<label>Recording mode<select name="recording_mode"><?php foreach (\Config\VehicleDamageRepairDocuments::MODES as $code => $label): ?><option value="<?= esc($code, 'attr') ?>" <?= ($retained['recording_mode'] ?? '') === $code ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Estimate amount<input name="amount" inputmode="decimal" required maxlength="13" value="<?= esc($retained['amount'] ?? '', 'attr') ?>"></label><label>Currency<select name="currency"><option value="USD">USD</option></select></label>
<label>Vendor snapshot<input name="vendor_snapshot" maxlength="190" value="<?= esc($retained['vendor_snapshot'] ?? '', 'attr') ?>"></label>
<label>Known vendor identity (optional)<select name="vendor_company_id"><option value="">Text / unknown</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int) $vendor['id'] ?>"><?= esc($vendor['name']) ?></option><?php endforeach; ?></select></label>
<label>Quote date<input type="date" name="quote_date" value="<?= esc($retained['quote_date'] ?? '', 'attr') ?>"></label>
<label>Expiry date (valid through this date)<input type="date" name="expires_on"></label><label>Exact expiry (YYYY-MM-DD HH:MM:SS, alternative to date)<input name="expires_at" maxlength="19"></label>
<label>Vendor quote reference<input name="vendor_quote_reference" maxlength="120"></label>
<label>Original quote note<textarea name="note" maxlength="2000"></textarea></label><label>Historical recording reason<textarea name="historical_recording_reason" maxlength="2000"></textarea></label>
</div>
<fieldset><legend>Frozen quote scope</legend><?php foreach ($members as $m): if ($m['withdrawn_at'] !== null) { continue; } ?><label><input type="checkbox" name="scope_membership_ids[]" value="<?= (int) $m['id'] ?>"> <?= esc($conditionsById[$m['vehicle_damage_item_id']]['description']) ?></label><?php endforeach; ?></fieldset>
<fieldset><legend>Source confirmation</legend><?php foreach (['amount' => 'I explicitly confirm this quoted amount.', 'currency' => 'I explicitly confirm USD currency.', 'vendor' => 'The source supports this vendor.', 'date' => 'The source supports this quote date.', 'scope' => 'The source supports the selected scope.'] as $key => $label): ?><label><input type="checkbox" name="<?= $key ?>_confirmed" value="1" <?= in_array($key, ['amount', 'currency'], true) ? 'required' : '' ?>> <?= esc($label) ?></label><?php endforeach; ?></fieldset>
<fieldset><legend>Estimate source document</legend><p>A current quote requires a PDF or verified image in this command. Historical incomplete recording may omit it.</p><input type="hidden" name="document[kind_code]" value="estimate"><label>Upload source<input type="file" name="repair_document" accept="application/pdf,image/jpeg,image/png,image/webp"></label><label>Document label<input name="document[label]" maxlength="190"></label></fieldset>
<button type="submit">Record estimate<?= $previous === null ? '' : ' revision' ?></button> <a href="<?= $base ?>">Cancel</a>
</form></section>
