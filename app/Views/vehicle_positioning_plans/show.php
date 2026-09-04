<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<int, array<string, string>> $navigation */
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed> $basis */
/** @var array<string, mixed> $recommendation */
/** @var array<string, mixed>|null $plan */
/** @var array<string, mixed>|null $nextTrip */
/** @var string|null $notice */
/** @var string|null $error */
$vehicleLabel = (string) ($vehicle['fleet_code'] ?? $vehicle['display_name'] ?? 'Vehicle');
$positioningOptions = [
    'leave_at_airport' => 'Leave at HNL',
    'retrieve_home' => 'Retrieve to home',
    'move_to_airport' => 'Move to HNL',
    'hold_home_flexible' => 'Hold at home',
    'operator_decision_needed' => 'Operator decision needed',
];
$locationOptions = ['' => 'No target', 'home' => 'Home', 'airport_hnl' => 'Airport HNL', 'waikiki_hotel' => 'Waikiki hotel', 'other_delivery' => 'Other delivery', 'unknown' => 'Unknown'];
$recommendationLabel = $positioningOptions[$recommendation['code'] ?? ''] ?? (string) $recommendation['label'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Positioning Plan | FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-frame import-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
        <main id="main-content" class="command-main import-main" tabindex="-1">
            <header class="top-status">
                <div><p class="eyebrow">Fleet Positioning</p><h1><?= esc($vehicleLabel) ?></h1><p class="status-copy">Review computed guidance, then record the operator's separate positioning plan.</p></div>
                <a class="button-link" href="/fleet/vehicles/<?= esc((string) $vehicle['id'], 'attr') ?>">Vehicle details</a>
            </header>

            <?php if ($notice !== null): ?><section class="section import-message tone-success"><strong><?= esc($notice) ?></strong></section><?php endif; ?>
            <?php if ($error !== null): ?><section class="section import-message tone-danger"><strong><?= esc($error) ?></strong></section><?php endif; ?>

            <section class="section" aria-labelledby="computed-recommendation">
                <div class="section-heading split-heading"><div><p class="eyebrow">FleetOS recommendation</p><h2 id="computed-recommendation"><?= esc((string) $recommendation['strength']) ?>: <?= esc($recommendationLabel) ?></h2></div><span class="status-badge tone-info">Derived guidance</span></div>
                <p><?= esc((string) $recommendation['explanation']) ?></p>
                <dl class="issue-facts">
                    <div><dt>Position basis</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) $basis['location_class']))) ?></dd></div>
                    <div><dt>Basis type</dt><dd><?= esc(ucfirst((string) $basis['basis_type'])) ?></dd></div>
                    <div><dt>Next trip</dt><dd><?= $nextTrip === null ? 'None confirmed' : esc((new DateTimeImmutable((string) $nextTrip['starts_at']))->format('M j, Y g:i A') . ' · ' . ($locationOptions[$nextTrip['pickup_location_class'] ?? 'unknown'] ?? 'Unknown')) ?></dd></div>
                </dl>
            </section>

            <section class="section" aria-labelledby="operator-plan">
                <div class="section-heading"><p class="eyebrow">Operator decision</p><h2 id="operator-plan">Operator positioning plan</h2></div>
                <?php if ($plan !== null): ?>
                    <div class="import-message <?= ($plan['is_basis_stale'] ?? true) ? 'tone-warning' : 'tone-success' ?>">
                        <?php $planDiffers = (string) ($plan['positioning_code'] ?? '') !== (string) ($recommendation['code'] ?? ''); ?>
                        <strong><?= ($plan['is_basis_stale'] ?? true) ? 'Existing plan is stale' : ($planDiffers ? 'Operator plan differs from recommendation' : 'Operator plan agrees with recommendation') ?></strong>
                        <h3><?= esc($positioningOptions[$plan['positioning_code']] ?? (string) $plan['positioning_code']) ?></h3>
                        <?php if (trim((string) ($plan['note'] ?? '')) !== ''): ?><p><?= esc((string) $plan['note']) ?></p><?php endif; ?>
                        <dl class="issue-facts">
                            <div><dt>Target</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) ($plan['target_location_class'] ?? 'No target')))) ?></dd></div>
                            <div><dt>Reason</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) ($plan['reason_code'] ?? 'Operator decision')))) ?></dd></div>
                            <div><dt>Set by</dt><dd><?= esc(trim((string) ($plan['actor_username'] ?? '')) ?: 'User #' . (int) ($plan['created_by'] ?? 0)) ?></dd></div>
                            <div><dt>Created</dt><dd><?= isset($plan['created_at']) ? esc((new DateTimeImmutable((string) $plan['created_at']))->format('M j, Y g:i A')) : 'Time not captured' ?></dd></div>
                            <?php if (($plan['transportation_state'] ?? 'not_applicable') !== 'not_applicable'): ?><div><dt>Transportation</dt><dd><?= esc(ucfirst((string) $plan['transportation_state'])) ?></dd></div><?php endif; ?>
                            <?php if (($plan['expires_at'] ?? null) !== null): ?><div><dt>Expires</dt><dd><?= esc((new DateTimeImmutable((string) $plan['expires_at']))->format('M j, Y g:i A')) ?></dd></div><?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>
                <form class="resolution-form" action="/fleet/vehicles/<?= esc((string) $vehicle['id'], 'attr') ?>/positioning-plan" method="post">
                    <?= csrf_field() ?>
                    <label>Positioning action<select name="positioning_code" required><option value="">Choose action</option><?php foreach ($positioningOptions as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
                    <label>Target location<select name="target_location_class"><?php foreach ($locationOptions as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option><?php endforeach; ?></select></label>
                    <label>Reason<select name="reason_code" required><option value="">Choose reason</option><option value="operator_choice">Operator choice</option><option value="trip_schedule">Trip schedule</option><option value="turnaround">Turnaround needs</option><option value="transportation">Transportation constraints</option><option value="logistics">Fleet logistics</option></select></label>
                    <label>Transportation dependency<select name="transportation_state"><option value="not_applicable">Not applicable</option><option value="unknown">Unknown</option><option value="confirmed">Confirmed</option><option value="unavailable">Unavailable</option></select></label>
                    <label>Expires at<input type="datetime-local" name="expires_at"></label>
                    <label>Operator note<textarea name="note" rows="3" maxlength="500" placeholder="Optional operational context"></textarea></label>
                    <button class="primary-action" type="submit">Save Operator Plan</button>
                </form>
            </section>
            <?= view('fleet_command_center/components/footer') ?>
        </main>
    </div>
    <?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>
