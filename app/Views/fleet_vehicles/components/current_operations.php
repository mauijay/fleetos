<?php
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed> $currentLocation */
/** @var array<string, mixed>|null $currentReadiness */
/** @var string|null $currentMovementHref */
/** @var array<string, array<string, mixed>> $hnlGarages */
$currentPositionData ??= [];
$currentReadinessData ??= [];
$positionObservedAt = (string) ($currentPositionData['occurred_at'] ?? date('Y-m-d\TH:i'));
$readinessObservedAt = (string) ($currentReadinessData['occurred_at'] ?? date('Y-m-d\TH:i'));
$selectedLocation = (string) ($currentPositionData['location_class'] ?? 'home');
$locationLabels = ['home' => 'Home', 'airport_hnl' => 'HNL', 'waikiki_hotel' => 'Waikiki Hotel', 'other_delivery' => 'Other', 'unknown' => 'Unknown'];
$isRented = ($currentLocation['operational_state'] ?? null) === 'rented';
$locationClass = trim((string) ($currentLocation['location_class'] ?? '')) ?: 'unknown';
$positionLabel = $isRented ? 'Rented' : ($locationLabels[$locationClass] ?? ucwords(str_replace('_', ' ', $locationClass)));
$garageCode = (string) ($currentLocation['airport_garage_code'] ?? '');
$positionDetails = [];
if ($garageCode !== '') {
    $positionDetails[] = (string) ($hnlGarages[$garageCode]['name'] ?? $garageCode);
    $positionDetails[] = 'Level ' . (string) ($currentLocation['airport_parking_level'] ?? '?');
    $positionDetails[] = 'Row ' . (string) ($currentLocation['airport_parking_row'] ?? '?');
} elseif (trim((string) ($currentLocation['location_detail'] ?? '')) !== '') {
    $positionDetails[] = (string) $currentLocation['location_detail'];
}
?>
<section class="section current-operations" id="current-operations" aria-labelledby="current-operations-heading">
    <div class="section-heading">
        <div><p class="eyebrow">Operator workspace</p><h2 id="current-operations-heading">Current Operations</h2></div>
        <div class="form-actions current-operations__links">
            <a class="action-link" href="/operations/vehicles/<?= (int) $vehicle['id'] ?>/trip-history">Trip History</a>
            <?php if ($currentMovementHref !== null): ?><a class="action-link" href="<?= esc($currentMovementHref, 'attr') ?>">Open Movement</a><?php endif; ?>
        </div>
    </div>

    <?php if ($currentStateNotice !== null): ?><div class="import-message tone-success"><strong><?= esc($currentStateNotice) ?></strong></div><?php endif; ?>
    <?php if ($currentStateError !== null): ?><div class="import-message tone-danger"><strong><?= esc($currentStateError) ?></strong></div><?php endif; ?>

    <div class="current-operations__summary">
        <div><span>Current position</span><strong><?= esc($positionLabel) ?></strong><?php if ($positionDetails !== []): ?><small><?= esc(implode(' · ', $positionDetails)) ?></small><?php endif; ?><?php if (($currentLocation['observed_at'] ?? null) !== null): ?><small>Observed <?= esc(date('M j, g:i A', strtotime((string) $currentLocation['observed_at']))) ?></small><?php endif; ?></div>
        <div><span>Current readiness</span><?php if ($currentReadiness === null): ?><strong>Not currently known</strong><?php else: ?><strong><?= esc(ucfirst((string) $currentReadiness['cleanliness'])) ?> · <?= (int) $currentReadiness['energy_percent'] ?>%</strong><small>Observed <?= esc(date('M j, g:i A', strtotime((string) $currentReadiness['captured_at']))) ?></small><?php endif; ?></div>
    </div>

    <?php if ($isRented): ?>
        <p class="muted">Current position and readiness cannot be certified while the vehicle is with a guest. Record its return or recovery first.</p>
    <?php else: ?>
        <div class="current-operations__forms">
            <details class="capital-disclosure" <?= $currentPositionData === [] ? '' : 'open' ?>><summary>Record Current Position</summary>
                <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/current-position" method="post"><?= csrf_field() ?>
                    <div class="issue-filters">
                        <fieldset class="local-datetime-fields" data-local-datetime><legend>Observed time</legend><label>Date<input type="date" name="occurred_on" required value="<?= esc(substr($positionObservedAt, 0, 10), 'attr') ?>"></label><label>Time<input type="time" name="occurred_time" required step="60" value="<?= esc(substr($positionObservedAt, 11, 5), 'attr') ?>"></label><input type="hidden" name="occurred_at" value="<?= esc($positionObservedAt, 'attr') ?>"><span>Honolulu local time</span></fieldset>
                        <label>Current position<select id="vehicle-current-position-class" name="location_class" required><?php foreach (['home' => 'Home', 'airport_hnl' => 'HNL', 'other_delivery' => 'Other'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>" <?= $selectedLocation === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                        <label data-location-detail <?= $selectedLocation === 'airport_hnl' ? 'hidden' : '' ?>>Location detail<input name="location_detail" maxlength="500" value="<?= esc((string) ($currentPositionData['location_detail'] ?? ''), 'attr') ?>" <?= $selectedLocation === 'airport_hnl' ? 'disabled' : '' ?>></label>
                        <fieldset class="hnl-parking-fields" data-hnl-parking data-location-select="vehicle-current-position-class"><legend>HNL parking</legend><label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= (string) ($currentPositionData['airport_parking_row'] ?? '') === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label><label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= (string) ($currentPositionData['airport_garage_code'] ?? '') === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?> · <?= esc($garage['color']) ?></option><?php endforeach; ?></select></label><label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= (string) ($currentPositionData['airport_parking_level'] ?? '') === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label></fieldset>
                        <label class="capital-wide">Optional detail / note<textarea name="note" rows="2"><?= esc((string) ($currentPositionData['note'] ?? '')) ?></textarea></label>
                    </div><div class="form-actions"><button class="primary-action" type="submit">Confirm Current Position</button></div>
                </form>
            </details>

            <details class="capital-disclosure" <?= $currentReadinessData === [] ? '' : 'open' ?>><summary>Record Current Readiness</summary>
                <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/current-readiness" method="post"><?= csrf_field() ?>
                    <div class="issue-filters">
                        <fieldset class="local-datetime-fields" data-local-datetime><legend>Observed time</legend><label>Date<input type="date" name="occurred_on" required value="<?= esc(substr($readinessObservedAt, 0, 10), 'attr') ?>"></label><label>Time<input type="time" name="occurred_time" required step="60" value="<?= esc(substr($readinessObservedAt, 11, 5), 'attr') ?>"></label><input type="hidden" name="occurred_at" value="<?= esc($readinessObservedAt, 'attr') ?>"><span>Honolulu local time</span></fieldset>
                        <label>Cleanliness<select name="cleanliness" required><option value="clean" <?= ($currentReadinessData['cleanliness'] ?? 'clean') === 'clean' ? 'selected' : '' ?>>Clean</option><option value="dirty" <?= ($currentReadinessData['cleanliness'] ?? null) === 'dirty' ? 'selected' : '' ?>>Dirty</option></select></label>
                        <label>Charge / fuel percentage<input type="number" name="energy_percent" min="0" max="100" required value="<?= esc((string) ($currentReadinessData['energy_percent'] ?? ''), 'attr') ?>"></label>
                        <label class="capital-wide">Optional note<textarea name="note" rows="2"><?= esc((string) ($currentReadinessData['note'] ?? '')) ?></textarea></label>
                    </div><div class="form-actions"><button class="primary-action" type="submit">Confirm Current Readiness</button></div>
                </form>
            </details>
        </div>
    <?php endif; ?>
</section>
