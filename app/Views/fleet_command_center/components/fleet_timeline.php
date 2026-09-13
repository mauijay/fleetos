<?php
/** @var array{today_date:string,groups:list<array{date:string,label:string,events:list<array<string,mixed>>}>,count:int,completed_today:list<array<string,mixed>>,completed_count:int} $timeline */
$futureVisibleLimit = 3;
$visibleFutureCount = 0;
$hiddenPendingCount = 0;
?>
<div class="fleet-timeline__content" data-fleet-timeline>
    <?php if ($timeline['groups'] === []): ?>
        <div class="empty-state">No upcoming fleet movements in the next 7 days.</div>
    <?php endif; ?>

    <?php if ($timeline['groups'] !== []): ?>
        <div id="fleet-timeline-pending" class="fleet-timeline__pending">
            <?php foreach ($timeline['groups'] as $group): ?>
                <?php
                $compactVisibility = [];
                foreach ($group['events'] as $event) {
                    $isToday = $group['date'] === $timeline['today_date'];
                    $compactHidden = ! $isToday && $visibleFutureCount >= $futureVisibleLimit;
                    $compactVisibility[] = $compactHidden;
                    if (! $isToday) {
                        ++$visibleFutureCount;
                    }
                    if ($compactHidden) {
                        ++$hiddenPendingCount;
                    }
                }
                $groupCompactHidden = $compactVisibility !== [] && ! in_array(false, $compactVisibility, true);
                ?>
                <section class="fleet-timeline__day" aria-labelledby="fleet-timeline-date-<?= esc($group['date'], 'attr') ?>"<?= $groupCompactHidden ? ' data-fleet-timeline-extra-group' : '' ?>>
                    <h3 id="fleet-timeline-date-<?= esc($group['date'], 'attr') ?>"><?= esc($group['label']) ?></h3>
                    <ol class="fleet-timeline__events">
                        <?php foreach ($group['events'] as $index => $event): ?>
                            <?= view('fleet_command_center/components/fleet_timeline_event', ['event' => $event, 'completed' => false, 'compactHidden' => $compactVisibility[$index]]) ?>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endforeach; ?>
        </div>

        <?php if ($hiddenPendingCount > 0): ?>
            <button class="fleet-timeline__disclosure" type="button" aria-expanded="false" aria-controls="fleet-timeline-pending" data-fleet-timeline-toggle data-collapsed-label="Show next 7 days" data-expanded-label="Show less" hidden>Show next 7 days</button>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($timeline['completed_count'] > 0): ?>
        <details class="fleet-timeline__completed">
            <summary>Completed today <span>(<?= esc((string) $timeline['completed_count']) ?>)</span></summary>
            <ol class="fleet-timeline__events fleet-timeline__completed-events">
                <?php foreach ($timeline['completed_today'] as $event): ?>
                    <?= view('fleet_command_center/components/fleet_timeline_event', ['event' => $event, 'completed' => true, 'compactHidden' => false]) ?>
                <?php endforeach; ?>
            </ol>
        </details>
    <?php endif; ?>
</div>
