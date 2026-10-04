<?php
$futureTrip = $readiness['next_trip'] ?? null;
$futureRequirements = array_values(array_filter($readiness['requirements'] ?? [], static fn (array $requirement): bool =>
    $requirement['phase'] === 'next_pickup_preparation'
    && (int) ($requirement['trip_id'] ?? 0) === (int) ($futureTrip['id'] ?? 0)
    && ($requirement['source_type'] ?? null) !== 'extra_fulfillment'));
?>
<?php if ($futureTrip !== null && ($futureRequirements !== [] || ($futureExtraPreparation ?? []) !== [])): ?>
<section class="section wrap-anywhere" id="future-preparation" aria-labelledby="future-preparation-heading">
    <div class="section-heading"><div><p class="eyebrow">Later work for this vehicle</p><h2 id="future-preparation-heading">Future vehicle preparation</h2><p class="muted">Trip <?= (int) $futureTrip['id'] ?> · Reservation <?= esc((string) ($futureTrip['turo_reservation_id'] ?? $futureTrip['turo_trip_id'] ?? 'Not recorded')) ?> · <?= esc((string) $futureTrip['starts_at']) ?></p></div></div>
    <p class="muted">This work belongs to the future trip and does not affect this movement’s readiness.</p>
    <?= view('trip_movement_checklists/_extras_verification', ['verification' => $readiness['next_trip_extra_verification'] ?? null]) ?>
    <?= view('trip_movement_checklists/_trip_preparation', ['checklist' => $checklist, 'extraPreparation' => $futureExtraPreparation ?? [], 'preparationTrip' => $futureTrip, 'isFuturePreparation' => true]) ?>
    <ul class="readiness-list">
        <?php foreach ($futureRequirements as $requirement): ?>
        <li class="<?= $requirement['status'] === 'satisfied' ? 'is-complete' : 'is-pending' ?>"><span aria-hidden="true"><?= $requirement['status'] === 'satisfied' ? '✓' : '○' ?></span><div><strong><?= esc((string) $requirement['label']) ?></strong><small><?= esc((string) ($requirement['deferred_label'] ?? (($requirement['retired_reason'] ?? null) === 'target_handoff' ? 'Preparation phase closed at this trip’s handoff.' : ucfirst((string) $requirement['status'])))) ?></small></div>
            <?php if (($requirement['actionable'] ?? false) && ($requirement['action']['type'] ?? null) === 'extras_verification'): ?><a class="action-link" href="<?= esc((string) $requirement['action']['href'], 'attr') ?>">Refresh Turo Extras</a><?php endif; ?>
            <?php if ($requirement['actionable'] && ($requirement['commitment_id'] ?? null) !== null): ?><a class="action-link" href="/operations/trips/<?= (int) $requirement['trip_id'] ?>/commitments#commitment-<?= (int) $requirement['commitment_id'] ?>">Review future commitment</a><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
