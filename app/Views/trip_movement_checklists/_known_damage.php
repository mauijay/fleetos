<?php
/** @var array<string,mixed> $checklist */
/** @var array<string,mixed> $vehicleDamage */
$current = $vehicleDamage['current'] ?? [];
$zones = $vehicleDamage['zones'] ?? [];
$types = $vehicleDamage['damage_types'] ?? [];
$severities = $vehicleDamage['severities'] ?? [];
$worseningSeverities = static function (array $options, string $currentSeverity): array {
    $currentIndex = array_search($currentSeverity, array_keys($options), true);

    return $currentIndex === false ? $options : array_slice($options, $currentIndex, null, true);
};
$availableDamageExceptions ??= [];
$notice ??= null;
$errors ??= [];
$form ??= null;
$formData ??= [];
$selected = static fn (string $field, string $value, string $fallback = ''): string => (string) ($formData[$field] ?? $fallback) === $value ? ' selected' : '';
$currentTripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
$tripStartsAt = strtotime((string) ($checklist['starts_at'] ?? '')) ?: null;
$damageContext = static function (array $item) use ($currentTripId, $tripStartsAt): string {
    $sourceTripId = (int) ($item['discovered_turo_trip_normalized_id'] ?? 0);
    $sourceReference = trim((string) ($item['turo_reservation_id'] ?? ''));
    if ($sourceTripId > 0 && $sourceTripId === $currentTripId) {
        return 'Recorded on this trip';
    }
    $discoveredAt = strtotime((string) ($item['discovered_at'] ?? '')) ?: null;
    if ($sourceTripId > 0 && $tripStartsAt !== null && $discoveredAt !== null && $discoveredAt < $tripStartsAt) {
        return 'Pre-existing for this trip' . ($sourceReference === '' ? '' : ' · First recorded on ' . $sourceReference);
    }

    return $sourceReference === '' ? 'Vehicle-level current record' : 'First recorded on ' . $sourceReference;
};
?>
<section class="section known-damage-section" id="known-vehicle-damage">
    <div class="section-heading"><div><p class="eyebrow">Vehicle-level condition</p><h2>Current Known Damage</h2></div><span class="status-badge <?= $current === [] ? 'tone-success' : 'tone-warning' ?>"><?= count($current) ?> current</span></div>
    <p class="muted">Persistent physical condition carried across trips. This context does not change vehicle availability.</p>
    <?php if ($notice !== null): ?><div class="import-message tone-success"><strong><?= esc($notice) ?></strong></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="import-message tone-danger"><strong>Damage item was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (($vehicleDamage['has_unsafe'] ?? false) === true): ?><div class="import-message tone-danger damage-unsafe-warning"><strong>Unsafe damage is recorded.</strong><p>Review the condition before operating the vehicle. Availability has not been changed automatically.</p></div><?php endif; ?>
    <?php if ($current === []): ?><div class="empty-state">No current known damage recorded.</div><?php else: ?>
        <div class="mapping-list damage-item-list"><?php foreach ($current as $item): ?><article class="mapping-card damage-item-card<?= $item['severity_code'] === 'unsafe' ? ' damage-item-unsafe' : '' ?>"><div><div class="vehicle-status-row"><span class="status-badge <?= $item['severity_code'] === 'unsafe' ? 'tone-danger' : 'tone-warning' ?>"><?= esc((string) $item['severity_label']) ?></span><span class="status-badge tone-info"><?= esc((string) $item['status_label']) ?></span></div><h3><?= esc((string) $item['zone_label']) ?> · <?= esc((string) $item['damage_type_label']) ?></h3><p><?= nl2br(esc((string) $item['description'])) ?></p><p class="damage-context"><strong><?= esc($damageContext($item)) ?></strong><span>First recorded <?= esc(date('M j, Y g:i A', strtotime((string) $item['discovered_at']))) ?></span></p></div><details class="capital-disclosure"><summary>Record worsening</summary><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/damage/<?= (int) $item['id'] ?>/worsen" method="post"><?= csrf_field() ?><p class="muted damage-action-help">Use this only when this same damage item has become worse. Record newly found damage as a separate item.</p><label>New severity<select name="severity_code" required><?php foreach ($worseningSeverities($severities, (string) $item['severity_code']) as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $code === $item['severity_code'] ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>What became worse<textarea name="note" rows="2" maxlength="2000" required></textarea></label><label>Occurred at<input type="datetime-local" name="occurred_at" value="<?= esc(date('Y-m-d\TH:i'), 'attr') ?>"></label><button class="secondary-action" type="submit">Record worsening</button></form></details></article><?php endforeach; ?></div>
    <?php endif; ?>
    <details class="capital-disclosure damage-create"<?= $form === 'new' ? ' open' : '' ?>><summary>Record newly found damage</summary><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/damage" method="post"><?= csrf_field() ?><div class="issue-filters damage-form-grid"><label>Vehicle zone<select name="zone_code" required><option value="">Choose zone</option><?php foreach ($zones as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('zone_code', $code) ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Damage type<select name="damage_type_code" required><option value="">Choose type</option><?php foreach ($types as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('damage_type_code', $code) ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Severity<select name="severity_code" required><?php foreach ($severities as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('severity_code', $code, 'cosmetic') ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Discovered at<input type="datetime-local" name="discovered_at" value="<?= esc((string) ($formData['discovered_at'] ?? date('Y-m-d\TH:i')), 'attr') ?>" required></label><label class="capital-wide">Description<textarea name="description" rows="3" maxlength="2000" required><?= esc((string) ($formData['description'] ?? '')) ?></textarea></label><?php if ($availableDamageExceptions !== []): ?><label class="capital-wide">Link recovery damage exception <span class="muted">optional</span><select name="recovery_exception_id"><option value="">Do not link</option><?php foreach ($availableDamageExceptions as $exception): ?><option value="<?= (int) $exception['id'] ?>"<?= (int) ($formData['recovery_exception_id'] ?? 0) === (int) $exception['id'] ? ' selected' : '' ?>>Exception #<?= (int) $exception['id'] ?><?= trim((string) ($exception['note'] ?? '')) === '' ? '' : ' · ' . esc((string) $exception['note']) ?></option><?php endforeach; ?></select></label><?php endif; ?><label class="capital-wide">External evidence reference <span class="muted">optional Turo text, URL, or ID</span><input name="external_reference" maxlength="500" value="<?= esc((string) ($formData['external_reference'] ?? ''), 'attr') ?>"></label><label>Evidence label <span class="muted">optional</span><input name="evidence_label" maxlength="190" value="<?= esc((string) ($formData['evidence_label'] ?? ''), 'attr') ?>"></label><p class="muted capital-wide">Add one evidence reference at a time. Existing files or images must already belong to this vehicle.</p><details class="capital-disclosure damage-link-details capital-wide"><summary>Link existing FleetOS records <span class="muted">optional</span></summary><div class="damage-link-grid"><label>Damage claim ID<input name="damage_claim_id" inputmode="numeric" value="<?= esc((string) ($formData['damage_claim_id'] ?? ''), 'attr') ?>"></label><label>Existing vehicle file ID<input name="file_id" inputmode="numeric" value="<?= esc((string) ($formData['file_id'] ?? ''), 'attr') ?>"></label><label>Existing vehicle image ID<input name="image_id" inputmode="numeric" value="<?= esc((string) ($formData['image_id'] ?? ''), 'attr') ?>"></label></div></details></div><div class="form-actions"><button class="primary-action" type="submit">Record damage</button></div></form></details>
    <p><a class="action-link" href="/fleet/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>#vehicle-damage">Review complete damage history</a></p>
</section>
