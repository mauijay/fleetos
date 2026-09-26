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
/** @var bool $canRecordRetroactiveHandoff */
/** @var bool $showRetroactiveHandoffForm */
/** @var array<string, mixed> $retroactiveHandoffData */
/** @var array<string, mixed>|null $guestReturn */
/** @var bool $guestReturnActive */
/** @var array<string, mixed> $guestReturnFormData */
/** @var bool $returnCompleted */
/** @var bool $correctGuestReturn */
/** @var bool $canRecover */
/** @var array<string, mixed> $recoveryFormData */
/** @var array<string, string> $recoveryLocationOptions */
/** @var string|null $recoveryLocationPrefill */
$tripIsOperational ??= true;
$tripStatusCode ??= null;
$hnlGarages ??= (new \App\Services\Fleet\HnlGarageCatalog())->definitions();
$guestReturn ??= null;
$guestReturnActive ??= false;
$guestReturnFormData ??= [];
$returnCompleted ??= false;
$correctGuestReturn ??= false;
$canRecover ??= false;
$recoveryFormData ??= [];
$recoveryLocationOptions ??= (new \App\Services\Fleet\LocationClassificationService())->recoveryLocationOptions();
$recoveryLocationPrefill ??= null;
$recoveryExceptions ??= [];
$turnaroundWork ??= ['cleaning' => null, 'energy' => null];
$navigation ??= [];
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
$canRecordRetroactiveHandoff ??= false;
$showRetroactiveHandoffForm ??= false;
$retroactiveHandoffData ??= [];
$factTarget ??= null;
$vehicleDamage ??= ['current' => [], 'history' => [], 'has_unsafe' => false, 'zones' => [], 'damage_types' => [], 'severities' => [], 'statuses' => []];
$availableDamageExceptions ??= [];
$vehicleDamageNotice ??= null;
$vehicleDamageErrors ??= [];
$vehicleDamageForm ??= null;
$vehicleDamageData ??= [];
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
    <title><?= ($checklist['movement_type'] ?? null) === 'return' ? 'Return Workflow' : 'Movement Checklist' ?> | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-frame import-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main import-main movement-main" tabindex="-1">
        <header class="top-status">
            <div>
                <p class="eyebrow"><?= ($checklist['movement_type'] ?? null) === 'return' ? 'Return Workflow' : 'Movement Checklist' ?></p>
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
            <?php if (! $tripIsOperational): ?>
                <?= view('trip_movement_checklists/_inactive', ['checklist' => $checklist, 'tripStatusCode' => $tripStatusCode]) ?>
            <?php else: ?>
            <?= view('trip_movement_checklists/_trip_preparation', ['checklist' => $checklist, 'extraPreparation' => $extraPreparation ?? []]) ?>
            <?php if (($checklist['movement_type'] ?? null) === 'return'): ?>
                <?= view('trip_movement_checklists/_return_workflow', ['checklist' => $checklist, 'readiness' => $readiness, 'guestReturn' => $guestReturn, 'guestReturnActive' => $guestReturnActive, 'returnCompleted' => $returnCompleted, 'canRecover' => $canRecover, 'turnaroundWork' => $turnaroundWork, 'recoveryExceptions' => $recoveryExceptions]) ?>
            <?php else: ?>
                <?= view('trip_movement_checklists/_readiness', ['checklist' => $checklist, 'readiness' => $readiness, 'tripFacts' => $tripFacts]) ?>
            <?php endif; ?>
            <?= view('trip_movement_checklists/_guest_commitments', ['checklist' => $checklist, 'guestCommitments' => $guestCommitments ?? []]) ?>
            <?= view('trip_movement_checklists/_known_damage', ['checklist' => $checklist, 'vehicleDamage' => $vehicleDamage, 'availableDamageExceptions' => $availableDamageExceptions, 'notice' => $vehicleDamageNotice, 'errors' => $vehicleDamageErrors, 'form' => $vehicleDamageForm, 'formData' => $vehicleDamageData]) ?>

            <?php if (($checklist['movement_type'] ?? null) === 'return'): ?>
                <?php if ($canRecover): ?>
                    <?php
                    $recoveryReport = $guestReturnActive ? $guestReturn : null;
                    $recoveryLocationCandidate = array_key_exists('location_class', $recoveryFormData)
                        ? (string) $recoveryFormData['location_class']
                        : (string) ($recoveryLocationPrefill ?? '');
                    $recoveryLocation = array_key_exists($recoveryLocationCandidate, $recoveryLocationOptions)
                        ? $recoveryLocationCandidate
                        : '';
                    $hasRecoveryLocation = $recoveryLocation !== '';
                    $isHnlRecovery = $recoveryLocation === 'airport_hnl';
                    $recoveryGarage = (string) ($recoveryFormData['airport_garage_code'] ?? ($recoveryReport['airport_garage_code'] ?? ''));
                    $recoveryLevel = (string) ($recoveryFormData['airport_parking_level'] ?? ($recoveryReport['airport_parking_level'] ?? ''));
                    $recoveryRow = (string) ($recoveryFormData['airport_parking_row'] ?? ($recoveryReport['airport_parking_row'] ?? ''));
                    $guestLocationNote = '';
                    if ($recoveryReport !== null && preg_match('/(?:^|\n)Location note: ([^\r\n]*)/', (string) ($recoveryReport['note'] ?? ''), $locationNoteMatch) === 1) {
                        $guestLocationNote = trim($locationNoteMatch[1]);
                    }
                    $priorLocationDetail = trim((string) ($recoveryReport['location_detail'] ?? ''));
                    if ($guestLocationNote === '' && $priorLocationDetail !== ''
                        && (($recoveryReport['location_class'] ?? null) !== 'airport_hnl'
                            || (new \App\Services\Fleet\HnlGarageCatalog())->parseLegacyDetail($priorLocationDetail) === null)) {
                        $guestLocationNote = $priorLocationDetail;
                    }
                    $recoveryTimeValue = (string) ($recoveryFormData['occurred_at'] ?? '');
                    if ($recoveryTimeValue === '' && ! ($recoveryNeedsDeliberateTime ?? false)) {
                        $recoveryTimeValue = date('Y-m-d\TH:i');
                    }
                    ?>
                    <section class="section operational-facts" id="recover-vehicle-entry">
                        <div class="section-heading"><div><p class="eyebrow">Operator recovery</p><h2>Recover Vehicle</h2></div></div>
                        <p class="muted">Confirm where and when you physically recovered the vehicle. Turo's return-photo and inspection workflow remains separate.</p>
                        <?php if ($recoveryReport !== null): ?><p class="import-message tone-warning">Guest reported — unverified. The location below is a prefill only; verify or correct it before recording recovery.</p><?php endif; ?>
                        <?php if ($recoveryNeedsDeliberateTime ?? false): ?>
                            <div class="import-message tone-warning">
                                <strong>Enter the actual recovery time.</strong> This is a historical return with later movement context, so FleetOS has not defaulted the time to now.
                                <ul class="compact-list">
                                    <?php if (! empty($recoveryChronology['scheduled_return_at'])): ?><li>Scheduled return: <?= esc(date('M j, Y g:i A', strtotime((string) $recoveryChronology['scheduled_return_at']))) ?></li><?php endif; ?>
                                    <?php if (! empty($recoveryChronology['guest_reported_at'])): ?><li>Guest-reported parked time: <?= esc(date('M j, Y g:i A', strtotime((string) $recoveryChronology['guest_reported_at']))) ?></li><?php endif; ?>
                                    <?php if (! empty($recoveryChronology['next_handoff_at'])): ?><li>Later guest handoff: <?= esc(date('M j, Y g:i A', strtotime((string) $recoveryChronology['next_handoff_at']))) ?></li><?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/recover-vehicle" method="post" data-recovery-location-form>
                            <?= csrf_field() ?>
                            <label>Recovery time (Honolulu)<input type="datetime-local" name="occurred_at" required value="<?= esc($recoveryTimeValue, 'attr') ?>"></label>
                            <label>Actual recovery location<select id="recovery-location-class" name="location_class" required data-recovery-location aria-controls="recovery-form-details" aria-expanded="<?= $hasRecoveryLocation ? 'true' : 'false' ?>"><option value="" <?= $recoveryLocation === '' ? 'selected' : '' ?>>Choose recovery location</option><?php foreach ($recoveryLocationOptions as $code => $label): ?><option value="<?= esc($code, 'attr') ?>" <?= $recoveryLocation === $code ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                            <fieldset class="recovery-form-details" id="recovery-form-details" data-recovery-details <?= $hasRecoveryLocation ? '' : 'hidden disabled' ?>>
                            <fieldset class="hnl-parking-fields" data-hnl-parking data-location-select="recovery-location-class" <?= $isHnlRecovery ? '' : 'hidden disabled' ?>><legend>Verified HNL parking</legend>
                                <label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= $recoveryLevel === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label>
                                <label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= $recoveryRow === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label>
                                <label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= $recoveryGarage === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?></option><?php endforeach; ?></select></label>
                            </fieldset>
                            <label data-recovery-location-detail>Location detail (optional)
                                <input name="location_detail" maxlength="500" value="<?= esc((string) ($recoveryFormData['location_detail'] ?? ''), 'attr') ?>" data-standard-recovery-location-detail <?= $isHnlRecovery ? 'hidden disabled' : '' ?>>
                                <input name="recovery_location_note" maxlength="500" value="<?= esc((string) ($recoveryFormData['recovery_location_note'] ?? $guestLocationNote), 'attr') ?>" data-hnl-recovery-location-detail <?= $isHnlRecovery ? '' : 'hidden disabled' ?>>
                            </label>
                            <label>Measured battery/fuel percent<input name="energy_percent" type="number" min="0" max="100" value="<?= esc((string) ($recoveryFormData['energy_percent'] ?? ''), 'attr') ?>"></label>
                            <label class="checkbox-row"><input type="checkbox" name="energy_unknown" value="1" <?= ($recoveryFormData['energy_unknown'] ?? null) === '1' ? 'checked' : '' ?>><span>Energy unknown — I could not get a measurement</span></label>
                            <label>Reason if energy unknown<input name="energy_unknown_reason" maxlength="500" value="<?= esc((string) ($recoveryFormData['energy_unknown_reason'] ?? ''), 'attr') ?>"></label>
                            <details class="secondary-disclosure"><summary>Exceptions? None unless selected</summary>
                                <p class="muted">Select only issues found during recovery. Turo's photos and inspection remain in Turo.</p>
                                <?php foreach (['damage' => 'Damage found', 'missing_key' => 'Missing key', 'missing_charge_adapter' => 'Missing charge adapter', 'not_drivable' => 'Vehicle not drivable', 'other' => 'Other'] as $exceptionCode => $exceptionLabel): ?>
                                    <label class="checkbox-row"><input type="checkbox" name="exception_codes[]" value="<?= esc($exceptionCode, 'attr') ?>" <?= in_array($exceptionCode, (array) ($recoveryFormData['exception_codes'] ?? []), true) ? 'checked' : '' ?>><span><?= esc($exceptionLabel) ?></span></label>
                                    <label><?= esc($exceptionLabel) ?> note<?= $exceptionCode === 'other' ? ' (required if selected)' : ' (optional)' ?><input name="exception_notes[<?= esc($exceptionCode, 'attr') ?>]" maxlength="2000" value="<?= esc((string) ($recoveryFormData['exception_notes'][$exceptionCode] ?? ''), 'attr') ?>"></label>
                                <?php endforeach; ?>
                            </details>
                            <label>Operational note (optional)<textarea name="note" rows="2"><?= esc((string) ($recoveryFormData['note'] ?? '')) ?></textarea></label>
                            <label class="checkbox-row"><input type="checkbox" name="confirm_recovery_location" value="1" required><span>I verified the actual recovery location; guest-reported details alone are not proof.</span></label>
                            <button class="primary-action" type="submit">Vehicle Recovered</button>
                            </fieldset>
                        </form>
                    </section>
                <?php endif; ?>
                <?php
                $correctGuestReturn = ! $returnCompleted && $guestReturn !== null && $correctGuestReturn;
                $showGuestReturnSection = ! $returnCompleted || $guestReturn !== null;
                $reportGarage = (string) ($guestReturnFormData['airport_garage_code'] ?? ($correctGuestReturn ? $guestReturn['airport_garage_code'] : ''));
                $reportLevel = (string) ($guestReturnFormData['airport_parking_level'] ?? ($correctGuestReturn ? $guestReturn['airport_parking_level'] : ''));
                $reportRow = (string) ($guestReturnFormData['airport_parking_row'] ?? ($correctGuestReturn ? $guestReturn['airport_parking_row'] : ''));
                ?>
                <?php if ($showGuestReturnSection): ?>
                <section class="section operational-facts" id="guest-return-entry">
                    <div class="section-heading"><div><p class="eyebrow">Guest report</p><h2>Return staged at HNL</h2></div></div>
                    <?php if ($guestReturn !== null): ?>
                        <div class="import-message tone-warning">
                            <strong><?= $guestReturnActive ? 'Awaiting Recovery' : 'Historical guest report' ?></strong>
                            <span>Guest-reported location — unverified. This report does not confirm operator possession or complete the return.</span>
                            <span><?= ($guestReturn['source'] ?? null) === 'guest_report_received' ? 'Report received' : 'Guest-reported parked time' ?>: <?= esc(date('M j, Y g:i A', strtotime((string) $guestReturn['occurred_at']))) ?></span>
                            <?php $reportedParking = (new \App\Services\Fleet\HnlGarageCatalog())->presentation($guestReturn['airport_garage_code'] ?? null, $guestReturn['airport_parking_level'] ?? null, $guestReturn['airport_parking_row'] ?? null); ?>
                            <span><?= $reportedParking === null ? 'Exact HNL level/row/garage not reported' : esc($reportedParking['location_label']) ?></span>
                            <?php if (trim((string) ($guestReturn['note'] ?? '')) !== ''): ?><span><?= nl2br(esc((string) $guestReturn['note'])) ?></span><?php endif; ?>
                        </div>
                        <?php if ($returnCompleted): ?><p class="muted">Recovery is authoritative. Any older return checklist checks remain available only in legacy history.</p><?php endif; ?>
                        <?php if (! $returnCompleted && ! $correctGuestReturn): ?>
                            <a class="action-link" href="?correct_guest_return=1#guest-return-entry">Correct guest report</a>
                            <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/guest-return-staged/void" method="post"><?= csrf_field() ?><input type="hidden" name="event_id" value="<?= (int) $guestReturn['id'] ?>"><label>Void reason<textarea name="void_reason" rows="2" required></textarea></label><button class="secondary-action" type="submit">Void guest report</button></form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="muted">When the guest reports the vehicle parked, record the unverified location here. Recovery is a separate operator action.</p>
                    <?php endif; ?>
                    <?php if (($guestReturn === null && ! $returnCompleted) || $correctGuestReturn): ?>
                        <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/guest-return-staged<?= $correctGuestReturn ? '/correct' : '' ?>" method="post">
                            <?= csrf_field() ?>
                            <?php if ($correctGuestReturn): ?><input type="hidden" name="event_id" value="<?= (int) $guestReturn['id'] ?>"><?php endif; ?>
                            <label>Guest-reported parked time (optional)<input type="datetime-local" name="reported_parked_at" value="<?= esc((string) ($guestReturnFormData['reported_parked_at'] ?? ($correctGuestReturn && ($guestReturn['source'] ?? '') !== 'guest_report_received' ? date('Y-m-d\TH:i', strtotime((string) $guestReturn['occurred_at'])) : '')), 'attr') ?>"></label>
                            <p class="muted">If unknown, FleetOS records the report-received time instead. Honolulu local time.</p>
                            <fieldset class="hnl-parking-fields" data-hnl-parking><legend>Guest-reported HNL location — unverified</legend>
                                <p class="muted">Leave all three blank if the exact parking location is unknown. If reported, choose a level and row; FleetOS derives the matching garage.</p>
                                <label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= $reportLevel === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label>
                                <label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= $reportRow === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label>
                                <label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= $reportGarage === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?></option><?php endforeach; ?></select></label>
                            </fieldset>
                            <?php if ($correctGuestReturn): ?>
                                <label>Report/location note<textarea name="note" rows="2"><?= esc((string) ($guestReturnFormData['note'] ?? $guestReturn['note'])) ?></textarea></label>
                                <label>Correction reason<textarea name="correction_reason" rows="2" required></textarea></label>
                                <button class="primary-action" type="submit">Save Correction</button>
                                <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>#guest-return-entry">Cancel</a>
                            <?php else: ?>
                                <label>Location note<input name="location_note" maxlength="500" value="<?= esc((string) ($guestReturnFormData['location_note'] ?? ''), 'attr') ?>"></label>
                                <label>Guest report note<textarea name="guest_report_note" rows="2"><?= esc((string) ($guestReturnFormData['guest_report_note'] ?? '')) ?></textarea></label>
                                <button class="primary-action" type="submit">Mark Awaiting Recovery</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

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
                            $contextHref = $trip !== null ? ($trip['movement_href'] ?? null) : null;
                            $commitmentsHref = $trip === null ? null : '/operations/trips/' . (int) $trip['id'] . '/commitments';
                            ?>
                            <article class="trip-context-item<?= $isCurrentTrip ? ' is-current' : '' ?><?= $isCanceledTrip ? ' is-canceled' : '' ?>">
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
                                    <?php if ($contextHref !== null): ?><a class="action-link trip-context-action" href="<?= esc((string) $contextHref, 'attr') ?>" aria-label="Open <?= esc(strtolower($label), 'attr') ?> movement">Open movement</a><?php endif; ?>
                                    <a class="action-link trip-context-action" href="<?= esc((string) $commitmentsHref, 'attr') ?>" aria-label="Guest commitments for <?= esc(strtolower($label), 'attr') ?>">Guest commitments</a>
                                <?php endif; ?>
                            </article>
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
                                <?php if ($tripIsOperational && $fact !== null && ! $correctingFacts && ! $repairingFacts): ?>
                                    <div class="fact-actions">
                                        <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>?correct=1&amp;fact=<?= esc($factType, 'attr') ?>">Correct <?= esc(strtolower($factLabel)) ?></a>
                                        <a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>?repair=1&amp;fact=<?= esc($factType, 'attr') ?>">Recorded on wrong trip</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($tripIsOperational && $factType === 'pickup' && $fact === null && $canRecordRetroactiveHandoff): ?>
                                <?php if (! $showRetroactiveHandoffForm): ?>
                                    <a class="action-link" href="?action=record-handoff#pickup-fact-heading">Record pickup / handoff</a>
                                <?php else: ?>
                                    <form class="issue-filters" action="/operations/trips/<?= (int) $checklist['turo_trip_normalized_id'] ?>/actual-handoff" method="post">
                                        <?= csrf_field() ?>
                                        <label>Actual pickup time (Honolulu)<input type="datetime-local" name="occurred_at" required value="<?= esc((string) ($retroactiveHandoffData['occurred_at'] ?? ''), 'attr') ?>"></label>
                                        <label>Handoff location (optional)<select name="location_class"><option value="">Not captured</option><?php foreach (['unknown' => 'Unknown', 'home' => 'Home', 'airport_hnl' => 'Airport HNL', 'waikiki_hotel' => 'Waikiki Hotel', 'other_delivery' => 'Other delivery'] as $locationCode => $locationLabel): ?><option value="<?= esc($locationCode, 'attr') ?>" <?= ($retroactiveHandoffData['location_class'] ?? '') === $locationCode ? 'selected' : '' ?>><?= esc($locationLabel) ?></option><?php endforeach; ?></select></label>
                                        <label>Location detail (optional)<input name="location_detail" maxlength="500" value="<?= esc((string) ($retroactiveHandoffData['location_detail'] ?? ''), 'attr') ?>"></label>
                                        <label>Cleanliness (optional)<select name="cleanliness"><option value="">Not captured</option><option value="clean" <?= ($retroactiveHandoffData['cleanliness'] ?? '') === 'clean' ? 'selected' : '' ?>>Clean</option><option value="dirty" <?= ($retroactiveHandoffData['cleanliness'] ?? '') === 'dirty' ? 'selected' : '' ?>>Dirty</option></select></label>
                                        <label>Charge/Fuel percent (optional)<input name="energy_percent" type="number" min="0" max="100" value="<?= esc((string) ($retroactiveHandoffData['energy_percent'] ?? ''), 'attr') ?>"></label>
                                        <label>Note (optional)<textarea name="note" rows="2" maxlength="2000"><?= esc((string) ($retroactiveHandoffData['note'] ?? '')) ?></textarea></label>
                                        <div class="form-actions"><button class="primary-action" type="submit">Record Guest Handoff</button><a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>#pickup-fact-heading">Cancel</a></div>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($fact !== null): ?>
                                <p class="trip-fact__time"><?= esc((string) $fact['occurred_at_label']) ?></p>
                                <dl class="movement-fact-summary">
                                    <div><dt><?= esc((string) $fact['location_label']) ?></dt><dd><?= esc((string) $fact['location_class_label']) ?><?php if (($fact['airport_location_label'] ?? null) !== null): ?><span class="movement-fact-detail movement-fact-garage"><?= esc((string) $fact['airport_location_label']) ?></span><?php elseif ($fact['location_detail_value'] !== null): ?><span class="movement-fact-detail"><?= esc((string) $fact['location_detail_value']) ?></span><?php endif; ?></dd></div>
                                    <div><dt>Cleanliness</dt><dd><?= esc((string) $fact['cleanliness_label']) ?></dd></div>
                                    <div><dt><?= esc((string) $fact['energy_label']) ?></dt><dd><?= esc((string) $fact['energy_value']) ?></dd></div>
                                    <div><dt>Provenance</dt><dd><?= esc((string) $fact['source_label']) ?> · <?= esc((string) $fact['actor_label']) ?></dd></div>
                                </dl>
                                <?php if ($tripIsOperational && $factType === 'return' && ($fact['event_code'] ?? null) === 'vehicle_recovered'): ?>
                                    <details class="secondary-disclosure"><summary>Void recovery</summary>
                                        <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/recover-vehicle/void" method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="event_id" value="<?= (int) $fact['event_id'] ?>">
                                            <label>Why is this recovery invalid?<textarea name="void_reason" rows="2" required></textarea></label>
                                            <button class="secondary-action" type="submit">Void Vehicle Recovery</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($tripFacts['pickup'] === null && $tripFacts['return'] === null && $currentLocation !== null): ?>
                    <p class="muted"><?= esc((string) ($currentLocation['location_label'] ?? 'Last known location')) ?>: <?= esc(ucwords(str_replace('_', ' ', (string) ($currentLocation['location_class'] ?? 'unknown')))) ?></p>
                <?php endif; ?>
                <?php if ($tripIsOperational): ?>
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
                            <strong><?= $movementType === 'return' ? (($tripFacts['return']['event_code'] ?? null) === 'vehicle_recovered' ? 'Vehicle recovery recorded' : 'Actual return recorded') : 'Guest pickup recorded' ?></strong>
                        <span>Use the <?= esc($movementType) ?> fact actions above to correct or repair this observation.</span>
                    </div>
                <?php elseif ($movementType === 'return' && ! $correctingFacts): ?>
                    <p class="muted">Use Recover Vehicle above for a new return. Historical actual-return facts remain available for correction and audit.</p>
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
                        <label>Level<select name="airport_parking_level" data-hnl-level><option value="">Choose level</option><?php for ($level = 1; $level <= 8; $level++): ?><option value="<?= $level ?>" <?= $selectedLevel === (string) $level ? 'selected' : '' ?>><?= $level ?></option><?php endfor; ?></select></label>
                        <label>Row<select name="airport_parking_row" data-hnl-row><option value="">Choose row</option><?php foreach ($hnlGarages as $code => $garage): ?><?php foreach ($garage['rows'] as $row): ?><option value="<?= esc($row, 'attr') ?>" data-garage="<?= esc($code, 'attr') ?>" <?= $selectedRow === $row ? 'selected' : '' ?>><?= esc($row) ?></option><?php endforeach; ?><?php endforeach; ?></select></label>
                        <label>Garage<select name="airport_garage_code" data-hnl-garage><option value="">Derived from row</option><?php foreach ($hnlGarages as $code => $garage): ?><option value="<?= esc($code, 'attr') ?>" data-max-level="<?= (int) $garage['levels'] ?>" <?= $selectedGarage === $code ? 'selected' : '' ?>><?= esc($garage['name']) ?> · <?= esc($garage['color']) ?></option><?php endforeach; ?></select></label>
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
                <?php endif; ?>
            </section>
            <?= view('trip_movement_checklists/_position', ['checklist' => $checklist, 'currentLocation' => $currentLocation, 'tripContext' => $tripContext, 'showPositionForm' => $showPositionForm, 'positionFormData' => $positionFormData, 'hnlGarages' => $hnlGarages, 'readOnly' => ! $tripIsOperational]) ?>
        <?php endif; ?>

        <?= view('fleet_command_center/components/footer') ?>
    </main>
    </div>
    <?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
