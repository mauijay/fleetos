<?php
/** @var array<string, mixed> $checklist */
/** @var array<string, mixed>|null $readiness */
/** @var array{pickup:array<string,mixed>|null,return:array<string,mixed>|null} $tripFacts */
$requirements = $readiness['requirements'] ?? [];
$requirements = array_values(array_filter($requirements, static fn (array $requirement): bool => ! str_starts_with((string) ($requirement['code'] ?? ''), 'extra_fulfillment_')));
$itemsByCode = array_column($checklist['items'] ?? [], null, 'item_code');
$readinessPhase = $readiness['readiness_phase'] ?? null;
$closed = $checklist['completed_at'] !== null;
$known = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === $readinessPhase && $requirement['kind'] === 'derived' && $requirement['status'] === 'satisfied'));
$completedHuman = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === $readinessPhase
    && in_array($requirement['kind'], ['human', 'hybrid'], true)
    && $requirement['status'] === 'satisfied'));
$pending = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === $readinessPhase && $requirement['status'] === 'unsatisfied' && ($requirement['actionable'] ?? true)));
$historicalDeficits = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === $readinessPhase && $requirement['status'] === 'unsatisfied' && ! ($requirement['actionable'] ?? true)));
$blockingPending = array_values(array_filter($pending, static fn (array $requirement): bool => $requirement['blocking']));
$requiredPending = array_values(array_filter($pending, static fn (array $requirement): bool => ! $requirement['blocking'] && ($itemsByCode[$requirement['code']]['is_required'] ?? false)));
$additionalPending = array_values(array_filter($pending, static fn (array $requirement): bool => ! $requirement['blocking'] && ! ($itemsByCode[$requirement['code']]['is_required'] ?? false)));
$blockingRemaining = count($blockingPending);
$totalBlockingRemaining = (int) ($readiness['blocking_remaining_count'] ?? $blockingRemaining);
$additionalRemaining = count($requiredPending) + count($additionalPending);
$lifecycle = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === 'pickup_lifecycle'));
$nextPickupPreparation = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['phase'] === 'next_pickup_preparation'));
$nextPickupPending = array_values(array_filter($nextPickupPreparation, static fn (array $requirement): bool => $requirement['status'] === 'unsatisfied' && ($requirement['actionable'] ?? true)));
$nextPickupFacts = array_values(array_filter($nextPickupPreparation, static fn (array $requirement): bool => $requirement['status'] !== 'unsatisfied' || ! ($requirement['actionable'] ?? true)));
$exceptionalDispositions = $readiness['exceptional_dispositions'] ?? [];
$currentDisposition = trim((string) ($checklist['vehicle_disposition'] ?? ''));
$lastKnownVehicleFacts = $readiness['last_known_vehicle_facts'] ?? null;
$pendingLabels = [
    'exterior_inspected' => 'Inspect exterior',
    'interior_inspected' => 'Inspect interior',
    'damage_check_completed' => 'Check for damage',
    'return_photos_completed' => 'Confirm return photos',
];
$activeFacts = $tripFacts[(string) ($checklist['movement_type'] ?? '')] ?? null;
$factDetail = static function (array $requirement) use ($activeFacts): ?string {
    if ($activeFacts === null) {
        return isset($requirement['basis_at']) ? date('g:i A', strtotime((string) $requirement['basis_at'])) : null;
    }
    return match ($requirement['code']) {
        'vehicle_received', 'return_time_confirmed' => (string) ($activeFacts['occurred_at_label'] ?? ''),
        'energy_known', 'energy_ready' => (string) ($activeFacts['energy_value'] ?? ''),
        'cleaning_status_known', 'vehicle_clean' => (string) ($activeFacts['cleanliness_label'] ?? ''),
        'location_confirmed', 'airport_staging', 'parking_location_recorded' => trim((string) ($activeFacts['location_class_label'] ?? '') . ' · ' . (string) ($activeFacts['location_detail_value'] ?? ''), ' ·'),
        default => isset($requirement['basis_at']) ? date('g:i A', strtotime((string) $requirement['basis_at'])) : null,
    };
};
?>
<section class="section readiness-panel" aria-labelledby="readiness-heading">
    <div class="section-heading">
        <div>
            <p class="eyebrow"><?= ($checklist['movement_type'] ?? '') === 'return' ? 'Return readiness' : 'Pickup preparation' ?></p>
            <h2 id="readiness-heading" tabindex="-1"><?= ($readiness['ready'] ?? false) ? 'Ready' : $totalBlockingRemaining . ' blocking actions remaining' ?></h2>
            <?php if ($additionalRemaining > 0): ?><p class="muted"><?= $totalBlockingRemaining ?> blocking · <?= $additionalRemaining ?> additional action<?= $additionalRemaining === 1 ? '' : 's' ?></p><?php endif; ?>
        </div>
        <?php if ($closed): ?><span class="status-badge tone-info">Workflow closed</span><?php endif; ?>
    </div>

    <?php if (($lastKnownVehicleFacts['energy_percent'] ?? null) !== null): ?>
        <?php
        $energySourceLabel = in_array($lastKnownVehicleFacts['energy_source_event'] ?? null, ['actual_return', 'vehicle_recovered'], true)
            ? 'Recorded at recovery'
            : 'Last observed';
        $energyObservedAt = trim((string) ($lastKnownVehicleFacts['energy_observed_at'] ?? ''));
        ?>
        <p class="muted readiness-last-known-energy">
            Last known Charge/Fuel: <?= (int) $lastKnownVehicleFacts['energy_percent'] ?>%
            <?php if ($energyObservedAt !== ''): ?> · <?= esc($energySourceLabel) ?> <?= esc(date('M j, g:i A', strtotime($energyObservedAt))) ?><?php endif; ?>.
            <?php if (! ($lastKnownVehicleFacts['energy_applicable_to_target'] ?? false)): ?> Current Charge/Fuel needs verification.<?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="readiness-groups">
        <div class="readiness-group">
            <h3>Action required</h3>
            <?php if ($pending === []): ?><p class="muted">No current readiness actions.</p><?php endif; ?>
            <?php foreach ([['label' => 'Blocking', 'requirements' => $blockingPending], ['label' => 'Required', 'requirements' => $requiredPending], ['label' => 'Additional actions', 'requirements' => $additionalPending]] as $actionGroup): ?>
                <?php if ($actionGroup['requirements'] !== []): ?><p class="eyebrow readiness-action-kind"><?= esc($actionGroup['label']) ?></p><?php endif; ?>
                <ul class="readiness-list readiness-actions">
                    <?php foreach ($actionGroup['requirements'] as $requirement): ?>
                        <?php
                        $item = $itemsByCode[$requirement['code']] ?? null;
                        $itemId = (int) ($item['id'] ?? $requirement['action']['item_id'] ?? 0);
                        $actionType = (string) ($requirement['action']['type'] ?? '');
                        $isSpecialAction = in_array($actionType, ['photos_composite', 'charging_adapter', 'guest_commitment_complete', 'guest_commitment_acknowledge'], true);
                        ?>
                        <li id="checklist-action-<?= esc((string) $requirement['code'], 'attr') ?>" tabindex="-1" class="is-pending readiness-action-row<?= $isSpecialAction ? ' readiness-compound-action' : '' ?>"><span aria-hidden="true">○</span><div class="readiness-action-label"><strong><?= esc((string) ($pendingLabels[$requirement['code']] ?? $requirement['action']['label'] ?? $requirement['label'])) ?></strong><?php if (($requirement['energy_policy_label'] ?? null) !== null): ?><small><?= esc((string) $requirement['energy_policy_label']) ?></small><?php endif; ?></div>
                            <?php if (! $closed && $actionType === 'photos_composite'): ?>
                                <div class="readiness-action-controls"><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/photos-complete" method="post"><?= csrf_field() ?><button class="primary-action" type="submit">Confirm</button></form></div>
                            <?php elseif (! $closed && $actionType === 'charging_adapter'): ?>
                                <div class="readiness-action-controls"><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/charging-adapter-present" method="post"><?= csrf_field() ?><button class="primary-action" type="submit">Confirm</button></form></div>
                            <?php elseif (! $closed && in_array($actionType, ['guest_commitment_complete', 'guest_commitment_acknowledge'], true)): ?>
                                <div class="readiness-action-controls"><form action="/operations/trips/<?= (int) $requirement['action']['trip_id'] ?>/commitments/<?= (int) $requirement['action']['commitment_id'] ?>/<?= $actionType === 'guest_commitment_complete' ? 'complete' : 'acknowledge' ?>" method="post"><?= csrf_field() ?><button class="primary-action" type="submit"><?= $actionType === 'guest_commitment_complete' ? 'Complete' : 'Acknowledge' ?></button></form></div>
                            <?php elseif (! $closed && $actionType === 'vehicle_health'): ?>
                                <div class="readiness-action-controls"><a class="action-link" href="<?= esc((string) $requirement['action']['href'], 'attr') ?>"><?= esc((string) $requirement['action']['label']) ?></a></div>
                            <?php elseif (! $closed && $itemId > 0): ?>
                                <div class="readiness-action-controls"><form action="/operations/checklist-items/<?= $itemId ?>/complete" method="post"><?= csrf_field() ?><button class="primary-action" type="submit">Confirm</button></form><?php if ($requirement['allows_na'] ?? false): ?><form action="/operations/checklist-items/<?= $itemId ?>/not-applicable" method="post"><?= csrf_field() ?><button class="action-link" type="submit">Not applicable</button></form><?php endif; ?></div>
                            <?php elseif (! $closed): ?><div class="readiness-action-controls"><a class="action-link" href="#handoff-entry">Record facts</a></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
            <?php if ($nextPickupPending !== []): ?>
                <div class="readiness-subgroup"><p class="eyebrow"><?= ($readiness['is_same_day_turnaround'] ?? false) ? 'Same-day turnaround' : 'Preparation for next pickup' ?></p>
                    <ul class="readiness-list"><?php foreach ($nextPickupPending as $requirement): ?><li id="checklist-action-<?= esc((string) $requirement['code'], 'attr') ?>" tabindex="-1" class="is-pending"><span aria-hidden="true">○</span><div><strong><?= esc((string) $requirement['label']) ?></strong><small><?= esc((string) ($requirement['action']['label'] ?? 'Attention required')) ?></small></div><?php if (str_starts_with((string) $requirement['code'], 'guest_commitment_')): ?><a class="action-link" href="/operations/trips/<?= (int) $requirement['target_trip_id'] ?>/commitments#commitment-<?= (int) $requirement['action']['commitment_id'] ?>">Review</a><?php endif; ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>
        <div class="readiness-group">
            <h3>Known</h3>
            <?php if ($known === []): ?><p class="muted">No authoritative facts recorded yet.</p><?php endif; ?>
            <ul class="readiness-list">
                <?php foreach ($known as $requirement): ?>
                    <li id="checklist-action-<?= esc((string) $requirement['code'], 'attr') ?>" tabindex="-1" class="is-complete"><span aria-hidden="true">✓</span><div><strong><?= esc((string) $requirement['label']) ?></strong><?php if (($detail = $factDetail($requirement)) !== null && $detail !== ''): ?><small><?= esc($detail) ?></small><?php endif; ?><?php if (($requirement['energy_policy_label'] ?? null) !== null): ?><small><?= esc((string) $requirement['energy_policy_label']) ?></small><?php endif; ?><?php if (($requirement['attention_label'] ?? null) !== null): ?><small><?= esc((string) $requirement['attention_label']) ?></small><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($completedHuman !== [] || $historicalDeficits !== []): ?>
        <div class="readiness-group">
            <?php if ($historicalDeficits !== []): ?><h3>Recorded at handoff</h3><p class="muted">These facts remain below target, but preparation is no longer actionable while the guest has the vehicle.</p><ul class="readiness-list"><?php foreach ($historicalDeficits as $requirement): ?><li><div><strong><?= esc((string) $requirement['label']) ?></strong></div></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($completedHuman !== []): ?><h3>Completed checks</h3><?php endif; ?>
            <ul class="readiness-list readiness-actions">
                <?php foreach ($completedHuman as $requirement): ?>
                    <?php
                    $item = $itemsByCode[$requirement['code']] ?? null;
                    $itemId = (int) ($item['id'] ?? 0);
                    $actionType = (string) ($requirement['action']['type'] ?? '');
                    ?>
                    <li id="checklist-action-<?= esc((string) $requirement['code'], 'attr') ?>" tabindex="-1" class="is-complete"><span aria-hidden="true">✓</span><div><strong><?= esc((string) $requirement['label']) ?></strong><?php if ($requirement['basis_at'] !== null): ?><small><?= esc(ucfirst((string) ($item['completion_source'] ?? 'manual'))) ?> · <?= esc(date('M j, g:i A', strtotime((string) $requirement['basis_at']))) ?></small><?php endif; ?></div><?php if (! $closed && $actionType === 'photos_composite'): ?><div class="readiness-action-controls"><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/photos-undo" method="post"><?= csrf_field() ?><button class="action-link" type="submit">Undo</button></form></div><?php elseif (! $closed && $actionType === 'charging_adapter'): ?><div class="readiness-action-controls"><form action="/operations/checklists/<?= (int) $checklist['id'] ?>/charging-adapter-undo" method="post"><?= csrf_field() ?><button class="action-link" type="submit">Undo</button></form></div><?php elseif (! $closed && $itemId > 0): ?><div class="readiness-action-controls"><form action="/operations/checklist-items/<?= $itemId ?>/undo" method="post"><?= csrf_field() ?><button class="action-link" type="submit">Undo</button></form></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <?php if (($checklist['movement_type'] ?? '') === 'return'): ?>
        <div id="exceptional-disposition" tabindex="-1" class="readiness-subgroup exceptional-disposition">
            <p class="eyebrow">Exceptional hold</p>
            <p class="muted">Optional. Normal turnaround is driven by recorded condition and energy.</p>
            <?php if ($currentDisposition !== ''): ?><p>Recorded: <strong><?= esc((string) ($exceptionalDispositions[$currentDisposition] ?? ucwords(str_replace('_', ' ', $currentDisposition)))) ?></strong></p><?php endif; ?>
            <?php if (! $closed): ?><form class="readiness-compound-controls inline-disposition" action="/operations/checklists/<?= (int) $checklist['id'] ?>/disposition" method="post"><?= csrf_field() ?><label><span class="visually-hidden">Choose exceptional hold</span><select name="vehicle_disposition"><option value="">No exceptional hold</option><?php foreach ($exceptionalDispositions as $disposition => $label): ?><option value="<?= esc((string) $disposition, 'attr') ?>" <?= $currentDisposition === $disposition ? 'selected' : '' ?>><?= esc((string) $label) ?></option><?php endforeach; ?></select></label><button class="secondary-action" type="submit">Save hold</button></form><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($lifecycle !== []): ?>
        <div class="readiness-subgroup"><p class="eyebrow">Lifecycle</p><ul class="readiness-list"><?php foreach ($lifecycle as $requirement): ?><li id="checklist-action-<?= esc((string) $requirement['code'], 'attr') ?>" tabindex="-1" class="<?= $requirement['status'] === 'satisfied' ? 'is-complete' : 'is-pending' ?>"><span aria-hidden="true"><?= $requirement['status'] === 'satisfied' ? '✓' : '○' ?></span><div><strong><?= esc((string) $requirement['label']) ?></strong><small>Does not gate pickup preparation</small></div></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($nextPickupFacts !== []): ?>
        <div class="readiness-subgroup"><p class="eyebrow">Next pickup facts</p><ul class="readiness-list"><?php foreach ($nextPickupFacts as $requirement): ?><li class="<?= $requirement['status'] === 'satisfied' ? 'is-complete' : 'is-muted' ?>"><span aria-hidden="true"><?= $requirement['status'] === 'satisfied' ? '✓' : '—' ?></span><div><strong><?= esc((string) $requirement['label']) ?></strong></div></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (! $closed && ($readiness['ready'] ?? false)): ?>
        <form class="workflow-close" action="/operations/checklists/<?= (int) $checklist['id'] ?>/complete" method="post"><?= csrf_field() ?><label>Completion note<textarea name="completion_note" rows="2" placeholder="Optional note"></textarea></label><button class="secondary-action" type="submit">Close Movement Workflow</button></form>
    <?php elseif ($closed): ?>
        <form class="workflow-close" action="/operations/checklists/<?= (int) $checklist['id'] ?>/reopen" method="post"><?= csrf_field() ?><label class="checkbox-row"><input type="checkbox" name="confirm_reopen" value="1" required><span>Confirm reopening this completed workflow.</span></label><button class="secondary-action" type="submit">Reopen Workflow</button></form>
    <?php endif; ?>

    <details class="checklist-history"><summary>Checklist history</summary><div class="history-list">
        <?php foreach (($readiness['workflow_history']['legacy_items'] ?? $checklist['items']) as $item): ?><div><strong><?= esc((string) $item['label']) ?></strong><span><?= esc(ucwords(str_replace('_', ' ', (string) (($item['applicability'] ?? 'applicable') === 'not_applicable' ? 'not_applicable' : $item['completion_state'])))) ?><?php if (($item['completion_source'] ?? null) !== null): ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $item['completion_source']))) ?><?php endif; ?><?php if (($item['completed_at'] ?? null) !== null): ?> · <?= esc(date('M j, Y g:i A', strtotime((string) $item['completed_at']))) ?><?php endif; ?></span><?php if (trim((string) ($item['note'] ?? '')) !== ''): ?><small><?= esc((string) $item['note']) ?></small><?php endif; ?></div><?php endforeach; ?>
        <?php if (trim((string) ($readiness['workflow_history']['vehicle_disposition'] ?? '')) !== ''): ?><div><strong>Recorded vehicle disposition</strong><span><?= esc(ucwords(str_replace('_', ' ', (string) $readiness['workflow_history']['vehicle_disposition']))) ?></span></div><?php endif; ?>
        <?php if (($readiness['workflow_history']['historically_completed'] ?? false)): ?><div><strong>Workflow completed</strong><span><?= esc(date('M j, Y g:i A', strtotime((string) $readiness['workflow_history']['completed_at']))) ?></span><?php if (trim((string) ($checklist['completion_note'] ?? '')) !== ''): ?><small><?= esc((string) $checklist['completion_note']) ?></small><?php endif; ?></div><?php endif; ?>
    </div></details>
</section>
