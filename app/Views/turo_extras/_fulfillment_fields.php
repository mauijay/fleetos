<?php
/** @var array<string, mixed> $extra */
$extra ??= [];
$type = (string) ($extra['fulfillment_type'] ?? 'none');
$phase = (string) ($extra['fulfillment_phase'] ?? '');
?>
<fieldset class="extra-fulfillment-config wide-field">
    <legend>Trip fulfillment</legend>
    <label>Fulfillment type<select name="fulfillment_type" required>
        <?php foreach (['none' => 'None / not configured', 'informational' => 'Informational only', 'pack' => 'Pack', 'install' => 'Install', 'configure' => 'Configure', 'logistics' => 'Logistics'] as $value => $label): ?>
            <option value="<?= esc($value, 'attr') ?>"<?= $type === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label>Fulfillment phase<select name="fulfillment_phase"><option value="">Not applicable</option><?php foreach (['preparation' => 'Preparation', 'pickup' => 'Pickup', 'return' => 'Return', 'entire_trip' => 'Entire trip'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $phase === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
    <label class="wide-field">Default operator action<input name="default_action_label" maxlength="190" value="<?= esc((string) ($extra['default_action_label'] ?? ''), 'attr') ?>" placeholder="Example: Pack {quantity} beach gear set(s)"></label>
    <label class="checkbox-field"><input name="requires_operator_confirmation" type="checkbox" value="1"<?= (int) ($extra['requires_operator_confirmation'] ?? 0) === 1 ? ' checked' : '' ?>> Operator must confirm fulfillment</label>
    <label class="checkbox-field"><input name="readiness_blocking" type="checkbox" value="1"<?= (int) ($extra['readiness_blocking'] ?? 0) === 1 ? ' checked' : '' ?>> Blocks movement readiness until confirmed</label>
    <p class="muted wide-field">Use <code>{quantity}</code> when quantity changes the physical work. Unknown source quantity remains unknown.</p>
</fieldset>
