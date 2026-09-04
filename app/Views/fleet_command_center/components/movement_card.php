<?php
/** @var array<string, mixed> $vehicle */
$state = $vehicle['state'];
$nextTrip = $vehicle['next_trip'];
$recommendation = $vehicle['recommendation'];
$operatorPlan = $vehicle['operator_plan'];
$freshness = $vehicle['freshness'];
$blockers = $vehicle['blockers'];
?>
<article class="movement-card movement-card--structured tone-<?= esc($state['tone'], 'attr') ?>">
    <header class="movement-card__header">
        <div class="movement-card__identity">
            <h3><?= esc($vehicle['fleet_code']) ?></h3>
            <p><?= esc($vehicle['model'] === '' ? 'Model not captured' : $vehicle['model']) ?></p>
        </div>
        <span class="status-badge tone-<?= esc($state['tone'], 'attr') ?>"><?= esc($state['label']) ?></span>
    </header>

    <p class="movement-card__primary"><?= esc($vehicle['primary_line']) ?></p>

    <dl class="movement-card__facts">
        <div class="movement-card__fact movement-card__fact--location">
            <dt><?= esc($vehicle['location_heading']) ?></dt>
            <dd>
                <strong><?= esc($vehicle['location_class_label']) ?></strong>
                <?php if (($vehicle['airport_garage_line'] ?? null) !== null): ?>
                    <span class="movement-card__garage"><?= esc($vehicle['airport_garage_line']) ?></span>
                    <span><?= esc($vehicle['airport_position_line']) ?></span>
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
                    <strong><?= esc($nextTrip['starts_at_label']) ?></strong>
                    <span><?= esc($nextTrip['pickup_location_label']) ?><?= trim((string) ($nextTrip['guest_name'] ?? '')) === '' ? '' : ' · ' . esc($nextTrip['guest_name']) ?></span>
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

    <section class="movement-card__blockers" aria-label="Movement blockers">
        <div class="movement-card__subheading">
            <h4>Blockers</h4>
            <span><?= esc((string) count($blockers)) ?></span>
        </div>
        <?php if ($blockers === []): ?>
            <p>None identified</p>
        <?php else: ?>
            <ul>
                <?php foreach ($blockers as $blocker): ?>
                    <li><?= esc($blocker['label']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

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
        <a class="button-link movement-card__action" href="<?= esc($vehicle['action']['href'], 'attr') ?>"><?= esc($vehicle['action']['label']) ?></a>
    </footer>
</article>
