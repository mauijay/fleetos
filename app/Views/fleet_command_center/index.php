<?php
/** @var array<string, mixed> $commandCenter */
/** @var array{css: ?string, js: ?string} $assets */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($commandCenter['page_title']) ?> | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-frame command-center-frame">
        <?= view('fleet_command_center/components/navigation', ['items' => $commandCenter['navigation']]) ?>

        <main id="main-content" class="command-main" tabindex="-1">
            <header class="top-status" aria-label="Fleet status bar">
                <div>
                    <p class="eyebrow">FleetOS Mission Control</p>
                    <h1>Fleet Command Center</h1>
                    <p class="status-copy">Operational picture as of <?= esc($commandCenter['as_of']) ?></p>
                </div>
                <div class="status-cluster" aria-label="Future integrations">
                    <span>Tesla API</span>
                    <span>Weather</span>
                    <span>Traffic</span>
                </div>
            </header>

            <section class="section briefing-card" id="morning-briefing" aria-labelledby="briefing-heading">
                <p class="eyebrow">Morning Briefing</p>
                <h2 id="briefing-heading"><?= esc($commandCenter['daily_operations']['briefing']['greeting']) ?></h2>
                <p class="briefing-copy"><?= esc($commandCenter['daily_operations']['briefing']['message']) ?></p>
            </section>

            <section class="section fleet-timeline" id="fleet-timeline" aria-labelledby="fleet-timeline-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Next Moves</p>
                        <h2 id="fleet-timeline-heading">Fleet Timeline</h2>
                    </div>
                    <span class="count-pill"><?= esc((string) $commandCenter['timeline']['count']) ?> upcoming</span>
                </div>
                <?= view('fleet_command_center/components/fleet_timeline', ['timeline' => $commandCenter['timeline']]) ?>
            </section>

            <section class="section" id="movement-board" aria-labelledby="movement-board-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Daily Operations</p>
                        <h2 id="movement-board-heading">Vehicle Movement Board</h2>
                    </div>
                    <span class="count-pill"><?= esc((string) count($commandCenter['daily_operations']['movement_board'])) ?> vehicles</span>
                </div>
                <?php if ($commandCenter['daily_operations']['movement_filter']['active'] !== null): ?>
                    <div class="movement-filter-status" role="status">
                        <span>Showing <?= esc($commandCenter['daily_operations']['movement_filter']['label']) ?>: <?= esc((string) $commandCenter['daily_operations']['movement_filter']['visible_count']) ?> of <?= esc((string) $commandCenter['daily_operations']['movement_filter']['total_count']) ?> vehicles</span>
                        <a href="<?= esc($commandCenter['daily_operations']['movement_filter']['clear_href'], 'attr') ?>">Clear filter</a>
                    </div>
                <?php endif; ?>
                <?php if ($commandCenter['daily_operations']['movement_board'] === []): ?>
                    <div class="empty-state">No vehicles match this movement queue.</div>
                <?php else: ?>
                    <div class="movement-board-grid">
                        <?php foreach ($commandCenter['daily_operations']['movement_board'] as $vehicle): ?>
                            <?= view('fleet_command_center/components/movement_card', ['vehicle' => $vehicle]) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="section" id="immediate-attention" aria-labelledby="attention-heading">
                <div class="section-heading">
                    <p class="eyebrow">Action Required</p>
                    <h2 id="attention-heading">Immediate Attention</h2>
                </div>
                <div class="alert-stack">
                    <?php if ($commandCenter['daily_operations']['attention'] === []): ?>
                        <div class="empty-state">Everything looks good. No immediate operational issues require attention.</div>
                    <?php endif; ?>
                    <?php foreach ($commandCenter['daily_operations']['attention'] as $attention): ?>
                        <a class="alert-card tone-<?= $attention['severity'] === 'critical' ? 'danger' : 'warning' ?>" href="<?= esc($attention['href'], 'attr') ?>">
                            <div>
                                <h3><?= esc($attention['label']) ?></h3>
                                <p><?= esc($attention['detail']) ?></p>
                            </div>
                            <span class="count-pill"><?= esc(ucfirst($attention['severity'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="operations-status" aria-labelledby="operations-status-heading">
                <div class="section-heading">
                    <p class="eyebrow">Daily Operations</p>
                    <h2 id="operations-status-heading">Daily Counts</h2>
                </div>
                <?php $dailyCounts = [
                    'going_out_today' => ['label' => 'Going Out Today', 'period' => 'Today'],
                    'returning_today' => ['label' => 'Returning Today', 'period' => 'Today'],
                    'same_day_turnarounds' => ['label' => 'Same-Day Turnarounds', 'period' => 'Today'],
                    'cleaning_needed' => ['label' => 'Cleaning Needed', 'period' => 'Current action'],
                    'charging_needed' => ['label' => 'Charging Needed', 'period' => 'Current action'],
                    'maintenance_attention' => ['label' => 'Maintenance Attention', 'period' => 'Current action'],
                    'utilization_percent' => ['label' => 'Month-to-date utilization', 'period' => 'Current month'],
                ]; ?>
                <div class="metric-grid status-grid">
                    <?php foreach ($dailyCounts as $code => $metric): ?>
                        <?php if (! array_key_exists($code, $commandCenter['daily_operations']['fleet_status'])) {
                            continue;
                        } ?>
                        <a class="metric-card tone-neutral" href="#movement-board">
                            <span><?= esc($metric['label']) ?></span>
                            <strong><?= esc((string) $commandCenter['daily_operations']['fleet_status'][$code]) ?><?= $code === 'utilization_percent' ? '%' : '' ?></strong>
                            <small><?= esc($metric['period']) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="operational-queue" aria-labelledby="queue-heading">
                <div class="section-heading">
                    <p class="eyebrow">Direct Actions</p>
                    <h2 id="queue-heading">Operational Queue</h2>
                </div>
                <?= view('fleet_command_center/components/operational_queue', ['queueView' => $commandCenter['daily_operations']['queue_view']]) ?>
            </section>

            <section class="section" id="data-honesty" aria-labelledby="data-honesty-heading">
                <div class="section-heading">
                    <p class="eyebrow">Data Honesty</p>
                    <h2 id="data-honesty-heading">Not Yet Captured Reliably</h2>
                </div>
                <ul class="issue-list">
                    <?php foreach ($commandCenter['daily_operations']['data_honesty'] as $note): ?>
                        <li><?= esc($note) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="section" id="fleet-status" aria-labelledby="fleet-status-heading">
                <div class="section-heading">
                    <p class="eyebrow">Live Operations</p>
                    <h2 id="fleet-status-heading">Fleet Status</h2>
                </div>
                <div class="metric-grid status-grid">
                    <?php foreach ($commandCenter['fleet_status'] as $card): ?>
                        <?= view('fleet_command_center/components/metric_card', ['card' => $card]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section mission-section" id="todays-mission" aria-labelledby="mission-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Today</p>
                        <h2 id="mission-heading">Today's Mission</h2>
                    </div>
                    <?php if ($commandCenter['mission_clear']): ?>
                        <p class="mission-clear">All clear. No operational tasks are due today.</p>
                    <?php endif; ?>
                </div>
                <div class="mission-grid">
                    <?php foreach ($commandCenter['mission'] as $task): ?>
                        <?= view('fleet_command_center/components/task_card', ['task' => $task]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($commandCenter['import_issues']['has_unresolved']): ?>
                <section class="section import-attention tone-warning" aria-labelledby="import-issues-heading">
                    <div>
                        <p class="eyebrow">Import Attention</p>
                        <h2 id="import-issues-heading">Turo Import Issues Need Review</h2>
                        <p class="status-copy"><?= esc((string) $commandCenter['import_issues']['unresolved_errors']) ?> unresolved errors and <?= esc((string) $commandCenter['import_issues']['unresolved_warnings']) ?> unresolved warnings are waiting.</p>
                    </div>
                    <a class="action-link" href="<?= esc($commandCenter['import_issues']['href'], 'attr') ?>">Review issues</a>
                </section>
            <?php endif; ?>

            <?php if ($commandCenter['vehicle_mappings']['has_unmatched']): ?>
                <section class="section import-attention tone-warning" aria-labelledby="vehicle-mappings-heading">
                    <div>
                        <p class="eyebrow">Vehicle Matching</p>
                        <h2 id="vehicle-mappings-heading">Turo Vehicles Need Mapping</h2>
                        <p class="status-copy"><?= esc((string) $commandCenter['vehicle_mappings']['unique_unmatched_vehicles']) ?> unique Turo vehicles affect <?= esc((string) $commandCenter['vehicle_mappings']['affected_issues']) ?> unresolved trip row(s).</p>
                    </div>
                    <a class="action-link" href="<?= esc($commandCenter['vehicle_mappings']['href'], 'attr') ?>">Map vehicles</a>
                </section>
            <?php endif; ?>

            <?php if ($commandCenter['trip_reconciliation']['has_reconciliation_work']): ?>
                <section class="section import-attention tone-warning" aria-labelledby="trip-reconciliation-heading">
                    <div>
                        <p class="eyebrow">Reconciliation</p>
                        <h2 id="trip-reconciliation-heading">Mapped Rows Need Reprocessing</h2>
                        <p class="status-copy"><?= esc((string) $commandCenter['trip_reconciliation']['awaiting_reconciliation']) ?> previously unmatched row(s) can now be reviewed for reconciliation.</p>
                    </div>
                    <a class="action-link" href="<?= esc($commandCenter['trip_reconciliation']['href'], 'attr') ?>">Open queue</a>
                </section>
            <?php endif; ?>

            <section class="section" id="decision-support" aria-labelledby="decision-support-heading">
                <div class="section-heading">
                    <p class="eyebrow">Decision Support</p>
                    <h2 id="decision-support-heading">Recommendations</h2>
                </div>
                <?= view('fleet_command_center/components/recommendations_panel', ['decisionSupport' => $commandCenter['decision_support']]) ?>
            </section>

            <section class="section" id="fleet-activity" aria-labelledby="fleet-activity-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Fleet Vehicles</p>
                        <h2 id="fleet-activity-heading">Fleet Activity</h2>
                    </div>
                    <a class="text-link" href="#fleet-timeline">Open timeline</a>
                </div>
                <div class="vehicle-grid">
                    <?php foreach ($commandCenter['vehicles'] as $vehicle): ?>
                        <?= view('fleet_command_center/components/vehicle_card', ['vehicle' => $vehicle]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="financial-snapshot" aria-labelledby="financial-heading">
                <div class="section-heading split-heading">
                    <div>
                        <p class="eyebrow">Owner View</p>
                        <h2 id="financial-heading">Financial Snapshot</h2>
                    </div>
                    <span><a class="text-link" href="/reports/vehicle-performance">View vehicle performance</a> · <a class="text-link" href="/reports/vehicle-financial-results">View vehicle financial results</a></span>
                </div>
                <div class="financial-grid">
                    <?php foreach ($commandCenter['financial'] as $card): ?>
                        <?= view('fleet_command_center/components/financial_card', ['card' => $card]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="fleet-health" aria-labelledby="health-heading">
                <div class="section-heading">
                    <p class="eyebrow">Warnings Only</p>
                    <h2 id="health-heading">Fleet Health</h2>
                </div>
                <div class="alert-stack">
                    <?php if ($commandCenter['health_alerts'] === []): ?>
                        <div class="empty-state">No active fleet health warnings.</div>
                    <?php endif; ?>
                    <?php foreach ($commandCenter['health_alerts'] as $alert): ?>
                        <?= view('fleet_command_center/components/alert_card', ['alert' => $alert]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="executive-kpis" aria-labelledby="kpi-heading">
                <div class="section-heading">
                    <p class="eyebrow">Executive KPIs</p>
                    <h2 id="kpi-heading">Performance</h2>
                </div>
                <div class="metric-grid kpi-grid">
                    <?php foreach ($commandCenter['executive_kpis'] as $card): ?>
                        <?= view('fleet_command_center/components/metric_card', ['card' => $card]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section" id="future-integrations" aria-labelledby="integrations-heading">
                <div class="section-heading">
                    <p class="eyebrow">Reserved Space</p>
                    <h2 id="integrations-heading">Future Integrations</h2>
                </div>
                <div class="integration-grid">
                    <?php foreach ($commandCenter['future_integrations'] as $integration): ?>
                        <div class="integration-chip">
                            <span><?= esc($integration['name']) ?></span>
                            <strong><?= esc($integration['status']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?= view('fleet_command_center/components/footer') ?>
        </main>

        <?= view('fleet_command_center/components/activity_panel', ['activity' => $commandCenter['activity']]) ?>
    </div>

    <?php if ($assets['js'] !== null): ?>
        <script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script>
    <?php endif; ?>
</body>
</html>
