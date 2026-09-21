<?php
/** @var array<string, mixed> $checklist */
/** @var array<string, mixed>|null $currentLocation */
/** @var array<string, array<string, mixed>|null>|null $tripContext */
/** @var bool $showPositionForm */
/** @var array<string, mixed> $positionFormData */
/** @var array<string, array<string, mixed>> $hnlGarages */
$readOnly ??= false;
$isRented = ($currentLocation['operational_state'] ?? null) === 'rented';
$locationClass = (string) ($currentLocation['location_class'] ?? 'unknown');
$locationLabels = ['home' => 'Home', 'airport_hnl' => 'Airport HNL', 'waikiki_hotel' => 'Waikiki Hotel', 'other_delivery' => 'Other', 'unknown' => 'Unknown'];
$selectedLocation = (string) ($positionFormData['location_class'] ?? 'home');
$occurredAt = (string) ($positionFormData['occurred_at'] ?? date('Y-m-d\TH:i'));
$selectedGarage = (string) ($positionFormData['airport_garage_code'] ?? '');
$selectedLevel = (string) ($positionFormData['airport_parking_level'] ?? '');
$selectedRow = (string) ($positionFormData['airport_parking_row'] ?? '');
$nextTrip = $tripContext['next'] ?? null;
$nextHnlPickupHref = ($nextTrip['pickup_location_class'] ?? null) === 'airport_hnl' ? ($nextTrip['movement_href'] ?? null) : null;
$positionDetail = trim((string) ($currentLocation['location_detail'] ?? ''));
if ($locationClass === 'airport_hnl') {
    $hnlCatalog = new \App\Services\Fleet\HnlGarageCatalog();
    $legacyParking = $hnlCatalog->parseLegacyDetail($positionDetail);
    $positionDetail = (string) ($hnlCatalog->locationLabel(
        (string) ($currentLocation['airport_garage_code'] ?? ($legacyParking['garage_code'] ?? '')),
        $currentLocation['airport_parking_level'] ?? ($legacyParking['level'] ?? null),
        (string) ($currentLocation['airport_parking_row'] ?? ($legacyParking['row'] ?? '')),
        $currentLocation['location_note'] ?? ($legacyParking === null ? $positionDetail : null),
    ) ?? $positionDetail);
}
?>
<section class="section position-panel" aria-labelledby="position-heading">
    <div class="section-heading"><div><p class="eyebrow">Current vehicle position</p><h2 id="position-heading"><?= $isRented ? 'Rented' : esc($locationLabels[$locationClass] ?? ucwords(str_replace('_', ' ', $locationClass))) ?></h2></div><?php if (! $readOnly): ?><a class="action-link" href="/fleet/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>/positioning-plan">Positioning plan</a><?php endif; ?></div>
    <?php if ($isRented): ?><p class="position-detail">Guest handoff recorded<?= $positionDetail === '' ? '' : ' at ' . esc($positionDetail) ?>.</p>
    <?php else: ?><p class="position-detail"><?= $positionDetail === '' ? 'No additional location detail.' : esc($positionDetail) ?><?php if (($currentLocation['observed_at'] ?? null) !== null): ?> · confirmed <?= esc(date('M j, g:i A', strtotime((string) $currentLocation['observed_at']))) ?><?php endif; ?></p><?php endif; ?>

    <?php if (! $readOnly && ! $isRented && ! $showPositionForm): ?><a class="primary-action position-action" href="/operations/checklists/<?= (int) $checklist['id'] ?>?action=position#position-entry">Record vehicle position</a><?php endif; ?>
    <?php if (! $readOnly && ! $isRented && $nextHnlPickupHref !== null): ?><a class="secondary-action position-action" href="<?= esc((string) $nextHnlPickupHref, 'attr') ?>#handoff-entry">Stage at HNL for next pickup</a><?php endif; ?>
    <?php if (! $readOnly && ! $isRented && $showPositionForm): ?>
        <form id="position-entry" class="issue-filters position-form" action="/operations/checklists/<?= (int) $checklist['id'] ?>/vehicle-position" method="post">
            <?= csrf_field() ?>
            <fieldset class="local-datetime-fields" data-local-datetime><legend>Actual position time</legend><label>Date<input type="date" name="occurred_on" required value="<?= esc(substr($occurredAt, 0, 10), 'attr') ?>"></label><label>Time<input type="time" name="occurred_time" required step="60" value="<?= esc(substr($occurredAt, 11, 5), 'attr') ?>"></label><input type="hidden" name="occurred_at" value="<?= esc($occurredAt, 'attr') ?>"><span>Honolulu local time</span></fieldset>
            <label>Actual location<select id="position-location-class" name="location_class" required><?php foreach (['home', 'waikiki_hotel', 'other_delivery', 'airport_hnl'] as $location): ?><?php if (($checklist['movement_type'] ?? '') === 'pickup' && $location === 'airport_hnl') {
                continue;
            } ?><option value="<?= esc($location, 'attr') ?>" <?= $selectedLocation === $location ? 'selected' : '' ?>><?= esc($locationLabels[$location]) ?></option><?php endforeach; ?></select></label>
            <label data-location-detail <?= $selectedLocation === 'airport_hnl' ? 'hidden' : '' ?>>Location detail<input name="location_detail" maxlength="500" value="<?= esc((string) ($positionFormData['location_detail'] ?? ''), 'attr') ?>" <?= $selectedLocation === 'airport_hnl' ? 'disabled' : '' ?>></label>
            <?php if (($checklist['movement_type'] ?? '') !== 'pickup'): ?><fieldset class="hnl-parking-fields" data-hnl-parking data-location-select="position-location-class"><legend>HNL parking</legend><label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= $selectedLevel === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label><label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= $selectedRow === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label><label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= $selectedGarage === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?> · <?= esc($garage['color']) ?></option><?php endforeach; ?></select></label></fieldset><?php endif; ?>
            <?php if (($checklist['movement_type'] ?? '') !== 'pickup'): ?><p class="muted">Airport HNL here records physical position only. Use the pickup movement to record guest staging.</p><?php endif; ?>
            <label>Note<textarea name="note" rows="2"><?= esc((string) ($positionFormData['note'] ?? '')) ?></textarea></label>
            <div class="form-actions"><button class="primary-action" type="submit">Confirm actual position</button><a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>">Cancel</a></div>
        </form>
    <?php elseif (! $readOnly && ($checklist['movement_type'] ?? '') === 'pickup' && ! $isRented): ?><p class="muted">Use the pickup staging workflow for an actual HNL guest staging position.</p><?php endif; ?>
</section>
