<?php
/** @var array<string, mixed> $event */
/** @var bool $completed */
/** @var bool $compactHidden */
$completed ??= false;
$compactHidden ??= false;
?>
<li class="fleet-timeline__event tone-<?= esc($event['tone'], 'attr') ?><?= $completed ? ' is-completed' : '' ?>"<?= $compactHidden ? ' data-fleet-timeline-extra' : '' ?>>
    <a class="fleet-timeline__event-link" href="<?= esc($event['href'], 'attr') ?>">
        <time datetime="<?= esc($event['date_time']->format(DATE_ATOM), 'attr') ?>"><?= $completed ? '<span aria-hidden="true">✓</span> ' : '' ?><?= esc($event['time_label']) ?></time>
        <strong class="fleet-timeline__guest"><?= esc($event['guest_label']) ?></strong>
        <span class="fleet-timeline__vehicle"><?= esc($event['vehicle_label']) ?></span>
        <span class="movement-badge tone-<?= esc($event['tone'], 'attr') ?>"><?= esc($event['movement_label']) ?></span>
        <span class="fleet-timeline__location<?= $event['location_label'] === null ? ' is-empty' : '' ?>"><?= esc($event['location_label'] ?? 'Location not captured') ?></span>
        <?php if (! $completed && $event['readiness_label'] !== null): ?>
            <span class="text-link fleet-timeline__readiness"><?= esc($event['readiness_label']) ?></span>
        <?php else: ?>
            <span class="fleet-timeline__readiness<?= $completed ? ' is-complete' : ' is-empty' ?>"><?= $completed ? 'Completed' : '' ?></span>
        <?php endif; ?>
    </a>
</li>
