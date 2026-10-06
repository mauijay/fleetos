<?php $item = $historicalPreview['item']; $snapshot = $historicalPreview['snapshot']; $formData ??= []; ?>
<section class="section"><h1>Backfill original historical incident</h1>
<p>This uses existing condition #<?= (int) $item['id'] ?>. No new damage condition will be created. Its current severity, status, discovery, history and evidence stay unchanged.</p>
<article class="mapping-card"><h2>Existing physical condition</h2><p><?= nl2br(esc((string) $item['description'])) ?></p>
<p>Panel: <?= esc(\Config\VehicleDamage::PANELS[$snapshot['panel_code'] ?? ''] ?? 'Unknown / Not specified') ?></p>
<p>Original recorded severity: <?= esc(\App\Services\Fleet\VehicleDamageService::SEVERITIES[$snapshot['severity_code']]) ?> · Current severity: <?= esc(\App\Services\Fleet\VehicleDamageService::SEVERITIES[$item['severity_code']]) ?></p>
<p>Original discovery: <?= esc((string) $item['discovered_at']) ?></p></article>
<?php if ($historicalPreview['original_incident_id'] !== null): ?>
<p>This condition already has an original incident. <a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage-incidents/<?= (int) $historicalPreview['original_incident_id'] ?>">Review that incident and its attribution</a>.</p>
<?php else: ?>
<p>Select authoritative trip context and its causation level explicitly. No trip or panel is inferred from the description. An unknown panel stays unknown; assigning it is a separate action.</p>
<form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage/<?= (int) $item['id'] ?>/historical-original" method="post">
<?= csrf_field() ?><input type="hidden" name="expected_state" value="<?= esc((string) $historicalPreview['expected_state'], 'attr') ?>">
<div class="damage-form-grid issue-filters">
<label>Authoritative historical reservation / trip<select name="trip_id" required><option value="">Choose trip</option><?php foreach ($trips as $trip): ?><option value="<?= (int) $trip['id'] ?>"<?= (int) ($formData['trip_id'] ?? 0) === (int) $trip['id'] ? ' selected' : '' ?>><?= esc((string) $trip['turo_reservation_id']) ?></option><?php endforeach; ?></select></label>
<label>Attribution meaning<select name="attribution_type" required><?php foreach (\Config\VehicleDamage::ATTRIBUTIONS as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= ($formData['attribution_type'] ?? 'unknown') === $code ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Historical discovery<input name="discovered_at" required value="<?= esc((string) ($formData['discovered_at'] ?? $item['discovered_at']), 'attr') ?>" placeholder="YYYY-MM-DD HH:MM:SS"></label>
<label>Occurred at (optional)<input type="datetime-local" name="occurred_at" value="<?= esc((string) ($formData['occurred_at'] ?? ''), 'attr') ?>"></label>
<label>Historical backfill reason<textarea name="reason" required maxlength="2000"><?= esc((string) ($formData['reason'] ?? '')) ?></textarea></label>
</div><label><input type="checkbox" name="confirmed" value="1" required> I confirm this records the original incident for this existing condition.</label>
<button type="submit" class="primary-action">Create historical incident using existing condition</button></form>
<?php endif; ?></section>
