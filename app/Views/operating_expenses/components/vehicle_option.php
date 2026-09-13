<?php

/** @var array{id:mixed,display_name?:mixed,fleet_code?:mixed,fleet_number?:mixed} $vehicle */
/** @var bool $isSelected */
$label = '';
foreach (['display_name', 'fleet_code'] as $field) {
    $label = trim((string) ($vehicle[$field] ?? ''));
    if ($label !== '') {
        break;
    }
}
if ($label === '') {
    $fleetNumber = trim((string) ($vehicle['fleet_number'] ?? ''));
    $label = $fleetNumber !== '' ? 'Fleet #' . $fleetNumber : 'Vehicle #' . (int) $vehicle['id'];
}
?>
<option value="<?= (int) $vehicle['id'] ?>"<?= $isSelected ? ' selected' : '' ?>><?= esc($label) ?></option>
