<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed> $checklist */
/** @var string|null $notice */
/** @var string|null $error */
/** @var array<string, mixed>|null $currentLocation */
/** @var array<string, mixed>|null $latestFacts */
/** @var bool $correctingFacts */
/** @var array<string, mixed> $factFormData */
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
            <section class="section briefing-card">
                <p class="eyebrow">Readiness</p>
                <h2><?= esc(ucwords(str_replace('_', ' ', (string) $checklist['readiness_status']))) ?></h2>
                <p class="briefing-copy"><?= esc((string) $checklist['progress']['required_complete_count']) ?> of <?= esc((string) $checklist['progress']['required_count']) ?> required items complete. <?= esc((string) $checklist['progress']['required_remaining_count']) ?> remaining.</p>
            </section>

            <?php if (($checklist['movement_type'] ?? '') === 'return'): ?>
                <section class="section">
                    <form class="issue-filters" action="/operations/checklists/<?= esc((string) $checklist['id'], 'attr') ?>/disposition" method="post">
                        <?= csrf_field() ?>
                        <label>Vehicle disposition
                            <select name="vehicle_disposition" required>
                                <?php foreach (['available', 'needs_cleaning', 'needs_charging', 'maintenance_required', 'claim_review_required', 'offline'] as $disposition): ?>
                                    <option value="<?= esc($disposition, 'attr') ?>" <?= ($checklist['vehicle_disposition'] ?? '') === $disposition ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $disposition))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="primary-action" type="submit">Save Disposition</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="section operational-facts">
                <div class="section-heading"><p class="eyebrow">Observed</p><h2>Operational Facts</h2></div>
                <?php if ($latestFacts !== null): ?>
                    <div class="briefing-card">
                        <div class="section-heading">
                            <div><p class="eyebrow">Latest saved facts</p><h3><?= esc((string) $latestFacts['event_title']) ?></h3></div>
                            <?php if (! $correctingFacts): ?><a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>?correct=1">Correct recorded facts</a><?php endif; ?>
                        </div>
                        <p class="briefing-copy"><?= esc((string) $latestFacts['occurred_at_label']) ?></p>
                        <dl class="movement-fact-summary">
                            <div><dt><?= esc((string) $latestFacts['location_label']) ?></dt><dd><?= esc((string) $latestFacts['location_class_label']) ?><?php if ($latestFacts['location_detail_value'] !== null): ?><span class="movement-fact-detail"><?= esc((string) $latestFacts['location_detail_value']) ?></span><?php endif; ?></dd></div>
                            <div><dt>Cleanliness</dt><dd><?= esc((string) $latestFacts['cleanliness_label']) ?></dd></div>
                            <div><dt><?= esc((string) $latestFacts['energy_label']) ?></dt><dd><?= esc((string) $latestFacts['energy_value']) ?></dd></div>
                            <div><dt>Provenance</dt><dd><?= esc((string) $latestFacts['source_label']) ?> · <?= esc((string) $latestFacts['actor_label']) ?></dd></div>
                        </dl>
                    </div>
                <?php elseif ($currentLocation !== null): ?>
                    <p class="muted"><?= esc((string) ($currentLocation['location_label'] ?? 'Last known location')) ?>: <?= esc(ucwords(str_replace('_', ' ', (string) ($currentLocation['location_class'] ?? 'unknown')))) ?></p>
                <?php endif; ?>
                <?php
                $movementType = (string) ($checklist['movement_type'] ?? 'movement');
