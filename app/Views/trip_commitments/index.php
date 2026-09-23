<?php
/** @var array{css:?string,js:?string} $assets */
/** @var list<array{label:string,href:string,active:string}> $navigation */
/** @var array<string,mixed> $workspace */
/** @var array{label:string,href:string} $backLink */
/** @var array<string,mixed>|null $editing */
/** @var array<string,mixed> $formData */
/** @var string|null $success */
/** @var string|null $error */
$trip = $workspace['trip'];
$workspace['fleet_extras'] ??= [];
$tripIsOperational = (bool) $workspace['trip_is_operational'];
$tripStateHeading = strtolower((string) ($trip['trip_status_code'] ?? '')) === 'invalid' ? 'Trip invalid' : 'Trip canceled';
$preservedHeading = $tripStateHeading === 'Trip invalid' ? 'Commitments preserved from invalid trip' : 'Commitments preserved from canceled trip';
$form = array_merge($editing ?? [
    'category' => 'pickup_instruction', 'instruction' => '', 'applies_during' => 'preparation',
    'handling_mode' => 'informational', 'required_before_dispatch' => false,
    'energy_comparison' => 'target', 'energy_percent' => '', 'energy_min_percent' => '', 'energy_max_percent' => '', 'arranged_at' => null, 'fleet_extra_id' => null,
], $formData);
$tripLabel = trim((string) ($trip['turo_reservation_id'] ?? '')) ?: (string) $trip['turo_trip_id'];
$vehicleLabel = trim((string) ($trip['display_name'] ?? '')) ?: (string) $trip['fleet_code'];
$location = static fn (?string $class, ?string $source): string => trim((string) $source) !== '' ? (string) $source : ucwords(str_replace('_', ' ', (string) ($class ?: 'Not captured')));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guest Commitments | FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame guest-commitments-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main operator-main guest-commitments-main" tabindex="-1">
        <header class="top-status">
            <div><p class="eyebrow">Trip-specific operator truth</p><h1>Guest Commitments</h1><p class="status-copy">Guest-specific instructions and promises for this trip. Turo reservation facts remain unchanged.</p></div>
            <a class="action-link" href="<?= esc($backLink['href'], 'attr') ?>"><?= esc($backLink['label']) ?></a>
        </header>

        <?php if ($success !== null): ?><section class="section import-message tone-success" role="status"><strong><?= esc($success) ?></strong></section><?php endif; ?>
        <?php if ($error !== null): ?><section class="section import-message tone-danger" role="alert"><strong>Guest commitment not saved</strong><p><?= esc($error) ?></p></section><?php endif; ?>

        <?php if (! $tripIsOperational): ?>
            <section class="section import-message tone-warning" role="status" aria-labelledby="trip-applicability-heading">
                <strong id="trip-applicability-heading"><?= esc($tripStateHeading) ?></strong>
                <p>Guest commitments are preserved for history.</p>
                <p>No preparation is required.</p>
            </section>
        <?php endif; ?>

        <section class="section commitment-trip-context" aria-labelledby="trip-context-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Reservation context</p><h2 id="trip-context-heading"><?= esc($vehicleLabel) ?></h2></div><span class="count-pill">Trip <?= esc($tripLabel) ?></span></div>
            <dl class="commitment-trip-facts">
                <div><dt>Official Turo pickup</dt><dd><strong><?= esc(date('M j, Y · g:i A', strtotime((string) $trip['starts_at']))) ?></strong><span><?= esc($location($trip['pickup_location_class'] ?? null, $trip['pickup_location_source_text'] ?? null)) ?></span></dd></div>
                <div><dt>Official Turo return</dt><dd><strong><?= esc(date('M j, Y · g:i A', strtotime((string) $trip['ends_at']))) ?></strong><span><?= esc($location($trip['return_location_class'] ?? null, $trip['return_location_source_text'] ?? null)) ?></span></dd></div>
            </dl>
        </section>

        <?php if ($tripIsOperational): ?><?= view('trip_movement_checklists/_trip_preparation', ['checklist' => ['turo_trip_normalized_id' => (int) $trip['id']], 'extraPreparation' => $extraPreparation ?? []]) ?><?php endif; ?>

        <?php if ($tripIsOperational): ?>
        <section class="section" id="guest-commitments" aria-labelledby="commitments-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">What we promised</p><h2 id="commitments-heading">Active commitments</h2></div><span class="count-pill"><?= count($workspace['active']) ?> active</span></div>
            <?php if ($workspace['active'] === []): ?><div class="empty-state"><strong>No active guest commitments</strong><p>This trip follows its normal FleetOS preparation and movement rules.</p></div><?php endif; ?>
            <div class="guest-commitment-grid">
                <?php foreach ($workspace['active'] as $commitment): ?>
                    <article class="guest-commitment-card<?= $commitment['is_blocking'] ? ' is-blocking' : '' ?>" id="commitment-<?= (int) $commitment['id'] ?>">
                        <div class="guest-commitment-card__heading"><div><p class="eyebrow"><?= esc((string) $commitment['category_label']) ?></p><h3><?= esc((string) $commitment['instruction']) ?></h3></div><span class="status-badge <?= $commitment['is_blocking'] ? 'tone-warning' : 'tone-info' ?>"><?= $commitment['is_blocking'] ? 'Required' : esc((string) $commitment['handling_label']) ?></span></div>
                        <p class="muted"><?= esc((string) $commitment['phase_label']) ?><?= (int) $commitment['required_before_dispatch'] === 1 ? ' · Required before dispatch' : '' ?></p>
                        <?php if (($commitment['fleet_extra_name'] ?? null) !== null): ?><p class="muted">Linked Extra: <strong><?= esc((string) $commitment['fleet_extra_name']) ?></strong></p><?php endif; ?>
                        <?php if ($commitment['category'] === 'energy_override'): ?><div class="commitment-special-instruction"><strong>Special instruction</strong><?= view('trip_commitments/components/energy_override_context', ['commitment' => $commitment]) ?></div><?php endif; ?>
                        <?php if ($commitment['arranged_at'] !== null): ?><div class="commitment-time-arrangement"><span>Guest arrangement</span><strong><?= esc(date('M j, Y · g:i A', strtotime((string) $commitment['arranged_at']))) ?></strong></div><?php endif; ?>
                        <div class="commitment-actions">
                            <?php if ($commitment['handling_mode'] === 'acknowledgment' && $commitment['acknowledged_at'] === null): ?><form method="post" action="/operations/trips/<?= (int) $trip['id'] ?>/commitments/<?= (int) $commitment['id'] ?>/acknowledge"><?= csrf_field() ?><button class="secondary-action" type="submit">Acknowledge</button></form><?php endif; ?>
                            <?php if ($commitment['handling_mode'] === 'task'): ?><form method="post" action="/operations/trips/<?= (int) $trip['id'] ?>/commitments/<?= (int) $commitment['id'] ?>/complete"><?= csrf_field() ?><button class="primary-action" type="submit">Complete</button></form><?php endif; ?>
                            <a class="action-link" href="?edit=<?= (int) $commitment['id'] ?>#commitment-form">Edit</a>
                        </div>
                        <details class="secondary-disclosure"><summary>Mark not applicable</summary><form class="commitment-cancel-form" method="post" action="/operations/trips/<?= (int) $trip['id'] ?>/commitments/<?= (int) $commitment['id'] ?>/cancel"><?= csrf_field() ?><label>Reason<textarea name="cancellation_reason" rows="2" maxlength="4000" required></textarea></label><button class="secondary-action" type="submit">Mark not applicable</button></form></details>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" id="commitment-form" aria-labelledby="commitment-form-heading">
            <div class="section-heading"><div><p class="eyebrow"><?= $editing === null ? 'New promise' : 'Edit with audit history' ?></p><h2 id="commitment-form-heading"><?= $editing === null ? 'Add Guest Commitment' : 'Edit Guest Commitment' ?></h2></div></div>
            <form class="guest-commitment-form" method="post" action="/operations/trips/<?= (int) $trip['id'] ?>/commitments<?= $editing === null ? '' : '/' . (int) $editing['id'] . '/edit' ?>" data-commitment-form>
                <?= csrf_field() ?>
                <label>Category<select name="category" required data-commitment-category><?php foreach ($workspace['categories'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $form['category'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Applies during<select name="applies_during" required><?php foreach ($workspace['phases'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $form['applies_during'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Handling<select name="handling_mode" required data-commitment-handling><?php foreach ($workspace['handling_modes'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $form['handling_mode'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                <label>Related canonical Extra<select name="fleet_extra_id"><option value="">None</option><?php foreach ($workspace['fleet_extras'] as $extra): ?><option value="<?= (int) $extra['id'] ?>"<?= (int) ($form['fleet_extra_id'] ?? 0) === (int) $extra['id'] ? ' selected' : '' ?>><?= esc((string) $extra['display_name']) ?></option><?php endforeach; ?></select></label>
                <label class="checkbox-row"><input type="checkbox" name="required_before_dispatch" value="1"<?= (bool) $form['required_before_dispatch'] ? ' checked' : '' ?>><span>Required before dispatch</span></label>
                <label class="wide-field">Instruction<textarea name="instruction" rows="4" maxlength="4000" required><?= esc((string) $form['instruction']) ?></textarea></label>
                <fieldset class="commitment-conditional wide-field" data-energy-fields><legend>Trip energy rule</legend>
                    <label>What does the percentage mean?<select name="energy_comparison" data-energy-comparison><?php foreach ($workspace['energy_comparisons'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $form['energy_comparison'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></label>
                    <label data-single-energy>Percentage<input name="energy_percent" type="number" min="1" max="100" value="<?= esc((string) $form['energy_percent'], 'attr') ?>"></label>
                    <div class="commitment-range-fields" data-range-energy>
                        <label>Minimum %<input name="energy_min_percent" type="number" min="0" max="100" value="<?= esc((string) $form['energy_min_percent'], 'attr') ?>"></label>
                        <label>Preferred maximum %<input name="energy_max_percent" type="number" min="0" max="100" value="<?= esc((string) $form['energy_max_percent'], 'attr') ?>"></label>
                    </div>
                    <p class="muted">This replaces the normal vehicle energy policy only while preparing this exact trip. A preferred maximum is guidance, not a discharge requirement.</p>
                </fieldset>
                <fieldset class="commitment-conditional wide-field" data-timing-fields><legend>Guest arrangement</legend><label>Arranged date/time<input name="arranged_at" type="datetime-local" value="<?= $form['arranged_at'] === null ? '' : esc(date('Y-m-d\TH:i', strtotime((string) $form['arranged_at'])), 'attr') ?>"></label><p class="muted">The official Turo pickup and return times remain unchanged.</p></fieldset>
                <div class="form-actions wide-field"><button class="primary-action" type="submit"><?= $editing === null ? 'Add commitment' : 'Save changes' ?></button><?php if ($editing !== null): ?><a class="action-link" href="/operations/trips/<?= (int) $trip['id'] ?>/commitments#guest-commitments">Cancel edit</a><?php endif; ?></div>
            </form>
        </section>
        <?php else: ?>
        <section class="section" id="guest-commitments" aria-labelledby="preserved-commitments-heading">
            <div class="section-heading split-heading"><div><p class="eyebrow">Historical trip record</p><h2 id="preserved-commitments-heading"><?= esc($preservedHeading) ?></h2></div><span class="count-pill"><?= count($workspace['preserved']) ?> preserved</span></div>
            <?php if ($workspace['preserved'] === []): ?><div class="empty-state"><strong>No preserved guest commitments</strong><p>No guest-specific instructions were recorded for this trip.</p></div><?php endif; ?>
            <div class="guest-commitment-grid">
                <?php foreach ($workspace['preserved'] as $commitment): ?>
                    <article class="guest-commitment-card" id="commitment-<?= (int) $commitment['id'] ?>">
                        <div class="guest-commitment-card__heading"><div><p class="eyebrow"><?= esc((string) $commitment['category_label']) ?></p><h3><?= esc((string) $commitment['instruction']) ?></h3></div><span class="status-badge tone-info">Preserved</span></div>
                        <p class="muted"><?= esc((string) $commitment['phase_label']) ?> · Original handling: <?= esc((string) $commitment['handling_label']) ?><?= (int) $commitment['required_before_dispatch'] === 1 ? ' · Required before dispatch' : '' ?></p>
                        <?php if ($commitment['category'] === 'energy_override'): ?><div class="commitment-special-instruction"><strong>Recorded energy override</strong><span class="commitment-energy-rule"><?= esc((string) $commitment['energy_rule_summary']) ?></span></div><?php endif; ?>
                        <?php if ($commitment['arranged_at'] !== null): ?><div class="commitment-time-arrangement"><span>Recorded guest arrangement</span><strong><?= esc(date('M j, Y · g:i A', strtotime((string) $commitment['arranged_at']))) ?></strong></div><?php endif; ?>
                        <p class="muted">Recorded <?= esc((string) $commitment['created_at']) ?> · Last updated <?= esc((string) $commitment['updated_at']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="section" aria-labelledby="history-heading"><div class="section-heading split-heading"><div><p class="eyebrow">Preserved history</p><h2 id="history-heading">Completed and not applicable</h2></div><span class="count-pill"><?= count($workspace['history']) ?></span></div>
            <?php if ($workspace['history'] === []): ?><p class="muted">No historical commitments for this trip.</p><?php endif; ?>
            <div class="history-list"><?php foreach ($workspace['history'] as $commitment): ?><div><strong><?= esc((string) $commitment['instruction']) ?></strong><span><?= esc(ucfirst((string) $commitment['state'])) ?> · <?= esc((string) $commitment['category_label']) ?></span><?php if ($commitment['cancellation_reason'] !== null): ?><small><?= esc((string) $commitment['cancellation_reason']) ?></small><?php endif; ?></div><?php endforeach; ?></div>
            <details class="secondary-disclosure"><summary>Audit history · <?= count($workspace['audits']) ?> events</summary><div class="history-list"><?php foreach ($workspace['audits'] as $audit): ?><div><strong><?= esc(ucfirst((string) $audit['action'])) ?> commitment #<?= (int) $audit['commitment_id'] ?></strong><span><?= esc((string) $audit['created_at']) ?> · Operator #<?= (int) $audit['actor_user_id'] ?></span></div><?php endforeach; ?></div></details>
        </section>
        <?= view('fleet_command_center/components/footer') ?>
    </main>
</div>
<?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
