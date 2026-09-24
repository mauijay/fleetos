<?php
/** @var array<string, mixed> $vehicle */
/** @var array<string, mixed> $vehicleHealth */
$notice ??= null;
$errors ??= [];
$form ??= null;
$formData ??= [];
$pressure = $vehicleHealth['current_tire_pressure'] ?? null;
$odometer = $vehicleHealth['current_odometer'] ?? null;
$policy = $vehicleHealth['policy'] ?? null;
$history = $vehicleHealth['history'] ?? [];
$reminders = $vehicleHealth['reminders'] ?? [];
$defaultObservedAt = (string) ($vehicleHealth['default_observed_at'] ?? '');
$dateTime = static fn (mixed $value): string => $value === null || $value === '' ? 'Not recorded' : date('M j, Y g:i A', strtotime((string) $value));
$psi = static fn (mixed $value): string => $value === null || $value === '' ? 'Not recorded' : number_format((int) $value) . ' PSI';
$psiRange = static fn (mixed $minimum, mixed $maximum): string => number_format((int) $minimum) . '–' . number_format((int) $maximum) . ' PSI';
$input = static function (string $name, mixed $fallback = '') use ($formData): string {
    return (string) (array_key_exists($name, $formData) ? $formData[$name] : $fallback);
};
$supersededIds = [];
foreach ($history as $row) {
    if (($row['supersedes_observation_id'] ?? null) !== null) {
        $supersededIds[(int) $row['supersedes_observation_id']] = true;
    }
}
?>
<section class="section vehicle-health" id="vehicle-health" aria-labelledby="vehicle-health-heading">
    <div class="section-heading split-heading">
        <div><p class="eyebrow">Authoritative observations</p><h2 id="vehicle-health-heading">Vehicle Health</h2></div>
        <span class="status-badge tone-info">Observation based</span>
    </div>
    <?php if ($notice !== null): ?><div class="import-message tone-success"><strong><?= esc($notice) ?></strong></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="import-message tone-danger"><strong>Vehicle health was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="vehicle-health-grid">
        <article class="operational-card">
            <p class="eyebrow">Current Health</p><h3>Tire pressure</h3>
            <?php if ($pressure === null): ?>
                <p class="muted">No tire-pressure observation recorded.</p>
            <?php else: ?>
                <dl class="health-reading-grid">
                    <?php foreach (['lf_psi' => 'LF', 'rf_psi' => 'RF', 'lr_psi' => 'LR', 'rr_psi' => 'RR'] as $field => $label): ?><div><dt><?= esc($label) ?></dt><dd><?= esc($psi($pressure[$field])) ?></dd></div><?php endforeach; ?>
                    <div><dt>Recommended</dt><dd><?= esc($psi($pressure['recommended_psi'])) ?></dd></div>
                    <div><dt>Observed</dt><dd><?= esc($dateTime($pressure['observed_at'])) ?></dd></div>
                    <div><dt>Source</dt><dd><?= esc(ucwords(str_replace('_', ' ', (string) $pressure['source']))) ?></dd></div>
                </dl>
            <?php endif; ?>
            <?php if ($policy === null): ?><p class="status-copy">Tire-pressure policy not configured.</p><?php else: ?><p class="status-copy">Acceptable range: <?= esc($psiRange($policy['acceptable_min_psi'], $policy['acceptable_max_psi'])) ?> · <?= (int) $policy['interval_value'] ?>-day checks<?= (bool) $policy['is_enabled'] ? '' : ' · Disabled' ?></p><?php endif; ?>
        </article>
        <article class="operational-card">
            <p class="eyebrow">Current Health</p><h3>Odometer</h3>
            <?php if ($odometer !== null): ?>
                <p class="health-primary-value"><?= number_format((int) $odometer['odometer_miles']) ?> mi</p>
                <p class="status-copy">Observed <?= esc($dateTime($odometer['observed_at'])) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $odometer['source']))) ?></p>
            <?php elseif (($vehicleHealth['legacy_odometer'] ?? null) !== null): ?>
                <p class="health-primary-value"><?= number_format((int) $vehicleHealth['legacy_odometer']) ?> mi</p>
                <p class="status-copy"><strong>Legacy odometer — unverified.</strong> Record a timestamped observation to establish authority.</p>
            <?php else: ?>
                <p class="muted">No authoritative odometer observation recorded.</p>
            <?php endif; ?>
        </article>
    </div>

    <div class="capital-subsection">
        <h3>Reminders</h3>
        <?php if ($reminders === []): ?><p class="empty-state">No vehicle-health action is required.</p><?php endif; ?>
        <div class="alert-stack"><?php foreach ($reminders as $reminder): ?>
            <article class="operational-card"><div class="split-heading"><div><strong><?= esc((string) $reminder['title']) ?></strong><p class="status-copy"><?= esc((string) $reminder['context']) ?></p></div><span class="status-badge <?= $reminder['state'] === 'attention' ? 'tone-warning' : 'tone-info' ?>"><?= esc(ucfirst((string) $reminder['state'])) ?></span></div>
            <?php if (($reminder['due_at'] ?? null) !== null): ?><small><?= ($reminder['state'] ?? null) === 'overdue' ? 'Routine check was due' : 'Next routine check' ?>: <?= esc($dateTime($reminder['due_at'])) ?></small><?php endif; ?>
            <?php if (($reminder['action_label'] ?? null) !== null): ?><p><a class="action-link" href="<?= esc((string) $reminder['href'], 'attr') ?>"><?= esc((string) $reminder['action_label']) ?></a></p><?php endif; ?></article>
        <?php endforeach; ?></div>
    </div>

    <div class="vehicle-health-actions">
        <details class="capital-disclosure" id="record-tire-pressure"<?= $form === 'tire_pressure' ? ' open' : '' ?>><summary>Record tire pressure</summary>
            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/tire-pressure" method="post"><?= csrf_field() ?>
                <div class="health-wheel-fields">
                    <?php foreach (['lf_psi' => 'LF', 'rf_psi' => 'RF', 'lr_psi' => 'LR', 'rr_psi' => 'RR'] as $field => $label): ?><label><?= esc($label) ?> PSI<input name="<?= esc($field, 'attr') ?>" required inputmode="numeric" type="number" step="1" min="1" max="200" value="<?= esc($input($field), 'attr') ?>"></label><?php endforeach; ?>
                    <label>Recommended PSI<input name="recommended_psi" required inputmode="numeric" type="number" step="1" min="1" max="200" value="<?= esc($input('recommended_psi', $policy['recommended_psi'] ?? ''), 'attr') ?>"></label>
                    <label>Observed at<input name="observed_at" required type="datetime-local" value="<?= esc($input('observed_at', $defaultObservedAt), 'attr') ?>"></label>
                    <label class="health-wide">Note optional<textarea name="note" rows="2"><?= esc($input('note')) ?></textarea></label>
                </div>
                <?php if ($policy !== null): ?><p class="muted">Acceptable range: <?= esc($psiRange($policy['acceptable_min_psi'], $policy['acceptable_max_psi'])) ?></p><?php else: ?><p class="muted">No acceptable range will be inferred until a policy is configured.</p><?php endif; ?>
                <div class="form-actions"><button class="primary-action" type="submit">Record tire pressure</button></div>
            </form>
        </details>
        <details class="capital-disclosure" id="record-odometer"<?= $form === 'odometer' ? ' open' : '' ?>><summary>Record odometer</summary>
            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/odometer" method="post"><?= csrf_field() ?><div class="issue-filters">
                <label>Odometer miles<input name="odometer_miles" required inputmode="numeric" type="number" min="0" step="1" value="<?= esc($input('odometer_miles'), 'attr') ?>"></label>
                <label>Observed at<input name="observed_at" required type="datetime-local" value="<?= esc($input('observed_at', $defaultObservedAt), 'attr') ?>"></label>
                <label>Note optional<textarea name="note" rows="2"><?= esc($input('note')) ?></textarea></label>
            </div><div class="form-actions"><button class="primary-action" type="submit">Record odometer</button></div></form>
        </details>
        <details class="capital-disclosure" id="tire-pressure-policy"<?= $form === 'policy' ? ' open' : '' ?>><summary>Configure tire-pressure policy</summary>
            <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/tire-pressure-policy" method="post"><?= csrf_field() ?><div class="issue-filters">
                <label>Check interval days<input name="interval_value" required inputmode="numeric" type="number" min="1" step="1" value="<?= esc($input('interval_value', $policy['interval_value'] ?? 30), 'attr') ?>"></label>
                <label>Recommended PSI<input name="recommended_psi" required inputmode="numeric" type="number" min="1" max="200" step="1" value="<?= esc($input('recommended_psi', $policy['recommended_psi'] ?? ''), 'attr') ?>"></label>
                <label>Acceptable minimum<input name="acceptable_min_psi" required inputmode="numeric" type="number" min="1" max="200" step="1" value="<?= esc($input('acceptable_min_psi', $policy['acceptable_min_psi'] ?? ''), 'attr') ?>"></label>
                <label>Acceptable maximum<input name="acceptable_max_psi" required inputmode="numeric" type="number" min="1" max="200" step="1" value="<?= esc($input('acceptable_max_psi', $policy['acceptable_max_psi'] ?? ''), 'attr') ?>"></label>
                <label>Safety minimum optional<input name="safety_min_psi" inputmode="numeric" type="number" min="1" max="200" step="1" value="<?= esc($input('safety_min_psi', $policy['safety_min_psi'] ?? ''), 'attr') ?>"></label>
                <label>Safety maximum optional<input name="safety_max_psi" inputmode="numeric" type="number" min="1" max="200" step="1" value="<?= esc($input('safety_max_psi', $policy['safety_max_psi'] ?? ''), 'attr') ?>"></label>
                <label class="readiness-check"><input name="is_enabled" type="checkbox" value="1"<?= $input('is_enabled', $policy === null || (bool) $policy['is_enabled'] ? '1' : '') !== '' ? ' checked' : '' ?>><span>Enable tire-pressure reminders</span></label>
            </div><div class="form-actions"><button class="primary-action" type="submit">Save policy</button></div></form>
            <?php if ($policy !== null && (bool) $policy['is_enabled']): ?><form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/tire-pressure-policy/disable" method="post" class="secondary-form"><?= csrf_field() ?><button class="secondary-action" type="submit">Disable policy</button></form><?php endif; ?>
        </details>
    </div>

    <details class="capital-disclosure health-history"><summary>Observation history · <?= count($history) ?></summary>
        <?php if ($history === []): ?><p class="empty-state">No vehicle-health observations recorded.</p><?php endif; ?>
        <div class="alert-stack"><?php foreach ($history as $row): $rowId = (int) $row['id'];
            $inactive = $row['voided_at'] !== null || isset($supersededIds[$rowId]); ?>
            <article class="operational-card" id="health-observation-<?= $rowId ?>"><div class="split-heading"><div><strong><?= $row['observation_code'] === 'odometer' ? number_format((int) $row['odometer_miles']) . ' mi' : esc($psi($row['lf_psi'])) . ' / ' . esc($psi($row['rf_psi'])) . ' / ' . esc($psi($row['lr_psi'])) . ' / ' . esc($psi($row['rr_psi'])) ?></strong><p class="status-copy"><?= esc($dateTime($row['observed_at'])) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $row['source']))) ?></p></div><span class="status-badge <?= $inactive ? 'tone-neutral' : 'tone-success' ?>"><?= $inactive ? 'Historical' : 'Active' ?></span></div>
            <?php if ($row['voided_at'] !== null): ?><p class="muted">Voided <?= esc($dateTime($row['voided_at'])) ?><?= trim((string) $row['void_reason']) === '' ? '' : ' · ' . esc((string) $row['void_reason']) ?></p><?php elseif (isset($supersededIds[$rowId])): ?><p class="muted">Superseded by a corrected observation.</p><?php endif; ?>
            <?php if (! $inactive): ?><details class="secondary-disclosure"<?= $form === 'correction_' . $rowId || $form === 'void_' . $rowId ? ' open' : '' ?>><summary>Correct or void</summary>
                <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/observations/<?= $rowId ?>/correct" method="post"><?= csrf_field() ?><div class="health-wheel-fields">
                    <?php if ($row['observation_code'] === 'tire_pressure'): ?><?php foreach (['lf_psi' => 'LF', 'rf_psi' => 'RF', 'lr_psi' => 'LR', 'rr_psi' => 'RR', 'recommended_psi' => 'Recommended'] as $field => $label): ?><label><?= esc($label) ?> PSI<input name="<?= esc($field, 'attr') ?>" required type="number" inputmode="numeric" min="1" max="200" step="1" value="<?= (int) $row[$field] ?>"></label><?php endforeach; ?><?php else: ?><label>Corrected odometer<input name="odometer_miles" required type="number" inputmode="numeric" min="0" step="1" value="<?= (int) $row['odometer_miles'] ?>"></label><?php endif; ?>
                    <label>Observed at<input name="observed_at" required type="datetime-local" value="<?= esc(date('Y-m-d\TH:i', strtotime((string) $row['observed_at'])), 'attr') ?>"></label><label class="health-wide">Correction reason<input name="correction_reason" required maxlength="500"></label><label class="health-wide">Note optional<textarea name="note" rows="2"><?= esc((string) ($row['note'] ?? '')) ?></textarea></label>
                </div><div class="form-actions"><button class="secondary-action" type="submit">Save correction</button></div></form>
                <form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/health/observations/<?= $rowId ?>/void" method="post" class="health-void-form"><?= csrf_field() ?><label>Void reason<input name="void_reason" required maxlength="500"></label><button class="secondary-action" type="submit">Void observation</button></form>
            </details><?php endif; ?></article>
        <?php endforeach; ?></div>
    </details>
</section>
