<?php
/** @var array<string, mixed> $checklist */
/** @var array<string, mixed>|null $readiness */
/** @var array<string, mixed>|null $guestReturn */
/** @var bool $guestReturnActive */
/** @var bool $returnCompleted */
/** @var bool $canRecover */
/** @var array{cleaning:?array,energy:?array} $turnaroundWork */
/** @var list<array<string,mixed>> $recoveryExceptions */
$legacyItems = $checklist['items'] ?? [];
$legacyAudits = $checklist['audits'] ?? [];
$openExceptions = array_values(array_filter($recoveryExceptions, static fn (array $row): bool => $row['status'] === 'open' && ($row['recovery_voided_at'] ?? null) === null));
$historicalExceptions = array_values(array_filter($recoveryExceptions, static fn (array $row): bool => $row['status'] === 'resolved' || ($row['recovery_voided_at'] ?? null) !== null));
$exceptionLabels = [
    'damage' => 'Damage found',
    'missing_key' => 'Missing key',
    'missing_charge_adapter' => 'Missing charge adapter',
    'not_drivable' => 'Vehicle not drivable',
    'other' => 'Other issue',
];
$energyWork = $turnaroundWork['energy'] ?? null;
$currentDisposition = trim((string) ($checklist['vehicle_disposition'] ?? ''));
?>
<section class="section readiness-panel" aria-labelledby="return-workflow-heading">
    <div class="section-heading"><div><p class="eyebrow">Return workflow</p><h2 id="return-workflow-heading" tabindex="-1"><?= $returnCompleted ? 'Recovery complete' : ($guestReturnActive ? 'Awaiting recovery' : 'Awaiting return') ?></h2></div></div>
    <?php if (! $returnCompleted): ?>
        <p class="muted">Turo handles its own return photos and inspection. FleetOS records custody and derives turnaround work after recovery.</p>
        <?php if ($canRecover): ?><a class="button-link" href="#recover-vehicle-entry">Recover Vehicle</a><?php endif; ?>
    <?php else: ?>
        <div class="readiness-subgroup" id="turnaround-actions" tabindex="-1"><h3>Turnaround</h3><ul class="readiness-list readiness-actions turnaround-action-list">
            <?php if (($turnaroundWork['cleaning'] ?? null) !== null): ?>
                <li class="is-pending readiness-action-row"><span aria-hidden="true">○</span><div class="readiness-action-label"><strong>Cleaning Required</strong><small>Record the vehicle's current condition after service.</small></div><div class="readiness-action-controls">
                    <form action="/fleet/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>/current-readiness" method="post"><?= csrf_field() ?><input type="hidden" name="occurred_at" value="<?= esc(date('Y-m-d\TH:i:s'), 'attr') ?>"><input type="hidden" name="cleanliness" value="clean"><input type="hidden" name="return_checklist_id" value="<?= (int) $checklist['id'] ?>"><button class="primary-action" type="submit">Mark Clean</button></form>
                    <a class="action-link" href="/fleet/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>#current-operations">Record Condition</a>
                </div></li>
            <?php endif; ?>
            <?php if ($energyWork !== null): ?>
                <li class="is-pending readiness-action-row"><span aria-hidden="true">○</span><div class="readiness-action-label"><strong><?= esc((string) ($energyWork['label'] ?? 'Fuel/Charge level unknown')) ?></strong><?php if (($energyWork['condition_code'] ?? null) === 'target_needed'): ?><small>Configuration needed before FleetOS can evaluate readiness.</small><?php else: ?><small>Record the measured post-service level; FleetOS will evaluate it against the configured target.</small><?php endif; ?></div><div class="readiness-action-controls">
                    <?php if (($energyWork['condition_code'] ?? null) === 'target_needed'): ?>
                        <a class="action-link" href="<?= esc((string) $energyWork['href'], 'attr') ?>"><?= esc((string) ($energyWork['action_label'] ?? 'Configure Vehicle')) ?></a>
                    <?php else: ?>
                        <form class="turnaround-energy-form" action="/fleet/vehicles/<?= (int) $checklist['fleet_vehicle_id'] ?>/current-readiness" method="post"><?= csrf_field() ?><input type="hidden" name="occurred_at" value="<?= esc(date('Y-m-d\TH:i:s'), 'attr') ?>"><input type="hidden" name="return_checklist_id" value="<?= (int) $checklist['id'] ?>"><label><span class="visually-hidden"><?= esc((string) ($energyWork['action_label'] ?? 'Record Fuel/Charge Level')) ?></span><input type="number" name="energy_percent" min="0" max="100" required inputmode="numeric" placeholder="%"></label><button class="primary-action" type="submit"><?= esc((string) ($energyWork['action_label'] ?? 'Record Fuel/Charge Level')) ?></button></form>
                    <?php endif; ?>
                </div></li>
            <?php endif; ?>
            <?php if (($turnaroundWork['cleaning'] ?? null) === null && $energyWork === null): ?><li class="is-complete"><span aria-hidden="true">✓</span><div><strong>No current cleaning or energy action</strong></div></li><?php endif; ?>
        </ul></div>
        <?php if (($readiness['next_trip'] ?? null) !== null): ?><p class="muted">Next confirmed pickup: <?= esc((string) ($readiness['next_trip']['starts_at'] ?? 'Time not captured')) ?>. Preparation remains derived from current vehicle facts.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($openExceptions !== []): ?>
        <div id="recovery-exceptions" class="readiness-subgroup" tabindex="-1"><h3>Recovery exceptions needing attention</h3>
            <?php foreach ($openExceptions as $exception): ?>
                <div class="import-message tone-warning"><strong><?= esc((string) ($exceptionLabels[$exception['exception_code']] ?? 'Recovery exception')) ?></strong>
                    <?php if (trim((string) ($exception['note'] ?? '')) !== ''): ?><span><?= esc((string) $exception['note']) ?></span><?php endif; ?>
                    <?php if ($exception['exception_code'] === 'damage'): ?><span>Review the existing claim process; no claim was created automatically. <a class="action-link" href="/#fleet-health">Review Damage</a></span><?php endif; ?>
                    <?php if ($exception['exception_code'] === 'not_drivable'): ?><span>Assess maintenance or an offline hold before the next movement.</span><?php endif; ?>
                    <form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/recovery-exceptions/<?= (int) $exception['id'] ?>/resolve" method="post"><?= csrf_field() ?><label>Resolution note (optional)<input name="resolution_note" maxlength="2000"></label><button class="secondary-action" type="submit">Resolve exception</button></form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($historicalExceptions !== []): ?><details class="checklist-history"><summary>Historical recovery exceptions</summary><div class="history-list"><?php foreach ($historicalExceptions as $exception): ?><div><strong><?= esc((string) ($exceptionLabels[$exception['exception_code']] ?? 'Recovery exception')) ?></strong><span><?php if (($exception['recovery_voided_at'] ?? null) !== null): ?>Recovery voided <?= esc((string) $exception['recovery_voided_at']) ?><?php else: ?>Resolved <?= esc((string) $exception['resolved_at']) ?> by operator #<?= (int) $exception['resolved_by'] ?><?php endif; ?></span><?php if (trim((string) ($exception['resolution_note'] ?? '')) !== ''): ?><small><?= esc((string) $exception['resolution_note']) ?></small><?php endif; ?></div><?php endforeach; ?></div></details><?php endif; ?>

    <?php if ($currentDisposition !== '' || $openExceptions !== []): ?><details class="secondary-disclosure" id="exceptional-disposition"><summary>Exceptional vehicle hold</summary><p class="muted">Use only when an operational hold is needed; recording an exception does not automatically set one.</p><?php if ($currentDisposition !== ''): ?><p>Recorded: <?= esc(ucwords(str_replace('_', ' ', $currentDisposition))) ?></p><?php endif; ?><form class="issue-filters" action="/operations/checklists/<?= (int) $checklist['id'] ?>/disposition" method="post"><?= csrf_field() ?><label>Hold<select name="vehicle_disposition"><option value="">No exceptional hold</option><?php foreach (($readiness['exceptional_dispositions'] ?? []) as $code => $label): ?><option value="<?= esc((string) $code, 'attr') ?>" <?= $currentDisposition === $code ? 'selected' : '' ?>><?= esc((string) $label) ?></option><?php endforeach; ?></select></label><button class="secondary-action" type="submit">Save hold</button></form></details><?php endif; ?>

    <?php if ($legacyItems !== [] || $legacyAudits !== [] || $checklist['completed_at'] !== null): ?>
        <details class="checklist-history"><summary>Legacy checklist history</summary><div class="history-list">
            <?php foreach ($legacyItems as $item): ?><div><strong><?= esc((string) $item['label']) ?></strong><span><?= esc(ucwords(str_replace('_', ' ', (string) $item['completion_state']))) ?><?php if (($item['completed_at'] ?? null) !== null): ?> · <?= esc((string) $item['completed_at']) ?><?php endif; ?></span><?php if (trim((string) ($item['note'] ?? '')) !== ''): ?><small><?= esc((string) $item['note']) ?></small><?php endif; ?></div><?php endforeach; ?>
            <?php foreach ($legacyAudits as $audit): ?><div><strong>Checklist audit: <?= esc((string) $audit['action']) ?></strong><span><?= esc((string) $audit['created_at']) ?> · item #<?= (int) ($audit['trip_movement_checklist_item_id'] ?? 0) ?></span></div><?php endforeach; ?>
            <?php if ($checklist['completed_at'] !== null): ?><div><strong>Historical workflow completion</strong><span><?= esc((string) $checklist['completed_at']) ?></span></div><?php endif; ?>
        </div></details>
    <?php endif; ?>
</section>
