<?php $formData ??= []; ?>
<form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage-incidents" method="post" data-damage-incident-form>
<?= csrf_field() ?><h2>Incident details</h2><div class="damage-form-grid issue-filters">
<label>Vehicle<input readonly value="<?= esc((string) ($vehicle['fleet_code'] ?? $vehicle['id']), 'attr') ?>"></label>
<label>Discovered at<input type="datetime-local" name="discovered_at" required value="<?= esc((string) ($formData['discovered_at'] ?? date('Y-m-d\TH:i')), 'attr') ?>"></label>
<label>Occurred at (optional)<input type="datetime-local" name="occurred_at" value="<?= esc((string) ($formData['occurred_at'] ?? ''), 'attr') ?>"></label>
<label>Reservation / trip (optional)<select name="trip_id"><option value="">Unknown</option><?php foreach ($trips as $trip): ?><option value="<?= (int) $trip['id'] ?>"<?= (int) ($formData['trip_id'] ?? 0) === (int) $trip['id'] ? ' selected' : '' ?>><?= esc((string) $trip['turo_reservation_id']) ?></option><?php endforeach; ?></select></label>
<label>Attribution meaning<select name="attribution_type"><?php foreach (\Config\VehicleDamage::ATTRIBUTIONS as $code => $label): ?><option value="<?= esc($code, 'attr') ?>"<?= ($formData['attribution_type'] ?? 'unknown') === $code ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
<label>Overall note (optional)<textarea name="overall_note" maxlength="2000" rows="2"><?= esc((string) ($formData['overall_note'] ?? '')) ?></textarea></label></div>
<h2>Damage areas</h2><div data-damage-areas>
<?php $submittedAreas = $formData['areas'] ?? [[]]; $areas = is_array($submittedAreas) ? array_slice(array_values(array_filter($submittedAreas, 'is_array')), 0, 40) : []; $areas = $areas === [] ? [[]] : $areas; foreach ($areas as $index => $area): ?>
<?= view('vehicle_damage_incidents/_areas', ['area' => $area, 'prefix' => 'areas[' . (int) $index . ']']) ?>
<?php endforeach; ?></div>
<div class="form-actions"><button type="button" class="secondary-action" data-add-damage-area>Add another area</button><button class="primary-action" type="submit">Record incident</button></div>
</form>
<template id="damage-area-template"><?= view('vehicle_damage_incidents/_areas', ['area' => [], 'prefix' => 'areas[__INDEX__]']) ?></template>
<script>
(() => {
    const form = document.querySelector('[data-damage-incident-form]');
    const list = form.querySelector('[data-damage-areas]');
    let index = <?= count($areas) ?>;
    form.querySelector('[data-add-damage-area]').addEventListener('click', () => {
        if (list.children.length >= 40) return;
        const template = document.getElementById('damage-area-template');
        list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index++)));
    });
    form.addEventListener('click', event => {
        if (event.target.matches('[data-remove-damage-area]') && list.children.length > 1) event.target.closest('fieldset').remove();
    });
})();
</script>
