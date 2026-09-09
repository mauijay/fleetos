<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed> $checklist */
/** @var string|null $notice */
/** @var string|null $error */
/** @var array<string, mixed>|null $currentLocation */
/** @var array<string, mixed>|null $readiness */
/** @var array<string, mixed>|null $latestFacts */
/** @var array{pickup:array<string, mixed>|null,return:array<string, mixed>|null} $tripFacts */
/** @var bool $correctingFacts */
/** @var bool $isStagedPickup */
/** @var bool $isPickupConfirmed */
/** @var string|null $pickupConfirmedAt */
/** @var bool $repairingFacts */
/** @var array<int, array<string, mixed>> $repairCandidates */
/** @var array<int, array<string, mixed>> $repairConflicts */
/** @var array<string, mixed> $factFormData */
/** @var bool $isEarlyHandoffWarning */
/** @var array<string, array<string, mixed>|null>|null $tripContext */
/** @var array<string, mixed> $positionFormData */
/** @var bool $showPositionForm */
$hnlGarages ??= (new \App\Services\Fleet\HnlGarageCatalog())->definitions();
$isStagedPickup ??= false;
$isPickupConfirmed ??= false;
$pickupConfirmedAt ??= null;
$repairingFacts ??= false;
$repairCandidates ??= [];
$repairConflicts ??= [];
$tripContext ??= null;
$currentLocation ??= null;
$isEarlyHandoffWarning ??= false;
$readiness ??= null;
$positionFormData ??= [];
$showPositionForm ??= false;
$factTarget ??= null;
$tripFacts ??= [
    'pickup' => ($latestFacts['movement_type'] ?? $checklist['movement_type'] ?? null) === 'pickup' ? $latestFacts : null,
    'return' => ($latestFacts['movement_type'] ?? $checklist['movement_type'] ?? null) === 'return' ? $latestFacts : null,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Movement Checklist | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <main id="main-content" class="command-main import-main" tabindex="-1">
        <header class="top-status">
            <div>
                <p class="eyebrow">Movement Checklist</p>
                <h1><?= esc((string) ($checklist['fleet_code'] ?? 'Movement')) ?></h1>
                <p class="status-copy"><?= esc(ucfirst((string) ($checklist['movement_type'] ?? 'movement'))) ?> · <?= esc((string) ($checklist['scheduled_at'] ?? 'Time pending')) ?> · <?= esc((string) ($checklist['guest_name'] ?? 'Guest not captured')) ?></p>
            </div>
            <a class="action-link" href="/">Command Center</a>
        </header>

        <?php if ($notice !== null): ?><section class="section import-message tone-success"><strong><?= esc($notice) ?></strong></section><?php endif; ?>
        <?php if ($error !== null): ?><section class="section import-message tone-danger"><strong><?= esc($error) ?></strong></section><?php endif; ?>

        <?php if (! ($checklist['exists'] ?? false)): ?>
            <section class="section"><div class="empty-state">Checklist not found.</div></section>
        <?php else: ?>
            <?= view('trip_movement_checklists/_readiness', ['checklist' => $checklist, 'readiness' => $readiness, 'tripFacts' => $tripFacts]) ?>

            <?php if ($tripContext !== null): ?>
                <section class="section trip-context">
                    <div class="section-heading">
                        <div><p class="eyebrow">Reservation context</p><h2>Previous, current, next</h2></div>
                        <a class="action-link" href="/operations/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>/trip-history?trip=<?= (int) $checklist['turo_trip_normalized_id'] ?>">Vehicle trip history</a>
                    </div>
                    <div class="trip-context-grid">
                        <?php foreach (['previous' => 'Previous trip', 'current' => 'Selected trip', 'next' => 'Next trip'] as $position => $label): ?>
                            <?php $trip = $tripContext[$position] ?? null; ?>
                            <?php
                            $isCurrentTrip = $position === 'current';
                            $isCanceledTrip = $trip !== null && str_starts_with((string) ($trip['trip_status_code'] ?? ''), 'canceled');
                            $contextHref = ! $isCurrentTrip && $trip !== null ? ($trip['movement_href'] ?? null) : null;
                            $contextTag = $contextHref === null ? 'div' : 'a';
                            ?>
                            <<?= $contextTag ?> class="trip-context-item<?= $isCurrentTrip ? ' is-current' : '' ?><?= $isCanceledTrip ? ' is-canceled' : '' ?><?= $contextHref !== null ? ' is-linked' : '' ?>"<?= $contextHref === null ? '' : ' href="' . esc((string) $contextHref, 'attr') . '" aria-label="Open ' . esc(strtolower($label), 'attr') . ' movement"' ?>>
                                <p class="eyebrow"><?= esc($label) ?></p>
                                <?php if ($trip === null): ?>
                                    <p class="muted">None</p>
                                <?php else: ?>
                                    <strong><?= esc((string) ($trip['guest_name'] ?? 'Guest not captured')) ?></strong>
                                    <span>Trip <?= esc((string) ($trip['turo_trip_id'] ?? $trip['id'])) ?></span>
                                    <span><?= esc((new DateTimeImmutable((string) $trip['starts_at']))->format('M j, g:i A')) ?> to <?= esc((new DateTimeImmutable((string) $trip['ends_at']))->format('M j, g:i A')) ?></span>
                                    <?php if (($trip['pickup_location_class'] ?? null) !== null): ?><span>Pickup: <?= esc(ucwords(str_replace('_', ' ', (string) $trip['pickup_location_class']))) ?></span><?php endif; ?>
                                    <?php if (($trip['return_location_class'] ?? null) !== null): ?><span>Return: <?= esc(ucwords(str_replace('_', ' ', (string) $trip['return_location_class']))) ?></span><?php endif; ?>
                                    <span><?= esc(ucwords(str_replace('_', ' ', (string) ($trip['trip_status_code'] ?? 'status unknown')))) ?></span>
                                    <?php if ($contextHref !== null): ?><span class="trip-context-action">Open movement</span><?php endif; ?>
                                <?php endif; ?>
                            </<?= $contextTag ?>>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="section operational-facts">
                <div class="section-heading"><div><p class="eyebrow">Observed</p><h2>Trip facts</h2></div></div>
                <div class="trip-facts-grid">
                    <?php foreach (['pickup' => 'Pickup', 'return' => 'Return'] as $factType => $factLabel): ?>
                        <?php $fact = $tripFacts[$factType] ?? null; ?>
                        <article class="trip-fact<?= $factTarget === $factType ? ' is-selected' : '' ?>" aria-labelledby="<?= esc($factType, 'attr') ?>-fact-heading">
                            <div class="trip-fact__header">
                                <div><p class="eyebrow"><?= esc($factLabel) ?></p><h3 id="<?= esc($factType, 'attr') ?>-fact-heading"><?= $fact === null ? 'Not recorded' : esc((string) $fact['event_title']) ?></h3></div>
                                <?php if ($fact !== null && ! $correctingFacts && ! $repairingFacts): ?>
                                    <div class="fact-actions">
                                        <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>?correct=1&amp;fact=<?= esc($factType, 'attr') ?>">Correct <?= esc(strtolower($factLabel)) ?></a>
                                        <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>?repair=1&amp;fact=<?= esc($factType, 'attr') ?>">Recorded on wrong trip</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($fact !== null): ?>
                                <p class="trip-fact__time"><?= esc((string) $fact['occurred_at_label']) ?></p>
                                <dl class="movement-fact-summary">
                                    <div><dt><?= esc((string) $fact['location_label']) ?></dt><dd><?= esc((string) $fact['location_class_label']) ?><?php if (($fact['airport_garage_line'] ?? null) !== null): ?><span class="movement-fact-detail movement-fact-garage"><?= esc((string) $fact['airport_garage_line']) ?></span><span class="movement-fact-detail"><?= esc((string) $fact['airport_position_line']) ?></span><?php elseif ($fact['location_detail_value'] !== null): ?><span class="movement-fact-detail"><?= esc((string) $fact['location_detail_value']) ?></span><?php endif; ?></dd></div>
                                    <div><dt>Cleanliness</dt><dd><?= esc((string) $fact['cleanliness_label']) ?></dd></div>
                                    <div><dt><?= esc((string) $fact['energy_label']) ?></dt><dd><?= esc((string) $fact['energy_value']) ?></dd></div>
                                    <div><dt>Provenance</dt><dd><?= esc((string) $fact['source_label']) ?> · <?= esc((string) $fact['actor_label']) ?></dd></div>
                                </dl>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($tripFacts['pickup'] === null && $tripFacts['return'] === null && $currentLocation !== null): ?>
                    <p class="muted"><?= esc((string) ($currentLocation['location_label'] ?? 'Last known location')) ?>: <?= esc(ucwords(str_replace('_', ' ', (string) ($currentLocation['location_class'] ?? 'unknown')))) ?></p>
                <?php endif; ?>
                <?php
$movementType = (string) (($correctingFacts || $repairingFacts) ? ($latestFacts['movement_type'] ?? $factTarget ?? $checklist['movement_type'] ?? 'movement') : ($checklist['movement_type'] ?? 'movement'));
$formAction = $correctingFacts ? '/operations/checklists/' . (int) $checklist['id'] . '/facts/correct' : '/operations/checklists/' . (int) $checklist['id'] . '/facts';
$occurredAt = (string) ($factFormData['occurred_at'] ?? date('Y-m-d\TH:i'));
$occurredOn = substr($occurredAt, 0, 10);
$occurredTime = substr($occurredAt, 11, 5);
$selectedLocation = (string) ($factFormData['location_class'] ?? 'unknown');
$selectedGarage = (string) ($factFormData['airport_garage_code'] ?? '');
$selectedLevel = (string) ($factFormData['airport_parking_level'] ?? '');
$selectedRow = (string) ($factFormData['airport_parking_row'] ?? '');
$selectedCleanliness = (string) ($factFormData['cleanliness'] ?? '');
$energyPercent = $factFormData['energy_percent'] ?? '';
?>
                <?php if ($repairingFacts): ?>
                    <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/facts/repair-trip" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="event_id" value="<?= (int) ($latestFacts['event_id'] ?? 0) ?>">
                        <input type="hidden" name="assessment_id" value="<?= (int) ($latestFacts['assessment_id'] ?? 0) ?>">
                        <input type="hidden" name="fact_target" value="<?= esc((string) ($factTarget ?? $movementType), 'attr') ?>">
                        <label>Correct trip
                            <select name="target_trip_id" required data-repair-target-select>
                                <option value="">Choose a nearby trip</option>
                                <?php foreach ($repairCandidates as $candidate): ?>
                                    <?php $candidateLabel = (string) ($candidate['guest_name'] ?? 'Guest not captured') . ' · Trip ' . (string) ($candidate['turo_trip_id'] ?? $candidate['id']) . ' · ' . (new DateTimeImmutable((string) ($candidate[$movementType === 'pickup' ? 'starts_at' : 'ends_at'])))->format('M j, g:i A'); ?>
                                    <option value="<?= (int) $candidate['id'] ?>" data-repair-preview="<?= esc($candidateLabel, 'attr') ?>"><?= esc($candidateLabel) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) ($candidate['trip_status_code'] ?? 'status unknown')))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="repair-preview" data-repair-preview>
                            <div><span>From</span><strong><?= esc((string) ($checklist['guest_name'] ?? 'Guest not captured')) ?> · Trip <?= esc((string) ($checklist['turo_trip_id'] ?? $checklist['turo_trip_normalized_id'])) ?></strong></div>
                            <span aria-hidden="true">→</span>
                            <div><span>To</span><strong data-repair-preview-target>Choose a nearby trip</strong></div>
                        </div>
                        <?php if ($repairCandidates === []): ?><p class="muted">No compatible same-vehicle trip is nearby and free of conflicting facts.</p><?php endif; ?>
                        <?php if ($repairConflicts !== []): ?>
                            <div class="import-message tone-danger">
                                <strong>Conflicting movement facts</strong>
                                <?php foreach ($repairConflicts as $conflict): ?>
                                    <span><?= esc((string) ($conflict['guest_name'] ?? 'Guest not captured')) ?> · Trip <?= esc((string) ($conflict['turo_trip_id'] ?? $conflict['id'])) ?> already has <?= esc((string) $conflict['conflict_label']) ?>.</span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <label>Repair reason<textarea name="repair_reason" rows="2" required></textarea></label>
                        <label class="checkbox-row"><input type="checkbox" required><span>Confirm these facts were recorded on the wrong trip.</span></label>
                        <button class="primary-action" type="submit" <?= $repairCandidates === [] ? 'disabled' : '' ?>>Move Recorded Facts</button>
                        <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>">Cancel repair</a>
                    </form>
                <?php elseif ($movementType === 'pickup' && $isPickupConfirmed && ! $correctingFacts): ?>
                    <div class="import-message tone-success">
                        <strong>Guest pickup confirmed</strong>
                        <span><?= $pickupConfirmedAt === null ? 'Time not captured' : esc(date('M j, Y g:i A', strtotime($pickupConfirmedAt))) ?></span>
                    </div>
                <?php elseif ($movementType === 'pickup' && $isStagedPickup && ! $isPickupConfirmed && ! $correctingFacts): ?>
                    <form id="handoff-entry" class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/confirm-guest-pickup" method="post">
                        <?= csrf_field() ?>
                        <fieldset class="local-datetime-fields" data-local-datetime><legend>Guest pickup time</legend><label>Date<input type="date" name="occurred_on" required value="<?= esc($occurredOn, 'attr') ?>"></label><label>Time<input type="time" name="occurred_time" required step="60" value="<?= esc($occurredTime, 'attr') ?>"></label><input type="hidden" name="occurred_at" value="<?= esc($occurredAt, 'attr') ?>"><span>Honolulu local time</span></fieldset>
                        <label>Note<textarea name="note" rows="2"><?= esc((string) ($factFormData['note'] ?? '')) ?></textarea></label>
                        <?php if ($isEarlyHandoffWarning): ?><label class="checkbox-row"><input type="checkbox" name="confirm_early_handoff" value="1" required><span>I reviewed the selected reservation and confirm this early guest pickup time is correct.</span></label><?php endif; ?>
                        <button class="primary-action" type="submit">Confirm Guest Pickup</button>
                    </form>
                <?php elseif (! $correctingFacts && ($tripFacts[$movementType] ?? null) !== null): ?>
                    <div class="import-message tone-success">
                        <strong><?= $movementType === 'return' ? 'Actual return recorded' : 'Guest pickup recorded' ?></strong>
                        <span>Use the <?= esc($movementType) ?> fact actions above to correct or repair this observation.</span>
                    </div>
                <?php else: ?>
                <form id="handoff-entry" class="issue-filters" action="<?= esc($formAction, 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if ($correctingFacts): ?>
                        <input type="hidden" name="event_id" value="<?= (int) ($factFormData['event_id'] ?? 0) ?>">
                        <input type="hidden" name="assessment_id" value="<?= (int) ($factFormData['assessment_id'] ?? 0) ?>">
                        <input type="hidden" name="fact_target" value="<?= esc((string) ($factTarget ?? $movementType), 'attr') ?>">
                    <?php endif; ?>
                    <fieldset class="local-datetime-fields" data-local-datetime><legend>Actual time</legend><label>Date<input type="date" name="occurred_on" required value="<?= esc($occurredOn, 'attr') ?>"></label><label>Time<input type="time" name="occurred_time" required step="60" value="<?= esc($occurredTime, 'attr') ?>"></label><input type="hidden" name="occurred_at" value="<?= esc($occurredAt, 'attr') ?>"><span>Honolulu local time</span></fieldset>
                    <label><?= $movementType === 'pickup' ? 'Handoff location' : 'Return location' ?><select id="movement-location-class" name="location_class" required><?php foreach (['unknown', 'home', 'airport_hnl', 'waikiki_hotel', 'other_delivery'] as $location): ?><option value="<?= esc($location, 'attr') ?>" <?= $selectedLocation === $location ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $location))) ?></option><?php endforeach; ?></select></label>
                    <label data-location-detail <?= $selectedLocation === 'airport_hnl' ? 'hidden' : '' ?>>Location detail<input name="location_detail" maxlength="500" value="<?= esc((string) ($factFormData['location_detail'] ?? ''), 'attr') ?>" <?= $selectedLocation === 'airport_hnl' ? 'disabled' : '' ?>></label>
                    <fieldset class="hnl-parking-fields" data-hnl-parking data-location-select="movement-location-class">
                        <legend>HNL parking</legend>
                        <label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= $selectedRow === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label>
                        <label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= $selectedGarage === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?> · <?= esc($garage['color']) ?></option><?php endforeach; ?></select></label>
                        <label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= $selectedLevel === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label>
                    </fieldset>
                    <label>Cleanliness<select name="cleanliness"><option value="" <?= $selectedCleanliness === '' ? 'selected' : '' ?>>Not captured</option><option value="clean" <?= $selectedCleanliness === 'clean' ? 'selected' : '' ?>>Clean</option><option value="dirty" <?= $selectedCleanliness === 'dirty' ? 'selected' : '' ?>>Dirty</option></select></label>
                    <label>Charge/Fuel percent<input name="energy_percent" type="number" min="0" max="100" value="<?= esc((string) $energyPercent, 'attr') ?>"></label>
                    <label>Note<textarea name="note" rows="2"><?= esc((string) ($factFormData['note'] ?? '')) ?></textarea></label>
                    <?php if ($correctingFacts): ?><label>Correction reason<textarea name="correction_reason" rows="2" required><?= esc((string) ($factFormData['correction_reason'] ?? '')) ?></textarea></label><?php endif; ?>
                    <?php if (! $correctingFacts && $movementType === 'pickup'): ?>
                        <?php if ($isEarlyHandoffWarning): ?><label class="checkbox-row"><input type="checkbox" name="confirm_early_handoff" value="1" required><span>I reviewed the selected reservation and confirm this early guest handoff time is correct.</span></label><?php endif; ?>
                        <button class="primary-action" type="submit" formaction="/operations/checklists/<?= (int) $checklist['id'] ?>/stage-at-hnl">Stage at HNL</button>
                        <button class="secondary-action" type="submit">Record Guest Handoff</button>
                        <p class="muted">For HNL, stage the vehicle first. Use guest handoff directly for Home, Waikiki, or Other delivery.</p>
                    <?php else: ?>
                        <button class="primary-action" type="submit"><?= $correctingFacts ? 'Save Correction' : 'Record Actual Return' ?></button>
                    <?php endif; ?>
                    <?php if ($correctingFacts): ?><a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>">Cancel correction</a><?php endif; ?>
                </form>
                <?php endif; ?>
            </section>
            <?= view('trip_movement_checklists/_position', ['checklist' => $checklist, 'currentLocation' => $currentLocation, 'tripContext' => $tripContext, 'showPositionForm' => $showPositionForm, 'positionFormData' => $positionFormData, 'hnlGarages' => $hnlGarages]) ?>
        <?php endif; ?>

        <?= view('fleet_command_center/components/footer') ?>
    </main>
    <?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
