<?php
/** @var array<string,mixed> $vehicle */
/** @var array<string,mixed> $vehicleDamage */
/** @var string|null $notice */
/** @var array<string,string> $errors */
/** @var string|null $form */
/** @var array<string,mixed> $formData */
$current = $vehicleDamage['current'] ?? [];
$history = $vehicleDamage['history'] ?? [];
$zones = $vehicleDamage['zones'] ?? [];
$types = $vehicleDamage['damage_types'] ?? [];
$severities = $vehicleDamage['severities'] ?? [];
$worseningSeverities = static function (array $options, string $currentSeverity): array {
    $currentIndex = array_search($currentSeverity, array_keys($options), true);

    return $currentIndex === false ? $options : array_slice($options, $currentIndex, null, true);
};
$selected = static fn (string $field, string $value, string $fallback = ''): string => (string) ($formData[$field] ?? $fallback) === $value ? ' selected' : '';
$dateTime = static fn (mixed $value): string => $value === null || $value === '' ? 'Not recorded' : date('M j, Y g:i A', strtotime((string) $value));
$eventLabel = static fn (string $code): string => match ($code) {
    'created' => 'Damage recorded',
    'detail_corrected' => 'Details corrected',
    'severity_changed' => 'Severity changed',
    'worsened' => 'Damage worsened',
    'accepted_unrepaired' => 'Accepted unrepaired',
    'repaired' => 'Repaired',
    'resolved_other' => 'Resolved — other',
    default => ucwords(str_replace('_', ' ', $code)),
};
?>
<section class="section vehicle-damage-section" id="vehicle-damage">
    <div class="section-heading">
        <p class="eyebrow">Persistent vehicle condition</p>
        <h2>Damage &amp; Condition</h2>
        <p class="muted">Current physical damage follows this vehicle across trips. Recovery exceptions and claims remain separate records.</p>
    </div>

    <?php if ($notice !== null): ?><div class="import-message tone-success"><strong><?= esc($notice) ?></strong></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="import-message tone-danger"><strong>Damage item was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (($vehicleDamage['has_unsafe'] ?? false) === true): ?>
        <div class="import-message tone-danger damage-unsafe-warning"><strong>Unsafe damage is recorded.</strong><p>Review the item before operating the vehicle. FleetOS has not changed vehicle availability automatically.</p></div>
    <?php endif; ?>

    <div class="damage-summary"><strong><?= count($current) ?> current known</strong><span><?= count($history) ?> historical</span></div>
    <?php if ($current === []): ?>
        <div class="empty-state">No current known damage recorded.</div>
    <?php else: ?>
        <div class="mapping-list damage-item-list">
            <?php foreach ($current as $item): ?>
                <article class="mapping-card damage-item-card<?= $item['severity_code'] === 'unsafe' ? ' damage-item-unsafe' : '' ?>" id="damage-item-<?= (int) $item['id'] ?>">
                    <div class="damage-item-heading"><div><p class="eyebrow">Current damage</p><h3><?= esc((string) $item['zone_label']) ?> · <?= esc((string) $item['damage_type_label']) ?></h3></div><div class="vehicle-status-row"><span class="status-badge <?= $item['severity_code'] === 'unsafe' ? 'tone-danger' : 'tone-warning' ?>"><?= esc((string) $item['severity_label']) ?></span><span class="status-badge tone-info"><?= esc((string) $item['status_label']) ?></span></div></div>
                    <p><?= nl2br(esc((string) $item['description'])) ?></p>
                    <dl class="issue-facts damage-facts">
                        <div><dt>Discovered</dt><dd><?= esc($dateTime($item['discovered_at'])) ?></dd></div>
                        <div><dt>Source trip</dt><dd><?= esc((string) ($item['turo_reservation_id'] ?? 'Not linked')) ?></dd></div>
                        <div><dt>Recovery exception</dt><dd><?= $item['vehicle_recovery_exception_id'] === null ? 'Not linked' : '#' . (int) $item['vehicle_recovery_exception_id'] . ' · ' . esc((string) $item['recovery_exception_status']) ?></dd></div>
                        <div><dt>Damage claim</dt><dd><?= $item['damage_claim_id'] === null ? 'Not linked' : esc((string) ($item['claim_number'] ?: '#' . (int) $item['damage_claim_id'])) . ' · ' . esc((string) ($item['claim_status_name'] ?? 'Status not entered')) ?></dd></div>
                    </dl>
                    <?php if ($item['evidence'] !== []): ?><div class="damage-evidence"><strong>Evidence</strong><ul><?php foreach ($item['evidence'] as $evidence): ?><li><?= esc((string) ($evidence['label'] ?: $evidence['external_reference'] ?: $evidence['original_filename'] ?: $evidence['alt_text'] ?: 'Stored evidence')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                    <details class="capital-disclosure"><summary>History and updates · <?= count($item['events']) ?></summary>
                        <ol class="damage-history"><?php foreach ($item['events'] as $event): ?><li><strong><?= esc($eventLabel((string) $event['event_code'])) ?></strong><span><?= esc($dateTime($event['occurred_at'])) ?> · operator #<?= (int) $event['actor_user_id'] ?></span><?php if ($event['turo_reservation_id'] !== null): ?><span>Trip <?= esc((string) $event['turo_reservation_id']) ?></span><?php endif; ?><?php if ($event['note'] !== null): ?><p><?= nl2br(esc((string) $event['note'])) ?></p><?php endif; ?></li><?php endforeach; ?></ol>
                        <div class="damage-update-grid">
                            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage/<?= (int) $item['id'] ?>/worsen" method="post"><?= csrf_field() ?><h4>Record worsening</h4><p class="muted damage-action-help">Use this only when this same damage item has become worse. Record newly found damage as a separate item.</p><label>New severity<select name="severity_code" required><?php foreach ($worseningSeverities($severities, (string) $item['severity_code']) as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $code === $item['severity_code'] ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>What became worse<textarea name="note" rows="2" maxlength="2000" required></textarea></label><label>Occurred at<input type="datetime-local" name="occurred_at"></label><button class="secondary-action" type="submit">Record worsening</button></form>
                            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage/<?= (int) $item['id'] ?>/correct" method="post"><?= csrf_field() ?><h4>Correct details</h4><label>Zone<select name="zone_code" required><?php foreach ($zones as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $code === $item['zone_code'] ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Type<select name="damage_type_code" required><?php foreach ($types as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $code === $item['damage_type_code'] ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Description<textarea name="description" rows="2" maxlength="2000" required><?= esc((string) $item['description']) ?></textarea></label><label>Correction reason<textarea name="note" rows="2" maxlength="2000" required></textarea></label><button class="secondary-action" type="submit">Save correction</button></form>
                            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage/<?= (int) $item['id'] ?>/severity" method="post"><?= csrf_field() ?><h4>Change severity</h4><label>Severity<select name="severity_code" required><?php foreach ($severities as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $code === $item['severity_code'] ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label><label>Reason<textarea name="note" rows="2" maxlength="2000" required></textarea></label><button class="secondary-action" type="submit">Update severity</button></form>
                            <div class="damage-status-actions"><h4>Physical status</h4><?php foreach (['accepted_unrepaired' => 'Accept unrepaired', 'repaired' => 'Mark repaired', 'resolved_other' => 'Resolve another way'] as $status => $button): ?><?php if ($status === 'accepted_unrepaired' && $item['status_code'] === 'accepted_unrepaired') {
                                continue;
                            } ?><form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage/<?= (int) $item['id'] ?>/status/<?= esc($status, 'attr') ?>" method="post"><?= csrf_field() ?><label><?= esc($button) ?> reason<textarea name="note" rows="2" maxlength="2000" required></textarea></label><button class="<?= $status === 'repaired' ? 'primary-action' : 'secondary-action' ?>" type="submit"><?= esc($button) ?></button></form><?php endforeach; ?></div>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <details class="capital-disclosure damage-create"<?= $form === 'new' ? ' open' : '' ?>><summary>Record vehicle damage</summary>
        <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage" method="post"><?= csrf_field() ?>
            <div class="issue-filters damage-form-grid">
                <label>Vehicle zone<select name="zone_code" required><option value="">Choose zone</option><?php foreach ($zones as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('zone_code', $code) ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Damage type<select name="damage_type_code" required><option value="">Choose type</option><?php foreach ($types as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('damage_type_code', $code) ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Severity<select name="severity_code" required><?php foreach ($severities as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= $selected('severity_code', $code, 'cosmetic') ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Discovered at<input type="datetime-local" name="discovered_at" value="<?= esc((string) ($formData['discovered_at'] ?? date('Y-m-d\TH:i')), 'attr') ?>" required></label>
                <label class="capital-wide">Description<textarea name="description" rows="3" maxlength="2000" required><?= esc((string) ($formData['description'] ?? '')) ?></textarea></label>
                <label class="capital-wide">External evidence reference <span class="muted">optional Turo text, URL, or ID</span><input name="external_reference" maxlength="500" value="<?= esc((string) ($formData['external_reference'] ?? ''), 'attr') ?>"></label>
                <label>Evidence label <span class="muted">optional</span><input name="evidence_label" maxlength="190" value="<?= esc((string) ($formData['evidence_label'] ?? ''), 'attr') ?>"></label>
                <p class="muted capital-wide">Add one evidence reference at a time. Existing files or images must already belong to this vehicle.</p>
                <details class="capital-disclosure damage-link-details capital-wide"><summary>Link existing FleetOS records <span class="muted">optional</span></summary><div class="damage-link-grid">
                    <label>Source trip ID<input name="trip_id" inputmode="numeric" value="<?= esc((string) ($formData['trip_id'] ?? ''), 'attr') ?>"></label>
                    <label>Movement event ID<input name="movement_event_id" inputmode="numeric" value="<?= esc((string) ($formData['movement_event_id'] ?? ''), 'attr') ?>"></label>
                    <label>Recovery exception ID<input name="recovery_exception_id" inputmode="numeric" value="<?= esc((string) ($formData['recovery_exception_id'] ?? ''), 'attr') ?>"></label>
                    <label>Damage claim ID<input name="damage_claim_id" inputmode="numeric" value="<?= esc((string) ($formData['damage_claim_id'] ?? ''), 'attr') ?>"></label>
                    <label>Existing vehicle file ID<input name="file_id" inputmode="numeric" value="<?= esc((string) ($formData['file_id'] ?? ''), 'attr') ?>"></label>
                    <label>Existing vehicle image ID<input name="image_id" inputmode="numeric" value="<?= esc((string) ($formData['image_id'] ?? ''), 'attr') ?>"></label>
                </div></details>
            </div><div class="form-actions"><button class="primary-action" type="submit">Record damage</button></div>
        </form>
    </details>

    <?php if ($history !== []): ?><details class="capital-disclosure damage-resolved"><summary>Repaired / resolved history · <?= count($history) ?></summary><div class="mapping-list"><?php foreach ($history as $item): ?><article class="mapping-card"><div><strong><?= esc((string) $item['zone_label']) ?> · <?= esc((string) $item['damage_type_label']) ?></strong><p><?= esc((string) $item['description']) ?></p></div><div><span class="status-badge tone-success"><?= esc((string) $item['status_label']) ?></span><p><?= esc($dateTime($item['resolved_at'])) ?></p></div><details class="capital-disclosure"><summary>Audit history · <?= count($item['events']) ?></summary><ol class="damage-history"><?php foreach ($item['events'] as $event): ?><li><strong><?= esc($eventLabel((string) $event['event_code'])) ?></strong><span><?= esc($dateTime($event['occurred_at'])) ?> · operator #<?= (int) $event['actor_user_id'] ?></span><?php if ($event['note'] !== null): ?><p><?= nl2br(esc((string) $event['note'])) ?></p><?php endif; ?></li><?php endforeach; ?></ol></details></article><?php endforeach; ?></div></details><?php endif; ?>
</section>
