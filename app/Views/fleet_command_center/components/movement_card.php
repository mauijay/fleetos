<?php
/** @var array<string, mixed> $vehicle */
$state = $vehicle['state'];
$currentTrip = $vehicle['current_trip'] ?? null;
$currentMovementHref = $vehicle['current_movement_href'] ?? null;
$nextTrip = $vehicle['next_trip'];
$recommendation = $vehicle['recommendation'];
$operatorPlan = $vehicle['operator_plan'];
$freshness = $vehicle['freshness'];
$readiness = $vehicle['readiness_compact'] ?? [
    'blocking_count' => (int) ($vehicle['readiness_display_remaining'] ?? 0),
    'additional_count' => (int) ($vehicle['readiness_additional_remaining'] ?? 0),
    'summary' => (string) ($vehicle['readiness_summary'] ?? 'Readiness not available'),
    'next_actions' => [],
];
$statusLabel = ($state['code'] ?? null) === 'on_trip' ? 'Rented' : (string) $state['label'];
?>
<article class="movement-card movement-card--structured tone-<?= esc($state['tone'], 'attr') ?>">
    <header class="movement-card__header">
        <div class="movement-card__identity">
            <h3><?= esc($vehicle['fleet_code']) ?></h3>
            <p><?= esc($vehicle['model'] === '' ? 'Model not captured' : $vehicle['model']) ?></p>
        </div>
        <span class="status-badge tone-<?= esc($state['tone'], 'attr') ?>"><?= esc($statusLabel) ?></span>
    </header>

    <div class="movement-card__commitment">
        <p class="movement-card__primary"><?= esc($vehicle['primary_line']) ?></p>
        <?php if ($currentTrip !== null): ?>
            <?php $currentTripTag = $currentMovementHref === null ? 'p' : 'a'; ?>
            <<?= $currentTripTag ?> class="movement-card__current-trip<?= $currentMovementHref === null ? '' : ' movement-card__current-trip-link' ?>"<?= $currentMovementHref === null ? ' aria-label="Current reservation"' : ' href="' . esc((string) $currentMovementHref, 'attr') . '" aria-label="Open movement for ' . esc((string) $currentTrip['guest_name'], 'attr') . '"' ?>>
                <strong><?= esc($currentTrip['guest_name']) ?></strong>
                <?php if ($currentTrip['timing_label'] !== null): ?>
                    <span><?= esc($currentTrip['timing_label']) ?></span>
                <?php endif; ?>
                <?php if ($currentMovementHref !== null): ?><span class="movement-card__movement-link">Open movement</span><?php endif; ?>
            </<?= $currentTripTag ?>>
        <?php endif; ?>
    </div>

    <?php $guestCommitments = $vehicle['guest_commitments'] ?? ['count' => 0, 'preview' => [], 'required' => false, 'href' => null]; ?>
    <?php if ((int) $guestCommitments['count'] > 0): ?>
        <section class="movement-card__guest-commitments<?= $guestCommitments['required'] ? ' is-required' : '' ?>" aria-label="Guest commitments">
            <div class="movement-card__subheading"><h4>Special instructions · <?= (int) $guestCommitments['count'] ?></h4><?php if ($guestCommitments['required']): ?><span>Guest setup required</span><?php endif; ?></div>
            <ul><?php foreach ($guestCommitments['preview'] as $commitment): ?><li><?= esc((string) $commitment['instruction']) ?><?php if ($commitment['category'] === 'energy_override'): ?><?= view('trip_commitments/components/energy_override_context', ['commitment' => $commitment, 'compact' => true]) ?><?php endif; ?></li><?php endforeach; ?></ul>
            <?php if ($guestCommitments['href'] !== null): ?><a class="text-link" href="<?= esc((string) $guestCommitments['href'], 'attr') ?>">Review guest instructions</a><?php endif; ?>
        </section>
    <?php endif; ?>

    <dl class="movement-card__facts">
        <div class="movement-card__fact movement-card__fact--location">
            <dt><?= esc($vehicle['location_heading']) ?></dt>
            <dd>
                <strong><?= esc($vehicle['location_class_label']) ?></strong>
                <?php if (($vehicle['guest_reported_at_label'] ?? null) !== null): ?>
                    <span><?= ($vehicle['guest_report_time_basis'] ?? null) === 'guest_report_received' ? 'Report received' : 'Guest-reported parked time' ?>: <?= esc($vehicle['guest_reported_at_label']) ?></span>
                <?php endif; ?>
                <?php if (($vehicle['airport_location_label'] ?? null) !== null): ?>
                    <span class="movement-card__garage"><?= esc($vehicle['airport_location_label']) ?></span>
                <?php elseif ($vehicle['location_detail'] !== null): ?>
                    <span><?= esc($vehicle['location_detail']) ?></span>
                <?php endif; ?>
            </dd>
        </div>
        <div class="movement-card__fact movement-card__fact--trip">
            <dt>Next confirmed trip</dt>
            <dd>
                <?php if ($nextTrip === null): ?>
                    <strong>No upcoming trip</strong>
                <?php else: ?>
                    <?php $nextGuestName = trim((string) ($nextTrip['guest_name'] ?? '')); ?>
                    <strong><?= esc($nextGuestName === '' ? $nextTrip['starts_at_label'] : $nextGuestName) ?></strong>
                    <span><?= $nextGuestName === '' ? '' : esc($nextTrip['starts_at_label']) . ' · ' ?><?= esc($nextTrip['pickup_location_label']) ?></span>
                <?php endif; ?>
            </dd>
        </div>
        <div class="movement-card__fact">
            <dt>Condition</dt>
            <dd><strong><?= esc($vehicle['condition_label']) ?></strong></dd>
        </div>
        <div class="movement-card__fact">
            <dt><?= esc($vehicle['energy_label']) ?></dt>
            <dd><strong><?= esc($vehicle['energy_value']) ?></strong></dd>
        </div>
    </dl>

    <section class="movement-card__blockers" aria-label="Movement readiness">
        <div class="movement-card__subheading">
            <h4>Today's movement actions</h4>
            <span><?= ($state['code'] ?? null) === 'awaiting_recovery' ? 'Pending recovery' : ((int) $readiness['blocking_count'] === 0 ? 'Ready' : esc((string) $readiness['blocking_count'])) ?></span>
        </div>
        <p><strong><?= esc((string) $readiness['summary']) ?></strong></p>
        <?php if ((int) ($readiness['trip_preparation_count'] ?? 0) > 0): ?><div class="movement-card__next-action"><span>Trip Preparation · <strong><?= (int) $readiness['trip_preparation_count'] ?> item<?= (int) $readiness['trip_preparation_count'] === 1 ? '' : 's' ?></strong></span><ul><?php foreach (($readiness['trip_preparation_items'] ?? []) as $item): ?><li><?= esc((string) $item) ?></li><?php endforeach; ?></ul><?php if (($readiness['next_actions'][0]['href'] ?? null) !== null): ?><a class="text-link" href="<?= esc((string) $readiness['next_actions'][0]['href'], 'attr') ?>#trip-preparation">Continue preparation</a><?php endif; ?></div><?php endif; ?>
        <?php foreach ($readiness['next_actions'] as $nextAction): ?>
            <p class="movement-card__next-action">Next: <strong><?= esc((string) $nextAction['label']) ?></strong></p>
        <?php endforeach; ?>
    </section>

    <?php if (($vehicle['recovery_exceptions'] ?? []) !== []): ?>
        <section class="movement-card__blockers" aria-label="Recovery exceptions">
            <h4>Recovery exception needs attention</h4>
            <?php foreach ($vehicle['recovery_exceptions'] as $exception): ?>
                <p><?= esc(ucwords(str_replace('_', ' ', (string) $exception['exception_code']))) ?> · <a class="text-link" href="/operations/checklists/<?= (int) $exception['checklist_id'] ?>#recovery-exceptions">Follow up</a></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="movement-card__recommendation" aria-label="FleetOS recommendation">
        <p class="eyebrow">FleetOS recommendation</p>
        <h4><?= esc($recommendation['display_label']) ?></h4>
        <?php if ($recommendation['reason_labels'] !== []): ?>
            <p><?= esc(implode(' ', $recommendation['reason_labels'])) ?></p>
        <?php endif; ?>
    </section>

    <section class="movement-card__operator-plan" aria-label="Operator plan">
        <p class="eyebrow">Operator plan</p>
        <?php if ($operatorPlan === null): ?>
            <p>No operator plan set.</p>
        <?php else: ?>
            <h4><?= esc($operatorPlan['label']) ?></h4>
            <p class="<?= $operatorPlan['is_basis_stale'] ? 'movement-card__plan-stale' : '' ?>"><?= esc($operatorPlan['status_label']) ?></p>
            <?php if (trim((string) ($operatorPlan['note'] ?? '')) !== ''): ?>
                <p><?= esc($operatorPlan['note']) ?></p>
            <?php endif; ?>
            <p>Set by <?= esc((string) ($operatorPlan['actor_label'] ?? 'Operator not captured')) ?> · <?= esc((string) ($operatorPlan['created_at_label'] ?? 'Time not captured')) ?></p>
        <?php endif; ?>
        <a class="text-link" href="<?= esc($vehicle['positioning_plan_href'], 'attr') ?>">Review positioning plan</a>
    </section>

    <footer class="movement-card__footer">
        <div class="movement-card__freshness<?= $freshness['is_stale'] ? ' is-stale' : '' ?>">
            <span>Turo data: <?= esc($freshness['age_label']) ?></span>
            <?php if ($freshness['is_stale']): ?>
                <strong><?= esc($freshness['warning']) ?></strong>
                <a class="text-link" href="/turo/imports">Refresh Turo data</a>
            <?php endif; ?>
        </div>
        <?php if (($vehicle['action'] ?? null) !== null): ?><a class="button-link movement-card__action" href="<?= esc($vehicle['action']['href'], 'attr') ?>"><?= esc($vehicle['action']['label']) ?></a><?php endif; ?>
    </footer>
</article>