$formAction = $correctingFacts ? '/operations/checklists/' . (int) $checklist['id'] . '/facts/correct' : '/operations/checklists/' . (int) $checklist['id'] . '/facts';
$occurredAt = (string) ($factFormData['occurred_at'] ?? date('Y-m-d\TH:i'));
$selectedLocation = (string) ($factFormData['location_class'] ?? 'unknown');
$selectedCleanliness = (string) ($factFormData['cleanliness'] ?? '');
$energyPercent = $factFormData['energy_percent'] ?? '';
?>
                <form class="issue-filters" action="<?= esc($formAction, 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if ($correctingFacts): ?>
                        <input type="hidden" name="event_id" value="<?= (int) ($factFormData['event_id'] ?? 0) ?>">
                        <input type="hidden" name="assessment_id" value="<?= (int) ($factFormData['assessment_id'] ?? 0) ?>">
                    <?php endif; ?>
                    <label>Actual time<input type="datetime-local" name="occurred_at" required value="<?= esc($occurredAt, 'attr') ?>"></label>
                    <label><?= $movementType === 'pickup' ? 'Handoff location' : 'Current location' ?><select name="location_class" required><?php foreach (['unknown', 'home', 'airport_hnl', 'waikiki_hotel', 'other_delivery'] as $location): ?><option value="<?= esc($location, 'attr') ?>" <?= $selectedLocation === $location ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $location))) ?></option><?php endforeach; ?></select></label>
                    <label>Location detail<input name="location_detail" maxlength="500" value="<?= esc((string) ($factFormData['location_detail'] ?? ''), 'attr') ?>"></label>
                    <label>Cleanliness<select name="cleanliness"><option value="" <?= $selectedCleanliness === '' ? 'selected' : '' ?>>Not captured</option><option value="clean" <?= $selectedCleanliness === 'clean' ? 'selected' : '' ?>>Clean</option><option value="dirty" <?= $selectedCleanliness === 'dirty' ? 'selected' : '' ?>>Dirty</option></select></label>
                    <label>Energy percent<input name="energy_percent" type="number" min="0" max="100" value="<?= esc((string) $energyPercent, 'attr') ?>"></label>
                    <label>Note<textarea name="note" rows="2"><?= esc((string) ($factFormData['note'] ?? '')) ?></textarea></label>
                    <?php if ($correctingFacts): ?><label>Correction reason<textarea name="correction_reason" rows="2" required><?= esc((string) ($factFormData['correction_reason'] ?? '')) ?></textarea></label><?php endif; ?>
                    <button class="primary-action" type="submit"><?= $correctingFacts ? 'Save Correction' : ($movementType === 'pickup' ? 'Record Guest Handoff' : 'Record Actual Return') ?></button>
                    <?php if ($correctingFacts): ?><a class="action-link" href="/operations/checklists/<?= (int) $checklist['id'] ?>">Cancel correction</a><?php endif; ?>
                </form>
            </section>

            <section class="section">
                <div class="movement-checklist-list">
                    <?php foreach ($checklist['items'] as $item): ?>
                        <article class="movement-checklist-item tone-<?= $item['completion_state'] === 'complete' ? 'success' : ($item['is_critical'] ? 'warning' : 'info') ?>">
                            <div>
                                <h3><?= esc($item['label']) ?></h3>
                                <p><?= $item['is_required'] ? 'Required' : 'Optional' ?> · <?= $item['is_critical'] ? 'Critical' : 'Standard' ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $item['completion_state']))) ?></p>
                            </div>
                            <div class="checklist-actions">
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/complete" method="post"><?= csrf_field() ?><button class="primary-action" type="submit">Complete</button></form>
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/undo" method="post"><?= csrf_field() ?><button class="secondary-action" type="submit">Undo</button></form>
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/not-applicable" method="post"><?= csrf_field() ?><button class="secondary-action" type="submit">N/A</button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section">
                <form class="resolution-form" action="/operations/checklists/<?= esc((string) $checklist['id'], 'attr') ?>/complete" method="post">
                    <?= csrf_field() ?>
                    <label>Completion note
                        <textarea name="completion_note" rows="3" placeholder="Optional note"></textarea>
                    </label>
                    <button class="primary-action" type="submit">Complete Movement Workflow</button>
                </form>
                <?php if ($checklist['completed_at'] !== null): ?>
                    <form class="resolution-form" action="/operations/checklists/<?= esc((string) $checklist['id'], 'attr') ?>/reopen" method="post">
                        <?= csrf_field() ?>
                        <label class="checkbox-row"><input type="checkbox" name="confirm_reopen" value="1" required><span>Confirm reopening this completed workflow.</span></label>
                        <button class="secondary-action" type="submit">Reopen Workflow</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?= view('fleet_command_center/components/footer') ?>
    </main>
</body>
</html>
