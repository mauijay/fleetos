<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed> $checklist */
/** @var string|null $notice */
/** @var string|null $error */
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

            <section class="section">
                <div class="movement-checklist-list">
                    <?php foreach ($checklist['items'] as $item): ?>
                        <article class="movement-checklist-item tone-<?= $item['completion_state'] === 'complete' ? 'success' : ($item['is_critical'] ? 'warning' : 'info') ?>">
                            <div>
                                <h3><?= esc($item['label']) ?></h3>
                                <p><?= $item['is_required'] ? 'Required' : 'Optional' ?> · <?= $item['is_critical'] ? 'Critical' : 'Standard' ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $item['completion_state']))) ?></p>
                            </div>
                            <div class="checklist-actions">
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/complete" method="post"><button class="primary-action" type="submit">Complete</button></form>
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/undo" method="post"><button class="secondary-action" type="submit">Undo</button></form>
                                <form action="/operations/checklist-items/<?= esc((string) $item['id'], 'attr') ?>/not-applicable" method="post"><button class="secondary-action" type="submit">N/A</button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section">
                <form class="resolution-form" action="/operations/checklists/<?= esc((string) $checklist['id'], 'attr') ?>/complete" method="post">
                    <label>Completion note
                        <textarea name="completion_note" rows="3" placeholder="Optional note"></textarea>
                    </label>
                    <button class="primary-action" type="submit">Complete Movement Workflow</button>
                </form>
                <?php if ($checklist['completed_at'] !== null): ?>
                    <form class="resolution-form" action="/operations/checklists/<?= esc((string) $checklist['id'], 'attr') ?>/reopen" method="post">
                        <label class="checkbox-row"><input type="checkbox" name="confirm_reopen" value="1" required><span>Confirm reopening this completed workflow.</span></label>
                        <button class="secondary-action" type="submit">Reopen Workflow</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
