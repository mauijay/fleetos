<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Incidentals Review · FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-frame import-frame incidentals-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main incidentals-main" tabindex="-1">
        <header class="top-status incidentals-header">
            <div>
                <p class="eyebrow">Invoice follow-up</p>
                <h1>Incidentals Review</h1>
                <p class="status-copy">Return to completed Turo trips after delayed incidentals have populated. FleetOS tracks the reminder and deadline, not charges or reimbursement accounting.</p>
            </div>
        </header>

        <?php if ($notice): ?><div class="notice success" role="status"><?= esc($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= esc($error) ?></div><?php endif; ?>

        <section class="section incidentals-queue" aria-labelledby="reviews-title">
            <div class="section-heading incidentals-queue-heading">
                <div>
                    <p class="eyebrow">Action queue</p>
                    <h2 id="reviews-title">Completed trip follow-up</h2>
                </div>
                <div class="incidental-summary" aria-label="Queue summary">
                    <span><span>Ready</span><strong><?= (int) $queue['summary']['ready'] ?></strong></span>
                    <span><span>Due soon</span><strong><?= (int) $queue['summary']['due_soon'] ?></strong></span>
                    <span><span>Overdue</span><strong><?= (int) $queue['summary']['overdue'] ?></strong></span>
                </div>
            </div>

            <nav class="queue-tabs" aria-label="Incidental review filters">
                <?php foreach (['action' => 'Action needed', 'waiting' => 'Waiting', 'needs_plan' => 'Plan needed', 'overdue' => 'Overdue', 'complete' => 'Complete', 'all' => 'All'] as $code => $label): ?>
                    <a href="/operations/incidentals?filter=<?= esc($code, 'attr') ?>" class="<?= $queue['filter'] === $code ? 'is-active' : '' ?>"<?= $queue['filter'] === $code ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($queue['reviews'] === []): ?>
                <div class="empty-state">No trip incidental reviews match this view.</div>
            <?php else: ?>
                <div class="incidental-list">
                    <?php foreach ($queue['reviews'] as $item): ?>
                        <?php
                        $local = static fn (?string $value): string => $value
                            ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('M j, g:i A')
                            : 'Unknown';
                        $urgencyCode = (string) $item['urgency']['code'];
                        $hoursRemaining = $item['urgency']['hours_remaining'];
                        $statusLabel = ucwords(str_replace('_', ' ', (string) $item['display_status']));
                        $planLabel = $item['earnings_plan_code_snapshot']
                            ? ucwords(str_replace('_', ' ', (string) $item['earnings_plan_code_snapshot']))
                            : 'Unknown — confirm plan';
                        $urgencyDetail = match ($urgencyCode) {
                            'waiting' => 'Review after ' . $local($item['review_after_at_utc']) . ' HST',
                            'overdue_unconfirmed' => number_format(abs((float) $hoursRemaining), 1) . ' hours past deadline',
                            'due_soon', 'due' => $hoursRemaining !== null
                                ? number_format((float) $hoursRemaining, 1) . ' hours remaining'
                                : 'Confirm the trip plan to establish its deadline',
                            'complete' => 'Follow-up closed',
                            default => '',
                        };
                        ?>
                        <article class="incidental-card urgency-<?= esc($urgencyCode, 'attr') ?>">
                            <header class="incidental-card-heading">
                                <div class="incidental-identity">
                                    <p class="incidental-card-kicker">Vehicle</p>
                                    <h3><?= esc($item['vehicle_name'] ?: $item['fleet_code']) ?></h3>
                                    <p class="incidental-trip-identity">
                                        <strong><?= esc($item['guest_name'] ?: 'Guest unavailable') ?></strong>
                                        <span>Trip <?= esc($item['turo_trip_id'] ?: $item['turo_reservation_id']) ?></span>
                                    </p>
                                </div>
                                <div class="incidental-current-state">
                                    <span class="status-badge status-<?= esc((string) $item['display_status'], 'attr') ?>"><?= esc($statusLabel) ?></span>
                                    <strong><?= esc($item['urgency']['label']) ?></strong>
                                    <?php if ($urgencyDetail !== ''): ?><span><?= esc($urgencyDetail) ?></span><?php endif; ?>
                                </div>
                            </header>

                            <div class="incidental-card-context">
                                <section class="incidental-timing" aria-label="Trip and review timing">
                                    <h4>Timing</h4>
                                    <dl class="incidental-facts">
                                        <div>
                                            <dt>Trip ended</dt>
                                            <dd><?= esc($local($item['trip_ended_at_utc'])) ?> HST</dd>
                                        </div>
                                        <div>
                                            <dt>Review Turo after</dt>
                                            <dd><?= esc($local($item['review_after_at_utc'])) ?> HST</dd>
                                        </div>
                                        <div>
                                            <dt>Invoice deadline</dt>
                                            <dd><?= esc($local($item['filing_deadline_at_utc'])) ?><?= $item['filing_deadline_at_utc'] ? ' HST' : '' ?></dd>
                                        </div>
                                    </dl>
                                </section>
                                <aside class="incidental-policy-summary" aria-label="Trip policy">
                                    <h4>Policy</h4>
                                    <strong><?= esc($planLabel) ?></strong>
                                    <span><?= esc($item['earnings_plan_code_snapshot'] === null ? 'Automatic plan snapshot unavailable' : ($item['plan_source_reference'] ?: 'Manual trip-level snapshot')) ?></span>
                                </aside>
                            </div>

                            <?php if ($item['earnings_plan_code_snapshot'] === null && $urgencyCode !== 'complete'): ?>
                                <section class="incidental-action-panel incidental-plan-panel" aria-labelledby="plan-heading-<?= (int) $item['id'] ?>">
                                    <div class="incidental-action-heading">
                                        <div>
                                            <p class="eyebrow">Exception · Plan needed</p>
                                            <h4 id="plan-heading-<?= (int) $item['id'] ?>">Resolve this trip’s earnings plan</h4>
                                        </div>
                                        <p>FleetOS could not create an automatic plan and deadline snapshot for this booked time. Confirm only after checking the trip in Turo.</p>
                                    </div>
                                    <form class="incidental-plan-form" method="post" action="/operations/incidentals/<?= (int) $item['id'] ?>/plan">
                                        <?= csrf_field() ?>
                                        <div class="incidental-plan-fields">
                                            <label>
                                                <span>Trip earnings plan</span>
                                                <select name="earnings_plan_code" required>
                                                    <option value="">Select confirmed plan</option>
                                                    <?php foreach ($queue['plan_options'] as $planCode => $displayName): ?>
                                                        <option value="<?= esc($planCode, 'attr') ?>"><?= esc($displayName) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span>Source/reference <small>(optional)</small></span>
                                                <input name="source_reference" maxlength="190" placeholder="Turo trip or reservation">
                                            </label>
                                            <label class="incidental-reason-field">
                                                <span>Reason</span>
                                                <input name="selection_reason" maxlength="500" required placeholder="How the trip-specific plan was verified">
                                            </label>
                                        </div>
                                        <button class="primary-action" type="submit">Confirm plan</button>
                                    </form>
                                </section>
                            <?php elseif ($urgencyCode !== 'complete'): ?>
                                <details class="incidental-plan-correction">
                                    <summary>Correct trip plan <span>Exception only</span></summary>
                                    <form class="incidental-plan-form" method="post" action="/operations/incidentals/<?= (int) $item['id'] ?>/plan">
                                        <?= csrf_field() ?>
                                        <div class="incidental-plan-fields">
                                            <label>
                                                <span>Correct earnings plan</span>
                                                <select name="earnings_plan_code" required>
                                                    <?php foreach ($queue['plan_options'] as $planCode => $displayName): ?>
                                                        <option value="<?= esc($planCode, 'attr') ?>"<?= $item['earnings_plan_code_snapshot'] === $planCode ? ' selected' : '' ?>><?= esc($displayName) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span>Source/reference <small>(optional)</small></span>
                                                <input name="source_reference" maxlength="190" placeholder="Turo trip or reservation">
                                            </label>
                                            <label class="incidental-reason-field">
                                                <span>Correction reason</span>
                                                <input name="selection_reason" maxlength="500" required placeholder="Why the existing trip snapshot is incorrect">
                                            </label>
                                        </div>
                                        <button class="secondary-action" type="submit">Save trip exception</button>
                                    </form>
                                </details>
                            <?php endif; ?>

                            <?php if (! in_array($urgencyCode, ['waiting', 'complete'], true)): ?>
                                <section class="incidental-action-panel incidental-completion-panel" aria-labelledby="completion-heading-<?= (int) $item['id'] ?>">
                                    <div class="incidental-action-heading">
                                        <div>
                                            <p class="eyebrow">Complete follow-up</p>
                                            <h4 id="completion-heading-<?= (int) $item['id'] ?>">Record the Turo review outcome</h4>
                                        </div>
                                    </div>
                                    <div class="incidental-actions">
                                        <form class="incidental-completion-form is-primary" method="post" action="/operations/incidentals/<?= (int) $item['id'] ?>/invoice-sent">
                                            <?= csrf_field() ?>
                                            <label>
                                                <span>Invoice reference <small>(optional)</small></span>
                                                <input name="reference" maxlength="190" placeholder="Turo reference">
                                            </label>
                                            <button class="primary-action" type="submit">Invoice sent</button>
                                        </form>
                                        <form class="incidental-completion-form" method="post" action="/operations/incidentals/<?= (int) $item['id'] ?>/no-invoice-needed">
                                            <?= csrf_field() ?>
                                            <label>
                                                <span>Completion note <small>(optional)</small></span>
                                                <input name="note" maxlength="500" placeholder="Why no invoice is needed">
                                            </label>
                                            <button class="secondary-action" type="submit">No invoice needed</button>
                                        </form>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="section incidentals-policy-section" id="policy-setup" aria-labelledby="policy-title">
            <div class="section-heading incidentals-policy-heading">
                <div>
                    <p class="eyebrow">Policy setup</p>
                    <h2 id="policy-title">Earnings plan resolution</h2>
                </div>
                <p>Assignments are append-only and resolved at each trip’s booked time. Existing trip snapshots never change when a later fleet or vehicle assignment is added.</p>
            </div>

            <section class="earnings-assignment-setup" aria-labelledby="assignment-title">
                <div class="assignment-setup-heading">
                    <div>
                        <p class="eyebrow">Automatic resolution</p>
                        <h3 id="assignment-title">Fleet default and vehicle overrides</h3>
                    </div>
                    <p>Trips without a known booked time—or booked before the applicable assignment—remain Plan needed.</p>
                </div>
                <div class="assignment-form-grid">
                    <form class="earnings-assignment-form" method="post" action="/operations/incidentals/assignments">
                        <?= csrf_field() ?>
                        <input type="hidden" name="scope" value="fleet">
                        <div class="assignment-form-heading">
                            <span class="status-badge">Fleet default</span>
                            <h4>Fleet default plan</h4>
                        </div>
                        <label>
                            <span>Fleet default plan</span>
                            <select name="earnings_plan_code" required>
                                <option value="">Select plan</option>
                                <?php foreach ($queue['plan_options'] as $planCode => $displayName): ?>
                                    <option value="<?= esc($planCode, 'attr') ?>"><?= esc($displayName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Effective from <small>(Honolulu time)</small></span>
                            <input type="datetime-local" name="effective_from" required>
                        </label>
                        <input type="hidden" name="assignment_reason" value="Operator configured the fleet default earnings plan in FleetOS.">
                        <button class="primary-action" type="submit">Add fleet default</button>
                    </form>

                    <form class="earnings-assignment-form" method="post" action="/operations/incidentals/assignments">
                        <?= csrf_field() ?>
                        <input type="hidden" name="scope" value="vehicle">
                        <div class="assignment-form-heading">
                            <span class="status-badge">Optional</span>
                            <h4>Vehicle override</h4>
                        </div>
                        <label>
                            <span>Vehicle</span>
                            <select name="fleet_vehicle_id" required>
                                <option value="">Select vehicle</option>
                                <?php foreach ($queue['vehicles'] as $vehicle): ?>
                                    <option value="<?= (int) $vehicle['id'] ?>"><?= esc($vehicle['display_name'] ?: $vehicle['fleet_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Override plan</span>
                            <select name="earnings_plan_code" required>
                                <option value="">Select plan</option>
                                <?php foreach ($queue['plan_options'] as $planCode => $displayName): ?>
                                    <option value="<?= esc($planCode, 'attr') ?>"><?= esc($displayName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Effective from <small>(Honolulu time)</small></span>
                            <input type="datetime-local" name="effective_from" required>
                        </label>
                        <input type="hidden" name="assignment_reason" value="Operator configured a vehicle-specific earnings plan override in FleetOS.">
                        <button class="secondary-action" type="submit">Add vehicle override</button>
                    </form>
                </div>

                <div class="assignment-history">
                    <div class="assignment-history-heading">
                        <h4>Assignment history</h4>
                        <span><?= count($queue['assignments']) ?> record<?= count($queue['assignments']) === 1 ? '' : 's' ?></span>
                    </div>
                    <?php if ($queue['assignments'] === []): ?>
                        <p class="assignment-empty">No fleet default or vehicle override has been configured. Trips remain Plan needed until an effective assignment covers their booked time.</p>
                    <?php else: ?>
                        <div class="assignment-history-list">
                            <?php foreach ($queue['assignments'] as $assignment): ?>
                                <?php $effectiveLocal = (new DateTimeImmutable((string) $assignment['effective_from_at_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('M j, Y · g:i A'); ?>
                                <article class="assignment-record">
                                    <div>
                                        <span><?= $assignment['fleet_vehicle_id'] === null ? 'Fleet default' : 'Vehicle override' ?></span>
                                        <strong><?= esc($assignment['fleet_vehicle_id'] === null ? 'All fleet vehicles' : ($assignment['vehicle_name'] ?: $assignment['fleet_code'])) ?></strong>
                                    </div>
                                    <div>
                                        <span>Plan</span>
                                        <strong><?= esc($queue['plan_options'][$assignment['earnings_plan_code']] ?? ucwords(str_replace('_', ' ', (string) $assignment['earnings_plan_code']))) ?></strong>
                                    </div>
                                    <div>
                                        <span>Effective from</span>
                                        <strong><?= esc($effectiveLocal) ?> HST</strong>
                                    </div>
                                    <p><?= esc($assignment['assignment_reason']) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="policy-window-heading">
                <div>
                    <p class="eyebrow">Deadline policy</p>
                    <h3>Plan filing windows</h3>
                </div>
                <p>Approving a source-backed version makes its 72, 96, or 120-hour window available for immutable trip snapshots.</p>
            </div>
            <div class="policy-grid">
                <?php foreach ($queue['policies'] as $policy): ?>
                    <article class="policy-card policy-<?= esc((string) $policy['status'], 'attr') ?>">
                        <header class="policy-card-heading">
                            <div>
                                <p class="policy-plan-code"><?= esc(str_replace('_', ' ', (string) $policy['earnings_plan_code'])) ?></p>
                                <h3><?= esc($policy['display_name']) ?></h3>
                            </div>
                            <span class="status-badge policy-status-<?= esc((string) $policy['status'], 'attr') ?>"><?= esc(ucfirst($policy['status'])) ?></span>
                        </header>
                        <p class="policy-duration"><strong><?= number_format((int) $policy['filing_window_minutes'] / 60) ?></strong><span>hours from final trip end</span></p>
                        <p class="policy-version">Rule version <?= esc($policy['rule_version']) ?></p>
                        <p class="policy-source"><strong>Source:</strong> <?= esc($policy['source_reference']) ?>. <?= esc($policy['source_note']) ?></p>
                        <?php if ($policy['status'] === 'draft'): ?>
                            <form class="policy-approval-form" method="post" action="/operations/incidentals/policies/<?= (int) $policy['id'] ?>/approve">
                                <?= csrf_field() ?>
                                <label>
                                    <span>Approval rationale</span>
                                    <textarea name="approval_rationale" rows="2" required>Verified against current US host earnings-plan incidental filing terms.</textarea>
                                </label>
                                <button class="secondary-action" type="submit">Approve policy</button>
                            </form>
                        <?php else: ?>
                            <p class="policy-approved-note"><strong>Approved:</strong> <?= esc($policy['approval_rationale']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
